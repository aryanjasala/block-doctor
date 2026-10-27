<?php
/**
 * REST routes for Tools > Block Doctor.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor\Rest;

use AryanX\Axle\BlockDoctor\Batches;
use AryanX\Axle\BlockDoctor\Config;
use AryanX\Axle\BlockDoctor\Fixer;
use AryanX\Axle\BlockDoctor\Issues;
use AryanX\Axle\BlockDoctor\Posts;
use AryanX\Axle\BlockDoctor\Report;
use AryanX\Axle\BlockDoctor\Scanner;
use AryanX\Axle\BlockDoctor\Service;

defined( 'ABSPATH' ) || exit;

/**
 * The routes the screen's JavaScript calls, under `block-doctor/v1`.
 *
 * Every route needs the Block Doctor capability. Posts the user cannot edit
 * are left out of scans and refused by fixes.
 */
final class Controller implements Service {

	public const NAMESPACE = 'block-doctor/v1';

	/**
	 * Defaults and limits.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Finds the posts.
	 *
	 * @var Posts
	 */
	private Posts $posts;

	/**
	 * Stored results.
	 *
	 * @var Issues
	 */
	private Issues $issues;

	/**
	 * Scan state.
	 *
	 * @var Scanner
	 */
	private Scanner $scanner;

	/**
	 * Fix batches.
	 *
	 * @var Batches
	 */
	private Batches $batches;

	/**
	 * Saves fixes.
	 *
	 * @var Fixer
	 */
	private Fixer $fixer;

	/**
	 * The report.
	 *
	 * @var Report
	 */
	private Report $report;

	/**
	 * Sets up the routes.
	 *
	 * @param Config  $config  Defaults and limits.
	 * @param Posts   $posts   Finds the posts.
	 * @param Issues  $issues  Stored results.
	 * @param Scanner $scanner Scan state.
	 * @param Batches $batches Fix batches.
	 * @param Fixer   $fixer   Saves fixes.
	 * @param Report  $report  The report.
	 */
	public function __construct( Config $config, Posts $posts, Issues $issues, Scanner $scanner, Batches $batches, Fixer $fixer, Report $report ) {
		$this->config  = $config;
		$this->posts   = $posts;
		$this->issues  = $issues;
		$this->scanner = $scanner;
		$this->batches = $batches;
		$this->fixer   = $fixer;
		$this->report  = $report;
	}

	/**
	 * Adds the routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function routes(): void {
		$allowed = array( $this->config, 'can_use' );
		$int     = array(
			'type'              => 'integer',
			'minimum'           => 0,
			'default'           => 0,
			'sanitize_callback' => 'absint',
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_posts' ),
				'permission_callback' => $allowed,
				'args'                => array(
					'after'    => $int,
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => $this->config->batch_size(),
					),
					'include'  => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => array(),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_summary' ),
					'permission_callback' => $allowed,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'control_scan' ),
					'permission_callback' => $allowed,
					'args'                => array(
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'start', 'pause', 'resume' ),
							'required' => true,
						),
						'blocks' => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/results',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_results' ),
					'permission_callback' => $allowed,
					'args'                => array(
						'cursor'   => $int,
						'count'    => $int,
						'finished' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'results'  => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'object' ),
							'default' => array(),
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_results' ),
					'permission_callback' => $allowed,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/server-scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'server_scan' ),
				'permission_callback' => $allowed,
				'args'                => array(
					'restart'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'post_type' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/issues',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_issues' ),
				'permission_callback' => $allowed,
				'args'                => array(
					'kind'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'post_type' => array(
						'type'    => 'string',
						'default' => '',
					),
					'page'      => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
					'ids_only'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/batches',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_batches' ),
					'permission_callback' => $allowed,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_batch' ),
					'permission_callback' => $allowed,
					'args'                => array(
						'kinds' => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/batches/(?P<id>\d+)/undo',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'undo_batch' ),
				'permission_callback' => $allowed,
				'args'                => array( 'id' => $int ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/fix',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'fix' ),
				'permission_callback' => $allowed,
				'args'                => array(
					'post_id' => array_merge( $int, array( 'required' => true ) ),
					'batch'   => array_merge( $int, array( 'required' => true ) ),
					'content' => array(
						'type'     => 'string',
						'required' => true,
					),
					'hash'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'issues'  => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'object' ),
						'default' => array(),
					),
				),
			)
		);
	}

	/**
	 * GET /posts: the next batch of posts after a cursor, or the posts asked for, with their raw content.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_posts( $request ) {
		$include = array_filter( array_map( 'absint', (array) $request->get_param( 'include' ) ) );
		$limit   = (int) $request->get_param( 'per_page' );
		$found   = $include ? $this->posts->get( $include ) : $this->posts->after( (int) $request->get_param( 'after' ), $limit );
		$posts   = array();
		$next    = (int) $request->get_param( 'after' );

		foreach ( $found as $post ) {
			$next = max( $next, (int) $post->ID );
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$posts[] = Posts::payload( $post );
			}
		}

		return rest_ensure_response(
			array(
				'posts' => $posts,
				'count' => count( $found ),
				'next'  => $next,
				'done'  => ! $include && count( $found ) < $limit,
			)
		);
	}

	/**
	 * GET /scan: counts and the state of both scans.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_summary() {
		return rest_ensure_response( $this->report->summary() );
	}

	/**
	 * POST /scan: start, pause or resume the browser scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function control_scan( $request ) {
		$action = (string) $request->get_param( 'action' );
		if ( 'start' === $action ) {
			$this->scanner->start_browser( (array) $request->get_param( 'blocks' ) );
		} else {
			$this->scanner->pause_browser( 'pause' === $action );
		}

		return rest_ensure_response( $this->report->summary() );
	}

	/**
	 * POST /results: one batch of browser results.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_results( $request ) {
		$this->scanner->record(
			(int) $request->get_param( 'cursor' ),
			(int) $request->get_param( 'count' ),
			(array) $request->get_param( 'results' ),
			(bool) $request->get_param( 'finished' )
		);

		return rest_ensure_response( $this->report->summary() );
	}

	/**
	 * DELETE /results: forget every result and both scans' progress. Fix batches stay, so they can still be undone.
	 *
	 * @return \WP_REST_Response
	 */
	public function clear_results() {
		$this->issues->clear();
		$this->scanner->reset();

		return rest_ensure_response( $this->report->summary() );
	}

