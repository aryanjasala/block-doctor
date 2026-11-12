<?php
/**
 * Tests for finding the plugin or theme behind a block type.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor\Tests\Unit;

use AryanX\Axle\BlockDoctor\Providers;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * A render callback in a known place, for the tests.
 */
function bd_test_render() {
}

final class ProvidersTest extends PluginTestCase {

	/**
	 * Scripts and styles by handle.
	 *
	 * @var array<string, string>
	 */
	private $sources = array();

	protected function setUp(): void {
		parent::setUp();
		$this->sources = array();

		Functions\when( 'plugins_url' )->justReturn( 'https://example.com/wp-content/plugins' );
		Functions\when( 'content_url' )->justReturn( 'https://example.com/wp-content/mu-plugins' );
		Functions\when( 'get_theme_root_uri' )->justReturn( 'https://example.com/wp-content/themes' );
		Functions\when( 'get_theme_root' )->justReturn( '/var/www/html/wp-content/themes' );
		Functions\when( 'includes_url' )->justReturn( 'https://example.com/wp-includes/' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'wp_normalize_path' )->alias( static fn( $path ) => str_replace( '\\', '/', (string) $path ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
		Functions\when( 'wp_scripts' )->alias( fn() => (object) array( 'registered' => array_map( static fn( $src ) => (object) array( 'src' => $src ), $this->sources ) ) );
		Functions\when( 'wp_styles' )->alias( fn() => (object) array( 'registered' => array_map( static fn( $src ) => (object) array( 'src' => $src ), $this->sources ) ) );
		Functions\when( 'get_plugins' )->justReturn(
			array(
				'acme-blocks/acme-blocks.php' => array( 'Name' => 'Acme Blocks' ),
				'single.php'                  => array( 'Name' => 'Single File' ),
			)
		);
		Functions\when( 'wp_get_theme' )->alias(
			static fn( $slug ) => new class( (string) $slug ) {
				private $slug;
				public function __construct( $slug ) {
					$this->slug = $slug;
				}
				public function exists() {
					return 'acme-theme' === $this->slug;
				}
				public function get( $key ) {
					return 'Acme Theme';
				}
			}
		);
	}

	public function test_a_location_inside_a_plugin_folder_names_the_plugin(): void {
		$providers = new Providers();

		$this->assertSame(
			array( 'type' => 'plugin', 'slug' => 'acme-blocks', 'name' => 'Acme Blocks', 'basename' => 'acme-blocks/acme-blocks.php' ),
			$providers->locate( 'https://example.com/wp-content/plugins/acme-blocks/build/index.js?ver=1.2' )
		);
		$this->assertSame( 'acme-blocks/acme-blocks.php', $providers->locate( '/var/www/html/wp-content/plugins/acme-blocks/src/render.php' )['basename'] );
		$this->assertSame( 'acme-blocks/acme-blocks.php', $providers->locate( 'http://example.com/wp-content/plugins/acme-blocks/x.js' )['basename'], 'the scheme does not matter' );
		$this->assertSame( 'acme-blocks/acme-blocks.php', $providers->locate( '/wp-content/plugins/acme-blocks/x.js' )['basename'], 'a URL without the host' );
		$this->assertSame( 'single.php', $providers->locate( 'https://example.com/wp-content/plugins/single.php' )['basename'] );
	}

	public function test_a_plugin_not_installed_is_still_named_by_its_folder(): void {
		$provider = ( new Providers() )->locate( 'https://example.com/wp-content/plugins/removed-plugin/a.js' );

		$this->assertSame( array( 'plugin', 'removed-plugin', 'removed-plugin', '' ), array( $provider['type'], $provider['slug'], $provider['name'], $provider['basename'] ) );
	}

	public function test_themes_must_use_plugins_and_core_are_told_apart(): void {
		$providers = new Providers();

		$this->assertSame( array( 'theme', 'acme-theme', 'Acme Theme' ), array_values( array_intersect_key( $providers->locate( 'https://example.com/wp-content/themes/acme-theme/blocks/a.js' ), array_flip( array( 'type', 'slug', 'name' ) ) ) ) );
		$this->assertSame( 'mu-plugin', $providers->locate( 'https://example.com/wp-content/mu-plugins/shared/a.js' )['type'] );
		$this->assertSame( 'core', $providers->locate( 'https://example.com/wp-includes/js/dist/blocks.js' )['type'] );
		$this->assertSame( 'core', $providers->locate( 'https://example.com/wp-admin/js/x.js' )['type'] );
		$this->assertNull( $providers->locate( 'https://cdn.example.org/x.js' ) );
		$this->assertNull( $providers->locate( '' ) );
	}

	public function test_a_registered_block_is_traced_through_its_scripts_and_styles(): void {
		\WP_Block_Type_Registry::$names          = array( 'acme/slider', 'acme/card' );
		\WP_Block_Type_Registry::$editor_scripts = array( 'acme/slider' => array( 'acme-slider-editor' ) );
		\WP_Block_Type_Registry::$props          = array( 'acme/card' => array( 'style_handles' => array( 'acme-card-style' ) ) );
		$this->sources                           = array(
			'acme-slider-editor' => 'https://example.com/wp-content/plugins/acme-blocks/slider/index.js',
			'acme-card-style'    => 'https://example.com/wp-content/themes/acme-theme/card.css',
		);
		$providers = new Providers();

		$this->assertSame( 'acme-blocks/acme-blocks.php', $providers->for_block( 'acme/slider' )['basename'] );
		$this->assertSame( 'theme', $providers->for_block( 'acme/card' )['type'] );
		$this->assertSame( 'plugin:acme-blocks/acme-blocks.php', Providers::key( $providers->for_block( 'acme/slider' ) ) );
		$this->assertSame( 'theme:acme-theme', Providers::key( $providers->for_block( 'acme/card' ) ) );
	}

	public function test_a_render_callback_in_a_plugin_file_is_a_last_resort_and_core_files_are_ignored(): void {
		\WP_Block_Type_Registry::$names = array( 'acme/dynamic' );
		\WP_Block_Type_Registry::$props = array( 'acme/dynamic' => array( 'render_callback' => __NAMESPACE__ . '\\bd_test_render' ) );

		// This test file is in no plugin folder, so nothing is found and the block stays unknown.
		$this->assertSame( 'unknown', ( new Providers() )->for_block( 'acme/dynamic' )['type'] );
	}

	public function test_core_blocks_are_core_and_what_nothing_can_trace_stays_unknown(): void {
		\WP_Block_Type_Registry::$names = array( 'core/image', 'acme/mystery' );
		$providers                      = new Providers();

		$this->assertSame( 'core', $providers->for_block( 'core/image' )['type'] );
		$this->assertSame( 'core', $providers->for_block( 'core-embed/youtube' )['type'] );
		$this->assertSame( 'unknown', $providers->for_block( 'acme/mystery' )['type'] );
		$this->assertSame( 'unknown', $providers->for_block( 'acme/never-seen' )['type'] );
		$this->assertSame( 'unknown', Providers::key( $providers->for_block( 'acme/mystery' ) ) );
	}

	public function test_what_is_found_is_remembered_so_a_block_keeps_its_plugin_after_the_plugin_is_off(): void {
		\WP_Block_Type_Registry::$names          = array( 'core/image', 'acme/slider' );
		\WP_Block_Type_Registry::$editor_scripts = array( 'acme/slider' => array( 'acme-slider-editor' ) );
		$this->sources                           = array( 'acme-slider-editor' => 'https://example.com/wp-content/plugins/acme-blocks/slider/index.js' );
		( new Providers() )->remember();

		$this->assertSame( array( 'acme/slider' ), array_keys( $this->options[ Providers::REMEMBERED ] ) );

		// The plugin is switched off: the block is not registered, and nothing names a script any more.
		\WP_Block_Type_Registry::$names = array( 'core/image' );
		$later                          = new Providers();
		$this->assertSame( 'acme-blocks/acme-blocks.php', $later->for_block( 'acme/slider' )['basename'] );
		$this->assertSame( array( 'acme/slider' ), $later->blocks_of( 'plugin:acme-blocks/acme-blocks.php' ) );
		$this->assertSame( array( 'plugin:acme-blocks/acme-blocks.php' ), array_keys( $later->groups() ) );
		$this->assertSame( array(), $later->blocks_of( 'plugin:other/other.php' ) );
	}

	public function test_scripts_the_browser_scan_saw_name_the_plugin_of_a_javascript_only_block(): void {
		\WP_Block_Type_Registry::$names = array( 'core/image' );
		$providers                      = new Providers();

		$count = $providers->record_sources(
			array(
				'acme/js-block'      => 'https://example.com/wp-content/plugins/acme-blocks/js/block.js?ver=3',
				'acme/inline'        => '',
				'acme/elsewhere'     => 'https://cdn.example.org/block.js',
				'core/image'         => 'https://example.com/wp-content/plugins/acme-blocks/x.js',
				'Bad Name'           => 'https://example.com/wp-content/plugins/acme-blocks/x.js',
				'acme/from-core-js'  => 'https://example.com/wp-includes/js/x.js',
			)
		);

		$this->assertSame( 1, $count );
		$this->assertSame( array( 'acme/js-block' ), array_keys( $this->options[ Providers::JS_SOURCES ] ) );
		$this->assertSame( 'Acme Blocks', $providers->for_block( 'acme/js-block' )['name'] );
		$this->assertSame( 'unknown', $providers->for_block( 'acme/inline' )['type'] );
	}

	public function test_a_filter_can_attribute_a_block_by_hand(): void {
		Filters\expectApplied( 'block_doctor_block_provider' )->andReturnUsing(
			static fn( $provider, $name ) => 'acme/mystery' === $name ? array( 'type' => 'plugin', 'slug' => 'acme', 'name' => 'Acme', 'basename' => 'acme/acme.php' ) : $provider
		);
		$provider = ( new Providers() )->for_block( 'acme/mystery' );

		$this->assertSame( 'Acme', $provider['name'] );
		$this->assertSame( 'plugin:acme/acme.php', Providers::key( $provider ) );
	}
}
