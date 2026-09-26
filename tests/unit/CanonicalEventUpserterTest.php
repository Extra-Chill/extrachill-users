<?php
/**
 * Focused canonical concert-event upsert contracts.
 *
 * @package ExtraChill\Users
 */

use ExtraChill\Users\Concert_Import\CanonicalEventUpserter;
use ExtraChill\Users\Concert_Import\ExternalEvent;
use ExtraChill\Users\Concert_Import\ImportOrchestrator;

/** Verify mapping, authority binding, legacy adoption, and attendance replay. */
class Test_Canonical_Event_Upserter extends WP_UnitTestCase {

	/**
	 * Events site fixture ID.
	 *
	 * @var int
	 */
	private int $events_blog_id;

	/**
	 * Import owner fixture ID.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Worker IDs returned by the controlled site query.
	 *
	 * @var int[]
	 */
	private array $worker_site_ids = array();

	/** Create multisite, table, user, and assertion fixtures. */
	protected function setUp(): void {
		parent::setUp();
		$this->events_blog_id = self::factory()->blog->create();
		$this->user_id        = self::factory()->user->create();
		extrachill_users_install_concert_tracking_table();
		extrachill_users_install_concert_import_runs_table();
		add_filter( 'extrachill_users_concert_import_service_assertion_keys', array( $this, 'assertion_keys' ) );
		add_filter( 'extrachill_users_concert_import_service_assertion_active_key_id', array( $this, 'active_key_id' ) );
		if ( ! post_type_exists( 'data_machine_events' ) ) {
			register_post_type( 'data_machine_events', array( 'public' => true ) );
		}
	}

	/** Remove shared fixture state. */
	protected function tearDown(): void {
		remove_filter( 'extrachill_users_concert_import_service_assertion_keys', array( $this, 'assertion_keys' ) );
		remove_filter( 'extrachill_users_concert_import_service_assertion_active_key_id', array( $this, 'active_key_id' ) );
		wp_set_current_user( 0 );
		$this->clear_tables();
		parent::tearDown();
	}

	/** Return test assertion keys. */
	public function assertion_keys(): array {
		return array( 'test-key' => str_repeat( 's', 32 ) );
	}

	/** Return active test key ID. */
	public function active_key_id(): string {
		return 'test-key';
	}

	/** Return deliberately absent assertion keys. */
	public function empty_assertion_keys(): array {
		return array();
	}

	/** Return deliberately absent active assertion key ID. */
	public function empty_active_key_id(): string {
		return '';
	}

	/**
	 * Return controlled worker IDs for grant registration tests.
	 *
	 * @param mixed         $sites Existing short-circuit value.
	 * @param WP_Site_Query $query Site query.
	 * @return int[]
	 */
	public function worker_sites( $sites, WP_Site_Query $query ): array {
		unset( $sites, $query );
		return $this->worker_site_ids;
	}

	/** Mapping preserves date-only, geography, performer, publication, and run identity. */
	public function test_maps_external_event_to_canonical_input(): void {
		$input = CanonicalEventUpserter::map_input(
			$this->event(),
			'setlist-fm',
			array(
				'id'      => 41,
				'user_id' => $this->user_id,
			)
		);

		$this->assertIsArray( $input );
		$this->assertSame( 'setlist-fm', $input['source'] );
		$this->assertSame( 'provider-17', $input['source_id'] );
		$this->assertSame( 41, $input['import_run_id'] );
		$this->assertSame( $this->user_id, $input['post_author'] );
		$this->assertSame( 'publish', $input['post_status'] );
		$this->assertSame( 'Fixture Band at Fixture Room · January 15, 2025', $input['event']['title'] );
		$this->assertSame( '2025-01-15', $input['event']['startDate'] );
		$this->assertArrayNotHasKey( 'startTime', $input['event'] );
		$this->assertSame( 'Charleston', $input['event']['venueCity'] );
		$this->assertSame( 'SC', $input['event']['venueState'] );
		$this->assertSame( 'US', $input['event']['venueCountry'] );
		$this->assertSame( 'Fixture Band', $input['event']['performer'] );
		$this->assertArrayNotHasKey( 'description', $input['event'] );
	}

	/** Core ability normalization and schema sanitation retain signed import_run_id. */
	public function test_import_run_id_survives_ability_normalization(): void {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'data-machine-events/upsert-event' ) : null;
		$this->assertNotNull( $ability, 'The data-machine-events validation dependency must register its public upsert ability.' );
		$input      = CanonicalEventUpserter::map_input(
			$this->event(),
			'setlist-fm',
			array(
				'id'      => 52,
				'user_id' => $this->user_id,
			)
		);
		$normalized = $ability->normalize_input( $input );
		$sanitized  = rest_sanitize_value_from_schema( $normalized, $ability->get_input_schema(), 'input' );

