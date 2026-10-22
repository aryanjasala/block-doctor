<?php
/**
 * Tests for storing, merging, counting and listing issues.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor\Tests\Unit;

use AryanX\BlockDoctor\Config;
use AryanX\BlockDoctor\Issues;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

final class IssuesTest extends PluginTestCase {

	/**
	 * An issue.
	 *
	 * @param string $kind Kind.
	 * @param int[]  $path Path.
	 * @return array
	 */
	private static function issue( string $kind, array $path = array( 0 ) ): array {
		return array(
			'kind'    => $kind,
			'path'    => $path,
			'name'    => 'core/heading',
			'message' => 'Something is wrong.',
		);
	}

	public function test_save_stores_the_record_and_the_index_and_fires_the_action(): void {
		$post   = $this->add_post( 'x', array( 'ID' => 5 ) );
		$issues = new Issues( new Config() );

		Actions\expectDone( 'block_doctor_issues_found' )
			->once()
			->with(
				5,
				array(
					array(
						'kind'    => 'invalid',
						'path'    => array( 1, 2 ),
						'name'    => 'core/heading',
						'message' => 'Something is wrong.',
						'source'  => 'browser',
					),
				)
			);
		$stored = $issues->save( $post, array( 'browser' => array( self::issue( 'invalid', array( 1, 2 ) ) ) ), 'hash1' );

		$this->assertCount( 1, $stored );
		$this->assertSame( 'hash1', $this->meta[5][ Issues::META ]['hash'] );
		$this->assertSame( array( 5 => array( 'type' => 'post', 'kinds' => array( 'invalid' => 1 ) ) ), $this->options[ Issues::INDEX ] );
	}

	public function test_saving_no_issues_removes_the_post_without_firing_the_action(): void {
		$post   = $this->add_post( 'x', array( 'ID' => 5 ) );
		$issues = new Issues( new Config() );
		$issues->save( $post, array( 'server' => array( self::issue( 'classic' ) ) ), 'h' );

		Actions\expectDone( 'block_doctor_issues_found' )->never();
		$this->assertSame( array(), $issues->save( $post, array( 'server' => array() ), 'h' ) );
		$this->assertArrayNotHasKey( Issues::META, $this->meta[5] );
		$this->assertSame( array(), $this->options[ Issues::INDEX ] );
	}

	public function test_browser_issues_win_over_the_same_server_issue_and_other_sources_stay_while_the_content_is_the_same(): void {
		$post   = $this->add_post( 'x', array( 'ID' => 5 ) );
		$issues = new Issues( new Config() );

		$issues->save( $post, array( 'browser' => array( self::issue( 'missing', array( 1 ) ), self::issue( 'invalid', array( 2 ) ) ) ), 'h' );
		$stored = $issues->save( $post, array( 'server' => array( self::issue( 'missing', array( 1 ) ), self::issue( 'pattern-ref', array( 3 ) ) ) ), 'h' );

		$this->assertSame(
			array( 'missing:1:browser', 'invalid:2:browser', 'pattern-ref:3:server' ),
			array_map(
				static function ( $issue ) {
					return $issue['kind'] . ':' . implode( '.', $issue['path'] ) . ':' . $issue['source'];
				},
				$stored
			)
		);

		$stored = $issues->save( $post, array( 'server' => array( self::issue( 'pattern-ref', array( 3 ) ) ) ), 'changed' );
		$this->assertSame( array( 'pattern-ref' ), array_column( $stored, 'kind' ), 'browser findings for older content are dropped' );
	}

	public function test_malformed_and_unknown_issues_are_cleaned_or_dropped(): void {
		$post   = $this->add_post( 'x', array( 'ID' => 5 ) );
		$issues = new Issues( new Config() );

		$stored = $issues->save(
			$post,
			array(
				'browser' => array(
					'not an issue',
					array( 'kind' => 'nonsense' ),
					array(
						'kind'    => 'INVALID',
						'path'    => array( '3', -1, 'x' ),
						'name'    => 'Core/Heading<script>',
						'message' => str_repeat( 'a', 600 ),
					),
					array( 'kind' => 'classic' ),
				),
			),
			'h'
		);

		$this->assertSame( array( 3, 1, 0 ), $stored[0]['path'] );
		$this->assertSame( 'core/headingscript', $stored[0]['name'] );
		$this->assertSame( 500, strlen( $stored[0]['message'] ) );
		$this->assertSame( array( 0 ), $stored[1]['path'] );
		$this->assertSame( '', $stored[1]['name'], 'an issue about the whole post names no block' );
		$this->assertCount( 2, $stored );
	}

	public function test_a_kind_removed_by_the_filter_is_not_stored(): void {
		Filters\expectApplied( 'block_doctor_issue_kinds' )->andReturnUsing(
			static function ( $kinds ) {
				unset( $kinds['classic'] );
				return $kinds;
			}
		);
		$post = $this->add_post( 'x', array( 'ID' => 5 ) );

		$this->assertSame( array(), ( new Issues( new Config() ) )->save( $post, array( 'server' => array( self::issue( 'classic' ) ) ), 'h' ) );
	}

	public function test_counts_broken_posts_find_and_rows(): void {
		$issues = new Issues( new Config() );
		$a      = $this->add_post( 'a', array( 'ID' => 3 ) );
		$b      = $this->add_post( 'b', array( 'ID' => 9, 'post_type' => 'page', 'post_title' => '' , 'post_name' => 'about' ) );
		$c      = $this->add_post( 'c', array( 'ID' => 7 ) );
		$issues->save( $a, array( 'browser' => array( self::issue( 'invalid' ), self::issue( 'invalid', array( 1 ) ) ) ), md5( 'a' ) );
		$issues->save( $b, array( 'server' => array( self::issue( 'classic' ) ) ), md5( 'old b' ) );
		$issues->save( $c, array( 'server' => array( self::issue( 'missing-media' ), self::issue( 'classic', array( 2 ) ) ) ), md5( 'c' ) );

		$counts = $issues->counts();
		$this->assertSame( array( 'issues' => 2, 'posts' => 1 ), $counts['invalid'] );
		$this->assertSame( array( 'issues' => 2, 'posts' => 2 ), $counts['classic'] );
		$this->assertSame( array( 'issues' => 1, 'posts' => 1 ), $issues->counts( 'page' )['classic'] );
		$this->assertSame( 2, $issues->broken_posts(), 'classic alone is not broken' );

		$this->assertSame( array( 'ids' => array( 3, 7, 9 ), 'total' => 3 ), $issues->find() );
		$this->assertSame( array( 'ids' => array( 7, 9 ), 'total' => 2 ), $issues->find( array( 'kind' => 'classic' ) ) );
		$this->assertSame( array( 'ids' => array( 9 ), 'total' => 1 ), $issues->find( array( 'post_type' => 'page' ) ) );
		$this->assertSame( array( 'ids' => array( 7 ), 'total' => 3 ), $issues->find( array( 'page' => 2, 'per_page' => 1 ) ) );
		$this->assertSame( array( 'ids' => array( 3 ), 'total' => 1 ), $issues->find( array( 'post_id' => 3 ) ) );

		$rows = $issues->rows( array( 9, 7, 404 ), 'classic' );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'about', $rows[0]['title'], 'untitled posts show their slug' );
		$this->assertSame( 'Page', $rows[0]['type_label'] );
		$this->assertTrue( $rows[0]['stale'], 'the content changed since the scan' );
		$this->assertFalse( $rows[1]['stale'] );
		$this->assertSame( array( 'classic' ), array_column( $rows[1]['issues'], 'kind' ) );
	}

	public function test_forget_and_clear(): void {
		$issues = new Issues( new Config() );
		$issues->save( $this->add_post( 'a', array( 'ID' => 3 ) ), array( 'server' => array( self::issue( 'classic' ) ) ), 'h' );
		$issues->save( $this->add_post( 'b', array( 'ID' => 4 ) ), array( 'server' => array( self::issue( 'classic' ) ) ), 'h' );

		$issues->forget( 3 );
		$issues->forget( 99 );
		$this->assertSame( array( 4 ), array_keys( $issues->index() ) );
		$this->assertNull( $issues->get( 3 ) );

		$this->assertSame( 1, $issues->clear() );
		$this->assertSame( array(), $issues->index() );
		$this->assertNull( $issues->get( 4 ) );
	}

	public function test_restore_puts_an_old_record_back(): void {
		$issues = new Issues( new Config() );
		$post   = $this->add_post( 'a', array( 'ID' => 3 ) );
		$record = array(
			'hash'   => 'h',
			'time'   => 1,
			'issues' => array( array_merge( self::issue( 'invalid' ), array( 'source' => 'browser' ) ) ),
		);

		$issues->restore( $post, $record );
		$this->assertSame( $record, $issues->get( 3 ) );
		$this->assertSame( array( 'invalid' => 1 ), $issues->index()[3]['kinds'] );

		$issues->restore( $post, null );
		$this->assertNull( $issues->get( 3 ) );
	}

	public function test_it_forgets_posts_that_are_trashed_or_deleted(): void {
		$issues = new Issues( new Config() );
		$issues->register();

		$this->assertNotFalse( has_action( 'deleted_post', array( $issues, 'forget' ) ) );
		$this->assertNotFalse( has_action( 'trashed_post', array( $issues, 'forget' ) ) );
	}
}
