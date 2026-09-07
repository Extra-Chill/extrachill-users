<?php
/**
 * Deterministic in-memory cache test double for auth rate-limit tests.
 *
 * Extracted from its test file so each file carries a single object structure
 * (Generic.Files.OneObjectStructurePerFile).
 */

class Auth_Rate_Limit_Test_Cache {
	/** @var array<string,array{value:mixed,expires_at:int}> */
	private $data = array();

	/** @var int */
	private $blog_id = 1;

	/** @var int */
	public $now = 1000;

	/** @var bool */
	public $fail = false;

	/** @var callable|null */
	public $after_add;

	/** @var string */
	public $after_add_pattern = '';

	/** @var callable|null */
	public $after_set;

	/** @var string */
	public $after_set_pattern = '';

	public function add( $key, $value, $group = 'default', $expiration = 0 ): bool {
		if ( $this->fail ) {
			return false;
		}

		$found = false;
		$this->get( $key, $group, false, $found );
		if ( $found ) {
			return false;
		}

		$this->set( $key, $value, $group, $expiration );
		if ( is_callable( $this->after_add ) && false !== strpos( (string) $key, $this->after_add_pattern ) ) {
			$callback        = $this->after_add;
			$this->after_add = null;
			$callback();
		}

		return true;
	}

	public function set( $key, $value, $group = 'default', $expiration = 0 ): bool {
		if ( $this->fail ) {
			return false;
		}

		$this->data[ $this->id( $key, $group ) ] = array(
			'value'      => $value,
			'expires_at' => $expiration ? $this->now + (int) $expiration : 0,
		);
		if ( is_callable( $this->after_set ) && false !== strpos( (string) $key, $this->after_set_pattern ) ) {
			$callback        = $this->after_set;
			$this->after_set = null;
			$callback();
		}

		return true;
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		if ( $this->fail ) {
			$found = false;
			return false;
		}

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

	public function get_multiple( $keys, $group = 'default', $force = false ): array {
		$values = array();
		foreach ( $keys as $key ) {
			$values[ $key ] = $this->get( $key, $group, $force );
		}

		return $values;
	}

	public function incr( $key, $offset = 1, $group = 'default' ) {
		$found = false;
		$value = $this->get( $key, $group, false, $found );
		if ( $this->fail || ! $found || ! is_numeric( $value ) ) {
			return false;
		}

		$id                         = $this->id( $key, $group );
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

	public function switch_to_blog( $blog_id ): void {
		$this->blog_id = (int) $blog_id;
	}

	public function add_global_groups( $groups ): void {
		// Authentication groups intentionally remain blog-scoped.
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
		return $this->blog_id . ':' . $group . ':' . $key;
	}
}
