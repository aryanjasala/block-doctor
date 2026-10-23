<?php
/**
 * Saving fixes and undoing them.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Saves the repaired content the browser worked out, one post at a time, and
 * puts posts back when a batch is undone.
 *
 * The repair itself happens in the browser, because only the block editor's
 * JavaScript knows what each block saves. Here the save is checked and made:
 * the post must not have changed since the preview, nobody may have it open,
 * and the content before the fix is kept in a revision so Undo batch, or
 * the core revisions screen, can bring it back.
 */
final class Fixer {

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
	 * Fix batches.
	 *
	 * @var Batches
	 */
	private Batches $batches;

	/**
	 * Scans a post again after a fix.
	 *
	 * @var Scanner
	 */
	private Scanner $scanner;

	/**
	 * Sets up the fixer.
	 *
	 * @param Posts   $posts   Finds the posts.
	 * @param Issues  $issues  Stored results.
	 * @param Batches $batches Fix batches.
	 * @param Scanner $scanner Scans a post again after a fix.
	 */
	public function __construct( Posts $posts, Issues $issues, Batches $batches, Scanner $scanner ) {
		$this->posts   = $posts;
		$this->issues  = $issues;
		$this->batches = $batches;
		$this->scanner = $scanner;
	}

	/**
	 * Saves a fixed version of a post.
	 *
	 * @param int    $post_id  Post.
	 * @param int    $batch_id Batch the fix belongs to.
	 * @param string $content  New content.
	 * @param string $hash     md5 of the content the fix was worked out from.
	 * @param array  $issues   Issues the browser still finds in the new content.
	 * @return array|\WP_Error `post_id`, `changed`, `revision` (holding the content before the fix, 0 when there is none), `issues` (how many are left).
	 */
	public function fix( int $post_id, int $batch_id, string $content, string $hash, array $issues = array() ) {
		$batch = $this->batches->get( $batch_id );
		if ( ! $batch || $batch['undone'] ) {
			return new \WP_Error( 'block_doctor_batch', __( 'That batch was undone or has expired. Preview the fixes again.', 'block-doctor' ), array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! $this->posts->is_scanned( $post ) ) {
			return new \WP_Error( 'block_doctor_post', __( 'Block Doctor does not scan that post.', 'block-doctor' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'block_doctor_forbidden', __( 'Sorry, you are not allowed to edit this post.', 'block-doctor' ), array( 'status' => 403 ) );
		}
		if ( md5( $post->post_content ) !== $hash ) {
			return new \WP_Error( 'block_doctor_changed', __( 'The post changed after the preview, so it was not saved. Preview it again.', 'block-doctor' ), array( 'status' => 409 ) );
		}

		$editing = $this->editing( $post->ID );
		if ( $editing ) {
			return new \WP_Error(
				'block_doctor_locked',
				/* translators: %s: user name. */
				sprintf( __( '%s has this post open in the editor, so it was not saved.', 'block-doctor' ), $editing ),
				array( 'status' => 409 )
			);
		}

		// A Custom HTML block with nothing in it is a block deleted, not repaired: dynamic blocks have no saved HTML to keep.
		if ( self::empty_html_blocks( $content ) > self::empty_html_blocks( (string) $post->post_content ) ) {
			return new \WP_Error( 'block_doctor_empty_html', __( 'The fix would leave an empty Custom HTML block where a block was, so it was not saved. Leave that block as it is.', 'block-doctor' ), array( 'status' => 422 ) );
		}

		if ( $content === $post->post_content ) {
			return array(
				'post_id'  => (int) $post->ID,
				'changed'  => false,
				'revision' => 0,
				'issues'   => count( $this->scanner->scan_post( $post, $issues, md5( $content ) ) ),
			);
		}

		$old      = (string) $post->post_content;
		$before   = $this->revision_of_current( $post );
		$previous = $this->issues->get( (int) $post->ID );

		$saved = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$after = get_post( $post->ID );
		if ( ! $after ) {
			return new \WP_Error( 'block_doctor_post', __( 'The post could not be read back after saving.', 'block-doctor' ), array( 'status' => 500 ) );
		}

		$undo = array(
			'before' => $before,
			'hash'   => md5( $after->post_content ),
			'issues' => $previous,
		);
		if ( ! $before ) {
			$undo['content'] = $old;
		}
		$this->batches->set_undo_data( (int) $post->ID, $batch_id, $undo );

		$batch = (array) $this->batches->get( $batch_id );
		if ( ! in_array( (int) $post->ID, $batch['posts'], true ) ) {
			$batch['posts'][] = (int) $post->ID;
			$this->batches->update( $batch );
		}

		$left = $this->scanner->scan_post( $after, $issues, md5( $content ) );

		/**
		 * Fires after a fixed post is saved.
		 *
		 * @param int      $post_id  Post.
		 * @param int      $batch_id Batch, for Undo batch.
		 * @param int      $revision Revision with the content before the fix, 0 when the post type keeps no revisions.
		 * @param string[] $kinds    Kinds the batch fixes.
		 */
		do_action( 'block_doctor_post_fixed', (int) $post->ID, $batch_id, $before, $batch['kinds'] );

		return array(
			'post_id'  => (int) $post->ID,
			'changed'  => true,
			'revision' => $before,
			'issues'   => count( $left ),
		);
	}

	/**
	 * Undoes a batch: every post goes back to its content before the fix, unless it was edited since.
	 *
	 * @param int $batch_id Batch.
	 * @param int $limit    Posts to handle in this call, 0 for all.
	 * @return array|\WP_Error `batch` (with `restored` and `skipped`) and `remaining` (posts still to handle).
	 */
	public function undo( int $batch_id, int $limit = 0 ) {
		$batch = $this->batches->get( $batch_id );
		if ( ! $batch ) {
			return new \WP_Error( 'block_doctor_batch', __( 'That batch does not exist, or is too old to undo. Its revisions are still there.', 'block-doctor' ), array( 'status' => 404 ) );
		}

		$todo = array_values( array_diff( array_reverse( $batch['posts'] ), $batch['restored'], array_keys( $batch['skipped'] ) ) );
		if ( $batch['undone'] || ! $todo ) {
			return $this->undone( $batch, array() );
		}

		$now  = $limit > 0 ? array_slice( $todo, 0, $limit ) : $todo;
		$rest = array_slice( $todo, count( $now ) );
		$this->issues->begin();
		try {
			foreach ( $now as $post_id ) {
				$reason = $this->restore( (int) $post_id, $batch_id );
				if ( '' === $reason ) {
					$batch['restored'][] = (int) $post_id;
				} else {
					$batch['skipped'][ (int) $post_id ] = $reason;
				}
			}
		} finally {
			$this->issues->commit();
		}

		$this->batches->update( $batch );

		return $this->undone( $batch, $rest );
	}

	/**
	 * Puts one post back.
	 *
	 * @param int $post_id  Post.
	 * @param int $batch_id Batch.
	 * @return string '' when restored, or why not: `deleted`, `forbidden`, `nothing`, `edited`, `revision`.
	 */
	private function restore( int $post_id, int $batch_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return 'deleted';
		}
		// WP-CLI runs without a user unless --user is given.
		if ( get_current_user_id() && ! current_user_can( 'edit_post', $post->ID ) ) {
			return 'forbidden';
		}
		$data = $this->batches->undo_data( $post_id, $batch_id );
		if ( ! $data ) {
			return 'nothing';
		}
		if ( md5( $post->post_content ) !== (string) $data['hash'] ) {
			return 'edited';
		}

		if ( isset( $data['content'] ) ) {
			$result = wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => wp_slash( (string) $data['content'] ),
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return 'revision';
			}
		} else {
			$revision_id = (int) $data['before'];
			if ( ! wp_get_post_revision( $revision_id ) || ! wp_restore_post_revision( $revision_id ) ) {
				return 'revision';
			}
		}

		$restored = get_post( $post->ID );
		if ( $restored ) {
			$this->issues->restore( $restored, is_array( $data['issues'] ) ? $data['issues'] : null );
		}
		$this->batches->set_undo_data( $post_id, $batch_id, null );

		return '';
	}

