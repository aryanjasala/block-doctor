<?php
/**
 * Stand-ins for the WordPress classes Block Doctor works with. The shared stubs
 * in tests/Stubs come from the repo template; these are this plugin's own.
 *
 * @package AryanX\Axle\BlockDoctor
 */

// phpcs:ignoreFile

if ( ! class_exists( 'BD_Test_Post' ) ) {
	/**
	 * A post with every field the plugin reads declared, so PHP 8.2 sees no dynamic properties.
	 */
	class BD_Test_Post extends WP_Post {
		/** @var string */
		public $post_title = '';
		/** @var string */
		public $post_name = '';
		/** @var string */
		public $post_type = 'post';
		/** @var string */
		public $post_status = 'publish';
		/** @var string */
		public $post_date = '2026-01-02 03:04:05';
		/** @var int */
		public $post_parent = 0;
	}
}

if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	/**
	 * The block type registry, with the names a test registers.
	 */
	class WP_Block_Type_Registry {
		/** @var string[] */
		public static $names = array();

		/** @var array<string, string[]> Editor script handles by block name. */
		public static $editor_scripts = array();

		/** @var WP_Block_Type_Registry|null */
		private static $instance;

		public static function get_instance() {
			return self::$instance ?? ( self::$instance = new self() );
		}

		public function is_registered( $name ) {
			return in_array( $name, self::$names, true );
		}

		public function get_all_registered() {
			$types = array();
			foreach ( self::$names as $name ) {
				$types[ $name ] = (object) array(
					'name'                  => $name,
					'editor_script_handles' => self::$editor_scripts[ $name ] ?? array(),
					'script_handles'        => array(),
				);
			}
			return $types;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * The method constants register_rest_route() takes.
	 */
	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
		const DELETABLE = 'DELETE';
	}
}

if ( ! class_exists( 'BD_Test_Query' ) ) {
	/**
	 * A query whose variables can be read, for the posts_where filter. The
	 * template stub's WP_Query already has query_vars and get().
	 */
	class BD_Test_Query extends WP_Query {
	}
}

// The main plugin file defines these; the tests do not load it.
defined( 'AryanX\Axle\BlockDoctor\VERSION' ) || define( 'AryanX\Axle\BlockDoctor\VERSION', '1.0.0' );
defined( 'AryanX\Axle\BlockDoctor\PLUGIN_FILE' ) || define( 'AryanX\Axle\BlockDoctor\PLUGIN_FILE', dirname( __DIR__, 2 ) . '/block-doctor.php' );
