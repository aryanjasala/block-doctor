<?php
/**
 * REST routes for Tools > Block Doctor.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor\Rest;

use AryanX\Axle\BlockDoctor\Admin\Page;
use AryanX\Axle\BlockDoctor\Batches;
use AryanX\Axle\BlockDoctor\Config;
use AryanX\Axle\BlockDoctor\Deactivation;
use AryanX\Axle\BlockDoctor\Fixer;
use AryanX\Axle\BlockDoctor\Issues;
use AryanX\Axle\BlockDoctor\Posts;
use AryanX\Axle\BlockDoctor\Providers;
use AryanX\Axle\BlockDoctor\Report;
use AryanX\Axle\BlockDoctor\Scanner;
use AryanX\Axle\BlockDoctor\Service;
use AryanX\Axle\BlockDoctor\Usage;

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
	 * The block usage index.
	 *
	 * @var Usage
	 */
	private Usage $usage;

	/**
	 * Where each block type comes from.
	 *
	 * @var Providers
	 */
	private Providers $providers;

	/**
	 * Warnings before a plugin is turned off.
	 *
	 * @var Deactivation
	 */
	private Deactivation $deactivation;

	/**
	 * Sets up the routes.
	 *
	 * @param Config       $config  Defaults and limits.
	 * @param Posts        $posts   Finds the posts.
	 * @param Issues       $issues  Stored results.
	 * @param Scanner      $scanner Scan state.
	 * @param Batches      $batches Fix batches.
	 * @param Fixer        $fixer   Saves fixes.
	 * @param Report       $report  The report.
	 * @param Usage        $usage        The block usage index.
	 * @param Providers    $providers    Where each block type comes from.
	 * @param Deactivation $deactivation Warnings before a plugin is turned off.
	 */
	public function __construct( Config $config, Posts $posts, Issues $issues, Scanner $scanner, Batches $batches, Fixer $fixer, Report $report, Usage $usage, Providers $providers, Deactivation $deactivation ) {
		$this->config       = $config;
		$this->posts        = $posts;
		$this->issues       = $issues;
		$this->scanner      = $scanner;
		$this->batches      = $batches;
		$this->fixer        = $fixer;
		$this->report       = $report;
		$this->usage        = $usage;
		$this->providers    = $providers;
		$this->deactivation = $deactivation;
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
						'action'  => array(
							'type'     => 'string',
							'enum'     => array( 'start', 'pause', 'resume' ),
							'required' => true,
						),
						'blocks'  => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
						'sources' => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'string' ),
							'default'              => array(),
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

		$this->usage_routes( $allowed );

		register_rest_route(
			self::NAMESPACE,
			'/steps',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_steps' ),
				'permission_callback' => $allowed,
				'args'                => array(
					'dismissed' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	/**
	 * Registers the block usage routes.
	 *
	 * @param callable $allowed Permission callback.
	 */
	private function usage_routes( callable $allowed ): void {
		$text = array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		);
		$page = array(
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => $this->config->usage_per_page(),
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/usage',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_usage' ),
				'permission_callback' => $allowed,
				'args'                => array_merge(
					$page,
					array(
						'search'   => $text,
						'filter'   => array_merge( $text, array( 'enum' => array( '', 'core', 'plugin', 'theme', 'unregistered', 'unused' ) ) ),
						'provider' => $text,
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/usage/posts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_usage_posts' ),
				'permission_callback' => $allowed,
				'args'                => array_merge(
					$page,
					array(
						'block'    => $text,
						'provider' => $text,
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/usage/index',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'index_usage' ),
				'permission_callback' => $allowed,
				'args'                => array(
					'restart' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/usage/warning',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_warning' ),
				'permission_callback' => $allowed,
				'args'                => array( 'provider' => array_merge( $text, array( 'required' => true ) ) ),
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
			// Where the browser saw each JavaScript-only block registered, so the usage tab can name its plugin.
			$this->providers->record_sources( (array) $request->get_param( 'sources' ) );
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
			// The capability for the screen is not enough: only posts this user may edit are listed.
			'editable'  => true,
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

	/**
	 * GET /usage: block types with their provider, posts and uses, a page at a time.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_usage( $request ) {
		$types    = $this->usage->visible_types();
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$rows     = $this->usage->blocks(
			array(
				'types'    => $types,
				'search'   => (string) $request->get_param( 'search' ),
				'filter'   => (string) $request->get_param( 'filter' ),
				'provider' => (string) $request->get_param( 'provider' ),
			)
		);
		$all      = $this->usage->blocks( array( 'types' => $types ) );

		$items = array();
		foreach ( array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ) as $row ) {
			$row['provider_key'] = Providers::key( $row['provider'] );
			$items[]             = $row;
		}

		$providers = array();
		foreach ( $this->providers->groups() as $key => $group ) {
			$providers[] = array(
				'key'  => $key,
				'name' => $group['provider']['name'],
				'type' => $group['provider']['type'],
			);
		}

		return rest_ensure_response(
			array(
				'ready'     => $this->usage->ready(),
				'state'     => $this->usage->state(),
				'items'     => $items,
				'total'     => count( $rows ),
				'pages'     => (int) ceil( count( $rows ) / max( 1, $per_page ) ),
				'providers' => $providers,
				'stats'     => array(
					'used'         => count( array_filter( wp_list_pluck( $all, 'posts' ) ) ),
					'unregistered' => count( array_filter( $all, static fn( array $row ): bool => ! $row['registered'] && $row['posts'] > 0 ) ),
					'unused'       => count( array_filter( $all, static fn( array $row ): bool => $row['registered'] && 0 === $row['posts'] ) ),
				),
			)
		);
	}

	/**
	 * GET /usage/posts: the posts that use a block type, or every block type of one plugin or theme.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_usage_posts( $request ) {
		$block    = Issues::block_name( (string) $request->get_param( 'block' ) );
		$provider = (string) $request->get_param( 'provider' );
		$names    = '' !== $block ? array( $block ) : $this->providers->blocks_of( $provider );
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$found    = $this->usage->posts_using( $names, $this->usage->visible_types(), $page, $per_page );

		$items  = array();
		$hidden = 0;
		foreach ( $found['items'] as $item ) {
			$post = get_post( $item['id'] );
			// The index counts by post type; a post the user may not edit is left out, and its title never leaves the server.
			if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
				++$hidden;
				continue;
			}
			$type      = get_post_type_object( $post->post_type );
			$status    = get_post_status_object( $post->post_status );
			$positions = '' !== $block ? $this->usage->positions( $post, $block, $this->config->usage_positions() ) : array();
			$items[]   = array(
				'id'           => (int) $post->ID,
				'title'        => '' !== $post->post_title ? (string) $post->post_title : (string) $post->post_name,
				'type'         => (string) $post->post_type,
				'type_label'   => $type ? (string) $type->labels->singular_name : (string) $post->post_type,
				'status'       => (string) $post->post_status,
				'status_label' => $status && is_string( $status->label ) ? $status->label : (string) $post->post_status,
				'edit_link'    => (string) get_edit_post_link( $post->ID, 'raw' ),
				'modified'     => (int) mysql2date( 'U', $post->post_modified_gmt ),
				'uses'         => $item['uses'],
				'positions'    => $positions,
			);
		}

		return rest_ensure_response(
			array(
				'items'  => $items,
				'total'  => $found['total'],
				'pages'  => (int) ceil( $found['total'] / max( 1, $per_page ) ),
				'hidden' => $hidden,
				'blocks' => $names,
			)
		);
	}

	/**
	 * POST /usage/index: indexes the next batch of posts, starting a build when none is running.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index_usage( $request ) {
		if ( $request->get_param( 'restart' ) ) {
			$this->usage->start();
		}
		$state = $this->usage->step();

		return rest_ensure_response(
			array(
				'ready' => $this->usage->ready(),
				'state' => $state,
			)
		);
	}

	/**
	 * GET /usage/warning: what switching off one plugin or theme does to the blocks in use.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_warning( $request ) {
		$key = (string) $request->get_param( 'provider' );
		if ( ! preg_match( '#^(plugin|mu-plugin|theme):#', $key ) ) {
			return rest_ensure_response( array( 'warning' => (object) array() ) );
		}
		$warning = $this->deactivation->warning( $key, $this->usage->visible_types() );

		return rest_ensure_response( array( 'warning' => $warning ? $warning : (object) array() ) );
	}

	/**
	 * POST /steps: hides or shows the Get started card for the current user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function dismiss_steps( $request ) {
		$dismissed = (bool) $request->get_param( 'dismissed' );
		if ( $dismissed ) {
			update_user_meta( get_current_user_id(), Page::STEPS_META, 1 );
		} else {
			delete_user_meta( get_current_user_id(), Page::STEPS_META );
		}

		return rest_ensure_response( array( 'dismissed' => $dismissed ) );
	}
}
