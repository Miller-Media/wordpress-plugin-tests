<?php
/**
 * Mandrill API Mock
 *
 * Intercepts HTTP requests to mandrillapp.com using WordPress's
 * pre_http_request filter. Returns JSON fixture data instead of
 * making real API calls.
 */
class MandrillAPIMock {

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
	 * Whether to simulate an error response.
	 *
	 * @var bool
	 */
	private static $simulate_error = false;

	/**
	 * Activate the mock.
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
		self::$request_log      = array();
		self::$custom_responses = array();
		self::$simulate_error   = false;
	}

	/**
	 * Set a custom response for a URL pattern.
	 *
	 * @param string $url_pattern  Regex pattern to match against URL.
	 * @param mixed  $response     Response body.
	 * @param int    $status_code  HTTP status code.
	 */
	public static function set_response( $url_pattern, $response, $status_code = 200 ) {
		self::$custom_responses[ $url_pattern ] = array(
			'body'        => $response,
			'status_code' => $status_code,
		);
	}

	/**
	 * Enable error simulation for the next request.
	 */
	public static function simulate_error() {
		self::$simulate_error = true;
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
	 * Intercept HTTP requests to Mandrill API.
	 *
	 * @param false|array $preempt  Whether to preempt the request.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Request URL.
	 * @return false|array
	 */
	public static function intercept( $preempt, $args, $url ) {
		if ( strpos( $url, 'mandrillapp.com' ) === false ) {
			return $preempt;
		}

		// Log the request
		self::$request_log[] = array(
			'url'    => $url,
			'method' => isset( $args['method'] ) ? $args['method'] : 'POST',
			'body'   => isset( $args['body'] ) ? $args['body'] : null,
		);

		// Check for custom responses first
		foreach ( self::$custom_responses as $pattern => $response_data ) {
			if ( preg_match( $pattern, $url ) ) {
				return self::build_response( $response_data['body'], $response_data['status_code'] );
			}
		}

		// Simulate error if requested
		if ( self::$simulate_error ) {
			self::$simulate_error = false;
			$fixture = self::load_fixture( 'mandrill-error.json' );
			return self::build_response( $fixture, 500 );
		}

		// users/ping2
		if ( strpos( $url, 'users/ping2' ) !== false ) {
			$fixture = self::load_fixture( 'mandrill-ping.json' );
			return self::build_response( $fixture );
		}

		// messages/send or messages/send-template
		if ( strpos( $url, 'messages/send' ) !== false ) {
			$fixture = self::load_fixture( 'mandrill-send.json' );
			return self::build_response( $fixture );
		}

		// Fallback: generic success
		return self::build_response( json_encode( array( 'status' => 'ok' ) ) );
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
		return json_encode( array( 'status' => 'ok' ) );
	}

	/**
	 * Build a mock HTTP response array.
	 *
	 * @param mixed $body        Response body.
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
				'message' => ( 200 === $status_code ) ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
