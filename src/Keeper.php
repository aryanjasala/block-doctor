<?php
/**
 * Keeping what a plugin's dynamic blocks show, before the plugin goes.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor;

use AryanX\Axle\BlockDoctor\Admin\Page;

defined( 'ABSPATH' ) || exit;

/**
 * A dynamic block has no saved HTML: the plugin's code makes its output every
 * time the page loads. Switch the plugin off and the block shows nothing.
 *
 * While the plugin is still on, this renders each of its dynamic blocks in the
 * context of its post and saves the output as a Custom HTML block, through the
 * fixer (a revision first, one batch with Undo batch). Blocks whose output
 * depends on the visitor are refused and listed. Every block it replaced is
 * kept word for word in post meta, so "Put the blocks back" restores the exact
 * original markup, also in a post that was edited since.
 */
final class Keeper implements Service {

	/**
	 * Option with one run per provider key: progress, cumulative counts, batches and the refused blocks.
	 */
	public const RUNS = 'block_doctor_keep';

	/**
	 * Post meta with the original markup of every block kept in the post: marker => markup.
	 */
	public const META = '_block_doctor_kept';

	/**
	 * Name of the admin-post action behind "Put the blocks back" in the notice.
	 */
	public const PUT_BACK = 'block_doctor_put_back';

	/**
	 * Most refused posts a run remembers.
	 */
	private const REFUSED_LIMIT = 200;

	/**
	 * Parts of a block name (split at `/`, `-` and `_`) that mean the output belongs to one visitor or one action.
	 */
	private const DENY = array( 'form', 'forms', 'cart', 'minicart', 'checkout', 'login', 'logout', 'register', 'signup', 'signin', 'account', 'order', 'orders', 'payment', 'pay', 'subscribe', 'newsletter', 'password', 'wishlist', 'profile', 'comment', 'comments', 'rsvp', 'booking' );

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
	 * Fix batches.
	 *
	 * @var Batches
	 */
	private Batches $batches;

	/**
	 * Saves content with a revision first.
	 *
	 * @var Fixer
	 */
	private Fixer $fixer;

	/**
	 * Sets up the keeper.
	 *
	 * @param Config    $config    Defaults and limits.
	 * @param Posts     $posts     Finds the posts.
	 * @param Providers $providers Where each block type comes from.
	 * @param Batches   $batches   Fix batches.
	 * @param Fixer     $fixer     Saves content with a revision first.
	 */
	public function __construct( Config $config, Posts $posts, Providers $providers, Batches $batches, Fixer $fixer ) {
		$this->config    = $config;
		$this->posts     = $posts;
		$this->providers = $providers;
		$this->batches   = $batches;
		$this->fixer     = $fixer;
	}

	/**
	 * Hooks the notice and its button.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_post_' . self::PUT_BACK, array( $this, 'handle_put_back' ) );
	}

	/**
	 * The block names of a plugin that this can keep: registered now, and dynamic.
	 *
	 * @param string $key Provider key, such as `plugin:acme/acme.php`.
	 * @return string[]
	 */
	public function dynamic_blocks( string $key ): array {
		if ( 0 !== strpos( $key, 'plugin:' ) ) {
			return array();
		}
		$registry = \WP_Block_Type_Registry::get_instance();
		$names    = array();
		foreach ( $this->providers->blocks_of( $key ) as $name ) {
			$type = $registry->get_registered( (string) $name );
			if ( $type && $type->is_dynamic() ) {
				$names[] = (string) $name;
			}
		}

		return $names;
	}

	/**
	 * What the Deactivate step says about a plugin's dynamic blocks.
	 *
	 * @param string        $key  Provider key.
	 * @param string[]|null $used Block names in use, to count only those. Null counts every dynamic block.
	 * @return array{blocks: int, posts: int, can_keep: bool} Dynamic block types in use, posts that use them, and whether this can keep them now.
	 */
	public function summary( string $key, ?array $used = null ): array {
		$names = $this->dynamic_blocks( $key );
		if ( null !== $used ) {
			// Only block types that posts use count for the sentence.
			$names = array_values( array_intersect( $names, $used ) );
		}
		$posts = $names ? $this->post_ids( $names, 0, 0 ) : array();

		return array(
			'blocks'   => count( $names ),
			'posts'    => count( $posts ),
			'can_keep' => $names && $posts && $this->plugin_is_active( $key ),
		);
	}

