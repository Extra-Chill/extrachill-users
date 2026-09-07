<?php

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$auth_fuzz_plugins = array(
	'00-auth-fuzz-fixture/auth-fuzz-fixture.php',
	'extrachill-network/extrachill-network.php',
	'extrachill-api/extrachill-api.php',
	'wp-native-auth/wp-native-auth.php',
	'extrachill-analytics/extrachill-analytics.php',
	'extrachill-users/extrachill-users.php',
);
foreach ( $auth_fuzz_plugins as $auth_fuzz_plugin ) {
	if ( ! is_plugin_active_for_network( $auth_fuzz_plugin ) ) {
		$result = activate_plugin( $auth_fuzz_plugin, '', true );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $auth_fuzz_plugin . ': ' . $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- standalone e2e harness error message, not web output.
		}
	}
}
if ( function_exists( 'extrachill_analytics_events_create_table' ) ) {
	extrachill_analytics_events_create_table();
}
