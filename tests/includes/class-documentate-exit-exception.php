<?php
/**
 * Exception used to stand in for the exit() that ends a redirecting request.
 *
 * Admin-post handlers finish with wp_safe_redirect() followed by exit(). Tests
 * hook the `wp_redirect` filter and throw this exception from it, so the
 * redirect target can be asserted without terminating the PHPUnit process.
 *
 * @package Documentate
 */

/**
 * Thrown instead of exit() when a redirect is intercepted.
 */
class Documentate_Exit_Exception extends Exception {

	/**
	 * The redirect target that would have been sent.
	 *
	 * @var string
	 */
	private $location;

	/**
	 * Build the exception around a redirect location.
	 *
	 * @param string $location Redirect target.
	 */
	public function __construct( $location = '' ) {
		parent::__construct( 'Request ended with a redirect to: ' . $location );
		$this->location = (string) $location;
	}

	/**
	 * Redirect target captured from the `wp_redirect` filter.
	 *
	 * @return string
	 */
	public function get_location() {
		return $this->location;
	}
}
