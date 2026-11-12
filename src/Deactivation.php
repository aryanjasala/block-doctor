<?php
/**
 * What switching a plugin or theme off does to the blocks it provides.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor;

use AryanX\Axle\BlockDoctor\Admin\AxleUi;
use AryanX\Axle\BlockDoctor\Admin\Page;
use const AryanX\Axle\BlockDoctor\PLUGIN_FILE;
use const AryanX\Axle\BlockDoctor\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Warns before a plugin that provides blocks is deactivated or deleted, tells
 * people afterwards which posts lost their blocks, and never blocks anything
 * itself: the Plugins screen shows a step first, WP-CLI prints a line.
 *
 * The facts come from the block usage index and the providers it remembers,
 * so a block keeps its plugin after the plugin is off.
 */
final class Deactivation implements Service {

	/**
	 * Option with the plugins and themes switched off while their blocks were in use: provider key => time.
	 */
	public const AFFECTED = 'block_doctor_affected';

	public const DISMISS = 'block_doctor_dismiss_affected';

	/**
	 * Defaults and limits.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * The block usage index.
	 *
	 * @var Usage
	 */
	private Usage $usage;

	/**
	 * Where each block type comes from.
	 *
	 * @var Providers
	 */
	private Providers $providers;

	/**
	 * Sets up the warnings.
	 *
	 * @param Config    $config    Defaults and limits.
	 * @param Usage     $usage     The block usage index.
	 * @param Providers $providers Where each block type comes from.
	 */
	public function __construct( Config $config, Usage $usage, Providers $providers ) {
		$this->config    = $config;
		$this->usage     = $usage;
		$this->providers = $providers;
	}

	/**
	 * Hooks into plugin and theme changes, and the Plugins screen.
	 */
	public function register(): void {
		add_action( 'deactivate_plugin', array( $this, 'before_deactivate' ) );
		add_action( 'deactivated_plugin', array( $this, 'after_deactivate' ) );
		add_action( 'activated_plugin', array( $this, 'after_activate' ) );
		add_action( 'switch_theme', array( $this, 'on_switch_theme' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_post_' . self::DISMISS, array( $this, 'dismiss' ) );
		add_action( 'load-plugins.php', array( $this, 'load_plugins_screen' ) );
	}

	/**
	 * The warning for one plugin or theme: its block types that posts use, and the posts.
	 *
	 * @param string        $key   Provider key, such as `plugin:acme/acme.php`.
	 * @param string[]|null $types Post types to count, null for every scanned type.
	 * @return array Empty when its blocks are not used anywhere, or the index is not built.
	 */
	public function warning( string $key, ?array $types = null ): array {
		if ( ! $this->usage->ready() ) {
			return array();
		}
		$names = $this->providers->blocks_of( $key );
		if ( ! $names ) {
			return array();
		}

		$args   = null === $types ? array() : array( 'types' => $types );
		$used   = array();
		$name   = '';
		$type   = '';
		$blocks = $this->usage->blocks( $args + array( 'provider' => $key ) );
		foreach ( $blocks as $row ) {
			$name = $row['provider']['name'];
			$type = $row['provider']['type'];
			if ( $row['posts'] > 0 ) {
				$used[] = array(
					'name'  => $row['name'],
					'title' => '' !== $row['title'] ? $row['title'] : $row['name'],
					'posts' => $row['posts'],
					'uses'  => $row['uses'],
				);
			}
		}
		if ( ! $used ) {
			return array();
		}

		$found = $this->usage->posts_using( wp_list_pluck( $used, 'name' ), $types, 1, $this->config->warning_posts() );
		$top   = array();
		foreach ( $found['items'] as $item ) {
			$post = get_post( $item['id'] );
			if ( $post && current_user_can( 'edit_post', $post->ID ) ) {
				$top[] = array(
					'id'        => (int) $post->ID,
					'title'     => '' !== $post->post_title ? (string) $post->post_title : (string) $post->post_name,
					'edit_link' => (string) get_edit_post_link( $post->ID, 'raw' ),
					'uses'      => $item['uses'],
				);
			}
		}

		$warning            = array(
			'key'         => $key,
			'name'        => $name,
			'type'        => $type,
			'blocks'      => $used,
			'block_count' => count( $used ),
			'posts'       => $found['total'],
			'top'         => $top,
			'url'         => add_query_arg(
				array(
					'tab'      => 'usage',
					'provider' => rawurlencode( $key ),
				),
				Page::url()
			),
			'message'     => '',
		);
		$warning['message'] = sprintf(
			/* translators: 1: number of block types, 2: number of posts. */
			_n( 'Its %1$s block type is used in %2$s posts.', 'Its %1$s block types are used in %2$s posts.', $warning['block_count'], 'block-doctor' ),
			number_format_i18n( $warning['block_count'] ),
			number_format_i18n( $warning['posts'] )
		);

		/**
		 * Filters the message in the warning before a plugin or theme that provides blocks in use is turned off.
		 *
		 * @param string $message Default "Its 3 block types are used in 212 posts."
		 * @param array  $warning The warning: key, name, type, blocks, block_count, posts, top, url.
		 */
		$message            = (string) apply_filters( 'block_doctor_deactivation_message', $warning['message'], $warning );
		$warning['message'] = '' !== $message ? $message : $warning['message'];

		/**
		 * Filters the warning for a plugin or theme whose blocks are in use. Return an empty array to show no warning.
		 *
		 * @param array  $warning The warning: key, name, type, blocks, block_count, posts, top, url, message.
		 * @param string $key     Provider key, such as `plugin:acme/acme.php`.
		 */
		return (array) apply_filters( 'block_doctor_deactivation_warning', $warning, $key );
	}

	/**
	 * Plugins and themes whose blocks are used in posts but no longer registered, with the posts they hit.
	 *
	 * @param string[]|null $types Post types to count, null for every scanned type.
	 * @return array<string, array{provider: array, blocks: string[], posts: int}> By provider key.
	 */
	public function orphans( ?array $types = null ): array {
		if ( ! $this->usage->ready() ) {
			return array();
		}

		$args   = array( 'filter' => 'unregistered' );
		$groups = array();
		foreach ( $this->usage->blocks( null === $types ? $args : $args + array( 'types' => $types ) ) as $row ) {
			if ( in_array( $row['provider']['type'], array( 'core', 'unknown' ), true ) ) {
				continue;
			}
			$key = Providers::key( $row['provider'] );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'provider' => $row['provider'],
					'blocks'   => array(),
					'posts'    => 0,
				);
			}
			$groups[ $key ]['blocks'][] = $row['name'];
		}
		foreach ( $groups as &$group ) {
			$group['posts'] = $this->usage->posts_using( $group['blocks'], $types, 1, 1 )['total'];
		}

		return $groups;
	}

