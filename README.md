# DistillPress

[![Latest Release](https://img.shields.io/github/v/release/guilamu/distillpress?color=blue)](https://github.com/guilamu/distillpress/releases) [![License: AGPL-3.0](https://img.shields.io/badge/license-AGPL--3.0-green.svg)](LICENSE) [![WordPress: 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org) [![PHP: 7.4+](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)

AI-powered article summarization, teaser generation, and automatic category selection using POE or Google Gemini.

## Generate Smart Summaries & Teasers
- Choose the number of bullet points (1-20)
- Optionally limit summary length as a percentage of the original content
- Engaging teaser paragraph generated in the same API call as the summary to save tokens
- Summaries and teasers are based **only** on your article content — no hallucinations or external knowledge
- Works in both Classic Editor and Gutenberg/Block Editor
- One-click copy for both summary and teaser

## Auto-Select Categories
- Matches against your existing WordPress categories
- Set maximum number of categories to select (1-20)
- Categories are automatically checked in the editor
- Optional default category that is always applied
- Works with hierarchical categories

## Choose Your Model & Reasoning
- Pick any text model POE serves, or one of the two Gemini models
- Only the latest version of each model is listed (Claude-Opus-4.8, not 4.5 through 4.8)
- Set the reasoning effort from **Off** to **Maximum**, sent only to models that accept it
- API Request Log shows the last 10 calls with model, POE points and token counts

## Key Features
- **Multi-Provider:** Choose between POE API and Google Gemini as your AI backend
- **Token-Efficient:** Summary and teaser generated in a single API call, with adjustable reasoning effort
- **Multilingual:** Works with content in any language; responses mirror the source language
- **Translation-Ready:** All strings are internationalized, French translation included
- **Secure:** API keys can be stored in `wp-config.php`; nonce and capability checks on every AJAX request
- **GitHub Updates:** Automatic updates from GitHub releases

## Requirements
- POE API key (from poe.com → Settings → API) **or** Google Gemini API key (from [aistudio.google.com/apikey](https://aistudio.google.com/apikey))
- WordPress 6.0 or higher
- PHP 7.4 or higher

## Installation
1. Upload the `distillpress` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Settings → DistillPress** and select your **API Provider** (POE or Google Gemini)
4. Enter your API key, then pick a model and a reasoning effort
5. Open any post and use the **DistillPress** box in the editor sidebar

## FAQ
### Where do I get an API key?
**POE:** sign in at poe.com, go to **Settings → API**, generate a key, and paste it into the DistillPress settings.

**Google Gemini:** go to [aistudio.google.com/apikey](https://aistudio.google.com/apikey), create a key, and paste it into the DistillPress settings.

### Which AI models are supported?
**POE:** every text model your key can reach, with only the newest version of each family listed — `DeepSeek-V4-Flash` (the default), Claude, GPT, Gemini, Grok, Qwen and others. Click **Refresh Models** to reload the list.

**Gemini:** `gemini-flash-latest` (fast and cheap) and `gemini-pro-latest` (most capable).

### What does the Reasoning Effort setting do?
It tells the model how much thinking to spend before answering, from **Off** to **Maximum**. Providers expose different controls, so the plugin sends the one each model advertises and stays silent for models that expose none. Leave it on **Model default** to change nothing.

### Does it work with the Block Editor (Gutenberg)?
Yes. DistillPress works with both the Classic Editor and Gutenberg.

### Will the summary include made-up information?
No, it should not. The prompts enforce factual output based only on your article.

### Can I customize which post types show the meta box?
Yes, use the `distillpress_supported_post_types` filter:
```php
add_filter( 'distillpress_supported_post_types', function( $post_types ) {
    $post_types[] = 'my_custom_post_type';
    return $post_types;
} );
```

### Is my API key secure?
Yes. Your API key stays server-side. You can also define it in `wp-config.php` using `DISTILLPRESS_POE_API_KEY` or `DISTILLPRESS_GEMINI_API_KEY`.

## Project Structure
```
.
├── distillpress.php                  # Main plugin file, AJAX handlers, provider dispatch
├── uninstall.php                     # Cleanup on uninstall
├── README.md
├── LICENSE
├── admin
│   ├── css
│   │   └── admin.css                 # Meta box and settings page styles
│   └── js
│       └── admin.js                  # Meta box actions, model refresh, clipboard
├── includes
│   ├── class-api-service.php         # Shared provider logic: requests, JSON, usage log
│   ├── class-poe-api-service.php     # POE models, version filtering, reasoning controls
│   ├── class-gemini-api-service.php  # Gemini endpoint and reasoning effort
│   ├── class-admin-settings.php      # Settings page and fields
│   ├── class-meta-box.php            # Editor meta box
│   ├── class-github-updater.php      # GitHub auto-updates
│   └── Parsedown.php                 # Markdown parser for the details popup
└── languages
    ├── distillpress-fr_FR.mo         # French translation (binary)
    ├── distillpress-fr_FR.po         # French translation (source)
    └── distillpress.pot              # Translation template
```

## Changelog

### 1.4.0 - 2026-08-23
- **New:** Reasoning Effort setting (Off, Low, Medium, High, Maximum) sent only to models that advertise a reasoning control
- **New:** `DeepSeek-V4-Flash` is the default POE model
- **Fixed:** Models POE serves without an advertised endpoint list (DeepSeek, GLM, Qwen, ...) are no longer wrongly hidden
- **Fixed:** Show/Hide buttons on the API key fields work in translated interfaces
- **Fixed:** The GitHub updater no longer calls a PHP 8 function on a plugin that supports PHP 7.4
- **Fixed:** The `distillpress_supported_post_types` filter is applied late enough for themes and plugins to hook it
- **Fixed:** System prompt rules are numbered continuously when summary or teaser is disabled
- **Fixed:** Uninstall now removes the request log and cleans every option on each site of a network
- **Improved:** API errors now surface the message returned by the provider instead of a bare status code
- **Improved:** The request log records the provider and what the call was for, and no longer loads on every page
- **Improved:** Both providers share a single API service class; dead code removed across the plugin
- **Improved:** Plugin details popup, "Report a Bug" link and README aligned with the Guilamu plugin references

### 1.3.2 - 2026-08-23
- **Fixed:** "Refresh Models" now really queries POE again instead of returning the list cached one hour earlier
- **Fixed:** Errors while loading models are shown next to the button instead of failing silently in the console
- **Improved:** Only the latest version of each model is listed (Claude-Opus-4.8 instead of 4.5 to 4.8, GPT-5.4 instead of GPT-4o to GPT-5.4)
- **Improved:** Image, video and audio models POE cannot use for text generation are no longer listed
- **Improved:** The model saved in the settings stays selectable even when a newer version replaces it in the list
- **Improved:** The version number is always part of the model label ("Claude-Sonnet-4.6", never a bare "Claude-Sonnet")
- **Improved:** Models can be listed right after typing an API key, before saving the settings

### 1.3.1
- **Improved:** GitHub auto-updater now parses local README.md for plugin details popup (Description, Installation, FAQ, Changelog tabs)
- **Improved:** "View details" thickbox link added to plugin row meta
- **Improved:** Update object now includes all required fields (id, slug, plugin, new_version) for full WordPress compatibility
- **Improved:** CSS injection via admin_head for proper modal styling
- **Improved:** Markdown tables converted to div/span structures to survive wp_kses sanitization
- **New:** Parsedown.php dependency for reliable Markdown-to-HTML conversion

### 1.3.0
- **New:** Google Gemini API support as an alternative AI provider
- **New:** API Provider selector in settings (POE or Google Gemini)
- **New:** Gemini API key field with show/hide toggle and link to Google AI Studio
- **New:** Gemini model selector (gemini-flash-latest and gemini-pro-latest)
- **New:** Support for `DISTILLPRESS_GEMINI_API_KEY` constant in wp-config.php
- **Improved:** Settings page dynamically shows/hides provider-specific fields
- **Improved:** Generic error messages no longer reference a specific provider

### 1.2.0
- **New:** API Request Log now includes a "Points" column showing actual POE credits consumed
- **New:** Points cost tracking via POE's `points_history` API endpoint for accurate billing data
- **Improved:** GitHub auto-updater now prefers custom release assets (clean zips) over GitHub zipballs
- **Improved:** Added GitHub Actions release workflow for automated release packaging

### 1.1.2
- **New:** Custom Instructions field to add personalized instructions to the AI prompt

### 1.1.0
- **New:** Enable/disable summary generation in settings
- **New:** Enable/disable teaser (accroche) generation in settings
- **New:** Summary and teaser are now saved as post meta and persist across page reloads
- **New:** "Regenerate" button appears when previously generated content exists
- **New:** Dynamic button text based on enabled features (Generate Summary, Generate Teaser, or both)
- **New:** Section is hidden when both summary and teaser are disabled
- **Improved:** French translations updated (teaser → accroche)
- **Improved:** Input validation with enforced min/max ranges
- **Improved:** Modern WordPress script loading with `wp_add_inline_script()`
- **Fixed:** Missing `distillpress_default_category` option cleanup on uninstall

### 1.0.0
- Initial release
- AI-powered article summarization and teaser generation
- Automatic category selection
- Support for Classic Editor and Gutenberg
- GitHub auto-updates
- Multilingual support

## Security

If you discover a security vulnerability in this plugin, please report it responsibly through [GitHub Security Advisories](https://github.com/guilamu/distillpress/security/advisories/new). Do not open a public issue for security reports.

## Contributing

Contributions are welcome! Please open an issue or submit a pull request on [GitHub](https://github.com/guilamu/distillpress).

For translations, the plugin uses WordPress i18n. You can contribute translations by editing the `.po` files in the `languages/` directory and generating the corresponding `.mo` files with the `wp i18n` CLI commands.

## License
This project is licensed under the GNU Affero General Public License v3.0 (AGPL-3.0) — see the [LICENSE](LICENSE) file for details.

---

Made with love for the WordPress community
