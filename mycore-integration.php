<?php
/**
 * Plugin Name: MyCoRe-Integration
 * Description: Use MyCoRe/Mir-Mods-data in wordpress-instances
 * Version:     0.1.0
 *
 */

define( 'MYCORE_INTEGRATION_URL',  plugin_dir_url( __FILE__ ) );
define( 'MYCORE_INTEGRATION_PATH', plugin_dir_path( __FILE__ ) );

require_once MYCORE_INTEGRATION_PATH . 'mycore-helpers.php';

// load the template corresponding to the selected file 
function mycore_load_template(): void {
    $name = get_option( 'mycore_integration_template_file' );
    if ( empty( $name ) ) {
        return;
    }
    $path = MYCORE_INTEGRATION_PATH . "templates/{$name}.template.php";
    if ( file_exists( $path ) ) {
        include_once $path;
    }
}
add_action( 'plugins_loaded', 'mycore_load_template' );


// register settings
function mycore_register_settings(): void {
    add_option( 'mycore_integration_api_url',       '' );
    add_option( 'mycore_integration_template_file', '' );

    register_setting( 'mycore_integration_options_group', 'mycore_integration_api_url' );
    register_setting( 'mycore_integration_options_group', 'mycore_integration_template_file' );
}
add_action( 'admin_init', 'mycore_register_settings' );

// add settings page
function mycore_add_settings_page(): void {
    add_options_page(
        'MyCoRe Integration Settings', 'MyCoRe Integration Settings',
        'manage_options', 'mycore_integration_settings', 'mycore_render_settings_page'
    );
}
add_action( 'admin_menu', 'mycore_add_settings_page' );

// render settings page
function mycore_render_settings_page(): void {
    $dir       = MYCORE_INTEGRATION_PATH . 'templates';
    $files     = is_dir( $dir ) ? scandir( $dir ) : [];
    $templates = array_map(
        fn( $f ) => str_replace( '.template.php', '', $f ),
        array_filter( $files, fn( $f ) => str_contains( $f, '.template.php' ) )
    );
    ?>
    <div class="wrap">
        <h2>MyCoRe Integration Settings</h2>
        <form method="post" action="options.php">
            <?php settings_fields( 'mycore_integration_options_group' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">MyCoRe-URL:</th>
                    <td><input type="text" name="mycore_integration_api_url" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'mycore_integration_api_url' ) ); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Template auswählen:</th>
                    <td>
                        <select name="mycore_integration_template_file">
                            <option value=""></option>
                            <?php foreach ( $templates as $t ) : ?>
                                <option value="<?php echo esc_attr( $t ); ?>"
                                    <?php selected( get_option( 'mycore_integration_template_file' ), $t ); ?>>
                                    <?php echo esc_html( $t ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Manueller Reload</th>
                    <td>
                        <button id="mycore-integration-manual-reload-btn" class="button button-primary">Ausführen</button>
                        <div style="margin-top:20px;">
                            <progress id="mycore-integration-manual-reload-progressbar" value="0" max="100" style="width:300px;"></progress>
                            <span id="mycore-integration-manual-reload-progress">0 %</span>
                        </div>
                    </td>
                    <td>
                        <?php if ( get_option( 'mycore_integration_manual_reload_running', false ) ) : ?>
                         <button id="mycore-integration-reset-btn" class="button" style="margin-left:8px;">
                            Flag zurücksetzen
                       </button>
                       <?php endif; ?></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// reset flag for manual reload, only needed if manual reload is aborted while loading
add_action( 'wp_ajax_mycore_integration_reset_reload', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Keine Berechtigung.' );
    update_option( 'mycore_integration_manual_reload_running', false );
    wp_send_json_success( 'Flag zurückgesetzt.' );
} );



// incorporate the js-script for the manual reload
function mycore_enqueue_reload_script( string $hook ): void {
    if ( $hook !== 'settings_page_mycore_integration_settings' ) {
        return;
    }
    wp_enqueue_script( 'mycore-manual-reload', MYCORE_INTEGRATION_URL . 'js/mycore-plugin-manual-reload.js', [ 'jquery' ], null, true );
    wp_localize_script( 'mycore-manual-reload', 'mycoreIntegrationManualReloadAjax', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'mycore_integration_manual_reload' ),
    ] );
}
add_action( 'admin_enqueue_scripts', 'mycore_enqueue_reload_script' );

// start the manual reload
add_action( 'wp_ajax_mycore_integration_manual_reload', function () {
    check_ajax_referer( 'mycore_integration_manual_reload' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Keine Berechtigung.' );
    if ( get_option( 'mycore_integration_manual_reload_running', false ) ) wp_send_json_error( 'Läuft bereits.' );

    update_option( 'mycore_integration_manual_reload_progress', 0 );
    update_option( 'mycore_integration_manual_reload_running', true );
    exec( 'php ' . escapeshellarg( MYCORE_INTEGRATION_PATH . 'manual-reload-background.php' ) . ' > /dev/null 2>&1 &' );
    wp_send_json_success( 'Job gestartet.' );
} );

// check progress of manual reload
add_action( 'wp_ajax_mycore_integration_manual_progress', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Keine Berechtigung.' );
    wp_send_json_success( (int) get_option( 'mycore_integration_manual_reload_progress', 0 ) );
} );


