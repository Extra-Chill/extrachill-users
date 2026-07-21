<?php
/**
 * Onboarding-origin artist access analytics tests.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify grants are emitted once for a completed access transition.
 */
class Test_Onboarding_Artist_Access_Grant extends WP_UnitTestCase {

	/**
	 * Whether this test registered the analytics fixture.
	 *
	 * @var bool
	 */
	private $registered_fake_analytics = false;

	/**
	 * Install a bounded analytics capture ability.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ec_onboarding_grant_events'] = array();

		if ( ! wp_has_ability( 'extrachill/track-analytics-event' ) ) {
			wp_register_ability(
				'extrachill/track-analytics-event',
				array(
					'label'               => 'Test analytics',
					'description'         => 'Captures onboarding grant events.',
					'category'            => 'extrachill-users',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'integer' ),
					'permission_callback' => '__return_true',
					'execute_callback'    => static function ( $input ) {
						$GLOBALS['ec_onboarding_grant_events'][] = $input;
						return count( $GLOBALS['ec_onboarding_grant_events'] );
					},
				)
			);
			$this->registered_fake_analytics = true;
		}
	}

	/**
	 * Remove analytics fixtures.
	 */
	protected function tearDown(): void {
		if ( $this->registered_fake_analytics ) {
			wp_unregister_ability( 'extrachill/track-analytics-event' );
		}
		unset( $GLOBALS['ec_onboarding_grant_events'] );
		parent::tearDown();
	}

	/**
	 * A real transition emits one exact privacy-safe payload.
	 */
	public function test_transition_emits_exact_bounded_payload(): void {
		$user_id = $this->create_onboarding_user( 'granttransition' );

		$result = $this->complete( $user_id, true, true );
		$events = $this->grant_events();

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $events );
		$this->assertSame(
			array(
				'user_id' => $user_id,
				'source'  => 'onboarding',
				'method'  => 'artist_and_professional',
			),
			$events[0]['event_data']
		);
		$this->assertSame( array( 'user_id', 'source', 'method' ), array_keys( $events[0]['event_data'] ) );
	}

	/**
	 * Replaying a completed request cannot duplicate conversion.
	 */
	public function test_completed_replay_does_not_duplicate_grant(): void {
		$user_id = $this->create_onboarding_user( 'grantreplay' );

		$this->complete( $user_id, true, false );
		$replay = $this->complete( $user_id, true, false );

		$this->assertWPError( $replay );
		$this->assertSame( 'already_completed', $replay->get_error_code() );
		$this->assertCount( 1, $this->grant_events() );
	}

	/**
	 * Existing access, including a partial prior attempt, is not a conversion.
	 */
	public function test_already_granted_user_does_not_emit(): void {
		$user_id = $this->create_onboarding_user( 'alreadygranted' );
		update_user_meta( $user_id, 'user_is_artist', '1' );

		$result = $this->complete( $user_id, false, true );

		$this->assertNotWPError( $result );
		$this->assertSame( '1', get_user_meta( $user_id, 'user_is_professional', true ) );
		$this->assertCount( 0, $this->grant_events() );
	}

	/**
	 * A failed completion restores access state and emits no conversion.
	 */
	public function test_failed_completion_rolls_back_access_and_does_not_emit(): void {
		$user_id = $this->create_onboarding_user( 'grantrollback' );
		$block   = static function ( $check, $object_id, $meta_key, $meta_value ) use ( $user_id ) {
			if ( $user_id === (int) $object_id && 'onboarding_completed' === $meta_key && '1' === $meta_value ) {
				return false;
			}
			return $check;
		};
		add_filter( 'update_user_metadata', $block, 10, 4 );

		try {
			$result = $this->complete( $user_id, true, false );
		} finally {
			remove_filter( 'update_user_metadata', $block, 10 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'onboarding_state_update_failed', $result->get_error_code() );
		$this->assertSame( '0', get_user_meta( $user_id, 'onboarding_completed', true ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, 'user_is_artist' ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, 'user_is_professional' ) );
		$this->assertCount( 0, $this->grant_events() );
	}

	/**
	 * Create an incomplete user fixture.
	 *
	 * @param string $login User login.
	 * @return int User ID.
	 */
	private function create_onboarding_user( $login ) {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => $login,
				'user_email' => $login . '@example.com',
			)
		);
		update_user_meta( $user_id, 'onboarding_completed', '0' );
		return $user_id;
	}

	/**
	 * Complete onboarding for a fixture.
	 *
	 * @param int  $user_id         User ID.
	 * @param bool $artist         Artist choice.
	 * @param bool $professional   Professional choice.
	 * @return array|WP_Error Result.
	 */
	private function complete( $user_id, $artist, $professional ) {
		return extrachill_users_ability_complete_onboarding(
			array(
				'user_id'              => $user_id,
				'username'             => get_userdata( $user_id )->user_login,
				'user_is_artist'       => $artist,
				'user_is_professional' => $professional,
			)
		);
	}

	/**
	 * Return only canonical grant events from all onboarding analytics.
	 *
	 * @return array[] Grant events.
	 */
	private function grant_events() {
		return array_values(
			array_filter(
				$GLOBALS['ec_onboarding_grant_events'],
				static function ( $event ) {
					return EC_ANALYTICS_EVENT_ARTIST_ACCESS_GRANTED === $event['event_type'];
				}
			)
		);
	}
}
