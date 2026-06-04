<?php
/** 
 * Registers the [mycore_map] shortcode that renders the Leaflet map
 * USAGE IN WORDPRESS:
 *   Add [mycore_map] to any page/post via the editor.
 *
 * SHORTCODE ATTRIBUTES:
 *   height        Map height in px (default: 500)
 *   post_type     Post type to query (default: post)
 *   center_lat    Initial map center latitude  (default: 51.1657 = Germany center)
 *   center_lng    Initial map center longitude (default: 10.4515)
 *   zoom          Initial zoom level (default: 6)
 *   tile_url      Leaflet tile URL (default: OpenStreetMap)
 *
 * EXAMPLE:
 *   [mycore_map height="600" center_lat="48.13" center_lng="11.57" zoom="10"]
 *
 * DEPENDENCIES:
 *   - Leaflet.js and Leaflet.css loaded from CDN (no local install needed)
 *   - /assets/js/mycore-map.js  (this plugin)
 *   - /assets/css/mycore-map.css (this plugin)
 *
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'mycore_map', 'mycore_ext_render_map' );

// renders the map
function mycore_ext_render_map( array $atts ): string {

    $atts = shortcode_atts( [
        'height'     => '500',
        'post_type'  => 'post',
        'center_lat' => '51.1657',
        'center_lng' => '10.4515',
        'zoom'       => '6',
        'tile_url'   => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    ], $atts, 'mycore_map' );

    // Sanitize
    $height     = absint( $atts['height'] ) ?: 500;
    $post_type  = sanitize_key( $atts['post_type'] );
    $center_lat = (float) $atts['center_lat'];
    $center_lng = (float) $atts['center_lng'];
    $zoom       = absint( $atts['zoom'] ) ?: 6;
    $tile_url = sanitize_text_field( $atts['tile_url'] );

    // Enqueue assets only when shortcode is actually used
    mycore_ext_enqueue_map_assets();

    // Build REST endpoint URL
    $api_url = esc_url( rest_url( 'mycore/v1/map-data' ) );

    // Pass config to JS via wp_localize_script (called after enqueue)
    wp_localize_script( 'mycore-map', 'MyCoreMapConfig', [
        'apiUrl'    => $api_url,
        'postType'  => $post_type,
        'centerLat' => $center_lat,
        'centerLng' => $center_lng,
        'zoom'      => $zoom,
        'tileUrl'   => $tile_url,
        'nonce'     => wp_create_nonce( 'wp_rest' ),
        'pluginUrl' => plugin_dir_url( __FILE__ ),
    ] );

    ob_start();
    ?>
    <div class="mycore-map-wrap">

          <div id="mycore-map-filters" class="mycore-map-filters"></div>

        <?php /* ── Map Canvas ──────────────────────────────────────────────────── */ ?>
        <div id="mycore-map"
             style="height:<?php echo $height; ?>px; width:100%;"
             aria-label="<?php esc_attr_e( 'Interactive map of records', 'mycore-ext' ); ?>">
        </div>

    </div>
    <?php
    return ob_get_clean();
}

// enqueues  all scripts and stylesheets
function mycore_ext_enqueue_map_assets(): void {
    // Leaflet CSS
    wp_enqueue_style(
        'leaflet',
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
        [],
        '1.9.4'
    );

    // Leaflet JS
    wp_enqueue_script(
        'leaflet',
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
        [],
        '1.9.4',
        true
    );

    wp_enqueue_style(
    'leaflet-markercluster',
    'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css',
    ['leaflet'],
    '1.5.3'
    );
    wp_enqueue_style(
        'leaflet-markercluster-default',
        'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css',
        ['leaflet-markercluster'],
        '1.5.3'
    );
    wp_enqueue_script(
        'leaflet-markercluster',
        'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js',
        ['leaflet'],
        '1.5.3',
        true
    );

    // Our map logic
    wp_enqueue_script(
        'mycore-map',
        plugin_dir_url( __FILE__ ) . 'js/mycore-map.js',
        [ 'leaflet' ],
        '1.0.0',
        true
    );

    // Our map styles
    wp_enqueue_style(
        'mycore-map',
        plugin_dir_url( __FILE__ ) . 'css/mycore-map.css',
        [ 'leaflet' ],
        '1.0.0'
    );

    wp_enqueue_script(
        'mycore-map',
        plugin_dir_url( __FILE__ ) . 'js/mycore-map.js',
        [ 'leaflet', 'leaflet-markercluster' ],  // ← add it here
        '1.0.0',
        true
    );
}

