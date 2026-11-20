<?php
/**
 * Tests for keeping what a plugin's dynamic blocks show.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor\Tests\Unit;

use AryanX\Axle\BlockDoctor\Keeper;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class KeeperTest extends PluginTestCase {

	private const EVENTS = '<!-- wp:acme/events {"count":3,"layout":{"type":"grid"}} /-->';

	public function test_blocks_that_show_the_same_to_everyone_are_kept(): void {
		$this->assertSame( '', Keeper::classify( 'acme/events', '<ul><li>Fair</li></ul>', '<ul><li>Fair</li></ul>', '<ul><li>Fair</li></ul>' ) );
		// Nobody to compare with on the command line: a visitor's render is all there is.
		$this->assertSame( '', Keeper::classify( 'acme/events', '<p>Hi</p>', '<p>Hi</p>', '', false ) );
	}

	public function test_blocks_that_depend_on_the_visitor_are_refused_with_a_reason(): void {
		$html = '<p>Hello</p>';

		$this->assertSame( 'visitor', Keeper::classify( 'acme/greeting', '<p>Hello guest</p>', '<p>Hello guest</p>', '<p>Hello Ada</p>' ), 'a logged-in user sees something else' );
		$this->assertSame( 'unstable', Keeper::classify( 'acme/quote', '<p>A</p>', '<p>B</p>', '<p>A</p>' ), 'changes on every render' );
		$this->assertSame( 'empty', Keeper::classify( 'acme/events', "  \n", "  \n", "  \n" ) );
		$this->assertSame( 'form', Keeper::classify( 'acme/survey', '<form><input name="a"></form>', '<form><input name="a"></form>', '<form><input name="a"></form>' ), 'a form needs the plugin' );
		$this->assertSame( 'nonce', Keeper::classify( 'acme/poll', '<div data-nonce="x1"></div>', '<div data-nonce="x1"></div>', '<div data-nonce="x1"></div>' ) );
		$this->assertSame( 'interactive', Keeper::classify( 'acme/tabs', '<div data-wp-interactive="acme">x</div>', '<div data-wp-interactive="acme">x</div>', '<div data-wp-interactive="acme">x</div>' ) );
		$this->assertSame( 'delimiter', Keeper::classify( 'acme/odd', '<!-- wp:html -->x', '<!-- wp:html -->x', '<!-- wp:html -->x' ) );
		$this->assertSame( '', Keeper::classify( 'acme/border-box', $html, $html, $html ), '"border" is not "order"' );
	}

	public function test_the_name_list_refuses_forms_carts_and_accounts_before_anything_renders(): void {
		foreach ( array( 'acme/contact-form', 'shop/mini-cart', 'shop/checkout', 'acme/my_account', 'acme/login' ) as $name ) {
			$this->assertSame( 'name', Keeper::classify( $name, '<p>x</p>', '<p>x</p>', '<p>x</p>' ), $name );
		}
	}

	public function test_the_name_list_has_a_filter(): void {
		Filters\expectApplied( 'block_doctor_keep_denylist' )->once()->andReturn( array( 'events' ) );

		$this->assertSame( 'name', Keeper::classify( 'acme/events', '<p>x</p>', '<p>x</p>', '<p>x</p>' ) );
		// The default list is gone for this call, so a form block with no form tag passes.
		Filters\expectApplied( 'block_doctor_keep_denylist' )->once()->andReturn( array( 'events' ) );
		$this->assertSame( '', Keeper::classify( 'acme/contact-form', '<p>x</p>', '<p>x</p>', '<p>x</p>' ) );
	}

	public function test_spans_find_the_outermost_blocks_of_the_plugin_with_their_exact_text(): void {
		$content = "<!-- wp:paragraph -->\n<p>Intro</p>\n<!-- /wp:paragraph -->\n\n" . self::EVENTS . "\n\n<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:acme/events /--></div>\n<!-- /wp:group -->";

		$spans = Keeper::spans( $content, array( 'acme/events' ) );

		$this->assertCount( 2, $spans );
		$this->assertSame( self::EVENTS, substr( $content, $spans[0][0], $spans[0][1] - $spans[0][0] ), 'attributes and spacing exactly as saved' );
		$this->assertSame( '<!-- wp:acme/events /-->', substr( $content, $spans[1][0], $spans[1][1] - $spans[1][0] ) );
		$this->assertSame( 'acme/events', $spans[1][2] );
	}

	public function test_a_dynamic_block_with_inner_blocks_is_one_span_and_blocks_inside_it_are_not_listed_again(): void {
		$content = "<!-- wp:acme/tabs -->\n<div><!-- wp:acme/tabs --><p>nested</p><!-- /wp:acme/tabs --></div>\n<!-- /wp:acme/tabs -->";

		$spans = Keeper::spans( $content, array( 'acme/tabs' ) );

		$this->assertCount( 1, $spans );
		$this->assertSame( $content, substr( $content, $spans[0][0], $spans[0][1] - $spans[0][0] ) );
	}

	public function test_core_blocks_without_a_namespace_and_malformed_content_are_handled(): void {
		$this->assertCount( 1, Keeper::spans( '<!-- wp:latest-posts /-->', array( 'core/latest-posts' ) ) );
		$this->assertSame( array(), Keeper::spans( '<!-- wp:acme/a --><!-- wp:acme/b --><!-- /wp:acme/a -->', array( 'acme/a' ) ), 'blocks that do not nest are left alone' );
		$this->assertSame( array(), Keeper::spans( '<!-- wp:acme/a -->never closed', array( 'acme/a' ) ) );
		$this->assertSame( array(), Keeper::spans( '<p>No blocks.</p>', array( 'acme/a' ) ) );
	}

	public function test_put_back_gives_the_exact_original_markup_and_leaves_other_edits_alone(): void {
		$original = "<!-- wp:paragraph -->\n<p>Intro</p>\n<!-- /wp:paragraph -->\n\n" . self::EVENTS . "\n\n<!-- wp:acme/events /-->\n\n<!-- wp:paragraph -->\n<p>Outro</p>\n<!-- /wp:paragraph -->";
		$spans    = Keeper::spans( $original, array( 'acme/events' ) );

		$kept      = $original;
		$originals = array();
		foreach ( array_reverse( array_keys( $spans ) ) as $i ) {
			$span                       = $spans[ $i ];
			$marker                     = 'bdk3-9-' . ( $i + 1 );
			$originals[ $marker ]       = substr( $original, $span[0], $span[1] - $span[0] );
			$kept                       = substr_replace( $kept, Keeper::html_block( $marker, '<ul><li>Fair</li></ul>' ), $span[0], $span[1] - $span[0] );
		}
		$this->assertStringContainsString( '<!-- wp:html -->', $kept );
		$this->assertStringNotContainsString( 'acme/events', $kept );

		$result = Keeper::restore_markup( $kept, $originals );
		$this->assertSame( $original, $result['content'], 'byte for byte' );
		$this->assertSame( array( 'bdk3-9-1', 'bdk3-9-2' ), $result['restored'] );

		// Someone edited a paragraph meanwhile: that edit stays.
		$edited = str_replace( 'Outro', 'New outro', $kept );
		$this->assertSame( str_replace( 'Outro', 'New outro', $original ), Keeper::restore_markup( $edited, $originals )['content'] );

		// A kept block somebody deleted has nothing to put back; the others still return.
		$removed = Keeper::restore_markup( str_replace( Keeper::html_block( 'bdk3-9-1', '<ul><li>Fair</li></ul>' ), '', $kept ), $originals );
		$this->assertSame( array( 'bdk3-9-2' ), $removed['restored'] );

		// Custom HTML blocks that are not ours are never touched.
		$other = "<!-- wp:html -->\n<p>Mine</p>\n<!-- /wp:html -->";
		$this->assertSame( $other, Keeper::restore_markup( $other, $originals )['content'] );
	}

	public function test_dynamic_blocks_are_the_registered_blocks_of_a_plugin_that_render_on_the_server(): void {
		\WP_Block_Type_Registry::$names                       = array( 'acme/events', 'acme/static' );
		\WP_Block_Type_Registry::$props['acme/events']        = array( 'render_callback' => 'strlen' );
		$this->options['block_doctor_providers']              = array(
			'acme/events' => array( 'type' => 'plugin', 'slug' => 'acme', 'name' => 'Acme', 'basename' => 'acme/acme.php' ),
			'acme/static' => array( 'type' => 'plugin', 'slug' => 'acme', 'name' => 'Acme', 'basename' => 'acme/acme.php' ),
			'acme/gone'   => array( 'type' => 'plugin', 'slug' => 'acme', 'name' => 'Acme', 'basename' => 'acme/acme.php' ),
		);

		$keeper = $this->plugin()['keeper'];

		$this->assertSame( array( 'acme/events' ), $keeper->dynamic_blocks( 'plugin:acme/acme.php' ) );
		$this->assertSame( array(), $keeper->dynamic_blocks( 'theme:acme' ), 'themes are not kept' );
		unset( \WP_Block_Type_Registry::$props['acme/events'] );
	}

	public function test_the_step_refuses_a_plugin_that_is_off_or_a_key_that_is_not_a_plugin(): void {
		Functions\when( 'is_plugin_active' )->justReturn( false );
		$keeper = $this->plugin()['keeper'];

		$this->assertSame( 'block_doctor_keep_key', $keeper->step( 'theme:x' )->get_error_code() );
		$this->assertSame( 'block_doctor_keep_inactive', $keeper->step( 'plugin:acme/acme.php' )->get_error_code() );
		$this->assertSame( 'block_doctor_keep_nothing', $keeper->put_back( 'plugin:acme/acme.php' )->get_error_code() );
	}

	public function test_put_back_works_through_the_posts_in_chunks_with_a_revision_first_and_keeps_every_post_id(): void {
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'wp_check_post_lock' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'wp_revisions_enabled' )->justReturn( true );
		Functions\when( 'wp_save_post_revision' )->alias(
			function ( $id ) {
				return $this->add_revision( (int) $id, $this->posts[ $id ]->post_content )->ID;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			function ( $data ) {
				$id                               = (int) $data['ID'];
				$this->posts[ $id ]->post_content = stripslashes( $data['post_content'] );
				return $id;
			}
		);
		$plugin = $this->plugin();
		$batch  = $plugin['batches']->create( array( 'keep' ) );
		$key    = 'plugin:acme/acme.php';

		$posts = array();
		foreach ( array( 41, 57, 90 ) as $id ) {
			$kept                          = Keeper::html_block( "bdk{$batch['id']}-{$id}-1", '<p>x</p>' );
			$this->add_post( "Before\n\n{$kept}", array( 'ID' => $id ) );
			$this->meta[ $id ][ Keeper::META ] = array( "bdk{$batch['id']}-{$id}-1" => '<!-- wp:acme/events /-->' );
			$batch                             = (array) $plugin['batches']->get( $batch['id'] );
			$batch['posts'][]                  = $id;
			$plugin['batches']->update( $batch );
			$posts[] = $id;
		}
		$this->options[ Keeper::RUNS ] = array( $key => array( 'name' => 'Acme', 'status' => 'done', 'batches' => array( $batch['id'] ), 'kept' => array( 'blocks' => 3, 'posts' => 3 ) ) );

		Actions\expectDone( 'block_doctor_blocks_put_back' )->times( 3 );
		$first = $plugin['keeper']->put_back( $key, 2 );

		$this->assertSame( array( 'restored' => 2, 'skipped' => array(), 'remaining' => 1 ), $first, 'two posts, one left, none lost to renumbered keys' );
		$this->assertSame( "Before\n\n<!-- wp:acme/events /-->", $this->posts[41]->post_content );
		$this->assertSame( "Before\n\n<!-- wp:acme/events /-->", $this->posts[57]->post_content );
		$this->assertStringContainsString( 'block-doctor-kept', $this->posts[90]->post_content );
		$this->assertCount( 1, $this->revisions[41], 'a revision first' );
		$this->assertStringContainsString( 'block-doctor-kept', $this->revisions[41][0]->post_content );
		$this->assertArrayHasKey( Keeper::RUNS, $this->options, 'the run waits until every post is back' );

		$second = $plugin['keeper']->put_back( $key, 2 );
		$this->assertSame( 1, $second['restored'] );
		$this->assertSame( 0, $second['remaining'] );
		$this->assertArrayNotHasKey( Keeper::RUNS, $this->options, 'forgotten once everything is back' );
		$this->assertGreaterThan( 0, $plugin['batches']->get( $batch['id'] )['undone'] );
	}
}
