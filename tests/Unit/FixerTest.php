<?php
/**
 * Tests for saving fixes, the batches and Undo batch.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor\Tests\Unit;

use AryanX\BlockDoctor\Batches;
use AryanX\BlockDoctor\Fixer;
use AryanX\BlockDoctor\Issues;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class FixerTest extends PluginTestCase {

	/**
	 * Revisions are on unless a test turns them off.
	 *
	 * @var bool
	 */
	private $revisions_on = true;

	protected function setUp(): void {
		parent::setUp();
		$this->revisions_on = true;

		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'wp_check_post_lock' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'wp_revisions_enabled' )->alias(
			function () {
				return $this->revisions_on;
			}
		);
		Functions\when( 'wp_save_post_revision' )->alias(
			function ( $id ) {
				$latest = $this->revisions[ $id ][0] ?? null;
				if ( ! $latest || $latest->post_content !== $this->posts[ $id ]->post_content ) {
					return $this->add_revision( (int) $id, $this->posts[ $id ]->post_content )->ID;
				}
				return null;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			function ( $data ) {
				$this->updates[] = $data;
				$id              = (int) $data['ID'];
				$this->posts[ $id ]->post_content = stripslashes( $data['post_content'] );
				$this->add_revision( $id, $this->posts[ $id ]->post_content );
				return $id;
			}
		);
		Functions\when( 'wp_get_post_revision' )->alias(
			function ( $id ) {
				$post = $this->posts[ $id ] ?? null;
				return $post && 'revision' === $post->post_type ? $post : null;
			}
		);
		Functions\when( 'wp_restore_post_revision' )->alias(
			function ( $id ) {
				$revision = $this->posts[ $id ];
				$this->posts[ $revision->post_parent ]->post_content = $revision->post_content;
				return $revision->post_parent;
			}
		);
	}

	public function test_a_fix_saves_the_post_after_keeping_its_old_content_in_a_revision(): void {
		$plugin = $this->plugin();
		$post   = $this->add_post( 'broken "markup"', array( 'ID' => 7 ) );
		$plugin['issues']->save( $post, array( 'browser' => array( array( 'kind' => 'invalid', 'path' => array( 0 ) ) ) ), md5( 'broken "markup"' ) );
		$batch = $plugin['batches']->create( array( 'invalid', 'nonsense kind' ) );

		Actions\expectDone( 'block_doctor_post_fixed' )->once()->with( 7, $batch['id'], \Mockery::type( 'int' ), array( 'invalid', 'nonsensekind' ) );
		$result = $plugin['fixer']->fix( 7, $batch['id'], 'fixed "markup"', md5( 'broken "markup"' ), array() );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 0, $result['issues'] );
		$this->assertSame( 'broken "markup"', $this->posts[ $result['revision'] ]->post_content, 'the revision holds the content before the fix' );
		$this->assertSame( 'fixed \"markup\"', $this->updates[0]['post_content'], 'content is slashed for wp_update_post' );
		$this->assertSame( 'fixed "markup"', $this->posts[7]->post_content );
		$this->assertSame( array( 7 ), $plugin['batches']->get( $batch['id'] )['posts'] );
		$this->assertNull( $plugin['issues']->get( 7 ), 'nothing left' );

		$undo = $plugin['batches']->undo_data( 7, $batch['id'] );
		$this->assertSame( $result['revision'], $undo['before'] );
		$this->assertSame( md5( 'fixed "markup"' ), $undo['hash'] );
		$this->assertArrayNotHasKey( 'content', $undo );
		$this->assertSame( 'invalid', $undo['issues']['issues'][0]['kind'] );
	}

	public function test_without_revisions_the_old_content_is_kept_with_the_batch(): void {
		$this->revisions_on = false;
		$plugin             = $this->plugin();
		$this->add_post( 'old', array( 'ID' => 7 ) );
		$batch = $plugin['batches']->create( array( 'invalid' ) );

		$result = $plugin['fixer']->fix( 7, $batch['id'], 'new', md5( 'old' ) );

		$this->assertSame( 0, $result['revision'] );
		$this->assertSame( 'old', $plugin['batches']->undo_data( 7, $batch['id'] )['content'] );
	}

	public function test_a_fix_is_refused_when_it_is_not_safe(): void {
		$plugin = $this->plugin();
		$this->add_post( 'old', array( 'ID' => 7 ) );
		$this->add_post( 'old', array( 'ID' => 8, 'post_type' => 'attachment' ) );
		$batch = $plugin['batches']->create( array( 'invalid' ) );
		$fix   = static function ( $id, $batch_id = null, $hash = null ) use ( $plugin, $batch ) {
			$result = $plugin['fixer']->fix( $id, $batch_id ?? $batch['id'], 'new', $hash ?? md5( 'old' ) );
			return $result instanceof \WP_Error ? $result->get_error_code() . ':' . $result->get_error_data()['status'] : 'saved';
		};

		$this->assertSame( 'block_doctor_batch:400', $fix( 7, 99 ) );
		$this->assertSame( 'block_doctor_post:404', $fix( 404 ) );
		$this->assertSame( 'block_doctor_post:404', $fix( 8 ) );
		$this->forbidden = array( 7 );
		$this->assertSame( 'block_doctor_forbidden:403', $fix( 7 ) );
		$this->forbidden = array();
		$this->assertSame( 'block_doctor_changed:409', $fix( 7, null, md5( 'something else' ) ) );

		Functions\when( 'wp_check_post_lock' )->justReturn( 2 );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'display_name' => 'Sam' ) );
		$locked = $plugin['fixer']->fix( 7, $batch['id'], 'new', md5( 'old' ) );
		$this->assertSame( 'Sam has this post open in the editor, so it was not saved.', $locked->get_error_message() );
		$this->assertSame( array(), $this->updates );
	}

	public function test_a_fix_that_changes_nothing_saves_nothing(): void {
		$plugin = $this->plugin();
		$this->add_post( 'same', array( 'ID' => 7 ) );
		$batch = $plugin['batches']->create( array( 'invalid' ) );

		Actions\expectDone( 'block_doctor_post_fixed' )->never();
		$result = $plugin['fixer']->fix( 7, $batch['id'], 'same', md5( 'same' ), array( array( 'kind' => 'missing', 'path' => array( 0 ), 'name' => 'acme/x' ) ) );

		$this->assertFalse( $result['changed'] );
		$this->assertSame( 1, $result['issues'] );
		$this->assertSame( array(), $this->updates );
	}

	public function test_undo_puts_every_post_back_and_skips_the_ones_edited_since(): void {
		$plugin = $this->plugin();
		foreach ( array( 7, 8, 9 ) as $id ) {
			$post = $this->add_post( "old $id", array( 'ID' => $id ) );
			$plugin['issues']->save( $post, array( 'browser' => array( array( 'kind' => 'invalid', 'path' => array( 0 ) ) ) ), md5( "old $id" ) );
		}
		$this->revisions_on = false;
		$this->add_post( 'old 10', array( 'ID' => 10 ) );
		$this->revisions_on = true;
		$batch = $plugin['batches']->create( array( 'invalid' ) );
		foreach ( array( 7, 8, 9 ) as $id ) {
			$plugin['fixer']->fix( $id, $batch['id'], "new $id", md5( "old $id" ) );
		}
		$this->revisions_on = false;
		$plugin['fixer']->fix( 10, $batch['id'], 'new 10', md5( 'old 10' ) );
		$this->posts[8]->post_content = 'edited by someone';
		unset( $this->posts[9] );

		Actions\expectDone( 'block_doctor_batch_undone' )->never();
		$first = $plugin['fixer']->undo( $batch['id'], 2 );
		$this->assertSame( 2, $first['remaining'] );
		$this->assertSame( array( 10 ), $first['batch']['restored'], 'newest first' );
		$this->assertSame( array( 9 => 'deleted' ), $first['batch']['skipped'] );
		$this->assertSame( 'old 10', $this->posts[10]->post_content, 'restored from the kept content' );

		Actions\expectDone( 'block_doctor_batch_undone' )->once()->with( $batch['id'], array( 10, 7 ), array( 9 => 'deleted', 8 => 'edited' ) );
		$second = $plugin['fixer']->undo( $batch['id'] );
		$this->assertSame( 0, $second['remaining'] );
		$this->assertGreaterThan( 0, $second['batch']['undone'] );
		$this->assertSame( 'old 7', $this->posts[7]->post_content, 'restored from the revision' );
		$this->assertSame( 'edited by someone', $this->posts[8]->post_content );
		$this->assertSame( 'invalid', $plugin['issues']->get( 7 )['issues'][0]['kind'], 'the issues from before the fix are back' );
		$this->assertNull( $plugin['batches']->undo_data( 7, $batch['id'] ) );

		$again = $plugin['fixer']->undo( $batch['id'] );
		$this->assertSame( $second['batch'], $again['batch'], 'undoing twice changes nothing' );

		$gone = $plugin['fixer']->fix( 7, $batch['id'], 'x', md5( 'old 7' ) );
		$this->assertSame( 'block_doctor_batch', $gone->get_error_code(), 'an undone batch takes no more fixes' );
	}

	public function test_undo_reports_missing_revisions_and_unknown_batches(): void {
		$plugin = $this->plugin();
		$this->add_post( 'old', array( 'ID' => 7 ) );
		$batch = $plugin['batches']->create( array( 'invalid' ) );
		$plugin['fixer']->fix( 7, $batch['id'], 'new', md5( 'old' ) );
		$before = $plugin['batches']->undo_data( 7, $batch['id'] )['before'];
		unset( $this->posts[ $before ] );

		$this->assertSame( array( 7 => 'revision' ), $plugin['fixer']->undo( $batch['id'] )['batch']['skipped'] );
		$this->assertSame( 'block_doctor_batch', $plugin['fixer']->undo( 404 )->get_error_code() );
	}

	public function test_undo_skips_posts_the_user_cannot_edit_or_has_nothing_for(): void {
		$plugin = $this->plugin();
		$this->add_post( 'old', array( 'ID' => 7 ) );
		$this->add_post( 'old', array( 'ID' => 8 ) );
		$batch = $plugin['batches']->create( array( 'invalid' ) );
		$plugin['fixer']->fix( 7, $batch['id'], 'new', md5( 'old' ) );
		$plugin['fixer']->fix( 8, $batch['id'], 'new', md5( 'old' ) );
		$plugin['batches']->set_undo_data( 8, $batch['id'], null );
		$this->forbidden = array( 7 );

		$this->assertSame( array( 8 => 'nothing', 7 => 'forbidden' ), $plugin['fixer']->undo( $batch['id'] )['batch']['skipped'] );
	}

	public function test_a_fix_that_would_leave_an_empty_custom_html_block_is_refused(): void {
		$plugin = $this->plugin();
		Functions\when( 'wp_check_post_lock' )->justReturn( false );
		Functions\when( 'parse_blocks' )->alias(
			static function ( $content ) {
				$blocks = array();
				preg_match_all( '#<!-- wp:([a-z/-]+)( \{[^}]*\})? (/)?-->(.*?)(?:<!-- /wp:\1 -->|$)#s', (string) $content, $found, PREG_SET_ORDER );
				foreach ( $found as $match ) {
					$name     = false === strpos( $match[1], '/' ) ? 'core/' . $match[1] : $match[1];
					$blocks[] = self::block( $name, array(), array(), empty( $match[3] ) ? (string) $match[4] : '' );
				}
				return $blocks;
			}
		);
		$old  = "<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->\n\n<!-- wp:acme/shots {\"ids\":[4]} /-->";
		$post = $this->add_post( $old, array( 'ID' => 7 ) );
		$batch = $plugin['batches']->create( array( 'missing' ) );

		$result = $plugin['fixer']->fix( 7, $batch['id'], "<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->\n\n<!-- wp:html /-->", md5( $old ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_doctor_empty_html', $result->get_error_code() );
		$this->assertSame( array(), $this->updates, 'nothing saved' );
		$this->assertSame( $old, $post->post_content );

		// A Custom HTML block with markup in it is fine, and so is an empty one that was already there.
		$this->assertSame( 0, Fixer::empty_html_blocks( '<!-- wp:html --><div>x</div><!-- /wp:html -->' ) );
		$this->assertSame( 1, Fixer::empty_html_blocks( '<!-- wp:html --><!-- /wp:html -->' ) );
		$this->assertSame( 0, Fixer::empty_html_blocks( '<p>no blocks</p>' ) );
	}

	public function test_batches_count_up_and_expire_after_the_undo_window(): void {
		$batches = new Batches( new \AryanX\BlockDoctor\Config() );
		$first   = $batches->create( array( 'invalid' ) );
		$second  = $batches->create( array( 'classic' ) );

		$this->assertSame( array( 1, 2 ), array( $first['id'], $second['id'] ) );
		$this->assertSame( array(), $batches->all(), 'a batch that saved nothing has nothing to undo, so it is not listed' );
		foreach ( array( $first, $second ) as $batch ) {
			$batch['posts'] = array( 9 );
			$batches->update( $batch );
		}
		$this->assertSame( array( 2, 1 ), array_keys( $batches->all() ), 'newest first' );

		$batches->set_undo_data( 5, 1, array( 'before' => 3 ) );
		$this->options[ Batches::OPTION ]['items'][1]['time'] = time() - 8 * DAY_IN_SECONDS;
		Filters\expectApplied( 'block_doctor_undo_days' )->andReturn( 7 );

		$this->assertNull( $batches->get( 1 ) );
		$this->assertSame( 3, $batches->create( array() )['id'], 'numbers are never reused' );

		$batches->set_undo_data( 5, 2, array( 'before' => 4 ) );
		$this->assertSame( array( 2 => array( 'before' => 4 ) ), $this->meta[5][ Batches::UNDO_META ], 'undo data of expired batches is dropped' );
		$batches->set_undo_data( 5, 2, null );
		$this->assertArrayNotHasKey( Batches::UNDO_META, $this->meta[5] );

		$batches->update( array( 'id' => 404 ) );
		$batches->clear();
		$this->assertSame( array(), $batches->all() );
	}
}
