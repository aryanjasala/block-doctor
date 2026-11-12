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

		/** @var array<string, array> Other properties of a block type by name: title, render_callback, style_handles. */
		public static $props = array();

		/** @var WP_Block_Type_Registry|null */
		private static $instance;

		public static function get_instance() {
			return self::$instance ?? ( self::$instance = new self() );
		}

		public function is_registered( $name ) {
			return in_array( $name, self::$names, true );
		}

		public function get_registered( $name ) {
			return $this->get_all_registered()[ $name ] ?? null;
		}

		public function get_all_registered() {
			$types = array();
			foreach ( self::$names as $name ) {
				$types[ $name ] = (object) array_merge(
					array(
						'name'                  => $name,
						'title'                 => '',
						'editor_script_handles' => self::$editor_scripts[ $name ] ?? array(),
						'script_handles'        => array(),
						'style_handles'         => array(),
						'render_callback'       => null,
					),
					self::$props[ $name ] ?? array()
				);
			}
			return $types;
		}
	}
}

if ( ! class_exists( 'WP_Theme' ) ) {
	/**
	 * A theme, with the two names the plugin reads.
	 */
	class WP_Theme {
		public function get_stylesheet() {
			return '';
		}

		public function get_template() {
			return '';
		}
	}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * WP-CLI's output, recorded.
	 */
	class WP_CLI {
		/** @var string[] */
		public static $warnings = array();

		public static function warning( $message ) {
			self::$warnings[] = (string) $message;
		}

		public static function add_command() {
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

if ( ! class_exists( 'BD_Test_Db' ) ) {
	/**
	 * A stand-in for $wpdb: it records every query and answers from rows a test sets.
	 */
	class BD_Test_Db {
		/** @var string */
		public $prefix = 'wp_';
		/** @var string */
		public $posts = 'wp_posts';
		/** @var string */
		public $postmeta = 'wp_postmeta';
		/** @var string[] Every query run, with its values filled in. */
		public $queries = array();
		/** @var array<string, array> Rows by a fragment of the SQL. */
		public $rows = array();
		/** @var array<string, mixed> Single values by a fragment of the SQL. */
		public $vars = array();

		public function esc_like( $text ) {
			return addcslashes( (string) $text, '_%\\' );
		}

		public function prepare( $sql, ...$args ) {
			$args = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
			$i    = 0;
			return (string) preg_replace_callback(
				'/%[sd]/',
				static function ( $m ) use ( &$i, $args ) {
					$value = $args[ $i++ ] ?? '';
					return '%d' === $m[0] ? (string) (int) $value : "'" . $value . "'";
				},
				$sql
			);
		}

		public function get_results( $sql, $output = null ) {
			$this->queries[] = $sql;
			foreach ( $this->rows as $fragment => $rows ) {
				if ( false !== strpos( $sql, $fragment ) ) {
					return $rows;
				}
			}
			return array();
		}

		public function get_var( $sql ) {
			$this->queries[] = $sql;
			foreach ( $this->vars as $fragment => $value ) {
				if ( false !== strpos( $sql, $fragment ) ) {
					return $value;
				}
			}
			return 0;
		}

		public function query( $sql ) {
			$this->queries[] = $sql;
			return 3;
		}
	}
}

// The main plugin file defines these; the tests do not load it.
defined( 'AryanX\Axle\BlockDoctor\VERSION' ) || define( 'AryanX\Axle\BlockDoctor\VERSION', '1.0.0' );
defined( 'AryanX\Axle\BlockDoctor\PLUGIN_FILE' ) || define( 'AryanX\Axle\BlockDoctor\PLUGIN_FILE', dirname( __DIR__, 2 ) . '/block-doctor.php' );
