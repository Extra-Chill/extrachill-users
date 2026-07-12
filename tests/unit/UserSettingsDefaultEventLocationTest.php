<?php
/**
 * Tests for the private default event location setting.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify account settings persist and resolve canonical event locations.
 */
class Test_User_Settings_Default_Event_Location extends WP_UnitTestCase {

	/**
	 * Events site blog ID.
	 *
	 * @var int
	 */
	private int $events_blog_id;

	/**
	 * Create a hierarchical event location fixture.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->events_blog_id                    = self::factory()->blog->create();
		$GLOBALS['ec_users_test_events_blog_id'] = $this->events_blog_id;
		add_filter(
			'extrachill_users_events_blog_id',
			static function () {
				return $GLOBALS['ec_users_test_events_blog_id'];
			}
		);

		if ( ! function_exists( 'ec_get_blog_id' ) ) {
			/**
			 * Resolve the test events site.
			 *
			 * @param string $site Site key.
			 * @return int Blog ID.
			 */
			function ec_get_blog_id( $site ) {
				return 'events' === $site ? $GLOBALS['ec_users_test_events_blog_id'] : 0;
			}
		}

		switch_to_blog( $this->events_blog_id );
		register_taxonomy( 'location', 'post', array( 'hierarchical' => true ) );
		$country = wp_insert_term( 'United States', 'location', array( 'slug' => 'usa' ) );
		$state   = wp_insert_term(
			'South Carolina',
			'location',
			array(
				'slug'   => 'south-carolina',
				'parent' => $country['term_id'],
			)
		);
		$city    = wp_insert_term(
			'Charleston',
			'location',
			array(
				'slug'   => 'charleston',
				'parent' => $state['term_id'],
			)
		);
		update_term_meta( $city['term_id'], '_location_coordinates', '32.7765,-79.9311' );
		restore_current_blog();
	}

	/**
	 * A city slug resolves on write and an empty string clears it.
	 */
	public function test_update_resolves_and_clears_private_default_location(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$original_blog_id = get_current_blog_id();

		$updated = extrachill_users_ability_update_settings( array( 'default_event_location' => 'charleston' ) );

		$this->assertSame( $original_blog_id, get_current_blog_id() );
		$this->assertSame( 'charleston', $updated['default_event_location']['slug'] );
		$this->assertSame( 32.7765, $updated['default_event_location']['coordinates']['lat'] );
		$this->assertSame( 'charleston', get_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, true ) );

		$cleared = extrachill_users_ability_update_settings( array( 'default_event_location' => '' ) );
		$this->assertNull( $cleared['default_event_location'] );
		$this->assertSame( '', get_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, true ) );
	}

	/**
	 * Country subdivisions and unknown slugs are not valid city defaults.
	 */
	public function test_update_rejects_non_city_and_unknown_locations(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$state = extrachill_users_ability_update_settings( array( 'default_event_location' => 'south-carolina' ) );
		$this->assertWPError( $state );
		$this->assertSame( 'invalid_default_event_location', $state->get_error_code() );

		$unknown = extrachill_users_ability_update_settings( array( 'default_event_location' => 'nowhere' ) );
		$this->assertWPError( $unknown );
		$this->assertSame( 'invalid_default_event_location', $unknown->get_error_code() );
	}
}
