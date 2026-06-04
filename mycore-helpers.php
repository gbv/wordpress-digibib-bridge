<?php

// a collection of generic functions to parse mods-xml and mycore data structure


// fetch mods xml of a mycore object
function mycore_fetch_xml( string $url ): ?SimpleXMLElement {
    $response = wp_remote_get( $url, [
        'timeout' => 20,
        'headers' => [ 'Accept' => 'application/xml' ],
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( "MycoreIntegration: HTTP-Fehler für {$url}: " . $response->get_error_message() );
        return null;
    }

    return mycore_parse_xml( wp_remote_retrieve_body( $response ) );
}

// parses xml string and introduces standard namespaces (+mods)
function mycore_parse_xml( string $xml_string ): ?SimpleXMLElement {
    $xml = simplexml_load_string( $xml_string );
    if ( $xml === false ) {
        return null;
    }

    $xml->registerXPathNamespace( 'mods',  'http://www.loc.gov/mods/v3' );
    $xml->registerXPathNamespace( 'xlink', 'http://www.w3.org/1999/xlink' );
    $xml->registerXPathNamespace( 'xml',   'http://www.w3.org/XML/1998/namespace' );

    return $xml;
}

// splits a URI at #
function mycore_split_uri( string $uri ): array {
    $pos = strpos( $uri, '#' );
    return $pos !== false
        ? [ substr( $uri, 0, $pos ), substr( $uri, $pos + 1 ) ]
        : [ $uri, '' ];
}


// returns classification label based on the classfication's uri in the object's xml
function mycore_resolve_label( string $value_uri ): string {
    [ $base_uri, $fragment ] = mycore_split_uri( $value_uri );

    $xml = mycore_fetch_xml( $base_uri );
    if ( $xml === null ) {
        return '';
    }

    $nodes = $xml->xpath( "//*[@ID='{$fragment}']" );
    if ( empty( $nodes ) ) {
        return '';
    }

    $labels = $nodes[0]->xpath( "label[@xml:lang='de']" );
    return ! empty( $labels ) ? (string) $labels[0]['text'] : '';
}

// reads classfications from object's xml based on display label and meta key
function mycore_parse_classifications( SimpleXMLElement $xml, array $map ): array {
    $result = array_fill_keys( array_values( $map ), '' );

    foreach ( $xml->xpath( '//mods:classification' ) ?? [] as $node ) {
        $display_label = (string) $node['displayLabel'];
        if ( ! isset( $map[ $display_label ] ) ) {
            continue;
        }
        $label = mycore_resolve_label( (string) $node['valueURI'] );
        if ( $label !== '' ) {
            $result[ $map[ $display_label ] ] .= $label;
        }
    }

    return $result;
}


// retrieves the tei's content transformed to html
function mycore_fetch_tei_content( string $derivate_id ): string {
    $base     = rtrim(get_option( 'mycore_integration_api_url' )) . '//servlets/MCRDerivateContentTransformerServlet/' ;
    $response = wp_remote_get( $base . $derivate_id . '/tei/0001.xml?XSL.Transformer=TEI-html-koloe' );
    return is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
}

// returns all urls of images in a derivate
function mycore_fetch_image_urls( string $base_url, string $object_id, string $derivate_id ): array {
    $xml = mycore_fetch_xml(
        "{$base_url}/api/v2/objects/{$object_id}/derivates/{$derivate_id}/contents"
    );
    if ( $xml === null ) {
        return [];
    }

    $urls = [];
    foreach ( $xml->xpath( '//file' ) as $file ) {
        if ( strpos( (string) $file->attributes()->mimeType, 'image/' ) === 0 ) {
            $urls[] = "{$base_url}/api/v2/objects/{$object_id}/derivates/{$derivate_id}/contents/"
                    . trim( (string) $file->attributes()->name );
        }
    }

    return $urls;
}

// checks if a derivate contains a tei directory
function mycore_has_transcript( string $base_url, string $object_id, string $derivate_id ): bool {
    $xml = mycore_fetch_xml(
        "{$base_url}/api/v2/objects/{$object_id}/derivates/{$derivate_id}/contents"
    );
    if ( $xml === null ) {
        return false;
    }
    foreach ( $xml->directory as $dir ) {
        if ( (string) $dir['name'] === 'tei' ) {
            return true;
        }
    }
    return false;
}


// generates a html block consisting of a label: and a value
function mycore_block_columns( string $label, string $value ): string {
    return '
    <div class="wp-block-columns is-layout-flex wp-block-columns-is-layout-flex" style="margin-bottom:0">
        <div class="wp-block-column" style="flex-basis:40%"><p><strong>'
            . esc_html( $label ) . ':</strong></p></div>
        <div class="wp-block-column" style="flex-basis:60%"><p>' . $value . '</p></div>
    </div>';
}