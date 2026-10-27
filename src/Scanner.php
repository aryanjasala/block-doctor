<?php
/**
 * Scan runs: the browser scan's progress, and the server-side scan.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the state of both kinds of scan, so they can pause and pick up again
 * from the last post ID they reached.
 *
 * The browser scan runs on Tools > Block Doctor: the page fetches posts in
 * batches, checks them with the block editor's own parser, and posts the
 * results here. Each batch also runs the server checks on the same posts.
 *
 * The server scan runs only the server checks, from WP-CLI, an ability or
 * WP-Cron, a batch at a time.
 */
final class Scanner implements Service {

	public const BROWSER = 'block_doctor_scan';

	public const SERVER = 'block_doctor_server_scan';

	public const CRON = 'block_doctor_server_scan';

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
	 * Server checks.
	 *
	 * @var Checks
	 */
	private Checks $checks;

	/**
	 * Sets up the scanner.
	 *
	 * @param Config $config Defaults and limits.
	 * @param Posts  $posts  Finds the posts.
	 * @param Issues $issues Stored results.
	 * @param Checks $checks Server checks.
	 */
	public function __construct( Config $config, Posts $posts, Issues $issues, Checks $checks ) {
		$this->config = $config;
		$this->posts  = $posts;
		$this->issues = $issues;
		$this->checks = $checks;
	}

	/**
	 * Runs background server scans.
	 */
	public function register(): void {
		add_action( self::CRON, array( $this, 'run_cron' ) );
	}

	/**
	 * State of a scan.
	 *
	 * @param string $source `browser` or `server`.
	 * @return array{status: string, started: int, finished: int, cursor: int, done: int, total: int, found: int, post_type: string}
	 */
	public function state( string $source ): array {
		$state = get_option( 'server' === $source ? self::SERVER : self::BROWSER, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'status'    => in_array( $state['status'] ?? '', array( 'running', 'paused', 'done' ), true ) ? (string) $state['status'] : 'idle',
			'started'   => (int) ( $state['started'] ?? 0 ),
			'finished'  => (int) ( $state['finished'] ?? 0 ),
			'cursor'    => (int) ( $state['cursor'] ?? 0 ),
			'done'      => (int) ( $state['done'] ?? 0 ),
			'total'     => (int) ( $state['total'] ?? 0 ),
			'found'     => (int) ( $state['found'] ?? 0 ),
			'post_type' => (string) ( $state['post_type'] ?? '' ),
		);
	}

	/**
	 * Starts a browser scan from the first post.
	 *
	 * @param array $js_blocks Block types registered in the browser, so the server check for unregistered blocks knows about JavaScript-only blocks.
	 * @return array State.
	 */
	public function start_browser( array $js_blocks ): array {
		$names = array();
		foreach ( $js_blocks as $name ) {
			if ( is_string( $name ) && preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name ) ) {
				$names[ $name ] = true;
			}
		}
		update_option( Checks::JS_BLOCKS, array_keys( $names ), false );

