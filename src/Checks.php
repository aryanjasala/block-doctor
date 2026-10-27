<?php
/**
 * Server-side checks.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * What PHP can find in a post on its own, without the block editor:
 * unregistered block types, classic content, posts emptied since a revision
 * with blocks, and references to deleted synced patterns, navigation menus
 * and media. Whether a block's markup is valid is only known in the
 * editor's JavaScript, so that is the browser scan's job.
 */
final class Checks {

	/**
	 * Option with the block types the browser registered at the last browser scan.
	 */
	public const JS_BLOCKS = 'block_doctor_js_blocks';

	/**
	 * Blocks that point at an attachment, and the attribute that holds its ID.
	 */
	private const MEDIA = array(
		'core/image'      => 'id',
		'core/cover'      => 'id',
		'core/video'      => 'id',
		'core/audio'      => 'id',
		'core/file'       => 'id',
		'core/media-text' => 'mediaId',
	);

	/**
	 * Defaults, for the date format of messages.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Sets up the checks.
	 *
	 * @param Config|null $config Defaults.
	 */
	public function __construct( ?Config $config = null ) {
		$this->config = $config ?? new Config();
	}

	/**
	 * Every check, by name.
	 *
	 * @return array<string, callable> Each takes ( WP_Post $post, array $blocks ) and returns a list of issues.
	 */
	public function all(): array {
		$checks = array(
			'missing'        => array( $this, 'check_missing' ),
			'classic'        => array( $this, 'check_classic' ),
			'empty'          => array( $this, 'check_empty' ),
			'pattern-ref'    => array( $this, 'check_patterns' ),
			'navigation-ref' => array( $this, 'check_navigation' ),
			'missing-media'  => array( $this, 'check_media' ),
		);

		/**
		 * Filters the server-side checks. Add your own, or remove one.
		 *
		 * A check is a callable that gets the post and its blocks from parse_blocks(), and returns a list of issues,
		 * each with `kind`, `path` (block position from 0, one number per level), `name` and `message`.
		 * A new kind also needs an entry in `block_doctor_issue_kinds`, or its issues are dropped.
		 *
		 * @param array<string, callable> $checks Checks by name.
		 */
		$filtered = (array) apply_filters( 'block_doctor_server_checks', $checks );

		return array_filter( $filtered, 'is_callable' );
	}

	/**
	 * Runs every check on a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array[] Issues.
	 */
	public function run( \WP_Post $post ): array {
		$blocks = parse_blocks( $post->post_content );
		$issues = array();

		foreach ( $this->all() as $name => $check ) {
			try {
				$found = call_user_func( $check, $post, $blocks );
			} catch ( \Throwable $error ) {
				// Say so, instead of storing the post as clean.
				$issues[] = array(
					'kind'    => 'error',
					'path'    => array( 0 ),
					'name'    => '',
					/* translators: %s: name of the check, such as missing-media. */
					'message' => sprintf( __( 'The %s check stopped with an error, so this post was not fully checked.', 'block-doctor' ), (string) $name ),
					'detail'  => $error->getMessage(),
				);
				continue;
			}
			foreach ( (array) $found as $issue ) {
				if ( is_array( $issue ) ) {
					$issues[] = $issue;
				}
			}
		}

		return $issues;
	}

