<?php
/**
 * End-to-end contract tests for login continuation handling (#206, #208).
 */

class Test_Login_Continuation extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/inc/oauth/google-canonical-origin.php';
	}

	public function test_same_site_redirect_is_preserved(): void {
		$_GET['redirect_to'] = 'https://extrachill.com/my-shows/';

		$this->assertSame(
			'https://extrachill.com/my-shows/',
			ec_users_get_validated_login_redirect_from_request()
		);
	}

	public function test_cross_subdomain_redirect_is_preserved(): void {
		$_GET['redirect_to'] = 'https://events.extrachill.com/my-shows/';

		$this->assertSame(
			'https://events.extrachill.com/my-shows/',
			ec_users_get_validated_login_redirect_from_request()
		);
	}

	public function test_encoded_destination_query_round_trips_without_double_decoding(): void {
		$destination         = 'https://events.extrachill.com/my-shows/?artist=Tyler%2C%20the%20Creator&source=local%26scene';
		$_GET['redirect_to'] = $destination;

		$this->assertSame( $destination, ec_users_get_validated_login_redirect_from_request() );

		$login_url = ec_users_login_url_with_redirect( 'https://community.extrachill.com/login/', $destination );
		parse_str( (string) wp_parse_url( $login_url, PHP_URL_QUERY ), $query );
		$this->assertSame( $destination, $query['redirect_to'] );
	}

	public function test_google_continuation_preserves_encoded_destination_query(): void {
		$destination = 'https://events.extrachill.com/my-shows/?artist=Tyler%2C%20the%20Creator&source=local%26scene';
		$login_url   = ec_users_canonical_google_signin_url( $destination );
		parse_str( (string) wp_parse_url( $login_url, PHP_URL_QUERY ), $query );

		$_GET[ EC_USERS_GOOGLE_REDIRECT_PARAM ] = $query[ EC_USERS_GOOGLE_REDIRECT_PARAM ] ?? '';
		$this->assertSame( $destination, ec_users_get_validated_google_redirect_from_request() );
	}

	public function test_unsafe_external_redirect_is_rejected(): void {
		$_GET['redirect_to'] = 'https://attacker.example/phish';

		$this->assertNull( ec_users_get_validated_login_redirect_from_request() );
		$this->assertSame(
			'https://community.extrachill.com/login/',
			ec_users_login_url_with_redirect(
				'https://community.extrachill.com/login/',
				'https://attacker.example/phish'
			)
		);
	}

	public function test_protocol_relative_redirect_is_rejected(): void {
		$_GET['redirect_to'] = '//attacker.example/phish';

		$this->assertNull( ec_users_get_validated_login_redirect_from_request() );
	}

	public function test_relative_redirect_is_rejected(): void {
		$_GET['redirect_to'] = '/my-shows/';

		$this->assertNull( ec_users_get_validated_login_redirect_from_request() );
	}

	public function test_missing_continuation_returns_null(): void {
		unset( $_GET['redirect_to'] );

		$this->assertNull( ec_users_get_validated_login_redirect_from_request() );
	}

	public function test_valid_request_continuation_takes_precedence_over_fixed_block_redirect(): void {
		$fixed_redirect         = 'https://community.extrachill.com/';
		$request_redirect       = 'https://community.extrachill.com/?compose=discussion&entity_taxonomy=artist&entity_slug=kid-lake';
		$_GET['redirect_to']    = $request_redirect;
		$_SERVER['HTTP_HOST']   = 'community.extrachill.com';
		$_SERVER['REQUEST_URI'] = '/login/?redirect_to=' . rawurlencode( $request_redirect );

		$config = $this->render_login_block_config( $fixed_redirect );

		$this->assertSame( $request_redirect, $config['loginRedirectUrl'] );
		$this->assertSame( $request_redirect, $config['successRedirectUrl'] );
	}

	public function test_unsafe_request_continuation_falls_back_to_fixed_block_redirect(): void {
		$fixed_redirect         = 'https://community.extrachill.com/';
		$_GET['redirect_to']    = 'https://attacker.example/phish';
		$_SERVER['HTTP_HOST']   = 'community.extrachill.com';
		$_SERVER['REQUEST_URI'] = '/login/?redirect_to=https%3A%2F%2Fattacker.example%2Fphish';

		$config = $this->render_login_block_config( $fixed_redirect );

		$this->assertSame( $fixed_redirect, $config['loginRedirectUrl'] );
		$this->assertSame( $fixed_redirect, $config['successRedirectUrl'] );
	}

	public function test_fixed_block_redirect_remains_the_default_without_request_continuation(): void {
		$fixed_redirect         = 'https://community.extrachill.com/';
		$_SERVER['HTTP_HOST']   = 'community.extrachill.com';
		$_SERVER['REQUEST_URI'] = '/login/';

		$config = $this->render_login_block_config( $fixed_redirect );

		$this->assertSame( $fixed_redirect, $config['loginRedirectUrl'] );
		$this->assertSame( $fixed_redirect, $config['successRedirectUrl'] );
	}

	public function test_network_custom_domain_is_allowed_by_existing_policy(): void {
		$this->assertTrue( ec_users_is_valid_return_to_url( 'https://extrachill.link/chubes/' ) );
	}

	public function test_attendance_cta_round_trips_through_wp_login_and_custom_login(): void {
		$attendance_url = 'https://events.extrachill.com/shows/khruangbin/?attendance=going&source=my-shows%2Fupcoming';
		$wp_login_url   = wp_login_url( $attendance_url );

		parse_str( (string) wp_parse_url( $wp_login_url, PHP_URL_QUERY ), $wp_login_query );
		$custom_login_url = ec_users_login_url_with_redirect(
			'https://community.extrachill.com/login/',
			$wp_login_query['redirect_to'] ?? ''
		);

		parse_str( (string) wp_parse_url( $custom_login_url, PHP_URL_QUERY ), $custom_login_query );
		$this->assertSame( $attendance_url, $custom_login_query['redirect_to'] ?? '' );
	}

	public function test_standard_registration_two_factor_and_google_flows_consume_the_resolved_continuation(): void {
		$root   = dirname( __DIR__, 2 );
		$render = file_get_contents( $root . '/blocks/login-register/render.php' );
		$view   = file_get_contents( $root . '/blocks/login-register/view.js' );
		$google = file_get_contents( $root . '/assets/js/google-signin.js' );
		$tokens = file_get_contents( $root . '/inc/auth-tokens/service.php' );

		$this->assertStringContainsString( 'ec_users_resolve_login_block_redirect( $block_redirect_url, $current_url )', $render );
		$this->assertStringContainsString( 'ec_users_canonical_google_signin_url( $success_redirect )', $render );
		$this->assertStringContainsString( 'name="redirect_to" value={ config.loginRedirectUrl }', $view );
		$this->assertStringContainsString( 'redirect_to: redirectTo', $view );
		$this->assertStringContainsString( 'name="success_redirect_url" value={ config.successRedirectUrl }', $view );
		$this->assertStringContainsString( 'success_redirect_url: config.successRedirectUrl', $view );
		$this->assertStringContainsString( 'data?.redirect_url || config.loginRedirectUrl', $view );
		$this->assertStringContainsString( 'success_redirect_url: successRedirectUrl', $google );
		$this->assertStringContainsString( 'extrachill_users_maybe_handle_two_factor( $identifier, $password, $remember, $safe_redirect_to )', $tokens );
		$this->assertStringContainsString( "'redirect_to'   => \$redirect_to ? \$redirect_to : home_url()", $tokens );
	}

	/**
	 * Render the dynamic block and decode its browser configuration.
	 *
	 * @param string $fixed_redirect Configured block redirect.
	 * @return array<string, mixed>
	 */
	private function render_login_block_config( string $fixed_redirect ): array {
		$html = render_block(
			array(
				'blockName' => 'extrachill/login-register',
				'attrs'     => array( 'redirectUrl' => $fixed_redirect ),
			)
		);

		$this->assertMatchesRegularExpression( '/data-ec-login-register-config="([^"]+)"/', $html );
		preg_match( '/data-ec-login-register-config="([^"]+)"/', $html, $matches );
		$config = json_decode( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ), true );

		$this->assertIsArray( $config );
		return $config;
	}

	protected function tearDown(): void {
		unset( $_GET['redirect_to'], $_GET[ EC_USERS_GOOGLE_REDIRECT_PARAM ] );
		parent::tearDown();
	}
}
