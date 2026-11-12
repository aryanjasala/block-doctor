<?php
/**
 * The block usage index: which block types each post uses, and how often.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one post meta row per post and block type, `_block_doctor_use_<name>`,
 * holding how many times the post uses the block. The meta key is indexed, so
 * counting a block type or listing the posts that use it is one indexed query,
 * and there is no growing option to keep in step.
 *
 * The index covers the same content the scans cover: the post types and
 * statuses from Config, so synced patterns, templates and template parts too.
 * It is built in batches in ID order and can stop and carry on from the last
 * ID. After that, `save_post` and deletions keep it current.
 */
final class Usage implements Service {

	public const PREFIX = '_block_doctor_use_';

	public const STATE = 'block_doctor_usage_scan';

	public const CRON = 'block_doctor_usage_index';

	private const CACHE = 'block_doctor';

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
	 * Where each block type comes from.
	 *
	 * @var Providers
	 */
	private Providers $providers;

	/**
	 * Sets up the index.
	 *
	 * @param Config    $config    Defaults and limits.
	 * @param Posts     $posts     Finds the posts.
	 * @param Providers $providers Where each block type comes from.
	 */
	public function __construct( Config $config, Posts $posts, Providers $providers ) {
		$this->config    = $config;
		$this->posts     = $posts;
		$this->providers = $providers;
	}

	/**
	 * Keeps the index current, and builds it in the background the first time someone opens the Dashboard, Plugins, Themes or Block Doctor screen.
	 */
	public function register(): void {
		add_action( 'save_post', array( $this, 'on_save' ), 20, 2 );
		add_action( 'deleted_post', array( $this, 'forget' ) );
		add_action( 'trashed_post', array( $this, 'forget' ) );
		add_action( self::CRON, array( $this, 'run_cron' ) );
		// Only the screens that need the numbers: one option read there, none on every admin page.
		foreach ( array( 'load-index.php', 'load-plugins.php', 'load-themes.php', 'load-tools_page_block-doctor' ) as $hook ) {
			add_action( $hook, array( $this, 'maybe_schedule' ) );
		}
	}

	/**
	 * The meta key for a block type.
	 *
	 * @param string $name Block name.
	 * @return string
	 */
	public static function meta_key( string $name ): string {
		return self::PREFIX . Issues::block_name( $name );
	}

	/**
	 * The block name inside a meta key, or '' when it is not one of ours.
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	public static function name_from_key( string $key ): string {
		return 0 === strpos( $key, self::PREFIX ) ? substr( $key, strlen( self::PREFIX ) ) : '';
	}

	/**
	 * State of the index build.
	 *
	 * @return array{status: string, started: int, finished: int, cursor: int, done: int, total: int}
	 */
	public function state(): array {
		$state = get_option( self::STATE, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'status'   => in_array( $state['status'] ?? '', array( 'running', 'done' ), true ) ? (string) $state['status'] : 'idle',
			'started'  => (int) ( $state['started'] ?? 0 ),
			'finished' => (int) ( $state['finished'] ?? 0 ),
			'cursor'   => (int) ( $state['cursor'] ?? 0 ),
			'done'     => (int) ( $state['done'] ?? 0 ),
			'total'    => (int) ( $state['total'] ?? 0 ),
		);
	}

	/**
	 * Whether the index was built once, so its numbers can be trusted.
	 *
	 * @return bool
	 */
	public function ready(): bool {
		return $this->state()['finished'] > 0;
	}

	/**
	 * Starts building the index from the first post.
	 *
	 * @return array State.
	 */
	public function start(): array {
		wp_clear_scheduled_hook( self::CRON );
		$state = array(
			'status'   => 'running',
			'started'  => time(),
			// A rebuild keeps the numbers from the last build, which stay valid: posts are indexed as they are saved.
			'finished' => $this->state()['finished'],
			'cursor'   => 0,
			'done'     => 0,
			'total'    => $this->posts->total(),
		);
		update_option( self::STATE, $state, false );

		/**
		 * Fires when the block usage index starts building.
		 *
		 * @param array $state status, started, finished, cursor, done, total.
		 */
		do_action( 'block_doctor_usage_index_started', $state );

		return $this->state();
	}

