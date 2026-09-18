<?php
/**
 * The branded consent screen serves two OAuth grants that verify different
 * nonce actions.
 *
 * wp-native-auth renders this template for both the authorization code flow
 * and the RFC 8628 device flow, passing the action each one verifies in
 * $args['nonce_action']. The template previously hardcoded the code-flow
 * action, so every device approval failed its nonce check with a 403 —
 * reproduced against production before this test existed.
 *
 * The plugin's own tests did not catch it because they exercise the default
 * unbranded template; the breakage only exists for hosts that override it.
 */

class Test_OAuth_Consent_Template extends WP_UnitTestCase {

	/**
	 * Render the branded template and return its markup.
	 *
	 * @param array<string,mixed> $overrides View args to merge.
	 * @return string Rendered HTML.
	 */
	/**
	 * Register the theme-owned handle the badges stylesheet depends on.
	 *
	 * The bare test theme does not provide it, so enqueuing during render
	 * would raise an unregistered-dependency notice. Registering a stub is
	 * better than declaring the notice: WP_Styles::add only fires once per
	 * process, so a declaration would pass in whichever test rendered first
	 * and fail in every one after it. See issue #401 for the underlying
	 * coupling.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! wp_style_is( 'extrachill-root', 'registered' ) ) {
			wp_register_style( 'extrachill-root', false, array(), null );
		}
	}

	private function render( array $overrides = array() ): string {
		/*
		 * The template renders full page chrome, and the bare test theme
		 * ships no header.php or footer.php. Those deprecations fire on every
		 * call, so declaring them is stable regardless of test order.
		 */
		$this->setExpectedDeprecated( 'Theme without header.php' );
		$this->setExpectedDeprecated( 'Theme without footer.php' );

		$args = array_merge(
			array(
				'client_name'      => 'Test Client',
				'client_uri'       => '',
				'client_id'        => 'test-client-id',
				'scope'            => 'account',
				'resource'         => '',
				'bundle'           => 'test-bundle',
				'signature'        => 'test-signature',
				'authorize_action' => 'ignored-by-this-template',
			),
			$overrides
		);

		ob_start();
		include EXTRACHILL_USERS_PLUGIN_DIR . 'templates/oauth-consent.php';

		return (string) ob_get_clean();
	}

	/**
	 * Extract the rendered _wpnonce value.
	 */
	private function rendered_nonce( string $html ): string {
		preg_match( '/name="_wpnonce"[^>]*value="([^"]+)"/', $html, $m );

		return isset( $m[1] ) ? $m[1] : '';
	}

	public function test_device_flow_renders_the_device_nonce_action(): void {
		$html = $this->render(
			array(
				'nonce_action'   => 'wp_native_auth_oauth_device',
				'is_device_flow' => true,
			)
		);

		$nonce = $this->rendered_nonce( $html );

		$this->assertNotSame( '', $nonce, 'the consent form rendered no nonce field' );
		$this->assertSame(
			1,
			wp_verify_nonce( $nonce, 'wp_native_auth_oauth_device' ),
			'the device flow verifies wp_native_auth_oauth_device, so the form must carry that action'
		);
	}

	public function test_code_flow_renders_the_consent_nonce_action(): void {
		$html = $this->render( array( 'nonce_action' => 'wp_native_auth_oauth_consent' ) );

		$this->assertSame(
			1,
			wp_verify_nonce( $this->rendered_nonce( $html ), 'wp_native_auth_oauth_consent' )
		);
	}

	/**
	 * A caller that predates the argument still gets the code-flow action.
	 */
	public function test_missing_nonce_action_falls_back_to_the_consent_action(): void {
		$html = $this->render();

		$this->assertSame(
			1,
			wp_verify_nonce( $this->rendered_nonce( $html ), 'wp_native_auth_oauth_consent' )
		);
	}

	/**
	 * The two actions must not be interchangeable, or the test above proves
	 * nothing.
	 */
	public function test_the_two_actions_are_distinct(): void {
		$html = $this->render( array( 'nonce_action' => 'wp_native_auth_oauth_device' ) );

		$this->assertFalse(
			(bool) wp_verify_nonce( $this->rendered_nonce( $html ), 'wp_native_auth_oauth_consent' ),
			'a device nonce must not validate against the consent action'
		);
	}
}
