/* WP Noise Injection — Dashboard Widget JS */
jQuery( function ( $ ) {
    $( '#wni-quick-generate' ).on( 'click', function ( e ) {
        e.preventDefault();

        var $btn    = $( this );
        var $status = $( '#wni-generate-status' );

        $btn.prop( 'disabled', true ).text( 'Generating…' );
        $status.removeClass( 'wni-error' ).text( '' );

        $.post( wniAjax.url, {
            action : 'wni_quick_generate',
            nonce  : wniAjax.nonce,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                $status.html( '✓ ' + res.data.message );
            } else {
                $status.addClass( 'wni-error' ).text( '✗ ' + res.data.message );
            }
        } )
        .fail( function () {
            $status.addClass( 'wni-error' ).text( '✗ Request failed. Try again.' );
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( 'Generate Draft Post' );
        } );
    } );
} );
