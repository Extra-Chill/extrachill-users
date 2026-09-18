<?php
/**
 * Users ability registration lifecycle tests.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify registration callbacks behave across the one-shot core lifecycle.
 */
class Test_Ability_Registration_Lifecycle extends WP_UnitTestCase {

	/**
	 * Original hook callbacks keyed by hook name.
	 *
	 * @var array<string,WP_Hook|null>
	 */
	private $original_hooks = array();

	/**
	 * Original action counts keyed by hook name.
	 *
	 * @var array<string,int|null>
	 */
	private $original_action_counts = array();

	/**
	 * Preserve and reset both ability lifecycle hooks.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wp_actions, $wp_filter;

		foreach ( array_keys( $this->lifecycle_hooks() ) as $hook_name ) {
			$this->original_hooks[ $hook_name ]         = $wp_filter[ $hook_name ] ?? null;
			$this->original_action_counts[ $hook_name ] = $wp_actions[ $hook_name ] ?? null;
			unset( $wp_filter[ $hook_name ], $wp_actions[ $hook_name ] );
		}
	}

	/**
	 * Restore both ability lifecycle hooks.
	 */
	protected function tearDown(): void {
		global $wp_actions, $wp_filter;

		foreach ( array_keys( $this->lifecycle_hooks() ) as $hook_name ) {
			if ( null === $this->original_hooks[ $hook_name ] ) {
				unset( $wp_filter[ $hook_name ] );
			} else {
				$wp_filter[ $hook_name ] = $this->original_hooks[ $hook_name ];
			}

			if ( null === $this->original_action_counts[ $hook_name ] ) {
				unset( $wp_actions[ $hook_name ] );
			} else {
				$wp_actions[ $hook_name ] = $this->original_action_counts[ $hook_name ];
			}
		}

		parent::tearDown();
	}

	/**
	 * Core lifecycle hooks, wrappers, and expected registries.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function lifecycle_hooks(): array {
		return array(
			'abilities'  => array( 'wp_abilities_api_init', 'extrachill_users_on_abilities_api_init' ),
			'categories' => array( 'wp_abilities_api_categories_init', 'extrachill_users_on_abilities_api_categories_init' ),
		);
	}

	/**
	 * A callback declared before initialization receives the core registry once.
	 *
	 * @dataProvider lifecycle_hooks
	 * @param string $hook_name Hook name.
	 * @param string $wrapper   Users lifecycle wrapper.
	 */
	public function test_before_hook_attaches_and_receives_registry( string $hook_name, string $wrapper ): void {
		$registry = extrachill_users_get_abilities_api_registry( $hook_name );
		$count    = 0;
		$callback = function ( $received_registry ) use ( &$count, $registry ): void {
			$this->assertSame( $registry, $received_registry );
			++$count;
		};

		$wrapper( $callback );

		$this->assertSame( 10, has_action( $hook_name, $callback ) );
		do_action( $hook_name, $registry );
		$this->assertSame( 1, $count );
	}

	/**
	 * A callback declared during initialization receives the core registry once.
	 *
	 * @dataProvider lifecycle_hooks
	 * @param string $hook_name Hook name.
	 * @param string $wrapper   Users lifecycle wrapper.
	 */
	public function test_during_hook_runs_immediately_with_registry( string $hook_name, string $wrapper ): void {
		$registry = extrachill_users_get_abilities_api_registry( $hook_name );
		$count    = 0;

		add_action(
			$hook_name,
			function ( $received_registry ) use ( &$count, $registry, $wrapper ): void {
				$this->assertSame( $registry, $received_registry );
				$wrapper(
					function ( $immediate_registry ) use ( &$count, $registry ): void {
						$this->assertSame( $registry, $immediate_registry );
						++$count;
					}
				);
			}
		);

		do_action( $hook_name, $registry );
		$this->assertSame( 1, $count );
	}

	/**
	 * A late callback receives the registry without replaying existing callbacks.
	 *
	 * @dataProvider lifecycle_hooks
	 * @param string $hook_name Hook name.
	 * @param string $wrapper   Users lifecycle wrapper.
	 */
	public function test_after_hook_receives_registry_without_replay( string $hook_name, string $wrapper ): void {
		$registry       = extrachill_users_get_abilities_api_registry( $hook_name );
		$existing_count = 0;
		$new_count      = 0;

		add_action(
			$hook_name,
			static function () use ( &$existing_count ): void {
				++$existing_count;
			}
		);
		do_action( $hook_name, $registry );

		$wrapper(
			function ( $received_registry ) use ( &$new_count, $hook_name, $registry ): void {
				$this->assertTrue( doing_action( $hook_name ) );
				$this->assertSame( $registry, $received_registry );
				++$new_count;
			}
		);

		$this->assertSame( 1, $existing_count );
		$this->assertSame( 1, $new_count );
		$this->assertFalse( doing_action( $hook_name ) );
	}

	/**
	 * Distinct closures created from one declaration remain distinct callbacks.
	 */
	public function test_distinct_closures_from_same_declaration_both_run(): void {
		$count = 0;
		do_action( 'wp_abilities_api_init', extrachill_users_get_abilities_api_registry( 'wp_abilities_api_init' ) );

		$first  = $this->counting_callback( $count );
		$second = $this->counting_callback( $count );
		extrachill_users_on_abilities_api_init( $first );
		extrachill_users_on_abilities_api_init( $second );

		$this->assertSame( 2, $count );
	}

