<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tracks cumulative API token usage across all AI calls.
 *
 * Counts are stored in wp_options and persist until manually reset.
 * Estimated cost figures are approximate and based on published
 * per-million-token rates at time of plugin release.
 */
class WNI_Token_Usage {

    const OPTION_KEY = 'wni_token_usage';

    // -------------------------------------------------------------------------
    // Setup
    // -------------------------------------------------------------------------

    public static function install_defaults(): void {
        if ( get_option( self::OPTION_KEY ) === false ) {
            add_option( self::OPTION_KEY, self::defaults(), '', false );
        }
    }

    private static function defaults(): array {
        return array(
            'input_tokens'  => 0,
            'output_tokens' => 0,
            'calls'         => 0,
            'reset_at'      => time(),
            'provider'      => '',
            'model'         => '',
        );
    }

    // -------------------------------------------------------------------------
    // Read / Write
    // -------------------------------------------------------------------------

    public static function get(): array {
        $saved = get_option( self::OPTION_KEY, array() );
        return wp_parse_args( $saved, self::defaults() );
    }

    /**
     * Record token usage from one API call.
     * Increments running totals and updates provider/model metadata.
     */
    public static function record( int $input_tokens, int $output_tokens, string $provider, string $model ): void {
        $usage = self::get();
        $usage['input_tokens']  += $input_tokens;
        $usage['output_tokens'] += $output_tokens;
        $usage['calls']         += 1;
        $usage['provider']       = $provider;
        $usage['model']          = $model;
        update_option( self::OPTION_KEY, $usage );
    }

    /**
     * Zero all counters and reset the timestamp.
     * Preserves provider/model so cost estimation remains contextual.
     */
    public static function reset(): void {
        $usage                   = self::get();
        $usage['input_tokens']   = 0;
        $usage['output_tokens']  = 0;
        $usage['calls']          = 0;
        $usage['reset_at']       = time();
        update_option( self::OPTION_KEY, $usage );
    }

    // -------------------------------------------------------------------------
    // Cost Estimation
    // -------------------------------------------------------------------------

    /**
     * Returns per-million-token rates for the given provider + model.
     * Matched by substring so 'claude-haiku-4-5-20251001' matches 'claude-haiku'.
     *
     * @return array|null  [ 'input' => float, 'output' => float ] or null if unknown.
     */
    private static function get_rates( string $provider, string $model ): ?array {
        $model_lc = strtolower( $model );

        $rates = array(
            // Anthropic Claude
            'claude-haiku' => array( 'input' => 0.80, 'output' => 4.00 ),
            'claude-sonnet' => array( 'input' => 3.00, 'output' => 15.00 ),
            'claude-opus'  => array( 'input' => 15.00, 'output' => 75.00 ),
            // OpenAI
            'gpt-4o-mini'  => array( 'input' => 0.15, 'output' => 0.60 ),
            'gpt-4o'       => array( 'input' => 2.50, 'output' => 10.00 ),
            'gpt-4'        => array( 'input' => 30.00, 'output' => 60.00 ),
            'gpt-3.5'      => array( 'input' => 0.50, 'output' => 1.50 ),
        );

        foreach ( $rates as $key => $rate ) {
            if ( strpos( $model_lc, $key ) !== false ) {
                return $rate;
            }
        }

        return null;
    }

    /**
     * Estimate USD cost for the given usage record.
     *
     * @param  array $usage  Result of get().
     * @return array         [ 'input' => '$0.0008', 'output' => '$0.0034', 'total' => '$0.0042' ]
     *                       Values are null strings ('—') if model is unrecognised.
     */
    public static function estimate_cost( array $usage ): array {
        $rates = self::get_rates( $usage['provider'] ?? '', $usage['model'] ?? '' );

        if ( $rates === null ) {
            return array( 'input' => '—', 'output' => '—', 'total' => '—' );
        }

        $input_cost  = ( $usage['input_tokens']  / 1_000_000 ) * $rates['input'];
        $output_cost = ( $usage['output_tokens'] / 1_000_000 ) * $rates['output'];
        $total_cost  = $input_cost + $output_cost;

        $fmt = function( float $n ): string {
            // Show at least 4 decimal places for small amounts.
            if ( $n < 0.0001 && $n > 0 ) {
                return '$' . number_format( $n, 6 );
            }
            return '$' . number_format( $n, 4 );
        };

        return array(
            'input'  => $fmt( $input_cost ),
            'output' => $fmt( $output_cost ),
            'total'  => $fmt( $total_cost ),
        );
    }

    // -------------------------------------------------------------------------
    // Display
    // -------------------------------------------------------------------------

    /**
     * Render a self-contained token usage info block as an HTML string.
     * Called from both page_settings() and page_topics().
     */
    public static function render_info_block( array $usage ): string {
        $cost       = self::estimate_cost( $usage );
        $since      = date( 'M j', $usage['reset_at'] );
        $nonce      = wp_create_nonce( 'wni_reset_token_usage' );
        $total      = $usage['input_tokens'] + $usage['output_tokens'];

        ob_start();
        ?>
        <div class="wni-token-usage">
            <span class="wni-token-usage__label">Token Usage <span class="wni-token-usage__since">(since <?php echo esc_html( $since ); ?>)</span></span>
            <span class="wni-token-usage__stats">
                In: <strong><?php echo number_format( $usage['input_tokens'] ); ?></strong>
                &nbsp; Out: <strong><?php echo number_format( $usage['output_tokens'] ); ?></strong>
                &nbsp; Calls: <strong><?php echo number_format( $usage['calls'] ); ?></strong>
                &nbsp; Est. cost: <strong><?php echo esc_html( $cost['total'] ); ?></strong>
                <span class="wni-token-usage__note">(approx.)</span>
            </span>
            <button type="button" class="button button-small wni-reset-tokens"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>">Reset</button>
        </div>
        <?php
        return ob_get_clean();
    }
}
