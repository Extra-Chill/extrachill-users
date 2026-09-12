<?php
/**
 * Canonical Events-runtime upsert transport for concert imports.
 *
 * @package ExtraChill\Users\Concert_Import
 */

namespace ExtraChill\Users\Concert_Import;

defined( 'ABSPATH' ) || exit;

/** Execute and authorize canonical event upserts for concert imports. */
final class CanonicalEventUpserter {

	public const SERVICE_ID    = 'extrachill.users.concert-import';
	public const SERVICE_SCOPE = 'extrachill/users:concert-import-event';
	public const ROUTE         = '/wp-abilities/v1/abilities/data-machine-events/upsert-event/run';

	private const LEGACY_SOURCE_META      = '_dm_import_source';
	private const LEGACY_SOURCE_ID_META   = '_dm_import_external_id';
	private const CANONICAL_IDENTITY_META = '_datamachine_event_source_identity';
	private const FINGERPRINT_MAX_DEPTH   = 8;
	private const FINGERPRINT_MAX_NODES   = 256;
	private const FINGERPRINT_MAX_BYTES   = 32768;

	/**
	 * Exact request currently holding elevation.
	 *
	 * @var \WP_REST_Request|null
	 */
	private static ?\WP_REST_Request $active_request = null;

	/**
	 * Fingerprint authorized for the active request.
	 *
	 * @var string
	 */
	private static string $active_fingerprint = '';

