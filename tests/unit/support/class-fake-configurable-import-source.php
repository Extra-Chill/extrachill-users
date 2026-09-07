<?php
/**
 * Tiny in-memory ImportSource used to flip is_configured() per test.
 *
 * Extracted from its test file so each file carries a single object structure
 * (Generic.Files.OneObjectStructurePerFile).
 */

use ExtraChill\Users\Concert_Import\ImportSource;

final class FakeConfigurableImportSource implements ImportSource {

	public function __construct(
		private string $slug,
		private string $label,
		private bool $configured
	) {
	}

	public function slug(): string {
		return $this->slug;
	}
	public function label(): string {
		return $this->label;
	}
	public function rate_limit(): array {
		return array(
			'requests_per_second' => 1.0,
			'requests_per_day'    => 1000,
		);
	}
	public function is_configured(): bool {
		return $this->configured;
	}
	public function preview( string $username ) {
		return array(
			'total'    => 0,
			'username' => $username,
		);
	}
	public function fetch_page( string $username, int $page ) {
		return array(
			'events'      => array(),
			'total_pages' => 1,
			'total'       => 0,
			'page'        => 1,
		);
	}
}
