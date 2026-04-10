<?php
defined( 'ABSPATH' ) || exit;

/**
 * Generates draft posts from topic seeds.
 *
 * The generator is entirely offline — no external API calls, no costs.
 * It builds a plausible draft from a seed outline using structural templates
 * and a generic filler sentence bank. The result is a starting point: you
 * edit it before publishing.
 *
 * Posts are always created as drafts regardless of settings.
 *
 * How generation works:
 *   1. A topic bucket is chosen (weighted random or specified).
 *   2. A seed is picked at random from that bucket's seed list.
 *   3. The seed is split into a title and a detail clause.
 *   4. A structural template is chosen at random (reflection, process note,
 *      two-section with heading).
 *   5. Generic filler sentences are drawn from the observation bank and
 *      combined with the seed detail to form the post body.
 *   6. The result is inserted as a draft post with topic metadata attached.
 */
class WNI_Generator {

    /**
     * Generate $count draft posts and return their post IDs.
     *
     * @param  int        $count  Number of posts to generate.
     * @param  array|null $topic  Specific topic array, or null for weighted random.
     * @return int[]              Array of created post IDs.
     */
    public static function generate_batch( $count = 1, $topic = null ) {
        $settings = WNI_Settings::get();
        $topics   = WNI_Topics::get_enabled();

        if ( empty( $topics ) ) {
            return array();
        }

        $post_ids = array();

        for ( $i = 0; $i < $count; $i++ ) {
            $chosen_topic = $topic ?? WNI_Topics::pick_weighted( $topics );
            if ( ! $chosen_topic || empty( $chosen_topic['seeds'] ) ) {
                continue;
            }

            $seed = $chosen_topic['seeds'][ array_rand( $chosen_topic['seeds'] ) ];
            $id   = self::create_draft( $chosen_topic, $seed, $settings );
            if ( $id && ! is_wp_error( $id ) ) {
                $post_ids[] = $id;
            }
        }

        return $post_ids;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private static function create_draft( array $topic, string $seed, array $settings ) {
        $title = self::seed_to_title( $seed );
        $body  = self::build_body( $seed, $topic['label'] );

        // Scatter post dates over the past 60 days to avoid timestamp clustering.
        $offset_days = wp_rand( 0, 60 );
        $post_date   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$offset_days} days" ) );

        $post_data = array(
            'post_title'    => $title,
            'post_content'  => $body,
            'post_status'   => 'draft',
            'post_author'   => $settings['author_id'],
            'post_date'     => $post_date,
            'post_date_gmt' => $post_date,
            'post_type'     => 'post',
            'meta_input'    => array(
                '_wni_topic'     => $topic['id'],
                '_wni_seed'      => $seed,
                '_wni_generated' => current_time( 'timestamp' ),
            ),
        );

        // Assign to an existing WordPress category matching the topic label if
        // one exists. Does NOT create categories automatically.
        $cat_id = self::find_category( $topic['label'] );
        if ( $cat_id ) {
            $post_data['post_category'] = array( $cat_id );
        }

        return wp_insert_post( $post_data, true );
    }

    /**
     * Derive a post title from a seed string.
     *
     * Seeds formatted as "Title: detail clause" use the part before the colon
     * as the title. Plain sentences are used directly, truncated if needed.
     */
    private static function seed_to_title( string $seed ): string {
        if ( strpos( $seed, ':' ) !== false ) {
            $parts = explode( ':', $seed, 2 );
            $title = trim( $parts[0] );
            if ( strlen( $title ) > 10 && strlen( $title ) < 80 ) {
                return $title;
            }
        }
        if ( strlen( $seed ) > 80 ) {
            return substr( $seed, 0, 77 ) . '...';
        }
        return $seed;
    }

    /**
     * Build a post body from a seed.
     * Tries AI generation first; falls back to the static template system silently
     * if no provider is configured or if the API call fails for any reason.
     */
    private static function build_body( string $seed, string $topic_label = '' ): string {
        $ai_body = self::generate_ai_body( $seed, $topic_label );
        if ( $ai_body !== false ) {
            return $ai_body;
        }

        // Fallback: static template system.
        $detail = $seed;
        if ( strpos( $seed, ':' ) !== false ) {
            $parts  = explode( ':', $seed, 2 );
            $detail = trim( $parts[1] );
        }

        $templates = self::body_templates();
        $template  = $templates[ array_rand( $templates ) ];
        return call_user_func( $template, $detail );
    }