	/**
	 * Why a block is refused, from what it rendered. Pure: no WordPress needed.
	 *
	 * @param string $name  Block name.
	 * @param string $guest What a visitor who is not logged in gets.
	 * @param string $again A second render as that visitor.
	 * @param string $user  What a logged-in user gets, '' when there was no user to try.
	 * @param bool   $check Whether to compare with the logged-in render.
	 * @return string '' to keep it, or `empty`, `unstable`, `visitor`, `form`, `nonce`, `interactive`, `delimiter`, `name`.
	 */
	public static function classify( string $name, string $guest, string $again, string $user, bool $check = true ): string {
		$parts = preg_split( '#[/_-]#', strtolower( $name ) );
		if ( array_intersect( (array) $parts, self::deny_list() ) ) {
			return 'name';
		}
		if ( '' === trim( $guest ) ) {
			return 'empty';
		}
		if ( false !== strpos( $guest, '<!-- wp:' ) || false !== strpos( $guest, '<!-- /wp:' ) ) {
			return 'delimiter';
		}
		if ( $guest !== $again ) {
			return 'unstable';
		}
		if ( $check && $guest !== $user ) {
			return 'visitor';
		}
		if ( preg_match( '/<form[\s>]|<input[\s>]|<select[\s>]|<textarea[\s>]/i', $guest ) ) {
			return 'form';
		}
		if ( preg_match( '/nonce|_wpnonce|wp_rest/i', $guest ) ) {
			return 'nonce';
		}
		if ( false !== stripos( $guest, 'data-wp-interactive' ) ) {
			return 'interactive';
		}

		return '';
	}

	/**
	 * The name parts that always refuse a block.
	 *
	 * @return string[]
	 */
	private static function deny_list(): array {
		/**
		 * Filters the parts of a block name that mean its output depends on the visitor or on an action, so it is never kept as HTML.
		 *
		 * A block name is split at `/`, `-` and `_`: `acme/contact-form` has the parts `acme`, `contact` and `form`.
		 * Blocks that pass are still rendered twice, as a visitor and as a user, and refused when the output differs.
		 *
		 * @param string[] $parts Lower case name parts. Default forms, carts, checkout, login, account, orders, payments, subscriptions and comments.
		 */
		$parts = (array) apply_filters( 'block_doctor_keep_denylist', self::DENY );

		return array_values( array_filter( array_map( 'strval', $parts ) ) );
	}

	/**
	 * Text for a refusal reason.
	 *
	 * @param string $reason Code from classify() or the save.
	 * @return string
	 */
	public static function reason_text( string $reason ): string {
		$texts = array(
			'name'        => __( 'Forms, carts, accounts and similar blocks show different things to different visitors.', 'block-doctor' ),
			'empty'       => __( 'It shows nothing right now, so there is nothing to keep.', 'block-doctor' ),
			'delimiter'   => __( 'Its output holds block comments that would break the saved post.', 'block-doctor' ),
			'unstable'    => __( 'Its output changes every time it renders, so a snapshot would go stale at once.', 'block-doctor' ),
			'visitor'     => __( 'Logged-in users see something else than visitors do.', 'block-doctor' ),
			'form'        => __( 'Its output has a form, which needs the plugin to work.', 'block-doctor' ),
			'nonce'       => __( 'Its output has a security token that would expire.', 'block-doctor' ),
			'interactive' => __( 'It needs the plugin\'s script to work, so the HTML alone would be dead.', 'block-doctor' ),
			'kses'        => __( 'WordPress would strip parts of its output when you save, because your account may not post unfiltered HTML.', 'block-doctor' ),
			'filter'      => __( 'A filter on this site refused it.', 'block-doctor' ),
			'locked'      => __( 'Someone has the post open in the editor.', 'block-doctor' ),
			'changed'     => __( 'The post changed while it was being kept.', 'block-doctor' ),
			'failed'      => __( 'The post could not be saved.', 'block-doctor' ),
		);

		return $texts[ $reason ] ?? $reason;
	}

