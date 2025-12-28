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
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

/**
 * Clean up plugin data.
 */
function distillpress_uninstall()
{
	// Delete plugin options
	delete_option('distillpress_api_key');
	delete_option('distillpress_model');
	delete_option('distillpress_default_num_points');
	delete_option('distillpress_default_reduction_percent');
	delete_option('distillpress_default_max_categories');
	delete_option('distillpress_default_category');
	delete_option('distillpress_enable_summary');
	delete_option('distillpress_enable_teaser');
	delete_option('distillpress_custom_prompt');

	// Delete transients
	delete_transient('distillpress_github_release');

	// Delete model cache transients (we need to find them by pattern)
	global $wpdb;
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'%_transient_distillpress_models_%',
			'%_transient_timeout_distillpress_models_%'
		)
	);

	// Delete all post meta
	$wpdb->delete($wpdb->postmeta, array('meta_key' => '_distillpress_summary'));
	$wpdb->delete($wpdb->postmeta, array('meta_key' => '_distillpress_teaser'));

	// For multisite, clean up each site
	if (is_multisite()) {
		$sites = get_sites(array('fields' => 'ids'));

		foreach ($sites as $site_id) {
			switch_to_blog($site_id);

			delete_option('distillpress_api_key');
			delete_option('distillpress_model');
			delete_option('distillpress_default_num_points');
			delete_option('distillpress_default_reduction_percent');
			delete_option('distillpress_default_max_categories');
			delete_option('distillpress_default_category');
			delete_option('distillpress_enable_summary');
			delete_option('distillpress_enable_teaser');
			delete_option('distillpress_custom_prompt');

			delete_transient('distillpress_github_release');

			// Delete all post meta for this site
			$wpdb->delete($wpdb->postmeta, array('meta_key' => '_distillpress_summary'));
			$wpdb->delete($wpdb->postmeta, array('meta_key' => '_distillpress_teaser'));

			restore_current_blog();
		}
	}
}

distillpress_uninstall();
