<?php
/**
 * Base test case for integration tests that use live APIs.
 *
 * Automatically skips tests when required API credentials are not
 * available in the environment. Set credentials in tests/.env to
 * enable integration tests.
 */
class IntegrationTestCase extends WP_UnitTestCase {

	/**
	 * Required environment variables for this test case.
	 * Override in subclasses.
	 *
	 * @var array
	 */
	protected $required_env_vars = array();

	/**
	 * Set up test fixtures.
	 *
	 * Checks for required environment variables and skips the test
	 * if any are missing.
	 */
	public function setUp(): void {
		parent::setUp();

		foreach ( $this->required_env_vars as $var ) {
			$value = getenv( $var );
			if ( empty( $value ) || strpos( $value, 'your_' ) === 0 ) {
				$this->markTestSkipped(
					"Integration test skipped: {$var} not set. Copy tests/.env.example to tests/.env and add credentials."
				);
			}
		}
	}

	/**
	 * Get an environment variable value.
	 *
	 * @param string $name Variable name.
	 * @return string|false
	 */
	protected function env( $name ) {
		return getenv( $name );
	}
}
