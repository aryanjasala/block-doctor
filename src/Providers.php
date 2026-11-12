<?php
/**
 * Which plugin or theme provides each block type.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Works out where a block type comes from, from facts only, never a guess.
 *
 * A block registered in PHP is traced through the files it names: its editor
 * script, view script and stylesheets (their URLs sit inside a plugin or theme
 * folder), and the file of its render callback. A block that only exists in
 * JavaScript is traced through the script that registered it, which the
 * browser scan records. A block type nothing can be traced for stays unknown.
 *
 * What is found is remembered, so a block keeps its plugin after that plugin
 * is switched off, which is when someone needs to know.
 */
final class Providers {

	/**
	 * Option with the providers found for registered block types: name => provider.
	 */
	public const REMEMBERED = 'block_doctor_providers';

	/**
	 * Option with the providers of JavaScript-only blocks, from the browser scan: name => provider.
	 */
	public const JS_SOURCES = 'block_doctor_js_sources';

	/**
	 * Most block types kept in each option.
	 */
	private const LIMIT = 2000;

	/**
	 * Providers found in this request, by block name.
	 *
	 * @var array<string, array>
	 */
	private array $found = array();

	/**
	 * Installed plugins, loaded on first use.
	 *
	 * @var array<string, array>|null
	 */
	private ?array $plugins = null;

	/**
	 * The provider of a block type.
	 *
	 * @param string $name Block name, such as acme/gallery.
	 * @return array{type: string, slug: string, name: string, basename: string} `type` is core, plugin, mu-plugin, theme or unknown.
	 */
	public function for_block( string $name ): array {
		if ( ! isset( $this->found[ $name ] ) ) {
			$this->found[ $name ] = $this->resolve( $name );
		}

		/**
		 * Filters the plugin or theme a block type is attributed to.
		 *
		 * @param array  $provider `type` (core, plugin, mu-plugin, theme or unknown), `slug`, `name` (display name) and `basename` (plugin file, empty for themes).
		 * @param string $name     Block name.
		 */
		$filtered = (array) apply_filters( 'block_doctor_block_provider', $this->found[ $name ], $name );

		return $filtered ? self::clean( $filtered ) : $this->found[ $name ];
	}

	/**
	 * A stable key for a provider: `plugin:acme/acme.php`, `theme:twentytwentyfive`, `core` or `unknown`.
	 *
	 * @param array $provider Provider.
	 * @return string
	 */
	public static function key( array $provider ): string {
		$type = (string) ( $provider['type'] ?? 'unknown' );
		if ( 'core' === $type || 'unknown' === $type ) {
			return $type;
		}
		$id = 'theme' === $type || '' === (string) ( $provider['basename'] ?? '' ) ? (string) ( $provider['slug'] ?? '' ) : (string) $provider['basename'];

		return $type . ':' . $id;
	}

	/**
	 * Finds the provider of a file path or URL inside wp-content or core.
	 *
	 * @param string $location Absolute path, URL, or a path starting at the site root.
	 * @return array|null Provider, or null when the location is in none of the folders.
	 */
	public function locate( string $location ): ?array {
		$location = (string) strtok( trim( $location ), '?#' );
		if ( '' === $location ) {
			return null;
		}

		foreach ( $this->roots() as $root ) {
			foreach ( $root['bases'] as $base ) {
				$rest = self::after( $location, $base );
				if ( null === $rest ) {
					continue;
				}
				if ( 'core' === $root['type'] ) {
					return self::provider( 'core', 'core', __( 'WordPress', 'block-doctor' ), '' );
				}
				$segment = (string) strtok( $rest, '/' );
				if ( '' === $segment ) {
					continue;
				}

				$in_folder = false !== strpos( $rest, '/' );

				return $this->provider_in( $root['type'], $segment, $in_folder );
			}
		}

		return null;
	}

	/**
	 * Remembers the providers of the block types registered now, so they are still known after the plugin is off.
	 */
	public function remember(): void {
		$stored  = $this->option( self::REMEMBERED );
		$changed = false;

		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			$name = (string) $name;
			if ( 0 === strpos( $name, 'core/' ) || 0 === strpos( $name, 'core-embed/' ) ) {
				continue;
			}
			$provider = $this->for_block( $name );
			if ( 'unknown' === $provider['type'] || 'core' === $provider['type'] ) {
				continue;
			}
			if ( ( $stored[ $name ] ?? null ) !== $provider ) {
				$stored[ $name ] = $provider;
				$changed         = true;
			}
		}

