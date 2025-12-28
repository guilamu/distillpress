<?php
/**
 * DistillPress Uninstall
 *
 * Fired when the plugin is uninstalled.
 * Cleans up all plugin data from the database.
 *
 * @package DistillPress
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean up plugin data.
 */
function distillpress_uninstall() {
	// Delete plugin options
	delete_option( 'distillpress_api_key' );
	delete_option( 'distillpress_model' );
	delete_option( 'distillpress_default_num_points' );
	delete_option( 'distillpress_default_reduction_percent' );
	delete_option( 'distillpress_default_max_categories' );
	delete_option( 'distillpress_default_category' );

	// Delete transients
	delete_transient( 'distillpress_github_release' );

	// Delete model cache transients (we need to find them by pattern)
	global $wpdb;
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'%_transient_distillpress_models_%',
			'%_transient_timeout_distillpress_models_%'
		)
	);

	// For multisite, clean up each site
	if ( is_multisite() ) {
		$sites = get_sites( array( 'fields' => 'ids' ) );

		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );

			delete_option( 'distillpress_api_key' );
			delete_option( 'distillpress_model' );
			delete_option( 'distillpress_default_num_points' );
			delete_option( 'distillpress_default_reduction_percent' );
			delete_option( 'distillpress_default_max_categories' );
			delete_option( 'distillpress_default_category' );

			delete_transient( 'distillpress_github_release' );

			restore_current_blog();
		}
	}
}

distillpress_uninstall();
