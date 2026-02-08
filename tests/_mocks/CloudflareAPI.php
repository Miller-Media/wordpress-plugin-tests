<?php
/**
 * Cloudflare API Mock
 *
 * Intercepts HTTP requests to api.cloudflare.com using WordPress's
 * pre_http_request filter. Returns JSON fixture data instead of
 * making real API calls.
 */
class CloudflareAPIMock {

	/**
	 * Whether the mock is active.
	 *
	 * @var bool
	 */
	private static $active = false;

	/**
	 * Log of intercepted requests for assertions.
	 *
	 * @var array
	 */
	private static $request_log = array();

	/**
	 * Custom response overrides keyed by URL pattern.
	 *
	 * @var array
	 */
	private static $custom_responses = array();

	/**
	 * Activate the mock by hooking into pre_http_request.
	 */
	public static function activate() {
		if ( ! self::$active ) {
			add_filter( 'pre_http_request', array( __CLASS__, 'intercept' ), 10, 3 );
			self::$active = true;
		}
	}

	/**
	 * Deactivate the mock.
	 */
	public static function deactivate() {
		remove_filter( 'pre_http_request', array( __CLASS__, 'intercept' ), 10 );
		self::$active = false;
	}

	/**
	 * Reset state between tests.
	 */
	public static function reset() {
		self::$request_log     = array();
		self::$custom_responses = array();
	}

	/**
	 * Set a custom response for a URL pattern.
	 *
	 * @param string $url_pattern  Regex pattern to match against URL.
	 * @param mixed  $response     Response body (array or object).
	 * @param int    $status_code  HTTP status code.
	 */
	public static function set_response( $url_pattern, $response, $status_code = 200 ) {
		self::$custom_responses[ $url_pattern ] = array(
			'body'        => $response,
			'status_code' => $status_code,
		);
	}

	/**
	 * Get the log of intercepted requests.
	 *
	 * @return array
	 */
	public static function get_request_log() {
		return self::$request_log;
	}

	/**
	 * Get the last intercepted request.
	 *
	 * @return array|null
	 */
	public static function get_last_request() {
		return ! empty( self::$request_log ) ? end( self::$request_log ) : null;
	}

	/**
	 * Get the count of intercepted requests.
	 *
	 * @return int
	 */
	public static function get_request_count() {
		return count( self::$request_log );
	}

	/**
	 * Intercept HTTP requests to Cloudflare API.
	 *
	 * @param false|array $preempt  Whether to preempt the request.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Request URL.
	 * @return false|array
	 */
	public static function intercept( $preempt, $args, $url ) {
		// Only intercept Cloudflare API calls
		if ( strpos( $url, 'api.cloudflare.com' ) === false ) {
			return $preempt;
		}

		// Log the request
		self::$request_log[] = array(
			'url'    => $url,
			'method' => isset( $args['method'] ) ? $args['method'] : 'GET',
			'body'   => isset( $args['body'] ) ? $args['body'] : null,
			'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
		);

		// Check for custom responses first
		foreach ( self::$custom_responses as $pattern => $response_data ) {
			if ( preg_match( $pattern, $url ) ) {
				return self::build_response( $response_data['body'], $response_data['status_code'] );
			}
		}

		// Default fixture-based responses
		$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';

		// GET /zones
		if ( preg_match( '#/zones(\?|$)#', $url ) && 'GET' === $method ) {
			$fixture = self::load_fixture( 'cloudflare-zones.json' );
			return self::build_response( $fixture );
		}

		// POST /zones/{id}/firewall/access_rules/rules (blacklist IP)
		if ( preg_match( '#/zones/[^/]+/firewall/access_rules/rules$#', $url ) && 'POST' === $method ) {
			$fixture = self::load_fixture( 'cloudflare-blacklist-ip.json' );
			return self::build_response( $fixture );
		}

		// DELETE /zones/{id}/firewall/access_rules/rules/{rule_id}
		if ( preg_match( '#/zones/[^/]+/firewall/access_rules/rules/[^/]+$#', $url ) && 'DELETE' === $method ) {
			$fixture = self::load_fixture( 'cloudflare-delete-rule.json' );
			return self::build_response( $fixture );
		}

		// DELETE /zones/{id}/purge_cache (purge by files or purge everything)
		if ( preg_match( '#/zones/[^/]+/purge_cache#', $url ) && 'DELETE' === $method ) {
			$fixture = self::load_fixture( 'cloudflare-purge-cache.json' );
			return self::build_response( $fixture );
		}

		// Fallback: return a generic success response
		return self::build_response( array(
			'success'  => true,
			'errors'   => array(),
			'messages' => array(),
			'result'   => null,
		) );
	}

	/**
	 * Load a JSON fixture file.
	 *
	 * @param string $filename Fixture filename.
	 * @return string JSON string.
	 */
	private static function load_fixture( $filename ) {
		$path = __DIR__ . '/fixtures/' . $filename;
		if ( file_exists( $path ) ) {
			return file_get_contents( $path );
		}
		return json_encode( array(
			'success' => true,
			'errors'  => array(),
			'result'  => null,
		) );
	}

	/**
	 * Build a mock HTTP response array.
	 *
	 * @param mixed $body        Response body (string or array).
	 * @param int   $status_code HTTP status code.
	 * @return array
	 */
	private static function build_response( $body, $status_code = 200 ) {
		if ( is_array( $body ) || is_object( $body ) ) {
			$body = json_encode( $body );
		}
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $status_code,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
