<?php
/**
 * Dev-only PHPStan/Homeboy stub for the DataMachine abilities permission helper.
 */

namespace DataMachine\Abilities;

class PermissionHelper {
	public static function run_as_authenticated( callable $callback, int $acting_user_id = 0 ): mixed {}
}
