<?php
/**
 * Artist Dispatch eligibility and audited access lifecycle.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

const EC_USERS_ARTIST_DISPATCH_POLICY_OPTION = 'extrachill_users_artist_dispatch_policy';
const EC_USERS_ARTIST_DISPATCH_STATE_META    = 'extrachill_artist_dispatch_access';
const EC_USERS_ARTIST_DISPATCH_AUDIT_META    = 'extrachill_artist_dispatch_access_event';
const EC_USERS_ARTIST_DISPATCH_TERMS_VERSION = '2026-07-18';

/**
 * Return the disabled-by-default network policy.
 *
 * @return array<string,mixed>
 */
function ec_users_get_artist_dispatch_policy() {
	$defaults = array(
		'minimum_points'            => null,
		'minimum_account_age_days'  => 0,
		'require_onboarding'        => true,
		'require_claimed_artist'    => true,
		'require_active_moderation' => true,
		'pilot_enabled'             => false,
	);
	$stored   = get_site_option( EC_USERS_ARTIST_DISPATCH_POLICY_OPTION, array() );
	$policy   = wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );

	$policy['minimum_points']            = null === $policy['minimum_points'] || '' === $policy['minimum_points']
		? null
		: max( 0, (float) $policy['minimum_points'] );
	$policy['minimum_account_age_days']  = max( 0, (int) $policy['minimum_account_age_days'] );
	$policy['require_onboarding']        = (bool) $policy['require_onboarding'];
	$policy['require_claimed_artist']    = (bool) $policy['require_claimed_artist'];
	$policy['require_active_moderation'] = (bool) $policy['require_active_moderation'];
	$policy['pilot_enabled']             = (bool) $policy['pilot_enabled'];

	return $policy;
}

/**
 * Store operator-owned policy values.
 *
 * @param array<string,mixed> $input Policy values.
 * @return array<string,mixed>
 */
function ec_users_update_artist_dispatch_policy( array $input ) {
	$policy = ec_users_get_artist_dispatch_policy();

	if ( array_key_exists( 'minimum_points', $input ) ) {
		$policy['minimum_points'] = '' === $input['minimum_points'] || null === $input['minimum_points']
			? null
			: max( 0, (float) $input['minimum_points'] );
	}
	if ( array_key_exists( 'minimum_account_age_days', $input ) ) {
		$policy['minimum_account_age_days'] = max( 0, (int) $input['minimum_account_age_days'] );
	}
	foreach ( array( 'require_onboarding', 'require_claimed_artist', 'require_active_moderation', 'pilot_enabled' ) as $field ) {
		if ( array_key_exists( $field, $input ) ) {
			$policy[ $field ] = (bool) $input[ $field ];
		}
	}

	update_site_option( EC_USERS_ARTIST_DISPATCH_POLICY_OPTION, $policy );
	return $policy;
}

/**
 * Return published artist profiles canonically linked to a user.
 *
 * Ec_get_artists_for_user() is the network-safe read side of the Artist
 * Platform's bidirectional membership primitive. It validates IDs against
 * published artist_profile posts on the artist site.
 *
 * @param int $user_id User ID.
 * @return int[]
 */
function ec_users_get_artist_dispatch_artist_ids( $user_id ) {
	if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
		return array();
	}

	return array_values( array_unique( array_map( 'absint', ec_get_artists_for_user( (int) $user_id, false ) ) ) );
}

/**
 * Return a server-resolved artist label for administration displays.
 *
 * @param int $artist_id Artist profile ID.
 * @return string
 */
function ec_users_get_artist_dispatch_artist_label( $artist_id ) {
	$artist_id      = absint( $artist_id );
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
	if ( ! $artist_id || ! $artist_blog_id ) {
		return '';
	}

	switch_to_blog( $artist_blog_id );
	try {
		return 'artist_profile' === get_post_type( $artist_id ) ? (string) get_the_title( $artist_id ) : '';
	} finally {
		restore_current_blog();
	}
}

/**
 * Evaluate every request criterion independently.
 *
 * @param int $user_id User ID.
 * @return array<string,mixed>
 */
