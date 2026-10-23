<?php
/**
 * Tests for the REST routes.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor\Tests\Unit;

use AryanX\BlockDoctor\Rest\Controller;
use Brain\Monkey\Functions;

final class RestTest extends PluginTestCase {

	/**
	 * Routes registered, by path.
	 *
	 * @var array<string, array>
	 */
	private $routes = array();

	protected function setUp(): void {
		parent::setUp();
		$this->routes = array();

		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) {
				$this->routes[ $namespace . $route ] = $args;
				return true;
			}
		);
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $data ) {
				return $data instanceof \WP_Error ? $data : new \WP_REST_Response( $data );
			}
		);
		Functions\when( 'parse_blocks' )->justReturn( array() );
	}

	/**
	 * The controller, wired to the in-memory site.
	 *
	 * @return Controller
	 */
	private function controller(): Controller {
		$plugin     = $this->plugin();
		$controller = new Controller( $plugin['config'], $plugin['posts'], $plugin['issues'], $plugin['scanner'], $plugin['batches'], $plugin['fixer'], $plugin['report'] );
		$controller->routes();

		return $controller;
	}

	public function test_every_route_needs_the_block_doctor_capability(): void {
		$this->controller();

		$this->assertSame(
			array( 'block-doctor/v1/posts', 'block-doctor/v1/scan', 'block-doctor/v1/results', 'block-doctor/v1/server-scan', 'block-doctor/v1/issues', 'block-doctor/v1/batches', 'block-doctor/v1/batches/(?P<id>\d+)/undo', 'block-doctor/v1/fix' ),
			array_keys( $this->routes )
		);
		foreach ( $this->routes as $route ) {
			foreach ( isset( $route['methods'] ) ? array( $route ) : $route as $endpoint ) {
				$this->assertTrue( call_user_func( $endpoint['permission_callback'] ) );
			}
		}

		$this->can_use = false;
		$this->assertFalse( call_user_func( $this->routes['block-doctor/v1/fix']['permission_callback'] ) );
	}

	public function test_posts_come_in_batches_with_raw_content_leaving_out_what_the_user_cannot_edit(): void {
		$controller      = $this->controller();
		$a               = $this->add_post( '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->', array( 'ID' => 3, 'post_title' => 'A' ) );
		$b               = $this->add_post( 'b', array( 'ID' => 4 ) );
		$this->forbidden = array( 4 );
		\WP_Query::$queue = array( array( 'posts' => array( $a, $b ) ) );

		$data = $controller->get_posts( new \WP_REST_Request( array( 'after' => 2, 'per_page' => 2, 'include' => array() ) ) )->get_data();

		$this->assertSame( array( 3 ), array_column( $data['posts'], 'id' ) );
		$this->assertSame( md5( $a->post_content ), $data['posts'][0]['hash'] );
		$this->assertSame( $a->post_content, $data['posts'][0]['content'] );
		$this->assertSame( array( 2, 4, false ), array( $data['count'], $data['next'], $data['done'] ) );
		$this->assertSame( 2, \WP_Query::$made[0]['block_doctor_after'] );

		$data = $controller->get_posts( new \WP_REST_Request( array( 'after' => 0, 'per_page' => 20, 'include' => array( 3, '4', 404 ) ) ) )->get_data();
		$this->assertSame( array( 3 ), array_column( $data['posts'], 'id' ) );
		$this->assertFalse( $data['done'], 'asking for posts by ID never ends a scan' );
	}

	public function test_the_browser_scan_starts_reports_and_pauses(): void {
		$controller = $this->controller();
		$this->add_post( 'a', array( 'ID' => 3 ) );

		$data = $controller->control_scan( new \WP_REST_Request( array( 'action' => 'start', 'blocks' => array( 'acme/js' ) ) ) )->get_data();
		$this->assertSame( 'running', $data['browser']['status'] );
		$this->assertSame( 1, $data['browser']['total'] );

		$data = $controller->post_results(
			new \WP_REST_Request(
				array(
					'cursor'   => 3,
					'count'    => 1,
					'finished' => false,
					'results'  => array(
						array(
							'id'     => 3,
							'hash'   => md5( 'a' ),
							'issues' => array( array( 'kind' => 'invalid', 'path' => array( 0 ), 'name' => 'core/heading', 'message' => 'x' ) ),
						),
					),
				)
			)
		)->get_data();
		$this->assertSame( 1, $data['counts']['invalid']['issues'] );

		$this->assertSame( 'paused', $controller->control_scan( new \WP_REST_Request( array( 'action' => 'pause' ) ) )->get_data()['browser']['status'] );
		$this->assertSame( 'running', $controller->control_scan( new \WP_REST_Request( array( 'action' => 'resume' ) ) )->get_data()['browser']['status'] );

		$data = $controller->clear_results()->get_data();
		$this->assertSame( 0, $data['posts'] );
		$this->assertSame( 'idle', $data['browser']['status'] );
	}

	public function test_the_server_scan_route_starts_once_and_then_continues(): void {
		$controller = $this->controller();
		\WP_Query::$queue = array( array( 'posts' => array() ), array( 'posts' => array() ) );

		$this->assertSame( 'done', $controller->server_scan( new \WP_REST_Request( array( 'restart' => false, 'post_type' => '' ) ) )->get_data()['server']['status'] );
		$this->assertCount( 1, \WP_Query::$made );
		$controller->server_scan( new \WP_REST_Request( array( 'restart' => true, 'post_type' => 'page' ) ) );
		$this->assertSame( array( 'page' ), \WP_Query::$made[1]['post_type'] );
	}

	public function test_issues_by_page_or_as_ids(): void {
		$plugin     = $this->plugin();
		$controller = $this->controller();
		foreach ( array( 3, 4, 5 ) as $id ) {
			$plugin['issues']->save( $this->add_post( 'x', array( 'ID' => $id ) ), array( 'server' => array( array( 'kind' => 'classic', 'path' => array( 0 ) ) ) ), md5( 'x' ) );
		}

		$data = $controller->get_issues( new \WP_REST_Request( array( 'kind' => 'classic', 'post_type' => '', 'page' => 2, 'per_page' => 2, 'ids_only' => false ) ) )->get_data();
		$this->assertSame( array( 5 ), array_column( $data['items'], 'id' ) );
		$this->assertSame( array( 3, 2 ), array( $data['total'], $data['pages'] ) );
		$this->assertSame( 3, $data['summary']['posts'] );

		$data = $controller->get_issues( new \WP_REST_Request( array( 'kind' => 'classic', 'post_type' => '', 'page' => 1, 'per_page' => 1, 'ids_only' => true ) ) )->get_data();
		$this->assertSame( array( 'ids' => array( 3, 4, 5 ) ), $data );
	}

	public function test_batches_fixes_and_undo(): void {
		$controller = $this->controller();
		Functions\when( 'wp_check_post_lock' )->justReturn( false );

		$batch = $controller->create_batch( new \WP_REST_Request( array( 'kinds' => array( 'invalid', 'bogus' ) ) ) )->get_data();
		$this->assertSame( array( 'invalid' ), $batch['kinds'] );
		$this->assertSame( array(), $controller->get_batches()->get_data(), 'listed once it fixed a post' );

		$error = $controller->fix( new \WP_REST_Request( array( 'post_id' => 404, 'batch' => $batch['id'], 'content' => 'x', 'hash' => 'y', 'issues' => array() ) ) );
		$this->assertInstanceOf( \WP_Error::class, $error );

		$undo = $controller->undo_batch( new \WP_REST_Request( array( 'id' => $batch['id'] ) ) )->get_data();
		$this->assertSame( 0, $undo['remaining'] );
		$this->assertInstanceOf( \WP_Error::class, $controller->undo_batch( new \WP_REST_Request( array( 'id' => 77 ) ) ) );
	}

	public function test_routes_are_added_on_rest_api_init(): void {
		$plugin     = $this->plugin();
		$controller = new Controller( $plugin['config'], $plugin['posts'], $plugin['issues'], $plugin['scanner'], $plugin['batches'], $plugin['fixer'], $plugin['report'] );
		$controller->register();

		$this->assertNotFalse( has_action( 'rest_api_init', array( $controller, 'routes' ) ) );
	}
}
