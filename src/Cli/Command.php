<?php
/**
 * `wp block-doctor` commands.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor\Cli;

use AryanX\BlockDoctor\Batches;
use AryanX\BlockDoctor\Config;
use AryanX\BlockDoctor\Fixer;
use AryanX\BlockDoctor\Issues;
use AryanX\BlockDoctor\Report;
use AryanX\BlockDoctor\Scanner;

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
	 * Sets up the command.
	 *
	 * @param Config  $config  Defaults and limits.
	 * @param Issues  $issues  Stored results.
	 * @param Scanner $scanner Scans.
	 * @param Batches $batches Fix batches.
	 * @param Fixer   $fixer   Undo.
	 * @param Report  $report  The report.
	 */
	public function __construct( Config $config, Issues $issues, Scanner $scanner, Batches $batches, Fixer $fixer, Report $report ) {
		$this->config  = $config;
		$this->issues  = $issues;
		$this->scanner = $scanner;
		$this->batches = $batches;
		$this->fixer   = $fixer;
		$this->report  = $report;
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
