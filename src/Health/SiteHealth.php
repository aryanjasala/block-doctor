<?php
/**
 * Site Health check.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor\Health;

use AryanX\Axle\BlockDoctor\Admin\AxleUi;
use AryanX\Axle\BlockDoctor\Admin\Page;
use AryanX\Axle\BlockDoctor\Deactivation;
use AryanX\Axle\BlockDoctor\Keeper;
use AryanX\Axle\BlockDoctor\Report;
use AryanX\Axle\BlockDoctor\Service;
use AryanX\Axle\BlockDoctor\Usage;

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
	 * The block usage index.
	 *
	 * @var Usage
	 */
	private Usage $usage;

	/**
	 * Blocks left behind by plugins and themes that are off.
	 *
	 * @var Deactivation
	 */
	private Deactivation $deactivation;

	/**
	 * Blocks kept as HTML.
	 *
	 * @var Keeper|null
	 */
	private ?Keeper $keeper;

	/**
	 * Sets up the checks.
	 *
	 * @param Report       $report       The report.
	 * @param Usage        $usage        The block usage index.
	 * @param Deactivation $deactivation Blocks left behind by plugins and themes that are off.
	 * @param Keeper|null  $keeper       Blocks kept as HTML.
	 */
	public function __construct( Report $report, Usage $usage, Deactivation $deactivation, ?Keeper $keeper = null ) {
		$this->report       = $report;
		$this->usage        = $usage;
		$this->deactivation = $deactivation;
		$this->keeper       = $keeper;
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
			$tests['direct']['block_doctor']         = array(
				'label' => __( 'Block Doctor', 'block-doctor' ),
				'test'  => array( $this, 'run' ),
			);
			$tests['direct']['block_doctor_orphans'] = array(
				'label' => __( 'Blocks of plugins and themes that are off', 'block-doctor' ),
				'test'  => array( $this, 'run_orphans' ),
			);
			if ( $this->keeper ) {
				$tests['direct']['block_doctor_kept'] = array(
					'label' => __( 'Blocks kept as HTML while their plugin is on', 'block-doctor' ),
					'test'  => array( $this, 'run_kept' ),
				);
			}
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
		/* translators: %s: number of posts. */
		$result['label']       = sprintf( _n( '%s post has invalid or broken blocks', '%s posts have invalid or broken blocks', $broken, 'block-doctor' ), number_format_i18n( $broken ) );
		$result['description'] = '<p>' . esc_html__( 'Visitors may see missing or broken content, and editors see "This block contains unexpected or invalid content" in these posts. Block Doctor can fix most of them in bulk, with a preview first and Undo after.', 'block-doctor' ) . '</p>';
		if ( $summary['last_scan'] ) {
			$result['description'] .= '<p>' . esc_html__( 'Last scan:', 'block-doctor' ) . ' ' . AxleUi::relative_time( (int) $summary['last_scan'] ) . '</p>';
		}
		$result['actions'] = sprintf( '<p><a href="%s">%s</a></p>', esc_url( Page::url() ), esc_html__( 'Review and fix them', 'block-doctor' ) );

		return $result;
	}

	/**
	 * Blocks that posts still use after their plugin or theme was switched off.
	 *
	 * @return array
	 */
	public function run_orphans(): array {
		$result = array(
			'label'       => __( 'No post uses blocks from a plugin or theme that is off', 'block-doctor' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Content', 'block-doctor' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Every block type that posts use is provided by something that is on.', 'block-doctor' ) . '</p>',
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( add_query_arg( array( 'tab' => 'usage' ), Page::url() ) ), esc_html__( 'Open the block usage list', 'block-doctor' ) ),
			'test'        => 'block_doctor_orphans',
		);

		if ( ! $this->usage->ready() ) {
			$result['label']       = __( 'Block Doctor is still counting block usage', 'block-doctor' );
			$result['description'] = '<p>' . esc_html__( 'The first count runs in the background. Once it is done, this check shows blocks left behind by a plugin or theme that was switched off.', 'block-doctor' ) . '</p>';

			return $result;
		}

		$orphans = $this->deactivation->orphans();
		if ( ! $orphans ) {
			return $result;
		}

		$posts = 0;
		$items = array();
		foreach ( $orphans as $key => $group ) {
			$posts  += $group['posts'];
			$items[] = sprintf(
				'<li>%1$s (<a href="%2$s">%3$s</a>)</li>',
				esc_html( $group['provider']['name'] ),
				esc_url(
					add_query_arg(
						array(
							'tab'      => 'usage',
							'provider' => rawurlencode( $key ),
						),
						Page::url()
					)
				),
				esc_html(
					sprintf(
						/* translators: 1: number of block types, 2: number of posts. */
						_n( '%1$s block type in %2$s posts', '%1$s block types in %2$s posts', count( $group['blocks'] ), 'block-doctor' ),
						number_format_i18n( count( $group['blocks'] ) ),
						number_format_i18n( $group['posts'] )
					)
				)
			);
		}

		$result['status']         = 'recommended';
		$result['badge']['color'] = 'orange';
		/* translators: %s: number of posts. */
		$result['label']       = sprintf( _n( '%s post uses blocks from a plugin or theme that is off', '%s posts use blocks from a plugin or theme that is off', $posts, 'block-doctor' ), number_format_i18n( $posts ) );
		$result['description'] = '<p>' . esc_html__( 'The editor shows these blocks as unregistered, and visitors may see nothing in their place. Switch the plugin or theme back on, or fix the posts with Block Doctor.', 'block-doctor' ) . '</p><ul>' . implode( '', $items ) . '</ul>';
		$result['actions']     = sprintf( '<p><a href="%s">%s</a></p>', esc_url( Page::url() ), esc_html__( 'Open Block Doctor to fix them', 'block-doctor' ) );

		return $result;
	}

	/**
	 * Blocks Block Doctor kept as HTML for a plugin that is on again, and that nobody put back.
	 *
	 * @return array
	 */
	public function run_kept(): array {
		$result = array(
			'label'       => __( 'No blocks are kept as HTML while their plugin is on', 'block-doctor' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Content', 'block-doctor' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Every dynamic block that Block Doctor saved as HTML belongs to a plugin that is off, or has been put back.', 'block-doctor' ) . '</p>',
			'actions'     => '',
			'test'        => 'block_doctor_kept',
		);

		$waiting = $this->keeper ? $this->keeper->waiting() : array();
		if ( ! $waiting ) {
			return $result;
		}

		$items  = array();
		$blocks = 0;
		foreach ( $waiting as $key => $run ) {
			$blocks += $run['kept']['blocks'];
			$items[] = sprintf(
				/* translators: 1: plugin name, 2: number of blocks. */
				'<li>%s</li>',
				esc_html(
					sprintf(
						/* translators: 1: plugin name, 2: number of blocks. */
						_n( '%1$s: %2$s block', '%1$s: %2$s blocks', $run['kept']['blocks'], 'block-doctor' ),
						'' !== $run['name'] ? $run['name'] : $key,
						number_format_i18n( $run['kept']['blocks'] )
					)
				)
			);
		}

		$result['status']         = 'recommended';
		$result['badge']['color'] = 'orange';
		/* translators: %s: number of blocks. */
		$result['label']       = sprintf( _n( '%s block is frozen as HTML although its plugin is on', '%s blocks are frozen as HTML although their plugin is on', $blocks, 'block-doctor' ), number_format_i18n( $blocks ) );
		$result['description'] = '<p>' . esc_html__( 'They show what they showed when they were kept, and no longer follow the plugin. Put the blocks back to get the live blocks again.', 'block-doctor' ) . '</p><ul>' . implode( '', $items ) . '</ul>';
		$result['actions']     = sprintf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'plugins.php' ) ), esc_html__( 'Open the Plugins screen to put them back', 'block-doctor' ) );

		return $result;
	}
}
