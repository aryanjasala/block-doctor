<?php
/**
 * Tests for the browser scan's progress and the server-side scan.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor\Tests\Unit;

use AryanX\BlockDoctor\Checks;
use AryanX\BlockDoctor\Issues;
use AryanX\BlockDoctor\Scanner;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ScannerTest extends PluginTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Content with "classic" in it parses as classic content, anything else as one paragraph.
		Functions\when( 'parse_blocks' )->alias(
			function ( $content ) {
				return false !== strpos( (string) $content, 'classic' )
					? array( self::block( null, array(), array(), '<p>classic</p>' ) )
					: array( self::block( 'core/paragraph' ) );
			}
		);
	}

	/**
	 * A browser issue.
	 *
	 * @return array
	 */
	private static function invalid(): array {
		return array(
			'kind'    => 'invalid',
			'path'    => array( 0 ),
			'name'    => 'core/paragraph',
			'message' => 'Bad.',
		);
	}

	public function test_starting_a_browser_scan_remembers_the_browser_blocks_and_fires_the_action(): void {
		$this->add_post( 'a' );
		$this->add_post( 'b', array( 'post_status' => 'draft' ) );
		$this->add_post( 'c', array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
		$scanner = $this->plugin()['scanner'];

		Actions\expectDone( 'block_doctor_scan_started' )
			->once()
			->with( 'browser', \Mockery::on( static fn( $state ) => 'running' === $state['status'] && 2 === $state['total'] && 0 === $state['cursor'] ) );
		$state = $scanner->start_browser( array( 'core/paragraph', 'acme/js-only', 'Bad Name', 42, 'acme/js-only' ) );

		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( array( 'core/paragraph', 'acme/js-only' ), $this->options[ Checks::JS_BLOCKS ] );
	}

	public function test_browser_results_are_stored_with_the_server_checks_and_the_scan_moves_on(): void {
		$plugin  = $this->plugin();
		$scanner = $plugin['scanner'];
		$scanner->start_browser( array() );
		$a       = $this->add_post( 'a', array( 'ID' => 3 ) );
		$b       = $this->add_post( 'classic', array( 'ID' => 4 ) );
		$c       = $this->add_post( 'c', array( 'ID' => 5 ) );
		$d       = $this->add_post( 'd', array( 'ID' => 6, 'post_type' => 'attachment' ) );
		$this->forbidden = array( 5 );

		Actions\expectDone( 'block_doctor_scan_finished' )->never();
		$state = $scanner->record(
			6,
			4,
			array(
				array( 'id' => 3, 'hash' => md5( 'a' ), 'issues' => array( self::invalid() ) ),
				array( 'id' => 4, 'hash' => 'stale', 'issues' => array( self::invalid() ) ),
				array( 'id' => 5, 'hash' => md5( 'c' ), 'issues' => array( self::invalid() ) ),
				array( 'id' => 6, 'hash' => md5( 'd' ), 'issues' => array( self::invalid() ) ),
				'junk',
			),
			false
		);

		$this->assertSame( array( 'running', 6, 4, 2 ), array( $state['status'], $state['cursor'], $state['done'], $state['found'] ) );
		$this->assertSame( array( 'invalid' ), array_column( $plugin['issues']->get( 3 )['issues'], 'kind' ) );
		$this->assertSame( array( 'classic' ), array_column( $plugin['issues']->get( 4 )['issues'], 'kind' ), 'the browser read older content, so only the server findings count' );
		$this->assertNull( $plugin['issues']->get( 5 ), 'not editable by this user' );
		$this->assertNull( $plugin['issues']->get( 6 ), 'not a scanned post type' );

		Actions\expectDone( 'block_doctor_scan_finished' )->once()->with( 'browser', \Mockery::on( static fn( $state ) => 'done' === $state['status'] && 5 === $state['done'] ) );
		$state = $scanner->record( 6, 1, array(), true );
		$this->assertSame( 'done', $state['status'] );
		$this->assertGreaterThan( 0, $state['finished'] );
	}

	public function test_a_batch_of_results_writes_the_index_once_not_once_per_post(): void {
		$scanner = $this->plugin()['scanner'];
		$results = array();
		for ( $i = 1; $i <= 20; $i++ ) {
			$post      = $this->add_post( 'post ' . $i );
			$results[] = array(
				'id'     => $post->ID,
				'hash'   => md5( $post->post_content ),
				'issues' => array( self::invalid() ),
			);
		}
		$scanner->start_browser( array() );
		$writes = $this->count_index_writes();

		$state = $scanner->record( 20, 20, $results, false );

		$this->assertSame( 1, $writes(), 'one write of block_doctor_index for the batch' );
		$this->assertSame( 20, $state['found'] );
		$this->assertCount( 20, $this->options[ Issues::INDEX ] );
	}

	public function test_a_server_batch_writes_the_index_once(): void {
		$scanner = $this->plugin()['scanner'];
		$posts   = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$posts[] = $this->add_post( 'classic ' . $i );
		}
		\WP_Query::$queue = array( array( 'posts' => $posts ) );
		$scanner->begin_server();
		$writes = $this->count_index_writes();

		$batch = $scanner->server_batch();

		$this->assertSame( 1, $writes() );
		$this->assertCount( 5, $batch['found'] );
		$this->assertCount( 5, $this->options[ Issues::INDEX ] );
	}

	public function test_deleting_many_posts_writes_the_index_once_when_the_request_ends(): void {
		$issues = $this->plugin()['issues'];
		$ids    = array();
		foreach ( range( 1, 4 ) as $i ) {
			$post  = $this->add_post( 'classic ' . $i );
			$ids[] = $post->ID;
			$issues->save( $post, array( 'server' => array( self::invalid() ) ), 'h' );
		}
		$writes = $this->count_index_writes();

		foreach ( $ids as $id ) {
			$issues->forget( $id );
		}
		$this->assertSame( 0, $writes(), 'nothing written while the request runs' );
		$this->assertSame( array(), $issues->index(), 'but the index already reads without them' );
		$this->assertNotFalse( has_action( 'shutdown', array( $issues, 'commit' ) ) );

		$issues->commit();
		$this->assertSame( 1, $writes() );
		$this->assertArrayNotHasKey( Issues::INDEX, $this->options, 'an empty index is deleted' );
	}

	/**
	 * Counts update_option() calls for the index from now on.
	 *
	 * @return callable(): int
	 */
	private function count_index_writes(): callable {
		$count = 0;
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$count ) {
				if ( Issues::INDEX === $name ) {
					++$count;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$count ) {
				if ( Issues::INDEX === $name ) {
					++$count;
				}
				unset( $this->options[ $name ] );
				return true;
			}
		);

		return static function () use ( &$count ): int {
			return $count;
		};
	}

	public function test_pause_and_resume_only_touch_a_scan_in_progress(): void {
		$scanner = $this->plugin()['scanner'];
		$this->assertSame( 'idle', $scanner->pause_browser( true )['status'] );

		$scanner->start_browser( array() );
		$this->assertSame( 'paused', $scanner->pause_browser( true )['status'] );
		$this->assertSame( 'running', $scanner->pause_browser( false )['status'] );

		$this->options[ Scanner::BROWSER ] = 'garbage';
		$this->assertSame( array( 'status' => 'idle', 'started' => 0, 'finished' => 0, 'cursor' => 0, 'done' => 0, 'total' => 0, 'found' => 0, 'post_type' => '' ), $scanner->state( 'browser' ) );
	}

	public function test_the_server_scan_runs_in_batches_and_carries_on_with_wp_cron(): void {
		Filters\expectApplied( 'block_doctor_batch_size' )->andReturn( 2 );
		$plugin = $this->plugin();
		$a      = $this->add_post( 'a', array( 'ID' => 3 ) );
		$b      = $this->add_post( 'classic', array( 'ID' => 4 ) );
		$c      = $this->add_post( 'classic', array( 'ID' => 5, 'post_type' => 'page' ) );
		\WP_Query::$queue = array( array( 'posts' => array( $a, $b ) ), array( 'posts' => array( $c ) ) );

		Actions\expectDone( 'block_doctor_scan_started' )->once()->with( 'server', \Mockery::type( 'array' ) );
		$state = $plugin['scanner']->start_server( '', true );

		$this->assertSame( array( 'running', 4, 2, 1 ), array( $state['status'], $state['cursor'], $state['done'], $state['found'] ) );
		$this->assertSame( array( Scanner::CRON ), $this->scheduled );
		$this->assertSame( 0, \WP_Query::$made[0]['block_doctor_after'] );
		$this->assertSame( array( 'post', 'page', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'note' ), \WP_Query::$made[0]['post_type'] );
		$this->assertSame( 2, \WP_Query::$made[0]['posts_per_page'] );

		Actions\expectDone( 'block_doctor_scan_finished' )->once()->with( 'server', \Mockery::on( static fn( $state ) => 3 === $state['done'] ) );
		$batch = $plugin['scanner']->server_batch();
		$this->assertSame( 'done', $batch['state']['status'] );
		$this->assertSame( 4, \WP_Query::$made[1]['block_doctor_after'] );
		$this->assertSame( array( 5 ), array_keys( $batch['found'] ) );
		$this->assertSame( array( 'classic' ), array_column( $batch['found'][5], 'kind' ) );

		$this->assertSame( 'done', $plugin['scanner']->server_batch()['state']['status'], 'a finished scan does nothing' );
		$this->assertCount( 2, \WP_Query::$made );
	}

	public function test_one_post_type_and_wp_cron_runs_until_done(): void {
		Filters\expectApplied( 'block_doctor_batch_size' )->andReturn( 1 );
		$plugin = $this->plugin();
		$a      = $this->add_post( 'a', array( 'ID' => 3, 'post_type' => 'page' ) );
		$b      = $this->add_post( 'b', array( 'ID' => 4, 'post_type' => 'page' ) );
		\WP_Query::$queue = array( array( 'posts' => array( $a ) ), array( 'posts' => array( $b ) ), array( 'posts' => array() ) );

		$plugin['scanner']->begin_server( 'page' );
		$this->assertSame( 2, $plugin['scanner']->state( 'server' )['total'] );
		$plugin['scanner']->run_cron();

		$this->assertSame( 'done', $plugin['scanner']->state( 'server' )['status'] );
		$this->assertSame( array( 'page' ), \WP_Query::$made[0]['post_type'] );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_the_posts_query_adds_the_id_condition_only_to_its_own_query(): void {
		global $wpdb;
		$wpdb = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			public $posts = 'wp_posts';

			public function prepare( $query, ...$args ) {
				return vsprintf( $query, $args );
			}
		};
		$posts = $this->plugin()['posts'];
		$mine  = new \BD_Test_Query( array() );
		$other = new \BD_Test_Query( array() );
		$mine->query_vars = array( 'block_doctor_after' => 41 );

		$this->assertSame( 'WHERE 1=1 AND wp_posts.ID > 41', $posts->where_after( 'WHERE 1=1', $mine ) );
		$this->assertSame( 'WHERE 1=1', $posts->where_after( 'WHERE 1=1', $other ) );
	}

	public function test_scan_post_reports_what_the_server_checks_found(): void {
		$plugin = $this->plugin();
		$post   = $this->add_post( 'classic' );
		$server = null;

		$stored = $plugin['scanner']->scan_post( $post, array( self::invalid() ), md5( 'classic' ), $server );

		$this->assertSame( array( 'classic' ), array_column( $server, 'kind' ) );
		$this->assertSame( array( 'invalid', 'classic' ), array_column( $stored, 'kind' ) );
	}

	public function test_reset_forgets_both_scans(): void {
		$scanner = $this->plugin()['scanner'];
		$scanner->start_browser( array() );
		$scanner->reset();

		$this->assertSame( 'idle', $scanner->state( 'browser' )['status'] );
		$this->assertArrayNotHasKey( Scanner::BROWSER, $this->options );
	}

	public function test_the_cron_hook_is_registered(): void {
		$scanner = $this->plugin()['scanner'];
		$scanner->register();

		$this->assertNotFalse( has_action( Scanner::CRON, array( $scanner, 'run_cron' ) ) );
	}
}
