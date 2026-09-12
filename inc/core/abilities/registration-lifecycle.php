<?php
/**
 * Users-owned Abilities API lifecycle helpers.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register one concrete callback on a one-shot Abilities API lifecycle hook.
 *
 * @param string   $hook_name        Abilities API lifecycle hook name.
 * @param callable $register_callback Registration callback.
 */
function extrachill_users_on_abilities_api_lifecycle( string $hook_name, callable $register_callback ): void {
	static $registration_owners = array();

	if ( $register_callback instanceof Closure ) {
		$owner = 'closure:' . spl_object_id( $register_callback );
	} elseif ( is_array( $register_callback ) ) {
		if ( is_object( $register_callback[0] ) ) {
			$owner = 'object-method:' . $register_callback[0]::class . ':' . spl_object_id( $register_callback[0] ) . '::' . strtolower( (string) $register_callback[1] );
		} else {
			$owner = 'static-method:' . strtolower( (string) $register_callback[0] . '::' . (string) $register_callback[1] );
		}
	} elseif ( is_object( $register_callback ) ) {
		$owner = 'invokable:' . $register_callback::class . ':' . spl_object_id( $register_callback );
	} elseif ( is_string( $register_callback ) ) {
		$owner = 'string:' . strtolower( $register_callback );
	} else {
		$owner = get_debug_type( $register_callback );
	}

	$owner = $hook_name . ':' . $owner;
	if ( isset( $registration_owners[ $owner ] ) ) {
		return;
	}
	if ( ! did_action( $hook_name ) ) {
		$registration_owners[ $owner ] = $register_callback;
		add_action( $hook_name, $register_callback );
		return;
	}

	$registry = extrachill_users_get_abilities_api_registry( $hook_name );
	if ( null === $registry ) {
		return;
	}

	$registration_owners[ $owner ] = $register_callback;

	if ( doing_action( $hook_name ) ) {
		$register_callback( $registry );
		return;
	}

	/*
	 * Core checks the current hook stack before allowing registration. Run only
	 * this newly loaded callback in that context; never replay the global hook.
	 */
	global $wp_current_filter;

	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Provide core's lifecycle context without replaying the global action.
	$wp_current_filter[] = $hook_name;
	try {
		$register_callback( $registry );
	} finally {
		array_pop( $wp_current_filter );
	}
}

/**
 * Resolve the initialized registry passed by a core Abilities API lifecycle.
 *
 * @param string $hook_name Abilities API lifecycle hook name.
 * @return WP_Abilities_Registry|WP_Ability_Categories_Registry|null Registry, or null when unavailable.
 */
function extrachill_users_get_abilities_api_registry( string $hook_name ) {
	if ( ! did_action( 'init' ) ) {
		return null;
	}

	if ( 'wp_abilities_api_init' === $hook_name ) {
		if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
			return null;
		}

		return WP_Abilities_Registry::get_instance();
	}

	if ( 'wp_abilities_api_categories_init' === $hook_name ) {
		if ( ! class_exists( 'WP_Ability_Categories_Registry' ) ) {
			return null;
		}

		return WP_Ability_Categories_Registry::get_instance();
	}

	return null;
}

/**
 * Register a Users ability callback on the core ability lifecycle.
 *
 * @param callable $register_callback Ability registration callback.
 */
function extrachill_users_on_abilities_api_init( callable $register_callback ): void {
	extrachill_users_on_abilities_api_lifecycle( 'wp_abilities_api_init', $register_callback );
}

/**
 * Register the Users category callback on the core category lifecycle.
 *
 * @param callable $register_callback Ability category registration callback.
 */
function extrachill_users_on_abilities_api_categories_init( callable $register_callback ): void {
	extrachill_users_on_abilities_api_lifecycle( 'wp_abilities_api_categories_init', $register_callback );
}
