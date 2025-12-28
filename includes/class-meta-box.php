<?php
/**
 * Meta Box Class
 *
 * Adds the DistillPress meta box to the post editor.
 *
 * @package DistillPress
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DistillPress_Meta_Box
 *
 * Manages the meta box in the post editor.
 */
class DistillPress_Meta_Box {

	/**
	 * Post types that support the meta box.
	 *
	 * @var array
	 */
	private static $supported_post_types = array( 'post', 'page' );

	/**
	 * Initialize the meta box.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );

		// Allow filtering of supported post types
		self::$supported_post_types = apply_filters( 'distillpress_supported_post_types', self::$supported_post_types );
	}

	/**
	 * Add the meta box.
	 */
	public static function add_meta_box() {
		foreach ( self::$supported_post_types as $post_type ) {
			add_meta_box(
				'distillpress_meta_box',
				__( 'DistillPress', 'distillpress' ),
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post The post object.
	 */
	public static function render_meta_box( $post ) {
		$api_key = DistillPress::get_api_key();
		$default_num_points = get_option( 'distillpress_default_num_points', 3 );
		$default_reduction = get_option( 'distillpress_default_reduction_percent', 0 );
		$default_max_cats = get_option( 'distillpress_default_max_categories', 3 );

		// Check if API key is configured
		if ( empty( $api_key ) ) {
			?>
			<div class="distillpress-notice distillpress-notice-warning">
				<p>
					<?php
					printf(
						/* translators: %s: link to settings page */
						esc_html__( 'Please configure your POE API key in the %s.', 'distillpress' ),
						'<a href="' . esc_url( admin_url( 'options-general.php?page=distillpress' ) ) . '">' . esc_html__( 'settings', 'distillpress' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
			return;
		}
		?>
		<div class="distillpress-metabox">
			<!-- Summary Section -->
			<div class="distillpress-section">
				<h4><?php esc_html_e( 'Generate Summary & Teaser', 'distillpress' ); ?></h4>
				
				<div class="distillpress-field">
					<label for="distillpress-num-points">
						<?php esc_html_e( 'Number of points:', 'distillpress' ); ?>
					</label>
					<input type="number" 
						   id="distillpress-num-points" 
						   value="<?php echo esc_attr( $default_num_points ); ?>" 
						   min="1" 
						   max="20"
						   class="small-text">
				</div>

				<div class="distillpress-field">
					<label for="distillpress-reduction-percent">
						<?php esc_html_e( 'Max length (% of original):', 'distillpress' ); ?>
					</label>
					<input type="number" 
						   id="distillpress-reduction-percent" 
						   value="<?php echo esc_attr( $default_reduction ); ?>" 
						   min="0" 
						   max="100"
						   class="small-text">
					<span>%</span>
					<p class="description">
						<?php esc_html_e( '0 = no limit', 'distillpress' ); ?>
					</p>
				</div>

				<button type="button" 
						class="button button-primary distillpress-btn" 
						id="distillpress-generate-summary"
						data-post-id="<?php echo esc_attr( $post->ID ); ?>">
					<span class="distillpress-btn-text"><?php esc_html_e( 'Generate Summary & Teaser', 'distillpress' ); ?></span>
					<span class="distillpress-btn-loading" style="display: none;">
						<span class="spinner is-active"></span>
						<?php esc_html_e( 'Generating...', 'distillpress' ); ?>
					</span>
				</button>

				<div id="distillpress-summary-result" class="distillpress-result" style="display: none;">
					<h5><?php esc_html_e( 'Summary', 'distillpress' ); ?></h5>
					<div class="distillpress-result-content distillpress-summary-content"></div>
					<div class="distillpress-result-actions">
						<button type="button" class="button button-small" id="distillpress-copy-summary">
							<?php esc_html_e( 'Copy', 'distillpress' ); ?>
						</button>
					</div>
					<h5><?php esc_html_e( 'Teaser', 'distillpress' ); ?></h5>
					<div class="distillpress-result-content distillpress-teaser-content"></div>
					<div class="distillpress-result-actions">
						<button type="button" class="button button-small" id="distillpress-copy-teaser">
							<?php esc_html_e( 'Copy', 'distillpress' ); ?>
						</button>
					</div>
				</div>
			</div>

			<hr>

			<!-- Category Section -->
			<div class="distillpress-section">
				<h4><?php esc_html_e( 'Auto-Select Categories', 'distillpress' ); ?></h4>

				<div class="distillpress-field">
					<label for="distillpress-max-categories">
						<?php esc_html_e( 'Max categories:', 'distillpress' ); ?>
					</label>
					<input type="number" 
						   id="distillpress-max-categories" 
						   value="<?php echo esc_attr( $default_max_cats ); ?>" 
						   min="1" 
						   max="20"
						   class="small-text">
				</div>

				<button type="button" 
						class="button button-primary distillpress-btn" 
						id="distillpress-auto-categorize"
						data-post-id="<?php echo esc_attr( $post->ID ); ?>">
					<span class="distillpress-btn-text"><?php esc_html_e( 'Auto-Select Categories', 'distillpress' ); ?></span>
					<span class="distillpress-btn-loading" style="display: none;">
						<span class="spinner is-active"></span>
						<?php esc_html_e( 'Finding...', 'distillpress' ); ?>
					</span>
				</button>

				<div id="distillpress-category-result" class="distillpress-result" style="display: none;">
					<div class="distillpress-result-content"></div>
				</div>
			</div>

			<!-- Status/Error Messages -->
			<div id="distillpress-message" class="distillpress-message" style="display: none;"></div>
		</div>
		<?php
	}
}
