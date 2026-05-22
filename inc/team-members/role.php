<?php
/**
 * Team Member WP Role + Capability Sync
 *
 * Phase 1 of Extra-Chill/extrachill-users#45.
 *
 * Maintains a first-class `extra_chill_team` WP role on every site in
 * the network, kept in sync with the existing `extrachill_team` +
 * `extrachill_team_manual_override` user_meta source of truth.
 *
 * Why this exists:
 *   The legacy `ec_is_team_member()` function is a parallel access-control
 *   system with zero WP capability semantics. Native WP code paths
 *   (Gutenberg, REST permission callbacks, the media library,
 *   wp_handle_upload, third-party plugins) all check capabilities, not
 *   our custom function. The result: a "team member" with no role on a
 *   subsite passes `ec_is_team_member()` but fails `current_user_can(
 *   'upload_files' )`, producing silent 500s from Gutenberg image
 *   uploads. Granting a real role with real caps closes that gap
 *   structurally — every native cap-aware code path automatically
 *   respects team membership.
 *
 * Design:
 *   - Source of truth stays as `extrachill_team` + manual_override meta.
 *     No data migration; no breakage in whatever admin process sets
 *     those flags today.
 *   - The role is *derived* from the meta. Meta change → role sync.
 *   - The role is registered on every site in the network (existing +
 *     newly-created via wp_initialize_site).
 *   - The role is added alongside any existing per-site role, not as
 *     a replacement. Manual per-site grants (e.g. someone manually
 *     promoted to `editor` on a single subsite) coexist with the team
 *     role. Effective caps are the union.
 *   - Super-admins are skipped — they already have all caps via the
 *     multisite cap layer.
 *
 * @package ExtraChill\Users
 * @since 0.11.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Role slug for the Extra Chill team WP role.
 */
const EC_USERS_TEAM_ROLE = 'extra_chill_team';

/**
 * Returns the capability set granted by the team role.
 *
 * Split into two groups:
 *
 *   1. Standard WP caps the team needs everywhere. These are the caps
 *      Gutenberg / REST / media library look for. Granting them via the
 *      role is what closes the silent-500 gap.
 *
 *   2. Custom EC team-only capabilities. These are the WP-native
 *      replacement for function-based `ec_is_team_member()` gates. Each
 *      one names a specific team-only surface (Studio, Roadie chat,
 *      Transcribe tokens, Events admin, the wp-admin admin bar).
 *
 * Explicitly NOT granted: manage_options, delete_others_posts,
 * edit_users, plugin/theme management. The team role is for music
 * journalists, not site administrators.
 *
 * @return array<string,bool> Capability map suitable for add_role().
 */
function ec_users_get_team_role_caps() {
	return array(
		// --- Standard WP caps ---
		'read'                 => true,
		'upload_files'         => true,
		'edit_posts'           => true,
		'edit_published_posts' => true,
		'edit_others_posts'    => true,
		'delete_posts'         => true,

		// --- Custom EC team-only caps ---
		'access_studio'        => true,
		'access_roadie'        => true,
		'access_transcribe'    => true,
		'access_events_admin'  => true,
		'access_admin_bar'     => true,
		'submit_for_review'    => true,
	);
}

/**
 * Returns the human-readable label for the team role.
 *
 * @return string
 */
function ec_users_get_team_role_label() {
	return __( 'Extra Chill Team', 'extrachill-users' );
}

/**
 * Register the team role on the current site.
 *
 * Safe to call repeatedly: add_role() is a no-op if the role already
 * exists, and we re-call add_role with the current cap set so deploys
 * that change the cap surface are picked up automatically.
 *
 * @return void
 */
function ec_users_register_team_role() {
	$caps = ec_users_get_team_role_caps();

	// add_role() returns null if the role already exists. To pick up
	// any capability additions/removals across deploys, remove and
	// re-add when the cap set has drifted.
	$existing = get_role( EC_USERS_TEAM_ROLE );
	if ( $existing instanceof WP_Role ) {
		$existing_caps = array_filter( $existing->capabilities );

		// Normalize for strict comparison: ksort both sides so key order
		// doesn't produce a false-positive diff.
		ksort( $existing_caps );
		$desired_caps = $caps;
		ksort( $desired_caps );

		if ( $existing_caps !== $desired_caps ) {
			remove_role( EC_USERS_TEAM_ROLE );
			add_role( EC_USERS_TEAM_ROLE, ec_users_get_team_role_label(), $caps );
		}
		return;
	}

	add_role( EC_USERS_TEAM_ROLE, ec_users_get_team_role_label(), $caps );
}

/**
 * Register the team role on every site in the network.
 *
 * @return void
 */
function ec_users_register_team_role_network_wide() {
	$site_ids = ec_users_get_network_site_ids();

	foreach ( $site_ids as $blog_id ) {
		try {
			switch_to_blog( (int) $blog_id );
			ec_users_register_team_role();
		} finally {
			restore_current_blog();
		}
	}
}

/**
 * Resolve the list of blog IDs to operate on.
 *
 * Prefers ec_get_all_site_ids() (active sites only), falls back to
 * ec_get_blog_ids() (the static map), then WP core get_sites().
 *
 * @return int[]
 */
function ec_users_get_network_site_ids() {
	if ( function_exists( 'ec_get_all_site_ids' ) ) {
		return array_map( 'intval', ec_get_all_site_ids() );
	}

	if ( function_exists( 'ec_get_blog_ids' ) ) {
		return array_map( 'intval', array_values( ec_get_blog_ids() ) );
	}

	return array_map( 'intval', get_sites( array( 'fields' => 'ids' ) ) );
}

