<?php
/**
 * Tests for explicit, idempotent concert attendance abilities.
 *
 * @package ExtraChill\Users
 */

class Test_Concert_Tracking_Abilities extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		extrachill_users_install_concert_tracking_table();
	}

	public function test_set_event_mark_is_idempotent_in_both_directions(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$first_mark  = extrachill_users_ability_set_event_mark( array( 'event_id' => 101, 'blog_id' => 7, 'marked' => true ) );
		$second_mark = extrachill_users_ability_set_event_mark( array( 'event_id' => 101, 'blog_id' => 7, 'marked' => true ) );

		$this->assertTrue( $first_mark['changed'] );
		$this->assertTrue( $first_mark['marked'] );
		$this->assertFalse( $second_mark['changed'] );
		$this->assertTrue( $second_mark['marked'] );
		$this->assertTrue( ec_users_is_event_marked( $user_id, 101, 7 ) );

		$first_unmark  = extrachill_users_ability_set_event_mark( array( 'event_id' => 101, 'blog_id' => 7, 'marked' => false ) );
		$second_unmark = extrachill_users_ability_set_event_mark( array( 'event_id' => 101, 'blog_id' => 7, 'marked' => false ) );

		$this->assertTrue( $first_unmark['changed'] );
		$this->assertFalse( $first_unmark['marked'] );
		$this->assertFalse( $second_unmark['changed'] );
		$this->assertFalse( $second_unmark['marked'] );
		$this->assertFalse( ec_users_is_event_marked( $user_id, 101, 7 ) );
	}

	public function test_network_administrator_can_target_another_user(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$target_user_id    = self::factory()->user->create();
		grant_super_admin( $administrator_id );
		wp_set_current_user( $administrator_id );

		$result = extrachill_users_ability_set_event_mark(
			array(
				'user_id'  => $target_user_id,
				'event_id' => 102,
				'blog_id'  => 7,
				'marked'   => true,
			)
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( $target_user_id, $result['user_id'] );
		$this->assertTrue( ec_users_is_event_marked( $target_user_id, 102, 7 ) );
		revoke_super_admin( $administrator_id );
	}

	public function test_regular_user_cannot_target_another_user(): void {
		$current_user_id = self::factory()->user->create();
		$target_user_id  = self::factory()->user->create();
		wp_set_current_user( $current_user_id );

		$result = extrachill_users_ability_set_event_mark(
			array(
				'user_id'  => $target_user_id,
				'event_id' => 103,
				'blog_id'  => 7,
				'marked'   => true,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden_user_target', $result->get_error_code() );
		$this->assertFalse( ec_users_is_event_marked( $target_user_id, 103, 7 ) );
	}

	public function test_targeted_attendance_check_is_read_only(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$target_user_id    = self::factory()->user->create();
		grant_super_admin( $administrator_id );
		ec_users_mark_event( $target_user_id, 104, 7 );
		wp_set_current_user( $administrator_id );

		$before = ec_users_get_event_mark_count( 104, 7 );
		$result = extrachill_users_ability_get_event_attendance(
			array(
				'user_id'  => $target_user_id,
				'event_id' => 104,
				'blog_id'  => 7,
			)
		);

		$this->assertTrue( $result['user_marked'] );
		$this->assertSame( $before, ec_users_get_event_mark_count( 104, 7 ) );
		revoke_super_admin( $administrator_id );
	}

	public function test_unauthenticated_set_requires_a_user(): void {
		wp_set_current_user( 0 );
		$result = extrachill_users_ability_set_event_mark( array( 'event_id' => 105, 'blog_id' => 7, 'marked' => true ) );

		$this->assertWPError( $result );
		$this->assertSame( 'no_user', $result->get_error_code() );
	}
}
