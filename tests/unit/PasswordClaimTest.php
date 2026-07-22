<?php
/**
 * Unit tests for claiming accounts through password reset completion.
 */

class Test_Password_Claim extends WP_UnitTestCase {

	private function create_user( bool $unclaimed = true ): WP_User {
		$user_id = self::factory()->user->create();

		if ( $unclaimed ) {
			update_user_meta( $user_id, 'ec_unclaimed', 1 );
		}

		return get_user_by( 'ID', $user_id );
	}

	public function test_successful_password_claim_clears_unclaimed_marker(): void {
		$user = $this->create_user();
		$key  = get_password_reset_key( $user );

		$this->assertNotWPError( $key );

		$verified_user = check_password_reset_key( $key, $user->user_login );
		$this->assertNotWPError( $verified_user );

		reset_password( $verified_user, 'new-secure-password' );

		$this->assertSame( '', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
		$this->assertTrue( wp_check_password( 'new-secure-password', get_userdata( $user->ID )->user_pass, $user->ID ) );
	}

	public function test_invalid_or_expired_key_does_not_clear_unclaimed_marker(): void {
		$user = $this->create_user();
		$key  = get_password_reset_key( $user );

		$this->assertNotWPError( $key );
		wp_set_password( 'replacement-password', $user->ID );

		$this->assertWPError( check_password_reset_key( $key, $user->user_login ) );
		$this->assertSame( '1', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
	}

	public function test_failure_before_password_reset_does_not_clear_unclaimed_marker(): void {
		$user = $this->create_user();

		do_action( 'password_reset', $user, 'new-secure-password' );

		$this->assertSame( '1', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
	}

	public function test_repeated_completion_hook_is_harmless(): void {
		$user = $this->create_user();

		do_action( 'after_password_reset', $user, 'new-secure-password' );
		do_action( 'after_password_reset', $user, 'new-secure-password' );

		$this->assertSame( '', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
	}

	public function test_password_reset_for_already_claimed_account_is_harmless(): void {
		$user = $this->create_user( false );

		reset_password( $user, 'new-secure-password' );

		$this->assertSame( '', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
		$this->assertTrue( wp_check_password( 'new-secure-password', get_userdata( $user->ID )->user_pass, $user->ID ) );
	}
}
