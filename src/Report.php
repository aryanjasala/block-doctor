<?php
/**
 * The report: summary and rows, shared by the screen, CSV, WP-CLI and abilities.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the stored results in the shapes the different outputs need.
 */
final class Report {

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
	 * Scan state.
	 *
	 * @var Scanner
	 */
	private Scanner $scanner;

	/**
	 * Sets up the report.
	 *
	 * @param Config  $config  Defaults and limits.
	 * @param Issues  $issues  Stored results.
	 * @param Scanner $scanner Scan state.
	 */
	public function __construct( Config $config, Issues $issues, Scanner $scanner ) {
		$this->config  = $config;
		$this->issues  = $issues;
		$this->scanner = $scanner;
	}

	/**
	 * Counts and the state of both scans.
	 *
	 * @return array
	 */
	public function summary(): array {
		$browser = $this->scanner->state( 'browser' );
		$server  = $this->scanner->state( 'server' );

		return array(
			'browser'      => $browser,
			'server'       => $server,
			'last_scan'    => max( $browser['finished'], $server['finished'] ),
			'posts'        => count( $this->issues->index() ),
			'broken_posts' => $this->issues->broken_posts(),
			'counts'       => $this->issues->counts(),
		);
	}

	/**
	 * One line per issue, for CSV and WP-CLI.
	 *
	 * @param array $args `kind`, `post_type`, `post_id`.
	 * @return array[]
	 */
	public function lines( array $args = array() ): array {
		$kind  = (string) ( $args['kind'] ?? '' );
		$lines = array();
		$ids   = $this->issues->find( $args )['ids'];

		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			foreach ( $this->issues->rows( $chunk, $kind ) as $row ) {
				foreach ( $row['issues'] as $issue ) {
					$lines[] = self::line( $row, $issue );
				}
			}
		}

		return $lines;
	}

	/**
	 * Columns of lines().
	 *
	 * @return string[]
	 */
	public static function columns(): array {
		return array( 'post_id', 'title', 'post_type', 'status', 'kind', 'block', 'path', 'message', 'detail', 'excerpt', 'source', 'changed_since_scan', 'scanned', 'edit_link' );
	}

	/**
	 * One issue as a line.
	 *
	 * @param array $row   Report row.
	 * @param array $issue Issue.
	 * @return array
	 */
	public static function line( array $row, array $issue ): array {
		return array(
			'post_id'            => $row['id'],
			'title'              => $row['title'],
			'post_type'          => $row['type'],
			'status'             => $row['status'],
			'kind'               => $issue['kind'],
			'block'              => $issue['name'],
			'path'               => implode( '.', $issue['path'] ),
			'message'            => $issue['message'],
			'detail'             => $issue['detail'] ?? '',
			'excerpt'            => $issue['excerpt'] ?? '',
			'source'             => $issue['source'] ?? 'server',
			'changed_since_scan' => $row['stale'] ? 'yes' : 'no',
			'scanned'            => gmdate( 'Y-m-d H:i:s', (int) $row['scanned'] ),
			'edit_link'          => $row['edit_link'],
		);
	}

	/**
	 * Issue kinds with their counts, for the screen.
	 *
	 * @return array[]
	 */
	public function kinds(): array {
		$counts = $this->issues->counts();
		$kinds  = array();
		foreach ( $this->config->issue_kinds() as $key => $kind ) {
			$kinds[] = array_merge( array( 'key' => $key ), $kind, $counts[ $key ] ?? array() );
		}

		return $kinds;
	}
}
