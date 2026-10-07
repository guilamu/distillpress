<?php
/**
 * Bluesky publishing (AT Protocol).
 *
 * @package DistillPress
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Posts a teaser to Bluesky with a link card to the article.
 */
class DistillPress_Bluesky
{
	/**
	 * Default PDS used to open the session.
	 */
	const SERVICE = 'https://bsky.social';

	/**
	 * Maximum thumbnail size accepted by Bluesky (bytes).
	 */
	const MAX_THUMB_BYTES = 976560;

	/**
	 * Get the Bluesky handle from wp-config.php or the settings.
	 *
	 * @return string
	 */
	public static function get_handle()
	{
		$handle = defined('DISTILLPRESS_BSKY_HANDLE') ? DISTILLPRESS_BSKY_HANDLE : get_option('distillpress_bsky_handle', '');
		return ltrim(trim((string) $handle), '@');
	}

	/**
	 * Get the Bluesky app password from wp-config.php or the settings.
	 *
	 * @return string
	 */
	public static function get_app_password()
	{
		return trim((string) (defined('DISTILLPRESS_BSKY_APP_PASSWORD') ? DISTILLPRESS_BSKY_APP_PASSWORD : get_option('distillpress_bsky_app_password', '')));
	}

	/**
	 * Whether a handle and an app password are set.
	 *
	 * @return bool
	 */
	public static function is_configured()
	{
		return '' !== self::get_handle() && '' !== self::get_app_password();
	}

	/**
	 * Post a text with a link card to the given article.
	 *
	 * @param int    $post_id Article ID (must be published).
	 * @param string $text    Post text (300 characters maximum).
	 * @return string|WP_Error URL of the Bluesky post.
	 */
	public static function post($post_id, $text)
	{
		$session = self::request(
			self::SERVICE,
			'com.atproto.server.createSession',
			wp_json_encode(
				array(
					'identifier' => self::get_handle(),
					'password'   => self::get_app_password(),
				)
			)
		);
		if (is_wp_error($session)) {
			return $session;
		}

		// Accounts may live on another PDS: use the one given by the session.
		$service = self::SERVICE;
		if (!empty($session['didDoc']['service'])) {
			foreach ($session['didDoc']['service'] as $entry) {
				if (isset($entry['id'], $entry['serviceEndpoint']) && '#atproto_pds' === $entry['id']) {
					$service = untrailingslashit($entry['serviceEndpoint']);
				}
			}
		}
		$token = $session['accessJwt'];

		$external = array(
			'uri'         => get_permalink($post_id),
			'title'       => wp_strip_all_tags(get_the_title($post_id)),
			'description' => $text,
		);

		// Thumbnail: featured image, skipped when missing or too large.
		$thumb_id = get_post_thumbnail_id($post_id);
		if ($thumb_id) {
			$src = wp_get_attachment_image_src($thumb_id, 'large');
			if ($src) {
				$image = wp_remote_get($src[0], array('timeout' => 15));
				$bytes = wp_remote_retrieve_body($image);
				$mime = wp_remote_retrieve_header($image, 'content-type');
				if (!is_wp_error($image) && '' !== $bytes && strlen($bytes) <= self::MAX_THUMB_BYTES && 0 === strpos((string) $mime, 'image/')) {
					$blob = self::request($service, 'com.atproto.repo.uploadBlob', $bytes, $token, $mime);
					if (!is_wp_error($blob) && isset($blob['blob'])) {
						$external['thumb'] = $blob['blob'];
					}
				}
			}
		}

		$record = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
			'langs'     => array(substr(get_locale(), 0, 2)),
			'embed'     => array(
				'$type'    => 'app.bsky.embed.external',
				'external' => $external,
			),
		);

		$created = self::request(
			$service,
			'com.atproto.repo.createRecord',
			wp_json_encode(
				array(
					'repo'       => $session['did'],
					'collection' => 'app.bsky.feed.post',
					'record'     => $record,
				)
			),
			$token
		);
		if (is_wp_error($created)) {
			return $created;
		}

		// at://did/app.bsky.feed.post/rkey -> https://bsky.app/profile/handle/post/rkey
		$rkey = substr(strrchr((string) $created['uri'], '/'), 1);
		return 'https://bsky.app/profile/' . rawurlencode($session['handle']) . '/post/' . rawurlencode($rkey);
	}

	/**
	 * Call an XRPC procedure.
	 *
	 * @param string      $service      PDS URL.
	 * @param string      $method       XRPC method.
	 * @param string      $body         Request body.
	 * @param string|null $token        Access token.
	 * @param string      $content_type Body content type.
	 * @return array|WP_Error Decoded response.
	 */
	private static function request($service, $method, $body, $token = null, $content_type = 'application/json')
	{
		$headers = array('Content-Type' => $content_type);
		if ($token) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_post(
			$service . '/xrpc/' . $method,
			array(
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 20,
			)
		);
		if (is_wp_error($response)) {
			return $response;
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);
		$code = wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300 || !is_array($data)) {
			$message = is_array($data) && !empty($data['message']) ? $data['message'] : 'HTTP ' . $code;
			/* translators: %s: error message returned by Bluesky */
			return new WP_Error('distillpress_bluesky', sprintf(__('Bluesky error: %s', 'distillpress'), $message));
		}

		return $data;
	}
}
