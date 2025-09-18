<?php
/**
 * Plugin Name: Export Empty PA Attributes (WP-CLI)
 * Description: WP-CLI command to export first N WooCommerce product attribute terms (pa_*) that have empty descriptions to CSV.
 * Version: 1.0
 * Author: (prepared for you)
 */

// Only register the command when WP-CLI is available
if ( defined( 'WP_CLI' ) && WP_CLI ) {

    class Export_Empty_PA_Attributes_Command {

        /**
         * Export first N empty product attribute terms to CSV.
         *
         * ## OPTIONS
         *
         * [--limit=<number>]
         * : Number of terms to export. Default 10.
         *
         * [--file=<path>]
         * : Full path to save CSV. Default: uploads/pa-empty-attributes.csv
         *
         * ## EXAMPLES
         *
         * wp attrs:export-empty --limit=10
         * wp attrs:export-empty --limit=20 --file=/tmp/empty-pa.csv
         */
        public function __invoke( $args, $assoc_args ) {
            $limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : 10;
            $custom_file = ! empty( $assoc_args['file'] ) ? $assoc_args['file'] : false;

            $upload_dir = wp_upload_dir();
            $default_file = rtrim( $upload_dir['basedir'], '/' ) . '/pa-empty-attributes.csv';
            $file = $custom_file ? $custom_file : $default_file;

            // Find pa_* taxonomies
            $all_taxonomies = get_taxonomies( [], 'names' );
            $pa_taxonomies = array_filter( $all_taxonomies, function( $t ) {
                return strpos( $t, 'pa_' ) === 0;
            } );
            $pa_taxonomies = array_values( $pa_taxonomies );

            if ( empty( $pa_taxonomies ) ) {
                WP_CLI::error( 'No pa_* taxonomies found on this site.' );
                return;
            }

            // Prepare CSV
            $headers = array(
                'term_id',
                'taxonomy',
                'term_name',
                'slug',
                'current_description',
                'product_count',
                'sample_product_ids',
                'sample_product_titles',
                'generated_description' // <-- new column
            );

            $fp = @fopen( $file, 'w' );
            if ( ! $fp ) {
                WP_CLI::error( "Unable to open file for writing: $file" );
                return;
            }
            fputcsv( $fp, $headers );

            $found = 0;

            foreach ( $pa_taxonomies as $tax ) {
                // get all terms for this taxonomy (hide_empty false so we get every term)
                $terms = get_terms( array(
                    'taxonomy'   => $tax,
                    'hide_empty' => false,
                    'fields'     => 'all',
                ) );

                if ( is_wp_error( $terms ) ) {
                    WP_CLI::warning( "Error fetching terms for {$tax}: " . $terms->get_error_message() );
                    continue;
                }

                foreach ( $terms as $term ) {
                    // skip if description exists
                    if ( trim( $term->description ) !== '' ) {
                        continue;
                    }

                    // gather sample product IDs that use this term (limit 3)
                    $sample_ids = get_posts( array(
                        'post_type'      => 'product',
                        'posts_per_page' => 3,
                        'fields'         => 'ids',
                        'tax_query'      => array(
                            array(
                                'taxonomy' => $tax,
                                'field'    => 'term_id',
                                'terms'    => $term->term_id,
                                'operator' => 'IN',
                            ),
                        ),
                    ) );

                    // get sample titles (safe to call get_the_title on IDs)
                    $sample_titles = array();
                    if ( ! empty( $sample_ids ) ) {
                        foreach ( $sample_ids as $pid ) {
                            $sample_titles[] = html_entity_decode( get_the_title( $pid ), ENT_QUOTES );
                        }
                    }

                    $row = array(
                        $term->term_id,
                        $tax,
                        $term->name,
                        $term->slug,
                        $term->description,
                        intval( $term->count ),
                        ! empty( $sample_ids ) ? implode( '|', $sample_ids ) : '',
                        ! empty( $sample_titles ) ? implode( '|', $sample_titles ) : '',
                        '' // generated_description left empty for now
                    );

                    fputcsv( $fp, $row );

                    $found++;
                    if ( $found >= $limit ) break 2; // done
                }
            }

            fclose( $fp );

            WP_CLI::success( "Export complete. Exported {$found} terms to: {$file}" );
            WP_CLI::line( "CSV file: {$file}" );
        }
    }

    WP_CLI::add_command( 'attrs:export-empty', 'Export_Empty_PA_Attributes_Command' );
}