function ec_users_get_artist_dispatch_eligibility( $user_id ) {
	$user_id = absint( $user_id );
	$user    = get_userdata( $user_id );
	$policy  = ec_users_get_artist_dispatch_policy();
	$reasons = array();

	$points       = $user && function_exists( 'extrachill_get_user_total_points' ) ? (float) extrachill_get_user_total_points( $user_id ) : 0.0;
	$onboarding   = $user && function_exists( 'ec_is_onboarding_complete' ) ? (bool) ec_is_onboarding_complete( $user_id ) : false;
	$claimed      = $user && '1' !== (string) get_user_meta( $user_id, 'ec_unclaimed', true );
	$moderation   = $user && function_exists( 'extrachill_users_get_moderation_status' )
		? extrachill_users_get_moderation_status( $user_id )
		: array(
			'active' => false,
			'state'  => 'unknown',
		);
	$artist_ids   = $user ? ec_users_get_artist_dispatch_artist_ids( $user_id ) : array();
	$registered   = $user ? strtotime( $user->user_registered . ' UTC' ) : false;
	$account_days = $registered ? max( 0, (int) floor( ( time() - $registered ) / DAY_IN_SECONDS ) ) : 0;

	$criteria = array(
		'policy_configured' => array(
			'passed' => null !== $policy['minimum_points'],
			'value'  => null !== $policy['minimum_points'],
		),
		'pilot_enabled'     => array(
			'passed' => $policy['pilot_enabled'],
			'value'  => $policy['pilot_enabled'],
		),
		'points'            => array(
			'passed'  => null !== $policy['minimum_points'] && $points >= $policy['minimum_points'],
			'value'   => $points,
			'minimum' => $policy['minimum_points'],
		),
		'onboarding'        => array(
			'passed'   => ! $policy['require_onboarding'] || $onboarding,
			'value'    => $onboarding,
			'required' => $policy['require_onboarding'],
		),
		'account_age'       => array(
			'passed'       => $account_days >= $policy['minimum_account_age_days'],
			'value_days'   => $account_days,
			'minimum_days' => $policy['minimum_account_age_days'],
		),
		'claimed_account'   => array(
			'passed' => (bool) $claimed,
			'value'  => (bool) $claimed,
		),
		'active_moderation' => array(
			'passed'   => ! $policy['require_active_moderation'] || ! empty( $moderation['active'] ),
			'value'    => ! empty( $moderation['active'] ),
			'required' => $policy['require_active_moderation'],
			'state'    => isset( $moderation['state'] ) ? (string) $moderation['state'] : 'unknown',
		),
		'claimed_artist'    => array(
			'passed'     => ! $policy['require_claimed_artist'] || ! empty( $artist_ids ),
			'value'      => ! empty( $artist_ids ),
			'required'   => $policy['require_claimed_artist'],
			'artist_ids' => $artist_ids,
		),
	);

	$messages = array(
		'policy_configured' => __( 'The points threshold has not been configured.', 'extrachill-users' ),
		'pilot_enabled'     => __( 'The Artist Dispatch pilot is disabled.', 'extrachill-users' ),
		'points'            => __( 'The minimum points threshold has not been met.', 'extrachill-users' ),
		'onboarding'        => __( 'Account onboarding is incomplete.', 'extrachill-users' ),
		'account_age'       => __( 'The account is too new for this pilot.', 'extrachill-users' ),
		'claimed_account'   => __( 'The account must be claimed before requesting access.', 'extrachill-users' ),
		'active_moderation' => __( 'Active moderation prevents Artist Dispatch access.', 'extrachill-users' ),
		'claimed_artist'    => __( 'A managed or claimed artist profile is required.', 'extrachill-users' ),
	);
	if ( ! $user ) {
		$reasons[] = __( 'A valid account is required.', 'extrachill-users' );
	}
	foreach ( $criteria as $key => $criterion ) {
		if ( empty( $criterion['passed'] ) ) {
			$reasons[] = $messages[ $key ];
		}
	}

	return array(
		'eligible' => $user && empty( $reasons ),
		'criteria' => $criteria,
		'reasons'  => array_values( array_unique( $reasons ) ),
		'policy'   => $policy,
	);
}

/**
 * Read the current network user-meta state.
 *
 * @param int $user_id User ID.
 * @return array<string,mixed>
 */
