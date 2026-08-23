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
class DistillPress_Gemini_API_Service extends DistillPress_API_Service
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
			'id' => 'gemini-flash-latest',
			'name' => 'Gemini Flash (fast, cheap)',
		),
		array(
			'id' => 'gemini-pro-latest',
			'name' => 'Gemini Pro (most capable)',
		),
	);

	/**
	 * Effort levels mapped to the values the Gemini endpoint accepts.
	 *
	 * The OpenAI-compatible layer takes "none", "low", "medium" and "high", and
	 * maps them to each model's own thinking configuration.
	 *
	 * @var array
	 */
	private const REASONING_VALUES = array(
		'none' => 'none',
		'low' => 'low',
		'medium' => 'medium',
		'high' => 'high',
		'max' => 'high',
	);

	/**
	 * Chat completions endpoint.
	 *
	 * @return string Absolute URL.
	 */
	protected static function get_endpoint()
	{
		return self::API_BASE_URL . '/chat/completions';
	}

	/**
	 * Provider label.
	 *
	 * @return string Provider name.
	 */
	protected static function get_label()
	{
		return 'Gemini';
	}

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
	 * Add the reasoning effort to every chat request.
	 *
	 * @param string $api_key Gemini API key.
	 * @param string $model   Model ID.
	 * @return array Extra payload entries.
	 */
	protected static function get_extra_payload($api_key, $model)
	{
		$level = DistillPress::get_reasoning_effort();

		if ('' === $level || !isset(self::REASONING_VALUES[$level])) {
			return array();
		}

		return array('reasoning_effort' => self::REASONING_VALUES[$level]);
	}
}
