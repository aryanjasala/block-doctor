<?php
/**
 * Contract for classes that hook into WordPress.
 *
 * @package AryanX\BlockDoctor
 */

namespace AryanX\BlockDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * A part of the plugin that adds its own actions and filters.
 */
interface Service {

	/**
	 * Adds the service's hooks.
	 */
	public function register(): void;
}
