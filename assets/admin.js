/* WP Noise Injection — Admin JS */
jQuery( function ( $ ) {

    var providerSelect = $( '#wni_ai_provider' );
    var aiFields       = $( '.wni-ai-field' );
    var modelInput     = $( '#wni_ai_model' );

    // Show/hide API key and model fields based on selected provider.
    function updateAiFields() {
        var provider = providerSelect.val();
        if ( provider === 'none' ) {
            aiFields.hide();
        } else {
            aiFields.show();
            // Update model placeholder to reflect provider default.
            if ( wniAdmin.models && wniAdmin.models[ provider ] ) {
                modelInput.attr( 'placeholder', wniAdmin.models[ provider ] );
            }
        }
    }

    providerSelect.on( 'change', updateAiFields );

    // Test connection button.
    $( '#wni-test-ai' ).on( 'click', function () {
        var $btn    = $( this );
        var $result = $( '#wni-test-ai-result' );
        var action  = ( wniAdmin.action ) ? wniAdmin.action : 'wni_test_ai';

        $btn.prop( 'disabled', true ).text( 'Testing…' );
        $result.css( 'color', '' ).text( '' );

        $.post( wniAdmin.ajaxUrl, {
            action : action,
            nonce  : wniAdmin.nonce,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                $result.css( 'color', '#46b450' ).text( '✓ ' + res.data.message );
            } else {
                $result.css( 'color', '#dc3232' ).text( '✗ ' + res.data.message );
            }
        } )
        .fail( function () {
            $result.css( 'color', '#dc3232' ).text( '✗ Request failed.' );
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( 'Test API Key' );
        } );
    } );

} );