	/**
	 * Block types that are registered neither in PHP nor, at the last browser scan, in JavaScript.
	 *
	 * @param \WP_Post $post   Post.
	 * @param array    $blocks Parsed blocks.
	 * @return array[]
	 */
	public function check_missing( \WP_Post $post, array $blocks ): array {
		$registry = \WP_Block_Type_Registry::get_instance();
		$browser  = array_flip( (array) get_option( self::JS_BLOCKS, array() ) );
		$issues   = array();

		self::walk(
			$blocks,
			static function ( array $block, array $path ) use ( $registry, $browser, &$issues ): bool {
				$name = (string) $block['blockName'];
				if ( '' === $name || $registry->is_registered( $name ) || isset( $browser[ $name ] ) ) {
					return true;
				}
				$issues[] = array(
					'kind'    => 'missing',
					'path'    => $path,
					'name'    => $name,
					/* translators: %s: block name, such as acme/widget. */
					'message' => sprintf( __( 'Block type %s is not registered. Its plugin may be inactive or deleted.', 'block-doctor' ), $name ),
					'excerpt' => self::excerpt( (string) $block['innerHTML'] ),
				);
				// The editor keeps an unregistered block whole, with whatever is inside it.
				return false;
			}
		);

		return $issues;
	}

	/**
	 * Content outside blocks: a whole classic post, or HTML between blocks.
	 *
	 * @param \WP_Post $post   Post.
	 * @param array    $blocks Parsed blocks.
	 * @return array[]
	 */
	public function check_classic( \WP_Post $post, array $blocks ): array {
		$issues   = array();
		$position = 0;
		$named    = count( array_filter( wp_list_pluck( $blocks, 'blockName' ) ) );

		foreach ( $blocks as $block ) {
			$html = trim( (string) $block['innerHTML'] );
			if ( null !== $block['blockName'] || '' !== $html ) {
				if ( null === $block['blockName'] ) {
					$issues[] = array(
						'kind'    => 'classic',
						'path'    => array( $position ),
						'name'    => 'core/freeform',
						'message' => $named
							/* translators: %s: start of the text. */
							? sprintf( __( 'Classic content between blocks: "%s"', 'block-doctor' ), self::excerpt( $html ) )
							: __( 'The whole post is classic content, with no blocks.', 'block-doctor' ),
					);
				}
				++$position;
			}
		}

		return $issues;
	}

	/**
	 * A post that is empty now, while a recent revision had blocks.
	 *
	 * @param \WP_Post $post   Post.
	 * @param array    $blocks Parsed blocks.
	 * @return array[]
	 */
	public function check_empty( \WP_Post $post, array $blocks ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Every check gets the same arguments.
		if ( '' !== trim( $post->post_content ) || ! post_type_supports( $post->post_type, 'revisions' ) ) {
			return array();
		}

		foreach ( wp_get_post_revisions( $post->ID, array( 'posts_per_page' => 5 ) ) as $revision ) {
			if ( ! has_blocks( $revision->post_content ) ) {
				continue;
			}
			$count = count( array_filter( wp_list_pluck( parse_blocks( $revision->post_content ), 'blockName' ) ) );

			return array(
				array(
					'kind'    => 'empty',
					'path'    => array( 0 ),
					'name'    => '',
					'message' => sprintf(
						/* translators: 1: date, 2: number of blocks. */
						_n( 'The post is empty, but the revision from %1$s had %2$s block.', 'The post is empty, but the revision from %1$s had %2$s blocks.', $count, 'block-doctor' ),
						mysql2date( $this->config->date_format(), $revision->post_date ),
						number_format_i18n( $count )
					),
				),
			);
		}

		return array();
	}

	/**
	 * Synced pattern blocks whose pattern is gone.
	 *
	 * @param \WP_Post $post   Post.
	 * @param array    $blocks Parsed blocks.
	 * @return array[]
	 */
	public function check_patterns( \WP_Post $post, array $blocks ): array {
		return $this->references(
			$blocks,
			'core/block',
			'wp_block',
			'pattern-ref',
			/* translators: %d: post ID of the pattern. */
			__( 'The synced pattern #%d this block shows was deleted, so it renders nothing.', 'block-doctor' )
		);
	}

	/**
	 * Navigation blocks whose menu is gone.
	 *
	 * @param \WP_Post $post   Post.
	 * @param array    $blocks Parsed blocks.
	 * @return array[]
	 */
	public function check_navigation( \WP_Post $post, array $blocks ): array {
		return $this->references(
			$blocks,
			'core/navigation',
			'wp_navigation',
			'navigation-ref',
			/* translators: %d: post ID of the menu. */
			__( 'The navigation menu #%d this block shows was deleted.', 'block-doctor' )
		);
	}

