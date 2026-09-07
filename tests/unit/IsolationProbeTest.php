<?php
/**
 * Diagnostic probe for PHPUnit process isolation in the managed sandbox.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */

class Test_Isolation_Probe extends WP_UnitTestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_noop_in_separate_process(): void {
		$this->assertTrue( true );
	}
}