		return $this->start( 'browser', '' );
	}

	/**
	 * Pauses or resumes the browser scan.
	 *
	 * @param bool $pause True to pause, false to resume.
	 * @return array State.
	 */
	public function pause_browser( bool $pause ): array {
		$state = $this->state( 'browser' );
		if ( in_array( $state['status'], array( 'running', 'paused' ), true ) ) {
			$state['status'] = $pause ? 'paused' : 'running';
			$this->save( 'browser', $state );
		}

		return $state;
	}

	/**
	 * Stores one batch of browser results and moves the scan on.
	 *
	 * @param int   $cursor   Highest post ID the batch covered.
	 * @param int   $count    Posts the browser looked at, including any it skipped.
	 * @param array $results  Each `array( 'id' => int, 'hash' => md5 of the content checked, 'issues' => array )`.
	 * @param bool  $finished Whether there are no posts after this batch.
	 * @return array State.
	 */
	public function record( int $cursor, int $count, array $results, bool $finished ): array {
		$state = $this->state( 'browser' );

		// One write of the results index for the whole batch, not one per post.
		$state['found'] += $this->issues->batch(
			function () use ( $results ): int {
				$found = 0;
				foreach ( $results as $result ) {
					if ( ! is_array( $result ) ) {
						continue;
					}
					$post = get_post( (int) ( $result['id'] ?? 0 ) );
					if ( ! $post || ! $this->posts->is_scanned( $post ) || ! current_user_can( 'edit_post', $post->ID ) ) {
						continue;
					}
					if ( $this->scan_post( $post, (array) ( $result['issues'] ?? array() ), (string) ( $result['hash'] ?? '' ) ) ) {
						++$found;
					}
				}

				return $found;
			}
		);

		$state['cursor'] = max( $state['cursor'], $cursor );
		$state['done']  += max( 0, $count );
		if ( $finished ) {
			$this->finish( 'browser', $state );
		} else {
			$this->save( 'browser', $state );
		}

		return $state;
	}

	/**
	 * Starts a server scan from the first post.
	 *
	 * @param string $post_type  One post type, or '' for all.
	 * @param bool   $background Carry on with WP-Cron after the first batch.
	 * @return array State.
	 */
	public function start_server( string $post_type = '', bool $background = false ): array {
		$this->begin_server( $post_type );
		$batch = $this->server_batch();

		if ( $background && 'running' === $batch['state']['status'] ) {
			wp_schedule_single_event( time(), self::CRON );
		}

		return $batch['state'];
	}

	/**
	 * Starts a server scan without checking anything yet. Call server_batch() until it is done.
	 *
	 * @param string $post_type One post type, or '' for all.
	 * @return array State.
	 */
	public function begin_server( string $post_type = '' ): array {
		wp_clear_scheduled_hook( self::CRON );

		return $this->start( 'server', $post_type );
	}

	/**
	 * Runs the server checks on the next batch of posts.
	 *
	 * @return array{state: array, found: array<int, array>} State, and the issues the server checks found, by post ID.
	 */
	public function server_batch(): array {
		$state = $this->state( 'server' );
		$found = array();
		if ( 'running' !== $state['status'] ) {
			return array(
				'state' => $state,
				'found' => $found,
			);
		}

		$limit = $this->config->batch_size();
		$posts = $this->posts->after( $state['cursor'], $limit, $state['post_type'] );
		$this->issues->begin();
		try {
			foreach ( $posts as $post ) {
				$server = array();
				if ( $this->scan_post( $post, null, '', $server ) ) {
					++$state['found'];
				}
				if ( $server ) {
					$found[ (int) $post->ID ] = $server;
				}
				$state['cursor'] = (int) $post->ID;
				++$state['done'];
			}
		} finally {
			// One write of the results index for the whole batch, not one per post.
			$this->issues->commit();
		}

		if ( count( $posts ) < $limit ) {
			$this->finish( 'server', $state );
		} else {
			$this->save( 'server', $state );
		}

		return array(
			'state' => $state,
			'found' => $found,
		);
	}

	/**
	 * WP-Cron: runs server batches until the time limit, then schedules the next run.
	 */
	public function run_cron(): void {
		$until = microtime( true ) + $this->config->cron_time_limit();
		do {
			$state = $this->server_batch()['state'];
		} while ( 'running' === $state['status'] && microtime( true ) < $until );

		if ( 'running' === $state['status'] ) {
			wp_schedule_single_event( time(), self::CRON );
		}
	}

	/**
	 * Checks one post on the server and stores the result, with the browser's issues when there are some.
	 *
	 * @param \WP_Post   $post    Post.
	 * @param array|null $browser Issues the browser found, or null to keep the stored ones.
	 * @param string     $hash    md5 of the content the browser checked.
	 * @param array|null $server  Set to the issues the server checks found.
	 * @param-out array  $server
	 * @return array Every issue now stored for the post.
	 */
	public function scan_post( \WP_Post $post, ?array $browser = null, string $hash = '', ?array &$server = null ): array {
		$current   = md5( $post->post_content );
		$server    = $this->checks->run( $post );
		$by_source = array( 'server' => $server );

		if ( null !== $browser ) {
			// The post changed after the browser read it: its findings are stale, so leave them for the next scan.
			$by_source = array( 'browser' => $hash === $current ? $browser : array() ) + $by_source;
		}

		return $this->issues->save( $post, $by_source, $current );
	}

	/**
	 * Forgets both scans' progress.
	 */
	public function reset(): void {
		wp_clear_scheduled_hook( self::CRON );
		delete_option( self::BROWSER );
		delete_option( self::SERVER );
	}

	/**
	 * Starts a scan.
	 *
	 * @param string $source    `browser` or `server`.
	 * @param string $post_type One post type, or ''.
	 * @return array State.
	 */
	private function start( string $source, string $post_type ): array {
		$state = array(
			'status'    => 'running',
			'started'   => time(),
			'finished'  => 0,
			'cursor'    => 0,
			'done'      => 0,
			'total'     => $this->posts->total( $post_type ),
			'found'     => 0,
			'post_type' => $post_type,
		);
		$this->save( $source, $state );

		/**
		 * Fires when a scan starts.
		 *
		 * @param string $source `browser` (Tools > Block Doctor) or `server` (WP-CLI, abilities, WP-Cron).
		 * @param array  $state  status, started, finished, cursor, done, total, found, post_type.
		 */
		do_action( 'block_doctor_scan_started', $source, $state );

		return $state;
	}

	/**
	 * Marks a scan done.
	 *
	 * @param string $source `browser` or `server`.
	 * @param array  $state  State, changed in place.
	 */
	private function finish( string $source, array &$state ): void {
		$state['status']   = 'done';
		$state['finished'] = time();
		$this->save( $source, $state );

		/**
		 * Fires when a scan reaches the last post.
		 *
		 * @param string $source `browser` or `server`.
		 * @param array  $state  status, started, finished, cursor, done, total, found (posts with issues), post_type.
		 */
		do_action( 'block_doctor_scan_finished', $source, $state );
	}

	/**
	 * Saves a scan's state.
	 *
	 * @param string $source `browser` or `server`.
	 * @param array  $state  State.
	 */
	private function save( string $source, array $state ): void {
		update_option( 'server' === $source ? self::SERVER : self::BROWSER, $state, false );
	}
}