		if ( $changed ) {
			update_option( self::REMEMBERED, array_slice( $stored, -self::LIMIT, null, true ), false );
		}
	}

	/**
	 * Stores where the browser scan saw JavaScript-only blocks registered.
	 *
	 * @param array<string, mixed> $sources Block name => URL of the script that registered it.
	 * @return int How many block types were attributed.
	 */
	public function record_sources( array $sources ): int {
		$stored   = $this->option( self::JS_SOURCES );
		$registry = \WP_Block_Type_Registry::get_instance();
		$count    = 0;

		foreach ( $sources as $name => $url ) {
			$name = (string) $name;
			if ( ! is_string( $url ) || ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name ) || $registry->is_registered( $name ) ) {
				continue;
			}
			$provider = $this->locate( $url );
			if ( $provider && ! in_array( $provider['type'], array( 'core', 'unknown' ), true ) ) {
				$stored[ $name ] = $provider;
				++$count;
			}
		}

		update_option( self::JS_SOURCES, array_slice( $stored, -self::LIMIT, null, true ), false );
		$this->found = array();

		return $count;
	}

	/**
	 * Every block type with a known plugin or theme, grouped by provider key.
	 *
	 * Counts the block types registered now and the ones remembered.
	 *
	 * @return array<string, array{provider: array, blocks: string[]}>
	 */
	public function groups(): array {
		$names = array_merge(
			array_map( 'strval', array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ) ),
			array_keys( $this->option( self::REMEMBERED ) ),
			array_keys( $this->option( self::JS_SOURCES ) )
		);

		$groups = array();
		foreach ( array_unique( $names ) as $name ) {
			$provider = $this->for_block( (string) $name );
			if ( in_array( $provider['type'], array( 'core', 'unknown' ), true ) ) {
				continue;
			}
			$key = self::key( $provider );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'provider' => $provider,
					'blocks'   => array(),
				);
			}
			$groups[ $key ]['blocks'][] = (string) $name;
		}

		foreach ( $groups as &$group ) {
			sort( $group['blocks'] );
		}

		return $groups;
	}

	/**
	 * The block types of one provider.
	 *
	 * @param string $key Provider key from key().
	 * @return string[]
	 */
	public function blocks_of( string $key ): array {
		return $this->groups()[ $key ]['blocks'] ?? array();
	}

	/**
	 * Works out one provider.
	 *
	 * @param string $name Block name.
	 * @return array
	 */
	private function resolve( string $name ): array {
		if ( 0 === strpos( $name, 'core/' ) || 0 === strpos( $name, 'core-embed/' ) ) {
			return self::provider( 'core', 'core', __( 'WordPress', 'block-doctor' ), '' );
		}

		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		if ( $type ) {
			foreach ( $this->locations( $type ) as $location ) {
				$provider = $this->locate( $location );
				if ( $provider && 'core' !== $provider['type'] ) {
					return $provider;
				}
			}
		}

		foreach ( array( self::REMEMBERED, self::JS_SOURCES ) as $option ) {
			$stored = $this->option( $option );
			if ( isset( $stored[ $name ] ) && is_array( $stored[ $name ] ) ) {
				return self::clean( $stored[ $name ] );
			}
		}

		return self::provider( 'unknown', '', '', '' );
	}

	/**
	 * Files and URLs a registered block type names: its scripts, its stylesheets and the file of its render callback.
	 *
	 * @param object $type Block type.
	 * @return string[]
	 */
	private function locations( $type ): array {
		$locations = array();

		foreach ( array( 'editor_script_handles', 'script_handles', 'view_script_handles' ) as $property ) {
			foreach ( (array) ( $type->$property ?? array() ) as $handle ) {
				$src = $this->source( (string) $handle, 'script' );
				if ( '' !== $src ) {
					$locations[] = $src;
				}
			}
		}
		foreach ( array( 'style_handles', 'editor_style_handles', 'view_style_handles' ) as $property ) {
			foreach ( (array) ( $type->$property ?? array() ) as $handle ) {
				$src = $this->source( (string) $handle, 'style' );
				if ( '' !== $src ) {
					$locations[] = $src;
				}
			}
		}

		$file = self::callback_file( $type->render_callback ?? null );
		if ( '' !== $file ) {
			$locations[] = $file;
		}

		return $locations;
	}

	/**
	 * The URL a script or style handle was registered with.
	 *
	 * @param string $handle Handle.
	 * @param string $kind   `script` or `style`.
	 * @return string
	 */
	private function source( string $handle, string $kind ): string {
		if ( '' === $handle ) {
			return '';
		}
		$registered = 'script' === $kind ? wp_scripts()->registered : wp_styles()->registered;
		$src        = isset( $registered[ $handle ] ) ? $registered[ $handle ]->src : '';

		return is_string( $src ) ? $src : '';
	}

	/**
	 * The file a callback is written in.
	 *
	 * @param mixed $callback A callable, or anything else.
	 * @return string Path, or '' when there is none.
	 */
	private static function callback_file( $callback ): string {
		if ( ! is_callable( $callback ) ) {
			return '';
		}

		try {
			if ( is_array( $callback ) ) {
				$reflection = new \ReflectionMethod( $callback[0], (string) $callback[1] );
			} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$reflection = new \ReflectionMethod( $callback );
			} else {
				$reflection = new \ReflectionFunction( \Closure::fromCallable( $callback ) );
			}
			$file = $reflection->getFileName();
		} catch ( \ReflectionException $error ) {
			return '';
		}

		return is_string( $file ) ? $file : '';
	}

	/**
	 * Folders and URLs that belong to core, plugins, must-use plugins and themes.
	 *
	 * @return array<int, array{type: string, bases: string[]}>
	 */
	private function roots(): array {
		$roots = array(
			'plugin'    => array( plugins_url(), WP_PLUGIN_DIR ),
			'mu-plugin' => array( content_url( '/mu-plugins' ), defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '' ),
			'theme'     => array( get_theme_root_uri(), get_theme_root() ),
			'core'      => array( includes_url(), ABSPATH . 'wp-includes', admin_url(), ABSPATH . 'wp-admin' ),
		);

		$list = array();
		foreach ( $roots as $type => $bases ) {
			$list[] = array(
				'type'  => $type,
				'bases' => array_values( array_filter( array_map( 'strval', $bases ) ) ),
			);
		}

		return $list;
	}

	/**
	 * What is left of a location after a base folder or URL, or null when it is not inside it.
	 *
	 * @param string $location Path or URL.
	 * @param string $base     Base folder or URL.
	 * @return string|null
	 */
	private static function after( string $location, string $base ): ?string {
		$normalize = static function ( string $value ): string {
			$value = wp_normalize_path( $value );

			return rtrim( (string) preg_replace( '#^https?:#i', '', $value ), '/' );
		};
		$location  = $normalize( $location );
		$base      = $normalize( $base );

		if ( 0 === strpos( $location, $base . '/' ) ) {
			return substr( $location, strlen( $base ) + 1 );
		}

		// A URL without the host, as scripts are sometimes registered: /wp-content/plugins/acme/block.js.
		$path = (string) wp_parse_url( 'http:' . $base, PHP_URL_PATH );
		if ( '' !== $path && '/' !== $path && 0 === strpos( $location, $path . '/' ) && 0 !== strpos( $location, '//' ) ) {
			return substr( $location, strlen( $path ) + 1 );
		}

		return null;
	}

	/**
	 * A plugin or theme from the first folder name inside its root.
	 *
	 * @param string $type    `plugin`, `mu-plugin` or `theme`.
	 * @param string $segment Folder name, or the file name for a single-file plugin.
	 * @param bool   $folder  Whether the segment is a folder.
	 * @return array
	 */
	private function provider_in( string $type, string $segment, bool $folder ): array {
		if ( 'theme' === $type ) {
			$theme = wp_get_theme( $segment );

			return self::provider( 'theme', $segment, $theme->exists() ? (string) $theme->get( 'Name' ) : $segment, '' );
		}

		if ( 'mu-plugin' === $type ) {
			return self::provider( 'mu-plugin', $segment, $segment, '' );
		}

		foreach ( $this->plugins() as $basename => $data ) {
			$inside = $folder ? 0 === strpos( (string) $basename, $segment . '/' ) : (string) $basename === $segment;
			if ( $inside ) {
				return self::provider( 'plugin', $folder ? $segment : (string) preg_replace( '/\.php$/', '', $segment ), (string) ( $data['Name'] ?? $segment ), (string) $basename );
			}
		}

		return self::provider( 'plugin', $segment, $segment, '' );
	}

	/**
	 * Installed plugins, by basename.
	 *
	 * @return array<string, array>
	 */
	private function plugins(): array {
		if ( null === $this->plugins ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$this->plugins = get_plugins();
		}

		return $this->plugins;
	}

	/**
	 * A stored option as an array.
	 *
	 * @param string $name Option.
	 * @return array<string, mixed>
	 */
	private function option( string $name ): array {
		$value = get_option( $name, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * A provider array.
	 *
	 * @param string $type     Type.
	 * @param string $slug     Folder or file slug.
	 * @param string $name     Display name.
	 * @param string $basename Plugin file.
	 * @return array{type: string, slug: string, name: string, basename: string}
	 */
	private static function provider( string $type, string $slug, string $name, string $basename ): array {
		return array(
			'type'     => $type,
			'slug'     => $slug,
			'name'     => $name,
			'basename' => $basename,
		);
	}

	/**
	 * A provider with every field a string and the type one of the known ones.
	 *
	 * @param array $provider Provider.
	 * @return array{type: string, slug: string, name: string, basename: string}
	 */
	private static function clean( array $provider ): array {
		$type = (string) ( $provider['type'] ?? 'unknown' );

		return self::provider(
			in_array( $type, array( 'core', 'plugin', 'mu-plugin', 'theme' ), true ) ? $type : 'unknown',
			sanitize_text_field( (string) ( $provider['slug'] ?? '' ) ),
			sanitize_text_field( (string) ( $provider['name'] ?? '' ) ),
			sanitize_text_field( (string) ( $provider['basename'] ?? '' ) )
		);
	}
}