// adding a meta box for the mycore object id
add_action( 'add_meta_boxes', function () {
    add_meta_box( 'mycore_object_id', 'MyCoRe Object ID', function ( WP_Post $post ) {
        $value = get_post_meta( $post->ID, 'mycore_external_object_id', true );
        echo '<input type="text" name="mycore_external_object_id" value="' . esc_attr( $value ) . '" class="widefat" />';
    }, 'post', 'normal', 'high' );
} );

// save object id as mycore_external_object_id
add_action( 'save_post', function ( int $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) )   return;
    if ( isset( $_POST['mycore_external_object_id'] ) ) {
        update_post_meta( $post_id, 'mycore_external_object_id',
            sanitize_text_field( $_POST['mycore_external_object_id'] )
        );
    }
} );


// synchronizing the post with data from mycore backend based on the template via saving the post
add_action( 'save_post', 'mycore_sync_post', 20, 2 );

function mycore_sync_post( int $post_id, WP_Post $post ): void {
    if ( $post->post_type !== 'post' )                   return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) )               return;
    if ( ! is_admin() )                                   return;

    $object_id = get_post_meta( $post_id, 'mycore_external_object_id', true );
    if ( empty( $object_id ) ) return;

    mycore_sync_single_post( $post_id, $object_id );
}


 //fetches mycore data for a post and writes them to wordpress
 //used by mycore_sync_post and manual reload
 function mycore_sync_single_post( int $post_id, string $object_id ): bool {
    if ( ! function_exists( 'getDataFromTemplate' ) ) return false;

    $api_url = rtrim( get_option( 'mycore_integration_api_url' ), '/' );

    $obj = wp_remote_get( "{$api_url}/api/v2/objects/{$object_id}", [ 'timeout' => 20 ] );
    $der = wp_remote_get( "{$api_url}/api/v2/objects/{$object_id}/derivates", [ 'timeout' => 20 ] );
    if ( is_wp_error( $obj ) || is_wp_error( $der ) ) return false;

    $data = getDataFromTemplate(
        wp_remote_retrieve_body( $obj ),
        wp_remote_retrieve_body( $der ),
        $post_id
    );
    if ( empty( $data ) ) return false;

    $title = $data['post_title']
        . ( ! empty( $data['post_subtitle'] ) ? "\n" . $data['post_subtitle'] : '' );

    remove_action( 'save_post', 'mycore_sync_post', 20 );
    wp_update_post( [ 'ID' => $post_id, 'post_title' => $title, 'post_content' => $data['post_content'] ] );
    add_action( 'save_post', 'mycore_sync_post', 20, 2 );

    if ( ! empty( $data['post_coordinates'] ) ) {
        [ $lng, $lat ] = array_map( 'trim', explode( ' ', $data['post_coordinates'], 2 ) );
        update_post_meta( $post_id, 'lng', $lng );
        update_post_meta( $post_id, 'lat', $lat );
    }

    foreach ( $data['meta'] ?? [] as $key => $value ) {
        ! empty( $value ) ? update_post_meta( $post_id, $key, $value ) : null;
    }
    foreach ( array_merge( $data['meta_arrays'] ?? [], $data['js_data'] ?? [] ) as $key => $value ) {
        ! empty( $value ) ? update_post_meta( $post_id, $key, $value ) : delete_post_meta( $post_id, $key );
    }

    if ( ! empty( $data['post_thumbnail'] ) ) {
        mycore_set_thumbnail( $post_id, $title, $data['post_thumbnail'] );
    } else {
        delete_post_thumbnail( $post_id );
    }

    return true;
}

// set an image as the post's thumbnail
function mycore_set_thumbnail( int $post_id, string $title, string $url ): void {
    $existing = get_posts( [
        'post_type' => 'attachment', 'posts_per_page' => 1, 'fields' => 'ids',
        'meta_query' => [ [ 'key' => 'source_image_url', 'value' => $url ] ],
    ] );

    $attach_id = ! empty( $existing ) ? $existing[0] : mycore_upload_image( $title, $url );
    if ( $attach_id ) {
        if ( empty( $existing ) ) update_post_meta( $attach_id, 'source_image_url', $url );
        set_post_thumbnail( $post_id, $attach_id );
    }
}

// helper function to upload an image
function mycore_upload_image( string $title, string $url ): int {
    $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
    if ( is_wp_error( $response ) ) return 0;

    $data = wp_remote_retrieve_body( $response );
    if ( empty( $data ) ) return 0;

    $upload    = wp_upload_dir();
    $ext       = pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'jpg';
    $filename  = sanitize_file_name( substr( md5( $url ), 0, 12 ) . '.' . $ext );
    $filepath  = trailingslashit( $upload['path'] ) . $filename;

    file_put_contents( $filepath, $data );

    $type      = wp_check_filetype( $filename );
    $attach_id = wp_insert_attachment( [
        'post_mime_type' => $type['type'],
        'post_title'     => sanitize_text_field( $title ),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ], $filepath );

    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $filepath ) );

    return $attach_id;
}


// binding javascript frontend scripts
function mycore_enqueue_post_scripts(): void {
    if ( ! is_single() ) {
        return;
    }
    do_action( 'mycore_integration_enqueue_post_scripts', get_the_ID() );
}
add_action( 'wp_enqueue_scripts', 'mycore_enqueue_post_scripts' );