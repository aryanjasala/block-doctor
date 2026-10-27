<?php
/**
 * Tests for the abilities.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor\Tests\Unit;

use AryanX\Axle\BlockDoctor\Abilities;
use Brain\Monkey\Functions;

final class AbilitiesTest extends PluginTestCase {

	/**
	 * Registered abilities by name.
	 *
	 * @var array<string, array>
	 */
	private $abilities = array();

	protected function setUp(): void {
		parent::setUp();
		$this->abilities = array();

		Functions\when( 'wp_register_ability' )->alias(
			function ( $name, $args ) {
				$this->abilities[ $name ] = $args;
				return true;
			}
		);
		Functions\when( 'wp_register_ability_category' )->justReturn( null );
		Functions\when( 'parse_blocks' )->justReturn( array( self::block( null, array(), array(), '<p>classic</p>' ) ) );
	}

	/**
	 * The abilities object, wired to the in-memory site.
	 *
	 * @return Abilities
	 */
	private function abilities(): Abilities {
		$plugin    = $this->plugin();
		$abilities = new Abilities( $plugin['config'], $plugin['posts'], $plugin['issues'], $plugin['scanner'], $plugin['fixer'], $plugin['report'] );
		$abilities->register();
		$abilities->register_category();
		$abilities->register_abilities();

		return $abilities;
	}

	public function test_four_public_abilities_with_the_right_annotations(): void {
		$abilities = $this->abilities();

		$this->assertNotFalse( has_action( 'wp_abilities_api_init', array( $abilities, 'register_abilities' ) ) );
		$this->assertSame( array( 'block-doctor/get-scan-summary', 'block-doctor/list-issues', 'block-doctor/run-server-scan', 'block-doctor/undo-batch' ), array_keys( $this->abilities ) );

		$annotations = array_map(
			static function ( $ability ) {
				return $ability['meta']['annotations'];
			},
			$this->abilities
		);
		$this->assertTrue( $annotations['block-doctor/get-scan-summary']['readonly'] );
		$this->assertTrue( $annotations['block-doctor/list-issues']['readonly'] );
		$this->assertSame( array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), $annotations['block-doctor/run-server-scan'] );
		$this->assertSame( array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ), $annotations['block-doctor/undo-batch'] );

		foreach ( $this->abilities as $ability ) {
			$this->assertTrue( $ability['meta']['public'] );
			$this->assertTrue( $ability['meta']['mcp']['public'] );
			$this->assertTrue( $ability['meta']['show_in_rest'] );
		}
		$this->assertArrayNotHasKey( 'input_schema', $this->abilities['block-doctor/get-scan-summary'], 'no input' );
		$this->assertContains( 'missing-media', $this->abilities['block-doctor/list-issues']['input_schema']['properties']['kind']['enum'] );
	}

	public function test_permission_is_the_block_doctor_capability(): void {
		$abilities = $this->abilities();
		$this->assertTrue( $abilities->can_use() );

		$this->can_use = false;
		$this->assertFalse( $abilities->can_use() );
	}

	public function test_an_agent_scans_one_post_lists_the_issues_and_reads_the_summary(): void {
		$abilities = $this->abilities();
		$post      = $this->add_post( 'classic', array( 'ID' => 5 ) );

		$scan = $abilities->run_server_scan( array( 'post_id' => 5 ) );
		$this->assertSame( array( 'classic' ), array_column( $scan['issues'], 'kind' ) );
		$this->assertArrayNotHasKey( 'cursor', $scan['state'] );

		$list = $abilities->list_issues( array( 'kind' => 'classic' ) );
		$this->assertSame( 1, $list['total'] );
		$this->assertSame( 5, $list['items'][0]['id'] );
		$this->assertArrayNotHasKey( 'type_label', $list['items'][0] );
		$this->assertSame( 0, $abilities->list_issues( array( 'kind' => 'invalid' ) )['total'] );

		$summary = $abilities->get_summary();
		$this->assertSame( 1, $summary['posts'] );
		$this->assertSame( 0, $summary['broken_posts'] );
		$this->assertArrayNotHasKey( 'cursor', $summary['browser'] );

		$this->forbidden = array( 5 );
		$this->assertInstanceOf( \WP_Error::class, $abilities->run_server_scan( array( 'post_id' => 5 ) ) );
	}

	public function test_a_whole_site_server_scan_starts_in_the_background(): void {
		$abilities = $this->abilities();
		$posts     = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$posts[] = $this->add_post( 'classic' );
		}
		\WP_Query::$queue = array( array( 'posts' => $posts ) );

		$result = $abilities->run_server_scan( array() );

		$this->assertSame( 'running', $result['state']['status'] );
		$this->assertSame( 20, $result['state']['done'] );
		$this->assertSame( array( 'block_doctor_server_scan' ), $this->scheduled );
		$this->assertInstanceOf( \WP_Error::class, $abilities->run_server_scan( array( 'post_type' => 'attachment' ) ) );
	}

	public function test_undo_batch_through_the_ability(): void {
		$abilities = $this->abilities();
		$this->assertSame( 'block_doctor_batch', $abilities->undo_batch( array( 'batch_id' => 9 ) )->get_error_code() );

		$plugin = $this->plugin();
		$batch  = $plugin['batches']->create( array( 'invalid' ) );
		$result = $abilities->undo_batch( array( 'batch_id' => $batch['id'] ) );
		$this->assertEquals( array( 'batch_id' => $batch['id'], 'undone' => true, 'restored' => array(), 'skipped' => (object) array(), 'remaining' => 0 ), $result );
	}
}
