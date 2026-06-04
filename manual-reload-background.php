<?php

require_once dirname( __FILE__, 4 ) . '/wp-load.php';

if ( ! function_exists( 'getDataFromTemplate' ) ) {
    error_log( 'MycoreIntegration manual-reload: Kein Template geladen. Abbruch.' );
    update_option( 'mycore_integration_manual_reload_running', false );
    exit( 1 );
}

global $wpdb;
$post_ids = $wpdb->get_col( $wpdb->prepare(
    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
    'mycore_external_object_id'
) );

if ( empty( $post_ids ) ) {
    update_option( 'mycore_integration_manual_reload_progress', 100 );
    update_option( 'mycore_integration_manual_reload_running',  false );
    exit( 0 );
}

$total   = count( $post_ids );
$counter = 0;
update_option( 'mycore_integration_manual_reload_progress', 0 );

foreach ( $post_ids as $post_id ) {
    $post_id   = (int) $post_id;
    $object_id = get_post_meta( $post_id, 'mycore_external_object_id', true );

    if ( empty( $object_id ) ) {
        $counter++;
        continue;
    }

    $success = mycore_sync_single_post( $post_id, $object_id );
    error_log( $success
        ? "MycoreIntegration manual-reload: Post {$post_id} aktualisiert."
        : "MycoreIntegration manual-reload: Post {$post_id} – keine Daten erhalten."
    );

    $counter++;
    update_option( 'mycore_integration_manual_reload_progress', (int) round( $counter / $total * 100 ) );
}

update_option( 'mycore_integration_manual_reload_progress', 100 );
update_option( 'mycore_integration_manual_reload_running',  false );
error_log( "MycoreIntegration manual-reload: Abgeschlossen. {$counter}/{$total} Posts verarbeitet." );