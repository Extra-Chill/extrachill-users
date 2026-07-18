<?php
/**
 * Artist Dispatch access lifecycle tests.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify the Artist Dispatch contract against WordPress multisite primitives.
 */
class Test_Artist_Dispatch_Access extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

	private int $main_blog_id;
	private int $artist_blog_id;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/inc/artist-dispatch-role.php';
		require_once dirname( __DIR__, 2 ) . '/inc/artist-dispatch.php';
		require_once dirname( __DIR__, 2 ) . '/inc/core/abilities/artist-dispatch.php';

		$this->main_blog_id   = ec_users_get_artist_dispatch_blog_id();
		$this->artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
		if ( ! is_multisite() || ! $this->artist_blog_id ) {
			$this->markTestSkipped( 'Artist Dispatch tests require the multisite artist-site map.' );
		}

		$this->ensure_blog_exists( $this->main_blog_id );
		$this->ensure_blog_exists( $this->artist_blog_id );
		delete_site_option( EC_USERS_ARTIST_DISPATCH_POLICY_OPTION );
		wp_set_current_user( 0 );

		switch_to_blog( $this->main_blog_id );
		remove_role( EC_USERS_ARTIST_DISPATCH_ROLE );
		ec_users_register_artist_dispatch_role();
		restore_current_blog();
	}

	protected function tearDown(): void {
		delete_site_option( EC_USERS_ARTIST_DISPATCH_POLICY_OPTION );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function ensure_blog_exists( int $blog_id ): void {
		while ( ! get_blog_details( $blog_id ) ) {
			$created = self::factory()->blog->create();
			if ( $created > $blog_id ) {
				$this->fail( 'Could not create expected mapped blog ID.' );
			}
		}
	}

	private function configure_policy( float $minimum_points = 10 ): void {
		ec_users_update_artist_dispatch_policy(
			array(
				'minimum_points'           => $minimum_points,
				'minimum_account_age_days' => 1,
				'pilot_enabled'            => true,
			)
		);
	}

	private function create_eligible_user(): array {
		$user_id = self::factory()->user->create(
			array(
				'role'            => '',
				'user_registered' => gmdate( 'Y-m-d H:i:s', time() - ( 10 * DAY_IN_SECONDS ) ),
			)
		);
		update_user_meta( $user_id, 'onboarding_completed', '1' );
		set_transient( 'user_points_' . $user_id, 25, HOUR_IN_SECONDS );

		switch_to_blog( $this->artist_blog_id );
		register_post_type( 'artist_profile', array( 'public' => true ) );
		$artist_id = self::factory()->post->create(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'publish',
				'post_title'  => 'Lifecycle Artist',
			)
		);
		restore_current_blog();
		update_user_meta( $user_id, '_artist_profile_ids', array( $artist_id ) );

		return array( $user_id, $artist_id );
	}

	private function request_access( int $user_id, int $artist_id ): array {
		wp_set_current_user( $user_id );
		$result = ec_users_request_artist_dispatch_access(
			$user_id,
			array(
				'artist_id'       => $artist_id,
				'description'     => str_repeat( 'A focused first-person dispatch proposal. ', 3 ),
				'sample_url'      => 'https://example.com/sample',
				'acknowledgement' => true,
				'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
			)
		);
		$this->assertNotWPError( $result );
		return $result;
	}

	private function create_network_admin(): int {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );
		return $admin_id;
	}

	public function test_policy_is_disabled_and_unconfigured_by_default(): void {
		$user_id     = self::factory()->user->create();
		$eligibility = ec_users_get_artist_dispatch_eligibility( $user_id );

		$this->assertFalse( $eligibility['eligible'] );
		$this->assertFalse( $eligibility['criteria']['policy_configured']['passed'] );
		$this->assertFalse( $eligibility['criteria']['pilot_enabled']['passed'] );
		$this->assertNull( $eligibility['criteria']['points']['minimum'] );
	}

	public function test_eligibility_reports_each_input_and_canonical_artist_relationship(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$eligibility                 = ec_users_get_artist_dispatch_eligibility( $user_id );

		$this->assertTrue( $eligibility['eligible'] );
		$this->assertSame( 25.0, $eligibility['criteria']['points']['value'] );
		$this->assertTrue( $eligibility['criteria']['onboarding']['value'] );
		$this->assertGreaterThanOrEqual( 1, $eligibility['criteria']['account_age']['value_days'] );
		$this->assertTrue( $eligibility['criteria']['claimed_account']['value'] );
		$this->assertTrue( $eligibility['criteria']['active_moderation']['value'] );
		$this->assertSame( array( $artist_id ), $eligibility['criteria']['claimed_artist']['artist_ids'] );

		update_user_meta( $user_id, '_artist_profile_ids', array() );
		$this->assertFalse( ec_users_get_artist_dispatch_eligibility( $user_id )['criteria']['claimed_artist']['passed'] );
	}

	public function test_request_is_self_only_and_server_validates_selected_artist(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$other_id                    = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$invalid = extrachill_users_ability_request_artist_dispatch_access(
			array(
				'user_id'         => $other_id,
				'artist_id'       => $artist_id + 999,
				'description'     => str_repeat( 'Valid length, invalid ownership. ', 3 ),
				'acknowledgement' => true,
				'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
			)
		);
		$this->assertWPError( $invalid );
		$this->assertSame( array(), ec_users_get_artist_dispatch_state( $other_id ) );

		$valid = extrachill_users_ability_request_artist_dispatch_access(
			array(
				'user_id'         => $other_id,
				'artist_id'       => $artist_id,
				'description'     => str_repeat( 'A valid proposal from the authenticated member. ', 3 ),
				'acknowledgement' => true,
				'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
			)
		);
		$this->assertNotWPError( $valid );
		$this->assertSame( 'pending', ec_users_get_artist_dispatch_state( $user_id )['status'] );
		$this->assertSame( array(), ec_users_get_artist_dispatch_state( $other_id ) );
		$this->assertArrayNotHasKey( 'description', $valid );
		$this->assertTrue( $valid['terms_acknowledged'] );
		$this->assertSame( EC_USERS_ARTIST_DISPATCH_TERMS_VERSION, $valid['terms_version'] );
	}

	public function test_request_requires_and_immutably_persists_current_terms_acceptance(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$base_input                  = array(
			'artist_id'   => $artist_id,
			'description' => str_repeat( 'A proposal with an explicit affiliation disclosure. ', 3 ),
		);

		$this->assertWPError( ec_users_request_artist_dispatch_access( $user_id, $base_input ) );
		$this->assertWPError(
			ec_users_request_artist_dispatch_access(
				$user_id,
				array_merge(
					$base_input,
					array(
						'acknowledgement' => false,
						'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
					)
				)
			)
		);
		$this->assertWPError( ec_users_request_artist_dispatch_access( $user_id, array_merge( $base_input, array( 'acknowledgement' => true ) ) ) );
		$this->assertWPError(
			ec_users_request_artist_dispatch_access(
				$user_id,
				array_merge(
					$base_input,
					array(
						'acknowledgement' => true,
						'terms_version'   => 'v1',
					)
				)
			)
		);

		$request = ec_users_request_artist_dispatch_access(
			$user_id,
			array_merge(
				$base_input,
				array(
					'acknowledgement' => true,
					'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
				)
			)
		);
		$this->assertNotWPError( $request );
		$this->assertTrue( $request['terms_acknowledged'] );
		$this->assertSame( EC_USERS_ARTIST_DISPATCH_TERMS_VERSION, $request['terms_version'] );
		$this->assertGreaterThan( 0, $request['terms_accepted_at'] );
		$events = get_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_AUDIT_META, false );
		$this->assertSame( EC_USERS_ARTIST_DISPATCH_TERMS_VERSION, $events[0]['details']['terms_version'] );

		$retry = ec_users_request_artist_dispatch_access(
			$user_id,
			array_merge(
				$base_input,
				array(
					'acknowledgement' => true,
					'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
				)
			)
		);
		$this->assertSame( $request['request_id'], $retry['request_id'] );
		$this->assertSame( $request['terms_accepted_at'], $retry['terms_accepted_at'] );
		$this->assertWPError(
			ec_users_request_artist_dispatch_access(
				$user_id,
				array_merge(
					$base_input,
					array(
						'acknowledgement' => true,
						'terms_version'   => 'changed-client-value',
					)
				)
			)
		);
		$this->assertSame( EC_USERS_ARTIST_DISPATCH_TERMS_VERSION, ec_users_get_artist_dispatch_state( $user_id )['terms_version'] );
	}

	public function test_request_analytics_payload_is_bounded_and_contains_no_application_text(): void {
		$payload = ec_users_get_artist_dispatch_requested_event_payload( 12, 34, EC_USERS_ARTIST_DISPATCH_TERMS_VERSION );
		$this->assertSame( array( 'user_id', 'artist_id', 'terms_version', 'surface' ), array_keys( $payload ) );
		$this->assertSame( 12, $payload['user_id'] );
		$this->assertSame( 34, $payload['artist_id'] );
		$this->assertSame( EC_USERS_ARTIST_DISPATCH_TERMS_VERSION, $payload['terms_version'] );
		$this->assertSame( 'artist_dispatch', $payload['surface'] );
		$this->assertArrayNotHasKey( 'description', $payload );
		$this->assertArrayNotHasKey( 'sample_url', $payload );
	}

	public function test_role_capabilities_are_exact_and_main_site_only(): void {
		$this->assertSame(
			array( 'read', 'edit_posts', 'delete_posts', 'submit_for_review' ),
			array_keys( ec_users_get_artist_dispatch_role_caps() )
		);

		$other_blog_id = self::factory()->blog->create();
		switch_to_blog( $other_blog_id );
		ec_users_register_artist_dispatch_role();
		$this->assertNull( get_role( EC_USERS_ARTIST_DISPATCH_ROLE ) );
		restore_current_blog();
	}

	public function test_approval_is_additive_and_native_post_authorization_is_bounded(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		add_user_to_blog( $this->main_blog_id, $user_id, 'subscriber' );
		$request  = $this->request_access( $user_id, $artist_id );
		$admin_id = $this->create_network_admin();
		$approved = ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id );
		$this->assertNotWPError( $approved );

		switch_to_blog( $this->main_blog_id );
		$user = new WP_User( $user_id );
		$this->assertContains( 'subscriber', $user->roles );
		$this->assertContains( EC_USERS_ARTIST_DISPATCH_ROLE, $user->roles );
		wp_set_current_user( $user_id );
		$own_draft  = self::factory()->post->create(
			array(
				'post_author' => $user_id,
				'post_status' => 'draft',
			)
		);
		$other      = self::factory()->user->create();
		$other_post = self::factory()->post->create(
			array(
				'post_author' => $other,
				'post_status' => 'draft',
			)
		);
		$this->assertTrue( current_user_can( 'edit_post', $own_draft ), 'Core autosaves use this edit_post authorization.' );
		$this->assertFalse( current_user_can( 'edit_post', $other_post ) );
		$this->assertFalse( current_user_can( 'publish_posts' ) );
		$this->assertFalse( current_user_can( 'upload_files' ) );
		$this->assertFalse( current_user_can( 'edit_others_posts' ) );
		$this->assertFalse( current_user_can( 'edit_published_posts' ) );
		$this->assertFalse( current_user_can( 'manage_categories' ) );
		restore_current_blog();
	}

	public function test_retries_do_not_duplicate_audit_or_notification_markers(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$request                     = $this->request_access( $user_id, $artist_id );
		$duplicate_request           = $this->request_access( $user_id, $artist_id );
		$this->assertSame( $request['request_id'], $duplicate_request['request_id'] );
		$this->assertCount( 1, get_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_AUDIT_META, false ) );

		$admin_id = $this->create_network_admin();
		$first    = ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id );
		$second   = ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id );
		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		$this->assertCount( 2, get_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_AUDIT_META, false ) );
		$this->assertCount( 1, $second['notifications'] );
	}

	public function test_revocation_removes_only_product_grant_and_cleans_grant_created_membership(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		remove_user_from_blog( $user_id, $this->main_blog_id );
		$request  = $this->request_access( $user_id, $artist_id );
		$admin_id = $this->create_network_admin();
		ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id );
		$this->assertTrue( is_user_member_of_blog( $user_id, $this->main_blog_id ) );

		$revoked = ec_users_revoke_artist_dispatch_access( $user_id, $request['request_id'], 'Pilot access ended.', $admin_id );
		$this->assertNotWPError( $revoked );
		$this->assertFalse( is_user_member_of_blog( $user_id, $this->main_blog_id ) );
		$this->assertSame( 'revoked', $revoked['status'] );
	}

	public function test_moderation_blocks_request_and_revokes_without_automatic_restore(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$admin_id                    = $this->create_network_admin();
		extrachill_users_apply_moderation_action(
			$user_id,
			array(
				'reason_key' => 'other',
				'reason'     => 'Review hold',
				'acted_by'   => $admin_id,
			)
		);
		$this->assertFalse( ec_users_get_artist_dispatch_eligibility( $user_id )['eligible'] );
		$this->assertWPError(
			ec_users_request_artist_dispatch_access(
				$user_id,
				array(
					'artist_id'       => $artist_id,
					'description'     => str_repeat( 'Blocked request text. ', 4 ),
					'acknowledgement' => true,
					'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
				)
			)
		);

		extrachill_users_clear_moderation_action( $user_id );
		$request = $this->request_access( $user_id, $artist_id );
		ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id );
		extrachill_users_apply_moderation_action(
			$user_id,
			array(
				'reason_key' => 'other',
				'reason'     => 'Second hold',
				'acted_by'   => $admin_id,
			)
		);
		$this->assertSame( 'revoked', ec_users_get_artist_dispatch_state( $user_id )['status'] );
		extrachill_users_clear_moderation_action( $user_id );
		$this->assertSame( 'revoked', ec_users_get_artist_dispatch_state( $user_id )['status'] );
	}

	public function test_threshold_change_after_approval_does_not_remove_native_access(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$request                     = $this->request_access( $user_id, $artist_id );
		$admin_id                    = $this->create_network_admin();
		ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id );

		ec_users_update_artist_dispatch_policy( array( 'minimum_points' => 500 ) );
		$this->assertFalse( ec_users_get_artist_dispatch_eligibility( $user_id )['eligible'] );
		switch_to_blog( $this->main_blog_id );
		$this->assertContains( EC_USERS_ARTIST_DISPATCH_ROLE, ( new WP_User( $user_id ) )->roles );
		restore_current_blog();
	}

	public function test_administrative_callbacks_self_defend(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( extrachill_users_ability_list_artist_dispatch_access_requests( array() ) );
		$this->assertWPError(
			extrachill_users_ability_approve_artist_dispatch_access(
				array(
					'user_id'    => 1,
					'request_id' => wp_generate_uuid4(),
				)
			)
		);
	}
}
