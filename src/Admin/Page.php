<?php
/**
 * Tools > Block Doctor.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor\Admin;

use AryanX\Axle\BlockDoctor\Config;
use AryanX\Axle\BlockDoctor\Issues;
use AryanX\Axle\BlockDoctor\Report;
use AryanX\Axle\BlockDoctor\Service;
use AryanX\Axle\BlockDoctor\Usage;
use const AryanX\Axle\BlockDoctor\PLUGIN_FILE;
use const AryanX\Axle\BlockDoctor\VERSION;

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

	public const USAGE_EXPORT = 'block_doctor_usage_export';

	/**
	 * User meta: the person dismissed the Get started card.
	 */
	public const STEPS_META = 'block_doctor_steps_dismissed';

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
	 * The block usage index.
	 *
	 * @var Usage
	 */
	private Usage $usage;

	/**
	 * Sets up the screen.
	 *
	 * @param Config $config Defaults and limits.
	 * @param Report $report The report.
	 * @param Usage  $usage  The block usage index.
	 */
	public function __construct( Config $config, Report $report, Usage $usage ) {
		$this->config = $config;
		$this->report = $report;
		$this->usage  = $usage;
	}

	/**
	 * Adds the menu entry, the download handlers and the link in the Plugins list.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::EXPORT, array( $this, 'export' ) );
		add_action( 'admin_post_' . self::USAGE_EXPORT, array( $this, 'export_usage' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds "Open Block Doctor" to the plugin's row on the Plugins screen.
	 *
	 * @param mixed $links Links.
	 * @return mixed
	 */
	public function action_links( $links ) {
		if ( is_array( $links ) && $this->config->can_use() ) {
			array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Open Block Doctor', 'block-doctor' ) ) );
		}

		return $links;
	}

	/**
	 * Which tab is open: `scan` or `usage`.
	 *
	 * @return string
	 */
	public static function tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses what to show, changes nothing.
		return isset( $_GET['tab'] ) && 'usage' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'usage' : 'scan';
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
		// Core loads the editor scripts of blocks registered from block.json only on block editor screens.
		// Without them, a plugin's working blocks would show here as unregistered.
		add_filter( 'should_load_block_editor_scripts_and_styles', '__return_true' );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		AxleUi::help_tabs( $this->help() );
	}

	/**
	 * The Help tabs: an overview, and one short tab for each job of the screen.
	 *
	 * @return array<int, array{id: string, title: string, content: string}>
	 */
	public function help(): array {
		return array(
			array(
				'id'      => 'overview',
				'title'   => __( 'Overview', 'block-doctor' ),
				'content' => '<p>' . __( 'Block Doctor checks every block on the site, fixes the broken ones in bulk, and shows where each block type is used. The Scan and fix tab finds problems. The Block usage tab lists every block type, who provides it and which posts use it.', 'block-doctor' ) . '</p>',
			),
			array(
				'id'      => 'scan',
				'title'   => __( 'Scan the site', 'block-doctor' ),
				'content' => '<p>' . __( 'A scan runs in this browser tab, with the block editor\'s own validation, 20 posts at a time. You can pause it, or close the tab and resume later. Nothing changes until you fix something.', 'block-doctor' ) . '</p>',
			),
			array(
				'id'      => 'fix',
				'title'   => __( 'Fix blocks', 'block-doctor' ),
				'content' => '<p>' . __( 'Tick the posts, then choose Preview fixes. You see a diff for every post before anything is saved. Each fix is saved as a revision, and only the broken blocks change.', 'block-doctor' ) . '</p>',
			),
			array(
				'id'      => 'undo',
				'title'   => __( 'Undo a batch', 'block-doctor' ),
				'content' => '<p>' . __( 'Every run of fixes is a batch with a number. Undo batch puts each post back to the revision saved before its fix. Posts edited since are skipped and listed.', 'block-doctor' ) . '</p>',
			),
			array(
				'id'      => 'usage',
				'title'   => __( 'Block usage', 'block-doctor' ),
				'content' => '<p>' . __( 'The Block usage tab counts the posts and uses of every block type. Choose a row to see the posts, with the position of the block in each. Use the filters to find blocks that are unregistered, or registered and not used.', 'block-doctor' ) . '</p>',
			),
			array(
				'id'      => 'switch-off',
				'title'   => __( 'Before you switch a plugin off', 'block-doctor' ),
				'content' => '<p>' . __( 'On the Plugins screen, Deactivate and Delete show how many posts use the blocks of that plugin before anything happens. You can continue or cancel. The warning needs the block usage index, which builds itself in the background the first time.', 'block-doctor' ) . '</p>',
			),
		);
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

		// Notes which script registers each block in the browser, so a block that only exists in JavaScript can be traced to its plugin or theme.
		wp_add_inline_script( 'wp-blocks', "window.blockDoctorSources = window.blockDoctorSources || {}; wp.hooks.addFilter( 'blocks.registerBlockType', 'block-doctor/source', function ( settings, name ) { var script = document.currentScript; if ( script && script.src && ! window.blockDoctorSources[ name ] ) { window.blockDoctorSources[ name ] = script.src; } return settings; }, 1 );", 'after' );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own hook, fired so third-party blocks register.
		do_action( 'enqueue_block_editor_assets' );

		// The scripts that register each block in the editor, as the post editor loads them, even when
		// another plugin turns should_load_block_editor_scripts_and_styles off again.
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $type ) {
			foreach ( array_merge( (array) $type->editor_script_handles, (array) $type->script_handles ) as $handle ) {
				if ( '' !== $handle ) {
					wp_enqueue_script( $handle );
				}
			}
		}

		$url = plugins_url( 'assets/', PLUGIN_FILE );
		wp_register_script( 'block-doctor-core', $url . 'js/doctor.js', array(), VERSION, true );
		wp_enqueue_script(
			'block-doctor-admin',
			$url . 'js/admin.js',
			array( 'block-doctor-core', 'wp-a11y', 'wp-api-fetch', 'wp-block-library', 'wp-block-serialization-default-parser', 'wp-blocks', 'wp-components', 'wp-date', 'wp-dom-ready', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-url' ),
			VERSION,
			true
		);
		wp_add_inline_script( 'block-doctor-admin', 'window.blockDoctorData = ' . wp_json_encode( $this->data() ) . ';', 'before' );
		wp_set_script_translations( 'block-doctor-admin', 'block-doctor' );
		AxleUi::enqueue();
		wp_enqueue_style( 'block-doctor', $url . 'admin.css', array( 'wp-components', AxleUi::HANDLE ), VERSION );
	}

	/**
	 * What the screen's JavaScript starts with.
	 *
	 * @return array
	 */
	public function data(): array {
		$default    = $this->config->fix_strategy( '' );
		$strategies = array();
		$registered = array_map( 'strval', array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ) );
		foreach ( $registered as $name ) {
			$strategy = $this->config->fix_strategy( $name );
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
			'batchSize'      => $this->config->batch_size(),
			'undoDays'       => $this->config->undo_days(),
			'kinds'          => $this->report->kinds(),
			'summary'        => $this->report->summary(),
			'strategy'       => $default,
			'strategies'     => (object) $strategies,
			'postTypes'      => $types,
			// Block types registered in PHP. The scan never reports these as unregistered, even when their editor script does not load here.
			'serverBlocks'   => $registered,
			// PHP date format for wp.date.dateI18n(), which also uses the site's time zone.
			'dateFormat'     => $this->config->date_format(),
			'tab'            => self::tab(),
			'usageUrl'       => add_query_arg( array( 'tab' => 'usage' ), self::url() ),
			'stepsDismissed' => (bool) get_user_meta( get_current_user_id(), self::STEPS_META, true ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses a filter, changes nothing.
			'provider'       => isset( $_GET['provider'] ) ? sanitize_text_field( wp_unslash( $_GET['provider'] ) ) : '',
			'usage'          => array(
				'ready' => $this->usage->ready(),
				'state' => $this->usage->state(),
			),
			'perPage'        => $this->config->usage_per_page(),
			'pluginsUrl'     => admin_url( 'plugins.php' ),
			'usageExport'    => add_query_arg(
				array(
					'action'   => self::USAGE_EXPORT,
					'_wpnonce' => wp_create_nonce( self::USAGE_EXPORT ),
				),
				admin_url( 'admin-post.php' )
			),
			// Not wp_nonce_url(): it escapes & for HTML, and this goes to JavaScript.
			'exportUrl'      => add_query_arg(
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
		<div class="wrap axle-ui bdr-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Block Doctor', 'block-doctor' ); ?></h1>
			<hr class="wp-header-end">
			<?php $tab = self::tab(); ?>
			<nav class="nav-tab-wrapper bdr-nav" aria-label="<?php esc_attr_e( 'Block Doctor views', 'block-doctor' ); ?>">
				<a class="nav-tab<?php echo 'scan' === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url() ); ?>"<?php echo 'scan' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Scan and fix', 'block-doctor' ); ?></a>
				<a class="nav-tab<?php echo 'usage' === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'usage' ), self::url() ) ); ?>"<?php echo 'usage' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Block usage', 'block-doctor' ); ?></a>
			</nav>
			<p class="axle-intro bdr-intro">
				<?php
				if ( 'usage' === $tab ) {
					esc_html_e( 'Every block type on the site, who provides it, and which posts use it. Check this before you turn a plugin off.', 'block-doctor' );
				} else {
					esc_html_e( 'Finds blocks the editor would flag as invalid, block types no plugin registers any more, classic content and broken references, in every post, page, pattern and template. Fixes are previewed first, saved as revisions, and a whole batch can be undone.', 'block-doctor' );
				}
				?>
			</p>
			<div id="bdr-app">
				<p class="bdr-loading" role="status"><span class="spinner is-active"></span> <?php esc_html_e( 'Loading the block types...', 'block-doctor' ); ?></p>
				<noscript><p><?php esc_html_e( 'Block Doctor needs JavaScript: block validation only exists in the block editor\'s scripts.', 'block-doctor' ); ?></p></noscript>
			</div>
			<?php AxleUi::footer(); ?>
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
		foreach ( $this->report->lines(
			array(
				'kind'     => $kind,
				'editable' => true,
			)
		) as $line ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML. csv_line() quotes every cell.
			echo self::csv_line( $line );
		}
		exit;
	}

	/**
	 * Streams the Block usage tab as CSV: every block type, or the posts using one.
	 */
	public function export_usage(): void {
		if ( ! $this->config->can_use() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to use Block Doctor.', 'block-doctor' ), 403 );
		}
		check_admin_referer( self::USAGE_EXPORT );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified just above.
		$args = array(
			'block'    => isset( $_GET['block'] ) ? Issues::block_name( sanitize_text_field( wp_unslash( $_GET['block'] ) ) ) : '',
			'provider' => isset( $_GET['provider'] ) ? sanitize_text_field( wp_unslash( $_GET['provider'] ) ) : '',
			'filter'   => isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : '',
			'search'   => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
		);
		// phpcs:enable

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=block-doctor-usage-' . ( '' !== $args['block'] ? str_replace( '/', '-', $args['block'] ) . '-' : '' ) . gmdate( 'Y-m-d' ) . '.csv' );

		foreach ( $this->usage_lines( $args ) as $line ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML. csv_line() quotes every cell.
			echo self::csv_line( $line );
		}
		exit;
	}

	/**
	 * The lines of the usage CSV, header first: one per block type, or one per post that uses `block`.
	 *
	 * @param array $args `block`, `provider`, `filter`, `search`.
	 * @return \Generator<int, array>
	 */
	public function usage_lines( array $args ): \Generator {
		$types = $this->usage->visible_types();
		$block = (string) ( $args['block'] ?? '' );

		if ( '' !== $block ) {
			yield array( 'post_id', 'title', 'post_type', 'status', 'uses', 'edit_link' );
			$page = 1;
			$more = true;
			while ( $more ) {
				$found = $this->usage->posts_using( array( $block ), $types, $page, 100 );
				foreach ( $found['items'] as $item ) {
					$post = get_post( $item['id'] );
					if ( $post && current_user_can( 'edit_post', $post->ID ) ) {
						yield array( $post->ID, $post->post_title, $post->post_type, $post->post_status, $item['uses'], get_edit_post_link( $post->ID, 'raw' ) );
					}
				}
				++$page;
				$more = 100 === count( $found['items'] );
			}
			return;
		}

		yield array( 'block', 'title', 'provider_type', 'provider', 'registered', 'posts', 'uses', 'last_used' );
		$rows = $this->usage->blocks(
			array(
				'types'    => $types,
				'search'   => (string) ( $args['search'] ?? '' ),
				'filter'   => (string) ( $args['filter'] ?? '' ),
				'provider' => (string) ( $args['provider'] ?? '' ),
			)
		);
		foreach ( $rows as $row ) {
			yield array( $row['name'], $row['title'], $row['provider']['type'], $row['provider']['name'], $row['registered'] ? 'yes' : 'no', $row['posts'], $row['uses'], $row['last'] ? gmdate( 'Y-m-d H:i:s', $row['last'] ) : '' );
		}
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
