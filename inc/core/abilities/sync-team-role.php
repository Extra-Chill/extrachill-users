<?php
/**
 * Sync Team Role Ability
 *
 * Phase 1 of Extra-Chill/extrachill-users#45.
 *
 * Reconciles the `extra_chill_team` WP role against the underlying
 * `extrachill_team` / `extrachill_team_manual_override` user_meta
 * source of truth across every site in the network.
 *
 * Two modes:
 *   - Sync a specific user (`user_id` param): one round-trip across
 *     every subsite for that user. Useful for ad-hoc fixes.
 *   - Sync everyone (no `user_id`): walks every user that currently
 *     has the team meta flag set (or a manual `add` override) AND
 *     every user that currently holds the team role somewhere (to
 *     catch removals where the meta has been cleared). Operator action
 *     — run after deploy to backfill the role across the network.
 *
 * @package ExtraChill\Users
 * @since   0.11.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_sync_team_role_ability' );

/**
 * Register the extrachill/sync-team-role ability.
 */
function extrachill_users_register_sync_team_role_ability() {
	wp_register_ability(
		'extrachill/sync-team-role',
		array(
			'label'               => __( 'Sync Team Role', 'extrachill-users' ),
			'description'         => __( 'Reconciles the extra_chill_team WP role on every subsite from the team-member user_meta source of truth. Pass a user_id to sync a single user; omit to reconcile every team member across the network.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array(
						'type'        => 'integer',
						'description' => __( 'Sync a single user. Omit to reconcile every team member across the network.', 'extrachill-users' ),
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => __( 'Compute the diff without applying any role changes.', 'extrachill-users' ),
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Sync report: per-user added/removed blog IDs and summary counts.', 'extrachill-users' ),
			),
			'execute_callback'    => 'extrachill_users_ability_sync_team_role',
			'permission_callback' => 'extrachill_users_ability_sync_team_role_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Permission callback: super-admins only.
 *
 * The reconcile loops every user across every site and writes role
 * assignments. Restrict to network admins.
 *
 * @return bool
 */
function extrachill_users_ability_sync_team_role_permission() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}
	return function_exists( 'is_super_admin' ) && is_super_admin();
}

/**
 * Execute callback: run the sync.
 *
 * @param array{user_id?: int, dry_run?: bool} $input Input parameters.
 * @return array|WP_Error Sync report.
 */
function extrachill_users_ability_sync_team_role( $input ) {
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/team-members/role.php';

	$dry_run = ! empty( $input['dry_run'] );
	$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;

	if ( $user_id > 0 ) {
		return extrachill_users_sync_team_role_single( $user_id, $dry_run );
	}

	return extrachill_users_sync_team_role_network( $dry_run );
}

/**
 * Sync a single user.
 *
 * @param int  $user_id User ID.
 * @param bool $dry_run If true, compute the would-be changes without applying them.
 * @return array|WP_Error Sync report for that user, or WP_Error if the user is unknown.
 */
function extrachill_users_sync_team_role_single( $user_id, $dry_run ) {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', sprintf( 'User %d not found.', $user_id ), array( 'status' => 404 ) );
	}

	$report = array(
		'user_id'     => $user_id,
		'user_login'  => $user->user_login,
		'should_have' => ec_users_compute_effective_team_status( $user_id ),
		'dry_run'     => $dry_run,
	);

	if ( $dry_run ) {
		$report['would_add']    = array();
		$report['would_remove'] = array();

		$site_ids = ec_users_get_network_site_ids();
		foreach ( $site_ids as $blog_id ) {
			$blog_id = (int) $blog_id;
			try {
				switch_to_blog( $blog_id );
				$site_user = new WP_User( $user_id );
				if ( ! $site_user->exists() ) {
					continue;
				}
				$has_role = in_array( EC_USERS_TEAM_ROLE, (array) $site_user->roles, true );
				if ( $report['should_have'] && ! $has_role ) {
					$report['would_add'][] = $blog_id;
				} elseif ( ! $report['should_have'] && $has_role ) {
					$report['would_remove'][] = $blog_id;
				}
			} finally {
				restore_current_blog();
			}
		}
		return $report;
	}

	$result            = ec_users_sync_team_role( $user_id );
	$report['added']   = $result['added'];
	$report['removed'] = $result['removed'];
	$report['skipped'] = $result['skipped'];

	return $report;
}

/**
 * Sync every team member across the network.
 *
 * @param bool $dry_run If true, compute would-be changes without applying them.
 * @return array Summary report.
 */
function extrachill_users_sync_team_role_network( $dry_run ) {
	$user_ids = extrachill_users_get_team_candidate_user_ids();

	$report = array(
		'dry_run'     => $dry_run,
		'total_users' => count( $user_ids ),
		'users'       => array(),
		'summary'     => array(
			'added_assignments'   => 0,
			'removed_assignments' => 0,
			'unchanged_users'     => 0,
		),
	);

	foreach ( $user_ids as $user_id ) {
		$user_report = extrachill_users_sync_team_role_single( $user_id, $dry_run );
		if ( is_wp_error( $user_report ) ) {
			continue;
		}

		if ( $dry_run ) {
			$report['summary']['added_assignments']   += count( $user_report['would_add'] );
			$report['summary']['removed_assignments'] += count( $user_report['would_remove'] );

			if ( empty( $user_report['would_add'] ) && empty( $user_report['would_remove'] ) ) {
				$report['summary']['unchanged_users'] += 1;
			}
		} else {
			$report['summary']['added_assignments']   += count( $user_report['added'] );
			$report['summary']['removed_assignments'] += count( $user_report['removed'] );

			if ( empty( $user_report['added'] ) && empty( $user_report['removed'] ) ) {
				$report['summary']['unchanged_users'] += 1;
			}
		}

		$report['users'][] = $user_report;
	}

	return $report;
}

/**
 * Compute the set of user IDs that might need a role change.
 *
 * Union of:
 *   - Users with `extrachill_team` meta = 1
 *   - Users with `extrachill_team_manual_override` meta = 'add' or 'remove'
 *   - Users who currently hold the `extra_chill_team` role on ANY subsite
 *     (catches users whose meta has been cleared but whose role hasn't been
 *     removed yet — the de-promotion path).
 *
 * @return int[]
 */
function extrachill_users_get_team_candidate_user_ids() {
	global $wpdb;

	$ids = array();

	// Users flagged as team via meta.
	$meta_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reconcile loop; no caching needed.
		"SELECT DISTINCT user_id FROM {$wpdb->usermeta}
		 WHERE ( meta_key = 'extrachill_team' AND meta_value = '1' )
		    OR ( meta_key = 'extrachill_team_manual_override' AND meta_value IN ( 'add', 'remove' ) )"
	);

	foreach ( (array) $meta_ids as $id ) {
		$ids[ (int) $id ] = true;
	}

	// Users who currently hold the role on any subsite.
	$site_ids = ec_users_get_network_site_ids();
	foreach ( $site_ids as $blog_id ) {
		$blog_id   = (int) $blog_id;
		$caps_key  = $wpdb->get_blog_prefix( $blog_id ) . 'capabilities';
		$role_meta = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix is sanitized by $wpdb->get_blog_prefix().
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
				$caps_key,
				'%' . $wpdb->esc_like( EC_USERS_TEAM_ROLE ) . '%'
			)
		);

		foreach ( (array) $role_meta as $id ) {
			$ids[ (int) $id ] = true;
		}
	}

	return array_map( 'intval', array_keys( $ids ) );
}