	/** Register the bounded transport and target-request hooks. */
	public static function register_hooks(): void {
		add_filter( 'ec_cross_site_service_assertion_source_grants', array( __CLASS__, 'register_source_grant' ) );
		add_filter( 'ec_cross_site_service_assertion_target_grants', array( __CLASS__, 'register_target_grant' ) );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'prepare_target_request' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'release_target_request' ), 10, 3 );
		add_filter( 'datamachine_events_upsert_event_permission', array( __CLASS__, 'filter_upsert_permission' ), 10, 2 );
	}

	/**
	 * Map and execute one event in the fully bootstrapped Events runtime.
	 *
	 * @param ExternalEvent        $event       Provider event.
	 * @param string               $source_slug Stable provider source.
	 * @param array<string, mixed> $run         Running import row.
	 * @return array<string, mixed>|\WP_Error Canonical upsert result.
	 */
	public function upsert( ExternalEvent $event, string $source_slug, array $run ) {
		$input = self::map_input( $event, $source_slug, $run );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
			return new \WP_Error( 'event_transport_unavailable', 'Canonical event transport is unavailable.' );
		}
		if ( null === self::assertion_config() ) {
			return new \WP_Error(
				'concert_import_service_unconfigured',
				'Concert import event transport requires configured rotatable service assertion keys.',
				array( 'status' => 503 )
			);
		}

		$result = ec_cross_site_rest_request(
			'events',
			'POST',
			self::ROUTE,
			array(
				'body'              => array( 'input' => $input ),
				'user_id'           => (int) $run['user_id'],
				'service_assertion' => array(
					'service_id' => self::SERVICE_ID,
					'scope'      => self::SERVICE_SCOPE,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) || empty( $result['success'] ) || empty( $result['event_id'] ) || empty( $result['action'] ) ) {
			return new \WP_Error( 'event_upsert_invalid_response', 'Canonical event upsert returned an invalid response.' );
		}

		return $result;
	}

	/**
	 * Build DME's public upsert input without inventing event content.
	 *
	 * @param ExternalEvent        $event       Provider event.
	 * @param string               $source_slug Stable provider source.
	 * @param array<string, mixed> $run         Running import row.
	 * @return array<string, mixed>|\WP_Error Ability input.
	 */
	public static function map_input( ExternalEvent $event, string $source_slug, array $run ) {
		$source_slug = trim( $source_slug );
		$source_id   = trim( $event->source_id );
		$run_id      = (int) ( $run['id'] ?? 0 );
		$user_id     = (int) ( $run['user_id'] ?? 0 );
		if ( ! $event->is_matchable() || '' === $source_slug || '' === $source_id || 0 >= $run_id || 0 >= $user_id ) {
			return new \WP_Error( 'invalid_event_upsert_input', 'Concert import event input is incomplete.' );
		}

		$event_input = array_filter(
			array(
				'title'         => self::format_title( $event ),
				'startDate'     => $event->date,
				'venue'         => $event->venue_name,
				'venueCity'     => $event->city,
				'venueState'    => $event->state,
				'venueCountry'  => $event->country,
				'performer'     => $event->headliner,
				'performerType' => 'PerformingGroup',
			),
			static function ( $value ): bool {
				return '' !== $value;
			}
		);

		return array(
			'source'        => $source_slug,
			'source_id'     => $source_id,
			'import_run_id' => $run_id,
			'post_status'   => 'publish',
			'post_author'   => $user_id,
			'event'         => $event_input,
		);
	}

	/**
	 * Register the source-side assertion grant.
	 *
	 * @param array<int, array<string, mixed>> $grants Existing grants.
	 * @return array<int, array<string, mixed>> Filtered grants.
	 */
	public static function register_source_grant( array $grants ): array {
		foreach ( self::worker_site_ids() as $site_id ) {
			$grant = self::grant( $site_id, true );
			if ( null !== $grant ) {
				$grants[] = $grant;
			}
		}
		return $grants;
	}

	/**
	 * Register the target-side assertion grant.
	 *
	 * @param array<int, array<string, mixed>> $grants Existing grants.
	 * @return array<int, array<string, mixed>> Filtered grants.
	 */
	public static function register_target_grant( array $grants ): array {
		foreach ( self::worker_site_ids() as $site_id ) {
			$grant = self::grant( $site_id, false );
			if ( null !== $grant ) {
				$grants[] = $grant;
			}
		}
		return $grants;
	}

	/**
	 * Bind verified service authority and an optional legacy candidate to one request.
	 *
	 * @param mixed            $response Existing response.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  Exact request.
	 * @return mixed
	 */
	public static function prepare_target_request( $response, array $handler, $request ) {
		unset( $handler );
		if ( is_wp_error( $response ) || ! $request instanceof \WP_REST_Request || ! self::is_target_request( $request ) ) {
			return $response;
		}

		$claims = function_exists( 'ec_cross_site_verified_service_context' ) ? ec_cross_site_verified_service_context( $request ) : array();
		if ( empty( $claims ) ) {
			return $response;
		}
		$grant = self::grant( (int) ( $claims['source_site_id'] ?? 0 ), false );
		if ( null === $grant || ! self::claims_match( $claims, $grant ) ) {
			return new \WP_Error( 'concert_import_service_denied', 'Concert import service authority was denied.', array( 'status' => 403 ) );
		}

		$json  = $request->get_json_params();
		$input = is_array( $json ) && is_array( $json['input'] ?? null ) ? $json['input'] : array();
		$run   = ImportOrchestrator::get_run( (int) ( $input['import_run_id'] ?? 0 ) );
		if (
			! $run
			|| ImportOrchestrator::STATUS_RUNNING !== $run['status']
			|| 0 >= (int) $run['user_id']
			|| get_current_user_id() !== (int) $run['user_id']
			|| (string) ( $input['source'] ?? '' ) !== (string) $run['source_slug']
			|| '' === trim( (string) ( $input['source_id'] ?? '' ) )
			|| (int) ( $input['post_author'] ?? 0 ) !== (int) $run['user_id']
			|| 'publish' !== (string) ( $input['post_status'] ?? '' )
			|| ! is_array( $input['event'] ?? null )
			|| array_key_exists( 'event_id', $input )
		) {
			return new \WP_Error( 'concert_import_run_mismatch', 'Concert import request does not match a running import.', array( 'status' => 403 ) );
		}

		$candidate = self::legacy_candidate( (string) $input['source'], (string) $input['source_id'] );
		if ( is_wp_error( $candidate ) ) {
			return $candidate;
		}
		if ( 0 < $candidate ) {
			$input['event_id'] = $candidate;
			$request->set_param( 'input', $input );
		}
		$fingerprint = self::input_fingerprint( $input );
		if ( null === $fingerprint ) {
			return new \WP_Error( 'concert_import_input_unbounded', 'Concert import event input exceeds authorization bounds.', array( 'status' => 400 ) );
		}

		self::active_request( $request, $fingerprint, true );
		add_filter( 'datamachine_indexnow_skip_auto_submit', array( __CLASS__, 'suppress_indexnow' ) );
		return $response;
	}

	/**
	 * Preserve IndexNow suppression for the exact active import request.
	 *
	 * @return bool Whether automatic submission should be skipped.
	 */
	public static function suppress_indexnow(): bool {
		return null !== self::active_request();
	}

	/**
	 * Release only the request that established service authority.
	 *
	 * @param mixed            $response Existing response.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  Exact request.
	 * @return mixed
	 */
	public static function release_target_request( $response, array $handler, $request ) {
		unset( $handler );
		if ( self::active_request() === $request ) {
			remove_filter( 'datamachine_indexnow_skip_auto_submit', array( __CLASS__, 'suppress_indexnow' ) );
			self::active_request( null, '', true );
		}
		return $response;
	}

	/**
	 * Leave ordinary DME callers unchanged and elevate only the bound request.
	 *
	 * @param bool  $allowed Existing DME authority decision.
	 * @param array $input   Validated DME ability input.
	 * @return bool Filtered authority decision.
	 */
	public static function filter_upsert_permission( bool $allowed, array $input ): bool {
		if ( $allowed ) {
			return true;
		}
		$fingerprint = self::input_fingerprint( $input );
		return null !== self::active_request()
			&& null !== $fingerprint
			&& hash_equals( self::active_fingerprint(), $fingerprint );
	}

	/**
	 * Resolve legacy identity without writing Events metadata.
	 *
	 * @param string $source    Stable source namespace.
	 * @param string $source_id Stable source item ID.
	 * @return int|\WP_Error Legacy-only candidate ID, zero, or collision error.
	 */
	public static function legacy_candidate( string $source, string $source_id ) {
		$identity  = hash( 'sha256', $source . "\0" . $source_id );
		$canonical = self::query_ids(
			array(
				array(
					'key'   => self::CANONICAL_IDENTITY_META,
					'value' => $identity,
				),
			)
		);
		$legacy    = self::query_ids(
			array(
				'relation' => 'AND',
				array(
					'key'   => self::LEGACY_SOURCE_META,
					'value' => $source,
				),
				array(
					'key'   => self::LEGACY_SOURCE_ID_META,
					'value' => $source_id,
				),
			)
		);

		if ( count( $canonical ) > 1 || count( $legacy ) > 1 || ( $canonical && $legacy && $canonical[0] !== $legacy[0] ) ) {
			return new \WP_Error( 'concert_import_identity_collision', 'Concert import event identity is ambiguous.', array( 'status' => 409 ) );
		}

		return empty( $canonical ) && 1 === count( $legacy ) ? $legacy[0] : 0;
	}

	/**
	 * Query at most two event IDs, sufficient to detect ambiguity.
	 *
	 * @param array $meta_query Exact metadata query.
	 * @return int[] Event IDs.
	 */
	private static function query_ids( array $meta_query ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'data_machine_events',
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' ),
				'posts_per_page'         => 2,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded read-only compatibility lookup.
				'meta_query'             => $meta_query,
			)
		);
		return array_values( array_unique( array_map( 'intval', $query->posts ) ) );
	}

	/**
	 * Build the exact Network service assertion grant.
	 *
	 * @param int  $source_site_id Worker source site ID.
	 * @param bool $source         Whether to include source-only active key data.
	 * @return array<string, mixed>|null Exact grant.
	 */
	private static function grant( int $source_site_id, bool $source ): ?array {
		if ( ! function_exists( 'ec_get_blog_id' ) || ! function_exists( 'ec_get_site_url' ) ) {
			return null;
		}
		$config = self::assertion_config();
		if ( null === $config || 1 > $source_site_id ) {
			return null;
		}

		$target_url  = (string) ec_get_site_url( 'events' );
		$target_host = wp_parse_url( $target_url, PHP_URL_HOST );
		$grant       = array(
			'service_id'     => self::SERVICE_ID,
			'scope'          => self::SERVICE_SCOPE,
			'source_site_id' => $source_site_id,
			'target_site_id' => (int) ec_get_blog_id( 'events' ),
			'target_host'    => is_string( $target_host ) ? strtolower( $target_host ) : '',
			'method'         => 'POST',
			'route'          => self::ROUTE,
			'keys'           => $config['keys'],
		);
		if ( $source ) {
			$grant['active_key_id'] = $config['active_key_id'];
		}
		return $grant;
	}

	/**
	 * Resolve deployment-provided rotatable assertion key configuration.
	 *
	 * @return array{keys: array<string, string>, active_key_id: string}|null Valid configuration.
	 */
	private static function assertion_config(): ?array {
		$keys          = defined( 'EXTRACHILL_USERS_CONCERT_IMPORT_SERVICE_ASSERTION_KEYS' )
			? constant( 'EXTRACHILL_USERS_CONCERT_IMPORT_SERVICE_ASSERTION_KEYS' )
			: array();
		$active_key_id = defined( 'EXTRACHILL_USERS_CONCERT_IMPORT_SERVICE_ASSERTION_ACTIVE_KEY_ID' )
			? constant( 'EXTRACHILL_USERS_CONCERT_IMPORT_SERVICE_ASSERTION_ACTIVE_KEY_ID' )
			: '';
		$keys          = apply_filters( 'extrachill_users_concert_import_service_assertion_keys', $keys );
		$active_key_id = apply_filters( 'extrachill_users_concert_import_service_assertion_active_key_id', $active_key_id );
		if (
			! is_array( $keys )
			|| ! is_string( $active_key_id )
			|| '' === $active_key_id
			|| ! isset( $keys[ $active_key_id ] )
			|| ! is_string( $keys[ $active_key_id ] )
			|| 32 > strlen( $keys[ $active_key_id ] )
		) {
			return null;
		}
		return array(
			'keys'          => $keys,
			'active_key_id' => $active_key_id,
		);
	}

	/**
	 * Return current network site IDs eligible to execute a worker.
	 *
	 * @return int[] Worker site IDs.
	 */
	private static function worker_site_ids(): array {
		if ( ! function_exists( 'get_sites' ) ) {
			return array();
		}
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		$site_ids = array_values( array_unique( array_filter( array_map( 'intval', $site_ids ) ) ) );
		sort( $site_ids, SORT_NUMERIC );
		return $site_ids;
	}

	/**
	 * Match verified Network claims to the configured target grant.
	 *
	 * @param array<string, mixed> $claims Verified request claims.
	 * @param array<string, mixed> $grant  Configured target grant.
	 * @return bool Whether claims match exactly.
	 */
	private static function claims_match( array $claims, array $grant ): bool {
		foreach ( array( 'service_id', 'scope', 'source_site_id', 'target_site_id', 'target_host' ) as $field ) {
			if ( ! array_key_exists( $field, $claims ) || (string) $claims[ $field ] !== (string) $grant[ $field ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check whether this is the exact target ability request.
	 *
	 * @param \WP_REST_Request $request Candidate request.
	 * @return bool Whether the request matches.
	 */
	private static function is_target_request( \WP_REST_Request $request ): bool {
		return 'POST' === $request->get_method() && self::ROUTE === $request->get_route();
	}

	/**
	 * Store or retrieve the exact active REST request.
	 *
	 * @param \WP_REST_Request|null $request     Exact request.
	 * @param string                $fingerprint Canonical authorized input fingerprint.
	 * @param bool                  $replace     Whether to replace stored state.
	 * @return \WP_REST_Request|null Active request.
	 */
	private static function active_request( ?\WP_REST_Request $request = null, string $fingerprint = '', bool $replace = false ) {
		if ( $replace ) {
			self::$active_request     = $request;
			self::$active_fingerprint = $fingerprint;
		}
		return self::$active_request;
	}

	/** Return the fingerprint associated with the active request. */
	private static function active_fingerprint(): string {
		return self::$active_fingerprint;
	}

	/**
	 * Fingerprint the complete authorization-sensitive normalized input.
	 *
	 * @param array<string, mixed> $input Ability-normalized input.
	 * @return string|null SHA-256 fingerprint, or null when bounds are exceeded.
	 */
	private static function input_fingerprint( array $input ): ?string {
		$shape = array(
			'source'        => (string) ( $input['source'] ?? '' ),
			'source_id'     => (string) ( $input['source_id'] ?? '' ),
			'import_run_id' => (int) ( $input['import_run_id'] ?? 0 ),
			'post_author'   => (int) ( $input['post_author'] ?? 0 ),
			'post_status'   => (string) ( $input['post_status'] ?? '' ),
			'event_id'      => (int) ( $input['event_id'] ?? 0 ),
			'event'         => is_array( $input['event'] ?? null ) ? $input['event'] : array(),
		);
		$nodes = 0;
		$shape = self::canonicalize_fingerprint_value( $shape, 0, $nodes );
		if ( null === $shape ) {
			return null;
		}
		$encoded = wp_json_encode( $shape, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) || self::FINGERPRINT_MAX_BYTES < strlen( $encoded ) ) {
			return null;
		}
		return hash( 'sha256', $encoded );
	}

	/**
	 * Recursively sort associative input while enforcing authorization bounds.
	 *
	 * @param mixed $value Canonical input value.
	 * @param int   $depth Current recursion depth.
	 * @param int   $nodes Running node count.
	 * @return mixed|null Canonical value, or null when invalid/unbounded.
	 */
	private static function canonicalize_fingerprint_value( $value, int $depth, int &$nodes ) {
		++$nodes;
		if ( self::FINGERPRINT_MAX_DEPTH < $depth || self::FINGERPRINT_MAX_NODES < $nodes ) {
			return null;
		}
		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) || null === $value ? $value : null;
		}
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$canonical = self::canonicalize_fingerprint_value( $item, $depth + 1, $nodes );
			if ( null === $canonical && null !== $item ) {
				return null;
			}
			$value[ $key ] = $canonical;
		}
		return $value;
	}

	/**
	 * Format the existing editorial event title policy.
	 *
	 * @param ExternalEvent $event Provider event.
	 * @return string Event title.
	 */
	private static function format_title( ExternalEvent $event ): string {
		$timestamp = strtotime( $event->date . ' 00:00:00 UTC' );
		$prefix    = '' !== $event->headliner ? $event->headliner . ' at ' . $event->venue_name : $event->venue_name;
		$date      = $timestamp ? gmdate( 'F j, Y', $timestamp ) : $event->date;
		return $prefix . ' · ' . $date;
	}
}
