<?php
/**
 * Tests for attendance failures during concert imports.
 *
 * @package ExtraChill\Users
 */

use ExtraChill\Users\Concert_Import\ExternalEvent;
use ExtraChill\Users\Concert_Import\ImportOrchestrator;
use ExtraChill\Users\Concert_Import\ImportSource;

/**
 * Verifies import runs truthfully expose rejected attendance writes.
 */
class Test_Concert_Import_Attendance_Validation extends WP_UnitTestCase {

	/**
	 * Canonical Events site fixture ID.
	 *
	 * @var int
	 */
	private int $events_blog_id;

	/**
	 * Authenticated import user ID.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Controlled external event fixture.
	 *
	 * @var ExternalEvent
	 */
	private ExternalEvent $external_event;

	/**
	 * Target event returned by the mocked Events runtime.
	 *
	 * @var int
	 */
	private int $target_event_id = 0;

	/**
	 * Action returned by the mocked Events runtime.
	 *
	 * @var string
	 */
	private string $target_action = 'no_change';

	/**
	 * Number of upcoming mocked target writes to reject.
	 *
	 * @var int
	 */
	private int $target_write_errors_remaining = 0;

	/**
	 * Number of target HTTP requests observed.
	 *
	 * @var int
	 */
	private int $target_requests = 0;

	/**
	 * Additional records returned on the fixture page.
	 *
	 * @var ExternalEvent[]
	 */
	private array $additional_events = array();