	/**
	 * Where the outermost blocks of some names sit in a markup string. Pure.
	 *
	 * Uses the delimiter pattern of core's block parser. Blocks inside a match are part of it.
	 *
	 * @param string   $content Post content.
	 * @param string[] $names   Block names.
	 * @return array<int, array{0: int, 1: int, 2: string}> Start offset, end offset (exclusive) and block name, in document order. Empty when the block comments do not nest properly.
	 */
	public static function spans( string $content, array $names ): array {
		if ( false === strpos( $content, '<!-- wp:' ) ) {
			return array();
		}
		$pattern = '/<!--\s+(?P<closer>\/)?wp:(?P<ns>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';
		if ( ! preg_match_all( $pattern, $content, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$wanted = array_flip( $names );
		$stack  = array();
		$spans  = array();
		$inside = 0;
		foreach ( $found as $token ) {
			$name  = ( '' === $token['ns'][0] ? 'core/' : $token['ns'][0] ) . $token['name'][0];
			$start = (int) $token['0'][1];
			$end   = $start + strlen( $token['0'][0] );
			$void  = '/' === substr( (string) ( $token['void'][0] ?? '' ), 0, 1 );

			if ( '/' === $token['closer'][0] ) {
				$top = array_pop( $stack );
				if ( ! $top || $top[0] !== $name ) {
					return array();
				}
				if ( isset( $wanted[ $name ] ) ) {
					--$inside;
					if ( $top[2] ) {
						$spans[] = array( $top[1], $end, $name );
					}
				}
				continue;
			}

			$target = isset( $wanted[ $name ] );
			if ( $void ) {
				if ( $target && 0 === $inside ) {
					$spans[] = array( $start, $end, $name );
				}
				continue;
			}
			$outer = $target && 0 === $inside;
			if ( $target ) {
				++$inside;
			}
			$stack[] = array( $name, $start, $outer );
		}

		return $stack ? array() : $spans;
	}

	/**
	 * The Custom HTML block that holds a block's output, with a marker so it can be put back.
	 *
	 * @param string $marker Marker, such as `bdk3-1`.
	 * @param string $html   What the block rendered.
	 * @return string
	 */
	public static function html_block( string $marker, string $html ): string {
		return "<!-- wp:html -->\n<!--block-doctor-kept:{$marker}-->\n{$html}\n<!-- /wp:html -->";
	}

	/**
	 * Puts the original markup back in place of the Custom HTML blocks Block Doctor made. Pure.
	 *
	 * @param string                $content   Post content.
	 * @param array<string, string> $originals Marker => original markup.
	 * @return array{content: string, restored: string[]} The new content and the markers that were found.
	 */
	public static function restore_markup( string $content, array $originals ): array {
		$restored = array();
		if ( false === strpos( $content, 'block-doctor-kept:' ) ) {
			return array(
				'content'  => $content,
				'restored' => $restored,
			);
		}
		$content = (string) preg_replace_callback(
			'#<!-- wp:html -->\s*<!--block-doctor-kept:([a-z0-9-]+)-->.*?<!-- /wp:html -->#s',
			static function ( array $found ) use ( $originals, &$restored ): string {
				if ( ! isset( $originals[ $found[1] ] ) ) {
					return $found[0];
				}
				$restored[] = $found[1];

				return $originals[ $found[1] ];
			},
			$content
		);

		return array(
			'content'  => $content,
			'restored' => $restored,
		);
	}

	/**
	 * Runs the next chunk of a keep run for one plugin.
	 *
	 * @param string $key     Provider key, `plugin:acme/acme.php`.
	 * @param bool   $restart Start a new run even when one is going.
	 * @return array|\WP_Error The run: `status`, `posts_total`, `done`, `kept` (blocks), `kept_posts`, `refused`, `skipped`, `batch`.
	 */
	public function step( string $key, bool $restart = false ) {
		if ( 0 !== strpos( $key, 'plugin:' ) ) {
			return new \WP_Error( 'block_doctor_keep_key', __( 'Blocks can only be kept for a plugin.', 'block-doctor' ), array( 'status' => 400 ) );
		}
		if ( ! $this->plugin_is_active( $key ) ) {
			return new \WP_Error( 'block_doctor_keep_inactive', __( 'The plugin is off, so its blocks cannot be rendered. Turn it on first.', 'block-doctor' ), array( 'status' => 409 ) );
		}
		$names = $this->dynamic_blocks( $key );
		if ( ! $names ) {
			return new \WP_Error( 'block_doctor_keep_none', __( 'That plugin has no dynamic blocks to keep.', 'block-doctor' ), array( 'status' => 404 ) );
		}

		$runs = $this->runs();
		$run  = $runs[ $key ] ?? null;
		if ( ! $run || $restart || 'running' !== $run['status'] ) {
			$run = $this->fresh( $key, $run, count( $this->post_ids( $names, 0, 0 ) ) );
			/**
			 * Fires when a keep run starts.
			 *
			 * @param string $key Provider key.
			 * @param array  $run The run.
			 */
			do_action( 'block_doctor_keep_started', $key, $run );
		}

		$ids = $this->post_ids( $names, (int) $run['after'], $this->config->batch_size() );
		foreach ( $ids as $post_id ) {
			$run['after'] = $post_id;
			$this->keep_post( $post_id, $names, $run );
		}
		if ( count( $ids ) < $this->config->batch_size() ) {
			$run['status'] = 'done';
		}
		$run['done'] = (int) $run['done'] + count( $ids );

		$runs[ $key ] = $run;
		update_option( self::RUNS, $runs, false );

		if ( 'done' === $run['status'] ) {
			/**
			 * Fires when a keep run has been through every post.
			 *
			 * @param string $key Provider key.
			 * @param array  $run The run: kept, kept_posts, refused, skipped, batches.
			 */
			do_action( 'block_doctor_keep_finished', $key, $run );
		}

		return $this->report( $run );
	}

	/**
	 * Puts the blocks back for one plugin, a chunk of posts at a time.
	 *
	 * @param string $key   Provider key.
	 * @param int    $limit Posts to handle, 0 for all.
	 * @return array{restored: int, skipped: array<int, string>, remaining: int}|\WP_Error
	 */
	public function put_back( string $key, int $limit = 0 ) {
		$runs = $this->runs();
		$run  = $runs[ $key ] ?? null;
		if ( ! $run || empty( $run['batches'] ) ) {
			return new \WP_Error( 'block_doctor_keep_nothing', __( 'Block Doctor has not kept any blocks for that plugin.', 'block-doctor' ), array( 'status' => 404 ) );
		}

		$todo = $this->kept_posts( $run );
		$now  = $limit > 0 ? array_slice( $todo, 0, $limit, true ) : $todo;
		$rest = array_slice( $todo, count( $now ), null, true );

		$restored = 0;
		$skipped  = array();
		foreach ( $now as $post_id => $batch_id ) {
			$originals = get_post_meta( (int) $post_id, self::META, true );
			$reason    = $this->fixer->put_back( (int) $post_id, is_array( $originals ) ? $originals : array(), (int) $batch_id );
			if ( '' === $reason ) {
				++$restored;
				/**
				 * Fires when a post got its original blocks back.
				 *
				 * @param int    $post_id  Post.
				 * @param int    $batch_id The keep batch.
				 * @param string $key      Provider key.
				 */
				do_action( 'block_doctor_blocks_put_back', (int) $post_id, (int) $batch_id, $key );
			} else {
				$skipped[ (int) $post_id ] = $reason;
				// Nothing left to put back in this post, or it is gone: stop waiting for it.
				if ( in_array( $reason, array( 'nothing', 'deleted' ), true ) ) {
					delete_post_meta( (int) $post_id, self::META );
				}
			}
		}

		$remaining = count( $rest );
		if ( ! $remaining ) {
			$this->finish_put_back( $key, $run );
		}

		return array(
			'restored'  => $restored,
			'skipped'   => $skipped,
			'remaining' => $remaining,
		);
	}

	/**
	 * What a run looks like to the screen, the CLI and the abilities, for the current user.
	 *
	 * @param array $run A run.
	 * @return array
	 */
	public function report( array $run ): array {
		$refused = array();
		foreach ( $run['refused'] as $post_id => $blocks ) {
			$post = get_post( (int) $post_id );
			// Titles only for posts this person may edit.
			if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$refused[] = array(
				'id'        => (int) $post->ID,
				'title'     => '' !== (string) $post->post_title ? (string) $post->post_title : (string) $post->post_name,
				'edit_link' => (string) get_edit_post_link( $post->ID, 'raw' ),
				'blocks'    => array_map(
					static function ( array $block ): array {
						return array(
							'name'    => (string) $block['name'],
							'reason'  => (string) $block['reason'],
							'message' => self::reason_text( (string) $block['reason'] ),
						);
					},
					$blocks
				),
			);
		}

		return array(
			'status'       => (string) $run['status'],
			'total'        => (int) $run['total'],
			'done'         => min( (int) $run['done'], (int) $run['total'] ),
			'kept'         => (int) $run['last']['blocks'],
			'kept_posts'   => (int) $run['last']['posts'],
			'refused'      => $refused,
			'refused_n'    => (int) $run['refused_n'],
			'not_yours'    => (int) $run['not_yours'],
			'batch'        => (int) $run['batch'],
			'kept_total'   => (int) $run['kept']['blocks'],
			'can_put_back' => ! empty( $run['batches'] ),
		);
	}

	/**
	 * The stored run of a plugin, or null.
	 *
	 * @param string $key Provider key.
	 * @return array|null
	 */
	public function run( string $key ): ?array {
		return $this->runs()[ $key ] ?? null;
	}

	/**
	 * Every run, cleaned.
	 *
	 * @return array<string, array>
	 */
	public function runs(): array {
		$stored = get_option( self::RUNS, array() );
		$runs   = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $run ) {
			if ( is_array( $run ) ) {
				$runs[ (string) $key ] = $this->clean( $run );
			}
		}

		return $runs;
	}

	/**
	 * Fills in the fields of a stored run.
	 *
	 * @param array $run Stored run.
	 * @return array
	 */
	private function clean( array $run ): array {
		$last = isset( $run['last'] ) && is_array( $run['last'] ) ? $run['last'] : array();
		$kept = isset( $run['kept'] ) && is_array( $run['kept'] ) ? $run['kept'] : array();

		return array(
			'name'      => (string) ( $run['name'] ?? '' ),
			'status'    => in_array( $run['status'] ?? '', array( 'running', 'done' ), true ) ? (string) $run['status'] : 'done',
			'started'   => (int) ( $run['started'] ?? 0 ),
			'after'     => (int) ( $run['after'] ?? 0 ),
			'total'     => (int) ( $run['total'] ?? 0 ),
			'done'      => (int) ( $run['done'] ?? 0 ),
			'batch'     => (int) ( $run['batch'] ?? 0 ),
			'batches'   => array_values( array_map( 'intval', (array) ( $run['batches'] ?? array() ) ) ),
			'last'      => array(
				'blocks' => (int) ( $last['blocks'] ?? 0 ),
				'posts'  => (int) ( $last['posts'] ?? 0 ),
			),
			'kept'      => array(
				'blocks' => (int) ( $kept['blocks'] ?? 0 ),
				'posts'  => (int) ( $kept['posts'] ?? 0 ),
			),
			'refused'   => isset( $run['refused'] ) && is_array( $run['refused'] ) ? $run['refused'] : array(),
			'refused_n' => (int) ( $run['refused_n'] ?? 0 ),
			'not_yours' => (int) ( $run['not_yours'] ?? 0 ),
		);
	}

	/**
	 * A new run. What earlier runs kept is carried over, so it can all be put back.
	 *
	 * @param string     $key   Provider key.
	 * @param array|null $old   The run it follows.
	 * @param int        $total Posts to go through.
	 * @return array
	 */
	private function fresh( string $key, ?array $old, int $total ): array {
		$name = '';
		foreach ( $this->providers->groups() as $group ) {
			if ( 'plugin:' . $group['provider']['basename'] === $key ) {
				$name = (string) $group['provider']['name'];
			}
		}

		return array(
			'name'      => $name,
			'status'    => 'running',
			'started'   => time(),
			'after'     => 0,
			'total'     => $total,
			'done'      => 0,
			'batch'     => 0,
			'batches'   => $old ? $old['batches'] : array(),
			'last'      => array(
				'blocks' => 0,
				'posts'  => 0,
			),
			'kept'      => $old ? $old['kept'] : array(
				'blocks' => 0,
				'posts'  => 0,
			),
			'refused'   => array(),
			'refused_n' => 0,
			'not_yours' => 0,
		);
	}

	/**
	 * Keeps the blocks of one post.
	 *
	 * @param int      $post_id Post.
	 * @param string[] $names   Dynamic block names of the plugin.
	 * @param array    $run     The run, changed in place.
	 */
	private function keep_post( int $post_id, array $names, array &$run ): void {
		$post = get_post( $post_id );
		if ( ! $post || ! $this->posts->is_scanned( $post ) ) {
			return;
		}
		// Never touch a post this person cannot edit, and say nothing about it but a count.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			++$run['not_yours'];
			return;
		}

		$content = (string) $post->post_content;
		$spans   = self::spans( $content, $names );
		if ( ! $spans ) {
			return;
		}

		$unfiltered = current_user_can( 'unfiltered_html' );
		$keep       = array();
		$refused    = array();
		foreach ( $spans as $span ) {
			$markup = substr( $content, $span[0], $span[1] - $span[0] );
			$result = $this->render_for_keep( $span[2], $markup, $post );
			if ( '' === $result['reason'] && ! $unfiltered && wp_kses_post( $result['html'] ) !== $result['html'] ) {
				$result['reason'] = 'kses';
			}
			if ( '' !== $result['reason'] ) {
				$refused[] = array(
					'name'   => $span[2],
					'reason' => $result['reason'],
				);
				continue;
			}
			$keep[] = array( $span, $markup, $result['html'] );
		}

		if ( $refused ) {
			$this->remember_refused( $run, $post_id, $refused );
		}
		if ( ! $keep ) {
			return;
		}

		if ( ! $run['batch'] ) {
			$batch        = $this->batches->create( array( 'keep' ) );
			$run['batch'] = (int) $batch['id'];
		}

		// From the end, so earlier offsets stay right. Each original is kept word for word under its marker.
		$next      = $content;
		$originals = array();
		$kept      = array();
		foreach ( array_reverse( array_keys( $keep ) ) as $index ) {
			list( $span, $markup, $html ) = $keep[ $index ];
			$marker                       = 'bdk' . $run['batch'] . '-' . $post->ID . '-' . ( $index + 1 );
			$originals[ $marker ]         = $markup;
			$kept[]                       = $span[2];
			$next                         = substr_replace( $next, self::html_block( $marker, $html ), $span[0], $span[1] - $span[0] );
		}

		$saved = $this->fixer->fix( (int) $post->ID, (int) $run['batch'], $next, md5( $content ) );
		if ( is_wp_error( $saved ) ) {
			$reason = str_replace( 'block_doctor_', '', (string) $saved->get_error_code() );
			$this->remember_refused(
				$run,
				$post_id,
				array(
					array(
						'name'   => '',
						'reason' => in_array( $reason, array( 'locked', 'changed' ), true ) ? $reason : 'failed',
					),
				)
			);
			return;
		}

		$stored = get_post_meta( (int) $post->ID, self::META, true );
		update_post_meta( (int) $post->ID, self::META, array_merge( is_array( $stored ) ? $stored : array(), $originals ) );
		if ( ! in_array( (int) $run['batch'], $run['batches'], true ) ) {
			$run['batches'][] = (int) $run['batch'];
		}
		$run['last']['blocks'] += count( $kept );
		++$run['last']['posts'];
		$run['kept']['blocks'] += count( $kept );
		++$run['kept']['posts'];

		/**
		 * Fires when the dynamic blocks of a post were saved as Custom HTML.
		 *
		 * @param int      $post_id Post.
		 * @param int      $batch   Batch, for Undo batch.
		 * @param string[] $kept    Names of the blocks kept, one per block.
		 */
		do_action( 'block_doctor_blocks_kept', (int) $post->ID, (int) $run['batch'], array_reverse( $kept ) );
	}

