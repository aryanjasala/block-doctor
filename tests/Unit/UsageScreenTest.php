<?php
/**
 * Tests for what reaches people: the usage routes, the abilities, the CSV, the tab, Site Health and the report's capability checks.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor\Tests\Unit;

use AryanX\Axle\BlockDoctor\Abilities;
use AryanX\Axle\BlockDoctor\Admin\Page;
use AryanX\Axle\BlockDoctor\Health\SiteHealth;
use AryanX\Axle\BlockDoctor\Providers;
use AryanX\Axle\BlockDoctor\Rest\Controller;
use AryanX\Axle\BlockDoctor\Usage;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class UsageScreenTest extends PluginTestCase {

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

		Functions\when( '_prime_post_caches' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'rest_ensure_response' )->alias( static fn( $data ) => new \WP_REST_Response( $data ) );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ) );
		Functions\when( 'get_post_type_object' )->alias(
			static fn( $type ) => (object) array(
				'labels' => (object) array( 'singular_name' => ucfirst( (string) $type ) ),
				'cap'    => (object) array( 'edit_others_posts' => 'edit_others_posts' ),
			)
		);
		Functions\when( 'parse_blocks' )->alias(
			static fn( $content ) => array(
				self::block( 'core/image' ),
				self::block( 'acme/slider', array(), array( self::block( 'core/image' ) ) ),
				self::block( 'acme/slider' ),
			)
		);

		\WP_Block_Type_Registry::$names          = array( 'core/image', 'core/quote', 'acme/slider' );
		\WP_Block_Type_Registry::$props          = array( 'acme/slider' => array( 'title' => 'Slider' ) );
		$this->options[ Usage::STATE ]           = array( 'status' => 'done', 'finished' => 1700000000 );
		$this->options[ Providers::REMEMBERED ]  = array( 'acme/slider' => array( 'type' => 'plugin', 'slug' => 'acme', 'name' => 'Acme', 'basename' => 'acme/acme.php' ) );
		$this->db->rows['GROUP BY m.meta_key']   = array(
			array( 'meta_key' => Usage::PREFIX . 'core/image', 'posts' => '9', 'uses' => '20', 'last' => '2026-11-01 10:00:00' ),
			array( 'meta_key' => Usage::PREFIX . 'acme/slider', 'posts' => '3', 'uses' => '4', 'last' => '2026-11-02 10:00:00' ),
		);
		$this->db->vars['COUNT(DISTINCT']        = '3';
		$this->db->rows['GROUP BY m.post_id']    = array( array( 'id' => '5', 'uses' => '2' ), array( 'id' => '6', 'uses' => '1' ), array( 'id' => '7', 'uses' => '1' ) );
		$this->add_post( 'x', array( 'ID' => 5, 'post_title' => 'Home', 'post_modified_gmt' => '2026-11-03 10:00:00' ) );
		$this->add_post( 'x', array( 'ID' => 6, 'post_title' => 'About', 'post_modified_gmt' => '2026-11-03 10:00:00' ) );
		$this->add_post( 'x', array( 'ID' => 7, 'post_title' => 'Private plans', 'post_modified_gmt' => '2026-11-03 10:00:00' ) );
	}

	private function controller(): Controller {
		$p = $this->plugin();

		return new Controller( $p['config'], $p['posts'], $p['issues'], $p['scanner'], $p['batches'], $p['fixer'], $p['report'], $p['usage'], $p['providers'], $p['deactivation'] );
	}

	private function abilities(): Abilities {
		$p = $this->plugin();

		return new Abilities( $p['config'], $p['posts'], $p['issues'], $p['scanner'], $p['fixer'], $p['report'], $p['usage'], $p['providers'] );
	}

	public function test_the_usage_route_lists_block_types_with_providers_and_stats(): void {
		$data = $this->controller()->get_usage( new \WP_REST_Request( array( 'search' => '', 'filter' => '', 'provider' => '', 'page' => 1, 'per_page' => 2 ) ) )->get_data();

		$this->assertTrue( $data['ready'] );
		$this->assertSame( 3, $data['total'] );
		$this->assertSame( 2, $data['pages'] );
		$this->assertSame( array( 'core/image', 'acme/slider' ), array_column( $data['items'], 'name' ) );
		$this->assertSame( 'plugin:acme/acme.php', $data['items'][1]['provider_key'] );
		$this->assertSame( array( 'used' => 2, 'unregistered' => 0, 'unused' => 1 ), $data['stats'] );
		$this->assertSame( array( array( 'key' => 'plugin:acme/acme.php', 'name' => 'Acme', 'type' => 'plugin' ) ), $data['providers'] );

		$filtered = $this->controller()->get_usage( new \WP_REST_Request( array( 'search' => '', 'filter' => '', 'provider' => 'plugin:acme/acme.php', 'page' => 1, 'per_page' => 20 ) ) )->get_data();
		$this->assertSame( array( 'acme/slider' ), array_column( $filtered['items'], 'name' ) );
	}

	public function test_the_posts_route_leaves_out_posts_the_person_cannot_edit_and_says_where_the_block_is(): void {
		$this->forbidden = array( 7 );
		$data            = $this->controller()->get_usage_posts( new \WP_REST_Request( array( 'block' => 'acme/slider', 'provider' => '', 'page' => 1, 'per_page' => 20 ) ) )->get_data();

		$this->assertSame( array( 5, 6 ), array_column( $data['items'], 'id' ) );
		$this->assertSame( 1, $data['hidden'] );
		$this->assertSame( 3, $data['total'] );
		$this->assertSame( array( array( 1 ), array( 2 ) ), $data['items'][0]['positions'] );
		$this->assertStringNotContainsString( 'Private plans', wp_json_encode( $data ) );
		$this->assertSame( array( 'acme/slider' ), $data['blocks'] );

		$by_provider = $this->controller()->get_usage_posts( new \WP_REST_Request( array( 'block' => '', 'provider' => 'plugin:acme/acme.php', 'page' => 1, 'per_page' => 20 ) ) )->get_data();
		$this->assertSame( array( 'acme/slider' ), $by_provider['blocks'] );
		$this->assertSame( array(), $by_provider['items'][0]['positions'], 'positions are for one block type' );
	}

	public function test_the_index_route_runs_a_batch_and_the_warning_route_only_takes_provider_keys(): void {
		$controller = $this->controller();
		\WP_Query::$queue = array( array( 'posts' => array() ) );
		$data = $controller->index_usage( new \WP_REST_Request( array( 'restart' => true ) ) )->get_data();
		$this->assertSame( 'done', $data['state']['status'] );
		$this->assertTrue( $data['ready'] );

		$this->assertEquals( (object) array(), $controller->get_warning( new \WP_REST_Request( array( 'provider' => 'something' ) ) )->get_data()['warning'] );
		$warning = $controller->get_warning( new \WP_REST_Request( array( 'provider' => 'plugin:acme/acme.php' ) ) )->get_data()['warning'];
		$this->assertSame( 3, $warning['posts'] );
	}

	public function test_the_get_started_card_is_dismissed_for_the_current_user(): void {
		Functions\expect( 'update_user_meta' )->once()->with( 1, Page::STEPS_META, 1 );
		$this->assertTrue( $this->controller()->dismiss_steps( new \WP_REST_Request( array( 'dismissed' => true ) ) )->get_data()['dismissed'] );

		Functions\expect( 'delete_user_meta' )->once()->with( 1, Page::STEPS_META );
		$this->assertFalse( $this->controller()->dismiss_steps( new \WP_REST_Request( array( 'dismissed' => false ) ) )->get_data()['dismissed'] );
	}

	public function test_the_report_only_lists_posts_the_person_may_edit(): void {
		$plugin = $this->plugin();
		foreach ( array( 5, 6, 7 ) as $id ) {
			$plugin['issues']->save( $this->posts[ $id ], array( 'server' => array( array( 'kind' => 'classic', 'path' => array( 0 ) ) ) ), md5( 'x' ) );
		}
		$this->forbidden = array( 6 );
		$controller      = $this->controller();

		$data = $controller->get_issues( new \WP_REST_Request( array( 'kind' => 'classic', 'post_type' => '', 'page' => 1, 'per_page' => 20, 'ids_only' => false ) ) )->get_data();
		$this->assertSame( array( 5, 7 ), array_column( $data['items'], 'id' ) );
		$this->assertSame( 2, $data['total'] );

		$ids = $controller->get_issues( new \WP_REST_Request( array( 'kind' => 'classic', 'post_type' => '', 'page' => 1, 'per_page' => 1, 'ids_only' => true ) ) )->get_data();
		$this->assertSame( array( 5, 7 ), $ids['ids'] );

		$ability = $this->abilities()->list_issues( array( 'kind' => 'classic' ) );
		$this->assertSame( array( 5, 7 ), array_column( $ability['items'], 'id' ) );
		$this->assertSame( 2, $ability['total'] );
	}

	public function test_the_usage_abilities_have_the_right_annotations_and_run(): void {
		$abilities = array();
		Functions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) use ( &$abilities ) {
				$abilities[ $name ] = $args;
				return true;
			}
		);
		$object = $this->abilities();
		$object->register_abilities();

		$this->assertTrue( $abilities['block-doctor/get-block-usage']['meta']['annotations']['readonly'] );
		$this->assertSame( array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), $abilities['block-doctor/run-usage-index']['meta']['annotations'] );

		$list = $object->get_block_usage( array( 'filter' => 'unused' ) );
		$this->assertTrue( $list['ready'] );
		$this->assertSame( array( 'core/quote' ), array_column( $list['blocks'], 'name' ) );

		$this->forbidden = array( 7 );
		$posts           = $object->get_block_usage( array( 'block' => 'core/image' ) );
		$this->assertSame( array( 5, 6 ), array_column( $posts['posts'], 'id' ) );
		$this->assertSame( array( array( 0 ), array( 1, 0 ) ), $posts['posts'][0]['positions'] );

		\WP_Query::$queue = array( array( 'posts' => array() ) );
		$run              = $object->run_usage_index();
		$this->assertSame( array( true, 'done' ), array( $run['ready'], $run['status'] ) );
	}

	public function test_the_plugin_row_links_to_the_screen_and_the_tab_comes_from_the_address(): void {
		$p    = $this->plugin();
		$page = new Page( $p['config'], $p['report'], $p['usage'] );

		$links = $page->action_links( array( '<a href="#">Deactivate</a>' ) );
		$this->assertStringContainsString( 'tools.php?page=block-doctor', $links[0] );
		$this->assertCount( 2, $links );

		$this->can_use = false;
		$this->assertCount( 1, $page->action_links( array( '<a href="#">Deactivate</a>' ) ) );
		$this->assertSame( 'scan', Page::tab() );

		$_GET['tab'] = 'usage';
		$this->assertSame( 'usage', Page::tab() );
		$_GET['tab'] = 'bogus';
		$this->assertSame( 'scan', Page::tab() );
		unset( $_GET['tab'] );

		$titles = array_column( $page->help(), 'id' );
		$this->assertSame( 'overview', $titles[0] );
		$this->assertContains( 'usage', $titles );
		foreach ( $page->help() as $tab ) {
			$this->assertStringNotContainsString( "\u{2014}", $tab['content'] );
		}
	}

	public function test_the_usage_csv_has_one_line_per_block_type_or_per_post(): void {
		$p    = $this->plugin();
		$page = new Page( $p['config'], $p['report'], $p['usage'] );

		$lines = iterator_to_array( $page->usage_lines( array( 'filter' => 'unused' ) ), false );
		$this->assertSame( array( 'block', 'title', 'provider_type', 'provider', 'registered', 'posts', 'uses', 'last_used' ), $lines[0] );
		$this->assertSame( array( 'core/quote', '', 'core', 'WordPress', 'yes', 0, 0, '' ), $lines[1] );
		$this->assertCount( 2, $lines );

		$this->forbidden = array( 7 );
		$lines           = iterator_to_array( $page->usage_lines( array( 'block' => 'core/image' ) ), false );
		$this->assertSame( array( 'post_id', 'title', 'post_type', 'status', 'uses', 'edit_link' ), $lines[0] );
		$this->assertSame( array( 5, 'Home', 'post', 'publish', 2 ), array_slice( $lines[1], 0, 5 ) );
		$this->assertSame( array( 5, 6 ), array_column( array_slice( $lines, 1 ), 0 ), 'a post the person cannot edit is not in the file' );
	}

	public function test_site_health_reports_blocks_left_behind_by_something_that_is_off(): void {
		$p      = $this->plugin();
		$health = new SiteHealth( $p['report'], $p['usage'], $p['deactivation'] );
		Functions\when( 'get_option_stub' )->justReturn( null );

		// Acme's slider is not registered, and the index says 3 posts use it.
		\WP_Block_Type_Registry::$names = array( 'core/image', 'core/quote' );
		$result                         = $health->run_orphans();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( '3 posts use blocks from a plugin or theme that is off', $result['label'] );
		$this->assertStringContainsString( 'Acme', $result['description'] );

		\WP_Block_Type_Registry::$names[] = 'acme/slider';
		$this->assertSame( 'good', ( new SiteHealth( $p['report'], $p['usage'], $this->plugin()['deactivation'] ) )->run_orphans()['status'] );

		unset( $this->options[ Usage::STATE ] );
		$result = ( new SiteHealth( $p['report'], $p['usage'], $this->plugin()['deactivation'] ) )->run_orphans();
		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'still counting', $result['label'] );

		$tests = $health->add_test( array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'block_doctor_orphans', $tests['direct'] );
	}

	public function test_the_new_defaults_are_clamped_filters(): void {
		$config = $this->plugin()['config'];

		$this->assertSame( array( true, 20, 10, 5 ), array( $config->usage_auto_index(), $config->usage_per_page(), $config->usage_positions(), $config->warning_posts() ) );
		$this->assertTrue( $config->cli_warning() );

		Filters\expectApplied( 'block_doctor_usage_per_page' )->andReturn( 500 );
		Filters\expectApplied( 'block_doctor_usage_positions' )->andReturn( 0 );
		Filters\expectApplied( 'block_doctor_deactivation_top_posts' )->andReturn( 99 );
		$this->assertSame( array( 100, 1, 20 ), array( $config->usage_per_page(), $config->usage_positions(), $config->warning_posts() ) );
	}
}