	/**
	 * Indexes the next batch of posts, starting a build when none is running.
	 *
	 * @return array State.
	 */
	public function step(): array {
		$state = $this->state();
		if ( 'running' !== $state['status'] ) {
			$state = $this->start();
		}

		$limit = $this->config->batch_size();
		$posts = $this->posts->after( $state['cursor'], $limit );
		foreach ( $posts as $post ) {
			$this->index_post( $post );
			$state['cursor'] = (int) $post->ID;
			++$state['done'];
		}

		if ( count( $posts ) < $limit ) {
			$state['status']   = 'done';
			$state['finished'] = time();
			update_option( self::STATE, $state, false );
			$this->providers->remember();

			/**
			 * Fires when the block usage index has covered every post.
			 *
			 * @param array $state status, started, finished, cursor, done, total.
			 */
			do_action( 'block_doctor_usage_index_finished', $state );
		} else {
			update_option( self::STATE, $state, false );
		}
		$this->touch();

		return $this->state();
	}

	/**
	 * WP-Cron: indexes batches until the time limit, then schedules the next run.
	 */
	public function run_cron(): void {
		$until = microtime( true ) + $this->config->cron_time_limit();
		do {
			$state = $this->step();
		} while ( 'running' === $state['status'] && microtime( true ) < $until );

		if ( 'running' === $state['status'] ) {
			wp_schedule_single_event( time(), self::CRON );
		}
	}