	/**
	 * Renders one block as a visitor, twice, and as a user, in its post, and says whether it can be kept.
	 *
	 * @param string   $name   Block name.
	 * @param string   $markup The block's markup from the post.
	 * @param \WP_Post $post   Post it sits in.
	 * @return array{html: string, reason: string}
	 */
	private function render_for_keep( string $name, string $markup, \WP_Post $post ): array {
		if ( array_intersect( (array) preg_split( '#[/_-]#', strtolower( $name ) ), self::deny_list() ) ) {
			return array(
				'html'   => '',
				'reason' => 'name',
			);
		}

		$parsed = array_values(
			array_filter(
				parse_blocks( $markup ),
				static function ( array $block ): bool {
					return null !== $block['blockName'];
				}
			)
		);
		if ( 1 !== count( $parsed ) || $parsed[0]['blockName'] !== $name ) {
			return array(
				'html'   => '',
				'reason' => 'failed',
			);
		}

		$current = (int) get_current_user_id();
		$guest   = $this->render( $parsed[0], $post, 0 );
		$again   = $this->render( $parsed[0], $post, 0 );
		// A user to compare with: the one running this, or on the command line the first administrator.
		$other = $current ? $current : $this->any_user();
		$user  = $other ? $this->render( $parsed[0], $post, $other ) : '';

		$reason = self::classify( $name, $guest, $again, $user, (bool) $other );

		/**
		 * Filters whether a dynamic block may be kept as HTML. Return a reason to refuse it, or '' to allow it.
		 *
		 * A block that renders the same for a visitor and a logged-in user can still depend on something else, such as a cookie or a query string. Refuse it here.
		 *
		 * @param string   $reason Why it is refused, '' when it passed. One of `empty`, `unstable`, `visitor`, `form`, `nonce`, `interactive`, `delimiter`, `name`.
		 * @param string   $name   Block name.
		 * @param string   $html   What it renders for a visitor.
		 * @param \WP_Post $post   The post it sits in.
		 */
		$reason = (string) apply_filters( 'block_doctor_keep_refuse', $reason, $name, $guest, $post );

		return array(
			'html'   => '' === $reason ? trim( $guest ) : '',
			'reason' => $reason,
		);
	}

