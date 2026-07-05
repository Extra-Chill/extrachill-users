<?php
/**
 * Concert tracking → dated contribution events.
 *
 * The dated sibling of rank-points.php. While rank-points.php contributes the
 * SCALAR concert points (via ec_rank_extra_points), this file contributes the
 * DATED concert check-in events (via ec_contribution_events) so the heatmap
 * (extrachill-community#147) can show concert attendance on a calendar grid.
 *
 * Source: the ec_concert_tracking table, which has `created_at` (the timestamp
 * the user marked the event) indexed on (user_id, created_at). We aggregate
 * check-ins per calendar day via ec_users_get_user_dated_event_checks().
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contribute dated concert check-in events.
 *
 * Returns one aggregated row per day the user marked a concert (concert
 * check-ins are low-volume — typically dozens, not thousands — so the full
 * history is returned and the engine's defensive since-window filter handles
 * truncation).
 *
 * @param array  $events    Running event list.
 * @param int    $user_id   WordPress user ID.
 * @param string $since_ymd Inclusive start date (YYYY-MM-DD), or '' for all.
 * @return array
 */
function ec_users_concert_contribution_events( $events, $user_id, $since_ymd ) {
	unset( $since_ymd ); // Engine applies the defensive date filter; we return all history.

	if ( ! function_exists( 'ec_users_get_user_dated_event_checks' ) ) {
		return $events;
	}

	$rows = ec_users_get_user_dated_event_checks( (int) $user_id );

	foreach ( $rows as $row ) {
		$events[] = array(
			'date'  => $row['date'],
			'type'  => 'concert',
			'count' => $row['count'],
		);
	}

	return $events;
}
add_filter( 'ec_contribution_events', 'ec_users_concert_contribution_events', 10, 3 );
