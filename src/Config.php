<?php
/**
 * Defaults and limits, each behind a filter.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Who may use Block Doctor, what it scans, and how much it does at once.
 *
 * Every value goes through a `block_doctor_*` filter and is checked after it,
 * so a bad filter falls back to the default instead of breaking a scan.
 */
final class Config {

	/**
	 * Post types that never hold block markup, even when they say they support the editor.
	 */
	private const NEVER = array( 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_font_family', 'wp_font_face' );

	/**
	 * Capability for the screen, the REST routes, the abilities and the dashboard widget.
	 *
	 * @return string
	 */
	public function capability(): string {
		/**
		 * Filters who may scan and fix blocks. Every fix and undo also checks `edit_post` for the post itself.
		 *
		 * @param string $capability Default `edit_others_posts`.
		 */
		return self::text( apply_filters( 'block_doctor_capability', 'edit_others_posts' ), 'edit_others_posts' );
	}

	/**
	 * A filtered value as a non-empty string, or the default.
	 *
	 * @param mixed  $value    Filtered value.
	 * @param string $fallback Default.
	 * @return string
	 */
	private static function text( $value, string $fallback ): string {
		return is_string( $value ) && '' !== $value ? $value : $fallback;
	}

	/**
	 * Whether the current user may use Block Doctor.
	 *
	 * @return bool
	 */
	public function can_use(): bool {
		return current_user_can( $this->capability() );
	}

	/**
	 * Post types to scan: REST-enabled with the editor, plus synced patterns, templates and template parts.
	 *
	 * @return string[]
	 */
	public function post_types(): array {
		$types = array();
		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $type ) {
			if ( post_type_supports( $type, 'editor' ) && ! in_array( $type, self::NEVER, true ) ) {
				$types[] = (string) $type;
			}
		}
		foreach ( array( 'wp_block', 'wp_template', 'wp_template_part' ) as $type ) {
			if ( post_type_exists( $type ) && ! in_array( $type, $types, true ) ) {
				$types[] = $type;
			}
		}

		/**
		 * Filters the post types Block Doctor scans.
		 *
		 * Types that do not exist, and types that never hold blocks (attachments, menu items, global styles, fonts), are dropped.
		 *
		 * @param string[] $types Default every REST-enabled post type with the editor, plus wp_block, wp_template and wp_template_part.
		 */
		$filtered = (array) apply_filters( 'block_doctor_post_types', $types );

		return array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $filtered ),
					static function ( string $type ): bool {
						return post_type_exists( $type ) && ! in_array( $type, self::NEVER, true );
					}
				)
			)
		);
	}

	/**
	 * Post statuses to scan.
	 *
	 * @return string[]
	 */
	public function post_statuses(): array {
		$default = array( 'publish', 'future', 'draft', 'pending', 'private' );

		/**
		 * Filters the post statuses Block Doctor scans. Statuses that are not registered are dropped.
		 *
		 * @param string[] $statuses Default publish, future, draft, pending and private.
		 */
		$filtered = (array) apply_filters( 'block_doctor_post_statuses', $default );
		$known    = get_post_stati();
		$statuses = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $filtered ),
					static function ( string $status ) use ( $known ): bool {
						return isset( $known[ $status ] ) && 'auto-draft' !== $status && 'inherit' !== $status;
					}
				)
			)
		);

		return $statuses ? $statuses : $default;
	}

	/**
	 * Posts per scan batch, per server-check batch and per undo request.
	 *
	 * @return int
	 */
	public function batch_size(): int {
		/**
		 * Filters how many posts one scan or fix request handles. The browser can lower it with the `blockDoctor.batchSize` JavaScript filter.
		 *
		 * @param int $size Default 20, from 1 to 100.
		 */
		return max( 1, min( 100, (int) apply_filters( 'block_doctor_batch_size', 20 ) ) );
	}

	/**
	 * Days a fix batch can be undone.
	 *
	 * @return int
	 */
	public function undo_days(): int {
		/**
		 * Filters how long Undo batch stays available. Older batches are forgotten; their revisions stay.
		 *
		 * @param int $days Default 30, from 1 to 365.
		 */
		return max( 1, min( 365, (int) apply_filters( 'block_doctor_undo_days', 30 ) ) );
	}

	/**
	 * Seconds one background (WP-Cron) server scan run may take before it hands over to the next run.
	 *
	 * @return int
	 */
	public function cron_time_limit(): int {
		/**
		 * Filters how long one WP-Cron run of the server-side scan keeps going.
		 *
		 * @param int $seconds Default 20, from 5 to 120.
		 */
		return max( 5, min( 120, (int) apply_filters( 'block_doctor_cron_time_limit', 20 ) ) );
	}

	/**
	 * How a block type with invalid content is repaired by default.
	 *
	 * @param string $block_name Block name, such as core/paragraph. Empty for the default of every other block.
	 * @return string `recover` (rebuild the block, like Attempt Block Recovery) or `html` (convert to a Custom HTML block).
	 */
	public function fix_strategy( string $block_name ): string {
		/**
		 * Filters the default repair for invalid blocks of one type. The screen can still change it per run.
		 *
		 * @param string $strategy   `recover` (default) or `html`.
		 * @param string $block_name Block name, or '' for blocks the server does not know.
		 */
		$strategy = apply_filters( 'block_doctor_fix_strategy', 'recover', $block_name );

		return 'html' === $strategy ? 'html' : 'recover';
	}

	/**
	 * How dates and times show on the screen, from the site's settings.
	 *
	 * @return string A PHP date format, as wp_date() takes it.
	 */
	public function date_format(): string {
		$default = trim( (string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ) );

		/**
		 * Filters the date and time format of Block Doctor's screen, its messages and the "Last scan" line.
		 *
		 * @param string $format Default the site's date format and time format from Settings > General.
		 */
		return self::text( apply_filters( 'block_doctor_date_format', $default ), '' !== $default ? $default : 'F j, Y g:i a' );
	}

	/**
	 * Issue kinds the report knows, with labels.
	 *
	 * @return array<string, array{label: string, description: string, fix: string, broken: bool}>
	 */
	public function issue_kinds(): array {
		$kinds = array(
			'invalid'        => array(
				'label'       => __( 'Invalid', 'block-doctor' ),
				'description' => __( 'The saved markup no longer matches what the block produces. The editor shows "This block contains unexpected or invalid content."', 'block-doctor' ),
				'fix'         => 'browser',
				'broken'      => true,
			),
			'missing'        => array(
				'label'       => __( 'Unregistered', 'block-doctor' ),
				'description' => __( 'No plugin registers this block type any more, so the editor cannot edit it.', 'block-doctor' ),
				'fix'         => 'browser',
				'broken'      => true,
			),
			'classic'        => array(
				'label'       => __( 'Classic', 'block-doctor' ),
				'description' => __( 'Content outside blocks, edited in a Classic block. Converting it to blocks is optional.', 'block-doctor' ),
				'fix'         => 'browser',
				'broken'      => false,
			),
			'deprecated'     => array(
				'label'       => __( 'Outdated', 'block-doctor' ),
				'description' => __( 'Saved by an older version of the block. It still works, and the markup changes the next time the post is saved.', 'block-doctor' ),
				'fix'         => 'browser',
				'broken'      => false,
			),
			'empty'          => array(
				'label'       => __( 'Emptied', 'block-doctor' ),
				'description' => __( 'The post is empty now, but an earlier revision had blocks.', 'block-doctor' ),
				'fix'         => '',
				'broken'      => true,
			),
			'pattern-ref'    => array(
				'label'       => __( 'Deleted pattern', 'block-doctor' ),
				'description' => __( 'A synced pattern block points to a pattern that was deleted, so it shows nothing.', 'block-doctor' ),
				'fix'         => '',
				'broken'      => true,
			),
			'navigation-ref' => array(
				'label'       => __( 'Deleted menu', 'block-doctor' ),
				'description' => __( 'A Navigation block points to a menu that was deleted.', 'block-doctor' ),
				'fix'         => '',
				'broken'      => true,
			),
			'missing-media'  => array(
				'label'       => __( 'Deleted media', 'block-doctor' ),
				'description' => __( 'An image, cover, video, audio, file or media and text block points to an attachment that was deleted.', 'block-doctor' ),
				'fix'         => '',
				'broken'      => true,
			),
			'error'          => array(
				'label'       => __( 'Not checked', 'block-doctor' ),
				'description' => __( 'The scan hit an error in this post, so its blocks were not checked. Open it in the editor to see what is wrong.', 'block-doctor' ),
				'fix'         => '',
				'broken'      => false,
			),
		);

		/**
		 * Filters the issue kinds the report shows. Remove one to stop reporting it, or add one for your own server check.
		 *
		 * Each kind has `label`, `description`, `fix` (`browser` for the four kinds the screen repairs, '' for the rest) and `broken` (counts in Site Health).
		 *
		 * @param array $kinds Kinds by key.
		 */
		$filtered = (array) apply_filters( 'block_doctor_issue_kinds', $kinds );

		$clean = array();
		foreach ( $filtered as $key => $kind ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $kind ) ) {
				continue;
			}
			$clean[ $key ] = array(
				'label'       => isset( $kind['label'] ) && is_string( $kind['label'] ) && '' !== $kind['label'] ? $kind['label'] : $key,
				'description' => isset( $kind['description'] ) && is_string( $kind['description'] ) ? $kind['description'] : '',
				// Only the four kinds the browser knows how to repair can be fixed.
				'fix'         => isset( $kinds[ $key ] ) ? $kinds[ $key ]['fix'] : '',
				'broken'      => ! empty( $kind['broken'] ),
			);
		}

		return $clean;
	}
}