	/**
	 * Renders a block in a post's context as one user.
	 *
	 * @param array    $block   Parsed block.
	 * @param \WP_Post $in_post Post.
	 * @param int      $user_id User, 0 for a visitor.
	 * @return string
	 */
	private function render( array $block, \WP_Post $in_post, int $user_id ): string {
		$previous_user = (int) get_current_user_id();
		$previous_post = $GLOBALS['post'] ?? null;
		wp_set_current_user( $user_id );
		$GLOBALS['post'] = $in_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The block renders in its post's context, restored below.
		setup_postdata( $in_post );
		try {
			$html = (string) render_block( $block );
		} catch ( \Throwable $error ) {
			$html = '';
		} finally {
			$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			if ( $previous_post instanceof \WP_Post ) {
				setup_postdata( $previous_post );
			} else {
				wp_reset_postdata();
			}
			wp_set_current_user( $previous_user );
		}

		return $html;
	}

	/**
	 * Any user to compare a visitor's render with, for runs without a user.
	 *
	 * @return int
	 */
	private function any_user(): int {
		$users = get_users(
			array(
				'number'   => 1,
				'fields'   => 'ID',
				'role__in' => array( 'administrator', 'editor' ),
				'orderby'  => 'ID',
			)
		);

		return $users ? (int) $users[0] : 0;
	}

