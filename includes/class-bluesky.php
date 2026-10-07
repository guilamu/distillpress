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
	 * Browser-like user agent: some servers reject requests without one.
	 */
	const USER_AGENT = 'Mozilla/5.0 (compatible; DistillPress; +https://github.com/guilamu/distillpress)';

	/**
	 * Get the Bluesky handle from wp-config.php or the settings.
	 *
	 * @return string
	 */
	public static function get_handle()
	{
		$handle = defined('DISTILLPRESS_BSKY_HANDLE') ? DISTILLPRESS_BSKY_HANDLE : get_option('distillpress_bsky_handle', '');
		// Accept a pasted profile URL (https://bsky.app/profile/name) as well as @name.
		$handle = preg_replace('#^https?://bsky\.app/profile/#i', '', trim((string) $handle));
		return ltrim(untrailingslashit($handle), '@');
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
	 * @return array|WP_Error Array with "url" (Bluesky post) and "warning" (image problem, or empty).
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

		// Card image, skipped (with a warning) when missing or too large.
		$warning = '';
		$thumb = self::get_thumbnail($post_id);
		if (is_wp_error($thumb)) {
			$warning = $thumb->get_error_message();
		} else {
			$blob = self::request($service, 'com.atproto.repo.uploadBlob', $thumb['bytes'], $token, $thumb['mime']);
			if (is_wp_error($blob) || !isset($blob['blob'])) {
				$warning = is_wp_error($blob) ? $blob->get_error_message() : __('Image upload failed.', 'distillpress');
			} else {
				$external['thumb'] = $blob['blob'];
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
		return array(
			'url'     => 'https://bsky.app/profile/' . rawurlencode($session['handle']) . '/post/' . rawurlencode($rkey),
			'warning' => $warning,
		);
	}

	/**
	 * Card image: the article og:image (as other sites see it), else the featured image.
	 *
	 * @param int $post_id Article ID.
	 * @return array|WP_Error Array with "bytes" and "mime".
	 */
	private static function get_thumbnail($post_id)
	{
		$url = apply_filters('distillpress_bluesky_thumb_url', self::find_og_image_url($post_id), $post_id);
		if ($url) {
			$image = self::load_image_url($url);
			if ($image) {
				return $image;
			}
		}

		$file = self::get_featured_image_file($post_id);
		if (is_wp_error($file)) {
			return $url ? new WP_Error('distillpress_bluesky_thumb', __('The featured image could not be used (file missing or larger than 1 MB): the card was posted without an image.', 'distillpress')) : $file;
		}

		return array(
			'bytes' => file_get_contents($file['path']), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			'mime'  => $file['mime'],
		);
	}

	/**
	 * Read the og:image URL from the published article page.
	 *
	 * @param int $post_id Article ID.
	 * @return string Empty string when not found.
	 */
	private static function find_og_image_url($post_id)
	{
		$response = wp_remote_get(get_permalink($post_id), array('timeout' => 15, 'user-agent' => self::USER_AGENT));
		$html = wp_remote_retrieve_body($response);

		if ('' !== $html && preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
			return html_entity_decode($m[1]);
		}

		return '';
	}

	/**
	 * Load an image from the uploads folder on disk, or else over HTTP.
	 *
	 * @param string $url Image URL.
	 * @return array|null Array with "bytes" and "mime", null when unusable.
	 */
	private static function load_image_url($url)
	{
		$uploads = wp_get_upload_dir();
		$base = preg_replace('#^https?://(www\.)?#i', '', $uploads['baseurl']);
		$relative = preg_replace('#^https?://(www\.)?#i', '', strtok($url, '?'));

		if (0 === strpos($relative, $base . '/')) {
			$path = $uploads['basedir'] . substr($relative, strlen($base));
			if (is_readable($path) && filesize($path) <= self::MAX_THUMB_BYTES) {
				$type = wp_check_filetype($path);
				if ($type['type'] && 0 === strpos($type['type'], 'image/')) {
					return array('bytes' => file_get_contents($path), 'mime' => $type['type']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				}
			}
		}

		$response = wp_remote_get($url, array('timeout' => 15, 'user-agent' => self::USER_AGENT));
		$bytes = wp_remote_retrieve_body($response);
		$mime = (string) wp_remote_retrieve_header($response, 'content-type');
		if ('' !== $bytes && strlen($bytes) <= self::MAX_THUMB_BYTES && 0 === strpos($mime, 'image/')) {
			return array('bytes' => $bytes, 'mime' => strtok($mime, ';'));
		}

		return null;
	}

	/**
	 * Featured image file small enough for Bluesky, trying the larger sizes first.
	 *
	 * @param int $post_id Article ID.
	 * @return array|WP_Error Array with "path" and "mime".
	 */
	private static function get_featured_image_file($post_id)
	{
		$thumb_id = get_post_thumbnail_id($post_id);
		$original = $thumb_id ? get_attached_file($thumb_id) : '';
		if (!$original) {
			return new WP_Error('distillpress_bluesky_thumb', __('The article has no image (og:image or featured image): the card was posted without an image.', 'distillpress'));
		}

		$candidates = array();
		foreach (array('large', 'medium_large', 'medium') as $size) {
			$meta = image_get_intermediate_size($thumb_id, $size);
			if ($meta && !empty($meta['file'])) {
				$candidates[] = array(path_join(dirname($original), basename($meta['file'])), isset($meta['mime-type']) ? $meta['mime-type'] : '');
			}
		}
		$candidates[] = array($original, get_post_mime_type($thumb_id));

		foreach ($candidates as $candidate) {
			list($path, $mime) = $candidate;
			if (is_readable($path) && filesize($path) <= self::MAX_THUMB_BYTES) {
				if (!$mime) {
					$type = wp_check_filetype($path);
					$mime = $type['type'];
				}
				if ($mime && 0 === strpos($mime, 'image/')) {
					return array('path' => $path, 'mime' => $mime);
				}
			}
		}

		return new WP_Error('distillpress_bluesky_thumb', __('The featured image could not be used (file missing or larger than 1 MB): the card was posted without an image.', 'distillpress'));
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
