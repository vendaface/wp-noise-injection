<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin menu, settings page, topic buckets page, and generate page.
 */
class WNI_Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menus' ) );
        add_action( 'admin_post_wni_save_settings',   array( __CLASS__, 'handle_save_settings' ) );
        add_action( 'admin_post_wni_save_topics',     array( __CLASS__, 'handle_save_topics' ) );
        add_action( 'admin_post_wni_generate_now',    array( __CLASS__, 'handle_generate_now' ) );
        add_action( 'wp_ajax_wni_test_ai',            array( __CLASS__, 'ajax_test_ai' ) );
        add_action( 'admin_enqueue_scripts',          array( __CLASS__, 'enqueue_admin_assets' ) );
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    public static function register_menus() {
        add_menu_page(
            'Noise Injection',
            'Noise Injection',
            'manage_options',
            'wni-settings',
            array( __CLASS__, 'page_settings' ),
            'dashicons-randomize',
            81
        );
        add_submenu_page( 'wni-settings', 'Settings',      'Settings',      'manage_options', 'wni-settings', array( __CLASS__, 'page_settings' ) );
        add_submenu_page( 'wni-settings', 'Topic Buckets', 'Topic Buckets', 'manage_options', 'wni-topics',   array( __CLASS__, 'page_topics' ) );
        add_submenu_page( 'wni-settings', 'Generate Now',  'Generate Now',  'edit_posts',     'wni-generate', array( __CLASS__, 'page_generate' ) );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public static function enqueue_admin_assets( $hook ) {
        $pages = array( 'toplevel_page_wni-settings', 'noise-injection_page_wni-topics', 'noise-injection_page_wni-generate' );
        if ( ! in_array( $hook, $pages, true ) ) {
            return;
        }
        wp_enqueue_style( 'wni-admin', WNI_PLUGIN_URL . 'assets/admin.css', array(), WNI_VERSION );
        wp_enqueue_script( 'wni-admin', WNI_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), WNI_VERSION, true );
        wp_localize_script( 'wni-admin', 'wniAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wni_test_ai' ),
            'models'  => array(
                'claude' => WNI_Settings::provider_default_model( 'claude' ),
                'openai' => WNI_Settings::provider_default_model( 'openai' ),
            ),
        ) );
    }

    // -------------------------------------------------------------------------
    // Settings Page
    // -------------------------------------------------------------------------

    public static function page_settings() {
        $settings = WNI_Settings::get();
        $users    = get_users( array( 'capability' => 'edit_posts' ) );
        $saved    = isset( $_GET['saved'] ) ? (int) $_GET['saved'] : 0;
        ?>
        <div class="wrap wni-wrap">
            <h1>Noise Injection — Settings</h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wni_save_settings', 'wni_nonce' ); ?>
                <input type="hidden" name="action" value="wni_save_settings">

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wni_author_id">Post Author</label></th>
                        <td>
                            <select name="author_id" id="wni_author_id">
                                <?php foreach ( $users as $user ) : ?>
                                    <option value="<?php echo $user->ID; ?>" <?php selected( $settings['author_id'], $user->ID ); ?>>
                                        <?php echo esc_html( $user->display_name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Author assigned to generated drafts. Posts are always created as drafts — never published automatically.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto-Generate</th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_generate" value="1" <?php checked( $settings['auto_generate'] ); ?>>
                                Enable scheduled automatic draft generation
                            </label>
                            <p class="description">When enabled, new drafts are created automatically on the schedule below. You still review and publish them manually.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wni_frequency">Schedule</label></th>
                        <td>
                            <select name="frequency" id="wni_frequency">
                                <option value="wni_weekly"   <?php selected( $settings['frequency'], 'wni_weekly' ); ?>>Weekly</option>
                                <option value="wni_biweekly" <?php selected( $settings['frequency'], 'wni_biweekly' ); ?>>Every Two Weeks</option>
                                <option value="monthly"      <?php selected( $settings['frequency'], 'monthly' ); ?>>Monthly</option>
                            </select>
                            <p class="description">Note: WP-Cron only fires when your site receives a visit. On very low-traffic sites, consider setting up a real server cron job.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wni_batch_size">Posts per Run</label></th>
                        <td>
                            <input type="number" name="batch_size" id="wni_batch_size"
                                   value="<?php echo (int) $settings['batch_size']; ?>"
                                   min="1" max="5" class="small-text">
                            <p class="description">How many draft posts to create each scheduled run (1–5).</p>
                        </td>
                    </tr>
                </table>

                <h2>AI Content Generation</h2>
                <p>When a provider is configured, post bodies are generated by AI using the seed as the prompt.
                   Falls back to the built-in template system if no key is set or if the API call fails.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wni_ai_provider">Provider</label></th>
                        <td>
                            <select name="ai_provider" id="wni_ai_provider">
                                <option value="none"   <?php selected( $settings['ai_provider'], 'none' ); ?>>None — use built-in templates</option>
                                <option value="claude" <?php selected( $settings['ai_provider'], 'claude' ); ?>>Claude (Anthropic)</option>
                                <option value="openai" <?php selected( $settings['ai_provider'], 'openai' ); ?>>OpenAI</option>
                            </select>
                        </td>
                    </tr>
                    <tr class="wni-ai-field" <?php echo $settings['ai_provider'] === 'none' ? 'style="display:none"' : ''; ?>>
                        <th scope="row"><label for="wni_ai_api_key">API Key</label></th>
                        <td>
                            <input type="password" name="ai_api_key" id="wni_ai_api_key"
                                   value="<?php echo esc_attr( $settings['ai_api_key'] ); ?>"
                                   class="regular-text" autocomplete="off">
                            <p class="description">Stored in your database. Never transmitted to the browser.</p>
                        </td>
                    </tr>
                    <tr class="wni-ai-field" <?php echo $settings['ai_provider'] === 'none' ? 'style="display:none"' : ''; ?>>
                        <th scope="row"><label for="wni_ai_model">Model</label></th>
                        <td>
                            <input type="text" name="ai_model" id="wni_ai_model"
                                   value="<?php echo esc_attr( $settings['ai_model'] ); ?>"
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr( WNI_Settings::provider_default_model( $settings['ai_provider'] ) ); ?>">
                            <p class="description">Leave blank to use the provider default. Current defaults: <code>claude-haiku-4-5-20251001</code> / <code>gpt-4o-mini</code>.</p>
                        </td>
                    </tr>
                    <tr class="wni-ai-field" <?php echo $settings['ai_provider'] === 'none' ? 'style="display:none"' : ''; ?>>
                        <th scope="row">Test Connection</th>
                        <td>
                            <button type="button" id="wni-test-ai" class="button">Test API Key</button>
                            <span id="wni-test-ai-result" style="margin-left:10px; font-style:italic;"></span>
                            <p class="description">Save settings first, then test. Sends a minimal request to verify the key works.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" class="button button-primary" value="Save Settings">
                </p>
            </form>

            <hr>
            <h2>Next Scheduled Run</h2>
            <?php
            $next = wp_next_scheduled( 'wni_scheduled_generate' );
            if ( $next ) {
                echo '<p>' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'F j, Y \a\t g:i a' ) ) . '</p>';
            } else {
                echo '<p>Auto-generation is disabled or no run is scheduled.</p>';
            }
            ?>
        </div>
        <?php
    }

    public static function handle_save_settings() {
        check_admin_referer( 'wni_save_settings', 'wni_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        WNI_Settings::save( $_POST );
        wp_redirect( admin_url( 'admin.php?page=wni-settings&saved=1' ) );
        exit;
    }

    /**
     * AJAX: Test the configured AI API key with a minimal request.
     */
    public static function ajax_test_ai() {
        check_ajax_referer( 'wni_test_ai', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
        }

        $settings = WNI_Settings::get();
        $provider = $settings['ai_provider'];
        $api_key  = $settings['ai_api_key'];
        $model    = $settings['ai_model'] ?: WNI_Settings::provider_default_model( $provider );

        if ( $provider === 'none' || $api_key === '' ) {
            wp_send_json_error( array( 'message' => 'No provider or API key configured.' ) );
        }

        $test_prompt = 'Reply with the single word: ok';

        if ( $provider === 'claude' ) {
            $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
                'timeout' => 15,
                'headers' => array(
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ),
                'body' => wp_json_encode( array(
                    'model'      => $model,
                    'max_tokens' => 10,
                    'messages'   => array( array( 'role' => 'user', 'content' => $test_prompt ) ),
                ) ),
            ) );
        } else {
            $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'content-type'  => 'application/json',
                ),
                'body' => wp_json_encode( array(
                    'model'      => $model,
                    'max_tokens' => 10,
                    'messages'   => array( array( 'role' => 'user', 'content' => $test_prompt ) ),
                ) ),
            ) );
        }

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'Connection failed: ' . $response->get_error_message() ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 200 ) {
            wp_send_json_success( array( 'message' => "Connected successfully using {$provider} / {$model}." ) );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $error_msg = $body['error']['message'] ?? $body['error']['type'] ?? "HTTP {$code}";
        wp_send_json_error( array( 'message' => "API error: {$error_msg}" ) );
    }

    // -------------------------------------------------------------------------
    // Topics Page
    // -------------------------------------------------------------------------

    public static function page_topics() {
        $topics = WNI_Topics::get_all();
        $saved  = isset( $_GET['saved'] ) ? (int) $_GET['saved'] : 0;
        ?>
        <div class="wrap wni-wrap">
            <h1>Noise Injection — Topic Buckets</h1>
            <p>Each bucket is a content category. Enabled buckets are drawn from when generating drafts.
               <strong>Weight</strong> controls how often a bucket is chosen relative to others (1 = rarely, 5 = often).
               <strong>Seeds</strong> are post concepts — one per line, a short phrase describing what a post in that bucket should be about.</p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>Topics saved.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wni_save_topics', 'wni_nonce' ); ?>
                <input type="hidden" name="action" value="wni_save_topics">

                <?php foreach ( $topics as $i => $topic ) : ?>
                    <div class="wni-topic-block postbox">
                        <div class="postbox-header">
                            <h2 class="hndle">
                                <input type="text" name="topics[<?php echo $i; ?>][label]"
                                       value="<?php echo esc_attr( $topic['label'] ); ?>"
                                       class="wni-topic-label-input" placeholder="Topic name">
                                <input type="hidden" name="topics[<?php echo $i; ?>][id]"
                                       value="<?php echo esc_attr( $topic['id'] ); ?>">
                            </h2>
                        </div>
                        <div class="inside">
                            <table class="form-table wni-topic-table">
                                <tr>
                                    <th>Enabled</th>
                                    <td>
                                        <input type="checkbox" name="topics[<?php echo $i; ?>][enabled]"
                                               value="1" <?php checked( $topic['enabled'] ); ?>>
                                    </td>
                                    <th>Weight</th>
                                    <td>
                                        <input type="number" name="topics[<?php echo $i; ?>][weight]"
                                               value="<?php echo (int) $topic['weight']; ?>"
                                               min="1" max="5" class="small-text">
                                        <span class="description">(1–5)</span>
                                    </td>
                                </tr>
                            </table>
                            <label><strong>Seed Outlines</strong></label>
                            <p class="description">One seed per line. Each seed becomes a post title and shapes the body content. Example: <em>"Why I switched to X after years of using Y"</em></p>
                            <textarea name="topics[<?php echo $i; ?>][seeds_text]"
                                      rows="8" class="large-text wni-seeds-textarea"><?php
                                echo esc_textarea( implode( "\n", $topic['seeds'] ) );
                            ?></textarea>
                        </div>
                    </div>
                <?php endforeach; ?>

                <p class="submit">
                    <input type="submit" class="button button-primary" value="Save Topics">
                </p>
            </form>
        </div>
        <?php
    }

    public static function handle_save_topics() {
        check_admin_referer( 'wni_save_topics', 'wni_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $raw_topics = isset( $_POST['topics'] ) ? (array) $_POST['topics'] : array();
        $clean      = array();

        foreach ( $raw_topics as $raw ) {
            if ( empty( $raw['id'] ) ) {
                continue;
            }
            $seeds = array();
            if ( ! empty( $raw['seeds_text'] ) ) {
                foreach ( explode( "\n", $raw['seeds_text'] ) as $line ) {
                    $line = sanitize_textarea_field( trim( $line ) );
                    if ( $line !== '' ) {
                        $seeds[] = $line;
                    }
                }
            }
            $clean[] = array(
                'id'      => sanitize_key( $raw['id'] ),
                'label'   => sanitize_text_field( $raw['label'] ),
                'enabled' => ! empty( $raw['enabled'] ),
                'weight'  => max( 1, min( 5, (int) $raw['weight'] ) ),
                'seeds'   => $seeds,
            );
        }

        WNI_Topics::save( $clean );
        wp_redirect( admin_url( 'admin.php?page=wni-topics&saved=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Generate Now Page
    // -------------------------------------------------------------------------

    public static function page_generate() {
        $topics  = WNI_Topics::get_enabled();
        $report  = WNI_Diversity::get_report();
        $created = array();

        if ( isset( $_GET['generated'] ) ) {
            $ids = array_map( 'intval', explode( ',', $_GET['generated'] ) );
            foreach ( $ids as $id ) {
                $post = get_post( $id );
                if ( $post ) {
                    $created[] = array(
                        'id'       => $id,
                        'title'    => $post->post_title,
                        'edit_url' => get_edit_post_link( $id, 'raw' ),
                        'topic'    => get_post_meta( $id, '_wni_topic', true ),
                    );
                }
            }
        }
        ?>
        <div class="wrap wni-wrap">
            <h1>Noise Injection — Generate Now</h1>

            <?php if ( ! empty( $created ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo count( $created ); ?> draft post(s) created:</p>
                    <ul>
                        <?php foreach ( $created as $p ) : ?>
                            <li>
                                <a href="<?php echo esc_url( $p['edit_url'] ); ?>"><?php echo esc_html( $p['title'] ); ?></a>
                                <span class="wni-topic-tag"><?php echo esc_html( $p['topic'] ); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="wni-generate-cols">

                <div class="wni-generate-left">
                    <h2>Diversity Score</h2>
                    <div class="wni-score-summary">
                        <span class="wni-big-score"><?php echo $report['score']; ?></span>
                        <span class="wni-big-label"><?php echo esc_html( $report['label'] ); ?></span>
                    </div>
                    <p><?php echo wp_kses( $report['recommendation'], array( 'strong' => array() ) ); ?></p>

                    <h3>Breakdown</h3>
                    <table class="widefat striped wni-breakdown-table">
                        <thead>
                            <tr><th>Topic</th><th>Posts</th><th>Share</th><th>Distribution</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $report['breakdown'] as $data ) : ?>
                                <tr <?php echo $data['enabled'] ? '' : 'class="wni-disabled"'; ?>>
                                    <td><?php echo esc_html( $data['label'] ); ?></td>
                                    <td><?php echo $data['count']; ?></td>
                                    <td><?php echo $data['pct']; ?>%</td>
                                    <td>
                                        <div class="wni-bar-track">
                                            <div class="wni-bar-fill" style="width:<?php echo $data['pct']; ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="wni-generate-right">
                    <h2>Generate Draft Posts</h2>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'wni_generate_now', 'wni_nonce' ); ?>
                        <input type="hidden" name="action" value="wni_generate_now">

                        <table class="form-table">
                            <tr>
                                <th><label for="wni_gen_count">Number to Generate</label></th>
                                <td>
                                    <input type="number" name="gen_count" id="wni_gen_count"
                                           value="1" min="1" max="5" class="small-text">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wni_gen_topic">Topic</label></th>
                                <td>
                                    <select name="gen_topic" id="wni_gen_topic">
                                        <option value="">— Weighted Random —</option>
                                        <?php foreach ( $topics as $t ) : ?>
                                            <option value="<?php echo esc_attr( $t['id'] ); ?>">
                                                <?php echo esc_html( $t['label'] ); ?> (weight: <?php echo (int) $t['weight']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Leave on Random to use weighted selection, or pick a specific topic to fill a gap in your diversity score.</p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <input type="submit" class="button button-primary" value="Generate Drafts">
                        </p>
                    </form>

                    <h3>Pending Drafts</h3>
                    <?php
                    $drafts = get_posts( array(
                        'post_status' => 'draft',
                        'numberposts' => 10,
                        'meta_key'    => '_wni_topic',
                        'orderby'     => 'date',
                        'order'       => 'DESC',
                    ) );

                    if ( empty( $drafts ) ) {
                        echo '<p>No generated drafts pending review.</p>';
                    } else {
                        echo '<ul class="wni-draft-list">';
                        foreach ( $drafts as $d ) {
                            $topic = get_post_meta( $d->ID, '_wni_topic', true );
                            printf(
                                '<li><a href="%s">%s</a> <span class="wni-topic-tag">%s</span> <span class="wni-draft-date">%s</span></li>',
                                esc_url( get_edit_post_link( $d->ID, 'raw' ) ),
                                esc_html( $d->post_title ),
                                esc_html( $topic ),
                                esc_html( get_the_date( 'M j', $d ) )
                            );
                        }
                        echo '</ul>';
                    }
                    ?>
                </div>

            </div>
        </div>
        <?php
    }

    public static function handle_generate_now() {
        check_admin_referer( 'wni_generate_now', 'wni_nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $count    = max( 1, min( 5, (int) $_POST['gen_count'] ) );
        $topic_id = sanitize_key( $_POST['gen_topic'] ?? '' );
        $topic    = null;

        if ( $topic_id ) {
            foreach ( WNI_Topics::get_enabled() as $t ) {
                if ( $t['id'] === $topic_id ) {
                    $topic = $t;
                    break;
                }
            }
        }

        $ids     = WNI_Generator::generate_batch( $count, $topic );
        $ids_str = implode( ',', $ids );

        wp_redirect( admin_url( 'admin.php?page=wni-generate&generated=' . $ids_str ) );
        exit;
    }
}
