<?php
/**
 * Admin Settings Class
 *
 * Handles the plugin settings page in WordPress admin.
 *
 * @package DistillPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class DistillPress_Admin_Settings
 *
 * Manages the settings page and options.
 */
class DistillPress_Admin_Settings
{

	/**
	 * Initialize the admin settings.
	 */
	public static function init()
	{
		add_action('admin_menu', array(__CLASS__, 'add_settings_page'));
		add_action('admin_init', array(__CLASS__, 'register_settings'));
	}

	/**
	 * Add the settings page to the admin menu.
	 */
	public static function add_settings_page()
	{
		add_options_page(
			__('DistillPress Settings', 'distillpress'),
			__('DistillPress', 'distillpress'),
			'manage_options',
			'distillpress',
			array(__CLASS__, 'render_settings_page')
		);
	}

	/**
	 * Register plugin settings.
	 */
	public static function register_settings()
	{
		// Register settings
		register_setting(
			'distillpress_settings',
			'distillpress_api_key',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => '',
			)
		);

		register_setting(
			'distillpress_settings',
			'distillpress_model',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => 'gpt-4o-mini',
			)
		);

		register_setting(
			'distillpress_settings',
			'distillpress_default_num_points',
			array(
				'type' => 'integer',
				'sanitize_callback' => 'absint',
				'default' => 3,
			)
		);

		register_setting(
			'distillpress_settings',
			'distillpress_default_reduction_percent',
			array(
				'type' => 'integer',
				'sanitize_callback' => 'absint',
				'default' => 0,
			)
		);

		register_setting(
			'distillpress_settings',
			'distillpress_default_max_categories',
			array(
				'type' => 'integer',
				'sanitize_callback' => 'absint',
				'default' => 3,
			)
		);

		register_setting(
			'distillpress_settings',
			'distillpress_default_category',
			array(
				'type' => 'integer',
				'sanitize_callback' => 'absint',
				'default' => 0,
			)
		);

		register_setting(
			'distillpress_settings',
			'distillpress_enable_summary',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true,
			)
		);

		register_setting(
			'distillpress_settings',
			'distillpress_enable_teaser',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true,
			)
		);

		register_setting(
			'distillpress_settings',
			'distillpress_custom_prompt',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default' => '',
			)
		);

		// API Settings Section
		add_settings_section(
			'distillpress_api_section',
			__('POE API Settings', 'distillpress'),
			array(__CLASS__, 'render_api_section'),
			'distillpress'
		);

		add_settings_field(
			'distillpress_api_key',
			__('API Key', 'distillpress'),
			array(__CLASS__, 'render_api_key_field'),
			'distillpress',
			'distillpress_api_section'
		);

		add_settings_field(
			'distillpress_model',
			__('AI Model', 'distillpress'),
			array(__CLASS__, 'render_model_field'),
			'distillpress',
			'distillpress_api_section'
		);

		// Summary Settings Section
		add_settings_section(
			'distillpress_summary_section',
			__('Summary Settings', 'distillpress'),
			array(__CLASS__, 'render_summary_section'),
			'distillpress'
		);

		add_settings_field(
			'distillpress_default_num_points',
			__('Default Number of Points', 'distillpress'),
			array(__CLASS__, 'render_num_points_field'),
			'distillpress',
			'distillpress_summary_section'
		);

		add_settings_field(
			'distillpress_default_reduction_percent',
			__('Default Reduction Percentage', 'distillpress'),
			array(__CLASS__, 'render_reduction_percent_field'),
			'distillpress',
			'distillpress_summary_section'
		);

		add_settings_field(
			'distillpress_enable_summary',
			__('Enable Summary', 'distillpress'),
			array(__CLASS__, 'render_enable_summary_field'),
			'distillpress',
			'distillpress_summary_section'
		);

		add_settings_field(
			'distillpress_enable_teaser',
			__('Enable Teaser', 'distillpress'),
			array(__CLASS__, 'render_enable_teaser_field'),
			'distillpress',
			'distillpress_summary_section'
		);

		add_settings_field(
			'distillpress_custom_prompt',
			__('Custom Instructions', 'distillpress'),
			array(__CLASS__, 'render_custom_prompt_field'),
			'distillpress',
			'distillpress_summary_section'
		);

		// Category Settings Section
		add_settings_section(
			'distillpress_category_section',
			__('Category Settings', 'distillpress'),
			array(__CLASS__, 'render_category_section'),
			'distillpress'
		);

		add_settings_field(
			'distillpress_default_max_categories',
			__('Default Max Categories', 'distillpress'),
			array(__CLASS__, 'render_max_categories_field'),
			'distillpress',
			'distillpress_category_section'
		);

		add_settings_field(
			'distillpress_default_category',
			__('Default Category', 'distillpress'),
			array(__CLASS__, 'render_default_category_field'),
			'distillpress',
			'distillpress_category_section'
		);
	}

	/**
	 * Render the settings page.
	 */
	public static function render_settings_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		// Check if API key is defined in wp-config.php
		$api_key_from_constant = defined('DISTILLPRESS_POE_API_KEY');
		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

			<?php if ($api_key_from_constant): ?>
				<div class="notice notice-info">
					<p>
						<?php
						printf(
							/* translators: %s: constant name */
							esc_html__('Your API key is defined in wp-config.php using the %s constant.', 'distillpress'),
							'<code>DISTILLPRESS_POE_API_KEY</code>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php
				settings_fields('distillpress_settings');
				do_settings_sections('distillpress');
				submit_button(__('Save Settings', 'distillpress'));
				?>
			</form>

			<?php self::render_points_log_accordion(); ?>
		</div>
		<?php
	}

	/**
	 * Render API section description.
	 */
	public static function render_api_section()
	{
		echo '<p>' . esc_html__('Configure your POE API connection settings.', 'distillpress') . '</p>';
	}

	/**
	 * Render summary section description.
	 */
	public static function render_summary_section()
	{
		echo '<p>' . esc_html__('Configure default settings for article summarization.', 'distillpress') . '</p>';
	}

	/**
	 * Render category section description.
	 */
	public static function render_category_section()
	{
		echo '<p>' . esc_html__('Configure default settings for automatic category selection.', 'distillpress') . '</p>';
	}

	/**
	 * Render API key field.
	 */
	public static function render_api_key_field()
	{
		$api_key_from_constant = defined('DISTILLPRESS_POE_API_KEY');
		$api_key = get_option('distillpress_api_key', '');

		if ($api_key_from_constant) {
			?>
			<input type="text" value="<?php echo esc_attr(str_repeat('•', 32)); ?>" class="regular-text" disabled>
			<p class="description">
				<?php esc_html_e('API key is defined in wp-config.php and cannot be changed here.', 'distillpress'); ?>
			</p>
			<?php
		} else {
			?>
			<input type="password" id="distillpress_api_key" name="distillpress_api_key" value="<?php echo esc_attr($api_key); ?>"
				class="regular-text" autocomplete="off">
			<button type="button" class="button" id="distillpress-toggle-api-key">
				<?php esc_html_e('Show', 'distillpress'); ?>
			</button>
			<p class="description">
				<?php esc_html_e('Enter your POE API key. Get it from poe.com → Settings → API.', 'distillpress'); ?>
			</p>
			<?php
		}
	}

	/**
	 * Render model selection field.
	 */
	public static function render_model_field()
	{
		$current_model = get_option('distillpress_model', 'gpt-4o-mini');
		$api_key = DistillPress::get_api_key();
		?>
		<select id="distillpress_model" name="distillpress_model" class="regular-text">
			<?php if (empty($api_key)): ?>
				<option value=""><?php esc_html_e('Enter API key first', 'distillpress'); ?></option>
			<?php else: ?>
				<option value="<?php echo esc_attr($current_model); ?>">
					<?php echo esc_html($current_model); ?>
				</option>
			<?php endif; ?>
		</select>
		<button type="button" class="button" id="distillpress-refresh-models" <?php echo empty($api_key) ? 'disabled' : ''; ?>>
			<?php esc_html_e('Refresh Models', 'distillpress'); ?>
		</button>
		<span id="distillpress-models-loading" style="display: none;">
			<span class="spinner is-active" style="float: none; margin-top: 0;"></span>
		</span>
		<p class="description">
			<?php esc_html_e('Select the AI model to use. Click "Refresh Models" to load available models.', 'distillpress'); ?>
		</p>
		<?php
	}

	/**
	 * Render number of points field.
	 */
	public static function render_num_points_field()
	{
		$num_points = get_option('distillpress_default_num_points', 3);
		?>
		<input type="number" id="distillpress_default_num_points" name="distillpress_default_num_points"
			value="<?php echo esc_attr($num_points); ?>" class="small-text" min="1" max="20">
		<p class="description">
			<?php esc_html_e('Default number of bullet points for the summary (1-20).', 'distillpress'); ?>
		</p>
		<?php
	}

	/**
	 * Render reduction percentage field.
	 */
	public static function render_reduction_percent_field()
	{
		$reduction = get_option('distillpress_default_reduction_percent', 0);
		?>
		<input type="number" id="distillpress_default_reduction_percent" name="distillpress_default_reduction_percent"
			value="<?php echo esc_attr($reduction); ?>" class="small-text" min="0" max="100">
		<span>%</span>
		<p class="description">
			<?php esc_html_e('Target summary length as a percentage of the original content. Set to 0 to disable this constraint.', 'distillpress'); ?>
			<br>
			<?php esc_html_e('Example: If set to 10% and the article has 1000 characters, the summary will be limited to ~100 characters.', 'distillpress'); ?>
		</p>
		<?php
	}

	/**
	 * Render max categories field.
	 */
	public static function render_max_categories_field()
	{
		$max_cats = get_option('distillpress_default_max_categories', 3);
		?>
		<input type="number" id="distillpress_default_max_categories" name="distillpress_default_max_categories"
			value="<?php echo esc_attr($max_cats); ?>" class="small-text" min="1" max="20">
		<p class="description">
			<?php esc_html_e('Maximum number of categories to auto-select (1-20).', 'distillpress'); ?>
		</p>
		<?php
	}

	/**
	 * Render default category field.
	 */
	public static function render_default_category_field()
	{
		$selected = get_option('distillpress_default_category', 0);
		wp_dropdown_categories(
			array(
				'show_option_none' => __('None', 'distillpress'),
				'option_none_value' => 0,
				'taxonomy' => 'category',
				'name' => 'distillpress_default_category',
				'id' => 'distillpress_default_category',
				'class' => 'regular-text',
				'hide_empty' => false,
				'orderby' => 'name',
				'order' => 'ASC',
				'selected' => $selected,
			)
		);
		?>
		<p class="description">
			<?php esc_html_e('This category will always be applied in addition to AI-selected categories.', 'distillpress'); ?>
		</p>
		<?php
	}

	/**
	 * Render enable summary checkbox.
	 */
	public static function render_enable_summary_field()
	{
		$enabled = get_option('distillpress_enable_summary', true);
		?>
		<label>
			<input type="checkbox" id="distillpress_enable_summary" name="distillpress_enable_summary" value="1" <?php checked($enabled); ?>>
			<?php esc_html_e('Generate bullet point summaries', 'distillpress'); ?>
		</label>
		<?php
	}

	/**
	 * Render enable teaser checkbox.
	 */
	public static function render_enable_teaser_field()
	{
		$enabled = get_option('distillpress_enable_teaser', true);
		?>
		<label>
			<input type="checkbox" id="distillpress_enable_teaser" name="distillpress_enable_teaser" value="1" <?php checked($enabled); ?>>
			<?php esc_html_e('Generate teaser paragraphs', 'distillpress'); ?>
		</label>
		<?php
	}

	/**
	 * Render custom prompt textarea.
	 */
	public static function render_custom_prompt_field()
	{
		$custom_prompt = get_option('distillpress_custom_prompt', '');
		?>
		<textarea id="distillpress_custom_prompt" name="distillpress_custom_prompt" rows="3" class="large-text"
			placeholder="<?php esc_attr_e('Example: Always mention the author name. Use a formal tone.', 'distillpress'); ?>"><?php echo esc_textarea($custom_prompt); ?></textarea>
		<p class="description">
			<?php esc_html_e('Add custom instructions to the AI prompt (1-2 sentences). These will be appended to the system prompt.', 'distillpress'); ?>
		</p>
		<?php
	}

	/**
	 * Render the points log accordion.
	 */
	public static function render_points_log_accordion()
	{
		$log = DistillPress_POE_API_Service::get_request_log();
		?>
		<details class="distillpress-points-log">
			<summary><?php esc_html_e('API Request Log (Last 10)', 'distillpress'); ?></summary>
			<div class="distillpress-points-log-content">
				<?php if (empty($log)): ?>
					<p class="description"><?php esc_html_e('No API requests logged yet.', 'distillpress'); ?></p>
				<?php else: ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Date/Time', 'distillpress'); ?></th>
								<th><?php esc_html_e('Action', 'distillpress'); ?></th>
								<th><?php esc_html_e('Model', 'distillpress'); ?></th>
								<th><?php esc_html_e('Points', 'distillpress'); ?></th>
								<th><?php esc_html_e('Tokens', 'distillpress'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($log as $entry): ?>
								<tr>
									<td><?php echo esc_html($entry['timestamp']); ?></td>
									<td><?php echo esc_html(ucwords(str_replace('_', ' ', $entry['action_type']))); ?></td>
									<td><?php echo esc_html($entry['model']); ?></td>
									<td>
										<?php
										if (!empty($entry['cost_points'])) {
											echo esc_html($entry['cost_points']);
										} else {
											esc_html_e('N/A', 'distillpress');
										}
										?>
									</td>
									<td>
										<?php
										if (null !== $entry['total_tokens']) {
											echo esc_html($entry['total_tokens']);
											if (null !== $entry['prompt_tokens'] && null !== $entry['completion_tokens']) {
												echo ' <small>(' . esc_html($entry['prompt_tokens']) . ' + ' . esc_html($entry['completion_tokens']) . ')</small>';
											}
										} else {
											esc_html_e('N/A', 'distillpress');
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}
}

