/* WP Noise Injection — Admin JS */
jQuery( function ( $ ) {

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Rebuild the profile <select> from a flat array of { id, name } objects.
     * Preserves the currently-selected id if it still exists.
     */
    function rebuildProfileSelect( profiles, selectedId ) {
        var $sel = $( '#wni-profile-select' );
        var current = selectedId !== undefined ? selectedId : $sel.val();
        $sel.empty().append( '<option value="">— Select a profile —</option>' );
        $.each( profiles, function ( _, p ) {
            var $opt = $( '<option>' ).val( p.id ).text( p.name );
            if ( p.id === current ) $opt.prop( 'selected', true );
            $sel.append( $opt );
        } );
        $sel.trigger( 'change' );
    }

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
    // Profile Manager
    // -------------------------------------------------------------------------

    // Initialise button states from server-rendered select.
    $( '#wni-profile-select' ).trigger( 'change' );

    $( '#wni-profile-select' ).on( 'change', function () {
        var id = $( this ).val();
        var hasProfile = id !== '';
        $( '#wni-profile-load, #wni-profile-delete' ).prop( 'disabled', ! hasProfile );
        if ( hasProfile ) {
            var exportUrl = wniAdmin.adminPostUrl +
                '?action=wni_export_profile' +
                '&profile_id=' + encodeURIComponent( id ) +
                '&_wpnonce=' + encodeURIComponent( wniAdmin.exportNonce );
            $( '#wni-profile-export' ).attr( 'href', exportUrl ).show();
        } else {
            $( '#wni-profile-export' ).hide();
        }
    } );

    $( '#wni-profile-save' ).on( 'click', function () {
        var $btn    = $( this );
        var $status = $( '#wni-profile-status' );
        var name    = $( '#wni-profile-name-input' ).val().trim();

        if ( ! name ) {
            $status.css( 'color', '#dc3232' ).text( 'Enter a profile name first.' );
            return;
        }

        // Check if a profile with this name already exists (update path).
        var existingId = '';
        $( '#wni-profile-select option' ).each( function () {
            if ( $( this ).text() === name ) {
                existingId = $( this ).val();
            }
        } );

        if ( existingId && ! confirm( 'Update existing profile "' + name + '"?' ) ) {
            return;
        }

        $btn.prop( 'disabled', true ).text( 'Saving…' );
        $status.css( 'color', '' ).text( '' );

        $.post( wniAdmin.ajaxUrl, {
            action : 'wni_save_profile',
            nonce  : wniAdmin.profileNonce,
            name   : name,
            id     : existingId,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                $status.css( 'color', '#46b450' ).text( '✓ ' + res.data.message );
                rebuildProfileSelect( res.data.profiles, res.data.profile.id );
                $( '#wni-profile-name-input' ).val( '' );
            } else {
                $status.css( 'color', '#dc3232' ).text( '✗ ' + res.data.message );
            }
        } )
        .fail( function () {
            $status.css( 'color', '#dc3232' ).text( '✗ Request failed.' );
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( 'Save Current' );
        } );
    } );

    $( '#wni-profile-load' ).on( 'click', function () {
        var $btn    = $( this );
        var $status = $( '#wni-profile-status' );
        var id      = $( '#wni-profile-select' ).val();
        var label   = $( '#wni-profile-select option:selected' ).text();

        if ( ! id ) return;
        if ( ! confirm( 'Load profile "' + label + '"?\n\nThis will replace the current bio, writing style, and all topic buckets.' ) ) return;

        $btn.prop( 'disabled', true ).text( 'Loading…' );
        $status.css( 'color', '' ).text( '' );

        $.post( wniAdmin.ajaxUrl, {
            action     : 'wni_load_profile',
            nonce      : wniAdmin.profileNonce,
            profile_id : id,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                window.location.href = res.data.redirect_url;
            } else {
                $status.css( 'color', '#dc3232' ).text( '✗ ' + res.data.message );
                $btn.prop( 'disabled', false ).text( 'Load' );
            }
        } )
        .fail( function () {
            $status.css( 'color', '#dc3232' ).text( '✗ Request failed.' );
            $btn.prop( 'disabled', false ).text( 'Load' );
        } );
    } );

    $( '#wni-profile-delete' ).on( 'click', function () {
        var $btn    = $( this );
        var $status = $( '#wni-profile-status' );
        var id      = $( '#wni-profile-select' ).val();
        var label   = $( '#wni-profile-select option:selected' ).text();

        if ( ! id ) return;
        if ( ! confirm( 'Delete profile "' + label + '"? This cannot be undone.' ) ) return;

        $btn.prop( 'disabled', true );
        $status.css( 'color', '' ).text( '' );

        $.post( wniAdmin.ajaxUrl, {
            action     : 'wni_delete_profile',
            nonce      : wniAdmin.profileNonce,
            profile_id : id,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                $status.css( 'color', '#46b450' ).text( '✓ ' + res.data.message );
                rebuildProfileSelect( res.data.profiles, '' );
            } else {
                $status.css( 'color', '#dc3232' ).text( '✗ ' + res.data.message );
            }
        } )
        .fail( function () {
            $status.css( 'color', '#dc3232' ).text( '✗ Request failed.' );
        } )
        .always( function () {
            $btn.prop( 'disabled', false );
        } );
    } );

    // -------------------------------------------------------------------------
    // Token Usage — Reset (delegated: block appears on multiple pages)
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.wni-reset-tokens', function () {
        var $btn   = $( this );
        var nonce  = $btn.data( 'nonce' );
        var $block = $btn.closest( '.wni-token-usage' );

        if ( ! confirm( 'Reset token usage counters to zero?' ) ) return;

        $btn.prop( 'disabled', true ).text( 'Resetting…' );

        $.post( wniAdmin.ajaxUrl, {
            action : 'wni_reset_token_usage',
            nonce  : nonce,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                var u = res.data.usage;
                $block.find( '.wni-token-usage__since' ).text( '(since ' + u.reset_at_label + ')' );
                $block.find( '.wni-token-usage__stats' ).html(
                    'In: <strong>0</strong>' +
                    '&nbsp; Out: <strong>0</strong>' +
                    '&nbsp; Calls: <strong>0</strong>' +
                    '&nbsp; Est. cost: <strong>—</strong>' +
                    ' <span class="wni-token-usage__note">(approx.)</span>'
                );
            }
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( 'Reset' );
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
