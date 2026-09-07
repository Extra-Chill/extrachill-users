<?php
/**
 * Dev-only PHPStan/Homeboy stub for the DataMachine OAuth provider base class.
 */

namespace DataMachine\Core\OAuth;

abstract class BaseAuthProvider {
	public function __construct( string $provider_slug ) {}

	/** @return array<string,mixed> */
	protected function get_config(): array {}
}
