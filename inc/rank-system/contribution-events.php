<?php
/**
 * Dated Contribution Events — the temporal projection of point sources.
 *
 * While `extrachill_get_user_total_points()` computes a SCALAR total, this seam
 * exposes the same engagement data as DATED events — one record per
 * day+type+count — so a consumer (e.g. the contribution heatmap,
 * extrachill-community#147) can bucket activity per calendar day.
 *
 * Sources that bear a timestamp hook `ec_contribution_events` and return
 * aggregated per-day rows. Sources without a timestamp trail (scalar counters
 * that have no per-day history) are intentionally excluded — they cannot be
 * dated without a new table, which is out of scope.
 *
 * Event shape (aggregated for efficiency):
 *   array( 'date' => 'YYYY-MM-DD', 'type' => '<source-key>', 'count' => int )
 *
 * Aggregation rationale: a prolific author may have thousands of contributions;
 * returning one row per contribution would make the payload and the per-day
 * GROUP BY prohibitively large. Contributors aggregate server-side (SQL
 * GROUP BY DATE(...)) and return one row per day+type. The consumer sums
 * `count` per date.
 *
 * Timezone: dates are calendar days in the site timezone
 * (wp_timezone() / America/New_York) so day boundaries match how users
 * perceive "today."
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a user's dated contribution events on or after a date.
 *
 * Applies the `ec_contribution_events` filter and returns the merged,
 * normalized event list from all sources.
 *
 * @param int    $user_id   WordPress user ID.
 * @param string $since_ymd Inclusive start date (YYYY-MM-DD, site timezone).
 *                          Events on or after this date are returned.
 *                          Empty string = no lower bound (all history).
 * @return array<int, array{date:string,type:string,count:int}>
 */
function ec_get_contribution_events( $user_id, $since_ymd = '' ) {
	/**
	 * Contribute dated engagement events.
	 *
	 * Each source returns an array of aggregated rows:
	 *   array( 'date' => 'YYYY-MM-DD', 'type' => string, 'count' => int )
	 *
	 * `date` is a calendar day in the site timezone. `type` is an opaque
	 * source identifier (the engine does not inspect it). `count` is the
	 * number of contributions of that type on that date.
	 *
	 * Sources without per-day timestamps (e.g. scalar counters) MUST NOT
	 * participate — there is no honest date to assign.
	 *
	 * @param array  $events    Running event list.
	 * @param int    $user_id   WordPress user ID.
	 * @param string $since_ymd Inclusive start date (YYYY-MM-DD), or '' for all.
	 */
	$events = (array) apply_filters( 'ec_contribution_events', array(), (int) $user_id, (string) $since_ymd );

	// Normalize: enforce shape, apply defensive date-window filter.
	$clean = array();
	foreach ( $events as $event ) {
		if ( ! is_array( $event ) ) {
			continue;
		}

		$date  = isset( $event['date'] ) ? (string) $event['date'] : '';
		$count = isset( $event['count'] ) ? (int) $event['count'] : 1;
		$type  = isset( $event['type'] ) ? (string) $event['type'] : '';

		if ( '' === $date || $count < 1 ) {
			continue;
		}

		// Respect the since-window when provided (defensive backstop —
		// contributors should also SQL-filter for efficiency).
		if ( '' !== $since_ymd && $date < $since_ymd ) {
			continue;
		}

		$clean[] = array(
			'date'  => $date,
			'type'  => $type,
			'count' => $count,
		);
	}

	return $clean;
}

/**
 * Built-in dated source: main-site published posts.
 *
 * Contributes one dated event per day the user published a `post` on the main
 * site. Network-level source owned by the engine (mirrors the scalar
 * `main_posts` source in points-engine.php). Hooks `ec_contribution_events`.
 *
 * @param array  $events    Running event list.
 * @param int    $user_id   WordPress user ID.
 * @param string $since_ymd Inclusive start date (YYYY-MM-DD), or '' for all.
 * @return array
 */
function ec_users_main_posts_contribution_events( $events, $user_id, $since_ymd ) {
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
	if ( ! $main_blog_id ) {
		return $events;
	}

	global $wpdb;

	switch_to_blog( $main_blog_id );
	try {
		// Aggregate published posts per calendar day for this author.
		// DATE(post_date) yields site-local calendar days (WP stores post_date
		// in site-local time for posts created via the WP API).
		if ( '' !== $since_ymd ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no interpolation; prepared statement.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DATE(post_date) AS d, COUNT(*) AS c
					 FROM {$wpdb->posts}
					 WHERE post_author = %d AND post_type = 'post' AND post_status = 'publish'
					   AND DATE(post_date) >= %s
					 GROUP BY d",
					$user_id,
					$since_ymd
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DATE(post_date) AS d, COUNT(*) AS c
					 FROM {$wpdb->posts}
					 WHERE post_author = %d AND post_type = 'post' AND post_status = 'publish'
					 GROUP BY d",
					$user_id
				),
				ARRAY_A
			);
		}
	} finally {
		restore_current_blog();
	}

	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$events[] = array(
				'date'  => (string) $row['d'],
				'type'  => 'post',
				'count' => (int) $row['c'],
			);
		}
	}

	return $events;
}
add_filter( 'ec_contribution_events', 'ec_users_main_posts_contribution_events', 10, 3 );
