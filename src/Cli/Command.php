<?php
/**
 * `wp block-doctor` commands.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor\Cli;

use AryanX\Axle\BlockDoctor\Batches;
use AryanX\Axle\BlockDoctor\Config;
use AryanX\Axle\BlockDoctor\Fixer;
use AryanX\Axle\BlockDoctor\Issues;
use AryanX\Axle\BlockDoctor\Providers;
use AryanX\Axle\BlockDoctor\Report;
use AryanX\Axle\BlockDoctor\Scanner;
use AryanX\Axle\BlockDoctor\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the server-side scan, lists stored issues and undoes fix batches.
 *
 * Invalid block markup can only be found and fixed in the browser, on
 * Tools > Block Doctor: block validation exists only in the block editor's
 * JavaScript. These commands cover everything PHP can do on its own.
 */
final class Command {

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
	 * Fix batches.
	 *
	 * @var Batches
	 */
	private Batches $batches;

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
	 * Sets up the command.
	 *
	 * @param Config    $config    Defaults and limits.
	 * @param Issues    $issues    Stored results.
	 * @param Scanner   $scanner   Scans.
	 * @param Batches   $batches   Fix batches.
	 * @param Fixer     $fixer     Undo.
	 * @param Report    $report    The report.
	 * @param Usage     $usage     The block usage index.
	 * @param Providers $providers Where each block type comes from.
	 */
	public function __construct( Config $config, Issues $issues, Scanner $scanner, Batches $batches, Fixer $fixer, Report $report, Usage $usage, Providers $providers ) {
		$this->config    = $config;
		$this->issues    = $issues;
		$this->scanner   = $scanner;
		$this->batches   = $batches;
		$this->fixer     = $fixer;
		$this->report    = $report;
		$this->usage     = $usage;
		$this->providers = $providers;
	}