	/**
	 * Distinct invokable instances remain distinct callbacks.
	 */
	public function test_distinct_object_instances_both_run(): void {
		$count = 0;
		do_action( 'wp_abilities_api_init', extrachill_users_get_abilities_api_registry( 'wp_abilities_api_init' ) );

		extrachill_users_on_abilities_api_init( $this->invokable_callback( $count ) );
		extrachill_users_on_abilities_api_init( $this->invokable_callback( $count ) );

		$this->assertSame( 2, $count );
	}

	/**
	 * Repeating the same callback object does not invoke it twice.
	 */
	public function test_same_callback_instance_is_deduped(): void {
		$count = 0;
		do_action( 'wp_abilities_api_init', extrachill_users_get_abilities_api_registry( 'wp_abilities_api_init' ) );

		$callback = $this->counting_callback( $count );
		extrachill_users_on_abilities_api_init( $callback );
		extrachill_users_on_abilities_api_init( $callback );

		$this->assertSame( 1, $count );
	}

	/**
	 * Late callback exceptions cannot leak the temporary current-filter context.
	 */
	public function test_late_exception_restores_current_filter(): void {
		global $wp_current_filter;

		do_action( 'wp_abilities_api_init', extrachill_users_get_abilities_api_registry( 'wp_abilities_api_init' ) );
		$original_stack = $wp_current_filter;

		try {
			extrachill_users_on_abilities_api_init(
				static function (): void {
					throw new RuntimeException( 'Expected lifecycle test exception.' );
				}
			);
			$this->fail( 'The callback exception should propagate.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Expected lifecycle test exception.', $exception->getMessage() );
		}

		$this->assertSame( $original_stack, $wp_current_filter );
		$this->assertFalse( doing_action( 'wp_abilities_api_init' ) );
	}

	/**
	 * A true post-registry declaration can register a category and dependent ability.
	 */
	public function test_post_registry_category_and_ability_register_once(): void {
		$category_name = 'extrachill-users-late-test';
		$ability_name  = 'extrachill-users/late-lifecycle-test';
		$category_runs = 0;
		$ability_runs  = 0;
		$category      = extrachill_users_get_abilities_api_registry( 'wp_abilities_api_categories_init' );
		$abilities     = extrachill_users_get_abilities_api_registry( 'wp_abilities_api_init' );

		do_action( 'wp_abilities_api_categories_init', $category );
		do_action( 'wp_abilities_api_init', $abilities );

		$register_category = function ( $received_registry ) use ( &$category_runs, $category, $category_name ): void {
			$this->assertSame( $category, $received_registry );
			++$category_runs;
			wp_register_ability_category(
				$category_name,
				array(
					'label'       => 'Late test category',
					'description' => 'Verifies late category registration.',
				)
			);
		};
		$register_ability  = function ( $received_registry ) use ( &$ability_runs, $abilities, $ability_name, $category_name ): void {
			$this->assertSame( $abilities, $received_registry );
			++$ability_runs;
			wp_register_ability(
				$ability_name,
				array(
					'label'               => 'Late test ability',
					'description'         => 'Verifies late ability registration.',
					'category'            => $category_name,
					'output_schema'       => array( 'type' => 'boolean' ),
					'permission_callback' => '__return_true',
					'execute_callback'    => '__return_true',
				)
			);
		};

		try {
			extrachill_users_on_abilities_api_categories_init( $register_category );
			extrachill_users_on_abilities_api_init( $register_ability );
			extrachill_users_on_abilities_api_categories_init( $register_category );
			extrachill_users_on_abilities_api_init( $register_ability );

			$this->assertTrue( wp_has_ability_category( $category_name ) );
			$this->assertTrue( wp_has_ability( $ability_name ) );
			$this->assertSame( 1, $category_runs );
			$this->assertSame( 1, $ability_runs );
		} finally {
			if ( wp_has_ability( $ability_name ) ) {
				wp_unregister_ability( $ability_name );
			}
			if ( wp_has_ability_category( $category_name ) ) {
				wp_unregister_ability_category( $category_name );
			}
		}
	}

	/**
	 * Every Users ability declaration uses the late-safe lifecycle helper.
	 */
	public function test_all_users_ability_declarations_use_lifecycle_helper(): void {
		$ability_files = glob( dirname( __DIR__, 2 ) . '/inc/core/abilities/*.php' );
		$this->assertIsArray( $ability_files );

		foreach ( $ability_files as $ability_file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source inspection, not a remote request.
			$source = file_get_contents( $ability_file );
			$this->assertIsString( $source );
			if ( false === strpos( $source, 'wp_register_ability(' ) ) {
				continue;
			}

			$this->assertStringContainsString( 'extrachill_users_on_abilities_api_init(', $source, $ability_file );
			$this->assertDoesNotMatchRegularExpression( "/add_action\(\s*['\"]wp_abilities_api_init['\"]/", $source, $ability_file );
		}
	}

	/**
	 * Create one closure from a shared declaration.
	 *
	 * @param int $count Invocation count.
	 * @return Closure Counting callback.
	 */
	private function counting_callback( int &$count ): Closure {
		return static function () use ( &$count ): void {
			++$count;
		};
	}

	/**
	 * Create one invokable object from a shared declaration.
	 *
	 * @param int $count Invocation count.
	 * @return object Invokable counting callback.
	 */
	private function invokable_callback( int &$count ): object {
		return new class( $count ) {

			/**
			 * Invocation count.
			 *
			 * @var int
			 */
			private $count;

			/**
			 * Build the callback.
			 *
			 * @param int $count Invocation count.
			 */
			public function __construct( int &$count ) {
				$this->count = &$count;
			}

			/**
			 * Record one invocation.
			 *
			 * @param object $registry Core registry.
			 */
			public function __invoke( $registry ): void {
				unset( $registry );
				++$this->count;
			}
		};
	}
}
