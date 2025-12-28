<?php
/**
 * POE API Service Class
 *
 * Handles all communication with the POE API.
 *
 * @package DistillPress
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DistillPress_POE_API_Service
 *
 * Provides methods to interact with the POE API.
 */
class DistillPress_POE_API_Service {

	/**
	 * POE API base URL.
	 *
	 * @var string
	 */
	private const API_BASE_URL = 'https://api.poe.com';

	/**
	 * Get available models from POE API.
	 *
	 * @param string $api_key    POE API key.
	 * @param bool   $image_only Only return models supporting image input.
	 * @return array|WP_Error Array of models or error.
	 */
	public static function get_models( $api_key, $image_only = false ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_api_key', __( 'API key is required', 'distillpress' ) );
		}

		// Check cache first
		$cache_key = 'distillpress_models_' . md5( $api_key ) . ( $image_only ? '_img' : '' );
		$cached_models = get_transient( $cache_key );
		if ( false !== $cached_models ) {
			return $cached_models;
		}

		$response = wp_remote_get(
			self::API_BASE_URL . '/v1/models',
			array(
				'headers'   => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'api_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'API returned status %d', 'distillpress' ), $status_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'json_error', __( 'Failed to parse API response', 'distillpress' ) );
		}

		$models = array();
		foreach ( $body['data'] ?? array() as $model ) {
			$input_modalities = $model['architecture']['input_modalities'] ?? array();

			// Filter for image-capable models if requested
			if ( $image_only && ! in_array( 'image', $input_modalities, true ) ) {
				continue;
			}

			$models[] = array(
				'id'              => $model['id'],
				'name'            => $model['metadata']['display_name'] ?? $model['id'],
				'supports_images' => in_array( 'image', $input_modalities, true ),
			);
		}

		// Sort alphabetically by name
		usort(
			$models,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		// Cache for 1 hour
		if ( ! empty( $models ) ) {
			set_transient( $cache_key, $models, HOUR_IN_SECONDS );
		}

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
	public static function chat_completion( $api_key, $model, $prompt, $temperature = 0.7, $max_tokens = 1000 ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_api_key', __( 'API key is required', 'distillpress' ) );
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
			self::API_BASE_URL . '/v1/chat/completions',
			array(
				'headers'   => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'      => wp_json_encode( $payload ),
				'timeout'   => 60,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$error_body = wp_remote_retrieve_body( $response );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DistillPress POE API Error: ' . $error_body );
			}
			return new WP_Error(
				'api_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'API returned status %d', 'distillpress' ), $status_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['choices'][0]['message']['content'] ) ) {
			return $body['choices'][0]['message']['content'];
		}

		return new WP_Error( 'invalid_response', __( 'Invalid API response', 'distillpress' ) );
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
	public static function chat_with_system( $api_key, $model, $system_prompt, $user_prompt, $temperature = 0.7, $max_tokens = 1000 ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_api_key', __( 'API key is required', 'distillpress' ) );
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
			self::API_BASE_URL . '/v1/chat/completions',
			array(
				'headers'   => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'      => wp_json_encode( $payload ),
				'timeout'   => 60,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$error_body = wp_remote_retrieve_body( $response );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DistillPress POE API Error: ' . $error_body );
			}
			return new WP_Error(
				'api_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'API returned status %d', 'distillpress' ), $status_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['choices'][0]['message']['content'] ) ) {
			return $body['choices'][0]['message']['content'];
		}

		return new WP_Error( 'invalid_response', __( 'Invalid API response', 'distillpress' ) );
	}

	/**
	 * Parse JSON from AI response (handles markdown code blocks).
	 *
	 * @param string $text The AI response text.
	 * @return array|null Parsed JSON or null.
	 */
	public static function extract_json_from_response( $text ) {
		// Try to find markdown JSON code block
		if ( preg_match( '/```json\s*(.*?)\s*```/s', $text, $matches ) ) {
			$json = json_decode( $matches[1], true );
			if ( null !== $json ) {
				return $json;
			}
		}

		// Try to find any code block
		if ( preg_match( '/```\s*(.*?)\s*```/s', $text, $matches ) ) {
			$json = json_decode( $matches[1], true );
			if ( null !== $json ) {
				return $json;
			}
		}

		// Try to find JSON array in text
		if ( preg_match( '/\[.*\]/s', $text, $matches ) ) {
			$json = json_decode( $matches[0], true );
			if ( null !== $json ) {
				return $json;
			}
		}

		// Try to find JSON object in text
		if ( preg_match( '/\{.*\}/s', $text, $matches ) ) {
			$json = json_decode( $matches[0], true );
			if ( null !== $json ) {
				return $json;
			}
		}

		// Fallback: try raw text
		return json_decode( $text, true );
	}

	/**
	 * Clear model cache (e.g., when API key changes).
	 *
	 * @param string $api_key API key.
	 */
	public static function clear_models_cache( $api_key ) {
		delete_transient( 'distillpress_models_' . md5( $api_key ) );
		delete_transient( 'distillpress_models_' . md5( $api_key ) . '_img' );
	}
}
