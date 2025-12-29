<?php
/**
 * Plugin Name:       DistillPress
 * Plugin URI:        https://github.com/guilamu/distillpress
 * Description:       AI-powered article summarization and automatic category selection using POE API. Distill your content to its essence.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            guilamu
 * Author URI:        https://github.com/guilamu
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       distillpress
 * Domain Path:       /languages
 * Update URI:        https://github.com/guilamu/distillpress/
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('DISTILLPRESS_VERSION', '1.2.0');
define('DISTILLPRESS_PATH', plugin_dir_path(__FILE__));
define('DISTILLPRESS_URL', plugin_dir_url(__FILE__));
define('DISTILLPRESS_BASENAME', plugin_basename(__FILE__));

/**
 * Main DistillPress class.
 */
final class DistillPress
{

	/**
	 * Single instance of the class.
	 *
	 * @var DistillPress|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return DistillPress
	 */
	public static function instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct()
	{
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Include required files.
	 */
	private function includes()
	{
		require_once DISTILLPRESS_PATH . 'includes/class-poe-api-service.php';
		require_once DISTILLPRESS_PATH . 'includes/class-github-updater.php';
		require_once DISTILLPRESS_PATH . 'includes/class-admin-settings.php';
		require_once DISTILLPRESS_PATH . 'includes/class-meta-box.php';
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks()
	{
		add_action('plugins_loaded', array($this, 'load_textdomain'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

		// Initialize admin settings
		DistillPress_Admin_Settings::init();

		// Initialize meta box
		DistillPress_Meta_Box::init();

		// Register AJAX handlers
		add_action('wp_ajax_distillpress_generate_summary', array($this, 'ajax_generate_summary'));
		add_action('wp_ajax_distillpress_auto_categorize', array($this, 'ajax_auto_categorize'));
		add_action('wp_ajax_distillpress_get_models', array($this, 'ajax_get_models'));

		// Add settings link to plugins page
		add_filter('plugin_action_links_' . DISTILLPRESS_BASENAME, array($this, 'add_settings_link'));
	}

	/**
	 * Load plugin text domain.
	 */
	public function load_textdomain()
	{
		load_plugin_textdomain(
			'distillpress',
			false,
			dirname(DISTILLPRESS_BASENAME) . '/languages'
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets($hook)
	{
		$screen = get_current_screen();

		// Load on post edit screens and settings page
		$allowed_screens = array('post', 'page', 'settings_page_distillpress');
		if (!$screen || !in_array($screen->base, $allowed_screens, true)) {
			if ('post.php' !== $hook && 'post-new.php' !== $hook && 'settings_page_distillpress' !== $hook) {
				return;
			}
		}

		wp_enqueue_style(
			'distillpress-admin',
			DISTILLPRESS_URL . 'admin/css/admin.css',
			array(),
			DISTILLPRESS_VERSION
		);

		wp_enqueue_script(
			'distillpress-admin',
			DISTILLPRESS_URL . 'admin/js/admin.js',
			array('jquery'),
			DISTILLPRESS_VERSION,
			true
		);

		$localize_data = array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('distillpress_nonce'),
			'i18n' => array(
				'processing' => __('Processing...', 'distillpress'),
				'generating_summary' => __('Generating summary...', 'distillpress'),
				'generating_teaser' => __('Generating teaser...', 'distillpress'),
				'generating_both' => __('Generating summary and teaser...', 'distillpress'),
				'finding_categories' => __('Finding categories...', 'distillpress'),
				'error' => __('An error occurred', 'distillpress'),
				'success' => __('Success!', 'distillpress'),
				'summary_generated' => __('Summary generated successfully!', 'distillpress'),
				'teaser_generated' => __('Teaser generated successfully!', 'distillpress'),
				'both_generated' => __('Summary and teaser generated successfully!', 'distillpress'),
				'categories_selected' => __('Categories selected successfully!', 'distillpress'),
				'no_content' => __('Please add content to your post first.', 'distillpress'),
				'no_categories' => __('No matching categories found.', 'distillpress'),
				'copy_summary' => __('Copy summary', 'distillpress'),
				'copy_teaser' => __('Copy teaser', 'distillpress'),
				'copied' => __('Copied!', 'distillpress'),
				'no_teaser' => __('No teaser generated.', 'distillpress'),
				'no_summary' => __('No summary generated.', 'distillpress'),
			),
		);

		wp_add_inline_script(
			'distillpress-admin',
			'var distillpressData = ' . wp_json_encode($localize_data) . ';',
			'before'
		);
	}

	/**
	 * Add settings link to plugins page.
	 *
	 * @param array $links Plugin action links.
	 * @return array Modified links.
	 */
	public function add_settings_link($links)
	{
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url('options-general.php?page=distillpress'),
			__('Settings', 'distillpress')
		);
		array_unshift($links, $settings_link);
		return $links;
	}

	/**
	 * Get API key from settings or constant.
	 *
	 * @return string API key.
	 */
	public static function get_api_key()
	{
		if (defined('DISTILLPRESS_POE_API_KEY')) {
			return DISTILLPRESS_POE_API_KEY;
		}
		return get_option('distillpress_api_key', '');
	}

	/**
	 * Get selected model.
	 *
	 * @return string Model ID.
	 */
	public static function get_model()
	{
		return get_option('distillpress_model', 'gpt-4o-mini');
	}

	/**
	 * AJAX handler: Generate summary.
	 */
	public function ajax_generate_summary()
	{
		check_ajax_referer('distillpress_nonce', 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'distillpress')));
		}

		$content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
		$post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
		$num_points = isset($_POST['num_points']) ? absint($_POST['num_points']) : 3;
		$num_points = min(max($num_points, 1), 20);
		$reduction_percent = isset($_POST['reduction_percent']) ? absint($_POST['reduction_percent']) : 0;
		$reduction_percent = min($reduction_percent, 100);

		// Get settings for what to generate.
		$enable_summary = get_option('distillpress_enable_summary', true);
		$enable_teaser = get_option('distillpress_enable_teaser', true);

		// If both are disabled, return error.
		if (!$enable_summary && !$enable_teaser) {
			wp_send_json_error(array('message' => __('Both summary and teaser are disabled in settings.', 'distillpress')));
		}

		if (empty($content)) {
			wp_send_json_error(array('message' => __('No content provided.', 'distillpress')));
		}

		$api_key = self::get_api_key();
		$model = self::get_model();

		if (empty($api_key)) {
			wp_send_json_error(array('message' => __('POE API key not configured. Please go to Settings > DistillPress.', 'distillpress')));
		}

		// Strip HTML for analysis.
		$plain_content = wp_strip_all_tags($content);
		$content_length = mb_strlen($plain_content);

		// Calculate max characters if reduction percentage is set.
		$max_chars_instruction = '';
		if ($enable_summary && $reduction_percent > 0 && $reduction_percent <= 100) {
			$max_chars = intval(($content_length * $reduction_percent) / 100);
			$max_chars_per_point = intval($max_chars / $num_points);
			/* translators: 1: total max characters, 2: max characters per point */
			$max_chars_instruction = sprintf(
				__('The total summary must not exceed %1$d characters (approximately %2$d characters per point).', 'distillpress'),
				$max_chars,
				$max_chars_per_point
			);
		}

		// Build system prompt based on what's enabled.
		$system_instructions = array(
			__('1. ONLY use information explicitly stated in the source text', 'distillpress'),
			__('2. NEVER add interpretations, opinions, or external knowledge', 'distillpress'),
			__('3. NEVER hallucinate or invent information not present in the text', 'distillpress'),
		);

		if ($enable_summary) {
			$system_instructions[] = __('4. Use neutral, objective language for the summary', 'distillpress');
		}
		if ($enable_teaser) {
			$system_instructions[] = __('5. Make the teaser engaging but still factual', 'distillpress');
		}
		$system_instructions[] = __('6. Preserve the original meaning accurately', 'distillpress');
		$system_instructions[] = __('7. Respond in the SAME LANGUAGE as the source text', 'distillpress');

		// Determine JSON format instruction.
		if ($enable_summary && $enable_teaser) {
			$system_instructions[] = __('8. Return your response in JSON format with "summary" and "teaser" fields', 'distillpress');
			$json_format = '{"summary": "• Point 1\n• Point 2\n• Point 3", "teaser": "Your teaser paragraph here."}';
		} elseif ($enable_summary) {
			$system_instructions[] = __('8. Return your response in JSON format with "summary" field', 'distillpress');
			$json_format = '{"summary": "• Point 1\n• Point 2\n• Point 3"}';
		} else {
			$system_instructions[] = __('8. Return your response in JSON format with "teaser" field', 'distillpress');
			$json_format = '{"teaser": "Your teaser paragraph here."}';
		}

		$system_prompt = __('You are a precise summarization assistant. Your task is to create factual content based EXCLUSIVELY on the provided text. You must:', 'distillpress') . "\n\n" .
			implode("\n", $system_instructions);

		// Append custom instructions if set.
		$custom_prompt = get_option('distillpress_custom_prompt', '');
		if (!empty($custom_prompt)) {
			$system_prompt .= "\n\n" . __('Additional instructions:', 'distillpress') . "\n" . $custom_prompt;
		}

		// Build user prompt.
		$user_prompt = '';

		if ($enable_summary && $enable_teaser) {
			$user_prompt .= __('Analyze the following article and provide both a summary and a teaser.', 'distillpress') . "\n\n";
		} elseif ($enable_summary) {
			$user_prompt .= __('Analyze the following article and provide a summary.', 'distillpress') . "\n\n";
		} else {
			$user_prompt .= __('Analyze the following article and provide a teaser.', 'distillpress') . "\n\n";
		}

		if ($enable_summary) {
			/* translators: %d: number of bullet points */
			$user_prompt .= sprintf(
				__('SUMMARY: Create exactly %d key bullet points.', 'distillpress'),
				$num_points
			) . "\n";

			if (!empty($max_chars_instruction)) {
				$user_prompt .= $max_chars_instruction . "\n";
			}

			$user_prompt .= __('- Each point must be a complete, standalone statement', 'distillpress') . "\n" .
				__('- Start each point with a bullet (•)', 'distillpress') . "\n" .
				__('- Focus on the most important and factual information', 'distillpress') . "\n\n";
		}

		if ($enable_teaser) {
			$user_prompt .= __('TEASER: Write a short, engaging paragraph (2-3 sentences) that entices readers to read the full article. The teaser should:', 'distillpress') . "\n" .
				__('- Highlight the most compelling aspect of the article', 'distillpress') . "\n" .
				__('- Create curiosity without revealing everything', 'distillpress') . "\n" .
				__('- Stay factual and based only on the article content', 'distillpress') . "\n\n";
		}

		$user_prompt .= __('Return ONLY valid JSON in this exact format:', 'distillpress') . "\n" .
			$json_format . "\n\n" .
			__('Source text:', 'distillpress') . "\n" . $plain_content;

		$result = DistillPress_POE_API_Service::chat_with_system(
			$api_key,
			$model,
			$system_prompt,
			$user_prompt,
			0.4,
			2500
		);

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}

		// Parse the JSON response.
		$parsed = DistillPress_POE_API_Service::extract_json_from_response($result);

		$summary = '';
		$teaser = '';

		if (is_array($parsed)) {
			$summary = isset($parsed['summary']) ? trim($parsed['summary']) : '';
			$teaser = isset($parsed['teaser']) ? trim($parsed['teaser']) : '';
		} else {
			// Fallback: treat entire response as summary if JSON parsing fails.
			if ($enable_summary) {
				$summary = trim($result);
			}
		}

		// Save to post meta if we have a post ID.
		if ($post_id > 0) {
			if ($enable_summary && !empty($summary)) {
				update_post_meta($post_id, '_distillpress_summary', $summary);
			}
			if ($enable_teaser && !empty($teaser)) {
				update_post_meta($post_id, '_distillpress_teaser', $teaser);
			}
		}

		wp_send_json_success(
			array(
				'summary' => $summary,
				'teaser' => $teaser,
			)
		);
	}

