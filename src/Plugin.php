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
	 * Registers the services.
	 */
	private function register(): void {
		$services = array(
			$this->issues,
			$this->scanner,
			new Controller( $this->config, $this->posts, $this->issues, $this->scanner, $this->batches, $this->fixer, $this->report ),
			new Page( $this->config, $this->report ),
			new Dashboard( $this->config, $this->report ),
			new SiteHealth( $this->report ),
			new Abilities( $this->config, $this->posts, $this->issues, $this->scanner, $this->fixer, $this->report ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'block-doctor', new Command( $this->config, $this->issues, $this->scanner, $this->batches, $this->fixer, $this->report ) );
		}
	}
}