	/**
	 * Before a plugin is deactivated its blocks are still registered: remember who provides them, and warn on the command line.
	 *
	 * @param string $plugin Plugin file.
	 */
	public function before_deactivate( $plugin ): void {
		$this->providers->remember();

		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) || ! $this->config->cli_warning() ) {
			return;
		}
		$warning = $this->warning( 'plugin:' . (string) $plugin );
		if ( $warning ) {
			\WP_CLI::warning(
				sprintf(
					/* translators: 1: plugin name, 2: warning such as "Its 3 block types are used in 212 posts.", 3: command to list the posts. */
					__( '%1$s provides blocks that posts use. %2$s List them with: %3$s', 'block-doctor' ),
					$warning['name'],
					$warning['message'],
					'wp block-doctor usage --provider=' . $warning['key']
				)
			);
		}
	}

	/**
	 * After a plugin is deactivated: note it when its blocks were in use, so a notice can say what to do.
	 *
	 * @param string $plugin Plugin file.
	 */
	public function after_deactivate( $plugin ): void {
		$this->affect( 'plugin', 'plugin:' . (string) $plugin );
	}

	/**
	 * After a plugin is activated: its blocks are back, so the notice goes away.
	 *
	 * @param string $plugin Plugin file.
	 */
	public function after_activate( $plugin ): void {
		$this->unaffect( 'plugin:' . (string) $plugin );
	}

	/**
	 * When the theme changes: the old theme's blocks may be gone.
	 *
	 * @param string          $new_name  New theme name.
	 * @param \WP_Theme|mixed $new_theme New theme.
	 * @param \WP_Theme|mixed $old_theme Old theme.
	 */
	public function on_switch_theme( $new_name = '', $new_theme = null, $old_theme = null ): void {
		unset( $new_name );
		$this->providers->remember();
		if ( $new_theme instanceof \WP_Theme ) {
			$this->unaffect( 'theme:' . $new_theme->get_stylesheet() );
			$this->unaffect( 'theme:' . $new_theme->get_template() );
		}
		if ( $old_theme instanceof \WP_Theme ) {
			$this->affect( 'theme', 'theme:' . $old_theme->get_stylesheet() );
		}
	}

	/**
	 * Notes that a provider was switched off while its blocks were in use.
	 *
	 * @param string $source `plugin` or `theme`.
	 * @param string $key    Provider key.
	 */
	private function affect( string $source, string $key ): void {
		$warning = $this->warning( $key );
		if ( ! $warning ) {
			return;
		}

		$affected         = (array) get_option( self::AFFECTED, array() );
		$affected[ $key ] = time();
		update_option( self::AFFECTED, $affected, false );

		/**
		 * Fires when a plugin or theme is switched off while posts still use its blocks.
		 *
		 * @param string $source  `plugin` or `theme`.
		 * @param string $key     Provider key, such as `plugin:acme/acme.php`.
		 * @param array  $warning The warning: name, blocks, block_count, posts, top, url.
		 */
		do_action( 'block_doctor_blocks_orphaned', $source, $key, $warning );
	}

	/**
	 * Forgets that a provider was switched off.
	 *
	 * @param string $key Provider key.
	 */
	private function unaffect( string $key ): void {
		$affected = (array) get_option( self::AFFECTED, array() );
		if ( isset( $affected[ $key ] ) ) {
			unset( $affected[ $key ] );
			if ( $affected ) {
				update_option( self::AFFECTED, $affected, false );
			} else {
				delete_option( self::AFFECTED );
			}
		}
	}

	/**
	 * The notice after a plugin or theme with blocks in use was switched off.
	 */
	public function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network', 'themes', 'tools_page_block-doctor' ), true ) || ! $this->config->can_use() ) {
			return;
		}
		$affected = (array) get_option( self::AFFECTED, array() );
		if ( ! $affected ) {
			return;
		}

		$orphans = $this->orphans( $this->usage->visible_types() );
		foreach ( array_keys( $affected ) as $key ) {
			if ( ! isset( $orphans[ (string) $key ] ) ) {
				// Back on, or no post uses its blocks any more.
				$this->unaffect( (string) $key );
				continue;
			}
			$group   = $orphans[ (string) $key ];
			$dismiss = wp_nonce_url(
				add_query_arg(
					array(
						'action' => self::DISMISS,
						'key'    => rawurlencode( (string) $key ),
					),
					admin_url( 'admin-post.php' )
				),
				self::DISMISS
			);
			$text    = sprintf(
				/* translators: 1: plugin or theme name, 2: number of block types, 3: number of posts. */
				_n( '%1$s is off, and its %2$s block type is still used in %3$s posts. The editor shows those blocks as unregistered.', '%1$s is off, and its %2$s block types are still used in %3$s posts. The editor shows those blocks as unregistered.', count( $group['blocks'] ), 'block-doctor' ),
				$group['provider']['name'],
				number_format_i18n( count( $group['blocks'] ) ),
				number_format_i18n( $group['posts'] )
			);

			/**
			 * Filters the notice shown after a plugin or theme with blocks in use is switched off.
			 *
			 * @param string $text  The sentence.
			 * @param array  $group provider, blocks, posts.
			 * @param string $key   Provider key.
			 */
			$text = (string) apply_filters( 'block_doctor_orphans_notice', $text, $group, (string) $key );

			printf(
				'<div class="notice notice-warning block-doctor-affected"><p>%1$s</p><p><a href="%2$s">%3$s</a> &middot; <a href="%4$s">%5$s</a> &middot; <a href="%6$s">%7$s</a></p></div>',
				esc_html( $text ),
				esc_url(
					add_query_arg(
						array(
							'tab'      => 'usage',
							'provider' => rawurlencode( (string) $key ),
						),
						Page::url()
					)
				),
				esc_html__( 'See the posts', 'block-doctor' ),
				esc_url( Page::url() ),
				esc_html__( 'Open Block Doctor to fix them', 'block-doctor' ),
				esc_url( $dismiss ),
				esc_html__( 'Dismiss', 'block-doctor' )
			);
		}
	}

	/**
	 * Dismisses one notice.
	 */
	public function dismiss(): void {
		if ( ! $this->config->can_use() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to use Block Doctor.', 'block-doctor' ), 403 );
		}
		check_admin_referer( self::DISMISS );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified just above.
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$this->unaffect( $key );

		wp_safe_redirect( wp_get_referer() ? (string) wp_get_referer() : Page::url() );
		exit;
	}

	/**
	 * Loads the step shown before Deactivate and Delete on the Plugins screen.
	 */
	public function load_plugins_screen(): void {
		if ( $this->config->can_use() && $this->usage->ready() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_plugins_screen' ) );
		}
	}

	/**
	 * Enqueues the script that holds Deactivate and Delete for a plugin whose blocks are in use.
	 */
	public function enqueue_plugins_screen(): void {
		$this->providers->remember();

		$plugins = array();
		foreach ( $this->providers->groups() as $group ) {
			if ( 'plugin' === $group['provider']['type'] && '' !== $group['provider']['basename'] ) {
				$plugins[ $group['provider']['basename'] ] = count( $group['blocks'] );
			}
		}
		if ( ! $plugins ) {
			return;
		}

		$url = plugins_url( 'assets/', PLUGIN_FILE );
		AxleUi::enqueue();
		wp_enqueue_style( 'block-doctor-plugins', $url . 'plugins.css', array( AxleUi::HANDLE ), VERSION );
		wp_enqueue_script( 'block-doctor-plugins', $url . 'js/plugins.js', array( 'wp-api-fetch', 'wp-a11y', 'wp-hooks', 'wp-i18n' ), VERSION, true );
		wp_add_inline_script( 'block-doctor-plugins', 'window.blockDoctorPlugins = ' . wp_json_encode( array( 'plugins' => (object) $plugins ) ) . ';', 'before' );
		wp_set_script_translations( 'block-doctor-plugins', 'block-doctor' );
	}
}
