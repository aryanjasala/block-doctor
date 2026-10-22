<?php
/**
 * Tests for the bootstrap.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor\Tests\Unit;

use AryanX\BlockDoctor\Plugin;
use AryanX\BlockDoctor\Scanner;

final class PluginTest extends PluginTestCase {

	public function test_boot_registers_every_service_once(): void {
		Plugin::boot();
		$plugin = Plugin::instance();
		Plugin::boot();

		$this->assertSame( $plugin, Plugin::instance() );
		$this->assertNotNull( $plugin );
		foreach ( array( 'deleted_post', 'rest_api_init', 'admin_menu', 'wp_dashboard_setup', Scanner::CRON ) as $hook ) {
			$this->assertTrue( has_action( $hook ), $hook );
		}
		$this->assertTrue( has_filter( 'site_status_tests' ) );
		$this->assertSame( 20, $plugin->config()->batch_size() );
		$this->assertSame( array(), $plugin->issues()->index() );
		$this->assertSame( 'idle', $plugin->scanner()->state( 'browser' )['status'] );
		$this->assertSame( array(), $plugin->batches()->all() );
		$this->assertSame( 0, $plugin->report()->summary()['posts'] );
		$this->assertInstanceOf( \AryanX\BlockDoctor\Fixer::class, $plugin->fixer() );
	}

	public function test_deactivation_stops_a_background_scan(): void {
		Plugin::deactivate();

		$this->assertSame( array( Scanner::CRON ), $this->cleared );
	}
}
