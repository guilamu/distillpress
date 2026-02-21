<?php
/**
 * Gemini API Service Class
 *
 * Handles all communication with the Google Gemini API
 * via the OpenAI-compatible endpoint.
 *
 * @package DistillPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class DistillPress_Gemini_API_Service
 *
 * Provides methods to interact with the Google Gemini API.
 */
class DistillPress_Gemini_API_Service
{

	/**
	 * Gemini API base URL (OpenAI-compatible).
	 *
	 * @var string
	 */
	private const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/openai';

	/**
	 * Available Gemini models.
	 *
	 * @var array
	 */
	private const MODELS = array(
		array(
			'id'   => 'gemini-flash-latest',
			'name' => 'Gemini Flash (fast, cheap)',
		),
		array(
			'id'   => 'gemini-pro-latest',
			'name' => 'Gemini Pro (most capable)',
		),
	);

	/**
	 * Get available Gemini models.
	 *
	 * @return array Array of models.
	 */
	public static function get_models()
	{
		return self::MODELS;
	}

	/**
	 * Send a chat completion request (text only).
	 *
	 * @param string $api_key     Gemini API key.
	 * @param string $model       Model ID.
	 * @param string $prompt      User message/prompt.
	 * @param float  $temperature Temperature (0.0-1.0).
	 * @param int    $max_tokens  Maximum tokens in response.
	 * @return string|WP_Error Response content or error.
	 */
	public static function chat_completion($api_key, $model, $prompt, $temperature = 0.7, $max_tokens = 1000)
	{
		if (empty($api_key)) {
			return new WP_Error('missing_api_key', __('Gemini API key is required', 'distillpress'));
		}

		$payload = array(
			'model'       => $model,
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature' => $temperature,
			'max_tokens'  => $max_tokens,
		);

		$response = wp_remote_post(
			self::API_BASE_URL . '/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'      => wp_json_encode($payload),
				'timeout'   => 60,
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
				error_log('DistillPress Gemini API Error: ' . $error_body);
			}
			return new WP_Error(
				'api_error',
				/* translators: %d: HTTP status code */
				sprintf(__('Gemini API returned status %d', 'distillpress'), $status_code)
			);
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		// Log the API request usage
		$usage = isset($body['usage']) ? $body['usage'] : null;
		self::log_request('chat_completion', $model, $usage);

		if (isset($body['choices'][0]['message']['content'])) {
			return $body['choices'][0]['message']['content'];
		}

		return new WP_Error('invalid_response', __('Invalid Gemini API response', 'distillpress'));
	}

	/**
	 * Send a chat completion with system prompt.
	 *
	 * @param string $api_key       Gemini API key.
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
			return new WP_Error('missing_api_key', __('Gemini API key is required', 'distillpress'));
		}

		$payload = array(
			'model'       => $model,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
			'temperature' => $temperature,
			'max_tokens'  => $max_tokens,
		);

		$response = wp_remote_post(
			self::API_BASE_URL . '/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'      => wp_json_encode($payload),
				'timeout'   => 60,
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
				error_log('DistillPress Gemini API Error: ' . $error_body);
			}
			return new WP_Error(
				'api_error',
				/* translators: %d: HTTP status code */
				sprintf(__('Gemini API returned status %d', 'distillpress'), $status_code)
			);
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		// Log the API request usage
		$usage = isset($body['usage']) ? $body['usage'] : null;
		self::log_request('chat_with_system', $model, $usage);

		if (isset($body['choices'][0]['message']['content'])) {
			return $body['choices'][0]['message']['content'];
		}

		return new WP_Error('invalid_response', __('Invalid Gemini API response', 'distillpress'));
	}

	/**
	 * Parse JSON from AI response (handles markdown code blocks).
	 *
	 * Reuses the same logic as the POE service.
	 *
	 * @param string $text The AI response text.
	 * @return array|null Parsed JSON or null.
	 */
	public static function extract_json_from_response($text)
	{
		return DistillPress_POE_API_Service::extract_json_from_response($text);
	}

	/**
	 * Log an API request for tracking usage.
	 *
	 * @param string     $action_type Type of action (chat_completion, chat_with_system).
	 * @param string     $model       Model ID used.
	 * @param array|null $usage       Usage data from API response.
	 */
	public static function log_request($action_type, $model, $usage = null)
	{
		$log = get_option('distillpress_api_log', array());

		$entry = array(
			'timestamp'         => current_time('mysql'),
			'action_type'       => $action_type,
			'model'             => $model,
			'cost_points'       => null, // Gemini does not use POE points
			'prompt_tokens'     => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
			'completion_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
			'total_tokens'      => isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
		);

		array_unshift($log, $entry);
		$log = array_slice($log, 0, 10);

		update_option('distillpress_api_log', $log);
	}
}
