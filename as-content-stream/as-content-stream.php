<?php
/**
 * Plugin Name: AS Content Stream
 * Plugin URI: https://github.com/cchatterton/as-content-stream/releases/latest
 * Description: Network content stream queue and WPML capability discovery for AlphaSys multisite networks.
 * Version: 0.1.17
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: AlphaSys
 * Network: true
 * Update URI: https://github.com/cchatterton/as-content-stream
 * Text Domain: as-content-stream
 *
 * @package AS_Content_Stream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AS_CONTENT_STREAM_VERSION', '0.1.17' );
define( 'AS_CONTENT_STREAM_FILE', __FILE__ );
define( 'AS_CONTENT_STREAM_DIR', plugin_dir_path( __FILE__ ) );
define( 'AS_CONTENT_STREAM_URL', plugin_dir_url( __FILE__ ) );

require_once AS_CONTENT_STREAM_DIR . 'includes/class-as-content-stream-github-updater.php';
require_once AS_CONTENT_STREAM_DIR . 'includes/class-as-content-stream.php';

register_activation_hook( __FILE__, array( 'AS_Content_Stream', 'activate' ) );

add_action( 'plugins_loaded', array( 'AS_Content_Stream', 'instance' ) );
add_action( 'plugins_loaded', array( 'AS_Content_Stream_GitHub_Updater', 'instance' ) );
