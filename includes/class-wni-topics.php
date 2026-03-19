<?php
defined( 'ABSPATH' ) || exit;

/**
 * Manages topic buckets.
 *
 * Each bucket is a content category with a set of seed outlines the generator
 * draws from when creating draft posts.
 *
 * Stored as a single wp_options entry: wni_topics (array of topic arrays).
 *
 * Topic structure:
 *   id       string   Slug-style identifier (e.g. "local-interest")
 *   label    string   Human-readable name shown in admin
 *   enabled  bool     Whether this bucket is active
 *   weight   int      1–5, relative probability of being selected
 *   seeds    array    Short outline strings — one post concept per entry
 *
 * Seeds are the primary way you customise the plugin. Each seed is a brief
 * phrase or sentence describing what a post in that bucket should be about.
 * The generator picks a seed at random and builds a draft post around it.
 *
 * Example seed formats:
 *   "Why I switched to X after years of using Y"
 *   "Local history: the story behind [landmark]"
 *   "Gear review: my six-month verdict on [product]"
 *   "How I approach [task] and what changed my mind about it"
 */
class WNI_Topics {

    const OPTION_KEY = 'wni_topics';

    public static function install_defaults() {
        if ( false === get_option( self::OPTION_KEY ) ) {
            add_option( self::OPTION_KEY, self::defaults() );
        }
    }

    /**
     * Default topic buckets installed on activation.
     *
     * These are intentionally generic examples. Replace them with topics
     * relevant to your site using the Topic Buckets admin page.
     */
    public static function defaults() {
        return array(
            array(
                'id'      => 'professional',
                'label'   => 'Professional Topics',
                'enabled' => true,
                'weight'  => 3,
                'seeds'   => array(
                    'A tool or process I changed my mind about after using it for a year',
                    'The most useful thing I learned on the job that I didn\'t expect to',
                    'How my workflow has changed in the past few years and what drove that',
                    'A common piece of advice in my field that I think deserves more scrutiny',
                    'What I actually look for when evaluating a new approach or technology',
                    'The question I get asked most often and my current honest answer to it',
                    'Something I got wrong early in my career and how I figured that out',
                    'The difference between how I thought this work looked from the outside and how it actually is',
                ),
            ),
            array(
                'id'      => 'local-interest',
                'label'   => 'Local Interest',
                'enabled' => true,
                'weight'  => 2,
                'seeds'   => array(
                    'A neighbourhood I keep returning to and what I find there',
                    'The local spot that most people overlook and why I think that is',
                    'How this place has changed since I first knew it',
                    'The seasonal version of this city that I prefer and why',
                    'A local history detail that surprised me when I learned it',
                    'What I tell people who are visiting for the first time and want to avoid the obvious',
                    'The thing about living here that took me a while to adjust to',
                    'A local institution worth knowing about that doesn\'t get enough attention',
                ),
            ),
            array(
                'id'      => 'personal-interests',
                'label'   => 'Personal Interests',
                'enabled' => true,
                'weight'  => 2,
                'seeds'   => array(
                    'How I got into this hobby and what I wish I\'d known at the start',
                    'The gear or equipment question I get asked about most and my current answer',
                    'What I\'ve learned from doing this for several years that isn\'t obvious from the outside',
                    'A mistake I made early on that I\'d save someone else from making',
                    'Why I keep coming back to this even when I consider taking a break from it',
                    'The part of this that I find unexpectedly meditative or satisfying',
                    'How my approach has simplified over time',
                    'A resource in this area that I\'d recommend to anyone starting out',
                ),
            ),
            array(
                'id'      => 'reflections',
                'label'   => 'Reflections & Notes',
                'enabled' => true,
                'weight'  => 1,
                'seeds'   => array(
                    'Something I\'ve been thinking about that I haven\'t fully worked out yet',
                    'A book or article I read recently that shifted how I think about something',
                    'A habit I\'ve kept for long enough to have an honest opinion on',
                    'The difference between what I thought I wanted and what I actually use',
                    'A question I find myself returning to without a satisfying answer',
                    'What I\'ve noticed changes when you do something consistently for a long time',
                    'Something I used to believe that I no longer hold with the same confidence',
                    'A small thing that has had a disproportionate effect on how I work or live',
                ),
            ),
        );
    }

    public static function get_all() {
        $saved = get_option( self::OPTION_KEY, array() );
        if ( empty( $saved ) ) {
            return self::defaults();
        }
        return $saved;
    }

    public static function get_enabled() {
        return array_values( array_filter( self::get_all(), function( $t ) {
            return ! empty( $t['enabled'] );
        } ) );
    }

    public static function save( array $topics ) {
        $clean = array();
        foreach ( $topics as $topic ) {
            if ( empty( $topic['id'] ) || empty( $topic['label'] ) ) {
                continue;
            }
            $seeds = array();
            if ( ! empty( $topic['seeds'] ) ) {
                foreach ( (array) $topic['seeds'] as $seed ) {
                    $seed = sanitize_textarea_field( $seed );
                    if ( $seed !== '' ) {
                        $seeds[] = $seed;
                    }
                }
            }
            $clean[] = array(
                'id'      => sanitize_key( $topic['id'] ),
                'label'   => sanitize_text_field( $topic['label'] ),
                'enabled' => ! empty( $topic['enabled'] ),
                'weight'  => max( 1, min( 5, (int) $topic['weight'] ) ),
                'seeds'   => $seeds,
            );
        }
        update_option( self::OPTION_KEY, $clean );
        return $clean;
    }

    /**
     * Pick a random topic respecting weights.
     * Higher weight = proportionally more likely to be chosen.
     */
    public static function pick_weighted( array $topics ) {
        $pool = array();
        foreach ( $topics as $topic ) {
            $weight = max( 1, (int) $topic['weight'] );
            for ( $i = 0; $i < $weight; $i++ ) {
                $pool[] = $topic;
            }
        }
        if ( empty( $pool ) ) {
            return null;
        }
        return $pool[ array_rand( $pool ) ];
    }
}
