<?php
/**
 * Base API Service Class
 *
 * Shared logic for the OpenAI-compatible providers supported by the plugin
 * (POE and Google Gemini): request handling, response parsing and usage log.
 *
 * @package DistillPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class DistillPress_API_Service
 *
 * Providers only have to declare their endpoint and their label; everything
 * else is shared.
 */
abstract class DistillPress_API_Service
{

	/**
	 * Option holding the request log.
	 *
	 * @var string
	 */
	protected const LOG_OPTION = 'distillpress_api_log';

	/**
	 * Number of entries kept in the request log.
	 *
	 * @var int
	 */
	protected const LOG_SIZE = 10;

	/**
	 * Chat completions endpoint of the provider.
	 *
	 * @return string Absolute URL.
	 */
	abstract protected static function get_endpoint();

	/**
	 * Human readable provider name, used in error messages and logs.
	 *
	 * @return string Provider label.
	 */
	abstract protected static function get_label();

	/**
	 * Send a chat completion with a system prompt.
	 *
	 * @param string $api_key       Provider API key.
	 * @param string $model         Model ID.
	 * @param string $system_prompt System instructions.
	 * @param string $user_prompt   User message.
	 * @param float  $temperature   Temperature (0.0-1.0).
	 * @param int    $max_tokens    Maximum tokens in response.
	 * @param string $action        Action name recorded in the request log.
	 * @return string|WP_Error Response content or error.
	 */
	public static function chat_with_system($api_key, $model, $system_prompt, $user_prompt, $temperature = 0.7, $max_tokens = 1000, $action = 'chat')
	{
		if (empty($api_key)) {
			return new WP_Error(
				'missing_api_key',
				/* translators: %s: provider name */
				sprintf(__('%s API key is required', 'distillpress'), static::get_label())
			);
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

		$payload = array_merge($payload, static::get_extra_payload($api_key, $model));

		return static::send_chat_request($api_key, $model, $payload, $action);
	}

	/**
	 * Provider specific payload entries, merged into every chat request.
	 *
	 * @param string $api_key Provider API key.
	 * @param string $model   Model ID.
	 * @return array Extra payload entries.
	 */
	protected static function get_extra_payload($api_key, $model)
	{
		return array();
	}

	/**
	 * Perform the chat completion request and return the message content.
	 *
	 * @param string $api_key Provider API key.
	 * @param string $model   Model ID.
	 * @param array  $payload Request payload.
	 * @param string $action  Action name recorded in the request log.
	 * @return string|WP_Error Response content or error.
	 */
	protected static function send_chat_request($api_key, $model, array $payload, $action)
	{
		$response = wp_remote_post(
			static::get_endpoint(),
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
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (200 !== $status_code) {
			return static::build_api_error($status_code, $body, wp_remote_retrieve_body($response));
		}

		static::log_request($api_key, $action, $model, isset($body['usage']) ? $body['usage'] : null);

		if (isset($body['choices'][0]['message']['content'])) {
			return $body['choices'][0]['message']['content'];
		}

		return new WP_Error(
			'invalid_response',
			/* translators: %s: provider name */
			sprintf(__('Invalid %s API response', 'distillpress'), static::get_label())
		);
	}

	/**
	 * Turn a failed API response into a WP_Error carrying the provider message.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param mixed  $body        Decoded response body.
	 * @param string $raw_body    Raw response body, used for debugging.
	 * @return WP_Error Error describing the failure.
	 */
	protected static function build_api_error($status_code, $body, $raw_body)
	{
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log(sprintf('DistillPress %s API Error: %s', static::get_label(), $raw_body));
		}

		// Providers return the useful part in error.message; show it to the user.
		$detail = '';
		if (isset($body['error']['message'])) {
			$detail = (string) $body['error']['message'];
		} elseif (isset($body['error']) && is_string($body['error'])) {
			$detail = $body['error'];
		}

		if ('' !== $detail) {
			return new WP_Error(
				'api_error',
				sprintf(
					/* translators: 1: provider name, 2: HTTP status code, 3: error message returned by the API */
					__('%1$s API returned status %2$d: %3$s', 'distillpress'),
					static::get_label(),
					$status_code,
					$detail
				)
			);
		}

		return new WP_Error(
			'api_error',
			sprintf(
				/* translators: 1: provider name, 2: HTTP status code */
				__('%1$s API returned status %2$d', 'distillpress'),
				static::get_label(),
				$status_code
			)
		);
	}

	/**
	 * Parse JSON from an AI response (handles markdown code blocks).
	 *
	 * @param string $text The AI response text.
	 * @return array|null Parsed JSON or null.
	 */
	public static function extract_json_from_response($text)
	{
		$candidates = array();

		// Markdown code block, with or without a json language hint.
		if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $matches)) {
			$candidates[] = $matches[1];
		}

		// Bare JSON array or object embedded in prose.
		if (preg_match('/\[.*\]/s', $text, $matches)) {
			$candidates[] = $matches[0];
		}

		if (preg_match('/\{.*\}/s', $text, $matches)) {
			$candidates[] = $matches[0];
		}

		$candidates[] = $text;

		foreach ($candidates as $candidate) {
			$decoded = json_decode($candidate, true);
			if (null !== $decoded) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Cost in provider credits of the request that just completed.
	 *
	 * @param string $api_key Provider API key.
	 * @return int|null Cost, or null when the provider does not report one.
	 */
	protected static function get_last_request_cost($api_key)
	{
		return null;
	}

	/**
	 * Log an API request for usage tracking.
	 *
	 * @param string     $api_key Provider API key.
	 * @param string     $action  Action name (summary, categories, ...).
	 * @param string     $model   Model ID used.
	 * @param array|null $usage   Usage data from the API response.
	 */
	protected static function log_request($api_key, $action, $model, $usage = null)
	{
		$log = get_option(self::LOG_OPTION, array());

		if (!is_array($log)) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'timestamp' => current_time('mysql'),
				'provider' => static::get_label(),
				'action_type' => $action,
				'model' => $model,
				'cost_points' => static::get_last_request_cost($api_key),
				'prompt_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
				'completion_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
				'total_tokens' => isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
			)
		);

		// The log is only read on the settings page: keep it out of the autoloaded options.
		update_option(self::LOG_OPTION, array_slice($log, 0, self::LOG_SIZE), false);
	}

	/**
	 * Get the API request log.
	 *
	 * @return array Array of log entries, newest first.
	 */
	public static function get_request_log()
	{
		$log = get_option(self::LOG_OPTION, array());

		return is_array($log) ? $log : array();
	}
}