function ec_users_get_artist_dispatch_state( $user_id ) {
	$state = get_user_meta( absint( $user_id ), EC_USERS_ARTIST_DISPATCH_STATE_META, true );
	return is_array( $state ) ? $state : array();
}

/**
 * Return a self-safe view without application copy or decision notes.
 *
 * @param int $user_id User ID.
 * @return array<string,mixed>
 */
function ec_users_get_artist_dispatch_safe_state( $user_id ) {
	$state = ec_users_get_artist_dispatch_state( $user_id );
	$safe  = array(
		'status'             => isset( $state['status'] ) ? (string) $state['status'] : 'none',
		'request_id'         => isset( $state['request_id'] ) ? (string) $state['request_id'] : '',
		'requested_at'       => isset( $state['requested_at'] ) ? (int) $state['requested_at'] : 0,
		'artist_id'          => isset( $state['artist_id'] ) ? (int) $state['artist_id'] : 0,
		'terms_acknowledged' => ! empty( $state['terms_acknowledged'] ),
		'terms_version'      => isset( $state['terms_version'] ) ? (string) $state['terms_version'] : '',
		'decided_at'         => isset( $state['decision']['decided_at'] ) ? (int) $state['decision']['decided_at'] : 0,
		'revoked_at'         => isset( $state['revocation']['revoked_at'] ) ? (int) $state['revocation']['revoked_at'] : 0,
		'eligibility'        => ec_users_get_artist_dispatch_eligibility( $user_id ),
	);
	if ( $safe['artist_id'] ) {
		$safe['artist_label'] = ec_users_get_artist_dispatch_artist_label( $safe['artist_id'] );
	}

	return $safe;
}

/**
 * Return the bounded, non-PII analytics payload for a successful request.
 *
 * @param int    $user_id       Subject user ID.
 * @param int    $artist_id     Canonical represented artist ID.
 * @param string $terms_version Accepted terms version.
 * @return array<string,mixed>
 */
function ec_users_get_artist_dispatch_requested_event_payload( $user_id, $artist_id, $terms_version ) {
	return array(
		'user_id'       => absint( $user_id ),
		'artist_id'     => absint( $artist_id ),
		'terms_version' => sanitize_text_field( $terms_version ),
		'surface'       => 'artist_dispatch',
	);
}

/**
 * Emit the successful request transition through the canonical analytics event.
 *
 * @param int    $user_id       Subject user ID.
 * @param int    $artist_id     Canonical represented artist ID.
 * @param string $terms_version Accepted terms version.
 */
function ec_users_emit_artist_dispatch_requested_event( $user_id, $artist_id, $terms_version ) {
	if ( ! defined( 'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REQUESTED' ) || ! function_exists( 'ec_users_emit_team_experience_event' ) ) {
		return;
	}

	$payload = ec_users_get_artist_dispatch_requested_event_payload( $user_id, $artist_id, $terms_version );
	unset( $payload['user_id'] );
	ec_users_emit_team_experience_event( EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REQUESTED, $user_id, $payload );
}

/**
 * Append an event once for a request UUID and transition.
 *
 * @param int    $user_id    User ID.
 * @param string $event_type Event type.
 * @param string $request_id Request UUID.
 * @param int    $actor_id   Actor user ID.
 * @param array  $details    Non-sensitive event details.
 * @return bool
 */
function ec_users_add_artist_dispatch_audit_event( $user_id, $event_type, $request_id, $actor_id, array $details = array() ) {
	foreach ( get_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_AUDIT_META, false ) as $event ) {
		if ( is_array( $event ) && ( $event['event'] ?? '' ) === $event_type && ( $event['request_id'] ?? '' ) === $request_id ) {
			return false;
		}
	}

	return false !== add_user_meta(
		$user_id,
		EC_USERS_ARTIST_DISPATCH_AUDIT_META,
		array(
			'schema_version' => 1,
			'event'          => sanitize_key( $event_type ),
			'request_id'     => sanitize_text_field( $request_id ),
			'occurred_at'    => time(),
			'actor_id'       => absint( $actor_id ),
			'details'        => $details,
		),
		false
	);
}

/**
 * Send one transition notification, marking it before delivery for retry safety.
 *
 * @param int    $user_id    Recipient.
 * @param string $event_type Transition.
 * @param int    $actor_id   Actor.
 * @return void
 */