	/**
	 * AJAX handler: Auto-categorize.
	 */
	public function ajax_auto_categorize()
	{
		check_ajax_referer('distillpress_nonce', 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'distillpress')));
		}

		$content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
		$max_categories = isset($_POST['max_categories']) ? absint($_POST['max_categories']) : 3;
		$max_categories = min(max($max_categories, 1), 20); // Enforce 1-20 range.
		$post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

		if (empty($content)) {
			wp_send_json_error(array('message' => __('No content provided.', 'distillpress')));
		}

		$api_key = self::get_api_key();
		$model = self::get_model();

		if (empty($api_key)) {
			wp_send_json_error(array('message' => __('POE API key not configured. Please go to Settings > DistillPress.', 'distillpress')));
		}

		// Default category (always applied)
		$default_category_id = absint(get_option('distillpress_default_category', 0));
		$default_category_name = '';
		if ($default_category_id > 0) {
			$default_term = get_term($default_category_id, 'category');
			if ($default_term && !is_wp_error($default_term)) {
				$default_category_name = $default_term->name;
			} else {
				$default_category_id = 0;
			}
		}

		// Get available categories
		$categories = get_terms(
			array(
				'taxonomy' => 'category',
				'hide_empty' => false,
			)
		);

		if (empty($categories) || is_wp_error($categories)) {
			wp_send_json_error(array('message' => __('No categories available.', 'distillpress')));
		}

		// Build category list with IDs
		$category_map = array();
		$category_names = array();
		foreach ($categories as $cat) {
			$category_map[$cat->name] = $cat->term_id;
			$category_names[] = $cat->name;
		}

		$categories_list = implode(', ', $category_names);
		$plain_content = wp_strip_all_tags($content);

		// Build prompt for category selection
		$system_prompt = __('You are a content categorization assistant. Your task is to analyze text and select the most relevant categories from a predefined list. You must:', 'distillpress') . "\n\n" .
			__('1. ONLY select categories from the provided list', 'distillpress') . "\n" .
			__('2. Choose categories based on the actual content, not assumptions', 'distillpress') . "\n" .
			__('3. Return ONLY a JSON array of category names, nothing else', 'distillpress') . "\n" .
			__('4. If no categories match, return an empty array []', 'distillpress') . "\n" .
			__('5. Order categories by relevance (most relevant first)', 'distillpress');

		/* translators: 1: maximum number of categories, 2: comma-separated list of available categories */
		$user_prompt = sprintf(
			__('Select up to %1$d most relevant categories for the following content from this list: %2$s', 'distillpress'),
			$max_categories,
			$categories_list
		) . "\n\n" .
			__('Return ONLY a JSON array of category names. Example: ["Category1", "Category2"]', 'distillpress') . "\n\n" .
			__('Content to categorize:', 'distillpress') . "\n" . $plain_content;

		$result = DistillPress_POE_API_Service::chat_with_system(
			$api_key,
			$model,
			$system_prompt,
			$user_prompt,
			0.2, // Very low temperature for precise selection
			500
		);

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}

		// Parse the JSON response
		$selected_categories = DistillPress_POE_API_Service::extract_json_from_response($result);

		if (!is_array($selected_categories)) {
			wp_send_json_error(array('message' => __('Failed to parse category response.', 'distillpress')));
		}

		// Filter to only valid categories and get their IDs
		$valid_category_ids = array();
		$valid_category_names = array();

		foreach ($selected_categories as $cat_name) {
			// Case-insensitive search
			foreach ($category_map as $name => $id) {
				if (strcasecmp($cat_name, $name) === 0) {
					$valid_category_ids[] = $id;
					$valid_category_names[] = $name;
					break;
				}
			}
		}

		// Limit to max categories (AI-selected)
		$valid_category_ids = array_slice($valid_category_ids, 0, $max_categories);
		$valid_category_names = array_slice($valid_category_names, 0, $max_categories);

		// Always ensure default category is applied
		if ($default_category_id > 0 && !in_array($default_category_id, $valid_category_ids, true)) {
			array_unshift($valid_category_ids, $default_category_id);
			if ($default_category_name) {
				array_unshift($valid_category_names, $default_category_name);
			}
		}

		if (empty($valid_category_ids)) {
			wp_send_json_error(array('message' => __('No matching categories found.', 'distillpress')));
		}

		// Optionally set categories on the post
		if ($post_id > 0) {
			wp_set_post_categories($post_id, $valid_category_ids);
		}

		wp_send_json_success(
			array(
				'category_ids' => $valid_category_ids,
				'category_names' => $valid_category_names,
			)
		);
	}

	/**
	 * AJAX handler: Get available models.
	 */
	public function ajax_get_models()
	{
		check_ajax_referer('distillpress_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'distillpress')));
		}

		$api_key = self::get_api_key();

		if (empty($api_key)) {
			wp_send_json_error(array('message' => __('Please enter your API key first.', 'distillpress')));
		}

		$models = DistillPress_POE_API_Service::get_models($api_key);

		if (is_wp_error($models)) {
			wp_send_json_error(array('message' => $models->get_error_message()));
		}

		wp_send_json_success(array('models' => $models));
	}
}

/**
 * Initialize the plugin.
 *
 * @return DistillPress
 */
function distillpress()
{
	return DistillPress::instance();
}

// Initialize
distillpress();
