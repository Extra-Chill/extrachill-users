<?php
/**
 * Team Experience Analytics Events
 *
 * Thin emit helper for team-experience + artist-funnel analytics events.
 *
 * SHARED EVENT CONTRACT (Extra-Chill/extrachill-users#127)
 * --------------------------------------------------------
 * The team-experience instrumentation spans three plugins
 * (extrachill-users, extrachill-studio, extrachill-roadie) plus the
 * artist-funnel work (extrachill-artist-platform#72). The event NAMES
 * and payload shape are a shared contract. Each plugin owns its own
 * emit helper and now defines its own event-name CONSTANTS (the names
 * it emits/reads) so a rename is mechanical within the plugin rather
 * than a scattered string edit (extrachill-users#129). Plugins use the
 * SAME event_type strings and the SAME `user_id`-in-payload convention
 * so the cohort rollups can join on the `extra_chill_team` role.
 *
 * Event types emitted by extrachill-users:
 *   - team_member_added        (ec_users_grant_team_role)
 *   - team_member_removed      (ec_users_revoke_team_role)
 *   - artist_access_requested  (request-artist-access ability)
 *   - artist_access_approved   (approve-artist-access ability)
 *
 * Event types emitted by sibling plugins (documented here for the
 * single-source contract — emitted in their own plugins):
 *   - extrachill-studio:  studio_draft_created, studio_submitted_for_review,
 *                         studio_transcription_run
 *   - extrachill-roadie:  roadie_session_started, roadie_tool_invoked
 *   - extrachill-artist-platform: artist_profile_created (sibling-owned)
 *
 * Payload convention: every event carries a `user_id` key in event_data
 * identifying the SUBJECT of the event (the user the action is about),
 * which may differ from the acting/authenticated user the analytics
 * table records in its own `user_id` column. This lets cohort rollups
 * join the subject against the `extra_chill_team` role regardless of who
 * triggered the action.
 *
 * All emits route through the existing `extrachill/track-analytics-event`
 * ability — never write the `c8c_extrachill_analytics_events` table
 * directly (RULES.md: use the ability).
 *
 * @package ExtraChill\Users
 * @since   0.18.0
 */

defined( 'ABSPATH' ) || exit;

/*
 * Event-name contract constants (Extra-Chill/extrachill-users#129).
 * ----------------------------------------------------------------
 * The analytics event_type strings are defined ONCE here and referenced
 * by every extrachill-users emit site AND the read-side stats abilities,
 * so a rename can never silently desync the emit and read halves of a
 * metric (the "permanently-zero metric, no error" failure mode).
 *
 * Scope: these constants cover ONLY the event types extrachill-users
 * itself emits and/or reads. Sibling-emitted events (studio_*, roadie_*,
 * artist_profile_created) are owned by their own plugins and are NOT
 * referenced here as cross-plugin constants — doing so would create a
 * new load-time dependency on those plugins. The team-experience reader
 * declares the sibling event names it READS as its own constants below;
 * each emitting plugin independently owns the same string as its emit
 * constant. Matching names remain a documented contract, but each side
 * now has a single in-plugin source instead of scattered literals.
 */

/** Team-membership events emitted by extrachill-users. */
const EC_USERS_EVENT_TEAM_MEMBER_ADDED   = 'team_member_added';
const EC_USERS_EVENT_TEAM_MEMBER_REMOVED = 'team_member_removed';

/** Artist-funnel events emitted by extrachill-users. */
const EC_USERS_EVENT_ARTIST_ACCESS_REQUESTED = 'artist_access_requested';
const EC_USERS_EVENT_ARTIST_ACCESS_APPROVED  = 'artist_access_approved';

/** Sibling-emitted event types that the team-experience reader READS. */
const EC_USERS_EVENT_STUDIO_DRAFT_CREATED        = 'studio_draft_created';
const EC_USERS_EVENT_STUDIO_SUBMITTED_FOR_REVIEW = 'studio_submitted_for_review';
const EC_USERS_EVENT_STUDIO_TRANSCRIPTION_RUN    = 'studio_transcription_run';
const EC_USERS_EVENT_ROADIE_SESSION_STARTED      = 'roadie_session_started';
const EC_USERS_EVENT_ROADIE_TOOL_INVOKED         = 'roadie_tool_invoked';

/**
 * Full set of event types surfaced by the team-experience cohort rollup
 * (`extrachill/get-team-experience-stats`). The reader builds its query
 * array from this group instead of re-listing string literals.
 */
const EC_USERS_TEAM_EXPERIENCE_EVENTS = array(
	EC_USERS_EVENT_TEAM_MEMBER_ADDED,
	EC_USERS_EVENT_TEAM_MEMBER_REMOVED,
	EC_USERS_EVENT_STUDIO_DRAFT_CREATED,
	EC_USERS_EVENT_STUDIO_SUBMITTED_FOR_REVIEW,
	EC_USERS_EVENT_STUDIO_TRANSCRIPTION_RUN,
	EC_USERS_EVENT_ROADIE_SESSION_STARTED,
	EC_USERS_EVENT_ROADIE_TOOL_INVOKED,
);

/**
 * Emit a team-experience analytics event via the canonical ability.
 *
 * No-op (returns 0) when the analytics ability is unavailable, so emit
 * sites never need to guard the call themselves.
 *
 * @param string $event_type Event type identifier from the shared contract.
 * @param int    $user_id    Subject user ID (the user the event is about).
 * @param array  $extra      Optional additional payload keys merged into event_data.
 * @return int Event ID on success, 0 on failure / when unavailable.
 */
function ec_users_emit_team_experience_event( $event_type, $user_id, $extra = array() ) {
	if ( empty( $event_type ) ) {
		return 0;
	}

	if ( ! function_exists( 'wp_get_ability' ) ) {
		return 0;
	}

	$ability = wp_get_ability( 'extrachill/track-analytics-event' );
	if ( ! $ability ) {
		return 0;
	}

	$event_data = array_merge(
		array( 'user_id' => (int) $user_id ),
		is_array( $extra ) ? $extra : array()
	);

	$result = $ability->execute(
		array(
			'event_type' => $event_type,
			'event_data' => $event_data,
		)
	);

	return is_int( $result ) ? $result : 0;
}
