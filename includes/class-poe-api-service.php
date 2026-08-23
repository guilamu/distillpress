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
class DistillPress_POE_API_Service
{

	/**
	 * POE API base URL.
	 *
	 * @var string
	 */
	private const API_BASE_URL = 'https://api.poe.com';

	/**
	 * Get available models from POE API.
	 *
	 * @param string $api_key     POE API key.
	 * @param bool   $image_only  Only return models supporting image input.
	 * @param bool   $latest_only Only keep the most recent version of each model.
	 * @param bool   $force       Ignore the cached list and query the API again.
	 * @return array|WP_Error Array of models or error.
	 */
	public static function get_models($api_key, $image_only = false, $latest_only = true, $force = false)
	{
		if (empty($api_key)) {
			return new WP_Error('missing_api_key', __('API key is required', 'distillpress'));
		}

		$cache_key = self::get_models_cache_key($api_key, $image_only);

		// The cache always holds the complete list; filtering happens on read.
		$models = $force ? false : get_transient($cache_key);

		if (false === $models) {
			$models = self::fetch_models($api_key, $image_only);

			if (is_wp_error($models)) {
				return $models;
			}

			// Cache for 1 hour
			if (!empty($models)) {
				set_transient($cache_key, $models, HOUR_IN_SECONDS);
			}
		}

		return $latest_only ? self::filter_latest_versions($models) : $models;
	}

	/**
	 * Build the transient key holding the model list for an API key.
	 *
	 * @param string $api_key    POE API key.
	 * @param bool   $image_only Image-capable models only.
	 * @return string Transient key.
	 */
	private static function get_models_cache_key($api_key, $image_only)
	{
		return 'distillpress_models_' . md5($api_key) . ($image_only ? '_img' : '');
	}

	/**
	 * Query the POE API for the list of usable models.
	 *
	 * @param string $api_key    POE API key.
	 * @param bool   $image_only Only return models supporting image input.
	 * @return array|WP_Error Array of models or error.
	 */
	private static function fetch_models($api_key, $image_only)
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
		if (200 !== $status_code) {
			return new WP_Error(
				'api_error',
				/* translators: %d: HTTP status code */
				sprintf(__('API returned status %d', 'distillpress'), $status_code)
			);
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (JSON_ERROR_NONE !== json_last_error()) {
			return new WP_Error('json_error', __('Failed to parse API response', 'distillpress'));
		}

		$models = array();
		foreach ($body['data'] ?? array() as $model) {
			if (empty($model['id']) || !self::supports_chat_completions($model)) {
				continue;
			}

			$input_modalities = $model['architecture']['input_modalities'] ?? array();

			// Filter for image-capable models if requested
			if ($image_only && !in_array('image', $input_modalities, true)) {
				continue;
			}

			$name = $model['metadata']['display_name'] ?? $model['id'];

			$models[] = array(
				'id' => $model['id'],
				'name' => self::add_version_to_name($model['id'], $name),
				'supports_images' => in_array('image', $input_modalities, true),
			);
		}

		return self::sort_models($models);
	}

