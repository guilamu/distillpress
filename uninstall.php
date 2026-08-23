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
 * Options created by the plugin.
 *
 * @return array Option names.
 */
function distillpress_uninstall_options()
{
	return array(
		'distillpress_api_provider',
		'distillpress_api_key',
		'distillpress_gemini_api_key',
		'distillpress_gemini_model',
		'distillpress_model',
		'distillpress_reasoning_effort',
		'distillpress_default_num_points',
		'distillpress_default_reduction_percent',
		'distillpress_default_max_categories',
		'distillpress_default_category',
		'distillpress_enable_summary',
		'distillpress_enable_teaser',
		'distillpress_custom_prompt',
		'distillpress_api_log',
	);
}

/**
 * Remove every trace of the plugin from the current site.
 */
function distillpress_uninstall_site()
{
	global $wpdb;

	foreach (distillpress_uninstall_options() as $option) {
		delete_option($option);
	}

	delete_transient('distillpress_github_release');

	// Model list transients are keyed by a hash of the API key.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like('_transient_distillpress_models_') . '%',
			$wpdb->esc_like('_transient_timeout_distillpress_models_') . '%'
		)
	);

	delete_post_meta_by_key('_distillpress_summary');
	delete_post_meta_by_key('_distillpress_teaser');
}

/**
 * Clean up plugin data, on every site of the network when relevant.
 */
function distillpress_uninstall()
{
	if (!is_multisite()) {
		distillpress_uninstall_site();
		return;
	}

	foreach (get_sites(array('fields' => 'ids')) as $site_id) {
		switch_to_blog($site_id);
		distillpress_uninstall_site();
		restore_current_blog();
	}
}

distillpress_uninstall();
