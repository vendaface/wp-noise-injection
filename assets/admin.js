/* WP Noise Injection — Admin JS */
jQuery( function ( $ ) {

    // -------------------------------------------------------------------------
    // AI provider field visibility
    // -------------------------------------------------------------------------

    var providerSelect = $( '#wni_ai_provider, #mni_ai_provider' );
    var aiFields       = $( '.wni-ai-field' );
    var modelInput     = $( '#wni_ai_model, #mni_ai_model' );

    function updateAiFields() {
        var provider = providerSelect.val();
        if ( provider === 'none' ) {
            aiFields.hide();
        } else {
            aiFields.show();
            if ( wniAdmin.models && wniAdmin.models[ provider ] ) {
                modelInput.attr( 'placeholder', wniAdmin.models[ provider ] );
            }
        }
    }

    providerSelect.on( 'change', updateAiFields );

    // -------------------------------------------------------------------------
    // Test AI connection
    // -------------------------------------------------------------------------

    $( '#wni-test-ai' ).on( 'click', function () {
        var $btn    = $( this );
        var $result = $( '#wni-test-ai-result' );
        var action  = wniAdmin.action || 'wni_test_ai';

        $btn.prop( 'disabled', true ).text( 'Testing…' );
        $result.css( 'color', '' ).text( '' );

        $.post( wniAdmin.ajaxUrl, { action: action, nonce: wniAdmin.nonce } )
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

    // -------------------------------------------------------------------------
    // Generate Topics from Bio
    // -------------------------------------------------------------------------

    $( '#wni-generate-topics' ).on( 'click', function () {
        var $btn    = $( this );
        var $result = $( '#wni-topics-result' );
        var action  = wniAdmin.generateTopicsAction || 'wni_generate_topics';

        if ( ! confirm( 'This will replace all existing topic buckets (seeds will be cleared). Continue?' ) ) {
            return;
        }

        $btn.prop( 'disabled', true ).text( 'Generating…' );
        $result.css( 'color', '' ).text( '' );

        $.post( wniAdmin.ajaxUrl, {
            action : action,
            nonce  : wniAdmin.generateTopicsNonce,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                $result.css( 'color', '#46b450' ).html(
                    '✓ ' + res.data.message +
                    ' <a href="' + wniAdmin.topicsUrl + '">Go to Topic Buckets →</a>'
                );
            } else {
                $result.css( 'color', '#dc3232' ).text( '✗ ' + res.data.message );
            }
        } )
        .fail( function () {
            $result.css( 'color', '#dc3232' ).text( '✗ Request failed.' );
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( 'Generate Topics from Bio' );
        } );
    } );

    // -------------------------------------------------------------------------
    // Generate Seeds from Bio — delegated (topic blocks are server-rendered)
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.wni-generate-seeds', function () {
        var $btn        = $( this );
        var $block      = $btn.closest( '.wni-topic-block, .mni-topic-block, .postbox' );
        var $textarea   = $block.find( 'textarea[name*="seeds_text"]' );
        var $status     = $btn.siblings( '.wni-seeds-status' );
        var topicLabel  = $btn.data( 'topic-label' );
        var action      = wniAdmin.generateSeedsAction || 'wni_generate_seeds';

        $btn.prop( 'disabled', true ).text( 'Generating…' );
        $status.css( 'color', '' ).text( '' );

        $.post( wniAdmin.ajaxUrl, {
            action      : action,
            nonce       : wniAdmin.generateSeedsNonce,
            topic_label : topicLabel,
        } )
        .done( function ( res ) {
            if ( res.success && res.data.seeds ) {
                $textarea.val( res.data.seeds.join( '\n' ) );
                $status.css( 'color', '#46b450' ).text( '✓ ' + res.data.seeds.length + ' seeds generated. Review and save.' );
            } else {
                $status.css( 'color', '#dc3232' ).text( '✗ ' + ( res.data.message || 'Failed.' ) );
            }
        } )
        .fail( function () {
            $status.css( 'color', '#dc3232' ).text( '✗ Request failed.' );
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( 'Generate Seeds from Bio' );
        } );
    } );

} );