	/**
	 * Notes refused blocks of a post in a run.
	 *
	 * @param array $run     The run, changed in place.
	 * @param int   $post_id Post.
	 * @param array $blocks  Each with `name` and `reason`.
	 */
	private function remember_refused( array &$run, int $post_id, array $blocks ): void {
		$run['refused_n'] += count( $blocks );
		if ( count( $run['refused'] ) < self::REFUSED_LIMIT ) {
			$run['refused'][ $post_id ] = array_merge( $run['refused'][ $post_id ] ?? array(), $blocks );
		}
	}

	/**
	 * Posts that use any of some blocks, by ID after a cursor, from the usage index.
	 *
	 * @param string[] $names Block names.
	 * @param int      $after Cursor: posts with a higher ID.
	 * @param int      $limit Most posts, 0 for all.
	 * @return int[]
	 */
	private function post_ids( array $names, int $after, int $limit ): array {
		global $wpdb;

		$keys = array_values( array_unique( array_map( array( Usage::class, 'meta_key' ), $names ) ) );
		if ( ! $keys ) {
			return array();
		}
		$in     = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$params = array_merge( $keys, array( $after, $limit > 0 ? $limit : 1000000 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list is made of placeholders only; the cursor changes with every call.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$in}) AND post_id > %d ORDER BY post_id ASC LIMIT %d", $params ) ) );
	}

