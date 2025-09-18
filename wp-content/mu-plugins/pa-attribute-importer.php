<?php
/**
 * Plugin Name: PA Attribute Importer (mu-plugin)
 * Description: Import product attribute descriptions from a CSV in the uploads folder and update WP term descriptions. Expects minimal CSV columns: term_id,taxonomy,generated_description
 * Version: 1.1
 * Author: Assistant
 * Notes: This file is designed to be placed in wp-content/mu-plugins/ and used via WP-CLI.
 */

/**
 * Registers WP-CLI command: wp pa-attr-import run [--csv=/full/path/file.csv] [--dry=1|0]
 *
 * If --csv is not provided, the plugin will search the WP uploads directory for a file named:
 *   pa-attributes-minimal-for-import.csv
 * or any file that matches the pattern pa-attributes*.csv and use the newest one.
 *
 * CSV must have a header row with at least:
 * term_id,taxonomy,generated_description
 *
 * Always run with --dry=1 first to preview changes.
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    class PA_Attr_Importer_Command {
        /**
         * Run the import.
         *
         * @param array $args Positional arguments (unused)
         * @param array $assoc Associative args: csv (path), dry (1 or 0)
         */
        public function run( $args, $assoc ) {
            $dry = isset( $assoc['dry'] ) ? boolval( $assoc['dry'] ) : true;
            $csv_provided = isset( $assoc['csv'] ) && ! empty( $assoc['csv'] );

            // If a CSV path was provided, use it; otherwise search uploads for a matching file.
            if ( $csv_provided ) {
                $csv_path = $assoc['csv'];
                if ( ! file_exists( $csv_path ) ) {
                    WP_CLI::error( "CSV not found at provided path: {$csv_path}" );
                    return;
                }
            } else {
                // Locate candidate files in uploads folder
                $uploads = wp_get_upload_dir();
                $uploads_dir = trailingslashit( $uploads['basedir'] );
                $candidates = glob( $uploads_dir . 'pa-attributes*.csv' );
                if ( empty( $candidates ) ) {
                    // Try exact default filename fallback
                    $default = $uploads_dir . 'pa-attributes-minimal-for-import.csv';
                    if ( file_exists( $default ) ) {
                        $csv_path = $default;
                    } else {
                        WP_CLI::error( "No CSV provided and no files matching 'pa-attributes*.csv' found in uploads: {$uploads_dir}" );
                        return;
                    }
                } else {
                    // pick newest candidate
                    usort( $candidates, function( $a, $b ) {
                        return filemtime( $b ) - filemtime( $a );
                    } );
                    $csv_path = $candidates[0];
                }
            }

            WP_CLI::line( "Using CSV: {$csv_path}" );
            WP_CLI::line( $dry ? "Mode: DRY RUN (no DB changes)" : "Mode: LIVE (will update DB)" );

            // Open CSV and parse header
            if ( ( $handle = fopen( $csv_path, 'r' ) ) === false ) {
                WP_CLI::error( "Failed to open CSV: {$csv_path}" );
                return;
            }

            $header = fgetcsv( $handle );
            if ( $header === false ) {
                WP_CLI::error( "CSV appears empty or unreadable: {$csv_path}" );
                fclose( $handle );
                return;
            }

            $header = array_map( 'trim', $header );
            $needed = array( 'term_id', 'taxonomy', 'generated_description' );
            $missing = array();
            foreach ( $needed as $col ) {
                if ( ! in_array( $col, $header, true ) ) {
                    $missing[] = $col;
                }
            }

            if ( ! empty( $missing ) ) {
                WP_CLI::error( "CSV header missing required columns: " . implode( ',', $missing ) );
                fclose( $handle );
                return;
            }

            $map = array_flip( $header );
            $updated = 0;
            $row_num = 1;
            while ( ( $row = fgetcsv( $handle ) ) !== false ) {
                $row_num++;
                // guard against short rows
                if ( count( $row ) < count( $header ) ) {
                    // extend to header length
                    $row = array_pad( $row, count( $header ), '' );
                }
                $data = array_combine( $header, $row );

                $term_id = intval( trim( $data['term_id'] ) );
                $taxonomy = trim( $data['taxonomy'] );
                $new_desc = $data['generated_description'];

                if ( ! $term_id ) {
                    WP_CLI::warning( "Skipping row {$row_num}: empty/invalid term_id" );
                    continue;
                }

                // If taxonomy missing, attempt to discover it from the term object
                if ( empty( $taxonomy ) ) {
                    $term_obj = get_term( $term_id );
                    if ( ! $term_obj || is_wp_error( $term_obj ) ) {
                        WP_CLI::warning( "Row {$row_num}: term_id {$term_id} not found; skipping" );
                        continue;
                    }
                    $taxonomy = $term_obj->taxonomy;
                    WP_CLI::line( "Row {$row_num}: discovered taxonomy '{$taxonomy}' for term_id {$term_id}" );
                }

                if ( $dry ) {
                    WP_CLI::line( "DRY: Would update term_id={$term_id} taxonomy={$taxonomy} desc_len=" . strlen( $new_desc ) );
                } else {
                    $res = wp_update_term( $term_id, $taxonomy, array( 'description' => $new_desc ) );
                    if ( is_wp_error( $res ) ) {
                        WP_CLI::warning( "Row {$row_num}: Failed updating term {$term_id}: " . $res->get_error_message() );
                    } else {
                        WP_CLI::line( "Updated term {$term_id} (taxonomy: {$taxonomy})" );
                        $updated++;
                    }
                }
            }
            fclose( $handle );
            WP_CLI::success( "Finished. Updated count: {$updated} (dry=" . ($dry ? '1' : '0') . ")" );
        }
    }

    WP_CLI::add_command( 'pa-attr-import', 'PA_Attr_Importer_Command' );
}
