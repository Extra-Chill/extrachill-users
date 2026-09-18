<?php
/**
 * Regression coverage for moderation email queue failures.
 *
 * The transport is substituted through the
 * extrachill_users_pre_send_moderation_email filter seam rather than a
 * function stub: PHPUnit process isolation is unavailable in the managed CI
 * sandbox, and the real extrachill-network ec_send_email_queued() cannot be
 * redefined in-process.
 */

class Test_Moderation_Email_Failure extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		add_filter(
			'extrachill_users_pre_send_moderation_email',
			static function ( $pre, array $args ) {
				return $GLOBALS['test_ec_send_email_queued_result'] ?? array( 'success' => true );
			},
			10,
			2
		);

		require_once dirname( __DIR__, 2 ) . '/inc/core/moderation/email.php';
	}

	protected function tearDown(): void {
		remove_all_filters( 'extrachill_users_pre_send_moderation_email' );
		unset( $GLOBALS['test_ec_send_email_queued_result'] );
		parent::tearDown();
	}

	public function test_wp_error_queue_result_returns_false_without_fatal(): void {
		$GLOBALS['test_ec_send_email_queued_result'] = new WP_Error( 'queue_unavailable' );
		$user = self::factory()->user->create_and_get();

		$this->assertFalse(
			extrachill_users_send_moderation_email(
				$user,
				array(
					'state'      => 'banned',
					'reason_key' => 'other',
				)
			)
		);
	}
}
