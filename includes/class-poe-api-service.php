<?php
/**
 * POE API Service Class
 *
 * Handles all communication with the POE API.
 *
 * @package DistillPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class DistillPress_POE_API_Service
 *
 * Provides methods to interact with the POE API.
 */
class DistillPress_POE_API_Service extends DistillPress_API_Service
{

	/**
	 * POE API base URL.
	 *
	 * @var string
	 */
	private const API_BASE_URL = 'https://api.poe.com';

	/**
	 * Model list cache lifetime.
	 *
	 * @var int
	 */
	private const MODELS_CACHE_TTL = 3600;

	/**
	 * Reasoning parameters understood by the plugin, by order of preference.
	 *
	 * POE advertises, per model, which one it accepts; sending another one is
	 * rejected with an HTTP 400, so only the advertised one may be used.
	 *
	 * @var array
	 */
	private const REASONING_PARAMETERS = array('reasoning_effort', 'output_effort', 'thinking_level', 'enable_thinking');

	/**
	 * Accepted values for each effort level, by order of preference.
	 *
	 * Models expose different enums; the first supported value wins.
	 *
	 * @var array
	 */
	private const REASONING_VALUES = array(
		'none' => array('none', 'minimal', 'low'),
		'low' => array('low', 'minimal', 'medium'),
		'medium' => array('medium', 'low', 'high'),
		'high' => array('high', 'xhigh', 'medium'),
		'max' => array('max', 'xhigh', 'high'),
	);

	/**
	 * Chat completions endpoint.
	 *
	 * @return string Absolute URL.
	 */
	protected static function get_endpoint()
	{
		return self::API_BASE_URL . '/v1/chat/completions';
	}

	/**
	 * Provider label.
	 *
	 * @return string Provider name.
	 */
	protected static function get_label()
	{
		return 'POE';
	}

	/**
	 * Get available models from POE API.
	 *
	 * @param string $api_key     POE API key.
	 * @param bool   $latest_only Only keep the most recent version of each model.
	 * @param bool   $force       Ignore the cached list and query the API again.
	 * @return array|WP_Error Array of models or error.
	 */
	public static function get_models($api_key, $latest_only = true, $force = false)
	{
		if (empty($api_key)) {
			return new WP_Error('missing_api_key', __('API key is required', 'distillpress'));
		}

		$cache_key = self::get_models_cache_key($api_key);

		// The cache always holds the complete list; filtering happens on read.
		$models = $force ? false : get_transient($cache_key);

		if (!is_array($models)) {
			$models = self::fetch_models($api_key);

			if (is_wp_error($models)) {
				return $models;
			}

			if (!empty($models)) {
				set_transient($cache_key, $models, self::MODELS_CACHE_TTL);
			}
		}

		return $latest_only ? self::filter_latest_versions($models) : $models;
	}

	/**
	 * Build the transient key holding the model list for an API key.
	 *
	 * @param string $api_key POE API key.
	 * @return string Transient key.
	 */
	private static function get_models_cache_key($api_key)
	{
		// The suffix is bumped whenever the cached structure changes.
		return 'distillpress_models_v2_' . md5($api_key);
	}

	/**
	 * Query the POE API for the list of usable models.
	 *
	 * @param string $api_key POE API key.
	 * @return array|WP_Error Array of models or error.
	 */
	private static function fetch_models($api_key)
	{
		$response = wp_remote_get(
			self::API_BASE_URL . '/v1/models',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => 30,
				'sslverify' => true,
			)
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (200 !== $status_code) {
			return self::build_api_error($status_code, $body, wp_remote_retrieve_body($response));
		}

		if (JSON_ERROR_NONE !== json_last_error()) {
			return new WP_Error('json_error', __('Failed to parse API response', 'distillpress'));
		}

		$models = array();
		foreach (isset($body['data']) && is_array($body['data']) ? $body['data'] : array() as $model) {
			if (empty($model['id']) || !self::is_text_model($model)) {
				continue;
			}

			$name = isset($model['metadata']['display_name']) ? $model['metadata']['display_name'] : $model['id'];

			$models[] = array(
				'id' => $model['id'],
				'name' => self::add_version_to_name($model['id'], $name),
				'reasoning' => self::extract_reasoning_support($model),
			);
		}

		return self::sort_models($models);
	}

