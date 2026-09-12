<?php
/**
 * Concert import architecture guard.
 *
 * @package ExtraChill\Users
 */

/** Prevent Users-owned event write primitives from returning. */
class Test_Concert_Import_Architecture extends WP_UnitTestCase {

	/** Production import code must delegate all event-domain writes to DME. */
	public function test_retired_event_write_paths_are_absent(): void {
		$directory = EXTRACHILL_USERS_PLUGIN_DIR . 'inc/concert-tracking/import/';
		$this->assertFileDoesNotExist( $directory . 'EventMatcher.php' );
		$this->assertFileDoesNotExist( $directory . 'EventCreator.php' );

		$production = '';
		foreach ( glob( $directory . '*.php' ) as $file ) {
			$production .= (string) file_get_contents( $file );
		}
		foreach ( array( 'datamachine/upsert-post', 'wp_insert_term(', 'datamachine_event_taxonomy_processed', 'wp:data-machine-events/event-details', 'similar_text(' ) as $retired ) {
			$this->assertStringNotContainsString( $retired, $production );
		}
	}
}
