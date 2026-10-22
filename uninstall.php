<?php
/**
 * Removes Block Doctor's stored results, scan progress, fix batches and the
 * background scan, on every site.
 *
 * Fixed posts stay fixed, and the revisions saved before each fix stay too.
 *
 * @package BlockDoctor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Block Doctor data from the current site.
 */
function block_doctor_uninstall_site(): void {
	foreach ( array( 'block_doctor_index', 'block_doctor_scan', 'block_doctor_server_scan', 'block_doctor_batches', 'block_doctor_js_blocks' ) as $block_doctor_option ) {
		delete_option( $block_doctor_option );
	}

	foreach ( array( '_block_doctor_issues', '_block_doctor_undo' ) as $block_doctor_key ) {
		delete_post_meta_by_key( $block_doctor_key );
	}

	wp_clear_scheduled_hook( 'block_doctor_server_scan' );
}

if ( is_multisite() ) {
	$block_doctor_offset = 0;
	do {
		$block_doctor_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $block_doctor_offset,
			)
		);

		foreach ( $block_doctor_sites as $block_doctor_site ) {
			switch_to_blog( $block_doctor_site );
			block_doctor_uninstall_site();
			restore_current_blog();
		}

		$block_doctor_offset += 100;
	} while ( $block_doctor_sites );
} else {
	block_doctor_uninstall_site();
}
