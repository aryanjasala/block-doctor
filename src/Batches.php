<?php
/**
 * Fix batches.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Every run of fixes is a batch with a number, so the whole run can be undone.
 *
 * The option holds the batch list: `id`, `time`, `kinds`, `posts` (IDs, in
 * the order they were fixed), `undone` (time, or 0), `restored` and `skipped`
 * (post ID => reason). What each post needs for its undo lives in that post's
 * `_block_doctor_undo` meta. Batches older than the undo window are dropped.
 */
final class Batches {

	public const OPTION = 'block_doctor_batches';

	public const UNDO_META = '_block_doctor_undo';

	/**
	 * Defaults and limits.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Sets up the store.
	 *
	 * @param Config $config Defaults and limits.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Every batch still inside the undo window that fixed at least one post, newest first.
	 *
	 * @return array<int, array>
	 */
	public function all(): array {
		$data = $this->data();
		krsort( $data['items'] );

		// A batch that saved nothing, say because every save was refused, has nothing to undo.
		return array_filter(
			$data['items'],
			static function ( array $batch ): bool {
				return ! empty( $batch['posts'] );
			}
		);
	}

	/**
	 * One batch.
	 *
	 * @param int $id Batch ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		return $this->data()['items'][ $id ] ?? null;
	}

	/**
	 * Starts a batch.
	 *
	 * @param string[] $kinds Kinds the run fixes.
	 * @return array The batch.
	 */
	public function create( array $kinds ): array {
		$data  = $this->data();
		$id    = (int) $data['next'];
		$batch = array(
			'id'       => $id,
			'time'     => time(),
			'kinds'    => array_values( array_unique( array_map( 'sanitize_key', array_map( 'strval', $kinds ) ) ) ),
			'posts'    => array(),
			'undone'   => 0,
			'restored' => array(),
			'skipped'  => array(),
		);

		$data['next']         = $id + 1;
		$data['items'][ $id ] = $batch;
		$this->write( $data );

		return $batch;
	}

	/**
	 * Saves a changed batch.
	 *
	 * @param array $batch Batch.
	 */
	public function update( array $batch ): void {
		$data = $this->data();
		if ( isset( $data['items'][ (int) $batch['id'] ] ) ) {
			$data['items'][ (int) $batch['id'] ] = $batch;
			$this->write( $data );
		}
	}

	/**
	 * Forgets every batch. Their revisions stay.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
		delete_post_meta_by_key( self::UNDO_META );
	}

	/**
	 * What a post needs to undo a batch.
	 *
	 * @param int $post_id  Post.
	 * @param int $batch_id Batch.
	 * @return array|null `before` (revision ID or 0), `content` (only when no revision holds it), `hash` (after the fix), `issues` (record before the fix).
	 */
	public function undo_data( int $post_id, int $batch_id ): ?array {
		$all = get_post_meta( $post_id, self::UNDO_META, true );

		return is_array( $all ) && isset( $all[ $batch_id ] ) && is_array( $all[ $batch_id ] ) ? $all[ $batch_id ] : null;
	}

	/**
	 * Saves what a post needs to undo a batch, and drops what it kept for batches that are gone.
	 *
	 * @param int        $post_id  Post.
	 * @param int        $batch_id Batch.
	 * @param array|null $data     Undo data, or null to delete it.
	 */
	public function set_undo_data( int $post_id, int $batch_id, ?array $data ): void {
		$all   = get_post_meta( $post_id, self::UNDO_META, true );
		$all   = is_array( $all ) ? $all : array();
		$items = $this->data()['items'];
		foreach ( array_keys( $all ) as $id ) {
			if ( ! isset( $items[ $id ] ) ) {
				unset( $all[ $id ] );
			}
		}
		if ( null === $data ) {
			unset( $all[ $batch_id ] );
		} else {
			$all[ $batch_id ] = $data;
		}

		if ( $all ) {
			update_post_meta( $post_id, self::UNDO_META, $all );
		} else {
			delete_post_meta( $post_id, self::UNDO_META );
		}
	}

	/**
	 * The option, without batches past the undo window.
	 *
	 * @return array{next: int, items: array<int, array>}
	 */
	private function data(): array {
		$data  = get_option( self::OPTION, array() );
		$data  = is_array( $data ) ? $data : array();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$since = time() - $this->config->undo_days() * DAY_IN_SECONDS;

		foreach ( $items as $id => $batch ) {
			if ( ! is_array( $batch ) || (int) ( $batch['time'] ?? 0 ) < $since ) {
				unset( $items[ $id ] );
			}
		}

		return array(
			'next'  => max( 1, (int) ( $data['next'] ?? 1 ) ),
			'items' => $items,
		);
	}

	/**
	 * Saves the option.
	 *
	 * @param array $data Option value.
	 */
	private function write( array $data ): void {
		update_option( self::OPTION, $data, false );
	}
}
