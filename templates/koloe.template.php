<?php
/**
 * Koloe-Template
 *
 * Projektspezifische Logik für das Koloe-Projekt.
 * Definiert getDataFromTemplate() und registriert projektspezifische Hooks.
 *
 * Die generischen MyCoRe-Hilfsfunktionen (mycore_fetch_xml, mycore_parse_*,
 * mycore_fetch_image_urls usw.) kommen aus mycore-helpers.php und sind
 * hier direkt nutzbar.
 */



require_once MYCORE_INTEGRATION_PATH . 'map-endpoint.php';
require_once MYCORE_INTEGRATION_PATH . 'map-shortcode.php';

add_action( 'mycore_integration_enqueue_post_scripts', function ( int $post_id ) {
    $base_url  = rtrim( get_option( 'mycore_integration_api_url' ), '/' );
    wp_enqueue_script( 'post-image-buttons', MYCORE_INTEGRATION_URL . 'js/post-image-buttons.js', [], '1.0', true );
    wp_localize_script( 'post-image-buttons', 'PostImageData', [
        'contentImages'      => get_post_meta( $post_id, 'content_images',      true ) ?: [],
        'uncensoredImages' => get_post_meta( $post_id, 'uncensored_images', true ) ?: [],
        'viewer_uri'         => get_post_meta( $post_id, 'viewer_uri',           true ),
        'base_url'          => $base_url,
    ] );
} );


// reads the object's and derivates' mods-xml and writes specified data to wordpress posts
function getDataFromTemplate( string $xml_string, string $xml_derivate, int $post_id ): array {
    $xml     = mycore_parse_xml( $xml_string );
    $xml_der = mycore_parse_xml( $xml_derivate );

    if ( $xml === null || $xml_der === null ) {
        return [];
    }

    $base_url  = rtrim( get_option( 'mycore_integration_api_url' ), '/' );
    $object_id = get_post_meta( $post_id, 'mycore_external_object_id', true );

    $data = [
        'post_title'       => '',
        'post_subtitle'    => mycore_parse_subtitle( $xml ),
        'post_thumbnail'   => '',
        'post_content'     => mycore_parse_archive( $xml ) . mycore_parse_signature( $xml ),
        'post_coordinates' => mycore_parse_coordinates( $xml ),
        'meta'             => mycore_parse_classifications( $xml, [
            'koloe_document_classification' => 'Quellenart',
            'koloe_topic_classification'    => 'Thema',
            'koloe_continents'              => 'Kontinent',
            'koloe_period'                  => 'Zeitraum',
        ] ),
        'meta_arrays' => [ 'content_images' => [], 'uncensored_images' => [] ],
        'js_data'     => [ 'viewer_uri' => '' ],
    ];

    // Thumbnail
    $thumb_nodes = $xml_der->xpath( '//derobject[classification[@categid="thumbnail"]]' );
    if ( ! empty( $thumb_nodes ) ) {
        $did     = (string) $thumb_nodes[0]->attributes( 'xlink', true )->href;
        $maindoc = trim( (string) $thumb_nodes[0]->maindoc );
        if ( $did && $maindoc ) {
            $data['post_thumbnail'] = "{$base_url}/api/v2/objects/{$object_id}/derivates/{$did}/contents/{$maindoc}";
        }
    }

    // Content-Bilder + Viewer-URI
    $content_nodes = $xml_der->xpath( '//derobject[classification[@categid="content"]]/@xlink:href' );
    if ( ! empty( $content_nodes ) ) {
        $did    = (string) $content_nodes[0];
        $images = mycore_fetch_image_urls( $base_url, $object_id, $did );
        $data['meta_arrays']['content_images'] = $images;
        if ( ! empty( $images ) && mycore_has_transcript( $base_url, $object_id, $did ) ) {
            $data['js_data']['viewer_uri'] = $did . '/' . basename( $images[0] );
        }
    }

    // uncensorede Bilder
    $other_nodes = $xml_der->xpath( '//derobject[classification[@categid="content_other_version"]]' );
    if ( ! empty( $other_nodes ) ) {
        $did = (string) $other_nodes[0]->attributes( 'xlink', true )->href;
        $data['meta_arrays']['uncensored_images'] = mycore_fetch_image_urls( $base_url, $object_id, $did );
    }

    // Verwandte Objekte: Titel kommt aus dem ersten described_in-Objekt
    foreach ( [ 'described_in', 'described_in_b', 'described_in_c' ] as $i => $type ) {
        $data['post_content'] .= mycore_parse_related_objects(
            $xml, $type, $base_url, $i === 0, $data['post_title']
        );
    }

    return $data;
}

