<?php
/**
 * Test double object cache for browser handoff token tests.
 *
 * Extracted from its test file so each file carries a single object structure
 * (Generic.Files.OneObjectStructurePerFile).
 */

class Browser_Handoff_Test_Cache {

	/** @var array */
	private $data = array();

	/** @var bool */
	public $fail_delete = false;

	public function set( $key, $value, $group = 'default', $expiration = 0 ): bool {
		$this->data[ $group . ':' . $key ] = array(
			'value'      => $value,
			'expires_at' => $expiration ? time() + (int) $expiration : 0,
		);
		return true;
	}

	public function add( $key, $value, $group = 'default', $expiration = 0 ): bool {
		$found = false;
		$this->get( $key, $group, false, $found );
		if ( $found ) {
			return false;
		}

		return $this->set( $key, $value, $group, $expiration );
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$id    = $group . ':' . $key;
		$entry = $this->data[ $id ] ?? null;
		if ( is_array( $entry ) && ( empty( $entry['expires_at'] ) || $entry['expires_at'] > time() ) ) {
			$found = true;
			return $entry['value'];
		}

		unset( $this->data[ $id ] );
		$found = false;
		return false;
	}

	public function delete( $key, $group = 'default' ): bool {
		$id = $group . ':' . $key;
		if ( $this->fail_delete || ! isset( $this->data[ $id ] ) ) {
			return false;
		}

		unset( $this->data[ $id ] );
		return true;
	}
}