    /**
     * Generate a post body via AI API.
     *
     * Uses the configured provider (Claude or OpenAI). All API calls are
     * server-side — the key is never exposed to the browser. Returns an HTML
     * string on success, false on any failure (missing key, network error,
     * API error, malformed response).
     *
     * @param  string       $seed        The seed string (becomes the post concept).
     * @param  string       $topic_label Human-readable topic name for prompt context.
     * @return string|false              HTML body or false if generation failed.
     */
    private static function generate_ai_body( string $seed, string $topic_label ) {
        $settings = WNI_Settings::get();

        $provider = $settings['ai_provider'] ?? 'none';
        $api_key  = $settings['ai_api_key']  ?? '';
        $model    = $settings['ai_model']    ?? '';

        if ( $provider === 'none' || $api_key === '' ) {
            return false;
        }

        if ( $model === '' ) {
            $model = WNI_Settings::provider_default_model( $provider );
        }

        $prompt = self::build_prompt( $seed, $topic_label );

        if ( $provider === 'claude' ) {
            return self::call_claude( $api_key, $model, $prompt );
        }

        if ( $provider === 'openai' ) {
            return self::call_openai( $api_key, $model, $prompt );
        }

        return false;
    }

    /**
     * Build the generation prompt.
     * Uses persona bio and writing style from settings when available.
     */
    private static function build_prompt( string $seed, string $topic_label ): string {
        $settings      = WNI_Settings::get();
        $bio           = trim( $settings['persona_bio']   ?? '' );
        $writing_style = trim( $settings['writing_style'] ?? '' );
        $topic_line    = $topic_label ? "\nCategory: {$topic_label}" : '';
        $style_line    = $writing_style ?: 'conversational first-person, favors specific detail over generalization';

        $natural_rules = <<<'RULES'
Natural writing requirements (critical — failure to follow these makes the writing obviously AI-generated):
- Vary sentence length significantly — mix short punchy sentences with longer ones
- Use contractions throughout (I've, it's, didn't, you'd, we're, that's)
- Sentence fragments are fine when they feel right
- Can start sentences with "And", "But", or "So"
- Specific named detail (a particular trail, an exact tool, a specific moment) beats vague generality
- Don't over-explain or hedge — assume the reader can keep up
- NEVER use: delve, tapestry, journey (metaphorical), navigate (metaphorical), realm, leverage, foster, nuanced, multifaceted, pivotal, utilize, underscore, "it's worth noting", "it's important to", "in today's world"
- No "Firstly / Secondly / Finally / In conclusion" structure
- Don't repeat points already made
- End on a specific note, not a summary
RULES;

        if ( $bio !== '' ) {
            return <<<PROMPT
You are ghostwriting a blog post for the following persona:

{$bio}

Writing style: {$style_line}

Post topic: "{$seed}"{$topic_line}

Write the post as this person would naturally write it. All experiences and details should be invented for this persona — do not use any real identifying information.

Length and structure:
- 300–400 words, 3–4 paragraphs
- No headers or subheadings
- End naturally — no wrap-up paragraph, no lessons-learned summary, no call to action

{$natural_rules}

Return only the post body as HTML <p> tags. No title, no preamble, no explanation.
PROMPT;
        }

        // No bio — generic fictional persona prompt.
        return <<<PROMPT
Write a personal blog post draft for a fictional persona about the following topic:

"{$seed}"{$topic_line}

The persona and all experiences described are entirely invented. All anecdotes, observations, and details should be fabricated.

Writing style: {$style_line}

Length and structure:
- 300–400 words, 3–4 paragraphs
- No headers or subheadings
- End naturally — no wrap-up paragraph, no call to action

{$natural_rules}

Return only the post body as HTML <p> tags. No title, no preamble, no explanation.
PROMPT;
    }

    /**
     * Call the Anthropic Claude API.
     *
     * @return string|false
     */
    private static function call_claude( string $api_key, string $model, string $prompt ) {
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'timeout' => 45,
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'      => $model,
                'max_tokens' => 1024,
                'messages'   => array(
                    array( 'role' => 'user', 'content' => $prompt ),
                ),
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = $body['content'][0]['text'] ?? '';

        return $text !== '' ? wp_kses_post( $text ) : false;
    }

    /**
     * Call the OpenAI Chat Completions API.
     *
     * @return string|false
     */
    private static function call_openai( string $api_key, string $model, string $prompt ) {
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'content-type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'      => $model,
                'max_tokens' => 1024,
                'messages'   => array(
                    array( 'role' => 'user', 'content' => $prompt ),
                ),
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = $body['choices'][0]['message']['content'] ?? '';

        return $text !== '' ? wp_kses_post( $text ) : false;
    }