// turns raw headings into boldface 
function mycore_bold_tei_headings( string $html ): string {
    if ( $html === '' ) {
        return '';
    }

    $dom = new DOMDocument( '1.0', 'UTF-8' );
    libxml_use_internal_errors( true );
    $dom->loadHTML( '<meta charset="utf-8">' . $html );
    libxml_clear_errors();

    foreach ( ( new DOMXPath( $dom ) )->query( '//body//div' ) as $div ) {
        foreach ( iterator_to_array( $div->childNodes, false ) as $child ) {
            if ( $child->nodeType !== XML_TEXT_NODE || trim( $child->nodeValue ) === '' ) {
                continue;
            }
            $strong = $dom->createElement( 'strong' );
            $strong->appendChild( $dom->createTextNode( trim( $child->nodeValue ) ) );
            $div->replaceChild( $strong, $child );
            break;
        }
    }

    $body   = $dom->getElementsByTagName( 'body' )->item( 0 );
    $result = '';
    foreach ( $body->childNodes as $node ) {
        $result .= $dom->saveHTML( $node );
    }
    return $result;
}

// reads the archives' name and returns a complete html-Block
function mycore_parse_archive( SimpleXMLElement $xml ): string {
    $nodes = $xml->xpath( '//mods:location/mods:physicalLocation[@type="repository"]/@valueURI' );
    if ( empty( $nodes ) ) {
        return '';
    }

    [ $base_uri, $fragment ] = mycore_split_uri( (string) $nodes[0] );
    $remote = mycore_fetch_xml( $base_uri );
    if ( $remote === null ) {
        return '';
    }

    $remote->registerXPathNamespace( 'xlink', 'http://www.w3.org/1999/xlink' );
    $matched = $remote->xpath( "//*[@ID='{$fragment}']" );
    if ( empty( $matched ) ) {
        return '';
    }

    $label_nodes = $matched[0]->xpath( "label[@xml:lang='de']" );
    $url_nodes   = $matched[0]->xpath( "url[@xlink:type='locator']/@xlink:href" );
    if ( empty( $label_nodes ) ) {
        return '';
    }

    $description = (string) $label_nodes[0]['description'];
    $text        = ! empty( $url_nodes )
        ? '<a href="' . esc_url( (string) $url_nodes[0] ) . '">' . esc_html( $description ) . '</a>'
        : esc_html( $description );

    return mycore_block_columns( 'Archiv', $text );
}

// reads the signature node and returns a complete html block
function mycore_parse_signature( SimpleXMLElement $xml ): string {
    $sig = $xml->xpath( '//mods:location/mods:shelfLocator' );
    $url = $xml->xpath( '//mods:location/mods:url' );

    if ( empty( $sig ) ) {
        return '';
    }

    $text = esc_html( trim( (string) $sig[0] ) );
    if ( ! empty( $url ) ) {
        $text = '<a href="' . esc_url( trim( (string) $url[0] ) ) . '">' . $text . '</a>';
    }

    return mycore_block_columns( 'Signatur', $text );
}

// reads the subtitle
function mycore_parse_subtitle( SimpleXMLElement $xml ): string {
    $nodes = $xml->xpath( '//mods:titleInfo/mods:subTitle' );
    return ! empty( $nodes ) ? trim( (string) $nodes[0] ) : '';
}

// reads the coordinates
function mycore_parse_coordinates( SimpleXMLElement $xml ): string {
    $nodes = $xml->xpath( '//mods:subject/mods:cartographics/mods:coordinates' );
    return ! empty( $nodes ) ? (string) $nodes[0] : '';
}

// reads related objects
function mycore_parse_related_objects(
    SimpleXMLElement $xml,
    string $other_type,
    string $base_url,
    bool $is_title_slot,
    string &$post_title
): string {
    $id_nodes = $xml->xpath( '//mods:relatedItem[@otherType="' . $other_type . '"]/@xlink:href' );
    if ( empty( $id_nodes ) ) {
        return '';
    }

    $html = '';

    foreach ( $id_nodes as $id_node ) {
        $id      = (string) $id_node;
        $obj_xml = mycore_fetch_xml( "{$base_url}/api/v2/objects/{$id}" );
        if ( $obj_xml === null ) {
            continue;
        }

        $title       = '';
        $title_nodes = $obj_xml->xpath( '//mods:mods/mods:titleInfo/mods:title' );
        if ( ! empty( $title_nodes ) ) {
            $title = trim( (string) $title_nodes[0] );
        }

        if ( $is_title_slot && $post_title === '' && $title !== '' ) {
            $post_title = $title;
        }

        $der_xml = mycore_fetch_xml( "{$base_url}/api/v2/objects/{$id}/derivates" );
        if ( $der_xml === null ) {
            continue;
        }

        $der_nodes = $der_xml->xpath( '//derobject[classification[@categid="content"]]/@xlink:href' );
        if ( empty( $der_nodes ) ) {
            continue;
        }

        $derivate = mycore_bold_tei_headings( mycore_fetch_tei_content( (string) $der_nodes[0] ) );

        if ( $derivate !== '' ) {
            $html .= '<div style="margin-bottom: 2em;">' . $derivate . '</div>';
        }
    }

    return $html;
}