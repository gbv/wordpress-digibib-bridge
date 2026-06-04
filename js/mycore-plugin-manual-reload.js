
jQuery( document ).ready( function ( $ ) {

    const $btn      = $( '#mycore-integration-manual-reload-btn' );
    const $bar      = $( '#mycore-integration-manual-reload-progressbar' );
    const $label    = $( '#mycore-integration-manual-reload-progress' );
    const ajaxUrl   = mycoreIntegrationManualReloadAjax.ajaxurl;
    const nonce     = mycoreIntegrationManualReloadAjax.nonce;

    // set progress bar to current value
    function setProgress( value ) {
        $bar.val( value );
        $label.text( value + ' %' );
    }

    // disables / enables start button
    function setButtonEnabled( enabled ) {
        $btn.prop( 'disabled', ! enabled );
        $btn.text( enabled ? 'Ausführen' : 'Läuft …' );
    }

    // queries the progress every 2 seconds, 
    function startProgressPolling() {
        const interval = setInterval( function () {
            $.post(
                ajaxUrl,
                {
                    action:      'mycore_integration_manual_progress',
                    _ajax_nonce: nonce,
                },
                function ( response ) {
                    if ( ! response || ! response.success ) {
                        clearInterval( interval );
                        setButtonEnabled( true );
                        alert( 'Fortschrittsabfrage fehlgeschlagen.' );
                        return;
                    }

                    const progress = parseInt( response.data, 10 );
                    setProgress( progress );

                    if ( progress >= 100 ) {
                        clearInterval( interval );
                        setButtonEnabled( true );
                        alert( 'Reload abgeschlossen!' );
                    }
                }
            ).fail( function () {
                clearInterval( interval );
                setButtonEnabled( true );
                alert( 'Verbindungsfehler bei der Fortschrittsabfrage.' );
            } );
        }, 2000 );
    }

    // start button
    $btn.on( 'click', function ( e ) {
        e.preventDefault();

        setButtonEnabled( false );
        setProgress( 0 );

        $.post(
            ajaxUrl,
            {
                action:      'mycore_integration_manual_reload',
                _ajax_nonce: nonce,
            },
            function ( response ) {
                if ( response && response.success ) {
                    startProgressPolling();
                } else {
                    setButtonEnabled( true );
                    alert( 'Fehler: ' + ( response && response.data ? response.data : 'unbekannt' ) );
                }
            }
        ).fail( function () {
            setButtonEnabled( true );
            alert( 'Verbindungsfehler beim Starten des Jobs.' );
        } );
    } );

    // button for resetting the flag if manual reload was aborted
    $( '#mycore-integration-reset-btn' ).on( 'click', function (e) {
        e.preventDefault();
        $.post( ajaxUrl, {
            action: 'mycore_integration_reset_reload',
            _ajax_nonce: nonce,
        }, function () {
            location.reload();
        });
    });

} );