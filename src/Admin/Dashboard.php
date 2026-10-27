<?php
/**
 * Dashboard widget.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor\Admin;

use AryanX\Axle\BlockDoctor\Config;
use AryanX\Axle\BlockDoctor\Report;
use AryanX\Axle\BlockDoctor\Service;

defined( 'ABSPATH' ) || exit;

/**
 * A dashboard widget with the issue counts from the last scan.
 */
final class Dashboard implements Service {

	/**
	 * Defaults and limits.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * The report.
	 *
	 * @var Report
	 */
	private Report $report;

	/**
	 * Sets up the widget.
	 *
	 * @param Config $config Defaults and limits.
	 * @param Report $report The report.
	 */
	public function __construct( Config $config, Report $report ) {
		$this->config = $config;
		$this->report = $report;
	}

	/**
	 * Adds the widget.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add' ) );
	}

	/**
	 * Adds the widget for people who may use Block Doctor.
	 */
	public function add(): void {
		if ( $this->config->can_use() ) {
			wp_add_dashboard_widget( 'block_doctor', __( 'Block Doctor', 'block-doctor' ), array( $this, 'render' ) );
		}
	}

	/**
	 * Renders the counts.
	 */
	public function render(): void {
		$summary = $this->report->summary();

		if ( ! $summary['last_scan'] && ! $summary['posts'] ) {
			echo '<p>' . esc_html__( 'No scan yet. A scan checks every block in every post, page, pattern and template.', 'block-doctor' ) . '</p>';
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( Page::url() ), esc_html__( 'Scan the site', 'block-doctor' ) );
			return;
		}

		$rows = array();
		foreach ( $this->report->kinds() as $kind ) {
			if ( ! empty( $kind['issues'] ) ) {
				$rows[] = sprintf(
					'<li class="bdr-widget-%1$s"><strong>%2$s</strong> %3$s</li>',
					esc_attr( $kind['key'] ),
					esc_html( number_format_i18n( (int) $kind['issues'] ) ),
					esc_html(
						sprintf(
							/* translators: 1: issue kind, such as Invalid, 2: number of posts. */
							_n( '%1$s, in %2$s post', '%1$s, in %2$s posts', (int) $kind['posts'], 'block-doctor' ),
							$kind['label'],
							number_format_i18n( (int) $kind['posts'] )
						)
					)
				);
			}
		}

		if ( $rows ) {
			echo '<ul class="bdr-widget">' . wp_kses_post( implode( '', $rows ) ) . '</ul>';
		} else {
			echo '<p>' . esc_html__( 'No issues found. Every block is healthy.', 'block-doctor' ) . '</p>';
		}

		if ( $summary['last_scan'] ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: date and time. */
						__( 'Last scan: %s', 'block-doctor' ),
						wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $summary['last_scan'] )
					)
				)
			);
		}
		printf( '<p><a href="%s">%s</a></p>', esc_url( Page::url() ), esc_html__( 'Open Block Doctor', 'block-doctor' ) );
	}
}
