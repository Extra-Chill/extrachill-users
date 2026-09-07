<?php
/**
 * Deterministic authentication rate-limit concurrency tests.
 *
 * @package ExtraChill\Users
 */
require_once __DIR__ . '/support/class-auth-rate-limit-test-cache.php';


class Test_Authentication_Rate_Limits extends WP_UnitTestCase {
	private const IP = '203.0.113.10';

	/** @var mixed */
	private $original_cache;

	/** @var bool */
	private $original_ext_cache;

	/** @var Auth_Rate_Limit_Test_Cache */
	private $cache;

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REMOTE_ADDR'] = self::IP;

		$this->original_cache       = $GLOBALS['wp_object_cache'];
		$this->original_ext_cache   = wp_using_ext_object_cache();
		$this->cache                = new Auth_Rate_Limit_Test_Cache();
		$GLOBALS['wp_object_cache'] = $this->cache;
		wp_using_ext_object_cache( true );

		add_filter(
			'extrachill_users_login_rate_limit_store',
			function () {
				return function ( $operation, $key ) {
					return ec_login_rate_limit_cache_operation( $operation, $key, $this->cache->now );
				};
			}
		);
		add_filter(
			'extrachill_users_password_reset_rate_limit_store',
			function () {
				return function ( $operation, $key ) {
					return ec_password_reset_rate_limit_cache_operation( $operation, $key, $this->cache->now );
				};
			}
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wp_object_cache'] = $this->original_cache;
		wp_using_ext_object_cache( $this->original_ext_cache );
		remove_all_filters( 'extrachill_users_login_rate_limit_store' );
		remove_all_filters( 'extrachill_users_password_reset_rate_limit_store' );
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	public function test_login_barrier_records_every_concurrent_failure(): void {
		$key                            = ec_get_login_attempt_key( 'person@example.com' );
		$this->cache->after_add_pattern = '_total';
		$this->cache->after_add         = static function (): void {
			for ( $attempt = 0; $attempt < 5; ++$attempt ) {
				ec_record_failed_login( 'person@example.com' );
			}
		};

		$this->assertSame( 1, ec_record_failed_login( 'person@example.com' ) );
		$this->assertSame( 6, ec_login_rate_limit_store( 'get', $key ) );
	}

	public function test_login_identity_aliases_share_canonical_counter_key(): void {
		$GLOBALS['wp_object_cache'] = $this->original_cache;
		wp_using_ext_object_cache( $this->original_ext_cache );
		$user_id                    = self::factory()->user->create(
			array(
				'user_login' => 'canonical-person',
				'user_email' => 'canonical@example.com',
			)
		);
		$login_key                  = ec_get_login_attempt_key( 'canonical-person' );
		$email_key                  = ec_get_login_attempt_key( 'canonical@example.com' );
		$GLOBALS['wp_object_cache'] = $this->cache;
		wp_using_ext_object_cache( true );

		$this->assertIsInt( $user_id );
		$this->assertSame( $login_key, $email_key );
	}

	public function test_login_admits_fifth_failure_and_blocks_sixth(): void {
		for ( $attempt = 1; $attempt <= EXTRACHILL_USERS_LOGIN_RATE_LIMIT; ++$attempt ) {
			$result = ec_rate_limit_login( new WP_Error( 'incorrect_password', 'No.' ), 'person@example.com' );
			$this->assertSame( 'incorrect_password', $result->get_error_code() );
		}

		$result = ec_rate_limit_login( new WP_Error( 'incorrect_password', 'No.' ), 'person@example.com' );
		$this->assertSame( 'ec_login_blocked', $result->get_error_code() );
	}

	public function test_failure_before_clear_is_cleared_and_failure_after_clear_survives(): void {
		ec_record_failed_login( 'person@example.com' );
		$this->cache->after_set_pattern = '_cleared';
		$this->cache->after_set         = static function (): void {
			ec_record_failed_login( 'person@example.com' );
		};

		$this->assertSame( 0, ec_clear_login_attempts( 'person@example.com' ) );
		$this->assertSame( 1, ec_login_rate_limit_store( 'get', ec_get_login_attempt_key( 'person@example.com' ) ) );
	}

	public function test_login_total_and_marker_share_bounded_fixed_window_lifetime(): void {
		ec_record_failed_login( 'person@example.com' );
		$this->assertSame( 0, ec_clear_login_attempts( 'person@example.com' ) );

		$window_key = ec_get_login_attempt_key( 'person@example.com' ) . '_window_' . intdiv( $this->cache->now, EXTRACHILL_USERS_LOGIN_RATE_WINDOW );
		$this->assertSame( 2 * EXTRACHILL_USERS_LOGIN_RATE_WINDOW, $this->cache->ttl( $window_key . '_total', EXTRACHILL_USERS_LOGIN_CACHE_GROUP ) );
		$this->assertSame( 2 * EXTRACHILL_USERS_LOGIN_RATE_WINDOW, $this->cache->ttl( $window_key . '_cleared', EXTRACHILL_USERS_LOGIN_CACHE_GROUP ) );
		$this->assertSame( -2, $this->cache->ttl( ec_get_login_attempt_key( 'person@example.com' ) . '_generation', EXTRACHILL_USERS_LOGIN_CACHE_GROUP ) );
	}

	public function test_login_add_loser_cannot_create_ttl_less_key_at_boundary(): void {
		$window_end                     = ( intdiv( $this->cache->now, EXTRACHILL_USERS_LOGIN_RATE_WINDOW ) + 1 ) * EXTRACHILL_USERS_LOGIN_RATE_WINDOW;
		$this->cache->now               = $window_end - 1;
		$this->cache->after_add_pattern = '_total';
		$this->cache->after_add         = static function (): void {
			ec_record_failed_login( 'person@example.com' );
		};

		ec_record_failed_login( 'person@example.com' );
		$old_total_key     = ec_get_login_attempt_key( 'person@example.com' ) . '_window_' . intdiv( $this->cache->now, EXTRACHILL_USERS_LOGIN_RATE_WINDOW ) . '_total';
		$this->cache->now += 2;

		$this->assertSame( ( 2 * EXTRACHILL_USERS_LOGIN_RATE_WINDOW ) - 2, $this->cache->ttl( $old_total_key, EXTRACHILL_USERS_LOGIN_CACHE_GROUP ) );
		$this->assertSame( 0, ec_login_rate_limit_store( 'get', ec_get_login_attempt_key( 'person@example.com' ) ) );
	}

	public function test_login_fixed_window_rollover_ignores_stale_clear_marker(): void {
		ec_record_failed_login( 'person@example.com' );
		ec_record_failed_login( 'person@example.com' );
		ec_clear_login_attempts( 'person@example.com' );
		ec_record_failed_login( 'person@example.com' );
		$this->assertSame( 1, ec_login_rate_limit_store( 'get', ec_get_login_attempt_key( 'person@example.com' ) ) );

		$this->cache->now = ( intdiv( $this->cache->now, EXTRACHILL_USERS_LOGIN_RATE_WINDOW ) + 1 ) * EXTRACHILL_USERS_LOGIN_RATE_WINDOW;
		$this->assertSame( 0, ec_login_rate_limit_store( 'get', ec_get_login_attempt_key( 'person@example.com' ) ) );
		$this->assertSame( 1, ec_record_failed_login( 'person@example.com' ) );
	}

	public function test_login_counter_expires_after_original_window(): void {
		ec_record_failed_login( 'person@example.com' );
		$this->cache->now += EXTRACHILL_USERS_LOGIN_RATE_WINDOW + 1;

		$this->assertFalse( ec_is_login_blocked( 'person@example.com' ) );
		$this->assertSame( 0, ec_login_rate_limit_store( 'get', ec_get_login_attempt_key( 'person@example.com' ) ) );
	}

	public function test_login_storage_failure_fails_closed(): void {
		$this->cache->fail = true;

		$this->assertWPError( ec_record_failed_login( 'person@example.com' ) );
		$this->assertTrue( ec_is_login_blocked( 'person@example.com' ) );
		$this->assertSame(
			'ec_login_limiter_unavailable',
			ec_rate_limit_login( new WP_Error( 'incorrect_password', 'No.' ), 'person@example.com' )->get_error_code()
		);
	}

	public function test_password_reset_barrier_records_every_concurrent_attempt(): void {
		$this->cache->after_add_pattern = 'ec_password_reset_attempts_';
		$this->cache->after_add         = static function (): void {
			for ( $attempt = 0; $attempt < 5; ++$attempt ) {
				ec_record_password_reset_attempt();
			}
		};

		$this->assertSame( 1, ec_record_password_reset_attempt() );
		$this->assertSame( 6, ec_password_reset_rate_limit_store( 'get', ec_get_password_reset_attempt_key() ) );
	}

	public function test_password_reset_add_loser_cannot_create_ttl_less_key_at_boundary(): void {
		$window_end                     = ( intdiv( $this->cache->now, EXTRACHILL_USERS_PASSWORD_RESET_RATE_WINDOW ) + 1 ) * EXTRACHILL_USERS_PASSWORD_RESET_RATE_WINDOW;
		$this->cache->now               = $window_end - 1;
		$this->cache->after_add_pattern = 'ec_password_reset_attempts_';
		$this->cache->after_add         = static function (): void {
			ec_record_password_reset_attempt();
		};

		ec_record_password_reset_attempt();
		$old_key           = ec_get_password_reset_attempt_key() . '_window_' . intdiv( $this->cache->now, EXTRACHILL_USERS_PASSWORD_RESET_RATE_WINDOW );
		$this->cache->now += 2;

		$this->assertSame( ( 2 * EXTRACHILL_USERS_PASSWORD_RESET_RATE_WINDOW ) - 2, $this->cache->ttl( $old_key, EXTRACHILL_USERS_PASSWORD_RESET_CACHE_GROUP ) );
		$this->assertSame( 0, ec_password_reset_rate_limit_store( 'get', ec_get_password_reset_attempt_key() ) );
	}

	public function test_password_reset_fifth_and_sixth_boundary_is_exact(): void {
		for ( $attempt = 1; $attempt <= EXTRACHILL_USERS_PASSWORD_RESET_RATE_LIMIT; ++$attempt ) {
			$this->assertSame( $attempt, ec_record_password_reset_attempt() );
		}

		$this->assertTrue( ec_is_password_reset_blocked() );
		$this->assertSame( 6, ec_record_password_reset_attempt() );
	}

	public function test_password_reset_counter_expires_after_original_window(): void {
		ec_record_password_reset_attempt();
		$this->cache->now += EXTRACHILL_USERS_PASSWORD_RESET_RATE_WINDOW + 1;

		$this->assertFalse( ec_is_password_reset_blocked() );
	}

	public function test_password_reset_storage_failure_fails_closed(): void {
		$this->cache->fail = true;

		$this->assertWPError( ec_record_password_reset_attempt() );
		$this->assertTrue( ec_is_password_reset_blocked() );
	}

	public function test_missing_request_ip_fails_closed_without_raw_key_data(): void {
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertSame( '', ec_get_login_attempt_key( 'person@example.com' ) );
		$this->assertSame( '', ec_get_password_reset_attempt_key() );
		$this->assertTrue( ec_is_login_blocked( 'person@example.com' ) );
		$this->assertTrue( ec_is_password_reset_blocked() );
	}

	public function test_rate_limit_groups_remain_site_scoped_on_multisite(): void {
		ec_record_failed_login( 'person@example.com' );
		ec_record_password_reset_attempt();
		$this->cache->switch_to_blog( 2 );

		$this->assertSame( 0, ec_login_rate_limit_store( 'get', ec_get_login_attempt_key( 'person@example.com' ) ) );
		$this->assertSame( 0, ec_password_reset_rate_limit_store( 'get', ec_get_password_reset_attempt_key() ) );
	}
}