function ec_users_maybe_notify_artist_dispatch_transition( $user_id, $event_type, $actor_id ) {
	$state = ec_users_get_artist_dispatch_state( $user_id );
	if ( ! empty( $state['notifications'][ $event_type ] ) ) {
		return;
	}

	$state['notifications'][ $event_type ] = time();
	update_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_STATE_META, $state );
	if ( ! function_exists( 'ec_users_notify' ) ) {
		return;
	}

	$titles = array(
		'approved' => __( 'Your Artist Dispatch access was approved.', 'extrachill-users' ),
		'rejected' => __( 'Your Artist Dispatch access request was not approved.', 'extrachill-users' ),
		'revoked'  => __( 'Your Artist Dispatch access was revoked.', 'extrachill-users' ),
	);
	if ( ! isset( $titles[ $event_type ] ) ) {
		return;
	}

	$blog_id = ec_users_get_artist_dispatch_blog_id();
	switch_to_blog( $blog_id );
	try {
		$link = home_url( '/submit/' );
	} finally {
		restore_current_blog();
	}

	ec_users_notify(
		$user_id,
		array(
			'actor_id' => absint( $actor_id ),
			'type'     => 'artist_dispatch_' . $event_type,
			'link'     => $link,
			'title'    => $titles[ $event_type ],
			'item_id'  => isset( $state['artist_id'] ) ? absint( $state['artist_id'] ) : 0,
		)
	);
}

/**
 * Add the bounded role on the main site without replacing existing roles.
 *
 * @param int $user_id User ID.
 * @return array|WP_Error Grant details.
 */
