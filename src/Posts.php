<?php
/**
 * Finding the posts to scan.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the posts Block Doctor scans in ID order, a batch at a time, so a
 * scan can stop anywhere and carry on from the last ID it saw.
 */
final class Posts {

	/**
	 * Defaults and limits.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Sets up the walker.
	 *
	 * @param Config $config Defaults and limits.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * The next posts after an ID.
	 *
	 * @param int    $after     Last post ID already scanned.
	 * @param int    $limit     How many.
	 * @param string $post_type One post type, or '' for every scanned type.
	 * @return \WP_Post[]
	 */
	public function after( int $after, int $limit, string $post_type = '' ): array {
		$types = $this->types( $post_type );
		if ( ! $types ) {
			return array();
		}

		add_filter( 'posts_where', array( $this, 'where_after' ), 10, 2 );
		$query = new \WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => $this->config->post_statuses(),
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => max( 1, $limit ),
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
				'block_doctor_after'     => $after,
			)
		);
		remove_filter( 'posts_where', array( $this, 'where_after' ), 10 );

		return array_values( array_filter( $query->posts, static fn( $post ): bool => $post instanceof \WP_Post ) );
	}

	/**
	 * Adds `ID > after` to the query that asked for it.
	 *
	 * @param string $where WHERE clause.
	 * @param mixed  $query Query.
	 * @return string
	 */
	public function where_after( $where, $query ) {
		$after = $query instanceof \WP_Query ? (int) $query->get( 'block_doctor_after' ) : 0;
		if ( $after > 0 ) {
			global $wpdb;
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
		}

		return $where;
	}

	/**
	 * Specific posts, in the order asked, when Block Doctor scans their type and status.
	 *
	 * @param int[] $ids Post IDs.
	 * @return \WP_Post[]
	 */
	public function get( array $ids ): array {
		$posts = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$post = get_post( $id );
			if ( $post && $this->is_scanned( $post ) ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	/**
	 * Whether Block Doctor looks at this post.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public function is_scanned( \WP_Post $post ): bool {
		return in_array( $post->post_type, $this->config->post_types(), true ) && in_array( $post->post_status, $this->config->post_statuses(), true );
	}

	/**
	 * How many posts a full scan covers, from core's cached counts.
	 *
	 * @param string $post_type One post type, or '' for every scanned type.
	 * @return int
	 */
	public function total( string $post_type = '' ): int {
		$total = 0;
		foreach ( $this->types( $post_type ) as $type ) {
			$counts = (array) wp_count_posts( $type );
			foreach ( $this->config->post_statuses() as $status ) {
				$total += (int) ( $counts[ $status ] ?? 0 );
			}
		}

		return $total;
	}

	/**
	 * The post as a scan sees it.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public static function payload( \WP_Post $post ): array {
		return array(
			'id'      => (int) $post->ID,
			'type'    => (string) $post->post_type,
			'status'  => (string) $post->post_status,
			'title'   => '' !== $post->post_title ? (string) $post->post_title : (string) $post->post_name,
			'hash'    => md5( $post->post_content ),
			'content' => (string) $post->post_content,
		);
	}

	/**
	 * Scanned types, or one of them.
	 *
	 * @param string $post_type One post type, or ''.
	 * @return string[]
	 */
	private function types( string $post_type ): array {
		$types = $this->config->post_types();
		if ( '' === $post_type ) {
			return $types;
		}

		return in_array( $post_type, $types, true ) ? array( $post_type ) : array();
	}
}
