<?php
/**
 * Stored scan results.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the issues of each post in post meta, and a small index of which
 * posts have issues of which kind in an option, so counts and the report
 * never need a meta query.
 *
 * A post's record is `array( 'hash' => md5 of the content scanned, 'time' => unix time, 'issues' => list )`.
 * Each issue has `kind`, `path` (block position from 0, one number per level), `name`, `message`,
 * `detail` (technical text, such as the editor's validation message, or ''), `excerpt` (the block's
 * first words, or '') and `source` (`browser` or `server`).
 *
 * The index is one option that grows with the site, so a scan changes it in
 * memory and writes it once per batch of posts (batch()), not once per post.
 */
final class Issues implements Service {

	public const META = '_block_doctor_issues';

	public const INDEX = 'block_doctor_index';

	/**
	 * Defaults and limits.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * The index while a batch() is running, written once when it ends. Null outside a batch.
	 *
	 * @var array|null
	 */
	private ?array $pending = null;

	/**
	 * How many batch() calls are open.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Whether the pending index changed.
	 *
	 * @var bool
	 */
	private bool $changed = false;

	/**
	 * Whether deleted posts keep the index open until the request ends.
	 *
	 * @var bool
	 */
	private bool $until_shutdown = false;

	/**
	 * Sets up the store.
	 *
	 * @param Config $config Defaults and limits.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Forgets a post's issues when it is trashed or deleted.
	 */
	public function register(): void {
		add_action( 'deleted_post', array( $this, 'forget' ) );
		add_action( 'trashed_post', array( $this, 'forget' ) );
	}

	/**
	 * A post's stored record.
	 *
	 * @param int $post_id Post.
	 * @return array|null
	 */
	public function get( int $post_id ): ?array {
		$record = get_post_meta( $post_id, self::META, true );

		return is_array( $record ) && isset( $record['issues'] ) && is_array( $record['issues'] ) ? $record : null;
	}

