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
		remove_all_filters( 'pre_http_request' );
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
		$result = ec_users_request_artist_invitation( 'new@example.com', 'token', 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_artist_invitation', $result->get_error_code() );
	}

	public function test_invitation_request_uses_artist_site_http_ability(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->assertStringContainsString( '/wp-json/wp-abilities/v1/abilities/extrachill/artist-invitation/run', $url );
				$this->assertStringContainsString( '"artist_id":20', $args['body'] );
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'status'    => 'valid',
							'artist_id' => 20,
						)
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		$result = ec_users_request_artist_invitation( 'new@example.com', 'secret', 20 );
		$this->assertSame(
			array(
				'status'    => 'valid',
				'artist_id' => 20,
			),
			$result
		);
		$this->assertFalse( has_filter( 'ec_cross_site_use_http_loopback' ) );
	}

	public function test_token_registration_returns_account_with_pending_repair_status(): void {
		$_SERVER['HTTP_EXTRACHILL_CLIENT'] = 'app';
		$request_count                     = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$request_count ) {
				++$request_count;
				if ( 1 === $request_count ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'status'    => 'valid',
								'artist_id' => 20,
							)
						),
						'cookies'  => array(),
					);
				}
				return array(
					'response' => array(
						'code'    => 503,
						'message' => 'Busy',
					),
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'code'    => 'artist_membership_busy',
							'message' => 'Retry membership.',
						)
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		$email  = 'pending-repair@example.com';
		$result = extrachill_users_register_with_tokens(
			array(
				'email'            => $email,
				'password'         => 'secure-password',
				'password_confirm' => 'secure-password',
				'device_id'        => '123e4567-e89b-42d3-a456-426614174000',
				'invite_token'     => 'secret-token',
				'invite_artist_id' => 20,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'pending_repair', $result['artist_invitation_status'] );
		$this->assertNotFalse( email_exists( $email ) );
		$this->assertSame( 2, $request_count );
	}

	public function test_browser_registration_logs_in_created_account_with_pending_repair_redirect(): void {
		$user_id  = self::factory()->user->create( array( 'user_email' => 'browser-repair@example.com' ) );
		$redirect = new class( 'https://example.org/register' ) extends EC_Redirect_Handler {
			/**
			 * Captured redirect URL.
			 *
			 * @var string
			 */
			public string $captured_url = '';

			public function redirect_to( string $url ): void {
				$this->captured_url = $url;
			}
		};

		extrachill_auto_login_new_user( $user_id, $redirect, null, '', true );

		$this->assertSame( $user_id, get_current_user_id() );
		$this->assertStringContainsString( 'artist_invitation=pending_repair', $redirect->captured_url );
	}
}
