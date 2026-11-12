<?php
/**
 * Abilities: scan results, the server-side scan and Undo batch for AI agents and other Abilities API clients.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Block Doctor's abilities on WordPress 6.9 and later.
 *
 * Whether a block is invalid is only known in the block editor's JavaScript,
 * so the abilities cover the server-side checks and the stored results. The
 * browser scan and the repair of invalid blocks need Tools > Block Doctor.
 */
final class Abilities implements Service {

	public const CATEGORY = 'block-doctor';

	/**
	 * Defaults and limits.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Stored results.
	 *
	 * @var Issues
	 */
	private Issues $issues;

	/**
	 * Scans.
	 *
	 * @var Scanner
	 */
	private Scanner $scanner;

	/**
	 * Undo.
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
	 * Finds the posts.
	 *
	 * @var Posts
	 */
	private Posts $posts;

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
	 * Sets up the abilities.
	 *
	 * @param Config    $config    Defaults and limits.
	 * @param Posts     $posts     Finds the posts.
	 * @param Issues    $issues    Stored results.
	 * @param Scanner   $scanner   Scans.
	 * @param Fixer     $fixer     Undo.
	 * @param Report    $report    The report.
	 * @param Usage     $usage     The block usage index.
	 * @param Providers $providers Where each block type comes from.
	 */
	public function __construct( Config $config, Posts $posts, Issues $issues, Scanner $scanner, Fixer $fixer, Report $report, Usage $usage, Providers $providers ) {
		$this->config    = $config;
		$this->posts     = $posts;
		$this->issues    = $issues;
		$this->scanner   = $scanner;
		$this->fixer     = $fixer;
		$this->report    = $report;
		$this->usage     = $usage;
		$this->providers = $providers;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the Block Doctor category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Block Doctor', 'block-doctor' ),
				'description' => __( 'Find invalid, unregistered and broken blocks across the site, see where each block type is used, and undo a batch of fixes.', 'block-doctor' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$state  = array(
			'type'       => 'object',
			'properties' => array(
				'status'    => array(
					'type' => 'string',
					'enum' => array( 'idle', 'running', 'paused', 'done' ),
				),
				'started'   => array( 'type' => 'integer' ),
				'finished'  => array( 'type' => 'integer' ),
				'done'      => array( 'type' => 'integer' ),
				'total'     => array( 'type' => 'integer' ),
				'found'     => array( 'type' => 'integer' ),
				'post_type' => array( 'type' => 'string' ),
			),
		);
		$counts = array(
			'type'                 => 'object',
			'description'          => __( 'Issues and posts per kind.', 'block-doctor' ),
			'additionalProperties' => array(
				'type'       => 'object',
				'properties' => array(
					'issues' => array( 'type' => 'integer' ),
					'posts'  => array( 'type' => 'integer' ),
				),
			),
		);
		$kinds  = array_keys( $this->config->issue_kinds() );
		$issue  = array(
			'type'       => 'object',
			'properties' => array(
				'kind'    => array( 'type' => 'string' ),
				'path'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'name'    => array( 'type' => 'string' ),
				'message' => array( 'type' => 'string' ),
				'detail'  => array(
					'type'        => 'string',
					'description' => 'Technical detail, such as the block editor\'s validation message, or empty.',
				),
				'excerpt' => array(
					'type'        => 'string',
					'description' => 'The first words of the block, or empty.',
				),
				'source'  => array( 'type' => 'string' ),
			),
		);

		$this->ability(
			'get-scan-summary',
			__( 'Get scan summary', 'block-doctor' ),
			__( 'Issue counts per kind from the stored results, how many posts have issues and how many have broken blocks, and the state of the last browser scan (Tools > Block Doctor) and server scan. Kinds: invalid (markup the editor rejects), missing (unregistered block type), classic, deprecated (outdated markup), empty, pattern-ref, navigation-ref, missing-media and error (a post the scan could not check).', 'block-doctor' ),
			array(),
			array(
				'type'       => 'object',
				'properties' => array(
					'browser'      => $state,
					'server'       => $state,
					'last_scan'    => array( 'type' => 'integer' ),
					'posts'        => array( 'type' => 'integer' ),
					'broken_posts' => array( 'type' => 'integer' ),
					'counts'       => $counts,
				),
			),
			array( $this, 'get_summary' ),
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'list-issues',
			__( 'List issues', 'block-doctor' ),
			__( 'Posts with stored issues, lowest ID first, each with its issues: kind, block path (position from 0, one number per level), block name, message, technical detail and the first words of the block. `stale` means the post changed since it was scanned.', 'block-doctor' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'kind'      => array(
						'type' => 'string',
						'enum' => $kinds,
					),
					'post_type' => array( 'type' => 'string' ),
					'post_id'   => array(
						'type'    => 'integer',
						'minimum' => 1,
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
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'total' => array( 'type' => 'integer' ),
					'items' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'           => array( 'type' => 'integer' ),
								'title'        => array( 'type' => 'string' ),
								'type'         => array( 'type' => 'string' ),
								'status'       => array( 'type' => 'string' ),
								'status_label' => array( 'type' => 'string' ),
								'edit_link'    => array( 'type' => 'string' ),
								'scanned'      => array( 'type' => 'integer' ),
								'stale'        => array( 'type' => 'boolean' ),
								'issues'       => array(
									'type'  => 'array',
									'items' => $issue,
								),
							),
						),
					),
				),
			),
			array( $this, 'list_issues' ),
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'run-server-scan',
			__( 'Run the server-side scan', 'block-doctor' ),
			__( 'Runs the checks PHP can do on its own: unregistered block types, classic content, emptied posts, and references to deleted synced patterns, navigation menus and media. With post_id it checks that post now. Otherwise it checks the first batch now and the rest in the background with WP-Cron; read get-scan-summary for progress. It cannot see invalid block markup, which needs the browser scan on Tools > Block Doctor, and it fixes nothing.', 'block-doctor' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array( 'type' => 'string' ),
					'post_id'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'state'  => $state,
					'issues' => array(
						'type'  => 'array',
						'items' => $issue,
					),
				),
			),
			array( $this, 'run_server_scan' ),
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		$provider = array(
			'type'       => 'object',
			'properties' => array(
				'type'     => array(
					'type' => 'string',
					'enum' => array( 'core', 'plugin', 'mu-plugin', 'theme', 'unknown' ),
				),
				'slug'     => array( 'type' => 'string' ),
				'name'     => array( 'type' => 'string' ),
				'basename' => array( 'type' => 'string' ),
			),
		);

		$this->ability(
			'get-block-usage',
			__( 'Get block usage', 'block-doctor' ),
			__( 'Which block types the site uses, who provides each one (core, a plugin, a theme, or unknown), how many posts use it and how many times. Give block to get the posts that use one block type, with the position of the block in each post, or provider (such as plugin:acme/acme.php) for the posts that use any block of one plugin or theme. Covers posts, pages, other post types, synced patterns, templates and template parts you may edit. Read ready first: false means the index is still being built.', 'block-doctor' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'block'    => array( 'type' => 'string' ),
					'provider' => array( 'type' => 'string' ),
					'filter'   => array(
						'type' => 'string',
						'enum' => array( 'core', 'plugin', 'theme', 'unregistered', 'unused' ),
					),
					'search'   => array( 'type' => 'string' ),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'ready'  => array( 'type' => 'boolean' ),
					'total'  => array( 'type' => 'integer' ),
					'blocks' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'       => array( 'type' => 'string' ),
								'title'      => array( 'type' => 'string' ),
								'provider'   => $provider,
								'registered' => array( 'type' => 'boolean' ),
								'posts'      => array( 'type' => 'integer' ),
								'uses'       => array( 'type' => 'integer' ),
								'last'       => array( 'type' => 'integer' ),
							),
						),
					),
					'posts'  => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'        => array( 'type' => 'integer' ),
								'title'     => array( 'type' => 'string' ),
								'type'      => array( 'type' => 'string' ),
								'status'    => array( 'type' => 'string' ),
								'edit_link' => array( 'type' => 'string' ),
								'uses'      => array( 'type' => 'integer' ),
								'positions' => array(
									'type'  => 'array',
									'items' => array(
										'type'  => 'array',
										'items' => array( 'type' => 'integer' ),
									),
								),
							),
						),
					),
				),
			),
			array( $this, 'get_block_usage' ),
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'run-usage-index',
			__( 'Build the block usage index', 'block-doctor' ),
			__( 'Counts the blocks of every post, so get-block-usage has numbers. It does the first batch now and the rest in the background with WP-Cron, and it changes no post. The index keeps itself current after that, whenever a post is saved or deleted. Read get-block-usage for ready.', 'block-doctor' ),
			array(),
			array(
				'type'       => 'object',
				'properties' => array(
					'ready'  => array( 'type' => 'boolean' ),
					'status' => array(
						'type' => 'string',
						'enum' => array( 'idle', 'running', 'done' ),
					),
					'done'   => array( 'type' => 'integer' ),
					'total'  => array( 'type' => 'integer' ),
				),
			),
			array( $this, 'run_usage_index' ),
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		$this->ability(
			'undo-batch',
			__( 'Undo a batch of fixes', 'block-doctor' ),
			sprintf(
				/* translators: %d: number of days. */
				__( 'Puts every post fixed in a batch back to its content before the fix, from the revision saved before it. Posts edited since the fix are skipped and listed. Fixes are made on Tools > Block Doctor, and each batch can be undone for %d days. Call again while remaining is above 0.', 'block-doctor' ),
				$this->config->undo_days()
			),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'batch_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'             => array( 'batch_id' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'batch_id'  => array( 'type' => 'integer' ),
					'undone'    => array( 'type' => 'boolean' ),
					'restored'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'skipped'   => array(
						'type'                 => 'object',
						'description'          => 'Post ID => why it was left alone: edited, deleted, forbidden, nothing or revision.',
						'additionalProperties' => array( 'type' => 'string' ),
					),
					'remaining' => array( 'type' => 'integer' ),
				),
			),
			array( $this, 'undo_batch' ),
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * Ability: the summary.
	 *
	 * @return array
	 */
	public function get_summary(): array {
		$summary = $this->report->summary();
		foreach ( array( 'browser', 'server' ) as $source ) {
			unset( $summary[ $source ]['cursor'] );
		}

		return $summary;
	}

	/**
	 * Ability: posts with issues.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function list_issues( $input = null ): array {
		$input = (array) $input;
		$args  = array(
			'kind'      => sanitize_key( (string) ( $input['kind'] ?? '' ) ),
			'post_type' => sanitize_key( (string) ( $input['post_type'] ?? '' ) ),
			'post_id'   => (int) ( $input['post_id'] ?? 0 ),
			'page'      => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'per_page'  => max( 1, min( 100, (int) ( $input['per_page'] ?? 20 ) ) ),
			// Only posts the current user may edit.
			'editable'  => true,
		);
		$found = $this->issues->find( $args );
		$items = array();
		foreach ( $this->issues->rows( $found['ids'], $args['kind'] ) as $row ) {
			unset( $row['type_label'] );
			$items[] = $row;
		}

		return array(
			'total' => $found['total'],
			'items' => $items,
		);
	}

	/**
	 * Ability: the server-side scan.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function run_server_scan( $input = null ) {
		$input   = (array) $input;
		$post_id = (int) ( $input['post_id'] ?? 0 );

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || ! $this->posts->is_scanned( $post ) || ! current_user_can( 'edit_post', $post->ID ) ) {
				return new \WP_Error( 'block_doctor_post', __( 'Block Doctor does not scan that post, or you cannot edit it.', 'block-doctor' ) );
			}
			$state = $this->scanner->state( 'server' );
			unset( $state['cursor'] );

			return array(
				'state'  => $state,
				'issues' => $this->scanner->scan_post( $post ),
			);
		}

		$post_type = sanitize_key( (string) ( $input['post_type'] ?? '' ) );
		if ( '' !== $post_type && ! in_array( $post_type, $this->config->post_types(), true ) ) {
			return new \WP_Error( 'block_doctor_post_type', __( 'Block Doctor does not scan that post type.', 'block-doctor' ) );
		}

		$state = $this->scanner->start_server( $post_type, true );
		unset( $state['cursor'] );

		return array(
			'state'  => $state,
			'issues' => array(),
		);
	}

	/**
	 * Ability: block usage.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function get_block_usage( $input = null ): array {
		$input    = (array) $input;
		$types    = $this->usage->visible_types();
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $input['per_page'] ?? 20 ) ) );
		$block    = Issues::block_name( (string) ( $input['block'] ?? '' ) );
		$provider = sanitize_text_field( (string) ( $input['provider'] ?? '' ) );
		$result   = array(
			'ready'  => $this->usage->ready(),
			'total'  => 0,
			'blocks' => array(),
			'posts'  => array(),
		);

		if ( '' !== $block || '' !== $provider ) {
			$names = '' !== $block ? array( $block ) : $this->providers->blocks_of( $provider );
			$found = $this->usage->posts_using( $names, $types, $page, $per_page );
			foreach ( $found['items'] as $item ) {
				$post = get_post( $item['id'] );
				if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
					continue;
				}
				$result['posts'][] = array(
					'id'        => (int) $post->ID,
					'title'     => '' !== $post->post_title ? (string) $post->post_title : (string) $post->post_name,
					'type'      => (string) $post->post_type,
					'status'    => (string) $post->post_status,
					'edit_link' => (string) get_edit_post_link( $post->ID, 'raw' ),
					'uses'      => $item['uses'],
					'positions' => '' !== $block ? $this->usage->positions( $post, $block, $this->config->usage_positions() ) : array(),
				);
			}
			$result['total'] = $found['total'];

			return $result;
		}

		$rows            = $this->usage->blocks(
			array(
				'types'  => $types,
				'search' => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
				'filter' => sanitize_key( (string) ( $input['filter'] ?? '' ) ),
			)
		);
		$result['total'] = count( $rows );
		foreach ( array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ) as $row ) {
			$result['blocks'][] = $row;
		}

		return $result;
	}

	/**
	 * Ability: build the block usage index.
	 *
	 * @return array
	 */
	public function run_usage_index(): array {
		$state = $this->usage->state();
		if ( 'running' !== $state['status'] ) {
			$this->usage->start();
		}
		$state = $this->usage->step();
		if ( 'running' === $state['status'] ) {
			wp_schedule_single_event( time(), Usage::CRON );
		}

		return array(
			'ready'  => $this->usage->ready(),
			'status' => $state['status'],
			'done'   => $state['done'],
			'total'  => $state['total'],
		);
	}

	/**
	 * Ability: Undo batch.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function undo_batch( $input = null ) {
		$input  = (array) $input;
		$result = $this->fixer->undo( (int) ( $input['batch_id'] ?? 0 ), $this->config->batch_size() * 5 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'batch_id'  => (int) $result['batch']['id'],
			'undone'    => (bool) $result['batch']['undone'],
			'restored'  => array_map( 'intval', $result['batch']['restored'] ),
			'skipped'   => (object) $result['batch']['skipped'],
			'remaining' => (int) $result['remaining'],
		);
	}

	/**
	 * Permission: the Block Doctor capability.
	 *
	 * @return bool
	 */
	public function can_use(): bool {
		return $this->config->can_use();
	}

	/**
	 * Registers one ability in the Block Doctor category.
	 *
	 * @param string   $name        Name without the namespace.
	 * @param string   $label       Label.
	 * @param string   $description What it does, for people and agents.
	 * @param array    $input       Input schema, empty for none.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, array $annotations ): void {
		$args = array(
			'label'               => $label,
			'description'         => $description,
			'category'            => self::CATEGORY,
			'output_schema'       => $output,
			'execute_callback'    => $execute,
			'permission_callback' => array( $this, 'can_use' ),
			'meta'                => array(
				'annotations'  => $annotations,
				'show_in_rest' => true,
				// Clients such as the MCP adapter only list abilities marked public:
				// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
				'public'       => true,
				'mcp'          => array( 'public' => true ),
			),
		);
		// An ability without input has no input schema.
		if ( $input ) {
			$args['input_schema'] = $input;
		}

		wp_register_ability( self::CATEGORY . '/' . $name, $args );
	}
}
