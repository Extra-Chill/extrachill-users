<?php
/**
 * Minimal Two_Factor_Core test double for login continuation tests.
 *
 * Loaded conditionally (class_exists guard) from the consuming test so the
 * real Two Factor plugin class wins when present. Extracted from its test
 * file so each file carries a single object structure
 * (Generic.Files.OneObjectStructurePerFile).
 */

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- signature-compatible test double; the real method signatures require these parameters.

class Two_Factor_Core {
	public static function is_user_using_two_factor( $user_id ) {
		return true;
	}

	public static function create_login_nonce( $user_id ) {
		return array( 'key' => 'test-2fa-nonce' );
	}
}
