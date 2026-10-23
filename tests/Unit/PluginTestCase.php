<?php
/**
 * Base class for Block Doctor's unit tests: a small site with posts, post meta
 * and revisions kept in memory.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor\Tests\Unit;

use AryanX\BlockDoctor\Batches;
use AryanX\BlockDoctor\Checks;
use AryanX\BlockDoctor\Config;
use AryanX\BlockDoctor\Fixer;
use AryanX\BlockDoctor\Issues;
use AryanX\BlockDoctor\Posts;
use AryanX\BlockDoctor\Report;
use AryanX\BlockDoctor\Scanner;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Doubles/wordpress.php';

/**
 * Posts, post meta and revisions in memory.
 */
abstract class PluginTestCase extends TestCase {

	/**
	 * Posts by ID.
	 *
	 * @var array<int, \BD_Test_Post>
	 */
	protected $posts = array();

	/**
	 * Post meta: post ID => key => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected $meta = array();

	/**
	 * Revisions by post ID, newest first.
	 *
	 * @var array<int, \BD_Test_Post[]>
	 */
	protected $revisions = array();

	/**
	 * Posts the current user may not edit.
	 *
	 * @var int[]
	 */
	protected $forbidden = array();

	/**
	 * Whether the current user has the Block Doctor capability.
	 *
	 * @var bool
	 */
	protected $can_use = true;

	/**
	 * Every wp_update_post() call.
	 *
	 * @var array[]
	 */
	protected $updates = array();

	/**
	 * Every wp_schedule_single_event() hook.
	 *
	 * @var string[]
	 */
	protected $scheduled = array();

	/**
	 * Every wp_clear_scheduled_hook() hook.
	 *
	 * @var string[]
	 */
	protected $cleared = array();

	/**
	 * Next post ID handed out.
	 *
	 * @var int
	 */
	private $next_id = 1000;

