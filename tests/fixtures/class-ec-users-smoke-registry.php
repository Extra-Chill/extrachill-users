<?php
/**
 * Registry fixture for the dependency-free lifecycle smoke.
 *
 * @package ExtraChill\Users
 */

/**
 * Minimal initialized registry fixture.
 */
class EC_Users_Smoke_Registry {

	/**
	 * Shared fixture instance.
	 *
	 * @var self|null
	 */
	private static $instance;

	/**
	 * Get the initialized fixture instance.
	 *
	 * @return self Registry fixture.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}
