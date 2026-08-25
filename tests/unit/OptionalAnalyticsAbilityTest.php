<?php
/**
 * Optional analytics ability integration tests.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify analytics emitters tolerate an absent provider and use one when present.
 */
class Test_Optional_Analytics_Ability extends WP_UnitTestCase {

	/**
	 * Whether this test registered the analytics fixture.
	 *
	 * @var bool
	 */
	private $registered_fake_analytics = false;

	/**
	 * Reset captured events.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ec_users_optional_analytics_events'] = array();
	}

	/**
	 * Remove test-owned analytics state.
	 */
	protected function tearDown(): void {
		if ( $this->registered_fake_analytics ) {
			wp_unregister_ability( 'extrachill/track-analytics-event' );
		}
		unset( $GLOBALS['ec_users_optional_analytics_events'] );
		parent::tearDown();
	}

	/**
	 * Optional emitters no-op without requesting a missing ability.
	 */
	public function test_optional_analytics_absent_is_a_clean_no_op(): void {
		if ( wp_has_ability( 'extrachill/track-analytics-event' ) ) {
			$this->markTestSkipped( 'The isolated Users test runtime must not load an external analytics provider.' );
		}

		$this->assertSame( 0, ec_users_emit_onboarding_event( 'test_onboarding', 0 ) );
		$this->assertSame( 0, ec_users_emit_team_experience_event( 'test_team', 0 ) );

		$user_id = extrachill_users_ability_create_user(
			array(
				'username' => 'optionalanalyticsabsent',
				'password' => 'securepassword',
				'email'    => 'optional-analytics-absent@example.com',
			)
		);

		$this->assertIsInt( $user_id );
		$this->assertGreaterThan( 0, $user_id );
	}

	/**
	 * Optional emitters execute a registered external provider.
	 */
	public function test_optional_analytics_present_is_executed(): void {
		if ( wp_has_ability( 'extrachill/track-analytics-event' ) ) {
			$this->markTestSkipped( 'The isolated Users test runtime must own the analytics fixture.' );
		}

		$ability = WP_Abilities_Registry::get_instance()->register(
			'extrachill/track-analytics-event',
			array(
				'label'               => 'Test analytics',
				'description'         => 'Captures optional analytics events.',
				'category'            => 'extrachill-users',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'integer' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) {
					$GLOBALS['ec_users_optional_analytics_events'][] = $input;
					return count( $GLOBALS['ec_users_optional_analytics_events'] );
				},
			)
		);
		$this->assertNotNull( $ability );
		$this->registered_fake_analytics = true;

		$this->assertSame( 1, ec_users_emit_onboarding_event( 'test_onboarding', 0 ) );
		$this->assertSame( 2, ec_users_emit_team_experience_event( 'test_team', 0 ) );

		$user_id = extrachill_users_ability_create_user(
			array(
				'username' => 'optionalanalyticspresent',
				'password' => 'securepassword',
				'email'    => 'optional-analytics-present@example.com',
			)
		);

		$this->assertIsInt( $user_id );
		$this->assertCount( 3, $GLOBALS['ec_users_optional_analytics_events'] );
		$this->assertSame( 'user_registration', $GLOBALS['ec_users_optional_analytics_events'][2]['event_type'] );
	}
}
