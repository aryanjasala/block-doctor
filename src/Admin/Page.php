<?php
/**
 * Tools > Block Doctor.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor\Admin;

use AryanX\BlockDoctor\Config;
use AryanX\BlockDoctor\Report;
use AryanX\BlockDoctor\Service;
use const AryanX\BlockDoctor\PLUGIN_FILE;
use const AryanX\BlockDoctor\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The screen, its scripts and the CSV download.
 *
 * Block validation only exists in the block editor's JavaScript, so this
 * screen loads the editor's block scripts and fires
 * `enqueue_block_editor_assets`, the way the post editor does, so every
 * plugin's blocks register here too. The scan and the repairs then run in
 * the browser, without opening any post.
 */
final class Page implements Service {

	public const SLUG = 'block-doctor';

	public const EXPORT = 'block_doctor_export';

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
	 * Sets up the screen.
	 *
	 * @param Config $config Defaults and limits.
	 * @param Report $report The report.
	 */
	public function __construct( Config $config, Report $report ) {
		$this->config = $config;
		$this->report = $report;
	}

	/**
	 * Adds the menu entry and the download handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::EXPORT, array( $this, 'export' ) );
	}

	/**
	 * The screen's URL.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'tools.php?page=' . self::SLUG );
	}

	/**
	 * Adds Tools > Block Doctor.
	 */
	public function menu(): void {
		$hook = add_management_page(
			__( 'Block Doctor', 'block-doctor' ),
			__( 'Block Doctor', 'block-doctor' ),
			$this->config->capability(),
			self::SLUG,
			array( $this, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Loads the scripts on this screen only.
	 */
	public function load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the block editor's block scripts, every plugin's blocks and the screen.
	 */
	public function enqueue(): void {
		foreach ( array( 'wp-blocks', 'wp-block-library', 'wp-format-library', 'wp-block-editor' ) as $handle ) {
			wp_enqueue_script( $handle );
		}

		// What the post editor adds before its blocks register: server-side block definitions and categories.
		wp_add_inline_script( 'wp-blocks', 'wp.blocks.unstable__bootstrapServerSideBlockDefinitions(' . wp_json_encode( get_block_editor_server_block_settings() ) . ');' );
		wp_add_inline_script( 'wp-blocks', sprintf( 'wp.blocks.setCategories( %s );', wp_json_encode( get_block_categories( new \WP_Block_Editor_Context( array( 'name' => 'core/edit-post' ) ) ) ) ), 'after' );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own hook, fired so third-party blocks register.
		do_action( 'enqueue_block_editor_assets' );

		$url = plugins_url( 'assets/', PLUGIN_FILE );
		wp_register_script( 'block-doctor-core', $url . 'js/doctor.js', array(), VERSION, true );
		wp_enqueue_script(
			'block-doctor-admin',
			$url . 'js/admin.js',
			array( 'block-doctor-core', 'wp-api-fetch', 'wp-block-library', 'wp-block-serialization-default-parser', 'wp-blocks', 'wp-components', 'wp-dom-ready', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-url' ),
			VERSION,
			true
		);
		wp_add_inline_script( 'block-doctor-admin', 'window.blockDoctorData = ' . wp_json_encode( $this->data() ) . ';', 'before' );
		wp_set_script_translations( 'block-doctor-admin', 'block-doctor' );
		wp_enqueue_style( 'block-doctor', $url . 'admin.css', array( 'wp-components' ), VERSION );
	}

	/**
	 * What the screen's JavaScript starts with.
	 *
	 * @return array
	 */
	public function data(): array {
		$default    = $this->config->fix_strategy( '' );
		$strategies = array();
		foreach ( array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ) as $name ) {
			$strategy = $this->config->fix_strategy( (string) $name );
			if ( $strategy !== $default ) {
				$strategies[ $name ] = $strategy;
			}
		}

		$types = array();
		foreach ( $this->config->post_types() as $type ) {
			$object         = get_post_type_object( $type );
			$types[ $type ] = $object ? (string) $object->labels->singular_name : $type;
		}

		return array(
			'batchSize'  => $this->config->batch_size(),
			'undoDays'   => $this->config->undo_days(),
			'kinds'      => $this->report->kinds(),
			'summary'    => $this->report->summary(),
			'strategy'   => $default,
			'strategies' => (object) $strategies,
			'postTypes'  => $types,
			// Not wp_nonce_url(): it escapes & for HTML, and this goes to JavaScript.
			'exportUrl'  => add_query_arg(
				array(
					'action'   => self::EXPORT,
					'_wpnonce' => wp_create_nonce( self::EXPORT ),
				),
				admin_url( 'admin-post.php' )
			),
		);
	}

	/**
	 * Renders the screen. The JavaScript fills it in.
	 */
	public function render(): void {
		if ( ! $this->config->can_use() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to use Block Doctor.', 'block-doctor' ), 403 );
		}
		?>
		<div class="wrap bdr-wrap">
			<h1><?php esc_html_e( 'Block Doctor', 'block-doctor' ); ?></h1>
			<p class="bdr-intro"><?php esc_html_e( 'Finds blocks the editor would flag as invalid, block types no plugin registers any more, classic content and broken references, in every post, page, pattern and template. Fixes are previewed first, saved as revisions, and a whole batch can be undone.', 'block-doctor' ); ?></p>
			<div id="bdr-app">
				<p class="bdr-loading"><?php esc_html_e( 'Loading the block types...', 'block-doctor' ); ?></p>
				<noscript><p><?php esc_html_e( 'Block Doctor needs JavaScript: block validation only exists in the block editor\'s scripts.', 'block-doctor' ); ?></p></noscript>
			</div>
		</div>
		<?php
	}

	/**
	 * Streams the report as CSV.
	 */
	public function export(): void {
		if ( ! $this->config->can_use() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to use Block Doctor.', 'block-doctor' ), 403 );
		}
		check_admin_referer( self::EXPORT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified just above.
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=block-doctor-' . gmdate( 'Y-m-d' ) . '.csv' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML. csv_line() quotes every cell.
		echo self::csv_line( Report::columns() );
		foreach ( $this->report->lines( array( 'kind' => $kind ) ) as $line ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML. csv_line() quotes every cell.
			echo self::csv_line( $line );
		}
		exit;
	}

	/**
	 * One CSV line, with formula-like values defused for spreadsheets.
	 *
	 * @param array $cells Values.
	 * @return string
	 */
	public static function csv_line( array $cells ): string {
		$out = array();
		foreach ( $cells as $cell ) {
			$cell = (string) $cell;
			if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
				$cell = "'" . $cell;
			}
			$out[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}

		return implode( ',', $out ) . "\r\n";
	}
}