/**
 * Compute the effective team-member status for a user from source meta.
 *
 * Same semantics as the legacy ec_is_team_member() function — manual
 * override wins, otherwise the `extrachill_team` flag decides. Lives
 * here as a pure helper so the role-sync code does not depend on the
 * shimmed ec_is_team_member() (which will eventually delegate back into
 * the cap system).
 *
 * @param int $user_id User ID.
 * @return bool
 */
function ec_users_compute_effective_team_status( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return false;
	}

	$manual_override = get_user_meta( $user_id, 'extrachill_team_manual_override', true );
	if ( 'add' === $manual_override ) {
		return true;
	}
	if ( 'remove' === $manual_override ) {
		return false;
	}

	return '1' === (string) get_user_meta( $user_id, 'extrachill_team', true );
}

/**
 * Sync the team role on every site for a user.
 *
 * Pure function over the source-of-truth meta: computes the effective
 * status once, then ensures every site in the network has the role
 * added (if status=true) or removed (if status=false).
 *
 * Super-admins are skipped — they already have all caps via the
 * multisite cap layer, and giving them an extra role on every subsite
 * is noise.
 *
 * @param int $user_id User ID to sync.
 * @return array{added: int[], removed: int[], skipped: bool} Per-site outcome (blog IDs added to / removed from).
 */
function ec_users_sync_team_role( $user_id ) {
	$user_id = (int) $user_id;

	$result = array(
		'added'   => array(),
		'removed' => array(),
		'skipped' => false,
	);

	if ( $user_id <= 0 ) {
		$result['skipped'] = true;
		return $result;
	}

	if ( function_exists( 'is_super_admin' ) && is_super_admin( $user_id ) ) {
		$result['skipped'] = true;
		return $result;
	}

	$should_have_role = ec_users_compute_effective_team_status( $user_id );
	$site_ids         = ec_users_get_network_site_ids();

	foreach ( $site_ids as $blog_id ) {
		$blog_id = (int) $blog_id;

		try {
			switch_to_blog( $blog_id );

			// Ensure the role exists on this site before we assign it.
			// WP_User::add_role() writes the role name into user meta
			// without checking whether the role is registered — so
			// without this call, a sync against a fresh subsite that
			// hasn't run init yet would create a "ghost role" (role on
			// the user, but get_role() returns null, so the role's
			// caps don't take effect). The call is cheap: a single
			// get_role() lookup + array compare on the steady state.
			ec_users_register_team_role();

			$user = new WP_User( $user_id );
			if ( ! $user->exists() ) {
				continue;
			}

			$has_role = in_array( EC_USERS_TEAM_ROLE, (array) $user->roles, true );

			if ( $should_have_role && ! $has_role ) {
				$user->add_role( EC_USERS_TEAM_ROLE );
				$result['added'][] = $blog_id;
			} elseif ( ! $should_have_role && $has_role ) {
				$user->remove_role( EC_USERS_TEAM_ROLE );
				$result['removed'][] = $blog_id;
			}
		} finally {
			restore_current_blog();
		}
	}

	return $result;
}

/**
 * Hook handler: sync the role when team meta changes.
 *
 * Bound to added_user_meta / updated_user_meta / deleted_user_meta so
 * either source meta key triggers a re-sync.
 *
 * @param int    $meta_id    Meta ID (unused, hook signature requirement).
 * @param int    $user_id    User ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Meta value (unused; sync recomputes from source).
 * @return void
 */
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature requires $meta_value position.
function ec_users_on_team_meta_change( $meta_id, $user_id, $meta_key, $meta_value ) {
	if ( ! in_array( $meta_key, array( 'extrachill_team', 'extrachill_team_manual_override' ), true ) ) {
		return;
	}

	// Defensive recursion guard. sync writes role assignments via
	// update_user_meta on the {prefix}_capabilities key, which is
	// filtered out by the in_array check above — so direct recursion
	// is impossible. This guards against indirect recursion if any
	// future code writes back to extrachill_team* meta from inside
	// updated_user_meta on the same user.
	static $syncing = array();
	if ( ! empty( $syncing[ $user_id ] ) ) {
		return;
	}
	$syncing[ $user_id ] = true;

	try {
		ec_users_sync_team_role( $user_id );
	} finally {
		unset( $syncing[ $user_id ] );
	}
}
add_action( 'added_user_meta', 'ec_users_on_team_meta_change', 10, 4 );
add_action( 'updated_user_meta', 'ec_users_on_team_meta_change', 10, 4 );
add_action( 'deleted_user_meta', 'ec_users_on_team_meta_change', 10, 4 );

/**
 * Register the role on a newly-initialized subsite.
 *
 * @param WP_Site $new_site The newly created site object.
 * @return void
 */
function ec_users_on_new_site_register_role( $new_site ) {
	try {
		switch_to_blog( (int) $new_site->blog_id );
		ec_users_register_team_role();
	} finally {
		restore_current_blog();
	}
}
add_action( 'wp_initialize_site', 'ec_users_on_new_site_register_role', 200 );

/**
 * Idempotent safety net: ensure the role exists on the current site
 * with the current cap set.
 *
 * Runs early on every request via init. ec_users_register_team_role()
 * self-debounces — it does a cheap get_role() + ksort()+!== compare
 * against the desired cap set and only writes when those diverge, so
 * the steady-state cost is a single get_role() call per request.
 *
 * The previous version-flag based gate (skip if option matches plugin
 * version) was wrong because patch-level deploys that change the cap
 * set without bumping the version would never propagate. The cap-diff
 * check IS the debounce.
 *
 * @return void
 */
function ec_users_maybe_register_team_role() {
	ec_users_register_team_role();
}
add_action( 'init', 'ec_users_maybe_register_team_role', 5 );
