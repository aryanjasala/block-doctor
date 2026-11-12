<?php
/**
 * Tests for the warnings before a plugin or theme that provides blocks is switched off.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor\Tests\Unit;

use AryanX\Axle\BlockDoctor\Deactivation;
use AryanX\Axle\BlockDoctor\Providers;
use AryanX\Axle\BlockDoctor\Usage;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class DeactivationTest extends PluginTestCase {

	private const KEY = 'plugin:acme-blocks/acme-blocks.php';

	/**
	 * The fake database.
	 *
	 * @var \BD_Test_Db
	 */
	private $db;

	protected function setUp(): void {
		parent::setUp();

		$this->db        = new \BD_Test_Db();
		$GLOBALS['wpdb'] = $this->db;

		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				// Like core: the values are not encoded for you.
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', array_map( static fn( $k, $v ) => $k . '=' . $v, array_keys( $args ), $args ) );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'get_post_type_object' )->alias(
			static fn( $type ) => (object) array(
				'labels' => (object) array( 'singular_name' => ucfirst( (string) $type ) ),
				'cap'    => (object) array( 'edit_others_posts' => 'edit_others_posts' ),
			)
		);

		// Acme Blocks was switched off: its block is not registered, and the index knows who provided it.
		\WP_Block_Type_Registry::$names                       = array( 'core/image' );
		$this->options[ Providers::REMEMBERED ]               = array( 'acme/slider' => array( 'type' => 'plugin', 'slug' => 'acme-blocks', 'name' => 'Acme Blocks', 'basename' => 'acme-blocks/acme-blocks.php' ) );
		$this->options[ Usage::STATE ]                        = array( 'status' => 'done', 'finished' => 1700000000 );
		$this->db->rows['GROUP BY m.meta_key']                = array( array( 'meta_key' => Usage::PREFIX . 'acme/slider', 'posts' => '212', 'uses' => '230', 'last' => '2026-11-02 10:00:00' ) );
		$this->db->vars['COUNT(DISTINCT']                     = '212';
		$this->db->rows['GROUP BY m.post_id']                 = array( array( 'id' => '5', 'uses' => '9' ), array( 'id' => '6', 'uses' => '3' ), array( 'id' => '7', 'uses' => '2' ) );
		$this->add_post( 'x', array( 'ID' => 5, 'post_title' => 'Home' ) );
		$this->add_post( 'x', array( 'ID' => 6, 'post_title' => '', 'post_name' => 'about' ) );
		$this->add_post( 'x', array( 'ID' => 7, 'post_title' => 'Secret' ) );
		$this->forbidden = array( 7 );
	}

	public function test_the_warning_counts_block_types_and_posts_and_lists_the_top_posts_a_person_may_edit(): void {
		$warning = $this->plugin()['deactivation']->warning( self::KEY );

		$this->assertSame( 'Acme Blocks', $warning['name'] );
		$this->assertSame( 1, $warning['block_count'] );
		$this->assertSame( 212, $warning['posts'] );
		$this->assertSame( 'Its 1 block type is used in 212 posts.', $warning['message'] );
		$this->assertSame( array( 5, 6 ), array_column( $warning['top'], 'id' ), 'a post the person cannot edit never shows' );
		$this->assertSame( 'about', $warning['top'][1]['title'] );
		$this->assertSame( 'https://example.com/wp-admin/tools.php?page=block-doctor&tab=usage&provider=' . rawurlencode( self::KEY ), $warning['url'] );
	}

	public function test_the_message_and_the_whole_warning_go_through_filters(): void {
		Filters\expectApplied( 'block_doctor_deactivation_message' )->andReturn( 'Careful.' );
		$this->assertSame( 'Careful.', $this->plugin()['deactivation']->warning( self::KEY )['message'] );

		Filters\expectApplied( 'block_doctor_deactivation_warning' )->andReturn( array() );
		$this->assertSame( array(), $this->plugin()['deactivation']->warning( self::KEY ) );
	}

	public function test_no_warning_before_the_index_is_built_or_when_no_block_is_in_use(): void {
		unset( $this->options[ Usage::STATE ] );
		$this->assertSame( array(), $this->plugin()['deactivation']->warning( self::KEY ) );

		$this->options[ Usage::STATE ]         = array( 'status' => 'done', 'finished' => 1700000000 );
		$this->db->rows['GROUP BY m.meta_key'] = array();
		$this->assertSame( array(), $this->plugin()['deactivation']->warning( self::KEY ) );
		$this->assertSame( array(), $this->plugin()['deactivation']->warning( 'plugin:other/other.php' ) );
	}

	public function test_orphans_are_blocks_in_posts_whose_plugin_is_off(): void {
		$orphans = $this->plugin()['deactivation']->orphans();

		$this->assertSame( array( self::KEY ), array_keys( $orphans ) );
		$this->assertSame( array( 'acme/slider' ), $orphans[ self::KEY ]['blocks'] );
		$this->assertSame( 212, $orphans[ self::KEY ]['posts'] );

		// Once the block is registered again, nothing is left behind.
		\WP_Block_Type_Registry::$names[] = 'acme/slider';
		$this->assertSame( array(), $this->plugin()['deactivation']->orphans() );
	}

	public function test_turning_a_plugin_off_notes_it_and_fires_the_action_and_turning_it_on_clears_the_note(): void {
		$deactivation = $this->plugin()['deactivation'];

		Actions\expectDone( 'block_doctor_blocks_orphaned' )->once()->with( 'plugin', self::KEY, \Mockery::on( static fn( $warning ) => 212 === $warning['posts'] ) );
		$deactivation->after_deactivate( 'acme-blocks/acme-blocks.php' );
		$this->assertSame( array( self::KEY ), array_keys( $this->options[ Deactivation::AFFECTED ] ) );

		$deactivation->after_activate( 'acme-blocks/acme-blocks.php' );
		$this->assertArrayNotHasKey( Deactivation::AFFECTED, $this->options );

		// A plugin nobody uses blocks from leaves no note.
		Actions\expectDone( 'block_doctor_blocks_orphaned' )->never();
		$deactivation->after_deactivate( 'other/other.php' );
		$this->assertArrayNotHasKey( Deactivation::AFFECTED, $this->options );
	}

	public function test_switching_themes_notes_the_old_theme_and_forgets_the_new_one(): void {
		$old = new class() extends \WP_Theme {
			public function get_stylesheet() {
				return 'acme-theme';
			}
		};
		$this->options[ Providers::REMEMBERED ]['acme/card'] = array( 'type' => 'theme', 'slug' => 'acme-theme', 'name' => 'Acme Theme', 'basename' => '' );
		$this->db->rows['GROUP BY m.meta_key']               = array( array( 'meta_key' => Usage::PREFIX . 'acme/card', 'posts' => '4', 'uses' => '4', 'last' => '2026-11-02 10:00:00' ) );
		$this->db->vars['COUNT(DISTINCT']                    = '4';
		$this->options[ Deactivation::AFFECTED ]             = array( 'theme:next-theme' => 1 );

		$new = new class() extends \WP_Theme {
			public function get_stylesheet() {
				return 'next-theme';
			}
			public function get_template() {
				return 'next-theme';
			}
		};
		$this->plugin()['deactivation']->on_switch_theme( 'Next', $new, $old );

		$this->assertSame( array( 'theme:acme-theme' ), array_keys( $this->options[ Deactivation::AFFECTED ] ) );
	}

	public function test_the_notice_says_what_happened_and_goes_away_when_nothing_is_left_to_fix(): void {
		$this->options[ Deactivation::AFFECTED ] = array( self::KEY => 1, 'plugin:gone/gone.php' => 1 );
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'plugins' ) );
		Functions\when( 'wp_nonce_url' )->alias( static fn( $url ) => $url . '&_wpnonce=n' );
		Functions\when( 'number_format_i18n' )->alias( 'strval' );

		ob_start();
		$this->plugin()['deactivation']->notices();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Acme Blocks is off, and its 1 block type is still used in 212 posts.', $html );
		$this->assertStringContainsString( 'tab=usage&amp;provider=', str_replace( '&', '&amp;', $html ) );
		$this->assertStringContainsString( 'action=block_doctor_dismiss_affected', $html );
		$this->assertSame( array( self::KEY ), array_keys( $this->options[ Deactivation::AFFECTED ] ), 'a plugin with nothing left to fix is dropped' );
	}

	public function test_the_notice_only_shows_on_the_screens_that_make_sense_and_to_people_who_may_use_block_doctor(): void {
		$this->options[ Deactivation::AFFECTED ] = array( self::KEY => 1 );
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'edit-post' ) );
		ob_start();
		$this->plugin()['deactivation']->notices();
		$this->assertSame( '', (string) ob_get_clean() );

		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'plugins' ) );
		$this->can_use = false;
		ob_start();
		$this->plugin()['deactivation']->notices();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_dismissing_a_notice_needs_the_capability_and_forgets_that_plugin(): void {
		$this->options[ Deactivation::AFFECTED ] = array( self::KEY => 1 );
		Functions\expect( 'check_admin_referer' )->once()->with( Deactivation::DISMISS );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_get_referer' )->justReturn( 'https://example.com/wp-admin/plugins.php' );
		Functions\expect( 'wp_safe_redirect' )->once()->with( 'https://example.com/wp-admin/plugins.php' )->andReturnUsing(
			static function () {
				throw new \RuntimeException( 'redirected' );
			}
		);
		$_GET['key'] = self::KEY;

		try {
			$this->plugin()['deactivation']->dismiss();
			$this->fail( 'It should redirect.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
		unset( $_GET['key'] );
		$this->assertArrayNotHasKey( Deactivation::AFFECTED, $this->options );
	}

	public function test_the_services_hook_into_plugin_and_theme_changes(): void {
		$deactivation = $this->plugin()['deactivation'];
		$deactivation->register();

		$this->assertNotFalse( has_action( 'deactivate_plugin', array( $deactivation, 'before_deactivate' ) ) );
		$this->assertNotFalse( has_action( 'deactivated_plugin', array( $deactivation, 'after_deactivate' ) ) );
		$this->assertNotFalse( has_action( 'activated_plugin', array( $deactivation, 'after_activate' ) ) );
		$this->assertNotFalse( has_action( 'switch_theme', array( $deactivation, 'on_switch_theme' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( $deactivation, 'notices' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_block_doctor_dismiss_affected', array( $deactivation, 'dismiss' ) ) );
		$this->assertNotFalse( has_action( 'load-plugins.php', array( $deactivation, 'load_plugins_screen' ) ) );
	}

	public function test_the_plugins_screen_script_only_loads_for_people_who_may_use_block_doctor_once_the_index_is_built(): void {
		$deactivation = $this->plugin()['deactivation'];
		$deactivation->load_plugins_screen();
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $deactivation, 'enqueue_plugins_screen' ) ) );

		\Brain\Monkey\Actions\expectAdded( 'admin_enqueue_scripts' )->never();
		unset( $this->options[ Usage::STATE ] );
		$this->plugin()['deactivation']->load_plugins_screen();
	}

	public function test_the_plugins_screen_script_gets_the_plugins_that_provide_blocks(): void {
		$queued = array();
		Functions\when( 'plugins_url' )->justReturn( 'https://example.com/wp-content/plugins/block-doctor/assets/' );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_set_script_translations' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$queued ) {
				$queued[] = $handle;
			}
		);
		$inline = '';
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code ) use ( &$inline ) {
				$inline = $code;
			}
		);

		$this->plugin()['deactivation']->enqueue_plugins_screen();

		$this->assertSame( array( 'block-doctor-plugins' ), $queued );
		$this->assertStringContainsString( '{"plugins":{"acme-blocks\/acme-blocks.php":1}}', $inline );
	}

	public function test_deactivating_on_the_command_line_prints_a_warning_and_blocks_nothing(): void {
		\WP_CLI::$warnings = array();
		defined( 'WP_CLI' ) || define( 'WP_CLI', true );

		$this->plugin()['deactivation']->before_deactivate( 'acme-blocks/acme-blocks.php' );
		$this->assertCount( 1, \WP_CLI::$warnings );
		$this->assertStringContainsString( 'Acme Blocks provides blocks that posts use. Its 1 block type is used in 212 posts.', \WP_CLI::$warnings[0] );
		$this->assertStringContainsString( 'wp block-doctor usage --provider=' . self::KEY, \WP_CLI::$warnings[0] );

		\WP_CLI::$warnings = array();
		Filters\expectApplied( 'block_doctor_cli_deactivation_warning' )->andReturn( false );
		$this->plugin()['deactivation']->before_deactivate( 'acme-blocks/acme-blocks.php' );
		$this->assertSame( array(), \WP_CLI::$warnings );

		\WP_CLI::$warnings = array();
		$this->plugin()['deactivation']->before_deactivate( 'other/other.php' );
		$this->assertSame( array(), \WP_CLI::$warnings );
	}
}
