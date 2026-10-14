<?php
/**
 * Regression coverage for the welcome-email fallback cron (#454).
 *
 * The hourly event was only scheduled from the activation hook, so once it
 * fell off the cron array nothing re-scheduled it and unfinished signups
 * stopped receiving welcome emails entirely. Covers the init self-heal and
 * the callback's date window — re-enabling the sweep must reach recent
 * signups only, never backfill months-old accounts.
 */

class Test_Welcome_Email_Fallback_Cron extends WP_UnitTestCase {

	/**
	 * User IDs the fallback tried to email during the current test.
	 *
	 * @var int[]
	 */
	private array $emailed_user_ids = array();

	/**
	 * Intercept the send-welcome-email ability (core's documented
	 * test-mocking seam) and record which users the fallback selects,
	 * without touching the mail layer or delivery meta.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->emailed_user_ids = array();

		add_filter( 'wp_pre_execute_ability', array( $this, 'capture_welcome_email_send' ), 10, 3 );
	}

	/**
	 * Remove the ability interception.
	 */
	protected function tearDown(): void {
		remove_filter( 'wp_pre_execute_ability', array( $this, 'capture_welcome_email_send' ), 10 );
		parent::tearDown();
	}

	/**
	 * Capture a fallback send and short-circuit with a schema-valid result.
	 *
	 * @param mixed  $pre   Short-circuit value (null to run the real callback).
	 * @param string $name  Ability name.
	 * @param array  $input Ability input.
	 * @return mixed
	 */
	public function capture_welcome_email_send( $pre, $name, $input ) {
		if ( 'extrachill/send-welcome-email' !== $name ) {
			return $pre;
		}

		$this->emailed_user_ids[] = (int) $input['user_id'];

		return true;
	}

	/**
	 * Create a signup stuck before onboarding, registered at a fixed point
	 * in the past.
	 *
	 * @param int $seconds_ago How long ago the account was registered.
	 * @return int User ID.
	 */
	private function make_stuck_signup( int $seconds_ago ): int {
		return self::factory()->user->create(
			array(
				'user_email'      => 'fallback-' . wp_generate_password( 8, false ) . '@example.com',
				'user_registered' => gmdate( 'Y-m-d H:i:s', time() - $seconds_ago ),
			)
		);
	}

	/**
	 * Mark a user as having received the welcome email already.
	 *
	 * @param int $user_id User ID.
	 */
	private function mark_already_sent( int $user_id ): void {
		update_user_meta( $user_id, 'onboarding_completed', '0' );
		update_user_meta( $user_id, 'welcome_email_sent', '1' );
	}

	/**
	 * Signups between 1 hour and 7 days old get the fallback email.
	 */
	public function test_emails_recent_incomplete_signup(): void {
		$user_id = $this->make_stuck_signup( DAY_IN_SECONDS );

		extrachill_welcome_email_fallback_callback();

		$this->assertContains( $user_id, $this->emailed_user_ids );
	}

	/**
	 * Regression for #454: the fallback must never backfill accounts that
	 * predate the outage window, so re-enabling the cron only reaches
	 * recent signups.
	 */
	public function test_skips_signups_older_than_seven_days(): void {
		$user_id = $this->make_stuck_signup( 30 * DAY_IN_SECONDS );

		extrachill_welcome_email_fallback_callback();

		$this->assertNotContains( $user_id, $this->emailed_user_ids );
	}

	/**
	 * The 1-hour grace period still applies — fresh signups wait for the
	 * inline send (or its retry) first.
	 */
	public function test_skips_signups_younger_than_one_hour(): void {
		$user_id = $this->make_stuck_signup( 10 * MINUTE_IN_SECONDS );

		extrachill_welcome_email_fallback_callback();

		$this->assertNotContains( $user_id, $this->emailed_user_ids );
	}

	/**
	 * Users who finished onboarding are out of scope for the fallback.
	 */
	public function test_skips_completed_onboarding(): void {
		$user_id = $this->make_stuck_signup( DAY_IN_SECONDS );
		update_user_meta( $user_id, 'onboarding_completed', '1' );

		extrachill_welcome_email_fallback_callback();

		$this->assertNotContains( $user_id, $this->emailed_user_ids );
	}

	/**
	 * Users who already received a welcome email are not re-emailed.
	 */
	public function test_skips_already_emailed_users(): void {
		$user_id = $this->make_stuck_signup( DAY_IN_SECONDS );
		$this->mark_already_sent( $user_id );

		extrachill_welcome_email_fallback_callback();

		$this->assertNotContains( $user_id, $this->emailed_user_ids );
	}

	/**
	 * The self-heal re-registers the event after it fell off the cron array,
	 * which is the exact outage from #454.
	 */
	public function test_self_heal_reschedules_missing_event(): void {
		if ( ! extrachill_users_welcome_email_fallback_is_owner_site() ) {
			$this->markTestSkipped( 'Current site does not own the fallback cron.' );
		}

		wp_clear_scheduled_hook( 'extrachill_welcome_email_fallback' );
		$this->assertFalse( wp_next_scheduled( 'extrachill_welcome_email_fallback' ) );

		extrachill_users_maybe_schedule_welcome_email_fallback();

		$event = wp_get_scheduled_event( 'extrachill_welcome_email_fallback' );
		$this->assertNotFalse( $event );
		$this->assertSame( 'hourly', $event->schedule );
	}

	/**
	 * Repeated self-heal runs must not stack duplicate events.
	 */
	public function test_self_heal_does_not_duplicate_event(): void {
		if ( ! extrachill_users_welcome_email_fallback_is_owner_site() ) {
			$this->markTestSkipped( 'Current site does not own the fallback cron.' );
		}

		wp_clear_scheduled_hook( 'extrachill_welcome_email_fallback' );
		extrachill_users_maybe_schedule_welcome_email_fallback();
		extrachill_users_maybe_schedule_welcome_email_fallback();

		$count = 0;
		foreach ( _get_cron_array() as $hooks ) {
			if ( isset( $hooks['extrachill_welcome_email_fallback'] ) ) {
				++$count;
			}
		}

		$this->assertSame( 1, $count );
	}

	/**
	 * Non-owner sites clear any stray schedule so the hourly sweep fires
	 * exactly once network-wide.
	 */
	public function test_non_owner_site_clears_stray_schedule(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Ownership pinning requires multisite.' );
		}

		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		try {
			if ( extrachill_users_welcome_email_fallback_is_owner_site() ) {
				$this->markTestSkipped( 'Factory blog resolved as the fallback owner site.' );
			}

			wp_schedule_event( time(), 'hourly', 'extrachill_welcome_email_fallback' );
			$this->assertNotFalse( wp_next_scheduled( 'extrachill_welcome_email_fallback' ) );

			extrachill_users_maybe_schedule_welcome_email_fallback();

			$this->assertFalse( wp_next_scheduled( 'extrachill_welcome_email_fallback' ) );
		} finally {
			restore_current_blog();
		}
	}
}