	/**
	 * Schedules the first build in the background, once, when nobody has built the index yet.
	 */
	public function maybe_schedule(): void {
		if ( ! $this->config->usage_auto_index() || ! $this->config->can_use() ) {
			return;
		}
		$state = $this->state();
		if ( 'idle' === $state['status'] && ! $state['finished'] && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time(), self::CRON );
		}
	}

	/**
	 * Keeps the index in step when a post is saved.
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save( $post_id, $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
		if ( ! $post || 'revision' === $post->post_type || wp_is_post_autosave( $post ) ) {
			return;
		}
		$this->index_post( $post );
		$this->touch();
	}

	/**
	 * Drops a post from the index when it is deleted or trashed.
	 *
	 * @param int $post_id Post.
	 */
	public function forget( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $this->stored( $post_id ) ) {
			foreach ( array_keys( $this->stored( $post_id ) ) as $key ) {
				delete_post_meta( $post_id, $key );
			}
			$this->touch();
		}
	}

	/**
	 * Counts every block in a post, inner blocks included: block name => uses.
	 *
	 * @param string $content Post content.
	 * @return array<string, int>
	 */
	public static function count_blocks( string $content ): array {
		if ( false === strpos( $content, '<!-- wp:' ) ) {
			return array();
		}

		$counts = array();
		$walk   = static function ( array $blocks ) use ( &$walk, &$counts ): void {
			foreach ( $blocks as $block ) {
				if ( ! empty( $block['blockName'] ) ) {
					$name = Issues::block_name( (string) $block['blockName'] );
					if ( '' !== $name ) {
						$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
					}
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( $content ) );

		return $counts;
	}

	/**
	 * Writes a post's usage, and removes what it no longer uses. A post Block Doctor does not scan is removed.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, int> Block name => uses now stored.
	 */
	public function index_post( \WP_Post $post ): array {
		$id      = (int) $post->ID;
		$stored  = $this->stored( $id );
		$scanned = $this->posts->is_scanned( $post );
		if ( ! $scanned && ! $stored ) {
			return array();
		}
		$counts = $scanned ? self::count_blocks( (string) $post->post_content ) : array();
		$keep   = array();

		foreach ( $counts as $name => $uses ) {
			$key          = self::meta_key( $name );
			$keep[ $key ] = true;
			if ( ( $stored[ $key ] ?? null ) !== $uses ) {
				update_post_meta( $id, $key, $uses );
			}
		}
		foreach ( array_keys( $stored ) as $key ) {
			if ( ! isset( $keep[ $key ] ) ) {
				delete_post_meta( $id, $key );
			}
		}

		/**
		 * Fires after a post's block usage was written to the index.
		 *
		 * @param int                $post_id Post.
		 * @param array<string, int> $counts  Block name => how many times the post uses it. Empty when the post is not scanned or has no blocks.
		 */
		do_action( 'block_doctor_post_indexed', $id, $counts );

		return $counts;
	}

	/**
	 * Deletes the whole index and its progress.
	 *
	 * @return int Rows removed.
	 */
	public function clear(): int {
		global $wpdb;

		wp_clear_scheduled_hook( self::CRON );
		delete_option( self::STATE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One delete of our own meta keys, by their indexed prefix.
		$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) );
		$this->touch();

		return (int) $removed;
	}

	/**
	 * Post types the current user may see usage for: those where they may edit other people's posts.
	 *
	 * @return string[]
	 */
	public function visible_types(): array {
		$types = array();
		foreach ( $this->config->post_types() as $type ) {
			$object = get_post_type_object( $type );
			$cap    = $object && isset( $object->cap->edit_others_posts ) ? (string) $object->cap->edit_others_posts : 'edit_others_posts';
			if ( current_user_can( $cap ) ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * Block types with how many posts and uses each has, merged with the registered ones and their providers.
	 *
	 * @param array $args `types` (post types to count; leave it out for every scanned type, an empty list counts nothing), `search`, `filter`
	 *                    (core, plugin, theme, unregistered or unused), `provider` (a provider key).
	 * @return array[] Rows: name, title, provider, registered, posts, uses, last. Most used first.
	 */
	public function blocks( array $args = array() ): array {
		$counts   = $this->counts( isset( $args['types'] ) ? (array) $args['types'] : null );
		$registry = \WP_Block_Type_Registry::get_instance();
		$browser  = array_flip( array_map( 'strval', (array) get_option( Checks::JS_BLOCKS, array() ) ) );
		$names    = array_unique( array_merge( array_keys( $counts ), array_map( 'strval', array_keys( $registry->get_all_registered() ) ) ) );
		$search   = strtolower( trim( (string) ( $args['search'] ?? '' ) ) );
		$filter   = (string) ( $args['filter'] ?? '' );
		$wanted   = (string) ( $args['provider'] ?? '' );
		$rows     = array();

		foreach ( $names as $name ) {
			$name       = (string) $name;
			$type       = $registry->get_registered( $name );
			$title      = $type && ! empty( $type->title ) ? (string) $type->title : '';
			$registered = (bool) $type || isset( $browser[ $name ] );
			$posts      = (int) ( $counts[ $name ]['posts'] ?? 0 );
			$provider   = $this->providers->for_block( $name );

			if ( '' !== $search && false === strpos( strtolower( $name . ' ' . $title . ' ' . $provider['name'] ), $search ) ) {
				continue;
			}
			if ( '' !== $wanted && Providers::key( $provider ) !== $wanted ) {
				continue;
			}
			if ( ! self::matches( $filter, $provider['type'], $registered, $posts ) ) {
				continue;
			}

			$rows[] = array(
				'name'       => $name,
				'title'      => $title,
				'provider'   => $provider,
				'registered' => $registered,
				'posts'      => $posts,
				'uses'       => (int) ( $counts[ $name ]['uses'] ?? 0 ),
				'last'       => (int) ( $counts[ $name ]['last'] ?? 0 ),
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( $b['posts'], $b['uses'], $a['name'] ) <=> array( $a['posts'], $a['uses'], $b['name'] );
			}
		);

		return $rows;
	}

	/**
	 * Whether a block type passes a filter.
	 *
	 * @param string $filter     `core`, `plugin`, `theme`, `unregistered`, `unused` or ''.
	 * @param string $type       Provider type.
	 * @param bool   $registered Whether the block type is registered in PHP or JavaScript.
	 * @param int    $posts      Posts using it.
	 * @return bool
	 */
	private static function matches( string $filter, string $type, bool $registered, int $posts ): bool {
		switch ( $filter ) {
			case 'core':
				return 'core' === $type;
			case 'plugin':
				return in_array( $type, array( 'plugin', 'mu-plugin' ), true );
			case 'theme':
				return 'theme' === $type;
			case 'unregistered':
				return ! $registered && $posts > 0;
			case 'unused':
				return $registered && 0 === $posts;
			default:
				// Every block type that is used or registered.
				return true;
		}
	}

	/**
	 * Posts that use one or more block types, most uses first.
	 *
	 * @param string[]      $blocks   Block names.
	 * @param string[]|null $types Post types to include, null for every scanned type. An empty list includes none.
	 * @param int           $page     Page from 1.
	 * @param int           $per_page Posts per page, 0 for all.
	 * @return array{items: array<int, array{id: int, uses: int}>, total: int}
	 */
	public function posts_using( array $blocks, ?array $types = null, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$keys  = array_values( array_unique( array_map( array( self::class, 'meta_key' ), $blocks ) ) );
		$types = $this->scoped( $types );
		if ( ! $keys || ! $types ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$cache_key = 'posts:' . md5( wp_json_encode( array( $keys, $types, $page, $per_page, $this->config->post_statuses() ) ) ) . ':' . $this->generation();
		$cached    = wp_cache_get( $cache_key, self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$statuses = $this->config->post_statuses();
		$where    = sprintf(
			'm.meta_key IN (%1$s) AND p.post_type IN (%2$s) AND p.post_status IN (%3$s)',
			implode( ',', array_fill( 0, count( $keys ), '%s' ) ),
			implode( ',', array_fill( 0, count( $types ), '%s' ) ),
			implode( ',', array_fill( 0, count( $statuses ), '%s' ) )
		);
		$params   = array_merge( $keys, $types, $statuses );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN lists are built from placeholders only, and the result is cached below.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE {$where}", $params ) );

		$select = "SELECT m.post_id AS id, SUM(CAST(m.meta_value AS UNSIGNED)) AS uses FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE {$where} GROUP BY m.post_id ORDER BY uses DESC, m.post_id ASC";
		if ( $per_page > 0 ) {
			$params[] = $per_page;
			$params[] = ( max( 1, $page ) - 1 ) * $per_page;
			$rows     = (array) $wpdb->get_results( $wpdb->prepare( "{$select} LIMIT %d OFFSET %d", $params ), ARRAY_A );
		} else {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $select, $params ), ARRAY_A );
		}
		// phpcs:enable

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'id'   => (int) $row['id'],
				'uses' => (int) $row['uses'],
			);
		}

		$result = array(
			'items' => $items,
			'total' => $total,
		);
		wp_cache_set( $cache_key, $result, self::CACHE, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Where one block type sits in a post: each path from 0, one number per level, like the scan's paths.
	 *
	 * @param \WP_Post $post  Post.
	 * @param string   $block Block name.
	 * @param int      $limit Most paths to return.
	 * @return array<int, int[]>
	 */
	public function positions( \WP_Post $post, string $block, int $limit ): array {
		$paths = array();
		Checks::walk(
			parse_blocks( (string) $post->post_content ),
			static function ( array $found, array $path ) use ( $block, $limit, &$paths ): bool {
				if ( $block === $found['blockName'] && count( $paths ) < $limit ) {
					$paths[] = $path;
				}
				return true;
			}
		);

		return $paths;
	}

	/**
	 * Block usage as rows: block name => posts, uses and the newest post change, from the index.
	 *
	 * @param string[]|null $types Post types to count, null for every scanned type.
	 * @return array<string, array{posts: int, uses: int, last: int}>
	 */
	private function counts( ?array $types ): array {
		global $wpdb;

		$types    = $this->scoped( $types );
		$statuses = $this->config->post_statuses();
		if ( ! $types || ! $statuses ) {
			return array();
		}

		$cache_key = 'blocks:' . md5( wp_json_encode( array( $types, $statuses ) ) ) . ':' . $this->generation();
		$cached    = wp_cache_get( $cache_key, self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$params = array_merge( array( $wpdb->esc_like( self::PREFIX ) . '%' ), $types, $statuses );
		$in_t   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$in_s   = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN lists are built from placeholders only, and the result is cached below.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.meta_key AS meta_key, COUNT(DISTINCT m.post_id) AS posts, SUM(CAST(m.meta_value AS UNSIGNED)) AS uses, MAX(p.post_modified_gmt) AS last FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key LIKE %s AND p.post_type IN ({$in_t}) AND p.post_status IN ({$in_s}) GROUP BY m.meta_key",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable

		$counts = array();
		foreach ( $rows as $row ) {
			$name = self::name_from_key( (string) $row['meta_key'] );
			if ( '' === $name ) {
				continue;
			}
			$counts[ $name ] = array(
				'posts' => (int) $row['posts'],
				'uses'  => (int) $row['uses'],
				'last'  => (int) strtotime( (string) $row['last'] . ' UTC' ),
			);
		}

		wp_cache_set( $cache_key, $counts, self::CACHE, HOUR_IN_SECONDS );

		return $counts;
	}

	/**
	 * Post types to count: the scanned ones, narrowed to a list when one is given.
	 *
	 * @param string[]|null $types Wanted types, or null for every scanned type.
	 * @return string[]
	 */
	private function scoped( ?array $types ): array {
		$scanned = $this->config->post_types();

		return null === $types ? $scanned : array_values( array_intersect( $scanned, $types ) );
	}

	/**
	 * The usage meta a post has now: meta key => uses.
	 *
	 * @param int $post_id Post.
	 * @return array<string, int>
	 */
	private function stored( int $post_id ): array {
		$stored = array();
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( 0 === strpos( (string) $key, self::PREFIX ) ) {
				$stored[ (string) $key ] = (int) ( is_array( $values ) ? reset( $values ) : $values );
			}
		}

		return $stored;
	}

	/**
	 * A value that changes whenever the index does, so cached counts are never served stale.
	 *
	 * @return string
	 */
	private function generation(): string {
		$generation = wp_cache_get( 'generation', self::CACHE );
		if ( ! is_string( $generation ) || '' === $generation ) {
			$generation = (string) microtime( true );
			wp_cache_set( 'generation', $generation, self::CACHE );
		}

		return $generation;
	}

	/**
	 * Invalidates cached counts.
	 */
	private function touch(): void {
		wp_cache_set( 'generation', (string) microtime( true ), self::CACHE );
	}
}