	/**
	 * Posts that still hold kept blocks of a run: post ID => batch.
	 *
	 * @param array $run The run.
	 * @return array<int, int>
	 */
	private function kept_posts( array $run ): array {
		$todo = array();
		foreach ( $run['batches'] as $batch_id ) {
			$batch = $this->batches->get( (int) $batch_id );
			foreach ( $batch ? $batch['posts'] : array() as $post_id ) {
				$originals = get_post_meta( (int) $post_id, self::META, true );
				if ( is_array( $originals ) && $originals ) {
					$todo[ (int) $post_id ] = (int) $batch_id;
				}
			}
		}

		return $todo;
	}

	/**
	 * After the last post was put back: the batches are done and the run is forgotten.
	 *
	 * @param string $key Provider key.
	 * @param array  $run The run.
	 */
	private function finish_put_back( string $key, array $run ): void {
		foreach ( $run['batches'] as $batch_id ) {
			$batch = $this->batches->get( (int) $batch_id );
			if ( $batch && ! $batch['undone'] ) {
				$batch['undone'] = time();
				$this->batches->update( $batch );
			}
		}
		if ( $this->kept_posts( $run ) ) {
			// Some posts could not be put back now (locked, no permission): keep waiting for those.
			return;
		}
		$runs = $this->runs();
		unset( $runs[ $key ] );
		if ( $runs ) {
			update_option( self::RUNS, $runs, false );
		} else {
			delete_option( self::RUNS );
		}
	}

