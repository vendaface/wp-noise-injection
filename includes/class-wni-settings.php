<?php
defined( 'ABSPATH' ) || exit;

/**
 * Manages plugin settings stored in wp_options.
 */
class WNI_Settings {

    public static function install_defaults() {
        if ( false === get_option( WNI_OPTION_KEY ) ) {
            add_option( WNI_OPTION_KEY, self::defaults() );
        }
    }

    public static function defaults() {
        return array(
            'auto_generate' => false,
            'frequency'     => 'wni_weekly',
            'batch_size'    => 1,
            'author_id'     => get_current_user_id(),
            'post_status'   => 'draft', // always draft — never auto-publish
        );
    }

    public static function get() {
        $saved = get_option( WNI_OPTION_KEY, array() );
        return wp_parse_args( $saved, self::defaults() );
    }

    public static function save( array $input ) {
        $clean = array(
            'auto_generate' => ! empty( $input['auto_generate'] ),
            'frequency'     => in_array( $input['frequency'], array( 'wni_weekly', 'wni_biweekly', 'monthly' ), true )
                                ? $input['frequency'] : 'wni_weekly',
            'batch_size'    => max( 1, min( 5, (int) $input['batch_size'] ) ),
            'author_id'     => (int) $input['author_id'],
            'post_status'   => 'draft',
        );
        update_option( WNI_OPTION_KEY, $clean );

        // Re-schedule if auto-generate changed.
        wp_clear_scheduled_hook( 'wni_scheduled_generate' );
        if ( $clean['auto_generate'] ) {
            wp_schedule_event( time(), $clean['frequency'], 'wni_scheduled_generate' );
        }

        return $clean;
    }
}
