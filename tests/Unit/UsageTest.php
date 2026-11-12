<?php
/**
 * Tests for the block usage index.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor\Tests\Unit;

use AryanX\Axle\BlockDoctor\Usage;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class UsageTest extends PluginTestCase {

	/**
	 * The fake database.
	 *
	 * @var \BD_Test_Db
	 */
	private $db;

	protected function setUp(): void {
		parent::setUp();

		$this->db         = new \BD_Test_Db();
		$GLOBALS['wpdb'] = $this->db;

		// The whole meta of a post when no key is asked for, as core returns it.
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key = '', $single = false ) {
				if ( '' === $key ) {
					return array_map( static fn( $value ) => array( $value ), $this->meta[ (int) $id ] ?? array() );
				}
				return $this->meta[ (int) $id ][ $key ] ?? '';
			}
		);
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_post_type_object' )->alias(
			static fn( $type ) => (object) array(
				'labels' => (object) array( 'singular_name' => ucfirst( (string) $type ) ),
				'cap'    => (object) array( 'edit_others_posts' => 'wp_template' === $type ? 'edit_theme_options' : 'edit_others_posts' ),
			)
		);
		Functions\when( 'parse_blocks' )->alias(
			static function ( $content ) {
				$blocks = array();
				// "a b>c": block a, then block b with an inner block c. A leading "~" is classic content.
				foreach ( preg_split( '/\s+/', trim( str_replace( '<!-- wp:x -->', '', (string) $content ) ) ) as $token ) {
					if ( '' === $token ) {
						continue;
					}
					$parts   = explode( '>', $token );
					$inner   = array();
					foreach ( array_slice( $parts, 1 ) as $child ) {
						$inner[] = self::block( $child );
					}
					$blocks[] = '~' === $parts[0] ? self::block( null ) : self::block( $parts[0], array(), $inner );
				}
				return $blocks;
			}
		);
	}

	/**
	 * Makes content the fake parse_blocks() reads, with the marker the index looks for.
	 *
	 * @param string $tokens Tokens such as "core/group>core/image core/image".
	 * @return string
	 */
	private static function content( string $tokens ): string {
		return '<!-- wp:x -->' . $tokens;
	}

	public function test_blocks_are_counted_with_the_ones_inside_other_blocks(): void {
		$counts = Usage::count_blocks( self::content( 'core/group>core/image>core/image core/image ~ core/paragraph' ) );

		$this->assertSame( array( 'core/group' => 1, 'core/image' => 3, 'core/paragraph' => 1 ), $counts );
		$this->assertSame( array(), Usage::count_blocks( '<p>No blocks here</p>' ) );
	}

	public function test_a_saved_post_gets_one_meta_row_for_each_block_type_and_loses_the_ones_it_stopped_using(): void {
		$usage = $this->plugin()['usage'];
		$post  = $this->add_post( self::content( 'core/image core/image acme/slider' ), array( 'ID' => 7 ) );
		$this->meta[7][ Usage::PREFIX . 'acme/gallery' ] = 4;
		$this->meta[7]['_other']                         = 'kept';

		Actions\expectDone( 'block_doctor_post_indexed' )->once()->with( 7, array( 'core/image' => 2, 'acme/slider' => 1 ) );
		$counts = $usage->index_post( $post );

		$this->assertSame( array( 'core/image' => 2, 'acme/slider' => 1 ), $counts );
		$this->assertSame( 2, $this->meta[7][ Usage::PREFIX . 'core/image' ] );
		$this->assertSame( 1, $this->meta[7][ Usage::PREFIX . 'acme/slider' ] );
		$this->assertArrayNotHasKey( Usage::PREFIX . 'acme/gallery', $this->meta[7] );
		$this->assertSame( 'kept', $this->meta[7]['_other'] );
	}

	public function test_a_post_block_doctor_does_not_scan_is_dropped_from_the_index(): void {
		$usage = $this->plugin()['usage'];
		$post  = $this->add_post( self::content( 'core/image' ), array( 'ID' => 8, 'post_status' => 'trash' ) );
		$this->meta[8][ Usage::PREFIX . 'core/image' ] = 1;

		$this->assertSame( array(), $usage->index_post( $post ) );
		$this->assertArrayNotHasKey( Usage::PREFIX . 'core/image', $this->meta[8] );

		// A post that never had a row costs nothing and fires nothing.
		Actions\expectDone( 'block_doctor_post_indexed' )->never();
		$this->assertSame( array(), $usage->index_post( $this->add_post( 'x', array( 'ID' => 9, 'post_type' => 'attachment', 'post_status' => 'inherit' ) ) ) );
	}

	public function test_saving_a_post_updates_the_index_but_revisions_and_autosaves_do_not(): void {
		$usage = $this->plugin()['usage'];
		$usage->register();
		$this->assertNotFalse( has_action( 'save_post', array( $usage, 'on_save' ) ) );
		$this->assertNotFalse( has_action( 'deleted_post', array( $usage, 'forget' ) ) );
		$this->assertNotFalse( has_action( 'trashed_post', array( $usage, 'forget' ) ) );

		$post = $this->add_post( self::content( 'core/cover' ), array( 'ID' => 10 ) );
		$usage->on_save( 10, $post );
		$this->assertSame( 1, $this->meta[10][ Usage::PREFIX . 'core/cover' ] );

		$revision = $this->add_post( self::content( 'core/video' ), array( 'ID' => 11, 'post_type' => 'revision', 'post_status' => 'inherit' ) );
		$usage->on_save( 11, $revision );
		$this->assertArrayNotHasKey( 11, $this->meta );

		$usage->forget( 10 );
		$this->assertArrayNotHasKey( Usage::PREFIX . 'core/cover', $this->meta[10] );
	}

	public function test_the_index_is_built_in_batches_from_the_last_id_and_fires_the_actions(): void {
		$usage = $this->plugin()['usage'];
		$one   = $this->add_post( self::content( 'core/image' ), array( 'ID' => 3 ) );
		$two   = $this->add_post( self::content( 'core/cover' ), array( 'ID' => 4 ) );
		$this->add_post( 'x', array( 'ID' => 5 ) );

		Filters\expectApplied( 'block_doctor_batch_size' )->andReturn( 2 );
		Actions\expectDone( 'block_doctor_usage_index_started' )->once();
		Actions\expectDone( 'block_doctor_usage_index_finished' )->once();

		\WP_Query::$queue = array( array( 'posts' => array( $one, $two ) ), array( 'posts' => array( $this->posts[5] ) ) );

		$state = $usage->start();
		$this->assertSame( 'running', $state['status'] );
		$this->assertFalse( $usage->ready() );

		$state = $usage->step();
		$this->assertSame( array( 'running', 4, 2 ), array( $state['status'], $state['cursor'], $state['done'] ) );
		$this->assertFalse( $usage->ready() );

		$state = $usage->step();
		$this->assertSame( array( 'done', 5, 3 ), array( $state['status'], $state['cursor'], $state['done'] ) );
		$this->assertTrue( $usage->ready() );
		$this->assertSame( 1, $this->meta[3][ Usage::PREFIX . 'core/image' ] );
		$this->assertSame( 4, \WP_Query::$made[1]['block_doctor_after'] );
	}

	public function test_a_rebuild_keeps_the_index_ready(): void {
		$usage                         = $this->plugin()['usage'];
		$this->options[ Usage::STATE ] = array( 'status' => 'done', 'finished' => 1700000000, 'done' => 5, 'total' => 5 );

		$this->assertTrue( $usage->ready() );
		$state = $usage->start();
		$this->assertSame( 'running', $state['status'] );
		$this->assertTrue( $usage->ready(), 'the numbers from the last build stay valid while it runs' );
	}

	public function test_the_first_build_is_scheduled_once_for_people_who_may_use_block_doctor(): void {
		$usage = $this->plugin()['usage'];
		$usage->maybe_schedule();
		$this->assertSame( array( Usage::CRON ), $this->scheduled );

		$this->scheduled = array();
		$this->can_use   = false;
		$usage->maybe_schedule();
		$this->assertSame( array(), $this->scheduled );

		$this->can_use = true;
		Filters\expectApplied( 'block_doctor_usage_auto_index' )->andReturn( false );
		$usage->maybe_schedule();
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_block_types_are_merged_with_the_registered_ones_filtered_and_sorted(): void {
		\WP_Block_Type_Registry::$names = array( 'core/image', 'core/quote', 'acme/slider' );
		\WP_Block_Type_Registry::$props = array( 'acme/slider' => array( 'title' => 'Slider' ) );
		$this->db->rows['GROUP BY m.meta_key'] = array(
			array( 'meta_key' => Usage::PREFIX . 'core/image', 'posts' => '9', 'uses' => '20', 'last' => '2026-11-01 10:00:00' ),
			array( 'meta_key' => Usage::PREFIX . 'acme/slider', 'posts' => '3', 'uses' => '4', 'last' => '2026-11-02 10:00:00' ),
			array( 'meta_key' => Usage::PREFIX . 'acme/gone', 'posts' => '2', 'uses' => '2', 'last' => '2026-10-02 10:00:00' ),
		);
		$usage = $this->plugin()['usage'];

		$rows = $usage->blocks();
		$this->assertSame( array( 'core/image', 'acme/slider', 'acme/gone', 'core/quote' ), array_column( $rows, 'name' ) );
		$this->assertSame( array( 9, 20 ), array( $rows[0]['posts'], $rows[0]['uses'] ) );
		$this->assertSame( strtotime( '2026-11-02 10:00:00 UTC' ), $rows[1]['last'] );
		$this->assertSame( 'Slider', $rows[1]['title'] );
		$this->assertFalse( $rows[2]['registered'] );
		$this->assertTrue( $rows[3]['registered'] );

		$this->assertSame( array( 'acme/gone' ), array_column( $usage->blocks( array( 'filter' => 'unregistered' ) ), 'name' ) );
		$this->assertSame( array( 'core/quote' ), array_column( $usage->blocks( array( 'filter' => 'unused' ) ), 'name' ) );
		$this->assertSame( array( 'core/image', 'core/quote' ), array_column( $usage->blocks( array( 'filter' => 'core' ) ), 'name' ) );
		$this->assertSame( array( 'acme/slider' ), array_column( $usage->blocks( array( 'search' => 'slid' ) ), 'name' ) );
		$this->assertSame( array(), $usage->blocks( array( 'filter' => 'theme' ) ) );
	}

	public function test_a_block_registered_only_in_the_browser_counts_as_registered(): void {
		$this->options[ \AryanX\Axle\BlockDoctor\Checks::JS_BLOCKS ] = array( 'acme/js-only' );
		$this->db->rows['GROUP BY m.meta_key']                      = array( array( 'meta_key' => Usage::PREFIX . 'acme/js-only', 'posts' => '1', 'uses' => '1', 'last' => '2026-11-02 10:00:00' ) );

		$rows = $this->plugin()['usage']->blocks();
		$this->assertTrue( $rows[0]['registered'] );
		$this->assertSame( array(), $this->plugin()['usage']->blocks( array( 'filter' => 'unregistered' ) ) );
	}

	public function test_counts_are_limited_to_the_post_types_a_person_may_see(): void {
		$usage = $this->plugin()['usage'];
		$usage->blocks( array( 'types' => array( 'page', 'attachment' ) ) );

		$sql = end( $this->db->queries );
		$this->assertStringContainsString( "p.post_type IN ('page')", $sql );
		$this->assertStringContainsString( "p.post_status IN ('publish','future','draft','pending','private')", $sql );

		// An empty list is nothing, not everything.
		$this->db->queries = array();
		$rows = $usage->blocks( array( 'types' => array() ) );
		$this->assertSame( array(), $this->db->queries );
		$this->assertSame( array( 0 ), array_unique( array_column( $rows, 'posts' ) ) );
	}

	public function test_visible_types_follow_the_capability_of_each_post_type(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'edit_theme_options' !== $cap );
		$visible = $this->plugin()['usage']->visible_types();

		$this->assertContains( 'post', $visible );
		$this->assertContains( 'wp_block', $visible );
		$this->assertNotContains( 'wp_template', $visible );
	}

	public function test_posts_using_one_or_more_blocks_is_paged_and_cached(): void {
		$this->db->vars['COUNT(DISTINCT']  = '42';
		$this->db->rows['GROUP BY m.post_id'] = array( array( 'id' => '5', 'uses' => '7' ), array( 'id' => '2', 'uses' => '1' ) );
		$cached = array();
		Functions\when( 'wp_cache_get' )->alias( static function ( $key ) use ( &$cached ) {
			return $cached[ $key ] ?? false;
		} );
		Functions\when( 'wp_cache_set' )->alias( static function ( $key, $value ) use ( &$cached ) {
			$cached[ $key ] = $value;
			return true;
		} );
		$usage = $this->plugin()['usage'];

		$found = $usage->posts_using( array( 'acme/slider', 'acme/gallery' ), array( 'page' ), 3, 20 );
		$this->assertSame( array( 'items' => array( array( 'id' => 5, 'uses' => 7 ), array( 'id' => 2, 'uses' => 1 ) ), 'total' => 42 ), $found );

		$sql = end( $this->db->queries );
		$this->assertStringContainsString( "m.meta_key IN ('" . Usage::PREFIX . "acme/slider','" . Usage::PREFIX . "acme/gallery')", $sql );
		$this->assertStringContainsString( 'LIMIT 20 OFFSET 40', $sql );

		$count = count( $this->db->queries );
		$this->assertSame( $found, $usage->posts_using( array( 'acme/slider', 'acme/gallery' ), array( 'page' ), 3, 20 ) );
		$this->assertCount( $count, $this->db->queries, 'the second call is answered from the cache' );

		$this->assertSame( array( 'items' => array(), 'total' => 0 ), $usage->posts_using( array(), null ) );
		$this->assertSame( array( 'items' => array(), 'total' => 0 ), $usage->posts_using( array( 'core/image' ), array() ) );
	}

	public function test_positions_list_where_a_block_sits_with_a_limit(): void {
		$usage = $this->plugin()['usage'];
		$post  = $this->add_post( self::content( 'core/image core/group>core/image>core/image ~ core/image' ), array( 'ID' => 12 ) );

		$this->assertSame( array( array( 0 ), array( 1, 0 ), array( 1, 1 ), array( 3 ) ), $usage->positions( $post, 'core/image', 10 ) );
		$this->assertSame( array( array( 0 ), array( 1, 0 ) ), $usage->positions( $post, 'core/image', 2 ) );
	}

	public function test_clearing_deletes_only_the_usage_rows_and_the_progress(): void {
		$usage                         = $this->plugin()['usage'];
		$this->options[ Usage::STATE ] = array( 'status' => 'done' );

		$this->assertSame( 3, $usage->clear() );
		$this->assertArrayNotHasKey( Usage::STATE, $this->options );
		$this->assertStringContainsString( "DELETE FROM wp_postmeta WHERE meta_key LIKE '", end( $this->db->queries ) );
		$this->assertContains( Usage::CRON, $this->cleared );
	}

	public function test_the_cron_run_indexes_until_done_or_schedules_the_next_run(): void {
		$usage = $this->plugin()['usage'];
		Filters\expectApplied( 'block_doctor_batch_size' )->andReturn( 1 );
		$this->add_post( self::content( 'core/image' ), array( 'ID' => 3 ) );
		\WP_Query::$queue = array( array( 'posts' => array( $this->posts[3] ) ), array( 'posts' => array() ) );
		Filters\expectApplied( 'block_doctor_cron_time_limit' )->andReturn( 5 );

		$usage->run_cron();
		$this->assertSame( 'done', $usage->state()['status'] );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_the_meta_key_is_built_from_a_clean_block_name(): void {
		$this->assertSame( Usage::PREFIX . 'acme/slider', Usage::meta_key( 'ACME/Slider' ) );
		$this->assertSame( 'acme/slider', Usage::name_from_key( Usage::PREFIX . 'acme/slider' ) );
		$this->assertSame( '', Usage::name_from_key( '_other' ) );
	}
}