function ec_users_grant_artist_dispatch_role( $user_id ) {
	$user_id = absint( $user_id );
	$blog_id = ec_users_get_artist_dispatch_blog_id();
	if ( ! $user_id || ! get_userdata( $user_id ) || ! $blog_id ) {
		return new WP_Error( 'invalid_artist_dispatch_user', __( 'A valid user and main site are required.', 'extrachill-users' ) );
	}

	$membership_preexisted = is_user_member_of_blog( $user_id, $blog_id );
	ec_users_register_artist_dispatch_role_on_main();
	if ( ! $membership_preexisted ) {
		$result = add_user_to_blog( $blog_id, $user_id, EC_USERS_ARTIST_DISPATCH_ROLE );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	switch_to_blog( $blog_id );
	try {
		ec_users_register_artist_dispatch_role();
		$user = new WP_User( $user_id );
		if ( ! in_array( EC_USERS_ARTIST_DISPATCH_ROLE, (array) $user->roles, true ) ) {
			$user->add_role( EC_USERS_ARTIST_DISPATCH_ROLE );
		}
		$granted = in_array( EC_USERS_ARTIST_DISPATCH_ROLE, (array) ( new WP_User( $user_id ) )->roles, true );
	} finally {
		restore_current_blog();
	}

	if ( ! $granted ) {
		return new WP_Error( 'artist_dispatch_grant_failed', __( 'The Artist Dispatch role could not be granted.', 'extrachill-users' ) );
	}

	return array(
		'blog_id'               => $blog_id,
		'role'                  => EC_USERS_ARTIST_DISPATCH_ROLE,
		'membership_preexisted' => $membership_preexisted,
	);
}

/**
 * Remove only the product role and clean up grant-created empty membership.
 *
 * @param int  $user_id               User ID.
 * @param bool $membership_preexisted Whether membership existed before approval.
 * @return array<string,mixed>
 */
function ec_users_revoke_artist_dispatch_role( $user_id, $membership_preexisted ) {
	$user_id = absint( $user_id );
	$blog_id = ec_users_get_artist_dispatch_blog_id();
	$removed = false;
	$cleaned = false;

	if ( ! $user_id || ! $blog_id || ! is_user_member_of_blog( $user_id, $blog_id ) ) {
		return array(
			'role_removed'       => false,
			'membership_removed' => false,
		);
	}

	switch_to_blog( $blog_id );
	try {
		$user = new WP_User( $user_id );
		if ( in_array( EC_USERS_ARTIST_DISPATCH_ROLE, (array) $user->roles, true ) ) {
			$user->remove_role( EC_USERS_ARTIST_DISPATCH_ROLE );
			$removed = true;
		}
		$user             = new WP_User( $user_id );
		$remaining_access = ! empty( $user->roles ) || ! empty( array_filter( (array) $user->caps ) );
	} finally {
		restore_current_blog();
	}

	if ( ! $membership_preexisted && ! $remaining_access && is_user_member_of_blog( $user_id, $blog_id ) ) {
		$cleaned = true === remove_user_from_blog( $user_id, $blog_id );
	}

	return array(
		'role_removed'       => $removed,
		'membership_removed' => $cleaned,
	);
}

/**
 * Create or return a pending self-service request.
 *
 * @param int   $user_id User ID.
 * @param array $input   Application fields.
 * @return array|WP_Error
 */
function ec_users_request_artist_dispatch_access( $user_id, array $input ) {
	$user_id       = absint( $user_id );
	$state         = ec_users_get_artist_dispatch_state( $user_id );
	$terms_version = isset( $input['terms_version'] ) ? sanitize_text_field( (string) $input['terms_version'] ) : '';
	if ( true !== ( $input['acknowledgement'] ?? false ) ) {
		return new WP_Error( 'artist_dispatch_acknowledgement_required', __( 'You must acknowledge the Artist Dispatch terms and your affiliation disclosure.', 'extrachill-users' ), array( 'status' => 400 ) );
	}
	if ( EC_USERS_ARTIST_DISPATCH_TERMS_VERSION !== $terms_version ) {
		return new WP_Error( 'invalid_artist_dispatch_terms_version', __( 'The Artist Dispatch terms version is missing or no longer current.', 'extrachill-users' ), array( 'status' => 400 ) );
	}
	if ( 'pending' === ( $state['status'] ?? '' ) ) {
		if ( empty( $state['terms_acknowledged'] ) || ( $state['terms_version'] ?? '' ) !== $terms_version ) {
			return new WP_Error( 'artist_dispatch_terms_mismatch', __( 'The pending request is bound to a different terms acceptance.', 'extrachill-users' ), array( 'status' => 409 ) );
		}
		return $state;
	}
	if ( 'approved' === ( $state['status'] ?? '' ) ) {
		return new WP_Error( 'artist_dispatch_already_approved', __( 'Artist Dispatch access is already approved.', 'extrachill-users' ), array( 'status' => 409 ) );
	}

	$eligibility = ec_users_get_artist_dispatch_eligibility( $user_id );
	if ( empty( $eligibility['eligible'] ) ) {
		return new WP_Error(
			'artist_dispatch_ineligible',
			implode( ' ', $eligibility['reasons'] ),
			array(
				'status'      => 403,
				'eligibility' => $eligibility,
			)
		);
	}

	$artist_id = isset( $input['artist_id'] ) ? absint( $input['artist_id'] ) : 0;
	if ( ! $artist_id || ! in_array( $artist_id, $eligibility['criteria']['claimed_artist']['artist_ids'], true ) ) {
		return new WP_Error( 'invalid_artist_dispatch_artist', __( 'Select an artist profile you canonically manage or claim.', 'extrachill-users' ), array( 'status' => 403 ) );
	}

	$description = isset( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : '';
	$length      = function_exists( 'mb_strlen' ) ? mb_strlen( $description ) : strlen( $description );
	if ( $length < 50 || $length > 2000 ) {
		return new WP_Error( 'invalid_artist_dispatch_description', __( 'The proposed Dispatch description must be between 50 and 2,000 characters.', 'extrachill-users' ), array( 'status' => 400 ) );
	}

	$sample_url = isset( $input['sample_url'] ) ? esc_url_raw( (string) $input['sample_url'] ) : '';
	if ( '' !== $sample_url && ! wp_http_validate_url( $sample_url ) ) {
		return new WP_Error( 'invalid_artist_dispatch_sample_url', __( 'The sample URL must be a valid HTTP or HTTPS URL.', 'extrachill-users' ), array( 'status' => 400 ) );
	}

	$state = array(
		'schema_version'       => 1,
		'status'               => 'pending',
		'request_id'           => wp_generate_uuid4(),
		'requested_at'         => time(),
		'artist_id'            => $artist_id,
		'terms_acknowledged'   => true,
		'terms_version'        => $terms_version,
		'terms_accepted_at'    => time(),
		'description'          => $description,
		'sample_url'           => $sample_url,
		'eligibility_snapshot' => $eligibility,
	);
	update_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_STATE_META, $state );
	ec_users_add_artist_dispatch_audit_event(
		$user_id,
		'requested',
		$state['request_id'],
		$user_id,
		array(
			'artist_id'     => $artist_id,
			'terms_version' => $terms_version,
		)
	);
	ec_users_emit_artist_dispatch_requested_event( $user_id, $artist_id, $terms_version );

	return $state;
}

/**
 * Approve a matching pending request or repair its missing role on retry.
 *
 * @param int    $user_id    User ID.
 * @param string $request_id Request UUID.
 * @param string $note       Internal note.
 * @param int    $actor_id   Decision actor.
 * @return array|WP_Error
 */
function ec_users_approve_artist_dispatch_access( $user_id, $request_id, $note, $actor_id ) {
	$user_id    = absint( $user_id );
	$request_id = sanitize_text_field( $request_id );
	$state      = ec_users_get_artist_dispatch_state( $user_id );
	if ( ( $state['request_id'] ?? '' ) !== $request_id ) {
		return new WP_Error( 'artist_dispatch_request_mismatch', __( 'The request ID does not match current state.', 'extrachill-users' ), array( 'status' => 409 ) );
	}
	if ( 'approved' === ( $state['status'] ?? '' ) ) {
		$repair = ec_users_grant_artist_dispatch_role( $user_id );
		return is_wp_error( $repair ) ? $repair : $state;
	}
	if ( 'pending' !== ( $state['status'] ?? '' ) ) {
		return new WP_Error( 'artist_dispatch_not_pending', __( 'Only pending requests can be approved.', 'extrachill-users' ), array( 'status' => 409 ) );
	}

	$eligibility = ec_users_get_artist_dispatch_eligibility( $user_id );
	if ( empty( $eligibility['eligible'] ) || ! in_array( (int) $state['artist_id'], $eligibility['criteria']['claimed_artist']['artist_ids'], true ) ) {
		return new WP_Error(
			'artist_dispatch_no_longer_eligible',
			__( 'The request no longer satisfies the Artist Dispatch policy.', 'extrachill-users' ),
			array(
				'status'      => 403,
				'eligibility' => $eligibility,
			)
		);
	}

	$grant = ec_users_grant_artist_dispatch_role( $user_id );
	if ( is_wp_error( $grant ) ) {
		return $grant;
	}
	$now               = time();
	$state['status']   = 'approved';
	$state['decision'] = array(
		'decided_at' => $now,
		'actor_id'   => absint( $actor_id ),
		'note'       => sanitize_textarea_field( $note ),
	);
	$state['grant']    = array_merge(
		$grant,
		array(
			'granted_at' => $now,
			'actor_id'   => absint( $actor_id ),
		)
	);
	update_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_STATE_META, $state );
	ec_users_add_artist_dispatch_audit_event(
		$user_id,
		'approved',
		$request_id,
		$actor_id,
		array(
			'artist_id' => (int) $state['artist_id'],
			'blog_id'   => (int) $grant['blog_id'],
			'role'      => $grant['role'],
		)
	);
	ec_users_maybe_notify_artist_dispatch_transition( $user_id, 'approved', $actor_id );

	return ec_users_get_artist_dispatch_state( $user_id );
}