	/**
	 * Whether a plugin is on, here or for the whole network.
	 *
	 * @param string $key Provider key.
	 * @return bool
	 */
	private function plugin_is_active( string $key ): bool {
		$basename = substr( $key, strlen( 'plugin:' ) );
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return '' !== $basename && is_plugin_active( $basename );
	}

	/**
	 * Plugins that are on while Block Doctor still holds blocks it kept for them.
	 *
	 * @return array<string, array> Provider key => run.
	 */
	public function waiting(): array {
		$waiting = array();
		foreach ( $this->runs() as $key => $run ) {
			if ( ! empty( $run['batches'] ) && $this->plugin_is_active( (string) $key ) && $this->kept_posts( $run ) ) {
				$waiting[ (string) $key ] = $run;
			}
		}

		return $waiting;
	}

	/**
	 * The notice after blocks were kept: their plugin is on, so they can be put back.
	 */
	public function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'tools_page_block-doctor' ), true ) || ! $this->config->can_use() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Shows a result, changes nothing.
		if ( isset( $_GET['block_doctor_put_back'] ) ) {
			$restored = absint( wp_unslash( $_GET['block_doctor_put_back'] ) );
			$left     = isset( $_GET['block_doctor_left'] ) ? absint( wp_unslash( $_GET['block_doctor_left'] ) ) : 0;
			// phpcs:enable
			printf(
				'<div class="notice notice-success is-dismissible block-doctor-put-back"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: number of posts put back, 2: number of posts left. */
						_n( 'Put the blocks back in %1$s post. %2$s are still waiting.', 'Put the blocks back in %1$s posts. %2$s are still waiting.', $restored, 'block-doctor' ),
						number_format_i18n( $restored ),
						number_format_i18n( $left )
					)
				)
			);
		}

		foreach ( $this->waiting() as $key => $run ) {
			$url  = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => self::PUT_BACK,
						'provider' => rawurlencode( (string) $key ),
					),
					admin_url( 'admin-post.php' )
				),
				self::PUT_BACK . $key
			);
			$text = sprintf(
				/* translators: 1: plugin name, 2: number of blocks. */
				_n( 'Block Doctor kept %2$s block of %1$s as HTML. The plugin is on, so those blocks are frozen as they looked then.', 'Block Doctor kept %2$s blocks of %1$s as HTML. The plugin is on, so those blocks are frozen as they looked then.', $run['kept']['blocks'], 'block-doctor' ),
				'' !== $run['name'] ? $run['name'] : (string) $key,
				number_format_i18n( $run['kept']['blocks'] )
			);

			/**
			 * Filters the sentence in the notice that offers to put kept blocks back.
			 *
			 * @param string $text Sentence.
			 * @param array  $run  The run: kept, batches, refused.
			 * @param string $key  Provider key.
			 */
			$text = (string) apply_filters( 'block_doctor_kept_notice', $text, $run, (string) $key );

			printf(
				'<div class="notice notice-info block-doctor-kept"><p>%1$s</p><p><a class="button" href="%2$s">%3$s</a></p></div>',
				esc_html( $text ),
				esc_url( $url ),
				esc_html__( 'Put the blocks back', 'block-doctor' )
			);
		}
	}

	/**
	 * The notice's button: puts the blocks back and returns to the screen.
	 */
	public function handle_put_back(): void {
		if ( ! $this->config->can_use() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to use Block Doctor.', 'block-doctor' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below, once the key is known.
		$key = isset( $_GET['provider'] ) ? sanitize_text_field( wp_unslash( $_GET['provider'] ) ) : '';
		check_admin_referer( self::PUT_BACK . $key );

		$restored = 0;
		$left     = 0;
		$deadline = time() + $this->config->cron_time_limit();
		do {
			$result = $this->put_back( $key, $this->config->batch_size() );
			if ( is_wp_error( $result ) ) {
				break;
			}
			$restored += $result['restored'];
			$left      = $result['remaining'];
		} while ( $left > 0 && time() < $deadline );

		wp_safe_redirect(
			add_query_arg(
				array(
					'block_doctor_put_back' => $restored,
					'block_doctor_left'     => $left,
				),
				false !== strpos( (string) wp_get_referer(), 'plugins.php' ) ? admin_url( 'plugins.php' ) : Page::url()
			)
		);
		exit;
	}
}