	/**
	 * Replaces the issues one or more sources found in a post.
	 *
	 * Issues from other sources stay when they were found in the same content,
	 * and are dropped when the content changed. Where two sources report the
	 * same kind at the same path, the first one wins (the browser, which knows
	 * every block type).
	 *
	 * @param \WP_Post $post      Post.
	 * @param array    $by_source Issues by source: `array( 'browser' => array(), 'server' => array() )`.
	 * @param string   $hash      md5 of the content the issues were found in.
	 * @return array The issues now stored.
	 */
	public function save( \WP_Post $post, array $by_source, string $hash ): array {
		$kinds    = $this->config->issue_kinds();
		$existing = $this->get( (int) $post->ID );
		$lists    = array();

		foreach ( $by_source as $source => $issues ) {
			$lists[ (string) $source ] = $this->clean( (array) $issues, (string) $source, $kinds );
		}
		if ( $existing && $existing['hash'] === $hash ) {
			foreach ( $existing['issues'] as $issue ) {
				$source = (string) ( $issue['source'] ?? 'server' );
				if ( ! array_key_exists( $source, $by_source ) ) {
					$lists[ $source ][] = $issue;
				}
			}
		}
		if ( isset( $lists['browser'] ) ) {
			$lists = array( 'browser' => $lists['browser'] ) + $lists;
		}

		$issues = array();
		$seen   = array();
		foreach ( $lists as $list ) {
			foreach ( $list as $issue ) {
				$key = $issue['kind'] . '|' . implode( '.', $issue['path'] );
				if ( isset( $seen[ $key ] ) || ! isset( $kinds[ $issue['kind'] ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$issues[]     = $issue;
			}
		}

		$this->put(
			(int) $post->ID,
			(string) $post->post_type,
			$issues ? array(
				'hash'   => $hash,
				'time'   => time(),
				'issues' => $issues,
			) : null
		);

		if ( $issues ) {
			/**
			 * Fires when a scan finds issues in a post, from the browser scan, the server checks or a fix that left some.
			 *
			 * @param int   $post_id Post.
			 * @param array $issues  Every issue now stored for the post: kind, path, name, message, source.
			 */
			do_action( 'block_doctor_issues_found', (int) $post->ID, $issues );
		}

		return $issues;
	}

	/**
	 * Writes a whole record back, such as the one from before a fix that was undone.
	 *
	 * @param \WP_Post   $post   Post.
	 * @param array|null $record Record, or null for no issues.
	 */
	public function restore( \WP_Post $post, ?array $record ): void {
		$this->put( (int) $post->ID, (string) $post->post_type, $record && ! empty( $record['issues'] ) ? $record : null );
	}

	/**
	 * Drops a post from the results.
	 *
	 * @param int $post_id Post.
	 */
	public function forget( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! isset( $this->index()[ $post_id ] ) ) {
			return;
		}
		delete_post_meta( $post_id, self::META );

		// Emptying the trash deletes many posts in one request: write the index once, when the request ends.
		if ( ! $this->until_shutdown ) {
			$this->until_shutdown = true;
			$this->begin();
			add_action( 'shutdown', array( $this, 'commit' ) );
		}
		$index = $this->index();
		unset( $index[ $post_id ] );
		$this->write( $index );
	}

	/**
	 * Runs some work with the index kept in memory, and writes it once at the end.
	 *
	 * @template T
	 * @param callable(): T $work The work.
	 * @return T What the work returns.
	 */
	public function batch( callable $work ) {
		$this->begin();
		try {
			return $work();
		} finally {
			$this->commit();
		}
	}

	/**
	 * Starts keeping the index in memory. Pair every call with commit().
	 */
	public function begin(): void {
		if ( 0 === $this->depth ) {
			$this->pending = $this->stored_index();
			$this->changed = false;
		}
		++$this->depth;
	}

	/**
	 * Ends what begin() started, and writes the index when the outermost one ends and something changed.
	 */
	public function commit(): void {
		if ( $this->depth < 1 ) {
			return;
		}
		--$this->depth;
		if ( $this->depth > 0 || null === $this->pending ) {
			return;
		}
		if ( $this->changed ) {
			if ( $this->pending ) {
				update_option( self::INDEX, $this->pending, false );
			} else {
				delete_option( self::INDEX );
			}
		}
		$this->pending = null;
		$this->changed = false;
	}

	/**
	 * Deletes every stored result.
	 *
	 * @return int How many posts had issues.
	 */
	public function clear(): int {
		$count = count( $this->index() );
		delete_post_meta_by_key( self::META );
		delete_option( self::INDEX );
		if ( null !== $this->pending ) {
			$this->pending = array();
			$this->changed = false;
		}

		return $count;
	}

	/**
	 * Which posts have issues: post ID => `array( 'type' => post type, 'kinds' => array( kind => count ) )`.
	 *
	 * @return array<int, array{type: string, kinds: array<string, int>}>
	 */
	public function index(): array {
		return null !== $this->pending ? $this->pending : $this->stored_index();
	}

	/**
	 * The index as stored.
	 *
	 * @return array<int, array{type: string, kinds: array<string, int>}>
	 */
	private function stored_index(): array {
		$index = get_option( self::INDEX, array() );

		return is_array( $index ) ? $index : array();
	}

	/**
	 * Saves the index, or keeps it in memory during a batch.
	 *
	 * @param array $index Index.
	 */
	private function write( array $index ): void {
		if ( null !== $this->pending ) {
			$this->pending = $index;
			$this->changed = true;
			return;
		}

		update_option( self::INDEX, $index, false );
	}

	/**
	 * Issue and post counts per kind.
	 *
	 * @param string $post_type Only this post type, or '' for all.
	 * @return array<string, array{issues: int, posts: int}>
	 */
	public function counts( string $post_type = '' ): array {
		$counts = array();
		foreach ( array_keys( $this->config->issue_kinds() ) as $kind ) {
			$counts[ $kind ] = array(
				'issues' => 0,
				'posts'  => 0,
			);
		}
		foreach ( $this->index() as $entry ) {
			if ( '' !== $post_type && $entry['type'] !== $post_type ) {
				continue;
			}
			foreach ( $entry['kinds'] as $kind => $count ) {
				if ( isset( $counts[ $kind ] ) ) {
					$counts[ $kind ]['issues'] += (int) $count;
					++$counts[ $kind ]['posts'];
				}
			}
		}

		return $counts;
	}

	/**
	 * Posts with at least one issue that counts as broken (Site Health).
	 *
	 * @return int
	 */
	public function broken_posts(): int {
		$broken = array_keys( array_filter( wp_list_pluck( $this->config->issue_kinds(), 'broken' ) ) );
		$count  = 0;
		foreach ( $this->index() as $entry ) {
			if ( array_intersect( array_keys( $entry['kinds'] ), $broken ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Post IDs with issues, filtered and paged, lowest ID first.
	 *
	 * @param array $args `kind`, `post_type`, `post_id`, `page` (from 1), `per_page` (0 for all).
	 * @return array{ids: int[], total: int}
	 */
	public function find( array $args = array() ): array {
		$kind      = (string) ( $args['kind'] ?? '' );
		$post_type = (string) ( $args['post_type'] ?? '' );
		$post_id   = (int) ( $args['post_id'] ?? 0 );
		$ids       = array();

		foreach ( $this->index() as $id => $entry ) {
			if ( ( '' !== $kind && empty( $entry['kinds'][ $kind ] ) ) || ( '' !== $post_type && $entry['type'] !== $post_type ) || ( $post_id && $post_id !== (int) $id ) ) {
				continue;
			}
			$ids[] = (int) $id;
		}
		sort( $ids );

		$per_page = max( 0, (int) ( $args['per_page'] ?? 0 ) );
		$total    = count( $ids );
		if ( $per_page ) {
			$ids = array_slice( $ids, ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page, $per_page );
		}

		return array(
			'ids'   => $ids,
			'total' => $total,
		);
	}

	/**
	 * Report rows for posts: the post, whether it changed since the scan, and its issues.
	 *
	 * @param int[]  $ids  Post IDs.
	 * @param string $kind Only issues of this kind, or '' for all.
	 * @return array[]
	 */
	public function rows( array $ids, string $kind = '' ): array {
		$rows = array();
		foreach ( $ids as $id ) {
			$post   = get_post( $id );
			$record = $this->get( $id );
			if ( ! $post || ! $record ) {
				continue;
			}
			$issues = array_values(
				array_filter(
					$record['issues'],
					static function ( array $issue ) use ( $kind ): bool {
						return '' === $kind || $issue['kind'] === $kind;
					}
				)
			);
			$type   = get_post_type_object( $post->post_type );
			$status = get_post_status_object( $post->post_status );
			$rows[] = array(
				'id'           => (int) $post->ID,
				'title'        => '' !== $post->post_title ? (string) $post->post_title : (string) $post->post_name,
				'type'         => (string) $post->post_type,
				'type_label'   => $type ? (string) $type->labels->singular_name : (string) $post->post_type,
				'status'       => (string) $post->post_status,
				'status_label' => $status && is_string( $status->label ) ? $status->label : (string) $post->post_status,
				'edit_link'    => (string) get_edit_post_link( $post->ID, 'raw' ),
				'scanned'      => (int) $record['time'],
				'stale'        => md5( $post->post_content ) !== $record['hash'],
				'issues'       => $issues,
			);
		}

		return $rows;
	}

	/**
	 * Cleans issues sent by a scan, dropping anything malformed.
	 *
	 * @param array  $issues Issues.
	 * @param string $source Where they come from.
	 * @param array  $kinds  Known kinds.
	 * @return array[]
	 */
	private function clean( array $issues, string $source, array $kinds ): array {
		$clean = array();
		foreach ( $issues as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			$kind = sanitize_key( (string) ( $issue['kind'] ?? '' ) );
			if ( ! isset( $kinds[ $kind ] ) ) {
				continue;
			}
			$path = array_slice( array_map( 'absint', array_values( (array) ( $issue['path'] ?? array() ) ) ), 0, 64 );

			$clean[] = array(
				'kind'    => $kind,
				'path'    => $path ? $path : array( 0 ),
				'name'    => self::block_name( (string) ( $issue['name'] ?? '' ) ),
				'message' => mb_substr( sanitize_text_field( (string) ( $issue['message'] ?? '' ) ), 0, 500 ),
				'detail'  => mb_substr( sanitize_text_field( (string) ( $issue['detail'] ?? '' ) ), 0, 500 ),
				'excerpt' => mb_substr( sanitize_text_field( (string) ( $issue['excerpt'] ?? '' ) ), 0, 100 ),
				'source'  => 'browser' === $source ? 'browser' : sanitize_key( $source ),
			);
		}

		return $clean;
	}

	/**
	 * A block name with anything unexpected removed. Empty for issues about the whole post.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function block_name( string $name ): string {
		return substr( (string) preg_replace( '#[^a-z0-9_/-]#', '', strtolower( $name ) ), 0, 100 );
	}

	/**
	 * Saves or deletes a record and keeps the index in step.
	 *
	 * @param int        $post_id   Post.
	 * @param string     $post_type Post type.
	 * @param array|null $record    Record, or null to delete.
	 */
	private function put( int $post_id, string $post_type, ?array $record ): void {
		$index = $this->index();

		if ( null === $record ) {
			delete_post_meta( $post_id, self::META );
			if ( ! isset( $index[ $post_id ] ) ) {
				return;
			}
			unset( $index[ $post_id ] );
		} else {
			update_post_meta( $post_id, self::META, $record );
			$kinds = array();
			foreach ( $record['issues'] as $issue ) {
				$kinds[ $issue['kind'] ] = ( $kinds[ $issue['kind'] ] ?? 0 ) + 1;
			}
			$index[ $post_id ] = array(
				'type'  => $post_type,
				'kinds' => $kinds,
			);
		}

		$this->write( $index );
	}
}