/**
 * Reject a matching pending request.
 *
 * @param int    $user_id    User ID.
 * @param string $request_id Request UUID.
 * @param string $reason     Rejection reason.
 * @param int    $actor_id   Decision actor.
 * @return array|WP_Error
 */
function ec_users_reject_artist_dispatch_access( $user_id, $request_id, $reason, $actor_id ) {
	$user_id    = absint( $user_id );
	$request_id = sanitize_text_field( $request_id );
	$reason     = sanitize_textarea_field( $reason );
	$state      = ec_users_get_artist_dispatch_state( $user_id );
	if ( '' === $reason ) {
		return new WP_Error( 'artist_dispatch_reason_required', __( 'A rejection reason is required.', 'extrachill-users' ), array( 'status' => 400 ) );
	}
	if ( ( $state['request_id'] ?? '' ) !== $request_id ) {
		return new WP_Error( 'artist_dispatch_request_mismatch', __( 'The request ID does not match current state.', 'extrachill-users' ), array( 'status' => 409 ) );
	}
	if ( 'rejected' === ( $state['status'] ?? '' ) ) {
		return $state;
	}
	if ( 'pending' !== ( $state['status'] ?? '' ) ) {
		return new WP_Error( 'artist_dispatch_not_pending', __( 'Only pending requests can be rejected.', 'extrachill-users' ), array( 'status' => 409 ) );
	}

	$state['status']   = 'rejected';
	$state['decision'] = array(
		'decided_at' => time(),
		'actor_id'   => absint( $actor_id ),
		'note'       => $reason,
	);
	update_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_STATE_META, $state );
	ec_users_add_artist_dispatch_audit_event( $user_id, 'rejected', $request_id, $actor_id );
	ec_users_maybe_notify_artist_dispatch_transition( $user_id, 'rejected', $actor_id );

	return ec_users_get_artist_dispatch_state( $user_id );
}

