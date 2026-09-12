<?php
/**
 * Dependency-free smoke test for the Users ability lifecycle helper.
 *
 * @package ExtraChill\Users
 */

define( 'ABSPATH', __DIR__ );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Standalone WordPress hook stubs are local to this executable smoke.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- This isolated smoke provides the WordPress hook globals it exercises.
$wp_actions        = array( 'init' => 1 );
$wp_filter         = array();
$wp_current_filter = array();

require_once __DIR__ . '/fixtures/class-ec-users-smoke-registry.php';
class_alias( 'EC_Users_Smoke_Registry', 'WP_Abilities_Registry' );
class_alias( 'EC_Users_Smoke_Registry', 'WP_Ability_Categories_Registry' );

function doing_action( $hook_name ): bool {
	global $wp_current_filter;
	return in_array( $hook_name, $wp_current_filter, true );
}

function did_action( $hook_name ): int {
	global $wp_actions;
	return $wp_actions[ $hook_name ] ?? 0;
}

function add_action( $hook_name, $callback ): void {
	global $wp_filter;
	$wp_filter[ $hook_name ][] = $callback;
}

function ec_users_smoke_do_action( string $hook_name, object $registry ): void {
	global $wp_actions, $wp_current_filter, $wp_filter;

	$wp_actions[ $hook_name ] = ( $wp_actions[ $hook_name ] ?? 0 ) + 1;
	$wp_current_filter[]      = $hook_name;
	try {
		foreach ( $wp_filter[ $hook_name ] ?? array() as $callback ) {
			$callback( $registry );
		}
	} finally {
		array_pop( $wp_current_filter );
	}
}

function ec_users_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is consumed by the CLI smoke runner.
		throw new RuntimeException( $message );
	}
}

require_once dirname( __DIR__ ) . '/inc/core/abilities/registration-lifecycle.php';

$lifecycles = array(
	'wp_abilities_api_init'            => array( 'extrachill_users_on_abilities_api_init', WP_Abilities_Registry::get_instance() ),
	'wp_abilities_api_categories_init' => array( 'extrachill_users_on_abilities_api_categories_init', WP_Ability_Categories_Registry::get_instance() ),
);

foreach ( $lifecycles as $hook_name => $lifecycle ) {
	$wrapper  = $lifecycle[0];
	$registry = $lifecycle[1];

	$before_count = 0;
	$before       = static function ( $received_registry ) use ( &$before_count, $registry ): void {
		ec_users_smoke_assert( $registry === $received_registry, 'Before callback received the wrong registry.' );
		++$before_count;
	};
	$wrapper( $before );
	ec_users_smoke_do_action( $hook_name, $registry );
	ec_users_smoke_assert( 1 === $before_count, 'Before callback did not run once.' );

	$existing_count = $before_count;
	$after_count    = 0;
	$after          = static function ( $received_registry ) use ( &$after_count, $hook_name, $registry ): void {
		ec_users_smoke_assert( doing_action( $hook_name ), 'Late callback lacked lifecycle context.' );
		ec_users_smoke_assert( $registry === $received_registry, 'Late callback received the wrong registry.' );
		++$after_count;
	};
	$wrapper( $after );
	$wrapper( $after );
	ec_users_smoke_assert( $existing_count === $before_count, 'Late registration replayed the global hook.' );
	ec_users_smoke_assert( 1 === $after_count, 'Same callback instance was not deduped.' );
}

$count   = 0;
$factory = static function () use ( &$count ): Closure {
	return static function () use ( &$count ): void {
		++$count;
	};
};
extrachill_users_on_abilities_api_init( $factory() );
extrachill_users_on_abilities_api_init( $factory() );
ec_users_smoke_assert( 2 === $count, 'Distinct closures from one declaration were deduped.' );

$original_stack = $wp_current_filter;
try {
	extrachill_users_on_abilities_api_init(
		static function (): void {
			throw new RuntimeException( 'Expected smoke exception.' );
		}
	);
} catch ( RuntimeException $exception ) {
	ec_users_smoke_assert( 'Expected smoke exception.' === $exception->getMessage(), 'Unexpected smoke exception.' );
}
ec_users_smoke_assert( $original_stack === $wp_current_filter, 'Exception leaked lifecycle context.' );
// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
// phpcs:enable Squiz.Commenting.FunctionComment.Missing