	/**
	 * Check that a model can be used for the chat completions the plugin sends.
	 *
	 * POE also lists image, video and audio models, which this plugin cannot use.
	 *
	 * @param array $model Raw model entry from the API.
	 * @return bool True if the model accepts chat completions and returns text.
	 */
	private static function supports_chat_completions($model)
	{
		$endpoints = $model['supported_endpoints'] ?? null;

		// Be permissive when the API does not advertise its endpoints.
		if (is_array($endpoints) && !in_array('/v1/chat/completions', $endpoints, true)) {
			return false;
		}

		$output_modalities = $model['architecture']['output_modalities'] ?? null;

		if (is_array($output_modalities) && !in_array('text', $output_modalities, true)) {
			return false;
		}

		return true;
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
			$left = $a['numbers'][$i] ?? 0;
			$right = $b['numbers'][$i] ?? 0;

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
	 * Send a chat completion request (text only).
	 *
	 * @param string $api_key     POE API key.
	 * @param string $model       Model ID.
	 * @param string $prompt      User message/prompt.
	 * @param float  $temperature Temperature (0.0-1.0).
	 * @param int    $max_tokens  Maximum tokens in response.
	 * @return string|WP_Error Response content or error.
	 */
	public static function chat_completion($api_key, $model, $prompt, $temperature = 0.7, $max_tokens = 1000)
	{
		if (empty($api_key)) {
			return new WP_Error('missing_api_key', __('API key is required', 'distillpress'));
		}

		$payload = array(
			'model' => $model,
			'messages' => array(
				array(
					'role' => 'user',
					'content' => $prompt,
				),
			),
			'temperature' => $temperature,
			'max_tokens' => $max_tokens,
		);

		$response = wp_remote_post(
			self::API_BASE_URL . '/v1/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode($payload),
				'timeout' => 60,
				'sslverify' => true,
			)
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code($response);
		if (200 !== $status_code) {
			$error_body = wp_remote_retrieve_body($response);
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('DistillPress POE API Error: ' . $error_body);
			}
			return new WP_Error(
				'api_error',
				/* translators: %d: HTTP status code */
				sprintf(__('API returned status %d', 'distillpress'), $status_code)
			);
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		// Log the API request usage
		$usage = isset($body['usage']) ? $body['usage'] : null;
		self::log_request($api_key, 'chat_completion', $model, $usage);

		if (isset($body['choices'][0]['message']['content'])) {
			return $body['choices'][0]['message']['content'];
		}

		return new WP_Error('invalid_response', __('Invalid API response', 'distillpress'));
	}

	/**
	 * Send a chat completion with system prompt for better control.
	 *
	 * @param string $api_key       POE API key.
	 * @param string $model         Model ID.
	 * @param string $system_prompt System instructions.
	 * @param string $user_prompt   User message.
	 * @param float  $temperature   Temperature (0.0-1.0).
	 * @param int    $max_tokens    Maximum tokens in response.
	 * @return string|WP_Error Response content or error.
	 */
	public static function chat_with_system($api_key, $model, $system_prompt, $user_prompt, $temperature = 0.7, $max_tokens = 1000)
	{
		if (empty($api_key)) {
			return new WP_Error('missing_api_key', __('API key is required', 'distillpress'));
		}

		$payload = array(
			'model' => $model,
			'messages' => array(
				array(
					'role' => 'system',
					'content' => $system_prompt,
				),
				array(
					'role' => 'user',
					'content' => $user_prompt,
				),
			),
			'temperature' => $temperature,
			'max_tokens' => $max_tokens,
		);

		$response = wp_remote_post(
			self::API_BASE_URL . '/v1/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode($payload),
				'timeout' => 60,
				'sslverify' => true,
			)
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code($response);
		if (200 !== $status_code) {
			$error_body = wp_remote_retrieve_body($response);
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('DistillPress POE API Error: ' . $error_body);
			}
			return new WP_Error(
				'api_error',
				/* translators: %d: HTTP status code */
				sprintf(__('API returned status %d', 'distillpress'), $status_code)
			);
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		// Log the API request usage
		$usage = isset($body['usage']) ? $body['usage'] : null;
		self::log_request($api_key, 'chat_with_system', $model, $usage);

		if (isset($body['choices'][0]['message']['content'])) {
			return $body['choices'][0]['message']['content'];
		}

		return new WP_Error('invalid_response', __('Invalid API response', 'distillpress'));
	}

	/**
	 * Parse JSON from AI response (handles markdown code blocks).
	 *
	 * @param string $text The AI response text.
	 * @return array|null Parsed JSON or null.
	 */
	public static function extract_json_from_response($text)
	{
		// Try to find markdown JSON code block
		if (preg_match('/```json\s*(.*?)\s*```/s', $text, $matches)) {
			$json = json_decode($matches[1], true);
			if (null !== $json) {
				return $json;
			}
		}

		// Try to find any code block
		if (preg_match('/```\s*(.*?)\s*```/s', $text, $matches)) {
			$json = json_decode($matches[1], true);
			if (null !== $json) {
				return $json;
			}
		}

		// Try to find JSON array in text
		if (preg_match('/\[.*\]/s', $text, $matches)) {
			$json = json_decode($matches[0], true);
			if (null !== $json) {
				return $json;
			}
		}

		// Try to find JSON object in text
		if (preg_match('/\{.*\}/s', $text, $matches)) {
			$json = json_decode($matches[0], true);
			if (null !== $json) {
				return $json;
			}
		}

		// Fallback: try raw text
		return json_decode($text, true);
	}

	/**
	 * Clear model cache (e.g., when API key changes).
	 *
	 * @param string $api_key API key.
	 */
	public static function clear_models_cache($api_key)
	{
		delete_transient('distillpress_models_' . md5($api_key));
		delete_transient('distillpress_models_' . md5($api_key) . '_img');
	}

	/**
	 * Log an API request for tracking usage.
	 *
	 * @param string     $api_key     POE API key (needed for points lookup).
	 * @param string     $action_type Type of action (chat_completion, chat_with_system).
	 * @param string     $model       Model ID used.
	 * @param array|null $usage       Usage data from API response.
	 */
	public static function log_request($api_key, $action_type, $model, $usage = null)
	{
		$log = get_option('distillpress_api_log', array());

		// Fetch actual points cost from POE API
		$cost_points = self::fetch_last_query_cost($api_key);

		// Build log entry
		$entry = array(
			'timestamp' => current_time('mysql'),
			'action_type' => $action_type,
			'model' => $model,
			'cost_points' => $cost_points,
			'prompt_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
			'completion_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
			'total_tokens' => isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
		);

		// Add to log (prepend for newest first)
		array_unshift($log, $entry);

		// Keep only last 10 entries
		$log = array_slice($log, 0, 10);

		update_option('distillpress_api_log', $log);
	}

	/**
	 * Fetch the cost points from the last API call via Poe usage history.
	 *
	 * @param string $api_key POE API key.
	 * @return int|null The cost in points, or null if unavailable.
	 */
	private static function fetch_last_query_cost($api_key)
	{
		if (empty($api_key)) {
			return null;
		}

		$response = wp_remote_get(
			'https://api.poe.com/usage/points_history?limit=1',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => 5,
				'sslverify' => true,
			)
		);

		if (is_wp_error($response)) {
			return null;
		}

		$status_code = wp_remote_retrieve_response_code($response);
		if (200 !== $status_code) {
			return null;
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (!empty($body['data'][0]['cost_points'])) {
			return (int) $body['data'][0]['cost_points'];
		}

		return null;
	}

	/**
	 * Get the API request log.
	 *
	 * @return array Array of log entries.
	 */
	public static function get_request_log()
	{
		return get_option('distillpress_api_log', array());
	}
}
