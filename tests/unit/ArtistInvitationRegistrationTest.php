<?php
/**
 * Artist invitation registration tests.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify invitation failures stop before user creation when possible.
 */
class Test_Artist_Invitation_Registration extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_EXTRACHILL_CLIENT'] );
		parent::tearDown();
	}

	public function test_incomplete_invitation_is_rejected_before_token_registration_creates_user(): void {
		$_SERVER['HTTP_EXTRACHILL_CLIENT'] = 'app';
		$email                             = 'failed-invite@example.com';

		$result = extrachill_users_register_with_tokens(
			array(
				'email'            => $email,
				'password'         => 'secure-password',
				'password_confirm' => 'secure-password',
				'device_id'        => '123e4567-e89b-42d3-a456-426614174000',
				'invite_token'     => 'token-without-artist',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_artist_invitation', $result->get_error_code() );
		$this->assertFalse( email_exists( $email ) );
	}

	public function test_incomplete_browser_invitation_contract_is_rejected(): void {
		$result = ec_users_validate_registration_artist_invitation( 'new@example.com', 'token', 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_artist_invitation', $result->get_error_code() );
	}
}