	/**
	 * Captured Data Machine logs.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $logs = array();

	/**
	 * Create import tables, source, user, and Events site fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->events_blog_id = self::factory()->blog->create();
		$this->user_id        = self::factory()->user->create();
		$this->external_event = new ExternalEvent(
			array(
				'date'       => '2025-01-15',
				'venue_name' => 'Fixture Room',
				'headliner'  => 'Fixture Band',
				'source_id'  => 'draft-external-id',
			)
		);

		wp_set_current_user( $this->user_id );
		add_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		add_filter( 'extrachill_concert_import_sources', array( $this, 'replace_sources' ), 999 );
		add_filter( 'extrachill_users_concert_import_service_assertion_keys', array( $this, 'assertion_keys' ) );
		add_filter( 'extrachill_users_concert_import_service_assertion_active_key_id', array( $this, 'active_key_id' ) );
		add_filter( 'pre_http_request', array( $this, 'mock_event_upsert' ), 10, 3 );
		add_action( 'datamachine_log', array( $this, 'capture_log' ), 10, 4 );
		if ( ! post_type_exists( 'data_machine_events' ) ) {
			register_post_type( 'data_machine_events', array( 'public' => true ) );
		}

		extrachill_users_install_concert_tracking_table();
		extrachill_users_install_concert_import_runs_table();
		$this->clear_tables();
	}

	/**
	 * Remove shared rows and test filters.
	 */
	protected function tearDown(): void {
		$this->clear_tables();
		remove_filter( 'extrachill_concert_import_sources', array( $this, 'replace_sources' ), 999 );
		remove_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		remove_filter( 'extrachill_users_concert_import_service_assertion_keys', array( $this, 'assertion_keys' ) );
		remove_filter( 'extrachill_users_concert_import_service_assertion_active_key_id', array( $this, 'active_key_id' ) );
		remove_filter( 'pre_http_request', array( $this, 'mock_event_upsert' ), 10 );
		remove_action( 'datamachine_log', array( $this, 'capture_log' ), 10 );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Return the Events site fixture ID.
	 */
	public function filter_events_blog_id(): int {
		return $this->events_blog_id;
	}

	/** Return test assertion keys. */
	public function assertion_keys(): array {
		return array( 'test-key' => str_repeat( 'k', 32 ) );
	}

	/** Return the active test assertion key. */
	public function active_key_id(): string {
		return 'test-key';
	}

	/**
	 * Capture bounded importer diagnostics.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Bounded context.
	 */
	public function capture_log( string $level, string $message, array $context ): void {
		$this->logs[] = compact( 'level', 'message', 'context' );
	}

	/**
	 * Return a controlled canonical upsert response.
	 *
	 * @param mixed  $pre  Existing preflight result.
	 * @param array  $args HTTP arguments.
	 * @param string $url  Target URL.
	 * @return array<string, mixed>
	 */
	public function mock_event_upsert( $pre, array $args, string $url ): array {
		unset( $pre, $url );
		++$this->target_requests;
		$this->assertSame( (string) $this->user_id, $args['headers']['X-EC-Internal-User'] ?? '' );
		if ( 0 < $this->target_write_errors_remaining ) {
			--$this->target_write_errors_remaining;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'code'    => 'fixture_event_write_failed',
						'message' => 'Fixture event write failed.',
					)
				),
				'response' => array(
					'code'    => 500,
					'message' => 'Server Error',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'success'  => true,
					'event_id' => $this->target_event_id,
					'action'   => $this->target_action,
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Replace production sources with the controlled source.
	 *
	 * @param array $sources Registered sources.
	 * @return ImportSource[]
	 */
	public function replace_sources( array $sources ): array {
		$events = array_merge( array( $this->external_event ), $this->additional_events );
		return array(
			new class( $events ) implements ImportSource {

				/**
				 * Events returned by the source.
				 *
				 * @var ExternalEvent[]
				 */
				private array $events;

				/**
				 * Initialize the controlled source.
				 *
				 * @param ExternalEvent[] $events Event fixtures.
				 */
				public function __construct( array $events ) {
					$this->events = $events;
				}

				/** Return the fixture source slug. */
				public function slug(): string {
					return 'attendance-validation';
				}

				/** Return the fixture source label. */
				public function label(): string {
					return 'Attendance Validation';
				}

				/** Return an unrestricted rate limit. */
				public function rate_limit(): array {
					return array();
				}

				/** Report that the fixture is configured. */
				public function is_configured(): bool {
					return true;
				}

				/**
				 * Return a successful preview.
				 *
				 * @param string $username External username.
				 * @return array<string, mixed>
				 */
				public function preview( string $username ) {
					return array(
						'total'    => count( $this->events ),
						'username' => $username,
					);
				}

				/**
				 * Return the controlled event page.
				 *
				 * @param string $username External username.
				 * @param int    $page Page number.
				 * @return array<string, mixed>
				 */
				public function fetch_page( string $username, int $page ) {
					return array(
						'events'      => $this->events,
						'total_pages' => 1,
						'total'       => count( $this->events ),
						'page'        => $page,
					);
				}
			},
		);
	}

	/**
	 * An unpublished external-ID match fails instead of claiming attendance.
	 */
	public function test_unpublished_external_id_match_fails_run_truthfully(): void {
		$original_blog_id = get_current_blog_id();
		$event_id         = $this->create_imported_event( 'draft' );
		$start            = ImportOrchestrator::start( $this->user_id, 'attendance-validation', 'fixture-user' );
		$this->assertFalse( is_wp_error( $start ) );

		ImportOrchestrator::process( (int) $start['run_id'] );

		$this->assertSame( $original_blog_id, get_current_blog_id() );
		$this->assertFalse( ec_users_is_event_marked( $this->user_id, $event_id, $this->events_blog_id ) );

		$status_ability = wp_get_ability( 'extrachill/get-concert-import-status' );
		$this->assertNotNull( $status_ability );
		$status = $status_ability->execute( array( 'limit' => 1 ) );
		$run    = $status['runs'][0];

		$this->assertSame( ImportOrchestrator::STATUS_FAILED, $run['status'] );
		$this->assertSame( 1, $run['total_events_seen'] );
		$this->assertSame( 0, $run['total_events_matched'] );
		$this->assertSame( 0, $run['total_events_created'] );
		$this->assertSame( 1, $run['total_events_unmatched'] );
		$this->assertStringContainsString( 'event_not_published', $run['error_message'] );
		$this->assertStringContainsString( $this->external_event->label(), $run['error_message'] );
		$this->assertStringStartsWith( 'Attendance import rejected', $run['error_message'] );
	}

	/** A create and replay use canonical action counters and one attendance row. */
	public function test_create_and_replay_preserve_attendance_flow(): void {
		$event_id = $this->create_imported_event( 'publish' );

		$this->target_action = 'created';
		$first               = ImportOrchestrator::start( $this->user_id, 'attendance-validation', 'fixture-user' );
		$this->assertFalse( is_wp_error( $first ) );

		wp_set_current_user( 0 );
		ImportOrchestrator::process( (int) $first['run_id'] );
		$first_run = ImportOrchestrator::get_run( (int) $first['run_id'] );
		$this->assertSame( ImportOrchestrator::STATUS_COMPLETE, $first_run['status'] );
		$this->assertSame( 1, (int) $first_run['total_events_created'] );
		$this->assertTrue( ec_users_is_event_marked( $this->user_id, $event_id, $this->events_blog_id ) );

		$this->target_action = 'no_change';
		$second              = ImportOrchestrator::start( $this->user_id, 'attendance-validation', 'fixture-user' );
		$this->assertFalse( is_wp_error( $second ) );
		ImportOrchestrator::process( (int) $second['run_id'] );
		$second_run = ImportOrchestrator::get_run( (int) $second['run_id'] );
		$this->assertSame( ImportOrchestrator::STATUS_COMPLETE, $second_run['status'] );
		$this->assertSame( 1, (int) $second_run['total_events_matched'] );
		$this->assertSame( 0, (int) $second_run['total_events_created'] );
		$this->assertTrue( ec_users_is_event_marked( $this->user_id, $event_id, $this->events_blog_id ) );
	}

	/** Missing provider IDs are unmatched while later valid records still process. */
	public function test_missing_source_id_is_unmatched_and_later_record_processes(): void {
		$event_id = $this->create_imported_event( 'publish' );

		$this->external_event->source_id = '';
		$this->additional_events[]       = $this->external_event( 'later-valid-id', 'Later Valid Band' );

		$start = ImportOrchestrator::start( $this->user_id, 'attendance-validation', 'fixture-user' );
		$this->assertFalse( is_wp_error( $start ) );

		wp_set_current_user( 0 );
		ImportOrchestrator::process( (int) $start['run_id'] );
		$run = ImportOrchestrator::get_run( (int) $start['run_id'] );
		$this->assertSame( ImportOrchestrator::STATUS_COMPLETE, $run['status'] );
		$this->assertSame( 2, (int) $run['total_events_seen'] );
		$this->assertSame( 1, (int) $run['total_events_unmatched'] );
		$this->assertSame( 1, (int) $run['total_events_matched'] );
		$this->assertSame( 0, (int) $run['total_events_skipped'] );
		$this->assertSame( 1, $this->target_requests );
		$this->assertTrue( ec_users_is_event_marked( $this->user_id, $event_id, $this->events_blog_id ) );
		$this->assertSame( 'warning', $this->logs[0]['level'] );
		$this->assertSame( 'Concert import event missing provider source ID', $this->logs[0]['message'] );
		$this->assertSame( '', $this->logs[0]['context']['source_id'] );
	}

	/** Canonical write errors are unmatched while later records still process. */
	public function test_event_write_error_continues_remaining_records(): void {
		$event_id                            = $this->create_imported_event( 'publish' );
		$this->target_write_errors_remaining = 1;
		$this->additional_events[]           = $this->external_event( 'later-valid-id', 'Later Valid Band' );

		$start = ImportOrchestrator::start( $this->user_id, 'attendance-validation', 'fixture-user' );
		$this->assertFalse( is_wp_error( $start ) );

		wp_set_current_user( 0 );
		ImportOrchestrator::process( (int) $start['run_id'] );
		$run = ImportOrchestrator::get_run( (int) $start['run_id'] );
		$this->assertSame( ImportOrchestrator::STATUS_COMPLETE, $run['status'] );
		$this->assertSame( 2, (int) $run['total_events_seen'] );
		$this->assertSame( 1, (int) $run['total_events_unmatched'] );
		$this->assertSame( 1, (int) $run['total_events_matched'] );
		$this->assertEmpty( $run['error_message'] );
		$this->assertSame( 2, $this->target_requests );
		$this->assertTrue( ec_users_is_event_marked( $this->user_id, $event_id, $this->events_blog_id ) );
		$this->assertSame( 'error', $this->logs[0]['level'] );
		$this->assertSame( 'Concert import canonical event write failed', $this->logs[0]['message'] );
		$this->assertSame( 'draft-external-id', $this->logs[0]['context']['source_id'] );
		$this->assertSame( 'fixture_event_write_failed', $this->logs[0]['context']['error_code'] );
	}

	/**
	 * Build another complete provider event fixture.
	 *
	 * @param string $source_id Provider source ID.
	 * @param string $headliner Performer name.
	 * @return ExternalEvent Complete fixture.
	 */
	private function external_event( string $source_id, string $headliner ): ExternalEvent {
		return new ExternalEvent(
			array(
				'date'       => '2025-01-16',
				'venue_name' => 'Fixture Room',
				'headliner'  => $headliner,
				'source_id'  => $source_id,
			)
		);
	}

	/**
	 * Create an event carrying the source's stable import identifiers.
	 *
	 * @param string $status Event post status.
	 * @return int
	 */
	private function create_imported_event( string $status ): int {
		switch_to_blog( $this->events_blog_id );
		try {
			$this->target_event_id = self::factory()->post->create(
				array(
					'post_type'   => 'data_machine_events',
					'post_status' => $status,
				)
			);
			return $this->target_event_id;
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Clear network import and attendance tables between tests.
	 */
	private function clear_tables(): void {
		global $wpdb;
		$tracking_table = extrachill_users_concert_tracking_table_name();
		$imports_table  = extrachill_users_concert_import_runs_table_name();
		$wpdb->query( "DELETE FROM {$tracking_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
		$wpdb->query( "DELETE FROM {$imports_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
	}
}
