<?php
defined( 'ABSPATH' ) || exit;

/**
 * Manages named persona profiles.
 *
 * A profile bundles the persona bio, writing style, and full topic/seed
 * snapshot under a human-readable name. Profiles are stored in a single
 * wp_option and survive plugin updates and reinstalls.
 *
 * Profiles do NOT include API keys or generation settings — those are
 * site-specific and remain in wni_settings.
 */
class WNI_Profiles {

    const OPTION_KEY = 'wni_profiles';

    // -------------------------------------------------------------------------
    // Setup
    // -------------------------------------------------------------------------

    public static function install_defaults(): void {
        if ( get_option( self::OPTION_KEY ) === false ) {
            add_option( self::OPTION_KEY, array(), '', false );
        }
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /** Return all saved profiles. */
    public static function get_all(): array {
        return (array) get_option( self::OPTION_KEY, array() );
    }

    /** Return a single profile by id, or null if not found. */
    public static function get( string $id ): ?array {
        foreach ( self::get_all() as $profile ) {
            if ( ( $profile['id'] ?? '' ) === $id ) {
                return $profile;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Save (create or update) a profile.
     *
     * Reads current bio, writing_style, and topics from the DB at call time
     * and snapshots them under the given name.
     *
     * @param  array $data  Must contain 'name'. Optionally 'id' to force a specific slug.
     * @return array        The saved profile.
     */
    public static function save( array $data ): array {
        $name = sanitize_text_field( trim( $data['name'] ?? '' ) );
        if ( $name === '' ) {
            return array();
        }

        $settings = WNI_Settings::get();
        $topics   = WNI_Topics::get_all();

        $profiles     = self::get_all();
        $existing_ids = array_column( $profiles, 'id' );

        // Use provided id or generate one.
        $id = isset( $data['id'] ) ? sanitize_key( $data['id'] ) : '';

        // Check if updating an existing profile.
        $existing_index = null;
        if ( $id !== '' ) {
            foreach ( $profiles as $i => $p ) {
                if ( ( $p['id'] ?? '' ) === $id ) {
                    $existing_index = $i;
                    break;
                }
            }
        }

        if ( $existing_index !== null ) {
            // Update in-place.
            $created_at = $profiles[ $existing_index ]['created_at'] ?? time();
        } else {
            // New profile — generate unique id if not provided.
            if ( $id === '' ) {
                $id = self::make_unique_id( $name, $existing_ids );
            }
            $created_at = time();
        }

        $profile = array(
            'id'            => $id,
            'name'          => $name,
            'persona_bio'   => sanitize_textarea_field( $settings['persona_bio'] ?? '' ),
            'writing_style' => sanitize_textarea_field( $settings['writing_style'] ?? '' ),
            'topics'        => self::sanitize_topics( $topics ),
            'created_at'    => $created_at,
            'updated_at'    => time(),
        );

        if ( $existing_index !== null ) {
            $profiles[ $existing_index ] = $profile;
        } else {
            $profiles[] = $profile;
        }

        update_option( self::OPTION_KEY, array_values( $profiles ) );
        return $profile;
    }

    /**
     * Delete a profile by id.
     *
     * @return bool  True if found and deleted, false if not found.
     */
    public static function delete( string $id ): bool {
        $profiles = self::get_all();
        $filtered = array_values( array_filter( $profiles, function( $p ) use ( $id ) {
            return ( $p['id'] ?? '' ) !== $id;
        } ) );

        if ( count( $filtered ) === count( $profiles ) ) {
            return false; // Nothing removed.
        }

        update_option( self::OPTION_KEY, $filtered );
        return true;
    }

    // -------------------------------------------------------------------------
    // Export / Import
    // -------------------------------------------------------------------------

    /**
     * Build an export envelope for a profile.
     * Returns null if the profile doesn't exist.
     */
    public static function export( string $id ): ?array {
        $profile = self::get( $id );
        if ( $profile === null ) {
            return null;
        }

        return array(
            'wni_export_version' => '1.0',
            'exported_at'        => time(),
            'profile'            => $profile,
        );
    }

    /**
     * Validate and import a profile from a decoded JSON array.
     *
     * The incoming data is the contents of the 'profile' key from an export
     * envelope. Generates a new id if the incoming id collides with an existing one.
     *
     * @param  array $data  Decoded profile object (not the full envelope).
     * @return array|WP_Error  The imported profile on success.
     */
    public static function import( array $data ): array|\WP_Error {
        $name = sanitize_text_field( trim( $data['name'] ?? '' ) );
        if ( $name === '' ) {
            return new \WP_Error( 'invalid_profile', 'Profile name is missing or invalid.' );
        }

        if ( empty( $data['topics'] ) || ! is_array( $data['topics'] ) ) {
            return new \WP_Error( 'invalid_profile', 'Profile topics data is missing or invalid.' );
        }

        $profiles     = self::get_all();
        $existing_ids = array_column( $profiles, 'id' );

        // Resolve id collision: if id exists, generate a new unique one.
        $incoming_id = sanitize_key( $data['id'] ?? '' );
        if ( $incoming_id === '' || in_array( $incoming_id, $existing_ids, true ) ) {
            $id = self::make_unique_id( $name, $existing_ids );
        } else {
            $id = $incoming_id;
        }

        $profile = array(
            'id'            => $id,
            'name'          => $name,
            'persona_bio'   => sanitize_textarea_field( $data['persona_bio'] ?? '' ),
            'writing_style' => sanitize_textarea_field( $data['writing_style'] ?? '' ),
            'topics'        => self::sanitize_topics( (array) $data['topics'] ),
            'created_at'    => (int) ( $data['created_at'] ?? time() ),
            'updated_at'    => time(),
        );

        $profiles[] = $profile;
        update_option( self::OPTION_KEY, array_values( $profiles ) );
        return $profile;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Generate a unique slug from a display name.
     * Appends -2, -3 etc. if the base slug already exists.
     */
    private static function make_unique_id( string $name, array $existing_ids ): string {
        $base = sanitize_key( str_replace( ' ', '-', strtolower( $name ) ) );
        if ( $base === '' ) {
            $base = 'profile';
        }

        $id      = $base;
        $counter = 2;
        while ( in_array( $id, $existing_ids, true ) ) {
            $id = $base . '-' . $counter;
            $counter++;
        }

        return $id;
    }

    /**
     * Sanitize a full topics array snapshot.
     * Mirrors the validation in WNI_Topics::save() without touching the DB.
     */
    private static function sanitize_topics( array $topics ): array {
        $clean = array();
        foreach ( $topics as $raw ) {
            if ( empty( $raw['id'] ) || empty( $raw['label'] ) ) {
                continue;
            }
            $seeds = array();
            foreach ( (array) ( $raw['seeds'] ?? array() ) as $seed ) {
                $seed = sanitize_textarea_field( trim( (string) $seed ) );
                if ( $seed !== '' ) {
                    $seeds[] = $seed;
                }
            }
            $clean[] = array(
                'id'      => sanitize_key( $raw['id'] ),
                'label'   => sanitize_text_field( $raw['label'] ),
                'enabled' => ! empty( $raw['enabled'] ),
                'weight'  => max( 1, min( 5, (int) ( $raw['weight'] ?? 2 ) ) ),
                'seeds'   => $seeds,
            );
        }
        return $clean;
    }
}
