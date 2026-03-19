<?php
defined( 'ABSPATH' ) || exit;

/**
 * Calculates a diversity score for the site's content across topic buckets.
 *
 * The score is Shannon entropy normalised to 0–100.
 *   100 = perfectly even distribution across all enabled buckets.
 *   0   = all content in a single bucket.
 *
 * Posts are attributed to buckets via the _wni_topic post meta set at
 * generation time. Posts without that meta are not counted.
 */
class WNI_Diversity {

    /**
     * Returns the full diversity report as an associative array:
     *
     *   score           int     0–100
     *   label           string  "Low / Moderate / Good / Excellent"
     *   breakdown       array   [ topic_id => [ label, count, pct, enabled ] ]
     *   total_posts     int
     *   recommendation  string  Human-readable next action
     */
    public static function get_report(): array {
        $topics    = WNI_Topics::get_all();
        $breakdown = array();
        $total     = 0;

        foreach ( $topics as $topic ) {
            $count = self::count_posts_for_topic( $topic );
            $breakdown[ $topic['id'] ] = array(
                'label'   => $topic['label'],
                'enabled' => ! empty( $topic['enabled'] ),
                'count'   => $count,
                'pct'     => 0,
            );
            $total += $count;
        }

        $entropy  = 0.0;
        $n_topics = count( array_filter( $topics, fn( $t ) => ! empty( $t['enabled'] ) ) );

        foreach ( $breakdown as $id => &$data ) {
            if ( $total > 0 ) {
                $data['pct'] = round( ( $data['count'] / $total ) * 100, 1 );
            }
            if ( $data['count'] > 0 ) {
                $p        = $data['count'] / $total;
                $entropy -= $p * log( $p, 2 );
            }
        }
        unset( $data );

        $max_entropy = $n_topics > 1 ? log( $n_topics, 2 ) : 1;
        $score       = $max_entropy > 0 ? (int) round( ( $entropy / $max_entropy ) * 100 ) : 0;
        $score       = max( 0, min( 100, $score ) );

        return array(
            'score'          => $score,
            'label'          => self::score_label( $score ),
            'breakdown'      => $breakdown,
            'total_posts'    => $total,
            'recommendation' => self::recommendation( $score, $breakdown ),
        );
    }

    private static function score_label( int $score ): string {
        if ( $score >= 80 ) return 'Excellent';
        if ( $score >= 60 ) return 'Good';
        if ( $score >= 35 ) return 'Moderate';
        return 'Low';
    }

    private static function recommendation( int $score, array $breakdown ): string {
        if ( $score >= 80 ) {
            return 'Content distribution is well-balanced. No action needed.';
        }

        $min_count = PHP_INT_MAX;
        $min_label = '';
        foreach ( $breakdown as $data ) {
            if ( $data['enabled'] && $data['count'] < $min_count ) {
                $min_count = $data['count'];
                $min_label = $data['label'];
            }
        }

        if ( $score >= 60 ) {
            return "Good distribution. Consider adding content in <strong>{$min_label}</strong> to improve balance.";
        }
        if ( $score >= 35 ) {
            return "Moderate imbalance. Generate 2–3 posts in <strong>{$min_label}</strong> to broaden the content mix.";
        }
        return "Content is heavily concentrated in one area. Generate posts across multiple buckets, especially <strong>{$min_label}</strong>.";
    }

    /**
     * Count posts attributed to a topic bucket via _wni_topic meta.
     * Falls back to category name matching for manually created posts.
     */
    private static function count_posts_for_topic( array $topic ): int {
        global $wpdb;

        $meta_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type   = 'post'
               AND p.post_status IN ('publish','draft','pending','private')
               AND pm.meta_key   = '_wni_topic'
               AND pm.meta_value = %s",
            $topic['id']
        ) );

        if ( $meta_count > 0 ) {
            return $meta_count;
        }

        $term = get_term_by( 'name', $topic['label'], 'category' );
        if ( ! $term || is_wp_error( $term ) ) {
            return 0;
        }

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE p.post_type   = 'post'
               AND p.post_status IN ('publish','draft','pending','private')
               AND tt.term_id    = %d",
            $term->term_id
        ) );
    }
}
