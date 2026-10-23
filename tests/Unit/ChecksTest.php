<?php
/**
 * Tests for the server-side checks.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor\Tests\Unit;

use AryanX\BlockDoctor\Checks;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ChecksTest extends PluginTestCase {

	public function test_unregistered_blocks_are_found_unless_the_browser_registered_them(): void {
		$this->options[ Checks::JS_BLOCKS ] = array( 'acme/js-only' );
		$blocks                             = array(
			self::block( 'core/paragraph' ),
			self::block( null, array(), array(), "\n\n" ),
			self::block( 'acme/widget', array(), array( self::block( 'acme/inner' ) ) ),
			self::block( 'acme/js-only' ),
			self::block( 'core/group', array(), array( self::block( 'core/paragraph' ), self::block( 'old/thing' ) ) ),
		);

		$issues = ( new Checks() )->check_missing( $this->add_post( '' ), $blocks );

		$this->assertSame( array( 'acme/widget', 'old/thing' ), array_column( $issues, 'name' ) );
		$this->assertSame( array( array( 1 ), array( 3, 1 ) ), array_column( $issues, 'path' ), 'blank lines do not count, and nothing inside an unregistered block is checked' );
		$this->assertSame( 'Block type acme/widget is not registered. Its plugin may be inactive or deleted.', $issues[0]['message'] );
	}

	public function test_classic_content_between_blocks_and_whole_classic_posts(): void {
		$checks = new Checks();
		$issues = $checks->check_classic(
			$this->add_post( '' ),
			array(
				self::block( null, array(), array(), "\n<p>Old <b>text</b> that goes on for quite a while, longer than sixty characters for sure.</p>\n" ),
				self::block( 'core/paragraph' ),
				self::block( null, array(), array(), "\n\n" ),
				self::block( 'core/paragraph' ),
				self::block( null, array(), array(), '<p>Tail</p>' ),
			)
		);
		$this->assertSame( array( array( 0 ), array( 3 ) ), array_column( $issues, 'path' ) );
		$this->assertSame( 'Classic content between blocks: "Old text that goes on for quite a while, longer than sixty c..."', $issues[0]['message'] );

		$whole = $checks->check_classic( $this->add_post( '' ), array( self::block( null, array(), array(), '<p>All classic</p>' ) ) );
		$this->assertSame( 'The whole post is classic content, with no blocks.', $whole[0]['message'] );
		$this->assertSame( array(), $checks->check_classic( $this->add_post( '' ), array() ) );
	}

	public function test_an_emptied_post_is_found_from_a_revision_with_blocks(): void {
		$checks = new Checks();
		Functions\when( 'parse_blocks' )->justReturn( array( self::block( 'core/paragraph' ), self::block( null, array(), array(), "\n\n" ), self::block( 'core/heading' ) ) );

		$empty = $this->add_post( "  \n" );
		$this->add_revision( $empty->ID, '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		$this->add_revision( $empty->ID, '' );
		$issues = $checks->check_empty( $empty, array() );
		$this->assertSame( 'empty', $issues[0]['kind'] );
		$this->assertSame( 'The post is empty, but the revision from 2026-03-04 05:06 had 2 blocks.', $issues[0]['message'] );

		$this->assertSame( array(), $checks->check_empty( $this->add_post( 'not empty' ), array() ) );
		$classic = $this->add_post( '' );
		$this->add_revision( $classic->ID, '<p>never had blocks</p>' );
		$this->assertSame( array(), $checks->check_empty( $classic, array() ) );
		$this->assertSame( array(), $checks->check_empty( $this->add_post( '', array( 'post_type' => 'note' ) ), array() ), 'no revisions to look at' );
	}

	public function test_references_to_deleted_patterns_and_menus(): void {
		$checks = new Checks();
		$this->add_post( 'p', array( 'ID' => 10, 'post_type' => 'wp_block' ) );
		$this->add_post( 'p', array( 'ID' => 11, 'post_type' => 'wp_block', 'post_status' => 'trash' ) );
		$this->add_post( 'p', array( 'ID' => 12, 'post_type' => 'post' ) );
		$this->add_post( 'n', array( 'ID' => 20, 'post_type' => 'wp_navigation' ) );
		$blocks = array(
			self::block( 'core/block', array( 'ref' => 10 ) ),
			self::block( 'core/block', array( 'ref' => 11 ) ),
			self::block( 'core/group', array(), array( self::block( 'core/block', array( 'ref' => 12 ) ), self::block( 'core/block', array( 'ref' => 404 ) ) ) ),
			self::block( 'core/block' ),
			self::block( 'core/navigation', array( 'ref' => 20 ) ),
			self::block( 'core/navigation', array( 'ref' => 21 ) ),
		);
		$post   = $this->add_post( '' );

		$patterns = $checks->check_patterns( $post, $blocks );
		$this->assertSame( array( array( 1 ), array( 2, 0 ), array( 2, 1 ) ), array_column( $patterns, 'path' ) );
		$this->assertSame( 'The synced pattern #404 this block shows was deleted, so it renders nothing.', $patterns[2]['message'] );

		$menus = $checks->check_navigation( $post, $blocks );
		$this->assertSame( array( array( 5 ) ), array_column( $menus, 'path' ) );
		$this->assertSame( 'navigation-ref', $menus[0]['kind'] );
	}

	public function test_media_blocks_pointing_at_deleted_attachments(): void {
		$this->add_post( '', array( 'ID' => 30, 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
		$this->add_post( '', array( 'ID' => 31, 'post_type' => 'attachment', 'post_status' => 'trash' ) );
		Functions\expect( '_prime_post_caches' )->once()->with( array( 30, 31, 32, 33 ), false, false );

		$issues = ( new Checks() )->check_media(
			$this->add_post( '' ),
			array(
				self::block( 'core/image', array( 'id' => 30 ) ),
				self::block( 'core/cover', array( 'id' => 31 ) ),
				self::block( 'core/media-text', array( 'mediaId' => 32 ) ),
				self::block( 'core/image' ),
				self::block( 'core/group', array(), array( self::block( 'core/video', array( 'id' => 33 ) ) ) ),
			)
		);

		$this->assertSame( array( 'core/cover', 'core/media-text', 'core/video' ), array_column( $issues, 'name' ) );
		$this->assertSame( array( 4, 0 ), $issues[2]['path'] );
		$this->assertSame( 'Attachment #32 was deleted from the Media Library. The block may still show a copy of the file, or a broken one.', $issues[1]['message'] );
	}

	public function test_the_checks_filter_adds_and_removes_checks_and_a_broken_one_is_reported_not_hidden(): void {
		$post = $this->add_post( 'content' );
		Functions\when( 'parse_blocks' )->justReturn( array( self::block( 'core/paragraph' ) ) );
		Filters\expectApplied( 'block_doctor_server_checks' )->once()->andReturnUsing(
			function ( array $checks ) use ( $post ) {
				$this->assertSame( array( 'missing', 'classic', 'empty', 'pattern-ref', 'navigation-ref', 'missing-media' ), array_keys( $checks ) );
				return array(
					'shortcodes' => static function ( $given, $blocks ) use ( $post ) {
						return $given === $post && 1 === count( $blocks ) ? array( array( 'kind' => 'shortcode', 'path' => array( 0 ) ), 'junk' ) : array();
					},
					'broken'     => static function () {
						throw new \RuntimeException( 'Oops' );
					},
					'not-a-check' => 'no_such_function_here',
				);
			}
		);

		$this->assertSame(
			array(
				array(
					'kind' => 'shortcode',
					'path' => array( 0 ),
				),
				// A check that throws is reported, so the post is never stored as clean.
				array(
					'kind'    => 'error',
					'path'    => array( 0 ),
					'name'    => '',
					'message' => 'The broken check stopped with an error, so this post was not fully checked.',
					'detail'  => 'Oops',
				),
			),
			( new Checks() )->run( $post )
		);
	}

	public function test_walk_counts_real_blocks_only(): void {
		$seen = array();
		Checks::walk(
			array(
				self::block( null, array(), array(), "\n" ),
				self::block( 'core/group', array(), array( self::block( 'core/paragraph' ) ) ),
				self::block( null, array(), array(), '<p>classic</p>' ),
				self::block( 'core/columns', array(), array( self::block( 'core/column' ) ) ),
			),
			static function ( array $block, array $path ) use ( &$seen ): bool {
				$seen[] = $block['blockName'] . '@' . implode( '.', $path );
				return 'core/columns' !== $block['blockName'];
			}
		);

		$this->assertSame( array( 'core/group@0', 'core/paragraph@0.0', 'core/columns@2' ), $seen );
	}
}