	/**
	 * POST /server-scan: runs the next batch of the server checks, starting a scan when none is running.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function server_scan( $request ) {
		if ( $request->get_param( 'restart' ) || 'running' !== $this->scanner->state( 'server' )['status'] ) {
			$this->scanner->start_server( sanitize_key( (string) $request->get_param( 'post_type' ) ) );
		} else {
			$this->scanner->server_batch();
		}

		return rest_ensure_response( $this->report->summary() );
	}

	/**
	 * GET /issues: report rows, a page at a time.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_issues( $request ) {
		$args = array(
			'kind'      => sanitize_key( (string) $request->get_param( 'kind' ) ),
			'post_type' => sanitize_key( (string) $request->get_param( 'post_type' ) ),
			'page'      => (int) $request->get_param( 'page' ),
			'per_page'  => (int) $request->get_param( 'per_page' ),
		);

		if ( $request->get_param( 'ids_only' ) ) {
			$found = $this->issues->find( array_merge( $args, array( 'per_page' => 0 ) ) );

			return rest_ensure_response( array( 'ids' => $found['ids'] ) );
		}

		$found = $this->issues->find( $args );

		return rest_ensure_response(
			array(
				'items'   => $this->issues->rows( $found['ids'], $args['kind'] ),
				'total'   => $found['total'],
				'pages'   => (int) ceil( $found['total'] / max( 1, $args['per_page'] ) ),
				'summary' => $this->report->summary(),
			)
		);
	}

	/**
	 * GET /batches: fix batches that can still be undone, newest first.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_batches() {
		return rest_ensure_response( array_values( $this->batches->all() ) );
	}

	/**
	 * POST /batches: starts a fix batch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_batch( $request ) {
		$kinds = array_intersect( array_map( 'strval', (array) $request->get_param( 'kinds' ) ), array_keys( $this->config->issue_kinds() ) );

		return rest_ensure_response( $this->batches->create( $kinds ) );
	}

	/**
	 * POST /fix: saves one fixed post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function fix( $request ) {
		$result = $this->fixer->fix(
			(int) $request->get_param( 'post_id' ),
			(int) $request->get_param( 'batch' ),
			(string) $request->get_param( 'content' ),
			(string) $request->get_param( 'hash' ),
			(array) $request->get_param( 'issues' )
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /batches/<id>/undo: undoes the next posts of a batch. Call again while `remaining` is above 0.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function undo_batch( $request ) {
		$result = $this->fixer->undo( (int) $request->get_param( 'id' ), $this->config->batch_size() );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