	protected function setUp(): void {
		parent::setUp();

		$this->posts     = array();
		$this->meta      = array();
		$this->revisions = array();
		$this->forbidden = array();
		$this->can_use   = true;
		$this->updates   = array();
		$this->scheduled = array();
		$this->cleared   = array();

		\WP_Block_Type_Registry::$names          = array( 'core/paragraph', 'core/heading', 'core/group', 'core/image', 'core/block', 'core/navigation', 'core/cover', 'core/media-text', 'core/html' );
		\WP_Block_Type_Registry::$editor_scripts = array();

		$supports = array(
			'post'             => array( 'editor', 'revisions' ),
			'page'             => array( 'editor', 'revisions' ),
			'attachment'       => array(),
			'nav_menu_item'    => array( 'editor' ),
			'wp_block'         => array( 'editor', 'revisions' ),
			'wp_template'      => array( 'editor', 'revisions' ),
			'wp_template_part' => array( 'editor', 'revisions' ),
			'wp_global_styles' => array( 'editor', 'revisions' ),
			'wp_navigation'    => array( 'editor', 'revisions' ),
			'note'             => array( 'editor' ),
		);
		Functions\when( 'get_post_types' )->justReturn( array_combine( array_keys( $supports ), array_keys( $supports ) ) );
		Functions\when( 'post_type_exists' )->alias(
			static function ( $type ) use ( $supports ) {
				return isset( $supports[ $type ] );
			}
		);
		Functions\when( 'post_type_supports' )->alias(
			static function ( $type, $feature ) use ( $supports ) {
				return in_array( $feature, $supports[ $type ] ?? array(), true );
			}
		);
		Functions\when( 'get_post_stati' )->justReturn( array_fill_keys( array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit' ), 'x' ) );
		Functions\when( 'get_post_status_object' )->alias(
			static function ( $status ) {
				$labels = array(
					'publish' => 'Published',
					'private' => 'Private',
					'draft'   => 'Draft',
				);
				return isset( $labels[ $status ] ) ? (object) array( 'label' => $labels[ $status ] ) : null;
			}
		);
		Functions\when( 'get_post_type_object' )->alias(
			static function ( $type ) {
				return (object) array( 'labels' => (object) array( 'singular_name' => ucfirst( (string) $type ) ) );
			}
		);

		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				return $this->posts[ (int) $id ] ?? null;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key, $single = false ) {
				return $this->meta[ (int) $id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ (int) $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ (int) $id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'delete_post_meta_by_key' )->alias(
			function ( $key ) {
				foreach ( $this->meta as $id => $keys ) {
					unset( $this->meta[ $id ][ $key ] );
				}
				return true;
			}
		);

		Functions\when( 'current_user_can' )->alias(
			function ( $cap, $id = 0 ) {
				return 'edit_post' === $cap ? ! in_array( (int) $id, $this->forbidden, true ) : $this->can_use;
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_edit_post_link' )->alias(
			static function ( $id ) {
				return 'https://example.com/wp-admin/post.php?post=' . $id . '&action=edit';
			}
		);
		Functions\when( 'wp_list_pluck' )->alias(
			static function ( $list, $field ) {
				$out = array();
				foreach ( (array) $list as $key => $item ) {
					$out[ $key ] = is_object( $item ) ? $item->$field : $item[ $field ];
				}
				return $out;
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $text ) {
				return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( $text ) {
				return strip_tags( (string) $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'number_format_i18n' )->alias( 'strval' );
		Functions\when( 'mysql2date' )->alias(
			static function ( $format, $date ) {
				return substr( (string) $date, 0, 16 );
			}
		);
		Functions\when( 'wp_slash' )->alias( 'addslashes' );
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) {
				$this->cleared[] = $hook;
				return 0;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $time, $hook ) {
				$this->scheduled[] = $hook;
				return true;
			}
		);
		Functions\when( 'wp_count_posts' )->alias(
			function ( $type ) {
				$counts = array();
				foreach ( $this->posts as $post ) {
					if ( $post->post_type === $type ) {
						$counts[ $post->post_status ] = ( $counts[ $post->post_status ] ?? 0 ) + 1;
					}
				}
				return (object) $counts;
			}
		);
		Functions\when( 'wp_get_post_revisions' )->alias(
			function ( $id, $args = array() ) {
				return array_slice( $this->revisions[ (int) $id ] ?? array(), 0, $args['posts_per_page'] ?? 100 );
			}
		);
		Functions\when( 'has_blocks' )->alias(
			static function ( $content ) {
				return false !== strpos( (string) $content, '<!-- wp:' );
			}
		);
	}

	/**
	 * Adds a post.
	 *
	 * @param string $content Content.
	 * @param array  $fields  Other fields.
	 * @return \BD_Test_Post
	 */
	protected function add_post( string $content, array $fields = array() ): \BD_Test_Post {
		$id   = (int) ( $fields['ID'] ?? ++$this->next_id );
		$post = new \BD_Test_Post( array_merge( array( 'ID' => $id, 'post_title' => 'Post ' . $id, 'post_content' => $content ), $fields ) );

		$this->posts[ $id ] = $post;

		return $post;
	}

	/**
	 * Adds a revision of a post, as the newest one.
	 *
	 * @param int    $post_id Post.
	 * @param string $content Content.
	 * @return \BD_Test_Post
	 */
	protected function add_revision( int $post_id, string $content ): \BD_Test_Post {
		$revision = $this->add_post( $content, array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post_id, 'post_date' => '2026-03-04 05:06:07' ) );

		$this->revisions[ $post_id ] = $this->revisions[ $post_id ] ?? array();
		array_unshift( $this->revisions[ $post_id ], $revision );

		return $revision;
	}

	/**
	 * A parsed block, as parse_blocks() returns it.
	 *
	 * @param string|null $name  Block name, null for classic content.
	 * @param array       $attrs Attributes.
	 * @param array       $inner Inner blocks.
	 * @param string      $html  Inner HTML.
	 * @return array
	 */
	protected static function block( ?string $name, array $attrs = array(), array $inner = array(), string $html = '<p>x</p>' ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	/**
	 * The plugin's objects, wired like Plugin does.
	 *
	 * @return array{config: Config, posts: Posts, issues: Issues, checks: Checks, scanner: Scanner, batches: Batches, fixer: Fixer, report: Report}
	 */
	protected function plugin(): array {
		$config  = new Config();
		$posts   = new Posts( $config );
		$issues  = new Issues( $config );
		$checks  = new Checks();
		$scanner = new Scanner( $config, $posts, $issues, $checks );
		$batches = new Batches( $config );

		return array(
			'config'  => $config,
			'posts'   => $posts,
			'issues'  => $issues,
			'checks'  => $checks,
			'scanner' => $scanner,
			'batches' => $batches,
			'fixer'   => new Fixer( $posts, $issues, $batches, $scanner ),
			'report'  => new Report( $config, $issues, $scanner ),
		);
	}
}