	/**
	 * Check that a model can be used for the chat completions the plugin sends.
	 *
	 * POE also lists image, video and audio bots, which this plugin cannot use.
	 *
	 * @param array $model Raw model entry from the API.
	 * @return bool True when the model generates text from a chat completion.
	 */
	private static function is_text_model($model)
	{
		$output_modalities = isset($model['architecture']['output_modalities']) ? $model['architecture']['output_modalities'] : null;

		if (is_array($output_modalities) && !empty($output_modalities) && !in_array('text', $output_modalities, true)) {
			return false;
		}

		$endpoints = isset($model['supported_endpoints']) ? $model['supported_endpoints'] : null;

		if (is_array($endpoints) && !empty($endpoints)) {
			return in_array('/v1/chat/completions', $endpoints, true);
		}

		// POE leaves the endpoint list empty for most models. Per-token pricing
		// is then the reliable marker of a language model: image, video and
		// utility bots are billed per request, or not priced at all.
		$pricing = isset($model['pricing']) ? $model['pricing'] : array();

		return !empty($pricing['prompt']) && !empty($pricing['completion']);
	}

	/**
	 * Read which reasoning parameter a model accepts, if any.
	 *
	 * @param array $model Raw model entry from the API.
	 * @return array|null Parameter name and accepted values, or null.
	 */
	private static function extract_reasoning_support($model)
	{
		$parameters = isset($model['parameters']) && is_array($model['parameters']) ? $model['parameters'] : array();
		$available = array();

		foreach ($parameters as $parameter) {
			if (isset($parameter['name'])) {
				$available[$parameter['name']] = isset($parameter['schema']) ? $parameter['schema'] : array();
			}
		}

		foreach (self::REASONING_PARAMETERS as $name) {
			if (!isset($available[$name])) {
				continue;
			}

			$schema = $available[$name];

			return array(
				'parameter' => $name,
				'values' => isset($schema['enum']) && is_array($schema['enum']) ? $schema['enum'] : array(),
			);
		}

		return null;
	}

	/**
	 * Build the reasoning entry to add to a chat request payload.
	 *
	 * @param string $api_key  POE API key.
	 * @param string $model_id Model ID.
	 * @return array Payload fragment, empty when the model has no such control.
	 */
	public static function get_reasoning_payload($api_key, $model_id)
	{
		$level = DistillPress::get_reasoning_effort();

		if ('' === $level || empty($api_key) || empty($model_id)) {
			return array();
		}

		$support = self::get_reasoning_support($api_key, $model_id);

		if (null === $support) {
			return array();
		}

		// A boolean control cannot express a level: it can only be turned off.
		if (empty($support['values'])) {
			return array($support['parameter'] => 'none' !== $level);
		}

		$preferences = isset(self::REASONING_VALUES[$level]) ? self::REASONING_VALUES[$level] : array();

		foreach ($preferences as $value) {
			if (in_array($value, $support['values'], true)) {
				return array($support['parameter'] => $value);
			}
		}

		return array();
	}

	/**
	 * Look up the reasoning control advertised for a model.
	 *
	 * @param string $api_key  POE API key.
	 * @param string $model_id Model ID.
	 * @return array|null Parameter name and accepted values, or null.
	 */
	private static function get_reasoning_support($api_key, $model_id)
	{
		// The whole list is searched: the selected model may be an older version
		// that the filtered list no longer offers.
		$models = self::get_models($api_key, false);

		if (is_wp_error($models)) {
			return null;
		}

		foreach ($models as $model) {
			if ($model['id'] === $model_id) {
				return isset($model['reasoning']) ? $model['reasoning'] : null;
			}
		}

		return null;
	}

	/**
	 * Add the reasoning parameter to every chat request.
	 *
	 * @param string $api_key POE API key.
	 * @param string $model   Model ID.
	 * @return array Extra payload entries.
	 */
	protected static function get_extra_payload($api_key, $model)
	{
		return self::get_reasoning_payload($api_key, $model);
	}

	/**
	 * Make sure the version number stays visible in the model label.
	 *
	 * Only the latest release of each model is listed, so the label has to say
	 * which one it is: "Claude-Sonnet-4.6", not "Claude-Sonnet". POE display
	 * names usually already include it; this appends it when they do not.
	 *
	 * @param string $id   Model ID.
	 * @param string $name Display name advertised by the API.
	 * @return string Display name including the version.
	 */
	private static function add_version_to_name($id, $name)
	{
		$parsed = self::parse_model_id($id);

		if (null === $parsed) {
			return $name;
		}

		$version = $parsed['version']['token'];
		$normalized = preg_replace('/[^a-z0-9.]/', '', strtolower($name));

		if (false !== strpos($normalized, $version)) {
			return $name;
		}

		return $name . '-' . $version;
	}

