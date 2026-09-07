<?php
/**
 * Deterministic in-memory cache test double for registration anti-automation tests.
 *
 * Extracted from its test file so each file carries a single object structure
 * (Generic.Files.OneObjectStructurePerFile).
 */

class Registration_Rate_Limit_Test_Cache {
	/** @var array<string,array{value:int,expires_at:int}> */
	private $data = array();

	/** @var int */
	public $now;

	/** @var callable|null */
	public $after_failed_add;

	public function __construct( int $now ) {
		$this->now = $now;
	}

	public function add( $key, $value, $group = 'default', $expiration = 0 ): bool {
		$found = false;
		$this->get( $key, $group, false, $found );
		if ( $found ) {
			if ( is_callable( $this->after_failed_add ) ) {
				$callback               = $this->after_failed_add;
				$this->after_failed_add = null;
				$callback();
			}
			return false;
		}

		$this->data[ $this->id( $key, $group ) ] = array(
			'value'      => (int) $value,
			'expires_at' => $expiration ? $this->now + (int) $expiration : 0,
		);
		return true;
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$id    = $this->id( $key, $group );
		$entry = $this->data[ $id ] ?? null;
		if ( is_array( $entry ) && ( 0 === $entry['expires_at'] || $entry['expires_at'] > $this->now ) ) {
			$found = true;
			return $entry['value'];
		}

		unset( $this->data[ $id ] );
		$found = false;
		return false;
	}

	public function incr( $key, $offset = 1, $group = 'default' ): int {
		$found = false;
		$value = $this->get( $key, $group, false, $found );
		$id    = $this->id( $key, $group );
		if ( ! $found ) {
			// Redis INCRBY creates a non-expiring key when the key is absent.
			$this->data[ $id ] = array(
				'value'      => (int) $offset,
				'expires_at' => 0,
			);
			return (int) $offset;
		}

		$this->data[ $id ]['value'] = (int) $value + (int) $offset;
		return $this->data[ $id ]['value'];
	}

	public function delete( $key, $group = 'default' ): bool {
		$id = $this->id( $key, $group );
		if ( ! isset( $this->data[ $id ] ) ) {
			return false;
		}

		unset( $this->data[ $id ] );
		return true;
	}

	public function add_global_groups( $groups ): void {
		// Registration counters are always global in this deterministic cache.
	}

	public function ttl( $key, $group ): int {
		$found = false;
		$this->get( $key, $group, false, $found );
		if ( ! $found ) {
			return -2;
		}

		$expires_at = $this->data[ $this->id( $key, $group ) ]['expires_at'];
		return 0 === $expires_at ? -1 : $expires_at - $this->now;
	}

	private function id( $key, $group ): string {
		return $group . ':' . $key;
	}
}
