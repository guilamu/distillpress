# DistillPress

AI-powered article summarization, teaser generation, and automatic category selection using the POE API. Distill your content to its essence and hook readers in one pass.

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

## Key Features
- **Factual & Accurate:** Prompts prevent hallucinations; outputs stay grounded in your article
- **Token-Efficient:** Summary and teaser generated in a single API call
- **Multilingual:** Works with content in any language; responses mirror the source language
- **Translation-Ready:** All strings are internationalized
- **Secure:** API keys can be stored in `wp-config.php`; nonce verification on all AJAX requests
- **GitHub Updates:** Automatic updates from GitHub releases

## Requirements
- POE API key (get it from poe.com → Settings → API)
- WordPress 6.0 or higher
- PHP 7.4 or higher

## Installation
1. Upload the `distillpress` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Settings → DistillPress** and enter your POE API key
4. (Optional) Configure default settings for summaries and categories
5. Use the DistillPress meta box in your post/page editor

## FAQ
### Where do I get a POE API key?
1. Sign in at poe.com
2. Go to **Settings → API**
3. Generate a new API key
4. Paste it into DistillPress settings

### Which AI models are supported?
All text models available through the POE API, including GPT-4o-mini, Claude, and others. Pick your preferred model in settings.

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
Yes. Your API key stays server-side.

## Changelog

### 1.3.0
- **New:** Integrated Guilamu Bug Reporter support
- **New:** Added "Report a Bug" link in plugins list

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