	/**
	 * Finishes an undo call, marking the batch undone once every post is handled.
	 *
	 * @param array $batch Batch.
	 * @param int[] $rest  Posts still to handle.
	 * @return array
	 */
	private function undone( array $batch, array $rest ): array {
		if ( ! $rest && ! $batch['undone'] ) {
			$batch['undone'] = time();
			$this->batches->update( $batch );

			/**
			 * Fires when every post of a batch has been handled by Undo batch.
			 *
			 * @param int   $batch_id Batch.
			 * @param int[] $restored Posts put back to their content before the fix.
			 * @param array $skipped  Post ID => why it was left alone: `edited` (changed since the fix), `deleted`, `forbidden`, `nothing`, `revision`.
			 */
			do_action( 'block_doctor_batch_undone', (int) $batch['id'], $batch['restored'], $batch['skipped'] );
		}

		return array(
			'batch'     => $batch,
			'remaining' => count( $rest ),
		);
	}

	/**
	 * A revision with exactly the post's current content, made now if there is none.
	 *
	 * @param \WP_Post $post Post.
	 * @return int Revision ID, or 0 when the post type keeps no revisions.
	 */
	private function revision_of_current( \WP_Post $post ): int {
		if ( ! wp_revisions_enabled( $post ) ) {
			return 0;
		}

		wp_save_post_revision( $post->ID );
		foreach ( wp_get_post_revisions( $post->ID, array( 'posts_per_page' => 5 ) ) as $revision ) {
			if ( wp_is_post_autosave( $revision ) ) {
				continue;
			}

			// core skips a revision when only whitespace changed, and Undo has to give back the exact markup.
			return $revision->post_content === $post->post_content ? (int) $revision->ID : 0;
		}

		return 0;
	}

	/**
	 * How many Custom HTML blocks in some content hold nothing.
	 *
	 * @param string $content Block markup.
	 * @return int
	 */
	public static function empty_html_blocks( string $content ): int {
		if ( false === strpos( $content, 'wp:html' ) ) {
			return 0;
		}

		$count = 0;
		$walk  = static function ( array $blocks ) use ( &$walk, &$count ): void {
			foreach ( $blocks as $block ) {
				if ( 'core/html' === $block['blockName'] && '' === trim( (string) $block['innerHTML'] ) && '' === trim( (string) ( $block['attrs']['content'] ?? '' ) ) ) {
					++$count;
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( $content ) );

		return $count;
	}

	/**
	 * Who else has a post open in the editor.
	 *
	 * @param int $post_id Post.
	 * @return string Their name, or '' when nobody does.
	 */
	private function editing( int $post_id ): string {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$user_id = wp_check_post_lock( $post_id );
		if ( ! $user_id ) {
			return '';
		}
		$user = get_userdata( (int) $user_id );

		return $user ? (string) $user->display_name : __( 'Someone', 'block-doctor' );
	}
}