		$this->assertSame( 52, $normalized['import_run_id'] );
		$this->assertSame( 52, $sanitized['import_run_id'] );
	}

	/** Ordinary DME authority remains additive when no import request is active. */
	public function test_ordinary_dme_authority_is_untouched(): void {
		$this->assertTrue( CanonicalEventUpserter::filter_upsert_permission( true, array() ) );
		$this->assertFalse( CanonicalEventUpserter::filter_upsert_permission( false, array() ) );
	}

	/** Source and target grants cover main, community, and studio workers. */
	public function test_grants_cover_every_worker_site_with_source_only_active_key(): void {
		$this->worker_site_ids = array(
			(int) ec_get_blog_id( 'main' ),
			(int) ec_get_blog_id( 'community' ),
			(int) ec_get_blog_id( 'studio' ),
		);
		add_filter( 'sites_pre_query', array( $this, 'worker_sites' ), 10, 2 );
		try {
			$sources = CanonicalEventUpserter::register_source_grant( array() );
			$targets = CanonicalEventUpserter::register_target_grant( array() );
		} finally {
			remove_filter( 'sites_pre_query', array( $this, 'worker_sites' ), 10 );
		}

		$this->assertSame( $this->worker_site_ids, array_column( $sources, 'source_site_id' ) );
		$this->assertSame( $this->worker_site_ids, array_column( $targets, 'source_site_id' ) );
		foreach ( $sources as $grant ) {
			$this->assertSame( 'test-key', $grant['active_key_id'] );
			$this->assertSame( CanonicalEventUpserter::ROUTE, $grant['route'] );
			$this->assertSame( 'POST', $grant['method'] );
		}
		foreach ( $targets as $grant ) {
			$this->assertArrayNotHasKey( 'active_key_id', $grant );
			$this->assertSame( (int) ec_get_blog_id( 'events' ), $grant['target_site_id'] );
		}
	}

	/** Network mints its complete body-bound header contract for a worker grant. */
	public function test_network_mints_assertion_headers_for_current_worker_body(): void {
		$input   = CanonicalEventUpserter::map_input(
			$this->event(),
			'setlist-fm',
			array(
				'id'      => 61,
				'user_id' => $this->user_id,
			)
		);
		$headers = ec_cross_site_build_service_assertion_headers(
			'events',
			'POST',
			CanonicalEventUpserter::ROUTE,
			array( 'body' => array( 'input' => $input ) ),
			CanonicalEventUpserter::SERVICE_ID,
			CanonicalEventUpserter::SERVICE_SCOPE
		);

		$this->assertIsArray( $headers );
		$this->assertCount( count( ec_cross_site_service_assertion_headers() ), $headers );
		$this->assertSame( (string) get_current_blog_id(), $headers['X-EC-Service-Source-Site'] );
		$this->assertSame( 'test-key', $headers['X-EC-Service-Key-ID'] );
	}

	/** Missing rotatable key configuration is explicit and fail-closed. */
	public function test_missing_assertion_config_fails_closed_with_diagnostic(): void {
		add_filter( 'extrachill_users_concert_import_service_assertion_keys', array( $this, 'empty_assertion_keys' ), 999 );
		add_filter( 'extrachill_users_concert_import_service_assertion_active_key_id', array( $this, 'empty_active_key_id' ), 999 );
		try {
			$result = ( new CanonicalEventUpserter() )->upsert(
				$this->event(),
				'setlist-fm',
				array(
					'id'      => 62,
					'user_id' => $this->user_id,
				)
			);
			$this->assertWPError( $result );
			$this->assertSame( 'concert_import_service_unconfigured', $result->get_error_code() );
			$this->assertStringContainsString( 'rotatable service assertion keys', $result->get_error_message() );
			$this->assertSame( array(), CanonicalEventUpserter::register_source_grant( array() ) );
		} finally {
			remove_filter( 'extrachill_users_concert_import_service_assertion_keys', array( $this, 'empty_assertion_keys' ), 999 );
			remove_filter( 'extrachill_users_concert_import_service_assertion_active_key_id', array( $this, 'empty_active_key_id' ), 999 );
		}
	}

	/** Verified service authority binds run, source, and current target user. */
	public function test_target_authority_rejects_run_source_and_user_tamper(): void {
		$run_id = $this->insert_running_run( $this->user_id, 'setlist-fm' );
		wp_set_current_user( $this->user_id );

		$run_tamper = $this->prepare_request( $run_id + 999, 'setlist-fm' );
		$this->assertWPError( $run_tamper );
		$this->assertSame( 'concert_import_run_mismatch', $run_tamper->get_error_code() );

		$source_tamper = $this->prepare_request( $run_id, 'phish-net' );
		$this->assertWPError( $source_tamper );
		$this->assertSame( 'concert_import_run_mismatch', $source_tamper->get_error_code() );

		wp_set_current_user( self::factory()->user->create() );
		$user_tamper = $this->prepare_request( $run_id, 'setlist-fm' );
		$this->assertWPError( $user_tamper );
		$this->assertSame( 'concert_import_run_mismatch', $user_tamper->get_error_code() );

		wp_set_current_user( $this->user_id );
		$request                      = $this->request( $run_id, 'setlist-fm' );
		$body                         = $request->get_json_params();
		$body['input']['post_author'] = self::factory()->user->create();
		$request->set_body( wp_json_encode( $body ) );
		$this->set_verified_context( $request );
		$author_tamper = CanonicalEventUpserter::prepare_target_request( null, array(), $request );
		$this->assertWPError( $author_tamper );
		$this->assertSame( 'concert_import_run_mismatch', $author_tamper->get_error_code() );
	}

	/** Legacy-only identity injects event_id; canonical and ambiguous identities do not adopt. */
	public function test_read_only_legacy_candidate_resolution_and_collision(): void {
		switch_to_blog( $this->events_blog_id );
		try {
			$legacy_id = $this->create_event_post();
			update_post_meta( $legacy_id, '_dm_import_source', 'setlist-fm' );
			update_post_meta( $legacy_id, '_dm_import_external_id', 'legacy-one' );
			$this->assertSame( $legacy_id, CanonicalEventUpserter::legacy_candidate( 'setlist-fm', 'legacy-one' ) );
			$this->assertSame( '', get_post_meta( $legacy_id, '_datamachine_event_source_identity', true ) );

			$canonical_id = $this->create_event_post();
			update_post_meta( $canonical_id, '_datamachine_event_source_identity', hash( 'sha256', "setlist-fm\0canonical-one" ) );
			$this->assertSame( 0, CanonicalEventUpserter::legacy_candidate( 'setlist-fm', 'canonical-one' ) );

			$collision_id = $this->create_event_post();
			update_post_meta( $collision_id, '_dm_import_source', 'setlist-fm' );
			update_post_meta( $collision_id, '_dm_import_external_id', 'canonical-one' );
			$collision = CanonicalEventUpserter::legacy_candidate( 'setlist-fm', 'canonical-one' );
			$this->assertWPError( $collision );
			$this->assertSame( 'concert_import_identity_collision', $collision->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}

	/** The exact verified target request receives legacy event_id via set_param. */
	public function test_target_request_injects_unambiguous_legacy_event_id(): void {
		$run_id = $this->insert_running_run( $this->user_id, 'setlist-fm' );
		wp_set_current_user( $this->user_id );
		$legacy_id = $this->create_event_post();
		update_post_meta( $legacy_id, '_dm_import_source', 'setlist-fm' );
		update_post_meta( $legacy_id, '_dm_import_external_id', 'provider-17' );
		$request = $this->request( $run_id, 'setlist-fm' );
		$this->set_verified_context( $request );

		$result = CanonicalEventUpserter::prepare_target_request( null, array(), $request );

		$this->assertNull( $result );
		$this->assertSame( $legacy_id, $request->get_json_params()['input']['event_id'] );
		$this->assertTrue( CanonicalEventUpserter::filter_upsert_permission( false, $request->get_json_params()['input'] ) );
		CanonicalEventUpserter::release_target_request( null, array(), $request );
		$this->assertFalse( CanonicalEventUpserter::filter_upsert_permission( false, array() ) );
	}

	/** Elevation follows exact normalized input and rejects nested or field tampering. */
	public function test_target_elevation_is_bound_to_exact_normalized_input(): void {
		$run_id = $this->insert_running_run( $this->user_id, 'setlist-fm' );
		wp_set_current_user( $this->user_id );
		$request = $this->request( $run_id, 'setlist-fm' );
		$this->set_verified_context( $request );
		$this->assertNull( CanonicalEventUpserter::prepare_target_request( null, array(), $request ) );
		$input              = $request->get_json_params()['input'];
		$reordered          = array_reverse( $input, true );
		$reordered_event    = array_reverse( $input['event'], true );
		$reordered['event'] = $reordered_event;
		$ability            = wp_get_ability( 'data-machine-events/upsert-event' );
		$this->assertNotNull( $ability );
		$normalized = $ability->normalize_input( $reordered );
		$this->assertTrue( CanonicalEventUpserter::filter_upsert_permission( false, $normalized ) );

		$tampered_fields = array( 'source', 'source_id', 'import_run_id', 'post_author', 'post_status', 'event_id' );
		foreach ( $tampered_fields as $field ) {
			$tampered           = $input;
			$tampered[ $field ] = is_int( $input[ $field ] ?? null ) ? (int) ( $input[ $field ] ?? 0 ) + 1 : (string) ( $input[ $field ] ?? '' ) . '-tampered';
			$this->assertFalse( CanonicalEventUpserter::filter_upsert_permission( false, $tampered ), $field . ' tamper inherited elevation.' );
		}

		$nested                     = $input;
		$nested['event']['venue']   = 'Nested Different Venue';
		$nested['event']['details'] = array( 'promoter' => 'Different Promoter' );
		$this->assertFalse( CanonicalEventUpserter::filter_upsert_permission( false, $ability->normalize_input( $nested ) ) );
		CanonicalEventUpserter::release_target_request( null, array(), $request );
	}

	/**
	 * Return a complete date-only fixture.
	 *
	 * @return ExternalEvent Complete fixture.
	 */
	private function event(): ExternalEvent {
		return new ExternalEvent(
			array(
				'date'       => '2025-01-15',
				'venue_name' => 'Fixture Room',
				'city'       => 'Charleston',
				'state'      => 'SC',
				'country'    => 'US',
				'headliner'  => 'Fixture Band',
				'source_id'  => 'provider-17',
			)
		);
	}

	/**
	 * Insert a running import row and return its ID.
	 *
	 * @param int    $user_id Import owner.
	 * @param string $source  Source slug.
	 * @return int Run ID.
	 */
	private function insert_running_run( int $user_id, string $source ): int {
		global $wpdb;
		$wpdb->insert(
			extrachill_users_concert_import_runs_table_name(),
			array(
				'user_id'           => $user_id,
				'source_slug'       => $source,
				'status'            => ImportOrchestrator::STATUS_RUNNING,
				'external_username' => 'fixture-user',
				'started_at'        => current_time( 'mysql', true ),
				'updated_at'        => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Build and authorize a target request.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $source Source slug.
	 * @return mixed Preparation result.
	 */
	private function prepare_request( int $run_id, string $source ) {
		$request = $this->request( $run_id, $source );
		$this->set_verified_context( $request );
		return CanonicalEventUpserter::prepare_target_request( null, array(), $request );
	}

	/**
	 * Build one canonical ability REST request.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $source Source slug.
	 * @return WP_REST_Request Request fixture.
	 */
	private function request( int $run_id, string $source ): WP_REST_Request {
		$input                  = CanonicalEventUpserter::map_input(
			$this->event(),
			$source,
			array(
				'id'      => $run_id,
				'user_id' => $this->user_id,
			)
		);
		$input['import_run_id'] = $run_id;
		$request                = new WP_REST_Request( 'POST', CanonicalEventUpserter::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ) ) );
		return $request;
	}

	/**
	 * Attach context Network exposes only after signature verification.
	 *
	 * @param WP_REST_Request $request Request fixture.
	 */
	private function set_verified_context( WP_REST_Request $request ): void {
		$this->assertTrue( function_exists( 'ec_cross_site_set_verified_service_context' ) );
		$target_url = (string) ec_get_site_url( 'events' );
		ec_cross_site_set_verified_service_context(
			$request,
			array(
				'service_id'     => CanonicalEventUpserter::SERVICE_ID,
				'scope'          => CanonicalEventUpserter::SERVICE_SCOPE,
				'source_site_id' => (int) ec_get_blog_id( 'main' ),
				'target_site_id' => (int) ec_get_blog_id( 'events' ),
				'target_host'    => strtolower( (string) wp_parse_url( $target_url, PHP_URL_HOST ) ),
			)
		);
	}

	/** Create one published event fixture. */
	private function create_event_post(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
	}

	/** Clear shared import and attendance rows. */
	private function clear_tables(): void {
		global $wpdb;
		$tracking = extrachill_users_concert_tracking_table_name();
		$imports  = extrachill_users_concert_import_runs_table_name();
		$wpdb->query( "DELETE FROM {$tracking}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted test table helper.
		$wpdb->query( "DELETE FROM {$imports}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted test table helper.
	}
}
