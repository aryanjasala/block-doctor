<?php
/**
 * Removes Block Doctor's stored results, scan progress, fix batches, the block
 * usage index and the background scans, on every site.
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
	foreach ( array( 'block_doctor_index', 'block_doctor_scan', 'block_doctor_server_scan', 'block_doctor_batches', 'block_doctor_js_blocks', 'block_doctor_usage_scan', 'block_doctor_providers', 'block_doctor_js_sources', 'block_doctor_affected' ) as $block_doctor_option ) {
		delete_option( $block_doctor_option );
	}

	foreach ( array( '_block_doctor_issues', '_block_doctor_undo' ) as $block_doctor_key ) {
		delete_post_meta_by_key( $block_doctor_key );
	}

	// The block usage index: one meta row per post and block type.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One delete of the plugin's own meta keys, on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_block_doctor_use_' ) . '%' ) );

	wp_clear_scheduled_hook( 'block_doctor_server_scan' );
	wp_clear_scheduled_hook( 'block_doctor_usage_index' );
}

// A per-user preference, so it is removed once for the whole network.
delete_metadata( 'user', 0, 'block_doctor_steps_dismissed', '', true );

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
