<?php
/**
 * Tests for the screen, the CSV, the dashboard widget, Site Health and the report.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor\Tests\Unit;

use AryanX\BlockDoctor\Admin\Dashboard;
use AryanX\BlockDoctor\Admin\Page;
use AryanX\BlockDoctor\Health\SiteHealth;
use AryanX\BlockDoctor\Report;
use AryanX\BlockDoctor\Scanner;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class AdminTest extends PluginTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_date' )->justReturn( '23 October 2026' );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( in_array( $name, array( 'date_format', 'time_format' ), true ) ) {
					return 'Y-m-d';
				}
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
	}

	/**
	 * Stores some issues: one broken post and one classic post.
	 */
	private function some_issues(): void {
		$issues = $this->plugin()['issues'];
		$issues->save( $this->add_post( 'a', array( 'ID' => 3, 'post_title' => '=cmd' ) ), array( 'browser' => array( array( 'kind' => 'invalid', 'path' => array( 1, 0 ), 'name' => 'core/heading', 'message' => 'Expected h3.' ) ) ), md5( 'a' ) );
		$issues->save( $this->add_post( 'b', array( 'ID' => 4 ) ), array( 'server' => array( array( 'kind' => 'classic', 'path' => array( 0 ), 'message' => 'Classic.' ) ) ), md5( 'b' ) );
	}

	public function test_csv_lines_quote_every_cell_and_defuse_formulas(): void {
		$this->assertSame( "\"a\",\"say \"\"hi\"\"\",\"'=SUM(A1)\",\"'-1\",\"\"\r\n", Page::csv_line( array( 'a', 'say "hi"', '=SUM(A1)', '-1', '' ) ) );
	}

	public function test_report_lines_have_one_row_per_issue(): void {
		$this->some_issues();
		$report = $this->plugin()['report'];

		$lines = $report->lines();
		$this->assertSame( Report::columns(), array_keys( $lines[0] ) );
		$this->assertSame( array( 3, '=cmd', 'invalid', 'core/heading', '1.0', 'browser', 'no' ), array( $lines[0]['post_id'], $lines[0]['title'], $lines[0]['kind'], $lines[0]['block'], $lines[0]['path'], $lines[0]['source'], $lines[0]['changed_since_scan'] ) );
		$this->assertCount( 1, $report->lines( array( 'kind' => 'classic' ) ) );

		$kinds = $report->kinds();
		$this->assertSame( array( 'key' => 'invalid', 'label' => 'Invalid' ), array_slice( $kinds[0], 0, 2 ) );
		$this->assertSame( 1, $kinds[0]['posts'] );
	}

	public function test_the_screen_is_under_tools_with_the_capability(): void {
		Filters\expectApplied( 'block_doctor_capability' )->andReturn( 'manage_options' );
		Functions\expect( 'add_management_page' )->once()->with( 'Block Doctor', 'Block Doctor', 'manage_options', 'block-doctor', \Mockery::type( 'array' ) )->andReturn( 'tools_page_block-doctor' );
		$plugin = $this->plugin();
		$page   = new Page( $plugin['config'], $plugin['report'] );
		$page->register();
		$page->menu();

		$this->assertNotFalse( has_action( 'load-tools_page_block-doctor', array( $page, 'load' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_block_doctor_export', array( $page, 'export' ) ) );
		$this->assertSame( 'https://example.com/wp-admin/tools.php?page=block-doctor', Page::url() );
	}

	public function test_the_screen_counts_as_a_block_editor_screen_so_core_loads_every_block_editor_script(): void {
		$plugin = $this->plugin();
		$page   = new Page( $plugin['config'], $plugin['report'] );
		$page->load();

		$this->assertNotFalse( has_filter( 'should_load_block_editor_scripts_and_styles', '__return_true' ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $page, 'enqueue' ) ) );
	}

	public function test_the_screen_loads_the_block_editor_scripts_and_fires_the_editor_assets_hook(): void {
		// A plugin block registered from block.json, with its editor script.
		\WP_Block_Type_Registry::$names[]                     = 'acme/shelf';
		\WP_Block_Type_Registry::$editor_scripts['acme/shelf'] = array( 'acme-shelf-editor-script' );
		$plugin  = $this->plugin();
		$page    = new Page( $plugin['config'], $plugin['report'] );
		$queued  = array();
		$inline  = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$queued ) {
				$queued[] = $handle;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code ) use ( &$inline ) {
				$inline[] = $handle . ': ' . substr( $code, 0, 60 );
			}
		);
		Functions\when( 'get_block_editor_server_block_settings' )->justReturn( array() );
		Functions\when( 'get_block_categories' )->justReturn( array() );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'plugins_url' )->justReturn( 'https://example.com/wp-content/plugins/block-doctor/assets/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'abc123' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin-post.php' );
		Functions\stubs( array( 'wp_register_script', 'wp_set_script_translations', 'wp_enqueue_style' ) );
		if ( ! class_exists( 'WP_Block_Editor_Context' ) ) {
			eval( 'class WP_Block_Editor_Context { public function __construct( $settings = array() ) {} }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		Actions\expectDone( 'enqueue_block_editor_assets' )->once();
		$page->enqueue();

		$this->assertSame( array( 'wp-blocks', 'wp-block-library', 'wp-format-library', 'wp-block-editor', 'acme-shelf-editor-script', 'block-doctor-admin' ), $queued );
		$this->assertStringContainsString( 'wp-blocks: wp.blocks.unstable__bootstrapServerSideBlockDefinitions(', $inline[0] );
		$this->assertStringContainsString( 'block-doctor-admin: window.blockDoctorData = ', $inline[2] );
	}

	public function test_the_screen_data_lists_only_block_types_whose_strategy_differs(): void {
		\WP_Block_Type_Registry::$names = array( 'core/paragraph', 'acme/slider' );
		Filters\expectApplied( 'block_doctor_fix_strategy' )->andReturnUsing(
			static function ( $strategy, $name ) {
				return 'acme/slider' === $name ? 'html' : $strategy;
			}
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'abc123' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		$plugin = $this->plugin();
		$data   = ( new Page( $plugin['config'], $plugin['report'] ) )->data();

		$this->assertSame( 'recover', $data['strategy'] );
		$this->assertEquals( (object) array( 'acme/slider' => 'html' ), $data['strategies'] );
		$this->assertSame( array( 'core/paragraph', 'acme/slider' ), $data['serverBlocks'], 'the scan never calls these unregistered' );
		$this->assertSame( 'Y-m-d Y-m-d', $data['dateFormat'], 'the site\'s date and time format, not the browser\'s' );
		$this->assertSame( 20, $data['batchSize'] );
		$this->assertSame( 'Post', $data['postTypes']['post'] );
		$this->assertSame( 'https://example.com/wp-admin/admin-post.php?action=block_doctor_export&_wpnonce=abc123', $data['exportUrl'] );
	}

	public function test_the_screen_and_the_csv_refuse_people_without_the_capability(): void {
		$this->can_use = false;
		Functions\when( 'wp_die' )->alias(
			static function ( $message ) {
				throw new \RuntimeException( $message );
			}
		);
		$plugin = $this->plugin();
		$page   = new Page( $plugin['config'], $plugin['report'] );

		foreach ( array( 'render', 'export' ) as $method ) {
			try {
				$page->$method();
				$this->fail( "$method should refuse" );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Sorry, you are not allowed to use Block Doctor.', $error->getMessage() );
			}
		}
	}

	public function test_the_dashboard_widget_shows_counts_or_asks_for_a_scan(): void {
		Functions\when( 'wp_kses_post' )->returnArg();
		$plugin = $this->plugin();
		$widget = new Dashboard( $plugin['config'], $plugin['report'] );

		Functions\expect( 'wp_add_dashboard_widget' )->once()->with( 'block_doctor', 'Block Doctor', array( $widget, 'render' ) );
		$widget->add();
		$this->can_use = false;
		$widget->add();

		ob_start();
		$widget->render();
		$this->assertStringContainsString( 'No scan yet.', (string) ob_get_clean() );

		$this->some_issues();
		$this->options[ Scanner::BROWSER ] = array( 'status' => 'done', 'finished' => 1700000000 );
		ob_start();
		$widget->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<li class="bdr-widget-invalid"><strong>1</strong> Invalid, in 1 post</li>', $html );
		$this->assertStringContainsString( 'Last scan: 23 October 2026', $html );
	}

	public function test_site_health_says_good_until_posts_have_broken_blocks(): void {
		$health = new SiteHealth( $this->plugin()['report'] );
		$tests  = $health->add_test( array( 'direct' => array() ) );
		$this->assertSame( array( $health, 'run' ), $tests['direct']['block_doctor']['test'] );
		$this->assertSame( 'not an array', $health->add_test( 'not an array' ) );

		$result = $health->run();
		$this->assertSame( array( 'good', 'Block Doctor has not scanned the site yet' ), array( $result['status'], $result['label'] ) );

		$this->options[ Scanner::BROWSER ] = array( 'status' => 'done', 'finished' => 1700000000 );
		$this->assertSame( 'No broken blocks found', $health->run()['label'] );

		$this->some_issues();
		$result = $health->run();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( '1 post has invalid or broken blocks (last scan 23 October 2026)', $result['label'] );
	}

	public function test_the_hooks_are_registered(): void {
		$plugin = $this->plugin();
		$widget = new Dashboard( $plugin['config'], $plugin['report'] );
		$health = new SiteHealth( $plugin['report'] );
		$widget->register();
		$health->register();

		$this->assertNotFalse( has_action( 'wp_dashboard_setup', array( $widget, 'add' ) ) );
		$this->assertNotFalse( has_filter( 'site_status_tests', array( $health, 'add_test' ) ) );
	}
}