	/**
	 * Media blocks whose attachment is gone.
	 *
	 * @param \WP_Post $post   Post.
	 * @param array    $blocks Parsed blocks.
	 * @return array[]
	 */
	public function check_media( \WP_Post $post, array $blocks ): array {
		$found = array();
		self::walk(
			$blocks,
			static function ( array $block, array $path ) use ( &$found ): bool {
				$name = (string) $block['blockName'];
				if ( isset( self::MEDIA[ $name ] ) ) {
					$id = (int) ( $block['attrs'][ self::MEDIA[ $name ] ] ?? 0 );
					if ( $id > 0 ) {
						$found[] = array( $id, $path, $name, self::excerpt( (string) $block['innerHTML'] ) );
					}
				}
				return true;
			}
		);

		if ( $found && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_unique( array_column( $found, 0 ) ), false, false );
		}

		$issues = array();
		foreach ( $found as list( $id, $path, $name, $excerpt ) ) {
			$attachment = get_post( $id );
			if ( $attachment && 'attachment' === $attachment->post_type && 'trash' !== $attachment->post_status ) {
				continue;
			}
			$issues[] = array(
				'kind'    => 'missing-media',
				'path'    => $path,
				'name'    => $name,
				'excerpt' => $excerpt,
				/* translators: %d: attachment ID. */
				'message' => sprintf( __( 'Attachment #%d was deleted from the Media Library. The block may still show a copy of the file, or a broken one.', 'block-doctor' ), $id ),
			);
		}

		return $issues;
	}

	/**
	 * Blocks of one type whose `ref` points at a post that is missing, of another type, or in the trash.
	 *
	 * @param array  $blocks    Parsed blocks.
	 * @param string $name      Block name.
	 * @param string $post_type Post type the reference must be.
	 * @param string $kind      Issue kind.
	 * @param string $message   Message with %d for the ID.
	 * @return array[]
	 */
	private function references( array $blocks, string $name, string $post_type, string $kind, string $message ): array {
		$issues = array();
		self::walk(
			$blocks,
			static function ( array $block, array $path ) use ( $name, $post_type, $kind, $message, &$issues ): bool {
				if ( $name !== $block['blockName'] ) {
					return true;
				}
				$ref = (int) ( $block['attrs']['ref'] ?? 0 );
				if ( $ref <= 0 ) {
					return true;
				}
				$target = get_post( $ref );
				if ( ! $target || $post_type !== $target->post_type || 'trash' === $target->post_status ) {
					$issues[] = array(
						'kind'    => $kind,
						'path'    => $path,
						'name'    => $name,
						'message' => sprintf( $message, $ref ),
					);
				}
				return true;
			}
		);

		return $issues;
	}

	/**
	 * Visits every block with its path. Paths count real blocks only: blank lines between blocks are skipped.
	 *
	 * @param array    $blocks Parsed blocks.
	 * @param callable $visit  ( array $block, int[] $path ) => bool, false to skip the inner blocks.
	 * @param int[]    $prefix Path of the parent.
	 */
	public static function walk( array $blocks, callable $visit, array $prefix = array() ): void {
		$position = 0;
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] && '' === trim( (string) $block['innerHTML'] ) ) {
				continue;
			}
			$path = array_merge( $prefix, array( $position++ ) );
			if ( null !== $block['blockName'] && $visit( $block, $path ) && ! empty( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $visit, $path );
			}
		}
	}

	/**
	 * The start of some HTML as plain text.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function excerpt( string $html ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $html ) ) );

		return mb_strlen( $text ) > 60 ? mb_substr( $text, 0, 60 ) . '...' : $text;
	}
}