	/**
	 * Runs the server-side checks on every post and stores the results.
	 *
	 * Finds unregistered block types, classic content, posts emptied since a
	 * revision with blocks, and references to deleted synced patterns,
	 * navigation menus and media. Invalid block markup needs the browser scan
	 * on Tools > Block Doctor, because block validation only exists in the
	 * block editor's JavaScript.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Only this post type.
	 *
	 * [--format=<format>]
	 * : How to list the issues found: table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp block-doctor scan
	 *     wp block-doctor scan --post_type=page --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function scan( array $args, array $assoc_args ): void {
		$post_type = (string) ( $assoc_args['post_type'] ?? '' );
		$format    = (string) ( $assoc_args['format'] ?? 'table' );
		if ( '' !== $post_type && ! in_array( $post_type, $this->config->post_types(), true ) ) {
			\WP_CLI::error( sprintf( 'Block Doctor does not scan "%s". It scans: %s.', $post_type, implode( ', ', $this->config->post_types() ) ) );
		}

		$state = $this->scanner->begin_server( $post_type );
		$lines = array();
		$kinds = $this->config->issue_kinds();

		while ( 'running' === $state['status'] ) {
			$batch = $this->scanner->server_batch();
			$state = $batch['state'];
			if ( 'table' === $format && 0 === $state['done'] % ( 10 * $this->config->batch_size() ) ) {
				\WP_CLI::log( sprintf( 'Checked %d of %d posts...', $state['done'], $state['total'] ) );
			}
			foreach ( $batch['found'] as $post_id => $issues ) {
				$post = get_post( $post_id );
				foreach ( $issues as $issue ) {
					if ( $post && isset( $kinds[ $issue['kind'] ?? '' ] ) ) {
						$lines[] = array(
							'post_id'   => $post_id,
							'title'     => $post->post_title,
							'post_type' => $post->post_type,
							'kind'      => $issue['kind'],
							'block'     => $issue['name'] ?? '',
							'path'      => implode( '.', (array) ( $issue['path'] ?? array() ) ),
							'message'   => $issue['message'] ?? '',
						);
					}
				}
			}
		}
		\WP_CLI\Utils\format_items( $format, $lines, array( 'post_id', 'title', 'post_type', 'kind', 'block', 'path', 'message' ) );
		if ( 'table' === $format ) {
			\WP_CLI::success( sprintf( 'Checked %d posts: %d issues in %d posts. Invalid blocks need the browser scan on Tools > Block Doctor.', $state['done'], count( $lines ), count( array_unique( array_column( $lines, 'post_id' ) ) ) ) );
		}
	}

	/**
	 * Lists the stored issues, from both the browser scan and the server checks.
	 *
	 * ## OPTIONS
	 *
	 * [--kind=<kind>]
	 * : Only this kind: invalid, missing, classic, deprecated, empty, pattern-ref, navigation-ref or missing-media.
	 *
	 * [--post_type=<type>]
	 * : Only this post type.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp block-doctor report
	 *     wp block-doctor report --kind=invalid --format=csv > invalid.csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function report( array $args, array $assoc_args ): void {
		$kind = (string) ( $assoc_args['kind'] ?? '' );
		if ( '' !== $kind && ! isset( $this->config->issue_kinds()[ $kind ] ) ) {
			\WP_CLI::error( sprintf( 'Unknown kind "%s". Kinds: %s.', $kind, implode( ', ', array_keys( $this->config->issue_kinds() ) ) ) );
		}

		$lines = $this->report->lines(
			array(
				'kind'      => $kind,
				'post_type' => (string) ( $assoc_args['post_type'] ?? '' ),
			)
		);
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $lines, array( 'post_id', 'title', 'post_type', 'kind', 'block', 'path', 'message', 'source', 'changed_since_scan' ) );
	}

	/**
	 * Lists every block type with its provider, posts and uses, or the posts that use one block type.
	 *
	 * Counts come from the block usage index, which builds itself in the
	 * background on the site and is built here first when it never was. With
	 * --block or --provider the command lists posts instead of block types.
	 *
	 * ## OPTIONS
	 *
	 * [--block=<name>]
	 * : List the posts that use this block type, with the position of the block in each post.
	 *
	 * [--provider=<key>]
	 * : Only block types of one plugin or theme, such as plugin:acme/acme.php or theme:twentytwentyfive. With no --block, lists the posts that use any of them.
	 *
	 * [--filter=<filter>]
	 * : Only core, plugin, theme, unregistered (used but not registered) or unused (registered, not used) block types.
	 *
	 * [--search=<text>]
	 * : Only block types whose name, title or provider contains this text.
	 *
	 * [--reindex]
	 * : Count the blocks of every post again first.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp block-doctor usage
	 *     wp block-doctor usage --filter=unregistered
	 *     wp block-doctor usage --block=acme/gallery --format=csv
	 *     wp block-doctor usage --provider=plugin:acme/acme.php
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function usage( array $args, array $assoc_args ): void {
		$format   = (string) ( $assoc_args['format'] ?? 'table' );
		$block    = Issues::block_name( (string) ( $assoc_args['block'] ?? '' ) );
		$provider = (string) ( $assoc_args['provider'] ?? '' );
		$filter   = (string) ( $assoc_args['filter'] ?? '' );
		if ( '' !== $filter && ! in_array( $filter, array( 'core', 'plugin', 'theme', 'unregistered', 'unused' ), true ) ) {
			\WP_CLI::error( sprintf( 'Unknown filter "%s". Filters: core, plugin, theme, unregistered, unused.', $filter ) );
		}

		if ( ! empty( $assoc_args['reindex'] ) || ! $this->usage->ready() ) {
			$state = $this->usage->start();
			while ( 'running' === $state['status'] ) {
				$state = $this->usage->step();
				if ( 'table' === $format && 0 === $state['done'] % ( 10 * $this->config->batch_size() ) ) {
					\WP_CLI::log( sprintf( 'Counted the blocks of %d of %d posts...', $state['done'], $state['total'] ) );
				}
			}
			if ( 'table' === $format ) {
				\WP_CLI::log( sprintf( 'Counted the blocks of %d posts.', $state['done'] ) );
			}
		}

		if ( '' !== $block || '' !== $provider ) {
			$names = '' !== $block ? array( $block ) : $this->providers->blocks_of( $provider );
			if ( '' !== $provider && ! $names ) {
				\WP_CLI::error( sprintf( 'No block types are known for "%s". Keys look like plugin:acme/acme.php or theme:twentytwentyfive.', $provider ) );
			}
			$lines = array();
			$page  = 1;
			$more  = true;
			while ( $more ) {
				$found = $this->usage->posts_using( $names, null, $page, 100 );
				foreach ( $found['items'] as $item ) {
					$post = get_post( $item['id'] );
					if ( ! $post ) {
						continue;
					}
					$lines[] = array(
						'post_id'   => $post->ID,
						'title'     => $post->post_title,
						'post_type' => $post->post_type,
						'status'    => $post->post_status,
						'uses'      => $item['uses'],
						'positions' => '' !== $block ? implode( ' ', array_map( static fn( array $path ): string => implode( '.', $path ), $this->usage->positions( $post, $block, $this->config->usage_positions() ) ) ) : '',
					);
				}
				++$page;
				$more = 100 === count( $found['items'] );
			}
			\WP_CLI\Utils\format_items( $format, $lines, array( 'post_id', 'title', 'post_type', 'status', 'uses', 'positions' ) );
			return;
		}

		$lines = array();
		foreach ( $this->usage->blocks(
			array(
				'search' => (string) ( $assoc_args['search'] ?? '' ),
				'filter' => $filter,
			)
		) as $row ) {
			$lines[] = array(
				'block'      => $row['name'],
				'title'      => $row['title'],
				'provider'   => '' !== $row['provider']['name'] ? $row['provider']['name'] : 'unknown',
				'type'       => $row['provider']['type'],
				'registered' => $row['registered'] ? 'yes' : 'no',
				'posts'      => $row['posts'],
				'uses'       => $row['uses'],
				'last_used'  => $row['last'] ? gmdate( 'Y-m-d H:i:s', $row['last'] ) : '',
			);
		}
		\WP_CLI\Utils\format_items( $format, $lines, array( 'block', 'title', 'provider', 'type', 'registered', 'posts', 'uses', 'last_used' ) );
	}

	/**
	 * Lists the fix batches that can still be undone.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function batches( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( $this->batches->all() as $batch ) {
			$rows[] = array(
				'id'       => $batch['id'],
				'date'     => gmdate( 'Y-m-d H:i:s', (int) $batch['time'] ),
				'kinds'    => implode( ', ', $batch['kinds'] ),
				'posts'    => count( $batch['posts'] ),
				'undone'   => $batch['undone'] ? gmdate( 'Y-m-d H:i:s', (int) $batch['undone'] ) : '',
				'restored' => count( $batch['restored'] ),
				'skipped'  => count( $batch['skipped'] ),
			);
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'date', 'kinds', 'posts', 'undone', 'restored', 'skipped' ) );
	}

	/**
	 * Undoes a batch of fixes: every post goes back to its content before the fix.
	 *
	 * Posts edited since the fix are skipped and listed. Fixes are made on
	 * Tools > Block Doctor; `wp block-doctor batches` lists them.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Batch ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp block-doctor undo-batch 3
	 *
	 * @subcommand undo-batch
	 *
	 * @param array $args Positional arguments.
	 */
	public function undo_batch( array $args ): void {
		$result = $this->fixer->undo( (int) $args[0] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		foreach ( $result['batch']['skipped'] as $post_id => $reason ) {
			\WP_CLI::warning( sprintf( 'Skipped post %d: %s.', $post_id, self::reason( (string) $reason ) ) );
		}
		\WP_CLI::success( sprintf( 'Batch %d undone: %d posts restored, %d skipped.', (int) $args[0], count( $result['batch']['restored'] ), count( $result['batch']['skipped'] ) ) );
	}

	/**
	 * Deletes every stored result and both scans' progress. Fix batches stay, so they can still be undone.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask first.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function clear( array $args, array $assoc_args ): void {
		\WP_CLI::confirm( 'Delete every stored Block Doctor result?', $assoc_args );
		$count = $this->issues->clear();
		$this->scanner->reset();
		\WP_CLI::success( sprintf( 'Cleared the results for %d posts.', $count ) );
	}

	/**
	 * Why Undo batch left a post alone, in words.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	private static function reason( string $reason ): string {
		$reasons = array(
			'edited'    => 'it was edited after the fix',
			'deleted'   => 'it was deleted',
			'forbidden' => 'you cannot edit it',
			'nothing'   => 'nothing to undo',
			'revision'  => 'the revision from before the fix is gone',
		);

		return $reasons[ $reason ] ?? $reason;
	}
}