/**
 * Revoke an approved grant without touching posts or unrelated roles.
 *
 * @param int    $user_id    User ID.
 * @param string $request_id Request UUID.
 * @param string $reason     Revocation reason.
 * @param int    $actor_id   Decision actor.
 * @return array|WP_Error
 */
function ec_users_revoke_artist_dispatch_access( $user_id, $request_id, $reason, $actor_id ) {
	$user_id    = absint( $user_id );
	$request_id = sanitize_text_field( $request_id );
	$reason     = sanitize_textarea_field( $reason );
	$state      = ec_users_get_artist_dispatch_state( $user_id );
	if ( '' === $reason ) {
		return new WP_Error( 'artist_dispatch_reason_required', __( 'A revocation reason is required.', 'extrachill-users' ), array( 'status' => 400 ) );
	}
	if ( ( $state['request_id'] ?? '' ) !== $request_id ) {
		return new WP_Error( 'artist_dispatch_request_mismatch', __( 'The request ID does not match current state.', 'extrachill-users' ), array( 'status' => 409 ) );
	}
	if ( 'revoked' === ( $state['status'] ?? '' ) ) {
		return $state;
	}
	if ( 'approved' !== ( $state['status'] ?? '' ) ) {
		return new WP_Error( 'artist_dispatch_not_approved', __( 'Only approved access can be revoked.', 'extrachill-users' ), array( 'status' => 409 ) );
	}

	$membership_preexisted = ! empty( $state['grant']['membership_preexisted'] );
	$cleanup               = ec_users_revoke_artist_dispatch_role( $user_id, $membership_preexisted );
	$state['status']       = 'revoked';
	$state['revocation']   = array(
		'revoked_at' => time(),
		'actor_id'   => absint( $actor_id ),
		'reason'     => $reason,
		'cleanup'    => $cleanup,
	);
	update_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_STATE_META, $state );
	ec_users_add_artist_dispatch_audit_event( $user_id, 'revoked', $request_id, $actor_id, $cleanup );
	ec_users_maybe_notify_artist_dispatch_transition( $user_id, 'revoked', $actor_id );

	return ec_users_get_artist_dispatch_state( $user_id );
}

/**
 * Moderation integration: revoke now, never restore when moderation clears.
 *
 * @param int    $user_id  User ID.
 * @param int    $actor_id Moderation actor.
 * @param string $reason   Moderation reason.
 * @return void
 */
function ec_users_revoke_artist_dispatch_for_moderation( $user_id, $actor_id, $reason ) {
	$state = ec_users_get_artist_dispatch_state( $user_id );
	if ( 'approved' !== ( $state['status'] ?? '' ) ) {
		return;
	}
	/* translators: %s: moderation reason. */
	$revocation_reason = sprintf( __( 'Revoked by account moderation: %s', 'extrachill-users' ), sanitize_text_field( $reason ) );

	ec_users_revoke_artist_dispatch_access(
		$user_id,
		(string) $state['request_id'],
		$revocation_reason,
		$actor_id
	);
}
