# WP Noise Injection

A WordPress plugin that generates varied draft posts across configurable topic buckets to broaden a site's content fingerprint.

Posts are **always created as drafts** — nothing is published automatically. You review and edit each draft before publishing.

---

## What It Does

The plugin maintains a set of **topic buckets**, each with a list of **seed outlines** — short phrases describing post concepts. On demand (or on a schedule), it picks a seed at random, builds a structured draft post from it, and saves it for your review.

A **diversity score** (0–100, based on Shannon entropy) measures how evenly your published and draft content is distributed across topic buckets. The score updates automatically and surfaces recommendations for which buckets need more content.

---

## Installation

1. Download or clone this repository
2. Zip the `wp-noise-injection` directory
3. In WordPress admin: **Plugins → Add New Plugin → Upload Plugin**
4. Upload the zip, install, and activate
5. The **Noise Injection** menu will appear in the sidebar

Requires WordPress 6.0+ and PHP 8.0+.

---

## Configuration

### Settings (Noise Injection → Settings)

| Setting | Default | Description |
|---|---|---|
| Post Author | Current user | Author assigned to generated draft posts |
| Auto-Generate | Off | Enables WP-Cron scheduled generation |
| Schedule | Weekly | Weekly / Every Two Weeks / Monthly |
| Posts per Run | 1 | Drafts created per scheduled run (1–5) |

### Topic Buckets (Noise Injection → Topic Buckets)

Four example buckets are pre-installed: **Professional Topics**, **Local Interest**, **Personal Interests**, and **Reflections & Notes**. Replace or extend these with buckets relevant to your site.

Each bucket has:
- **Label** — the display name (shown in admin and used for category matching)
- **Enabled** — whether this bucket is active
- **Weight** — relative selection probability (1–5); higher = chosen more often
- **Seeds** — one post concept per line, used as the basis for generated drafts

**Seed format examples:**
```
Why I switched to X after years of using Y
Local history: the story behind [landmark]
Gear review: my six-month verdict on [product]
How I approach [task] and what changed my mind about it
Topic: more specific detail that becomes the post body focus
```

Seeds formatted as `Title: detail` use the part before the colon as the post title.

---

## Generating Posts

### Dashboard Widget
Click **Generate Draft Post** on the Noise Diversity Score widget on your WordPress dashboard. The draft is created immediately and a link to edit it appears inline.

### Generate Now Page
Go to **Noise Injection → Generate Now** to:
- Set how many drafts to create (1–5)
- Optionally pin generation to a specific topic bucket
- See your current diversity score and breakdown
- Review pending drafts

### Scheduled (WP-Cron)
Enable **Auto-Generate** in Settings. WordPress will create drafts automatically on your chosen schedule.

> **Note:** WP-Cron only fires when someone visits your site. On low-traffic sites, set up a real server cron job:
> ```
> */15 * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null
> ```

---

## Diversity Score

The score measures how evenly distributed your content is across enabled topic buckets, using Shannon entropy normalised to 0–100.

| Score | Label | Meaning |
|---|---|---|
| 80–100 | Excellent | Well-balanced across all buckets |
| 60–79 | Good | Slight imbalance; minor gaps |
| 35–59 | Moderate | Noticeable concentration in one area |
| 0–34 | Low | Most content in a single bucket |

The score counts all posts with `_wni_topic` metadata (set at generation time), plus any posts in matching WordPress categories as a fallback.

---

## Customising Generated Content

The generator builds post bodies by combining your seed text with generic filler sentences from a built-in observation bank. The filler sentences are intentionally topic-neutral — they read as plausible personal reflection for any subject.

To produce more specific or stylised content, edit the `observations()` method in `includes/class-wni-generator.php`. You can replace the generic bank with topic-specific sentences keyed by bucket ID, following the same pattern.

---

## File Structure

```
wp-noise-injection/
├── wp-noise-injection.php          Main plugin file, bootstrap, cron
├── includes/
│   ├── class-wni-settings.php      Settings storage (wp_options)
│   ├── class-wni-topics.php        Topic bucket management
│   ├── class-wni-generator.php     Draft post generation engine
│   ├── class-wni-diversity.php     Shannon entropy diversity scoring
│   ├── class-wni-widget.php        Dashboard widget + AJAX handler
│   └── class-wni-admin.php         Admin menu and pages
└── assets/
    ├── admin.css                   Admin page styles
    ├── widget.css                  Dashboard widget styles
    └── widget.js                   Widget AJAX (jQuery)
```

---

## License

GPL-2.0-or-later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