	/**
	 * Keep only the most recent version of each model family.
	 *
	 * POE exposes every past release (Claude-Opus-4.5 through 4.8, GPT-4o
	 * through GPT-5.4, ...). Only the newest of each family is worth offering.
	 *
	 * @param array $models Models as returned by fetch_models().
	 * @return array Filtered models.
	 */
	public static function filter_latest_versions($models)
	{
		if (!is_array($models)) {
			return $models;
		}

		$unversioned = array();
		$families = array();

		foreach ($models as $model) {
			$parsed = self::parse_model_id($model['id']);

			// No version number in the ID: nothing to compare it against.
			if (null === $parsed) {
				$unversioned[] = $model;
				continue;
			}

			$family = $parsed['family'];

			if (!isset($families[$family]) || self::compare_versions($parsed['version'], $families[$family]['version']) > 0) {
				$families[$family] = array(
					'version' => $parsed['version'],
					'model' => $model,
				);
			}
		}

		$filtered = $unversioned;
		foreach ($families as $family) {
			$filtered[] = $family['model'];
		}

		return self::sort_models($filtered);
	}

	/**
	 * Split a model ID into a family key and a comparable version.
	 *
	 * "claude-opus-4.8" becomes family "claude|opus" with version 4.8, so it can
	 * be compared with "claude-opus-4.5". Qualifiers such as "mini", "pro" or a
	 * parameter count ("27b") stay part of the family and are never merged.
	 *
	 * @param string $id Model ID.
	 * @return array|null Family key and version, or null when the ID has no version.
	 */
	private static function parse_model_id($id)
	{
		$tokens = preg_split('/[^a-z0-9.]+/', strtolower($id), -1, PREG_SPLIT_NO_EMPTY);
		$version = null;
		$family = array();

		foreach ($tokens as $token) {
			if (null === $version && preg_match('/^([a-z]*?)v?(\d+(?:\.\d+)*)([a-z]?)$/', $token, $matches)) {
				// "70b", "128k" and "1m" are sizes or context windows, not versions.
				if (!in_array($matches[3], array('b', 'k', 'm'), true)) {
					$version = array(
						'token' => $token,
						'numbers' => array_map('intval', explode('.', $matches[2])),
						'suffix' => $matches[3],
					);

					// Keep a leading letter ("o3", "k2") as part of the family.
					if ('' !== $matches[1]) {
						$family[] = $matches[1];
					}

					continue;
				}
			}

			$family[] = $token;
		}

		if (null === $version || empty($family)) {
			return null;
		}

		sort($family);

		return array(
			'family' => implode('|', $family),
			'version' => $version,
		);
	}

	/**
	 * Compare two versions returned by parse_model_id().
	 *
	 * @param array $a First version.
	 * @param array $b Second version.
	 * @return int Negative if $a is older, positive if newer, 0 if equal.
	 */
	private static function compare_versions($a, $b)
	{
		$length = max(count($a['numbers']), count($b['numbers']));

		for ($i = 0; $i < $length; $i++) {
			$left = isset($a['numbers'][$i]) ? $a['numbers'][$i] : 0;
			$right = isset($b['numbers'][$i]) ? $b['numbers'][$i] : 0;

			if ($left !== $right) {
				return $left < $right ? -1 : 1;
			}
		}

		// "gpt-4o" is newer than "gpt-4".
		return strcmp($a['suffix'], $b['suffix']);
	}

	/**
	 * Sort models alphabetically by display name.
	 *
	 * @param array $models Models to sort.
	 * @return array Sorted models.
	 */
	private static function sort_models($models)
	{
		usort(
			$models,
			function ($a, $b) {
				return strcasecmp($a['name'], $b['name']);
			}
		);

		return $models;
	}

	/**
	 * Fetch the cost in points of the last API call from the Poe usage history.
	 *
	 * @param string $api_key POE API key.
	 * @return int|null The cost in points, or null if unavailable.
	 */
	protected static function get_last_request_cost($api_key)
	{
		if (empty($api_key)) {
			return null;
		}

		$response = wp_remote_get(
			self::API_BASE_URL . '/usage/points_history?limit=1',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => 5,
				'sslverify' => true,
			)
		);

		if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
			return null;
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (!empty($body['data'][0]['cost_points'])) {
			return (int) $body['data'][0]['cost_points'];
		}

		return null;
	}
}