    /**
     * Three structural templates for post bodies.
     * Each is a closure taking a $detail string and returning HTML.
     */
    private static function body_templates(): array {

        $openers = array(
            'A few notes on',
            'Some thoughts on',
            'Worth writing down:',
            'I keep meaning to write this up properly, so here it is.',
            'This is one of those things I figured out slowly.',
            'Quick notes while it\'s still fresh.',
            'I\'ve been asked about this more than once.',
        );

        $closers = array(
            'Still figuring some of this out.',
            'Happy to compare notes if you\'ve approached this differently.',
            'Your mileage will vary, but this is where I\'ve landed.',
            'Nothing here is the final word.',
            'I\'ll update this as things change.',
            'As always, context matters more than any general rule.',
        );

        return array(

            // Template 1: Opener + three observations + closer
            function( $detail ) use ( $openers, $closers ) {
                $opener = $openers[ array_rand( $openers ) ];
                $closer = $closers[ array_rand( $closers ) ];
                $obs    = self::observations( 3 );
                $body   = "<p>{$opener} {$detail}.</p>\n\n";
                foreach ( $obs as $o ) {
                    $body .= "<p>{$o}</p>\n\n";
                }
                $body .= "<p>{$closer}</p>";
                return $body;
            },

            // Template 2: Short personal process note
            function( $detail ) use ( $closers ) {
                $closer = $closers[ array_rand( $closers ) ];
                $obs    = self::observations( 2 );
                $body   = "<p>Here's where I've landed on {$detail}.</p>\n\n";
                $body  .= "<p>{$obs[0]}</p>\n\n";
                $body  .= "<p>{$obs[1]}</p>\n\n";
                $body  .= "<p>{$closer}</p>";
                return $body;
            },

            // Template 3: Two-section with a mid-post heading
            function( $detail ) use ( $closers ) {
                $closer = $closers[ array_rand( $closers ) ];
                $obs    = self::observations( 4 );
                $body   = "<p>{$obs[0]}</p>\n\n";
                $body  .= "<p>{$obs[1]}</p>\n\n";
                $body  .= "<h2>What I actually do</h2>\n\n";
                $body  .= "<p>{$obs[2]}</p>\n\n";
                $body  .= "<p>{$obs[3]}</p>\n\n";
                $body  .= "<p>{$closer}</p>";
                return $body;
            },

        );
    }

    /**
     * Generic observation sentences drawn to fill post bodies.
     *
     * These are intentionally topic-neutral: they read as plausible personal
     * reflection regardless of subject matter. The seed provides the specific
     * topic; these sentences provide structure and word count.
     *
     * To customise the generated content more heavily, edit these sentences or
     * extend this method to accept topic-specific banks keyed by topic ID.
     */
    private static function observations( int $count ): array {
        $bank = array(
            'The hard part isn\'t the initial setup — it\'s making the habit stick long enough to get useful data.',
            'I\'ve changed my approach here more than once. What I have now is simpler than what I started with.',
            'Most of the complexity in this area is incidental rather than essential. Worth separating the two.',
            'I started paying attention to this after a situation that wasn\'t catastrophic but was annoying enough to motivate change.',
            'The defaults are rarely set up with your specific interests as the priority. Worth auditing at least once.',
            'The gap between what\'s possible and what most people actually do is where most of the interesting questions live.',
            'I try to revisit this once a year. It takes a few hours and consistently turns up something worth adjusting.',
            'There\'s a lot of advice in this area that\'s technically correct but practically useless. I try to focus on what actually changes behaviour.',
            'The convenience tradeoff is real. I\'ve accepted some friction in exchange for outcomes I care about.',
            'I don\'t think of this as a one-time decision. It\'s more like ongoing maintenance.',
            'The best approach is usually the one you\'ll actually follow consistently, not the theoretically optimal one.',
            'I\'m skeptical of anything that adds significant complexity without a proportional payoff.',
            'My setup has gotten simpler over the years, not more elaborate. That\'s been the right direction.',
            'The difference between what I thought I wanted and what I actually use has been instructive.',
            'Automation earns its keep when the underlying thing is genuinely repetitive. Otherwise it\'s usually just deferred thinking.',
            'I try to distinguish between things I need and things I find interesting. The lists don\'t overlap as much as I\'d like.',
            'Writing things down, even roughly, is usually where I figure out what I actually think.',
            'The question I should have asked earlier was simpler than the ones I spent time on.',
            'Context matters more here than most general advice acknowledges.',
            'I\'ve stopped trying to find the perfect version of this. Good enough and consistent beats perfect and inconsistent.',
            'Talking to people who disagree with my approach has been more useful than reading people who confirm it.',
            'The thing that changed my view wasn\'t an argument — it was seeing the alternative work in practice.',
            'I\'d rather know what I don\'t know here than be confident about something I haven\'t tested.',
            'The version of this I describe to others is usually simpler than what I actually do, which probably means I should simplify.',
        );
        shuffle( $bank );
        return array_slice( $bank, 0, $count );
    }

    /**
     * Find an existing WordPress category by name. Returns term ID or false.
     * Does NOT create categories automatically.
     */
    private static function find_category( string $label ) {
        $term = get_term_by( 'name', $label, 'category' );
        if ( $term && ! is_wp_error( $term ) ) {
            return $term->term_id;
        }
        return false;
    }
}
