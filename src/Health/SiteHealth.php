<?php
/**
 * Site Health check.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor\Health;

use AryanX\BlockDoctor\Admin\Page;
use AryanX\BlockDoctor\Report;
use AryanX\BlockDoctor\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a Block Doctor test to Tools > Site Health, from the stored results.
 * It never scans: that needs the browser.
 */
final class SiteHealth implements Service {

	/**
	 * The report.
	 *
	 * @var Report
	 */
	private Report $report;

	/**
	 * Sets up the check.
	 *
	 * @param Report $report The report.
	 */
	public function __construct( Report $report ) {
		$this->report = $report;
	}

	/**
	 * Registers the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the test to the direct tests.
	 *
	 * @param mixed $tests Site Health tests.
	 * @return mixed
	 */
	public function add_test( $tests ) {
		if ( is_array( $tests ) ) {
			$tests['direct']['block_doctor'] = array(
				'label' => __( 'Block Doctor', 'block-doctor' ),
				'test'  => array( $this, 'run' ),
			);
		}

		return $tests;
	}

	/**
	 * Runs the test.
	 *
	 * @return array
	 */
	public function run(): array {
		$summary = $this->report->summary();
		$result  = array(
			'label'       => __( 'No broken blocks found', 'block-doctor' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Content', 'block-doctor' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Block Doctor found no invalid blocks, unregistered block types or broken references in the last scan.', 'block-doctor' ) . '</p>',
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( Page::url() ), esc_html__( 'Open Block Doctor', 'block-doctor' ) ),
			'test'        => 'block_doctor',
		);

		if ( ! $summary['last_scan'] && ! $summary['posts'] ) {
			$result['label']       = __( 'Block Doctor has not scanned the site yet', 'block-doctor' );
			$result['description'] = '<p>' . esc_html__( 'A scan finds blocks the editor would show as invalid, after a migration or a plugin update or removal.', 'block-doctor' ) . '</p>';

			return $result;
		}

		$broken = (int) $summary['broken_posts'];
		if ( ! $broken ) {
			return $result;
		}

		$result['status']         = 'recommended';
		$result['badge']['color'] = 'orange';
		$result['label']          = $summary['last_scan']
			? sprintf(
				/* translators: 1: number of posts, 2: date of the last scan. */
				_n( '%1$s post has invalid or broken blocks (last scan %2$s)', '%1$s posts have invalid or broken blocks (last scan %2$s)', $broken, 'block-doctor' ),
				number_format_i18n( $broken ),
				wp_date( (string) get_option( 'date_format' ), $summary['last_scan'] )
			)
			/* translators: %s: number of posts. */
			: sprintf( _n( '%s post has invalid or broken blocks', '%s posts have invalid or broken blocks', $broken, 'block-doctor' ), number_format_i18n( $broken ) );
		$result['description'] = '<p>' . esc_html__( 'Visitors may see missing or broken content, and editors see "This block contains unexpected or invalid content" in these posts. Block Doctor can fix most of them in bulk, with a preview first and Undo after.', 'block-doctor' ) . '</p>';
		$result['actions']     = sprintf( '<p><a href="%s">%s</a></p>', esc_url( Page::url() ), esc_html__( 'Review and fix them', 'block-doctor' ) );

		return $result;
	}
}
