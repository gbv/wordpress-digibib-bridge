<?php
/**
 * map-endpoint.php
 *
 * Registers a lightweight WP REST API endpoint that returns all posts
 * with geodata, used by the Leaflet map frontend.
 *
 * ENDPOINT:
 *   GET /wp-json/mycore/v1/map-data
 *
 * RESPONSE (JSON array):
 * [
 *   {
 *     "id": 1332,
 *     "title": "Koloniale Sehnsüchte in der Weimarer Republik: Ein Flugblatt der Deutschen Kolonialgesellschaft",
 *     "lat": 52.502777777778,
 *     "lng": 9.4627777777778,
 *     "categories": [Zeitraum: "Nach dem Ende der formellen deutschen Kolonialherrschaft",
 *                    Kontinent: "Afrika",
 *                    ...],
 *     "thumbnail": "https://...",
 *     "permalink": "https://..."
 *   }, ...
 * ]
 * 
 * 
 *
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// initializing the rest api
add_action( 'rest_api_init', function () {
    register_rest_route( 'mycore/v1', '/map-data', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'mycore_ext_map_data_handler',
        'permission_callback' => '__return_true',  // Public endpoint
        'args'                => [
            'post_type' => [
                'default'           => 'post',
                'sanitize_callback' => 'sanitize_key',
            ],
            'category' => [
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ] );
} );


function mycore_ext_map_data_handler( WP_REST_Request $request ): WP_REST_Response {

    global $wpdb;
    //$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mycore_ext_map_data_%'");


    $post_type      = $request->get_param( 'post_type' );
    $filter_category = $request->get_param( 'category' );

    // Cache key includes filters so different filter combos don't collide
    $cache_key = 'mycore_ext_map_data_' . md5( $post_type . '|' . $filter_category );
    $cached    = get_transient( $cache_key );

    if ( $cached !== false ) {
        return new WP_REST_Response( $cached, 200 );
    }

    //  Build WP_Query meta query
    $meta_query = [
        'relation' => 'AND',
        [
            'key'     => 'lat',
            'compare' => 'EXISTS',
        ],
        [
            'key'     => 'lng',
            'compare' => 'EXISTS',
        ],
    ];

    $args = [
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => -1,           // All geo-tagged posts
        'meta_query'     => $meta_query,
        'no_found_rows'  => true,         // Faster: skip pagination count
        'fields'         => 'ids',        // Only fetch IDs first
    ];

    $query = new WP_Query( $args );
    $items = [];

    foreach ( $query->posts as $post_id ) {
        $lat        = (float) get_post_meta( $post_id, 'lat', true );
        $lng        = (float) get_post_meta( $post_id, 'lng', true );
        

        $period      = get_post_meta( $post_id, 'Zeitraum', true );
        $topic       = get_post_meta( $post_id, 'Thema', true );
        $source_type = get_post_meta( $post_id, 'Quellenart', true );
        $continent   = get_post_meta( $post_id, 'Kontinent', true );

        // Split comma-separated values into arrays
        $split = fn( $val ) => $val
            ? array_map( 'trim', explode( ',', $val ) )
            : [];

        $categories = [
            'Zeitraum'   => $split( $period ),
            'Thema'      => $split( $topic ),
            'Quellenart' => $split( $source_type ),
            'Kontinent'  => $split( $continent ),
        ];

        // Apply filter  check across all category types
        if ( $filter_category !== '' ) {
            $all_values = array_merge( ...array_values( $categories ) );
            if ( ! in_array( $filter_category, $all_values, true ) ) {
                continue;
            }
        }

        // Skip posts with 0,0 coordinates
        if ( $lat === 0.0 && $lng === 0.0 ) continue;

        $thumbnail = get_the_post_thumbnail_url( $post_id, 'medium' ) ?: null;

        $items[] = [
            'id'         => $post_id,
            'title'      => get_the_title( $post_id ),
            'lat'        => $lat,
            'lng'        => $lng,
            'categories' => $categories,  
            'thumbnail'  => $thumbnail,
            'permalink'  => get_permalink( $post_id ),
        ];
    
    }

    // Cache for 1 hour
    set_transient( $cache_key, $items, HOUR_IN_SECONDS );

    return new WP_REST_Response( $items, 200 );
}

//Clear all map data transients when any post is saved.
add_action( 'save_post', function ( int $post_id ) {
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_mycore_ext_map_data_%'
            OR option_name LIKE '_transient_timeout_mycore_ext_map_data_%'"
    );
} );
