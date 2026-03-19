<?php
defined( 'ABSPATH' ) || exit;

/**
 * Dashboard widget: Noise Diversity Score.
 * Displays a gauge, per-bucket bar chart, and a one-click Generate button.
 */
class WNI_Widget {

    public static function register() {
        add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_widget' ) );
        add_action( 'wp_ajax_wni_quick_generate', array( __CLASS__, 'ajax_quick_generate' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function add_widget() {
        wp_add_dashboard_widget(
            'wni_diversity_widget',
            '🎲 Noise Diversity Score',
            array( __CLASS__, 'render_widget' )
        );
    }

    public static function enqueue_assets( $hook ) {
        if ( $hook !== 'index.php' ) {
            return;
        }
        wp_enqueue_style(
            'wni-widget',
            WNI_PLUGIN_URL . 'assets/widget.css',
            array(),
            WNI_VERSION
        );
        wp_enqueue_script(
            'wni-widget',
            WNI_PLUGIN_URL . 'assets/widget.js',
            array( 'jquery' ),
            WNI_VERSION,
            true
        );
        wp_localize_script( 'wni-widget', 'wniAjax', array(
            'url'   => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'wni_quick_generate' ),
        ) );
    }

    public static function render_widget() {
        $report = WNI_Diversity::get_report();
        $score  = $report['score'];
        $label  = $report['label'];
        $total  = $report['total_posts'];
        $rec    = $report['recommendation'];

        if ( $score >= 80 )      $colour = '#46b450';
        elseif ( $score >= 60 )  $colour = '#00a0d2';
        elseif ( $score >= 35 )  $colour = '#ffb900';
        else                     $colour = '#dc3232';
        ?>
        <div class="wni-widget-wrap">

            <div class="wni-score-row">
                <div class="wni-gauge">
                    <svg viewBox="0 0 36 36" class="wni-circle-svg">
                        <path class="wni-circle-bg"
                            d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                        <path class="wni-circle-fill"
                            stroke="<?php echo esc_attr( $colour ); ?>"
                            stroke-dasharray="<?php echo $score; ?>, 100"
                            d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                    </svg>
                    <div class="wni-gauge-label">
                        <span class="wni-score-num"><?php echo $score; ?></span>
                        <span class="wni-score-pct">/ 100</span>
                    </div>
                </div>
                <div class="wni-score-meta">
                    <p class="wni-score-grade" style="color:<?php echo esc_attr( $colour ); ?>"><?php echo esc_html( $label ); ?></p>
                    <p class="wni-score-total"><?php echo $total; ?> posts analysed</p>
                    <p class="wni-rec"><?php echo wp_kses( $rec, array( 'strong' => array() ) ); ?></p>
                </div>
            </div>

            <table class="wni-breakdown">
                <tbody>
                <?php foreach ( $report['breakdown'] as $data ) : ?>
                    <tr class="<?php echo $data['enabled'] ? '' : 'wni-disabled'; ?>">
                        <td class="wni-topic-name"><?php echo esc_html( $data['label'] ); ?></td>
                        <td class="wni-topic-bar">
                            <div class="wni-bar-track">
                                <div class="wni-bar-fill" style="width:<?php echo $data['pct']; ?>%; background:<?php echo esc_attr( $colour ); ?>"></div>
                            </div>
                        </td>
                        <td class="wni-topic-count"><?php echo $data['count']; ?></td>
                        <td class="wni-topic-pct"><?php echo $data['pct']; ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="wni-widget-actions">
                <button id="wni-quick-generate" class="button button-primary">Generate Draft Post</button>
                <span id="wni-generate-status" class="wni-status-msg"></span>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wni-topics' ) ); ?>" class="button">Manage Topics</a>
            </div>

        </div>
        <?php
    }

    public static function ajax_quick_generate() {
        check_ajax_referer( 'wni_quick_generate', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
        }

        $ids = WNI_Generator::generate_batch( 1 );

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'No topics enabled. Add topics first.' ) );
        }

        $post     = get_post( $ids[0] );
        $edit_url = get_edit_post_link( $ids[0], 'raw' );

        wp_send_json_success( array(
            'message'  => 'Draft created: <a href="' . esc_url( $edit_url ) . '">' . esc_html( $post->post_title ) . '</a>',
            'post_id'  => $ids[0],
            'edit_url' => $edit_url,
        ) );
    }
}
