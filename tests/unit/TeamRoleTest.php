<?php
/**
 * Unit tests for the team role helpers (#45 Phase 1).
 *
 * Covers the pure functions in inc/team-members/role.php that don't
 * need network-wide site iteration. Integration coverage for the
 * network-wide sync behavior lives in tests/integration once a real
 * multisite test harness is available.
 */

class Test_Team_Role extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/inc/team-members/role.php';
	}

	public function test_role_caps_grant_upload_files(): void {
		$caps = ec_users_get_team_role_caps();
		$this->assertArrayHasKey( 'upload_files', $caps );
		$this->assertTrue( $caps['upload_files'] );
	}

	public function test_role_caps_grant_custom_access_caps(): void {
		$caps = ec_users_get_team_role_caps();
		foreach (
			array(
				'access_studio',
				'access_roadie',
				'access_transcribe',
				'access_events_admin',
				'access_admin_bar',
				'submit_for_review',
			) as $cap
		) {
			$this->assertArrayHasKey( $cap, $caps, sprintf( 'Missing custom team cap: %s', $cap ) );
			$this->assertTrue( $caps[ $cap ], sprintf( 'Custom cap %s should be granted.', $cap ) );
		}
	}

	public function test_role_caps_do_not_grant_admin_caps(): void {
		$caps = ec_users_get_team_role_caps();
		foreach ( array( 'manage_options', 'delete_others_posts', 'edit_users', 'manage_network' ) as $cap ) {
			$this->assertArrayNotHasKey( $cap, $caps, sprintf( 'Team role must not grant %s.', $cap ) );
		}
	}

	public function test_compute_effective_status_zero_user(): void {
		$this->assertFalse( ec_users_compute_effective_team_status( 0 ) );
		$this->assertFalse( ec_users_compute_effective_team_status( -1 ) );
	}

	public function test_compute_effective_status_flag_only(): void {
		$user_id = self::factory()->user->create();

		$this->assertFalse( ec_users_compute_effective_team_status( $user_id ) );

		update_user_meta( $user_id, 'extrachill_team', '1' );
		$this->assertTrue( ec_users_compute_effective_team_status( $user_id ) );

		update_user_meta( $user_id, 'extrachill_team', '0' );
		$this->assertFalse( ec_users_compute_effective_team_status( $user_id ) );
	}

	public function test_compute_effective_status_manual_override_add_wins(): void {
		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, 'extrachill_team', '0' );
		update_user_meta( $user_id, 'extrachill_team_manual_override', 'add' );

		$this->assertTrue(
			ec_users_compute_effective_team_status( $user_id ),
			'Manual override "add" must override a missing/zero extrachill_team flag.'
		);
	}

	public function test_compute_effective_status_manual_override_remove_wins(): void {
		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, 'extrachill_team', '1' );
		update_user_meta( $user_id, 'extrachill_team_manual_override', 'remove' );

		$this->assertFalse(
			ec_users_compute_effective_team_status( $user_id ),
			'Manual override "remove" must override a truthy extrachill_team flag.'
		);
	}

	public function test_compute_effective_status_unknown_override_falls_through(): void {
		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, 'extrachill_team', '1' );
		update_user_meta( $user_id, 'extrachill_team_manual_override', 'garbage' );

		$this->assertTrue(
			ec_users_compute_effective_team_status( $user_id ),
			'Unknown override values should fall through to the meta flag.'
		);
	}

	public function test_register_team_role_creates_role_with_expected_caps(): void {
		// Make sure we start clean for this test.
		remove_role( EC_USERS_TEAM_ROLE );

		ec_users_register_team_role();

		$role = get_role( EC_USERS_TEAM_ROLE );
		$this->assertInstanceOf( WP_Role::class, $role );
		$this->assertTrue( $role->has_cap( 'upload_files' ) );
		$this->assertTrue( $role->has_cap( 'access_studio' ) );
		$this->assertFalse( $role->has_cap( 'manage_options' ) );
	}

	public function test_register_team_role_idempotent(): void {
		remove_role( EC_USERS_TEAM_ROLE );

		ec_users_register_team_role();
		ec_users_register_team_role();
		ec_users_register_team_role();

		$role = get_role( EC_USERS_TEAM_ROLE );
		$this->assertInstanceOf( WP_Role::class, $role );
		$this->assertTrue( $role->has_cap( 'upload_files' ) );
	}

	public function test_is_team_member_with_cap_returns_true(): void {
		require_once dirname( __DIR__, 2 ) . '/inc/team-members.php';

		remove_role( EC_USERS_TEAM_ROLE );
		ec_users_register_team_role();

		$user_id = self::factory()->user->create();
		$user    = new WP_User( $user_id );
		$user->add_role( EC_USERS_TEAM_ROLE );

		wp_set_current_user( $user_id );
		$this->assertTrue( ec_is_team_member() );
		$this->assertTrue( ec_is_team_member( $user_id ) );
	}

	public function test_is_team_member_falls_back_to_meta_when_role_absent(): void {
		require_once dirname( __DIR__, 2 ) . '/inc/team-members.php';

		// Make sure the user does not have the role.
		$user_id = self::factory()->user->create();
		$user    = new WP_User( $user_id );
		$user->remove_role( EC_USERS_TEAM_ROLE );

		$this->assertFalse( ec_is_team_member( $user_id ) );

		update_user_meta( $user_id, 'extrachill_team', '1' );
		$this->assertTrue(
			ec_is_team_member( $user_id ),
			'Transition fallback: meta-based status must still be honored before reconcile runs.'
		);
	}

	public function test_is_team_member_zero_user_id_returns_false(): void {
		require_once dirname( __DIR__, 2 ) . '/inc/team-members.php';

		wp_set_current_user( 0 );
		$this->assertFalse( ec_is_team_member() );
	}
}
