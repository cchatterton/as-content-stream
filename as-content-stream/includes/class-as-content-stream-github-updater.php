<?php
/**
 * GitHub release updater.
 *
 * @package AS_Content_Stream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds WordPress-native update checks for GitHub release assets.
 */
class AS_Content_Stream_GitHub_Updater {
	const OWNER             = 'cchatterton';
	const REPO              = 'as-content-stream';
	const SLUG              = 'as-content-stream';
	const ASSET_NAME        = 'as-content-stream.zip';
	const RELEASE_TRANSIENT = 'as_content_stream_github_latest_release';
	const ERROR_TRANSIENT   = 'as_content_stream_github_latest_release_error';
	const CHECK_ACTION      = 'as_content_stream_check_updates';

	/**
	 * Singleton instance.
	 *
	 * @var AS_Content_Stream_GitHub_Updater|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return AS_Content_Stream_GitHub_Updater
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'add_update_data' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'add_update_data' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 20, 3 );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'handle_manual_check' ) );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_update' ), 10, 2 );
	}

	/**
	 * Add update information to WordPress plugin update transient.
	 *
	 * @param object|false $transient Update transient.
	 * @return object|false
	 */
	public function add_update_data( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$transient->response  = isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
		$transient->no_update = isset( $transient->no_update ) && is_array( $transient->no_update ) ? $transient->no_update : array();

		$plugin_file = plugin_basename( AS_CONTENT_STREAM_FILE );
		$release     = $this->get_latest_release( $this->is_forced_check() );

		unset( $transient->response[ $plugin_file ] );
		unset( $transient->no_update[ $plugin_file ] );

		if ( empty( $release['version'] ) || empty( $release['download_url'] ) ) {
			return $transient;
		}

		if ( version_compare( $release['version'], AS_CONTENT_STREAM_VERSION, '>' ) ) {
			$transient->response[ $plugin_file ] = (object) array(
				'id'          => $this->repo_url(),
				'slug'        => self::SLUG,
				'plugin'      => $plugin_file,
				'new_version' => $release['version'],
				'url'         => $release['release_url'],
				'package'     => $release['download_url'],
				'requires'    => '6.0',
				'requires_php'=> '7.4',
			);
		}

		return $transient;
	}

	/**
	 * Supply plugin details modal content.
	 *
	 * @param mixed  $result Existing result.
	 * @param string $action API action.
	 * @param object $args API args.
	 * @return mixed
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release( false );
		if ( empty( $release['version'] ) || empty( $release['download_url'] ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'AS Content Stream',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'AlphaSys',
			'homepage'      => $this->repo_url(),
			'download_link' => $release['download_url'],
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'sections'      => array(
				'description' => 'Network content stream queue and WPML capability discovery for AlphaSys multisite networks.',
				'changelog'   => ! empty( $release['body'] ) ? wp_kses_post( $release['body'] ) : '',
			),
		);
	}

	/**
	 * Add GitHub metadata links to the plugin row.
	 *
	 * @param string[] $links Existing links.
	 * @param string   $file Plugin file.
	 * @return string[]
	 */
	public function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( AS_CONTENT_STREAM_FILE ) !== $file || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$plugins_url = is_multisite() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
		$check_url   = wp_nonce_url( add_query_arg( self::CHECK_ACTION, '1', $plugins_url ), self::CHECK_ACTION );

		$links[] = '<a href="' . esc_url( $this->repo_url() ) . '">' . esc_html__( 'GitHub', 'as-content-stream' ) . '</a>';
		$links[] = '<a href="' . esc_url( $check_url ) . '">' . esc_html__( 'Check for updates', 'as-content-stream' ) . '</a>';

		return $links;
	}

	/**
	 * Handle manual plugin update checks.
	 *
	 * @return void
	 */
	public function handle_manual_check() {
		if ( empty( $_GET[ self::CHECK_ACTION ] ) ) {
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to check for plugin updates.', 'as-content-stream' ) );
		}

		check_admin_referer( self::CHECK_ACTION );
		$this->clear_release_cache();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		wp_safe_redirect( is_multisite() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Clear caches after this plugin updates.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options Update options.
	 * @return void
	 */
	public function clear_cache_after_update( $upgrader, $options ) {
		if ( empty( $options['plugins'] ) || ! is_array( $options['plugins'] ) ) {
			return;
		}

		if ( in_array( plugin_basename( AS_CONTENT_STREAM_FILE ), $options['plugins'], true ) ) {
			$this->clear_release_cache();
		}
	}

	/**
	 * Fetch latest GitHub release details.
	 *
	 * @param bool $force Force refresh.
	 * @return array<string,string>
	 */
	private function get_latest_release( $force ) {
		if ( $force ) {
			$this->clear_release_cache();
		}

		$cached = get_site_transient( self::RELEASE_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::OWNER . '/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'AS-Content-Stream/' . AS_CONTENT_STREAM_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->store_error( 'wp_error', $response->get_error_message() );
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			$this->store_error( 'http_error', 'GitHub returned HTTP ' . (int) $code );
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			$this->store_error( 'json_error', 'GitHub release response could not be parsed.' );
			return array();
		}

		$release = $this->parse_release( $data );
		if ( empty( $release ) ) {
			return array();
		}

		$ttl = version_compare( $release['version'], AS_CONTENT_STREAM_VERSION, '>' ) ? 6 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
		set_site_transient( self::RELEASE_TRANSIENT, $release, $ttl );
		delete_site_transient( self::ERROR_TRANSIENT );

		return $release;
	}

	/**
	 * Parse release API data.
	 *
	 * @param array<string,mixed> $data Release data.
	 * @return array<string,string>
	 */
	private function parse_release( $data ) {
		$version = isset( $data['tag_name'] ) ? ltrim( (string) $data['tag_name'], 'vV' ) : '';
		if ( '' === $version || empty( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
			return array();
		}

		foreach ( $data['assets'] as $asset ) {
			if ( self::ASSET_NAME === ( $asset['name'] ?? '' ) && ! empty( $asset['browser_download_url'] ) ) {
				return array(
					'version'      => sanitize_text_field( $version ),
					'release_url'  => esc_url_raw( (string) ( $data['html_url'] ?? $this->repo_url() ) ),
					'download_url' => esc_url_raw( (string) $asset['browser_download_url'] ),
					'body'         => isset( $data['body'] ) ? (string) $data['body'] : '',
				);
			}
		}

		return array();
	}

	/**
	 * Check whether WordPress is forcing an update refresh.
	 *
	 * @return bool
	 */
	private function is_forced_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return false;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		return isset( $_REQUEST['force-check'] )
			|| in_array( $action, array( 'update-selected', 'upgrade-plugin', 'do-plugin-upgrade' ), true );
	}

	/**
	 * Store short-lived lookup diagnostics.
	 *
	 * @param string $type Error type.
	 * @param string $message Error message.
	 * @return void
	 */
	private function store_error( $type, $message ) {
		set_site_transient(
			self::ERROR_TRANSIENT,
			array(
				'type'       => sanitize_key( $type ),
				'message'    => sanitize_text_field( $message ),
				'checked_at' => current_time( 'mysql' ),
			),
			10 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Clear release and diagnostic caches.
	 *
	 * @return void
	 */
	private function clear_release_cache() {
		delete_site_transient( self::RELEASE_TRANSIENT );
		delete_site_transient( self::ERROR_TRANSIENT );
	}

	/**
	 * Get repository URL.
	 *
	 * @return string
	 */
	private function repo_url() {
		return 'https://github.com/' . self::OWNER . '/' . self::REPO;
	}
}
