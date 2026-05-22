<?php
/**
 * Team Member Helper Functions
 *
 * @package ExtraChill\Users
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/team-members/role.php';

/**
 * Check team member status.
 *
 * Phase 1 of #45: delegates to the WP capability system. A user is a
 * team member iff they have the `access_studio` capability — granted
 * by the `extra_chill_team` role that mirrors the meta source of
 * truth. Super-admins always count as team members regardless of role.
 *
 * Backward-compatible: existing call sites pass unchanged. The legacy
 * meta-based logic remains as a transition fallback for the brief
 * window between code deploy and the network-wide role reconcile, so
 * nobody loses access mid-migration.
 *
 * @param int $user_id User ID (0 = current user).
 * @return bool
 */
function ec_is_team_member( $user_id = 0 ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return false;
	}

	// Super-admins are always considered team members.
	if ( function_exists( 'is_super_admin' ) && is_super_admin( $user_id ) ) {
		return true;
	}

	// Native capability check — the post-migration source of truth.
	// access_studio is a custom cap granted by the extra_chill_team
	// role registered in inc/team-members/role.php.
	// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom cap registered by ec_users_register_team_role().
	if ( user_can( $user_id, 'access_studio' ) ) {
		return true;
	}

	// Transition fallback: read the underlying meta directly so the
	// brief gap between code deploy and the reconcile run does not
	// drop existing team members. Remove this block (Phase 2) after
	// the reconcile has run across the network and every team member
	// is confirmed to have the role on every site.
	return ec_users_compute_effective_team_status( $user_id );
}

/**
 * Check if user has account on extrachill.com.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function ec_has_main_site_account( $user_id ) {
	if ( ! $user_id ) {
		return false;
	}

	$has_account = false;

	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
	if ( ! $main_blog_id ) {
		return false;
	}

	if ( function_exists( 'switch_to_blog' ) ) {
		global $current_user;

		if ( isset( $current_user ) && $current_user instanceof WP_User ) {
			try {
				switch_to_blog( $main_blog_id );
				$has_account = is_user_member_of_blog( $user_id, $main_blog_id );
			} finally {
				restore_current_blog();
			}
		}
	}

	return $has_account;
}
