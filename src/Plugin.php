<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\BlockDoctor
 */

namespace AryanX\Axle\BlockDoctor;

use AryanX\Axle\BlockDoctor\Admin\Dashboard;
use AryanX\Axle\BlockDoctor\Admin\Page;
use AryanX\Axle\BlockDoctor\Cli\Command;
use AryanX\Axle\BlockDoctor\Health\SiteHealth;
use AryanX\Axle\BlockDoctor\Rest\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the shared objects once and registers their hooks.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Defaults and limits.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Stored results.
	 *
	 * @var Issues
	 */
	private Issues $issues;

	/**
	 * Scans.
	 *
	 * @var Scanner
	 */
	private Scanner $scanner;

	/**
	 * Fix batches.
	 *
	 * @var Batches
	 */
	private Batches $batches;

	/**
	 * Saves fixes and undoes them.
	 *
	 * @var Fixer
	 */
	private Fixer $fixer;

	/**
	 * The report.
	 *
	 * @var Report
	 */
	private Report $report;

	/**
	 * Finds the posts.
	 *
	 * @var Posts
	 */
	private Posts $posts;

	/**
	 * Where each block type comes from.
	 *
	 * @var Providers
	 */
	private Providers $providers;

	/**
	 * The block usage index.
	 *
	 * @var Usage
	 */
	private Usage $usage;

	/**
	 * Warnings before a plugin that provides blocks is turned off.
	 *
	 * @var Deactivation
	 */
	private Deactivation $deactivation;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Stops a background server scan on deactivation. Results and batches stay.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Scanner::CRON );
		wp_clear_scheduled_hook( Usage::CRON );
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->config  = new Config();
		$this->posts   = new Posts( $this->config );
		$this->issues  = new Issues( $this->config );
		$this->scanner = new Scanner( $this->config, $this->posts, $this->issues, new Checks( $this->config ) );
		$this->batches = new Batches( $this->config );
		$this->fixer   = new Fixer( $this->posts, $this->issues, $this->batches, $this->scanner );
		$this->report  = new Report( $this->config, $this->issues, $this->scanner );

		$this->providers    = new Providers();
		$this->usage        = new Usage( $this->config, $this->posts, $this->providers );
		$this->deactivation = new Deactivation( $this->config, $this->usage, $this->providers );
	}

	/**
	 * Defaults and limits.
	 *
	 * @return Config
	 */
	public function config(): Config {
		return $this->config;
	}

	/**
	 * Stored results.
	 *
	 * @return Issues
	 */
	public function issues(): Issues {
		return $this->issues;
	}

	/**
	 * Scans.
	 *
	 * @return Scanner
	 */
	public function scanner(): Scanner {
		return $this->scanner;
	}

	/**
	 * Fix batches.
	 *
	 * @return Batches
	 */
	public function batches(): Batches {
		return $this->batches;
	}

	/**
	 * Saves fixes and undoes them.
	 *
	 * @return Fixer
	 */
	public function fixer(): Fixer {
		return $this->fixer;
	}

	/**
	 * The report.
	 *
	 * @return Report
	 */
	public function report(): Report {
		return $this->report;
	}

	/**
	 * Where each block type comes from.
	 *
	 * @return Providers
	 */
	public function providers(): Providers {
		return $this->providers;
	}

	/**
	 * The block usage index.
	 *
	 * @return Usage
	 */
	public function usage(): Usage {
		return $this->usage;
	}

	/**
	 * Warnings before a plugin that provides blocks is turned off.
	 *
	 * @return Deactivation
	 */
	public function deactivation(): Deactivation {
		return $this->deactivation;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		$services = array(
			$this->issues,
			$this->scanner,
			$this->usage,
			$this->deactivation,
			new Controller( $this->config, $this->posts, $this->issues, $this->scanner, $this->batches, $this->fixer, $this->report, $this->usage, $this->providers, $this->deactivation ),
			new Page( $this->config, $this->report, $this->usage ),
			new Dashboard( $this->config, $this->report ),
			new SiteHealth( $this->report, $this->usage, $this->deactivation ),
			new Abilities( $this->config, $this->posts, $this->issues, $this->scanner, $this->fixer, $this->report, $this->usage, $this->providers ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'block-doctor', new Command( $this->config, $this->issues, $this->scanner, $this->batches, $this->fixer, $this->report, $this->usage, $this->providers ) );
		}
	}
}
