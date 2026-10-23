<?php
/**
 * Tests for the defaults and their filters.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor\Tests\Unit;

use AryanX\BlockDoctor\Config;
use Brain\Monkey\Filters;

final class ConfigTest extends PluginTestCase {

	public function test_the_capability_takes_a_filter_and_falls_back(): void {
		$config = new Config();
		$this->assertSame( 'edit_others_posts', $config->capability() );

		Filters\expectApplied( 'block_doctor_capability' )->once()->with( 'edit_others_posts' )->andReturn( 'manage_options' );
		$this->assertSame( 'manage_options', $config->capability() );

		Filters\expectApplied( 'block_doctor_capability' )->twice()->andReturn( '', array( 'x' ) );
		$this->assertSame( 'edit_others_posts', $config->capability() );
		$this->assertSame( 'edit_others_posts', $config->capability() );
	}

	public function test_can_use_checks_the_capability(): void {
		$config = new Config();
		$this->assertTrue( $config->can_use() );

		$this->can_use = false;
		$this->assertFalse( $config->can_use() );
	}

	public function test_post_types_are_editor_types_plus_patterns_and_templates_but_never_menus_or_styles(): void {
		$this->assertSame( array( 'post', 'page', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'note' ), ( new Config() )->post_types() );
	}

	public function test_the_post_types_filter_can_narrow_but_not_add_unknown_or_blockless_types(): void {
		Filters\expectApplied( 'block_doctor_post_types' )
			->once()
			->with( array( 'post', 'page', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'note' ) )
			->andReturn( array( 'page', 'product', 'attachment', 'wp_global_styles', 'page' ) );

		$this->assertSame( array( 'page' ), ( new Config() )->post_types() );
	}

	public function test_post_statuses_take_a_filter_and_drop_unknown_ones(): void {
		$config = new Config();
		$this->assertSame( array( 'publish', 'future', 'draft', 'pending', 'private' ), $config->post_statuses() );

		Filters\expectApplied( 'block_doctor_post_statuses' )->once()->andReturn( array( 'publish', 'archived', 'inherit', 'auto-draft', 'trash' ) );
		$this->assertSame( array( 'publish', 'trash' ), $config->post_statuses() );

		Filters\expectApplied( 'block_doctor_post_statuses' )->once()->andReturn( array( 'nope' ) );
		$this->assertSame( array( 'publish', 'future', 'draft', 'pending', 'private' ), $config->post_statuses(), 'nothing valid left falls back to the default' );
	}

	public function test_numbers_take_filters_and_are_clamped(): void {
		$config = new Config();
		$this->assertSame( 20, $config->batch_size() );
		$this->assertSame( 30, $config->undo_days() );
		$this->assertSame( 20, $config->cron_time_limit() );

		Filters\expectApplied( 'block_doctor_batch_size' )->once()->with( 20 )->andReturn( 500 );
		Filters\expectApplied( 'block_doctor_undo_days' )->once()->with( 30 )->andReturn( 0 );
		Filters\expectApplied( 'block_doctor_cron_time_limit' )->once()->with( 20 )->andReturn( 'lots' );

		$this->assertSame( 100, $config->batch_size() );
		$this->assertSame( 1, $config->undo_days() );
		$this->assertSame( 5, $config->cron_time_limit() );
	}

	public function test_the_fix_strategy_is_per_block_type(): void {
		$config = new Config();
		$this->assertSame( 'recover', $config->fix_strategy( 'core/paragraph' ) );

		Filters\expectApplied( 'block_doctor_fix_strategy' )->once()->with( 'recover', 'acme/slider' )->andReturn( 'html' );
		$this->assertSame( 'html', $config->fix_strategy( 'acme/slider' ) );

		Filters\expectApplied( 'block_doctor_fix_strategy' )->once()->with( 'recover', '' )->andReturn( 'delete' );
		$this->assertSame( 'recover', $config->fix_strategy( '' ), 'anything but html recovers' );
	}

	public function test_the_date_format_comes_from_the_site_settings_and_a_filter(): void {
		$this->options['date_format'] = 'Y-m-d';
		$this->options['time_format'] = 'H:i';
		$config                       = new Config();

		$this->assertSame( 'Y-m-d H:i', $config->date_format() );

		Filters\expectApplied( 'block_doctor_date_format' )->once()->with( 'Y-m-d H:i' )->andReturn( 'j M Y' );
		$this->assertSame( 'j M Y', $config->date_format() );
	}

	public function test_a_bad_date_format_filter_falls_back_to_the_site_settings(): void {
		$this->options['date_format'] = 'd/m/Y';
		$this->options['time_format'] = 'g:i a';
		Filters\expectApplied( 'block_doctor_date_format' )->once()->andReturn( array( 'nope' ) );

		$this->assertSame( 'd/m/Y g:i a', ( new Config() )->date_format() );
	}

	public function test_issue_kinds_can_be_added_and_removed_but_only_the_built_in_ones_are_fixable(): void {
		$config = new Config();
		$kinds  = $config->issue_kinds();
		$this->assertSame( array( 'invalid', 'missing', 'classic', 'deprecated', 'empty', 'pattern-ref', 'navigation-ref', 'missing-media', 'error' ), array_keys( $kinds ) );
		$this->assertFalse( $kinds['error']['broken'], 'a post the scan could not check is not counted as broken' );
		$this->assertSame( '', $kinds['error']['fix'] );
		$this->assertSame( 'browser', $kinds['invalid']['fix'] );
		$this->assertSame( '', $kinds['empty']['fix'] );
		$this->assertFalse( $kinds['classic']['broken'] );

		Filters\expectApplied( 'block_doctor_issue_kinds' )->once()->andReturnUsing(
			static function ( array $kinds ): array {
				unset( $kinds['classic'] );
				$kinds['Shortcode']             = array(
					'label'  => 'Shortcode',
					'fix'    => 'browser',
					'broken' => 1,
				);
				$kinds['invalid']['label']      = 'Broken';
				$kinds['no-label']              = array();
				$kinds['junk']                  = 'not an array';
				$kinds['deprecated']['fix']     = '';
				return $kinds;
			}
		);
		$kinds = $config->issue_kinds();

		$this->assertArrayNotHasKey( 'classic', $kinds );
		$this->assertArrayNotHasKey( 'junk', $kinds );
		$this->assertSame( 'Broken', $kinds['invalid']['label'] );
		$this->assertSame( array( 'label' => 'Shortcode', 'description' => '', 'fix' => '', 'broken' => true ), $kinds['shortcode'] );
		$this->assertSame( 'no-label', $kinds['no-label']['label'] );
		$this->assertSame( 'browser', $kinds['deprecated']['fix'], 'a filter cannot change what the browser can fix' );
	}
}
