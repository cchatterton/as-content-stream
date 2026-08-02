<?php
/**
 * Core plugin class.
 *
 * @package AS_Content_Stream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content Stream bootstrap, admin UI, site discovery, and queue capture.
 */
class AS_Content_Stream {
	const OPTION_TARGET_LANGUAGE = 'as_content_stream_target_language';
	const OPTION_CAPTURE_STATUS  = 'as_content_stream_capture_status';
	const OPTION_PROCESSING_ENABLED = 'as_content_stream_processing_enabled';
	const OPTION_HEARTBEAT_SECONDS = 'as_content_stream_heartbeat_seconds';
	const OPTION_TELEMETRY       = 'as_content_stream_telemetry';
	const OPTION_TARGET_SIGNATURE = 'as_content_stream_target_signature';
	const NONCE_SETTINGS         = 'as_content_stream_settings';
	const NONCE_QUEUE            = 'as_content_stream_queue';
	const NONCE_LOG              = 'as_content_stream_log';
	const NONCE_PROCESSING       = 'as_content_stream_processing';
	const NONCE_HEARTBEAT        = 'as_content_stream_heartbeat';
	const NONCE_LAZY_ROWS        = 'as_content_stream_lazy_rows';
	const NONCE_TEST_TICK        = 'as_content_stream_test_tick';
	const PAGE_SLUG              = 'as-content-stream';
	const CRON_HOOK              = 'as_content_stream_process_tick';
	const STREAM_AUTHOR_LOGIN    = 'as_content_stream';
	const STREAM_AUTHOR_EMAIL    = 'as-content-stream@invalid.local';
	const STREAM_AUTHOR_ROLE     = 'integration';
	const STREAM_AUTHOR_META     = '_as_content_stream_user';

	/**
	 * Singleton instance.
	 *
	 * @var AS_Content_Stream|null
	 */
	private static $instance = null;

	/**
	 * Post IDs currently being queued.
	 *
	 * @var array<int,bool>
	 */
	private $queue_locks = array();

	/**
	 * Get singleton instance.
	 *
	 * @return AS_Content_Stream
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Activate plugin.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_queue_table();
		self::create_processing_queue_table();
		self::create_links_table();
		self::run_activation_discovery();

		if ( get_site_option( self::OPTION_PROCESSING_ENABLED, false ) ) {
			self::schedule_cron();
		}
	}

	/**
	 * Seed Discovery once on activation.
	 *
	 * @return void
	 */
	private static function run_activation_discovery() {
		if ( ! is_multisite() ) {
			return;
		}

		$restore = get_current_blog_id() !== get_main_site_id();
		if ( $restore ) {
			switch_to_blog( get_main_site_id() );
		}

		self::instance()->refresh_discovery_queue();

		if ( $restore ) {
			restore_current_blog();
		}
	}

	/**
	 * Deactivate plugin.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::unschedule_cron();
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_core_site_menu' ) );
		add_action( 'admin_post_as_content_stream_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_as_content_stream_clear_queue', array( $this, 'clear_queue' ) );
		add_action( 'admin_post_as_content_stream_run_queue_item', array( $this, 'run_queue_item' ) );
		add_action( 'admin_post_as_content_stream_rerun_discovery', array( $this, 'rerun_discovery' ) );
		add_action( 'admin_post_as_content_stream_clear_log', array( $this, 'clear_log' ) );
		add_action( 'admin_post_as_content_stream_run_processing_job', array( $this, 'run_processing_job' ) );
		add_action( 'admin_post_as_content_stream_delete_processing_job', array( $this, 'delete_processing_job' ) );
		add_action( 'admin_post_as_content_stream_run_link', array( $this, 'run_link' ) );
		add_action( 'admin_post_as_content_stream_test_tick', array( $this, 'run_test_tick' ) );
		add_action( 'admin_init', array( $this, 'normalize_skipped_processing_jobs' ) );
		add_action( 'admin_init', array( $this, 'normalize_streaming_map_rows' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_as_content_stream_heartbeat', array( $this, 'ajax_heartbeat' ) );
		add_action( 'wp_ajax_as_content_stream_queue_pulse', array( $this, 'ajax_queue_pulse' ) );
		add_action( 'wp_ajax_as_content_stream_lazy_rows', array( $this, 'ajax_lazy_rows' ) );
		add_action( self::CRON_HOOK, array( $this, 'process_tick' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
		add_filter( 'authenticate', array( $this, 'block_stream_author_authentication' ), 30, 3 );

		add_action( 'wp_after_insert_post', array( $this, 'capture_after_insert_post' ), 20, 4 );
		add_action( 'wp_trash_post', array( $this, 'capture_trash_post' ), 20, 2 );
		add_action( 'before_delete_post', array( $this, 'capture_delete_post' ), 20, 2 );

		if ( get_site_option( self::OPTION_PROCESSING_ENABLED, false ) ) {
			self::schedule_cron();
		}
	}

	/**
	 * Create global queue table.
	 *
	 * @return void
	 */
	private static function create_queue_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::queue_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			action varchar(20) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			source_blog_id bigint(20) unsigned NOT NULL,
			source_post_id bigint(20) unsigned NOT NULL,
			target_blog_id bigint(20) unsigned NOT NULL,
			target_language varchar(20) NOT NULL,
			post_type varchar(64) NOT NULL,
			payload longtext NULL,
			last_error text NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY source_post (source_blog_id, source_post_id),
			KEY pending_source_action (status, source_blog_id, source_post_id, action, post_type),
			KEY target_blog (target_blog_id),
			KEY action_post_type (action, post_type)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Create global processing queue table.
	 *
	 * @return void
	 */
	private static function create_processing_queue_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::processing_queue_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			started_at datetime NULL,
			completed_at datetime NULL,
			parent_queue_id bigint(20) unsigned NOT NULL,
			link_id bigint(20) unsigned NOT NULL DEFAULT 0,
			blocked_by bigint(20) unsigned NOT NULL DEFAULT 0,
			priority int(11) NOT NULL DEFAULT 0,
			action varchar(20) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			source_blog_id bigint(20) unsigned NOT NULL,
			source_post_id bigint(20) unsigned NOT NULL,
			target_blog_id bigint(20) unsigned NOT NULL,
			target_language varchar(20) NOT NULL,
			post_type varchar(64) NOT NULL,
			payload longtext NULL,
			result_message text NULL,
			attempts int(11) unsigned NOT NULL DEFAULT 0,
			duration_ms bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY link_id (link_id),
			KEY blocked_by (blocked_by),
			KEY priority_status (priority, status),
			KEY parent_status (parent_queue_id, status),
			KEY status (status),
			KEY action_status (action, status),
			KEY target_blog (target_blog_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Create global source/destination links table.
	 *
	 * @return void
	 */
	private static function create_links_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::links_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			last_streamed_at datetime NULL,
			source_uuid varchar(64) NOT NULL,
			source_blog_id bigint(20) unsigned NOT NULL,
			source_post_id bigint(20) unsigned NOT NULL,
			source_post_type varchar(64) NOT NULL,
			source_slug varchar(200) NOT NULL,
			target_blog_id bigint(20) unsigned NOT NULL,
			target_post_id bigint(20) unsigned NOT NULL,
			target_language varchar(20) NOT NULL,
			target_slug varchar(200) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			last_action varchar(20) NOT NULL DEFAULT '',
			last_queue_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_processing_job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY source_target_language (source_uuid, target_blog_id, target_language),
			KEY source_post (source_blog_id, source_post_id),
			KEY target_post (target_blog_id, target_post_id),
			KEY lookup_source_id (source_post_id),
			KEY lookup_target_id (target_post_id),
			KEY concrete_map (source_blog_id, source_post_id, source_post_type, target_blog_id, target_language),
			KEY source_slug (source_slug, source_post_type, target_language)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Get queue table name.
	 *
	 * @return string
	 */
	private static function queue_table_name() {
		global $wpdb;

		return $wpdb->base_prefix . 'as_content_stream_queue';
	}

	/**
	 * Get processing queue table name.
	 *
	 * @return string
	 */
	private static function processing_queue_table_name() {
		global $wpdb;

		return $wpdb->base_prefix . 'as_content_stream_processing_queue';
	}

	/**
	 * Get links table name.
	 *
	 * @return string
	 */
	private static function links_table_name() {
		global $wpdb;

		return $wpdb->base_prefix . 'as_content_stream_links';
	}

	/**
	 * Register the core site admin menu.
	 *
	 * @return void
	 */
	public function register_core_site_menu() {
		if ( ! is_multisite() || ! is_main_site() ) {
			return;
		}

		add_menu_page(
			__( 'Content Stream', 'as-content-stream' ),
			__( 'Content Stream', 'as-content-stream' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' ),
			'dashicons-randomize',
			58
		);
	}

	/**
	 * Enqueue admin CSS.
	 *
	 * @param string $hook_suffix Current hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'as-content-stream-admin',
			AS_CONTENT_STREAM_URL . 'assets/admin.css',
			array(),
			AS_CONTENT_STREAM_VERSION
		);
	}

	/**
	 * Render admin screen.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! is_multisite() || ! is_main_site() ) {
			wp_die( esc_html__( 'Content Stream is only available in the core site admin.', 'as-content-stream' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Content Stream.', 'as-content-stream' ) );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'create_queue';
		self::create_queue_table();
		self::create_links_table();

		$has_discovery = $this->has_discovery_queue_items();
		$tabs       = array(
			'settings'     => __( 'Settings', 'as-content-stream' ),
			'sites'        => __( 'Sites & WPML', 'as-content-stream' ),
		);
		if ( $has_discovery ) {
			$tabs['discovery_queue'] = __( 'Discovery Queue', 'as-content-stream' );
		}
		$tabs['create_queue'] = __( 'Create Queue', 'as-content-stream' );
		$tabs['update_queue'] = __( 'Update Queue', 'as-content-stream' );
		$tabs['delete_queue'] = __( 'Delete Queue', 'as-content-stream' );
		$tabs['processing_queue'] = __( 'Processing Queue', 'as-content-stream' );
		$tabs['links'] = __( 'Streaming Map', 'as-content-stream' );
		$tabs['log'] = __( 'Log', 'as-content-stream' );

		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'create_queue';
		}

		?>
		<div class="wrap as-content-stream">
			<h1><?php esc_html_e( 'Content Stream', 'as-content-stream' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Content Stream tabs', 'as-content-stream' ); ?>">
				<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
					<a class="nav-tab <?php echo esc_attr( $active_tab === $tab_key ? 'nav-tab-active' : '' ); ?>" href="<?php echo esc_url( $this->admin_url( array( 'tab' => $tab_key ) ) ); ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( isset( $_GET['updated'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'as-content-stream' ) . '</p></div>';
			}
			if ( isset( $_GET['queue_cleared'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Pending queue items cleared.', 'as-content-stream' ) . '</p></div>';
			}
			if ( isset( $_GET['discovery_refreshed'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Discovery queue rebuilt.', 'as-content-stream' ) . '</p></div>';
			}
			if ( isset( $_GET['log_cleared'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Processing log cleared.', 'as-content-stream' ) . '</p></div>';
			}

			switch ( $active_tab ) {
				case 'settings':
					$this->render_settings_tab();
					break;
				case 'sites':
					$this->render_sites_tab();
					break;
				case 'discovery_queue':
					$this->render_discovery_tab();
					break;
				case 'update_queue':
					$this->render_queue_tab( 'update' );
					break;
				case 'delete_queue':
					$this->render_queue_tab( 'delete' );
					break;
				case 'processing_queue':
					$this->render_processing_queue_tab();
					break;
				case 'links':
					$this->render_links_tab();
					break;
				case 'log':
					$this->render_log_tab();
					break;
				default:
					$this->render_queue_tab( 'create' );
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render overview.
	 *
	 * @return void
	 */
	private function render_overview_tab() {
		$sites           = $this->discover_sites();
		$wpml_sites      = array_filter(
			$sites,
			static function ( $site ) {
				return $site['wpml_active'];
			}
		);
		$queue_counts    = $this->get_queue_counts();
		?>
		<div class="as-content-grid">
			<div class="as-content-panel">
				<h2><?php esc_html_e( 'Network Readiness', 'as-content-stream' ); ?></h2>
				<p><strong><?php esc_html_e( 'WPML sites:', 'as-content-stream' ); ?></strong> <?php echo esc_html( count( $wpml_sites ) ); ?> / <?php echo esc_html( count( $sites ) ); ?></p>
				<p><strong><?php esc_html_e( 'Pending queue items:', 'as-content-stream' ); ?></strong> <?php echo esc_html( isset( $queue_counts['pending'] ) ? $queue_counts['pending'] : 0 ); ?></p>
			</div>
			<div class="as-content-panel">
				<h2><?php esc_html_e( 'Current Scope', 'as-content-stream' ); ?></h2>
				<p><?php esc_html_e( 'This build records core-site content create, update, and delete actions in the queue.', 'as-content-stream' ); ?></p>
				<p><?php esc_html_e( 'Actual content streaming is intentionally not executed yet.', 'as-content-stream' ); ?></p>
			</div>
			<div class="as-content-panel">
				<h2><?php esc_html_e( 'Last Capture', 'as-content-stream' ); ?></h2>
				<?php $this->render_capture_status(); ?>
			</div>
			<div class="as-content-panel">
				<h2><?php esc_html_e( 'Processing Order', 'as-content-stream' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Process Create Queue until clear.', 'as-content-stream' ); ?></li>
					<li><?php esc_html_e( 'Process Update Queue until clear.', 'as-content-stream' ); ?></li>
					<li><?php esc_html_e( 'Process Delete Queue until clear.', 'as-content-stream' ); ?></li>
				</ol>
			</div>
		</div>
		<?php
	}

	/**
	 * Render sites table.
	 *
	 * @return void
	 */
	private function render_sites_tab() {
		$sites = $this->discover_sites();
		?>
		<table class="widefat striped as-content-sites">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Site ID', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Site', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'WPML', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Languages', 'as-content-stream' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $sites ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No sites found.', 'as-content-stream' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $sites as $site ) : ?>
					<tr>
						<td><?php echo esc_html( (int) $site['blog_id'] ); ?></td>
						<td>
							<strong><?php echo esc_html( $site['name'] ); ?></strong><br>
							<a href="<?php echo esc_url( $site['url'] ); ?>"><?php echo esc_html( $site['url'] ); ?></a>
						</td>
						<td>
							<span class="as-status as-status-<?php echo esc_attr( $site['wpml_active'] ? 'yes' : 'no' ); ?>">
								<?php echo esc_html( $site['wpml_active'] ? __( 'Active', 'as-content-stream' ) : __( 'Not detected', 'as-content-stream' ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $this->format_list( $site['languages'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render settings/status.
	 *
	 * @return void
	 */
	private function render_settings_tab() {
		$sites        = $this->discover_sites();
		$processing_open_count = $this->get_processing_queue_open_count();
		$queue_action_counts = $this->get_queue_action_counts();
		$streamed_count = $this->get_streamed_content_count();
		$published_content_count = $this->get_published_source_content_count();
		$log_count = $this->get_processing_log_count();
		$language_counts = $this->get_language_counts( $sites );
		$target_language = $this->get_effective_target_language( $language_counts );
		$heartbeat_seconds = self::get_heartbeat_seconds();
		$processing_enabled = (bool) get_site_option( self::OPTION_PROCESSING_ENABLED, false );
		$heartbeat = $this->get_heartbeat_status();
		$wpml_sites   = array_filter(
			$sites,
			static function ( $site ) {
				return $site['wpml_active'];
			}
		);
		$this->ensure_stream_author_for_sites( $wpml_sites );
		?>
		<div class="as-content-grid as-content-settings-grid">
			<div class="as-content-panel as-content-target-language-panel">
				<h2><?php esc_html_e( 'Options', 'as-content-stream' ); ?></h2>
				<form class="as-content-panel-form" method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_save_settings' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_SETTINGS ); ?>
					<input type="hidden" name="settings_context" value="options">
					<div class="as-content-panel-body">
						<label class="as-content-field-label" for="as-content-target-language"><?php esc_html_e( 'Target Language', 'as-content-stream' ); ?></label>
						<select id="as-content-target-language" class="as-content-select" name="target_language">
						<?php if ( empty( $language_counts ) ) : ?>
							<option value=""><?php esc_html_e( 'No destination languages available', 'as-content-stream' ); ?></option>
						<?php else : ?>
							<?php foreach ( $language_counts as $language => $count ) : ?>
								<option value="<?php echo esc_attr( $language ); ?>" <?php selected( $target_language, $language ); ?>>
									<?php echo esc_html( sprintf( '%s (%d)', $language, $count ) ); ?>
								</option>
							<?php endforeach; ?>
						<?php endif; ?>
						</select>
						<label class="as-content-field-label" for="as-content-heartbeat-seconds"><?php esc_html_e( 'Heartbeat Seconds', 'as-content-stream' ); ?></label>
						<input id="as-content-heartbeat-seconds" class="as-content-number-input" type="number" name="heartbeat_seconds" min="1" step="1" value="<?php echo esc_attr( $heartbeat_seconds ); ?>">
					</div>
					<div class="as-content-panel-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'as-content-stream' ); ?></button>
					</div>
				</form>
			</div>
			<div class="as-content-panel">
				<h2><?php esc_html_e( 'Network Status', 'as-content-stream' ); ?></h2>
				<table class="as-content-metric-table">
					<tbody>
						<tr><th scope="row"><?php esc_html_e( 'Published Content', 'as-content-stream' ); ?></th><td data-as-heartbeat="status_published_content"><?php echo esc_html( $published_content_count ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Sites', 'as-content-stream' ); ?></th><td data-as-heartbeat="status_sites"><?php echo esc_html( count( $sites ) ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'WPML active sites', 'as-content-stream' ); ?></th><td data-as-heartbeat="status_wpml_sites"><?php echo esc_html( count( $wpml_sites ) ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Discovery Queue', 'as-content-stream' ); ?></th><td data-as-heartbeat="status_discovery"><?php echo esc_html( isset( $queue_action_counts['discover'] ) ? $queue_action_counts['discover'] : 0 ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Create Queue', 'as-content-stream' ); ?></th><td data-as-heartbeat="status_create"><?php echo esc_html( isset( $queue_action_counts['create'] ) ? $queue_action_counts['create'] : 0 ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Update Queue', 'as-content-stream' ); ?></th><td data-as-heartbeat="status_update"><?php echo esc_html( isset( $queue_action_counts['update'] ) ? $queue_action_counts['update'] : 0 ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Delete Queue', 'as-content-stream' ); ?></th><td data-as-heartbeat="status_delete"><?php echo esc_html( isset( $queue_action_counts['delete'] ) ? $queue_action_counts['delete'] : 0 ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Processing Queue', 'as-content-stream' ); ?></th><td data-as-heartbeat="status_processing"><?php echo esc_html( $processing_open_count ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Streaming Map', 'as-content-stream' ); ?></th><td data-as-heartbeat="status_streamed"><?php echo esc_html( $streamed_count ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Log', 'as-content-stream' ); ?></th><td data-as-heartbeat="status_log"><?php echo esc_html( $log_count ); ?></td></tr>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_rerun_discovery' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_QUEUE ); ?>
					<?php submit_button( __( 'Re-run Discovery', 'as-content-stream' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
			<div class="as-content-panel">
				<h2><?php esc_html_e( 'Heartbeat', 'as-content-stream' ); ?></h2>
				<div id="as-content-heartbeat" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_HEARTBEAT ) ); ?>">
					<div class="as-content-gauge">
						<div class="as-content-gauge-meta">
							<span><?php esc_html_e( 'Next check', 'as-content-stream' ); ?></span>
						</div>
						<div class="as-content-progress as-content-progress-success as-content-progress-timer <?php echo $processing_enabled ? '' : 'is-paused'; ?>" aria-hidden="true">
							<span style="--as-content-heartbeat-duration: <?php echo esc_attr( self::get_heartbeat_seconds() ); ?>s;"></span>
						</div>
					</div>
					<div class="as-content-gauge">
						<div class="as-content-gauge-meta">
							<span><?php esc_html_e( 'In progress / Pending', 'as-content-stream' ); ?></span>
							<strong><span data-as-heartbeat="parent_in_progress"><?php echo esc_html( $heartbeat['parent_in_progress'] ); ?></span> / <span data-as-heartbeat="parent_pending"><?php echo esc_html( $heartbeat['parent_pending'] ); ?></span></strong>
						</div>
						<div class="as-content-progress" aria-hidden="true">
							<span data-as-heartbeat-bar="parent" style="width: <?php echo esc_attr( $heartbeat['parent_pressure_percent'] ); ?>%;"></span>
						</div>
					</div>
					<div class="as-content-gauge">
						<div class="as-content-gauge-meta">
							<span><?php esc_html_e( 'Queued / Blocked / Failed', 'as-content-stream' ); ?></span>
							<strong><span data-as-heartbeat="child_queued"><?php echo esc_html( $heartbeat['child_queued'] ); ?></span> / <span data-as-heartbeat="child_blocked"><?php echo esc_html( $heartbeat['child_blocked'] ); ?></span> / <span data-as-heartbeat="child_failed"><?php echo esc_html( $heartbeat['child_failed'] ); ?></span></strong>
						</div>
						<div class="as-content-progress as-content-progress-danger" aria-hidden="true">
							<span data-as-heartbeat-bar="child" style="width: <?php echo esc_attr( $heartbeat['child_obstructed_percent'] ); ?>%;"></span>
						</div>
					</div>
					<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_save_settings' ) ); ?>">
						<?php wp_nonce_field( self::NONCE_SETTINGS ); ?>
						<input type="hidden" name="settings_context" value="processing">
						<input type="hidden" name="target_language" value="<?php echo esc_attr( $target_language ); ?>">
						<label class="as-content-toggle">
							<input type="checkbox" name="processing_enabled" value="1" <?php checked( $processing_enabled ); ?>>
							<span><?php esc_html_e( 'Automatic cron processing', 'as-content-stream' ); ?></span>
						</label>
						<?php submit_button( __( 'Save Mode', 'as-content-stream' ), 'primary', 'submit', false ); ?>
					</form>
				</div>
			</div>
		</div>
		<script>
			(function () {
				var heartbeat = document.getElementById('as-content-heartbeat');
				var root = document.querySelector('.as-content-settings-grid');
				if (!heartbeat || !root || !window.ajaxurl) {
					return;
				}
				var statusRequestInFlight = false;
				var pulseRequestInFlight = false;
				function setText(name, value) {
					var node = root.querySelector('[data-as-heartbeat="' + name + '"]');
					if (node && node.textContent !== String(value)) {
						node.textContent = value;
						node.classList.remove('as-content-value-changed');
						window.requestAnimationFrame(function () {
							node.classList.add('as-content-value-changed');
						});
					}
				}
				function updateTargetLanguages(languages, selectedLanguage) {
					var select = root.querySelector('#as-content-target-language');
					if (!select || !languages) {
						return;
					}
					var currentValue = select.value;
					var languageKeys = Object.keys(languages);
					var existingKeys = Array.prototype.map.call(select.options, function (option) {
						return option.value;
					});
					if (languageKeys.join('|') !== existingKeys.join('|')) {
						select.innerHTML = '';
						if (!languageKeys.length) {
							var emptyOption = document.createElement('option');
							emptyOption.value = '';
							emptyOption.textContent = '<?php echo esc_js( __( 'No destination languages available', 'as-content-stream' ) ); ?>';
							select.appendChild(emptyOption);
						}
						languageKeys.forEach(function (language) {
							var option = document.createElement('option');
							option.value = language;
							option.textContent = languages[language];
							select.appendChild(option);
						});
					} else {
						Array.prototype.forEach.call(select.options, function (option) {
							if (languages[option.value] && option.textContent !== languages[option.value]) {
								option.textContent = languages[option.value];
							}
						});
					}
					if (currentValue && languages[currentValue]) {
						select.value = currentValue;
					} else if (selectedLanguage && languages[selectedLanguage]) {
						select.value = selectedLanguage;
					}
				}
				function updateQueuePulse(status) {
					setText('parent_in_progress', status.parent_in_progress);
					setText('parent_pending', status.parent_pending);
					setText('child_queued', status.child_queued);
					setText('child_blocked', status.child_blocked);
					setText('child_failed', status.child_failed);
					var parentBar = root.querySelector('[data-as-heartbeat-bar="parent"]');
					if (parentBar) {
						parentBar.style.width = status.parent_pressure_percent + '%';
					}
					var childBar = root.querySelector('[data-as-heartbeat-bar="child"]');
					if (childBar) {
						childBar.style.width = status.child_obstructed_percent + '%';
					}
				}
				function refreshStatus() {
					if (statusRequestInFlight) {
						return;
					}
					statusRequestInFlight = true;
					var data = new window.FormData();
					data.append('action', 'as_content_stream_heartbeat');
					data.append('nonce', heartbeat.getAttribute('data-nonce'));
					window.fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function (response) { return response.json(); })
						.then(function (response) {
							if (!response || !response.success || !response.data) {
								return;
							}
							var status = response.data;
							updateQueuePulse(status);
							setText('status_discovery', status.status_discovery);
							setText('status_create', status.status_create);
							setText('status_update', status.status_update);
							setText('status_delete', status.status_delete);
							setText('status_processing', status.status_processing);
							setText('status_streamed', status.status_streamed);
							setText('status_published_content', status.status_published_content);
							setText('status_log', status.status_log);
							setText('status_sites', status.status_sites);
							setText('status_wpml_sites', status.status_wpml_sites);
							updateTargetLanguages(status.target_language_labels, status.target_language);
						})
						.catch(function () {})
						.finally(function () {
							statusRequestInFlight = false;
						});
				}
				function refreshQueuePulse() {
					if (pulseRequestInFlight) {
						return;
					}
					pulseRequestInFlight = true;
					var data = new window.FormData();
					data.append('action', 'as_content_stream_queue_pulse');
					data.append('nonce', heartbeat.getAttribute('data-nonce'));
					window.fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function (response) { return response.json(); })
						.then(function (response) {
							if (!response || !response.success || !response.data) {
								return;
							}
							updateQueuePulse(response.data);
						})
						.catch(function () {})
						.finally(function () {
							pulseRequestInFlight = false;
						});
				}
				var heartbeatTimer = root.querySelector('.as-content-progress-timer span');
				if (heartbeatTimer) {
					heartbeatTimer.addEventListener('animationiteration', function () {
						refreshQueuePulse();
						window.setTimeout(refreshQueuePulse, 500);
					});
				}
				refreshStatus();
				refreshQueuePulse();
				window.setInterval(refreshQueuePulse, 500);
				window.setInterval(refreshStatus, 5000);
			}());
		</script>
		<?php
	}

	/**
	 * Render queue.
	 *
	 * @return void
	 */
	private function render_queue_tab( $action, $limit = 50 ) {
		$snapshot_id = $this->get_queue_snapshot_id( $action );
		$items  = $this->get_queue_items( $action, $limit, 0, $snapshot_id );
		$counts = $this->get_queue_counts();
		?>
		<div class="as-content-queue-actions">
			<p><strong><?php esc_html_e( 'Queue status:', 'as-content-stream' ); ?></strong> <?php echo esc_html( $this->format_counts( $counts ) ); ?></p>
			<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_clear_queue' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_QUEUE ); ?>
				<?php submit_button( __( 'Clear Pending Items', 'as-content-stream' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<table class="widefat striped as-content-queue" data-as-lazy-table="queue" data-as-action="<?php echo esc_attr( sanitize_key( $action ) ); ?>" data-as-offset="<?php echo esc_attr( count( $items ) ); ?>" data-as-limit="<?php echo esc_attr( $limit ); ?>" data-as-snapshot-id="<?php echo esc_attr( $snapshot_id ); ?>">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Job', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Created', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Action', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Status', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Source', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Post Title', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Post Name', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Post Type', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Control', 'as-content-stream' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr><td colspan="9"><?php esc_html_e( 'No queue items yet.', 'as-content-stream' ); ?></td></tr>
				<?php endif; ?>
				<?php $this->render_queue_item_rows( $items ); ?>
			</tbody>
		</table>
		<?php $this->render_lazy_rows_script(); ?>
		<?php
	}

	/**
	 * Render discovery queue.
	 *
	 * @return void
	 */
	private function render_discovery_tab() {
		$stats = $this->get_discovery_stats();
		?>
		<div class="as-content-queue-actions">
			<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_rerun_discovery' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_QUEUE ); ?>
				<?php submit_button( __( 'Re-run Discovery', 'as-content-stream' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<table class="widefat striped as-content-queue as-content-discovery-stats">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Post Type', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Published in Core', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Mapped', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Unmapped', 'as-content-stream' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $stats ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'All published source content is mapped for the active target sites.', 'as-content-stream' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $stats as $stat ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $stat['post_type'] ); ?></strong></td>
						<td><?php echo esc_html( (int) $stat['published'] ); ?></td>
						<td><?php echo esc_html( (int) $stat['mapped'] ); ?></td>
						<td><?php echo esc_html( (int) $stat['unmapped'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->render_queue_tab( 'discover' );
	}

	/**
	 * Render processing queue.
	 *
	 * @return void
	 */
	private function render_processing_queue_tab() {
		$limit = 50;
		$snapshot_id = $this->get_processing_queue_snapshot_id( false );
		$items = $this->get_processing_queue_items( false, 0, $limit, 0, $snapshot_id );
		?>
		<table class="widefat striped as-content-queue" data-as-lazy-table="processing" data-as-offset="<?php echo esc_attr( count( $items ) ); ?>" data-as-limit="<?php echo esc_attr( $limit ); ?>" data-as-snapshot-id="<?php echo esc_attr( $snapshot_id ); ?>">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Job', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Created', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Parent Job', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Blocked By Job', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Action', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Status', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Source', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Post Title', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Post Type', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Destination Site', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Destination Post', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Language', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Attempts', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Result', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Control', 'as-content-stream' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr><td colspan="16"><?php esc_html_e( 'No processing jobs yet.', 'as-content-stream' ); ?></td></tr>
				<?php endif; ?>
				<?php $this->render_processing_item_rows( $items, false ); ?>
			</tbody>
		</table>
		<?php $this->render_lazy_rows_script(); ?>
		<?php
	}

	/**
	 * Render processing log.
	 *
	 * @return void
	 */
	private function render_log_tab() {
		$lookup_id = isset( $_GET['lookup_id'] ) ? absint( $_GET['lookup_id'] ) : 0;
		$limit = 50;
		$snapshot_id = $this->get_processing_queue_snapshot_id( true, $lookup_id );
		$items = $this->get_processing_queue_items( true, $lookup_id, $limit, 0, $snapshot_id );
		?>
		<div class="as-content-queue-actions">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="as-content-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="log">
				<label for="as-content-log-lookup"><?php esc_html_e( 'Post ID lookup', 'as-content-stream' ); ?></label>
				<input id="as-content-log-lookup" type="number" min="1" name="lookup_id" value="<?php echo esc_attr( $lookup_id ? $lookup_id : '' ); ?>">
				<?php submit_button( __( 'Find', 'as-content-stream' ), 'secondary', 'submit', false ); ?>
				<?php if ( $lookup_id ) : ?>
					<a class="button" href="<?php echo esc_url( $this->admin_url( array( 'tab' => 'log' ) ) ); ?>"><?php esc_html_e( 'Clear', 'as-content-stream' ); ?></a>
				<?php endif; ?>
			</form>
			<?php if ( $lookup_id ) : ?>
				<p><?php esc_html_e( 'Showing completed processing jobs where that ID is source or destination.', 'as-content-stream' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_clear_log' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_LOG ); ?>
				<?php submit_button( __( 'Clear Log', 'as-content-stream' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<table class="widefat striped as-content-queue" data-as-lazy-table="log" data-as-lookup-id="<?php echo esc_attr( $lookup_id ); ?>" data-as-offset="<?php echo esc_attr( count( $items ) ); ?>" data-as-limit="<?php echo esc_attr( $limit ); ?>" data-as-snapshot-id="<?php echo esc_attr( $snapshot_id ); ?>">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Job', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Completed', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Parent Job', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Action', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Status', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Source', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Post Title', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Post Type', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Destination Site', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Destination Post', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Language', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Attempts', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Result', 'as-content-stream' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr><td colspan="14"><?php esc_html_e( 'No completed processing jobs yet.', 'as-content-stream' ); ?></td></tr>
				<?php endif; ?>
				<?php $this->render_processing_item_rows( $items, true ); ?>
			</tbody>
		</table>
		<?php $this->render_lazy_rows_script(); ?>
		<?php
	}

	/**
	 * Render source/destination links.
	 *
	 * @return void
	 */
	private function render_links_tab() {
		$lookup_id = isset( $_GET['lookup_id'] ) ? absint( $_GET['lookup_id'] ) : 0;
		$limit = 50;
		$snapshot_id = $this->get_links_snapshot_id( $lookup_id );
		$links = $this->get_links( $lookup_id, $limit, 0, $snapshot_id );
		?>
		<div class="as-content-queue-actions">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="as-content-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="links">
				<label for="as-content-link-lookup"><?php esc_html_e( 'Post ID lookup', 'as-content-stream' ); ?></label>
				<input id="as-content-link-lookup" type="number" min="1" name="lookup_id" value="<?php echo esc_attr( $lookup_id ? $lookup_id : '' ); ?>">
				<?php submit_button( __( 'Find', 'as-content-stream' ), 'secondary', 'submit', false ); ?>
				<?php if ( $lookup_id ) : ?>
					<a class="button" href="<?php echo esc_url( $this->admin_url( array( 'tab' => 'links' ) ) ); ?>"><?php esc_html_e( 'Clear', 'as-content-stream' ); ?></a>
				<?php endif; ?>
			</form>
			<?php if ( $lookup_id ) : ?>
				<p><?php esc_html_e( 'Showing streaming map rows where that ID is source or destination.', 'as-content-stream' ); ?></p>
			<?php endif; ?>
		</div>
		<table class="widefat striped as-content-queue" data-as-lazy-table="links" data-as-lookup-id="<?php echo esc_attr( $lookup_id ); ?>" data-as-offset="<?php echo esc_attr( count( $links ) ); ?>" data-as-limit="<?php echo esc_attr( $limit ); ?>" data-as-snapshot-id="<?php echo esc_attr( $snapshot_id ); ?>">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Job', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Last Streamed', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Link', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Last Action', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Status', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Source', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Post Title', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Post Type', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Destination Site', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Destination Post', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Language', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Control', 'as-content-stream' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $links ) ) : ?>
					<tr><td colspan="12"><?php esc_html_e( 'No streaming map rows found.', 'as-content-stream' ); ?></td></tr>
				<?php endif; ?>
				<?php $this->render_link_rows( $links ); ?>
			</tbody>
		</table>
		<?php $this->render_lazy_rows_script(); ?>
		<?php
	}

	/**
	 * Render queue item rows.
	 *
	 * @param array<int,object> $items Queue rows.
	 * @return void
	 */
	private function render_queue_item_rows( $items ) {
		foreach ( $items as $item ) :
			$payload = $this->decode_queue_payload( $item->payload );
			$edit_url = $this->source_edit_url( (int) $item->source_blog_id, (int) $item->source_post_id );
			$post_title = isset( $payload['post_title'] ) ? $payload['post_title'] : $this->get_post_title_from_site( (int) $item->source_blog_id, (int) $item->source_post_id );
			?>
			<tr>
				<td><?php echo esc_html( '#' . (int) $item->id ); ?></td>
				<td><?php echo esc_html( $item->created_at ); ?></td>
				<td><?php echo esc_html( $this->format_action_label( $item->action ) ); ?></td>
				<td><?php echo esc_html( ucfirst( $item->status ) ); ?></td>
				<td><?php echo esc_html( '#' . (int) $item->source_post_id ); ?></td>
				<td>
					<?php if ( $edit_url ) : ?>
						<a href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $post_title ? $post_title : '#' . (int) $item->source_post_id ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $post_title ? $post_title : '-' ); ?>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( isset( $payload['post_name'] ) ? $payload['post_name'] : '' ); ?></td>
				<td><?php echo esc_html( $item->post_type ); ?></td>
				<td>
					<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_run_queue_item' ) ); ?>">
						<?php wp_nonce_field( self::NONCE_QUEUE ); ?>
						<input type="hidden" name="queue_id" value="<?php echo esc_attr( (int) $item->id ); ?>">
						<?php submit_button( __( 'Run', 'as-content-stream' ), 'secondary small', 'submit', false ); ?>
					</form>
				</td>
			</tr>
			<?php
		endforeach;
	}

	/**
	 * Render processing queue or log rows.
	 *
	 * @param array<int,object> $items Processing rows.
	 * @param bool              $terminal Whether rows are log rows.
	 * @return void
	 */
	private function render_processing_item_rows( $items, $terminal ) {
		foreach ( $items as $item ) :
			$payload = $this->decode_queue_payload( $item->payload );
			$source_url = $this->source_edit_url( (int) $item->source_blog_id, (int) $item->source_post_id );
			$post_type_url = $this->post_type_list_url( (int) $item->target_blog_id, sanitize_key( $item->post_type ), sanitize_key( $item->target_language ) );
			$destination_post_id = $this->destination_post_id_for_processing_item( $item, $payload );
			$destination_post_url = $destination_post_id ? $this->source_edit_url( (int) $item->target_blog_id, $destination_post_id ) : '';
			$post_title = isset( $payload['post_title'] ) ? $payload['post_title'] : $this->get_post_title_from_site( (int) $item->source_blog_id, (int) $item->source_post_id );
			$is_blocked = 'blocked' === sanitize_key( $item->status ) || ! empty( $item->blocked_by );
			?>
			<tr>
				<td><?php echo esc_html( '#' . (int) $item->id ); ?></td>
				<td><?php echo esc_html( $terminal ? $item->completed_at : $item->created_at ); ?></td>
				<td><?php echo esc_html( '#' . (int) $item->parent_queue_id ); ?></td>
				<?php if ( ! $terminal ) : ?>
					<td><?php echo esc_html( ! empty( $item->blocked_by ) ? '#' . (int) $item->blocked_by : '-' ); ?></td>
				<?php endif; ?>
				<td><?php echo esc_html( $this->format_action_label( $item->action ) ); ?></td>
				<td><?php echo esc_html( ucfirst( $item->status ) ); ?></td>
				<td><?php echo esc_html( '#' . (int) $item->source_post_id ); ?></td>
				<td>
					<?php if ( $source_url ) : ?>
						<a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $post_title ? $post_title : '#' . (int) $item->source_post_id ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $post_title ? $post_title : '-' ); ?>
					<?php endif; ?>
				</td>
				<td><a href="<?php echo esc_url( $post_type_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( sanitize_key( $item->post_type ) ); ?></a></td>
				<td><a href="<?php echo esc_url( $this->site_admin_url( (int) $item->target_blog_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $this->site_label( (int) $item->target_blog_id ) ); ?></a></td>
				<td>
					<?php if ( $destination_post_id && $destination_post_url ) : ?>
						<a href="<?php echo esc_url( $destination_post_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( '#' . $destination_post_id ); ?></a>
					<?php else : ?>
						<?php echo esc_html( '-' ); ?>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $item->target_language ); ?></td>
				<td><?php echo esc_html( (int) $item->attempts ); ?></td>
				<td><?php echo esc_html( (int) $item->duration_ms . 'ms' ); ?></td>
				<td><?php echo esc_html( $item->result_message ); ?></td>
				<?php if ( ! $terminal ) : ?>
					<td>
						<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_run_processing_job' ) ); ?>" class="as-content-row-action">
							<?php wp_nonce_field( self::NONCE_PROCESSING ); ?>
							<input type="hidden" name="job_id" value="<?php echo esc_attr( (int) $item->id ); ?>">
							<?php submit_button( __( 'Run', 'as-content-stream' ), 'secondary small', 'submit', false, $is_blocked ? array( 'disabled' => 'disabled' ) : array() ); ?>
						</form>
						<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_delete_processing_job' ) ); ?>" class="as-content-row-action">
							<?php wp_nonce_field( self::NONCE_PROCESSING ); ?>
							<input type="hidden" name="job_id" value="<?php echo esc_attr( (int) $item->id ); ?>">
							<button type="submit" class="button button-small as-content-icon-button" aria-label="<?php esc_attr_e( 'Delete processing job', 'as-content-stream' ); ?>" title="<?php esc_attr_e( 'Delete processing job', 'as-content-stream' ); ?>" onclick="return window.confirm('<?php echo esc_js( __( 'Delete this processing job?', 'as-content-stream' ) ); ?>');">
								<span class="dashicons dashicons-trash" aria-hidden="true"></span>
							</button>
						</form>
					</td>
				<?php endif; ?>
			</tr>
			<?php
		endforeach;
	}

	/**
	 * Render Streaming Map rows.
	 *
	 * @param array<int,object> $links Link rows.
	 * @return void
	 */
	private function render_link_rows( $links ) {
		foreach ( $links as $link ) :
			$source_url = $this->source_edit_url( (int) $link->source_blog_id, (int) $link->source_post_id );
			$target_url = $this->source_edit_url( (int) $link->target_blog_id, (int) $link->target_post_id );
			$post_type_url = $this->post_type_list_url( (int) $link->target_blog_id, sanitize_key( $link->source_post_type ), sanitize_key( $link->target_language ) );
			$post_title = $this->get_post_title_from_site( (int) $link->source_blog_id, (int) $link->source_post_id );
			?>
			<tr>
				<td><?php echo esc_html( $link->last_processing_job_id ? '#' . (int) $link->last_processing_job_id : '-' ); ?></td>
				<td><?php echo esc_html( $link->last_streamed_at ? $link->last_streamed_at : '-' ); ?></td>
				<td><?php echo esc_html( '#' . (int) $link->id ); ?></td>
				<td><?php echo esc_html( $this->format_action_label( $link->last_action ) ); ?></td>
				<td><?php echo esc_html( ucfirst( $link->status ) ); ?></td>
				<td><?php echo esc_html( '#' . (int) $link->source_post_id ); ?></td>
				<td>
					<?php if ( $source_url ) : ?>
						<a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $post_title ? $post_title : '#' . (int) $link->source_post_id ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $post_title ? $post_title : '-' ); ?>
					<?php endif; ?>
				</td>
				<td><a href="<?php echo esc_url( $post_type_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( sanitize_key( $link->source_post_type ) ); ?></a></td>
				<td><a href="<?php echo esc_url( $this->site_admin_url( (int) $link->target_blog_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $this->site_label( (int) $link->target_blog_id ) ); ?></a></td>
				<td><a href="<?php echo esc_url( $target_url ? $target_url : $this->site_admin_url( (int) $link->target_blog_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( '#' . (int) $link->target_post_id ); ?></a></td>
				<td><?php echo esc_html( $link->target_language ); ?></td>
				<td>
					<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_run_link' ) ); ?>">
						<?php wp_nonce_field( self::NONCE_PROCESSING ); ?>
						<input type="hidden" name="link_id" value="<?php echo esc_attr( (int) $link->id ); ?>">
						<?php submit_button( __( 'Run', 'as-content-stream' ), 'secondary small', 'submit', false ); ?>
					</form>
				</td>
			</tr>
			<?php
		endforeach;
	}

	/**
	 * Render lazy row loading script once.
	 *
	 * @return void
	 */
	private function render_lazy_rows_script() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>
			(function () {
				var ajaxUrl = window.ajaxurl || <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE_LAZY_ROWS ) ); ?>;
				if (!ajaxUrl || !nonce) {
					return;
				}
				function appendRows(tbody, html) {
					var template = document.createElement('template');
					template.innerHTML = html;
					tbody.appendChild(template.content);
				}
				function loadNext(table) {
					if (table.dataset.asDone === '1' || table.dataset.asLoading === '1') {
						return;
					}
					var loader = table.nextElementSibling && table.nextElementSibling.classList.contains('as-content-lazy-loader') ? table.nextElementSibling : null;
					if (!loader) {
						loader = document.createElement('div');
						loader.className = 'as-content-lazy-loader';
						loader.setAttribute('aria-live', 'polite');
						loader.innerHTML = '<span></span><span></span><span></span>';
						table.parentNode.insertBefore(loader, table.nextSibling);
					}
					loader.hidden = false;
					table.dataset.asLoading = '1';
					var data = new window.FormData();
					data.append('action', 'as_content_stream_lazy_rows');
					data.append('nonce', nonce);
					data.append('table', table.dataset.asLazyTable || '');
					data.append('queue_action', table.dataset.asAction || '');
					data.append('lookup_id', table.dataset.asLookupId || '0');
					data.append('offset', table.dataset.asOffset || '0');
					data.append('limit', table.dataset.asLimit || '50');
					data.append('snapshot_id', table.dataset.asSnapshotId || '0');
					window.fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function (response) { return response.json(); })
						.then(function (response) {
							if (!response || !response.success || !response.data) {
								table.dataset.asDone = '1';
								if (loader) {
									loader.hidden = true;
								}
								return;
							}
							var tbody = table.querySelector('tbody');
							if (tbody && response.data.html) {
								appendRows(tbody, response.data.html);
							}
							table.dataset.asOffset = String(parseInt(table.dataset.asOffset || '0', 10) + parseInt(response.data.count || '0', 10));
							if (!response.data.has_more) {
								table.dataset.asDone = '1';
								if (loader) {
									loader.hidden = true;
								}
							} else {
								window.setTimeout(function () { loadNext(table); }, 150);
							}
						})
						.catch(function () {
							table.dataset.asDone = '1';
							if (loader) {
								loader.hidden = true;
							}
						})
						.finally(function () {
							table.dataset.asLoading = '0';
						});
				}
				Array.prototype.forEach.call(document.querySelectorAll('[data-as-lazy-table]'), function (table) {
					if ((parseInt(table.dataset.asOffset || '0', 10) || 0) < (parseInt(table.dataset.asLimit || '50', 10) || 50)) {
						table.dataset.asDone = '1';
						return;
					}
					window.setTimeout(function () { loadNext(table); }, 100);
				});
			}());
		</script>
		<?php
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to update Content Stream.', 'as-content-stream' ) );
		}

		check_admin_referer( self::NONCE_SETTINGS );

		$settings_context = isset( $_POST['settings_context'] ) ? sanitize_key( wp_unslash( $_POST['settings_context'] ) ) : '';
		$language_counts = $this->get_language_counts( $this->discover_sites() );
		$target_language = isset( $_POST['target_language'] ) ? sanitize_key( wp_unslash( $_POST['target_language'] ) ) : '';
		if ( '' !== $target_language && ! isset( $language_counts[ $target_language ] ) ) {
			$target_language = '';
		}

		update_site_option( self::OPTION_TARGET_LANGUAGE, $target_language );

		$heartbeat_changed = false;
		if ( isset( $_POST['heartbeat_seconds'] ) ) {
			$heartbeat_seconds = absint( wp_unslash( $_POST['heartbeat_seconds'] ) );
			if ( $heartbeat_seconds < 1 ) {
				$heartbeat_seconds = MINUTE_IN_SECONDS;
			}
			$heartbeat_changed = $heartbeat_seconds !== self::get_heartbeat_seconds();
			update_site_option( self::OPTION_HEARTBEAT_SECONDS, $heartbeat_seconds );
		}

		if ( 'processing' === $settings_context ) {
			$processing_enabled = ! empty( $_POST['processing_enabled'] );
			update_site_option( self::OPTION_PROCESSING_ENABLED, $processing_enabled ? 1 : 0 );

			if ( $processing_enabled ) {
				self::schedule_cron();
			} else {
				self::unschedule_cron();
			}
		} elseif ( $heartbeat_changed && get_site_option( self::OPTION_PROCESSING_ENABLED, false ) ) {
			self::unschedule_cron();
			self::schedule_cron();
		}

		wp_safe_redirect( $this->admin_url( array( 'tab' => 'settings', 'updated' => 1 ) ) );
		exit;
	}

	/**
	 * AJAX heartbeat status.
	 *
	 * @return void
	 */
	public function ajax_heartbeat() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		check_ajax_referer( self::NONCE_HEARTBEAT, 'nonce' );
		wp_send_json_success( $this->get_heartbeat_status() );
	}

	/**
	 * AJAX queue pulse for fast-moving heartbeat bars.
	 *
	 * @return void
	 */
	public function ajax_queue_pulse() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		check_ajax_referer( self::NONCE_HEARTBEAT, 'nonce' );
		wp_send_json_success( $this->get_queue_pulse_status() );
	}

	/**
	 * AJAX lazy table rows.
	 *
	 * @return void
	 */
	public function ajax_lazy_rows() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		check_ajax_referer( self::NONCE_LAZY_ROWS, 'nonce' );

		$table = isset( $_POST['table'] ) ? sanitize_key( wp_unslash( $_POST['table'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$limit = isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 50;
		$limit = min( 50, max( 1, $limit ) );
		$lookup_id = isset( $_POST['lookup_id'] ) ? absint( wp_unslash( $_POST['lookup_id'] ) ) : 0;
		$snapshot_id = isset( $_POST['snapshot_id'] ) ? absint( wp_unslash( $_POST['snapshot_id'] ) ) : 0;
		$rows = array();

		ob_start();
		switch ( $table ) {
			case 'queue':
				$queue_action = isset( $_POST['queue_action'] ) ? sanitize_key( wp_unslash( $_POST['queue_action'] ) ) : '';
				if ( in_array( $queue_action, array( 'create', 'update', 'delete', 'discover' ), true ) ) {
					$rows = $this->get_queue_items( $queue_action, $limit, $offset, $snapshot_id );
					$this->render_queue_item_rows( $rows );
				}
				break;

			case 'processing':
				$rows = $this->get_processing_queue_items( false, 0, $limit, $offset, $snapshot_id );
				$this->render_processing_item_rows( $rows, false );
				break;

			case 'log':
				$rows = $this->get_processing_queue_items( true, $lookup_id, $limit, $offset, $snapshot_id );
				$this->render_processing_item_rows( $rows, true );
				break;

			case 'links':
				$rows = $this->get_links( $lookup_id, $limit, $offset, $snapshot_id );
				$this->render_link_rows( $rows );
				break;
		}
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html'     => $html,
				'count'    => count( $rows ),
				'has_more' => count( $rows ) >= $limit,
			)
		);
	}

	/**
	 * Add Content Stream cron schedule.
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_cron_schedules( $schedules ) {
		$schedules['as_content_stream_interval'] = array(
			'interval' => self::get_heartbeat_seconds(),
			'display'  => __( 'Content Stream heartbeat interval', 'as-content-stream' ),
		);

		return $schedules;
	}

	/**
	 * Schedule cron.
	 *
	 * @return void
	 */
	private static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$interval = self::get_heartbeat_seconds();
			wp_schedule_event( time() + $interval, 'as_content_stream_interval', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule cron.
	 *
	 * @return void
	 */
	private static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Process one cron tick.
	 *
	 * @param bool $force Whether to run while processing is disabled.
	 * @param int  $job_limit Optional number of child jobs to process. Zero means no limit.
	 * @return void
	 */
	public function process_tick( $force = false, $job_limit = 0 ) {
		if ( ! $force && ! get_site_option( self::OPTION_PROCESSING_ENABLED, false ) ) {
			$this->store_telemetry(
				array(
					'phase'        => 'disabled',
					'last_message' => __( 'Processing is disabled.', 'as-content-stream' ),
				)
			);
			return;
		}

		self::create_queue_table();
		self::create_processing_queue_table();

		$started = microtime( true );
		$source_item = $this->get_next_source_queue_item();

		if ( $source_item ) {
			$this->explode_source_queue_item( $source_item );
		}

		$result = $this->process_pending_processing_queue( $job_limit );
		if ( $source_item ) {
			$this->complete_source_queue_item_if_ready( (int) $source_item->id );
		}

		$this->store_telemetry(
			array(
				'phase'        => $source_item ? $source_item->action : 'idle',
				'current_source_id' => $source_item ? (int) $source_item->id : 0,
				'batch_total'  => (int) $result['total'],
				'batch_done'   => (int) $result['done'],
				'last_message' => sprintf(
					/* translators: 1: processed count, 2: total count. */
					__( 'Processed %1$d processing jobs; %2$d remain queued.', 'as-content-stream' ),
					(int) $result['done'],
					(int) $result['total']
				),
				'last_batch_duration_ms' => $this->duration_ms( $started ),
			)
		);
	}

	/**
	 * Run one manual test processing tick while cron is disabled.
	 *
	 * @return void
	 */
	public function run_test_tick() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run Content Stream processing.', 'as-content-stream' ) );
		}

		check_admin_referer( self::NONCE_TEST_TICK );

		if ( ! get_site_option( self::OPTION_PROCESSING_ENABLED, false ) ) {
			$this->process_tick( true, 1 );
		}

		wp_safe_redirect( $this->admin_url( array( 'tab' => 'processing_queue' ) ) );
		exit;
	}

	/**
	 * Get next source queue item in discover, create, update, delete order.
	 *
	 * @return object|null
	 */
	private function get_next_source_queue_item() {
		global $wpdb;

		foreach ( array( 'discover', 'create', 'update', 'delete' ) as $action ) {
			foreach ( array( 'in_progress', 'pending' ) as $status ) {
				$item = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT * FROM ' . self::queue_table_name() . ' WHERE action = %s AND status = %s ORDER BY id ASC LIMIT 1',
						$action,
						$status
					)
				);

				if ( $item ) {
					return $item;
				}
			}
		}

		return null;
	}

	/**
	 * Explode one source queue row into one processing row per destination site.
	 *
	 * @param object $source_item Source queue row.
	 * @param int $limit Optional target limit. Zero means no limit.
	 * @return int Number of processing jobs created.
	 */
	private function explode_source_queue_item( $source_item, $limit = 0 ) {
		global $wpdb;

		if ( $this->processing_jobs_exist_for_parent( (int) $source_item->id ) ) {
			return 0;
		}

		$language_counts = $this->get_language_counts( $this->discover_sites() );
		$target_language = $this->get_effective_target_language( $language_counts );
		$targets = $this->get_processing_targets( $target_language );

		$wpdb->update(
			self::queue_table_name(),
			array( 'status' => 'in_progress' ),
			array( 'id' => (int) $source_item->id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( empty( $targets ) ) {
			$wpdb->update(
				self::queue_table_name(),
				array(
					'status'     => 'complete',
					'last_error' => __( 'No active destination sites matched the target language.', 'as-content-stream' ),
				),
				array( 'id' => (int) $source_item->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return 0;
		}

		if ( $limit > 0 ) {
			$targets = array_slice( $targets, 0, $limit );
		}

		$created = 0;
		foreach ( $targets as $target ) {
			if ( $this->processing_job_exists_for_target( (int) $source_item->id, (int) $target['blog_id'] ) ) {
				continue;
			}

			$payload = $this->decode_queue_payload( $source_item->payload );
			$payload['target_language'] = sanitize_key( $target_language );
			$link_id = 0;
			if ( ! empty( $payload['source_uuid'] ) ) {
				$link = $this->get_link_for_source_target( sanitize_text_field( $payload['source_uuid'] ), (int) $target['blog_id'], sanitize_key( $target_language ) );
				$link_id = $link ? (int) $link->id : 0;
			}

			$inserted = $wpdb->insert(
				self::processing_queue_table_name(),
				array(
					'created_at'      => current_time( 'mysql', true ),
					'parent_queue_id' => (int) $source_item->id,
					'link_id'         => $link_id,
					'action'          => sanitize_key( $source_item->action ),
					'status'          => 'pending',
					'source_blog_id'  => (int) $source_item->source_blog_id,
					'source_post_id'  => (int) $source_item->source_post_id,
					'target_blog_id'  => (int) $target['blog_id'],
					'target_language' => sanitize_key( $target_language ),
					'post_type'       => sanitize_key( $source_item->post_type ),
					'payload'         => wp_json_encode( $payload ),
				),
				array( '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
			);

			if ( $inserted ) {
				$created++;
			}
		}

		return $created;
	}

	/**
	 * Process pending child jobs for a source item.
	 *
	 * @param int $parent_queue_id Parent queue ID.
	 * @param int $limit Optional job limit. Zero means no limit.
	 * @return array<string,int>
	 */
	private function process_processing_jobs( $parent_queue_id, $limit = 0 ) {
		global $wpdb;

		$limit_sql = $limit > 0 ? ' LIMIT ' . absint( $limit ) : '';
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::processing_queue_table_name() . ' WHERE parent_queue_id = %d AND status = %s AND blocked_by = 0 ORDER BY priority DESC, id ASC' . $limit_sql,
				$parent_queue_id,
				'pending'
			)
		);
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::processing_queue_table_name() . ' WHERE parent_queue_id = %d',
				$parent_queue_id
			)
		);
		$done = 0;

		foreach ( $jobs as $job ) {
			if ( $this->process_processing_job_row( $job ) ) {
				$done++;
			}
		}

		return array(
			'total' => $total,
			'done'  => $done,
		);
	}

	/**
	 * Drain pending, unblocked processing jobs across the Processing Queue.
	 *
	 * @param int $limit Optional job limit. Zero means no limit.
	 * @return array<string,int>
	 */
	private function process_pending_processing_queue( $limit = 0 ) {
		global $wpdb;

		$processed = 0;
		$limit = absint( $limit );

		while ( 0 === $limit || $processed < $limit ) {
			$job = $wpdb->get_row(
				'SELECT * FROM ' . self::processing_queue_table_name() . " WHERE status = 'pending' AND blocked_by = 0 ORDER BY priority DESC, parent_queue_id ASC, id ASC LIMIT 1"
			);

			if ( ! $job ) {
				break;
			}

			if ( $this->process_processing_job_row( $job ) ) {
				$processed++;
				$this->complete_source_queue_item_if_ready( (int) $job->parent_queue_id );
			} else {
				break;
			}
		}

		$remaining = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . self::processing_queue_table_name() . " WHERE status = 'pending' AND blocked_by = 0"
		);

		return array(
			'total' => $remaining,
			'done'  => $processed,
		);
	}

	/**
	 * Process one processing queue row.
	 *
	 * @param object $job Processing job.
	 * @return bool
	 */
	private function process_processing_job_row( $job ) {
		global $wpdb;

		if ( ! empty( $job->blocked_by ) && ! $this->processing_job_is_complete( (int) $job->blocked_by ) ) {
			$wpdb->update(
				self::processing_queue_table_name(),
				array(
					'status'         => 'blocked',
					'result_message' => sprintf(
						/* translators: %d: blocking job ID. */
						__( 'Waiting for blocking job #%d.', 'as-content-stream' ),
						(int) $job->blocked_by
					),
				),
				array( 'id' => (int) $job->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return false;
		}

		$started = microtime( true );
		$wpdb->update(
			self::processing_queue_table_name(),
			array(
				'status'     => 'in_progress',
				'started_at' => current_time( 'mysql', true ),
				'attempts'   => (int) $job->attempts + 1,
			),
			array( 'id' => (int) $job->id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);

		$result = $this->process_processing_job( $job );
		$completed_at = in_array( $result['status'], array( 'complete', 'failed' ), true ) ? current_time( 'mysql', true ) : null;
		$updated = $wpdb->update(
			self::processing_queue_table_name(),
			array(
				'status'         => $result['status'],
				'completed_at'   => $completed_at,
				'duration_ms'    => $this->duration_ms( $started ),
				'result_message' => $result['message'],
			),
			array( 'id' => (int) $job->id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false !== $updated && 'complete' === $result['status'] ) {
			$this->unblock_processing_jobs_waiting_on( (int) $job->id );
		}

		return false !== $updated;
	}

	/**
	 * Process one destination job.
	 *
	 * @param object $job Processing job.
	 * @return array<string,string>
	 */
	private function process_processing_job( $job ) {
		$action = sanitize_key( $job->action );
		$post_type = sanitize_key( $job->post_type );

		if ( ! $this->is_streamable_post_type( $post_type ) ) {
			return $this->processing_result( 'complete', __( 'Non-streamable post type ignored.', 'as-content-stream' ) );
		}

		if ( 'delete' === $action ) {
			return $this->process_delete_job( $job );
		}

		if ( 'discover' === $action ) {
			return $this->process_discovery_job( $job );
		}

		if ( 'create' === $action || 'update' === $action ) {
			return $this->process_upsert_job( $job );
		}

		return $this->processing_result( 'failed', __( 'Unknown processing action.', 'as-content-stream' ) );
	}

	/**
	 * Discover one destination mapping.
	 *
	 * @param object $job Processing job.
	 * @return array<string,string>
	 */
	private function process_discovery_job( $job ) {
		$payload = $this->hydrate_processing_payload( $job, $this->decode_queue_payload( $job->payload ) );
		$source_uuid = isset( $payload['source_uuid'] ) ? sanitize_text_field( $payload['source_uuid'] ) : '';

		if ( '' === $source_uuid ) {
			return $this->processing_result( 'failed', __( 'Missing source stream UUID.', 'as-content-stream' ) );
		}

		$restore = get_current_blog_id() !== (int) $job->target_blog_id;
		if ( $restore ) {
			switch_to_blog( (int) $job->target_blog_id );
		}

		$mapped_id = $this->find_destination_post_id( $job, $payload );
		if ( $mapped_id && 'trash' !== get_post_status( $mapped_id ) ) {
			$link_id = $this->upsert_link( $job, (int) $mapped_id, $payload );
			if ( $link_id ) {
				$this->set_processing_job_link_id( (int) $job->id, $link_id );
			}

			if ( $restore ) {
				restore_current_blog();
			}
			return $this->processing_result( 'complete', sprintf( __( 'Existing destination mapped from Streaming Map memory and forced to draft. #%d', 'as-content-stream' ), (int) $mapped_id ) );
		}

		$legacy_id = $this->find_legacy_destination_post_id( $job, $payload );
		if ( is_wp_error( $legacy_id ) ) {
			if ( $restore ) {
				restore_current_blog();
			}
			return $this->processing_result( 'failed', $legacy_id->get_error_message() );
		}

		if ( $legacy_id ) {
			if ( 'trash' !== get_post_status( $legacy_id ) ) {
				$link_id = $this->upsert_link( $job, (int) $legacy_id, $payload );
				if ( $link_id ) {
					$this->set_processing_job_link_id( (int) $job->id, $link_id );
				}

				if ( $restore ) {
					restore_current_blog();
				}
				return $this->processing_result( 'complete', sprintf( __( 'Existing destination mapped from legacy metadata and forced to draft. #%d', 'as-content-stream' ), (int) $legacy_id ) );
			}
		}

		$slug_id = $this->find_destination_post_by_slug( $job, $payload );
		if ( $slug_id ) {
			if ( 'trash' !== get_post_status( $slug_id ) ) {
				$link_id = $this->upsert_link( $job, (int) $slug_id, $payload );
				if ( $link_id ) {
					$this->set_processing_job_link_id( (int) $job->id, $link_id );
				}

				if ( $restore ) {
					restore_current_blog();
				}
				return $this->processing_result( 'complete', sprintf( __( 'Existing destination mapped from slug and forced to draft. #%d', 'as-content-stream' ), (int) $slug_id ) );
			}
		}

		if ( $restore ) {
			restore_current_blog();
		}

		$create_job_id = $this->create_missing_destination_job_for_discovery( $job, $payload );
		if ( ! $create_job_id ) {
			return $this->processing_result( 'failed', __( 'Unable to create blocking create job for missing destination.', 'as-content-stream' ) );
		}

		$this->block_processing_job( (int) $job->id, $create_job_id );

		return $this->processing_result(
			'blocked',
			sprintf(
				/* translators: %d: create job ID. */
				__( 'No destination match found; created blocking create job #%d.', 'as-content-stream' ),
				$create_job_id
			)
		);
	}

	/**
	 * Create a normal create job when Discovery cannot find a destination.
	 *
	 * @param object              $discovery_job Discovery job.
	 * @param array<string,mixed> $payload Discovery payload.
	 * @return int
	 */
	private function create_missing_destination_job_for_discovery( $discovery_job, $payload ) {
		global $wpdb;

		self::create_processing_queue_table();

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::processing_queue_table_name() . " WHERE status <> 'complete' AND action = %s AND source_blog_id = %d AND source_post_id = %d AND target_blog_id = %d AND target_language = %s LIMIT 1",
				'create',
				(int) $discovery_job->source_blog_id,
				(int) $discovery_job->source_post_id,
				(int) $discovery_job->target_blog_id,
				sanitize_key( $discovery_job->target_language )
			)
		);

		if ( $existing ) {
			return (int) $existing;
		}

		$payload['target_language'] = sanitize_key( $discovery_job->target_language );
		$wpdb->insert(
			self::processing_queue_table_name(),
			array(
				'created_at'      => current_time( 'mysql', true ),
				'parent_queue_id' => (int) $discovery_job->parent_queue_id,
				'link_id'         => 0,
				'blocked_by'      => 0,
				'priority'        => (int) $discovery_job->priority + 10,
				'action'          => 'create',
				'status'          => 'pending',
				'source_blog_id'  => (int) $discovery_job->source_blog_id,
				'source_post_id'  => (int) $discovery_job->source_post_id,
				'target_blog_id'  => (int) $discovery_job->target_blog_id,
				'target_language' => sanitize_key( $discovery_job->target_language ),
				'post_type'       => sanitize_key( $discovery_job->post_type ),
				'payload'         => wp_json_encode( $payload ),
				'result_message'  => sprintf(
					/* translators: %d: discovery job ID. */
					__( 'Create destination for Discovery job #%d.', 'as-content-stream' ),
					(int) $discovery_job->id
				),
			),
			array( '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Create or update one destination post.
	 *
	 * @param object $job Processing job.
	 * @return array<string,string>
	 */
	private function process_upsert_job( $job ) {
		$payload = $this->hydrate_processing_payload( $job, $this->decode_queue_payload( $job->payload ) );
		$source_uuid = isset( $payload['source_uuid'] ) ? sanitize_text_field( $payload['source_uuid'] ) : '';

		if ( '' === $source_uuid ) {
			return $this->processing_result( 'failed', __( 'Missing source stream UUID.', 'as-content-stream' ) );
		}

		$restore = get_current_blog_id() !== (int) $job->target_blog_id;
		if ( $restore ) {
			switch_to_blog( (int) $job->target_blog_id );
		}

		$author_id = $this->ensure_stream_author_user();
		if ( is_wp_error( $author_id ) ) {
			if ( $restore ) {
				restore_current_blog();
			}
			return $this->processing_result( 'failed', $author_id->get_error_message() );
		}

		$site_result = $this->ensure_stream_author_on_site( (int) $job->target_blog_id, (int) $author_id );
		if ( is_wp_error( $site_result ) ) {
			if ( $restore ) {
				restore_current_blog();
			}
			return $this->processing_result( 'failed', $site_result->get_error_message() );
		}

		$action = sanitize_key( $job->action );
		$existing_id = $this->find_destination_post_id( $job, $payload );
		if ( $existing_id && 'trash' === get_post_status( $existing_id ) ) {
			if ( 'create' === $action ) {
				$existing_id = 0;
			} else {
				if ( $restore ) {
					restore_current_blog();
				}
				return $this->processing_result( 'complete', __( 'Destination is in trash; no update applied.', 'as-content-stream' ) );
			}
		}

		if ( $existing_id && 'create' === $action ) {
			$link_id = $this->upsert_link( $job, (int) $existing_id, $payload );
			if ( $link_id ) {
				$this->set_processing_job_link_id( (int) $job->id, $link_id );
			}
			if ( $restore ) {
				restore_current_blog();
			}
			return $this->processing_result( 'complete', sprintf( __( 'Destination already exists; mapped existing destination and forced to draft. #%d', 'as-content-stream' ), (int) $existing_id ) );
		}

		$result_id = $this->copy_source_post_sql_to_destination( $job, (int) $author_id, (int) $existing_id );
		if ( $result_id ) {
			$link_id = $this->upsert_link( $job, (int) $result_id, $payload );
			if ( $link_id ) {
				$this->set_processing_job_link_id( (int) $job->id, $link_id );
			}
			$this->set_wpml_language_for_destination( (int) $result_id, $job );

			$dependency = $this->ensure_meta_dependencies_for_job( $job );
			if ( $dependency ) {
				if ( $restore ) {
					restore_current_blog();
				}
				return $dependency;
			}

			$this->copy_source_postmeta_sql_to_destination( $job, (int) $result_id );
			$featured_result = $this->copy_featured_image_for_job( $job, (int) $result_id );
			if ( 'failed' === $featured_result['status'] || 'blocked' === $featured_result['status'] ) {
				if ( $restore ) {
					restore_current_blog();
				}
				return $featured_result;
			}
		}

		if ( $result_id && $existing_id ) {
			$message = __( 'Destination post updated.', 'as-content-stream' );
		} elseif ( $result_id ) {
			$message = __( 'Destination post created.', 'as-content-stream' );
		}

		if ( ! $result_id ) {
			if ( $restore ) {
				restore_current_blog();
			}
			return $this->processing_result( 'failed', __( 'Unable to copy source post SQL to destination.', 'as-content-stream' ) );
		}

		if ( $restore ) {
			restore_current_blog();
		}

		return $this->processing_result( 'complete', sprintf( '%s #%d', $message, (int) $result_id ) );
	}

	/**
	 * Trash one destination post.
	 *
	 * @param object $job Processing job.
	 * @return array<string,string>
	 */
	private function process_delete_job( $job ) {
		$payload = $this->hydrate_processing_payload( $job, $this->decode_queue_payload( $job->payload ) );
		$restore = get_current_blog_id() !== (int) $job->target_blog_id;
		if ( $restore ) {
			switch_to_blog( (int) $job->target_blog_id );
		}

		$existing_id = $this->find_destination_post_id( $job, $payload );
		if ( ! $existing_id ) {
			if ( $restore ) {
				restore_current_blog();
			}
			return $this->processing_result( 'complete', __( 'Destination did not exist; no delete needed.', 'as-content-stream' ) );
		}

		if ( 'trash' === get_post_status( $existing_id ) ) {
			if ( $restore ) {
				restore_current_blog();
			}
			return $this->processing_result( 'complete', __( 'Destination already in trash; no delete needed.', 'as-content-stream' ) );
		}

		$result = wp_trash_post( $existing_id );
		if ( $restore ) {
			restore_current_blog();
		}

		if ( ! $result ) {
			return $this->processing_result( 'failed', __( 'Unable to move destination to trash.', 'as-content-stream' ) );
		}

		$this->mark_link_deleted( $job, $existing_id, $payload );

		return $this->processing_result( 'complete', sprintf( __( 'Destination post moved to trash. #%d', 'as-content-stream' ), (int) $existing_id ) );
	}

	/**
	 * Build a processing result.
	 *
	 * @param string $status Result status.
	 * @param string $message Result message.
	 * @return array<string,string>
	 */
	private function processing_result( $status, $message ) {
		return array(
			'status'  => sanitize_key( $status ),
			'message' => sanitize_text_field( $message ),
		);
	}

	/**
	 * Fill missing processing payload fields from the source post.
	 *
	 * @param object              $job Processing job.
	 * @param array<string,mixed> $payload Processing payload.
	 * @return array<string,mixed>
	 */
	private function hydrate_processing_payload( $job, $payload ) {
		if ( ! empty( $payload['source_uuid'] ) && ! empty( $payload['post_title'] ) ) {
			return $payload;
		}

		$restore = get_current_blog_id() !== (int) $job->source_blog_id;
		if ( $restore ) {
			switch_to_blog( (int) $job->source_blog_id );
		}

		$post = get_post( (int) $job->source_post_id );
		if ( $post instanceof WP_Post ) {
			$payload = array_merge(
				$payload,
				array(
					'source_uuid'        => $this->get_or_create_source_uuid( (int) $job->source_blog_id, (int) $job->source_post_id ),
					'post_title'         => $post->post_title,
					'post_status'        => $post->post_status,
					'post_name'          => $post->post_name,
					'post_date'          => $post->post_date,
					'post_date_gmt'      => $post->post_date_gmt,
					'post_modified'      => $post->post_modified,
					'post_modified_gmt'  => $post->post_modified_gmt,
					'original_post_name' => $this->get_original_post_name( $post ),
				)
			);
		}

		if ( $restore ) {
			restore_current_blog();
		}

		$this->update_processing_job_payload( (int) $job->id, $payload );

		return $payload;
	}

	/**
	 * Persist a hydrated processing payload.
	 *
	 * @param int                 $job_id Processing job ID.
	 * @param array<string,mixed> $payload Processing payload.
	 * @return void
	 */
	private function update_processing_job_payload( $job_id, $payload ) {
		global $wpdb;

		$wpdb->update(
			self::processing_queue_table_name(),
			array( 'payload' => wp_json_encode( $payload ) ),
			array( 'id' => $job_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Find the matching destination post.
	 *
	 * @param object              $job Processing job.
	 * @param array<string,mixed> $payload Queue payload.
	 * @return int
	 */
	private function find_destination_post_id( $job, $payload ) {
		$source_uuid = isset( $payload['source_uuid'] ) ? sanitize_text_field( $payload['source_uuid'] ) : '';

		if ( '' !== $source_uuid ) {
			$link = $this->get_link_for_source_target( $source_uuid, (int) $job->target_blog_id, sanitize_key( $job->target_language ) );
			if ( $link && ! empty( $link->target_post_id ) ) {
				return (int) $link->target_post_id;
			}
		}

		return $this->find_destination_post_by_slug( $job, $payload );
	}

	/**
	 * Find a destination post by source slug.
	 *
	 * @param object              $job Processing job.
	 * @param array<string,mixed> $payload Queue payload.
	 * @return int
	 */
	private function find_destination_post_by_slug( $job, $payload ) {
		$slug = isset( $payload['original_post_name'] ) && '' !== $payload['original_post_name'] ? $payload['original_post_name'] : ( isset( $payload['post_name'] ) ? $payload['post_name'] : '' );
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return 0;
		}

		$post_type = sanitize_key( $job->post_type );
		$post = get_page_by_path( $slug, OBJECT, $post_type );
		if ( $post instanceof WP_Post && $this->post_matches_target_language_current_site( (int) $post->ID, $post_type, sanitize_key( $job->target_language ) ) ) {
			return (int) $post->ID;
		}

		return 0;
	}

	/**
	 * Find a destination post using legacy WFC Push Post metadata.
	 *
	 * @param object              $job Processing job.
	 * @param array<string,mixed> $payload Queue payload.
	 * @return int|WP_Error
	 */
	private function find_legacy_destination_post_id( $job, $payload ) {
		unset( $payload );

		$source_blog_id = (int) $job->source_blog_id;
		$source_post_id = (int) $job->source_post_id;
		$post_type = sanitize_key( $job->post_type );
		$target_language = sanitize_key( $job->target_language );
		$matches = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'   => 'wfc_source_record_id',
						'value' => $source_post_id,
					),
					array(
						'key'   => 'wfc_source_instance_id',
						'value' => $source_blog_id,
					),
				),
			)
		);

		if ( empty( $matches ) ) {
			$matches = get_posts(
				array(
					'post_type'        => $post_type,
					'post_status'      => 'any',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'suppress_filters' => true,
					'meta_key'         => 'wfc_source_record_id',
					'meta_value'       => $source_post_id,
				)
			);
		}

		$matches = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', (array) $matches ),
					function ( $post_id ) use ( $post_type, $target_language ) {
						return $this->post_matches_target_language_current_site( $post_id, $post_type, $target_language );
					}
				)
			)
		);

		if ( count( $matches ) > 1 ) {
			return new WP_Error( 'as_content_stream_legacy_conflict', __( 'Multiple legacy destination matches found.', 'as-content-stream' ) );
		}

		return empty( $matches ) ? 0 : (int) $matches[0];
	}

	/**
	 * Check the current site's WPML language for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $post_type Post type.
	 * @param string $target_language Target language.
	 * @return bool
	 */
	private function post_matches_target_language_current_site( $post_id, $post_type, $target_language ) {
		if ( '' === $target_language ) {
			return true;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'icl_translations';
		if ( ! $this->table_exists( $table_name ) ) {
			return true;
		}

		$language = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT language_code FROM {$table_name} WHERE element_id = %d AND element_type = %s LIMIT 1",
				$post_id,
				'post_' . sanitize_key( $post_type )
			)
		);

		return '' === (string) $language || sanitize_key( $language ) === $target_language;
	}

	/**
	 * Copy the source post row to the destination with SQL.
	 *
	 * @param object $job Processing job.
	 * @param int    $author_id Destination author ID.
	 * @param int    $existing_id Existing destination post ID, if any.
	 * @return int
	 */
	private function copy_source_post_sql_to_destination( $job, $author_id, $existing_id = 0 ) {
		global $wpdb;

		$source_table = $wpdb->get_blog_prefix( (int) $job->source_blog_id ) . 'posts';
		$target_table = $wpdb->get_blog_prefix( (int) $job->target_blog_id ) . 'posts';
		$source_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$source_table} WHERE ID = %d LIMIT 1",
				(int) $job->source_post_id
			),
			ARRAY_A
		);

		if ( empty( $source_row ) ) {
			return 0;
		}

		unset( $source_row['ID'] );
		$source_row['post_author'] = $author_id;
		$source_row['post_status'] = 'draft';
		$source_row['guid'] = '';
		$source_row['post_parent'] = 0;

		if ( $existing_id ) {
			$source_row['ID'] = $existing_id;
			$updated = $wpdb->replace( $target_table, $source_row );
			clean_post_cache( $existing_id );

			return false === $updated ? 0 : $existing_id;
		}

		$inserted = $wpdb->insert( $target_table, $source_row );
		if ( ! $inserted ) {
			return 0;
		}

		$insert_id = (int) $wpdb->insert_id;
		clean_post_cache( $insert_id );

		return $insert_id;
	}

	/**
	 * Force an existing destination post to draft without using post update hooks.
	 *
	 * @param int $blog_id Destination blog ID.
	 * @param int $post_id Destination post ID.
	 * @return bool
	 */
	private function force_destination_post_draft( $blog_id, $post_id ) {
		global $wpdb;

		$restore = get_current_blog_id() !== $blog_id;
		if ( $restore ) {
			switch_to_blog( $blog_id );
		}

		$table = $wpdb->get_blog_prefix( $blog_id ) . 'posts';
		$updated = $wpdb->update(
			$table,
			array( 'post_status' => 'draft' ),
			array( 'ID' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);

		clean_post_cache( $post_id );

		if ( $restore ) {
			restore_current_blog();
		}

		return false !== $updated;
	}

	/**
	 * Copy source postmeta rows to the destination with SQL.
	 *
	 * @param object $job Processing job.
	 * @param int    $destination_post_id Destination post ID.
	 * @return void
	 */
	private function copy_source_postmeta_sql_to_destination( $job, $destination_post_id ) {
		global $wpdb;

		$source_table = $wpdb->get_blog_prefix( (int) $job->source_blog_id ) . 'postmeta';
		$target_table = $wpdb->get_blog_prefix( (int) $job->target_blog_id ) . 'postmeta';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$source_table} WHERE post_id = %d",
				(int) $job->source_post_id
			),
			ARRAY_A
		);

		$wpdb->delete( $target_table, array( 'post_id' => $destination_post_id ), array( '%d' ) );
		foreach ( (array) $rows as $row ) {
			if ( '_thumbnail_id' === $row['meta_key'] ) {
				continue;
			}

			$meta_value = $this->translate_meta_post_ids( $row['meta_value'], (int) $job->source_blog_id, (int) $job->target_blog_id, sanitize_key( $job->target_language ) );
			$wpdb->insert(
				$target_table,
				array(
					'post_id'    => $destination_post_id,
					'meta_key'   => $row['meta_key'],
					'meta_value' => $meta_value,
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Translate source post IDs in a meta value to destination post IDs.
	 *
	 * @param mixed  $value Meta value.
	 * @param int    $source_blog_id Source blog ID.
	 * @param int    $target_blog_id Target blog ID.
	 * @param string $target_language Target language.
	 * @return mixed
	 */
	private function translate_meta_post_ids( $value, $source_blog_id, $target_blog_id, $target_language ) {
		$serialized = is_string( $value ) && is_serialized( $value );
		$decoded = $serialized ? maybe_unserialize( $value ) : $value;
		$translated = $this->translate_meta_post_ids_recursive( $decoded, $source_blog_id, $target_blog_id, $target_language );

		return $serialized ? maybe_serialize( $translated ) : $translated;
	}

	/**
	 * Translate source post IDs recursively.
	 *
	 * @param mixed  $value Meta value.
	 * @param int    $source_blog_id Source blog ID.
	 * @param int    $target_blog_id Target blog ID.
	 * @param string $target_language Target language.
	 * @return mixed
	 */
	private function translate_meta_post_ids_recursive( $value, $source_blog_id, $target_blog_id, $target_language ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->translate_meta_post_ids_recursive( $item, $source_blog_id, $target_blog_id, $target_language );
			}
			return $value;
		}

		if ( is_numeric( $value ) && (int) $value > 0 && $this->source_post_exists( $source_blog_id, (int) $value, false, true ) ) {
			$source_uuid = $this->get_or_create_source_uuid( $source_blog_id, (int) $value );
			$link = $this->get_link_for_source_target( $source_uuid, $target_blog_id, $target_language );
			if ( $link && ! empty( $link->target_post_id ) ) {
				return is_string( $value ) ? (string) (int) $link->target_post_id : (int) $link->target_post_id;
			}
		}

		return $value;
	}

	/**
	 * Copy a featured image attachment and assign it on the destination post.
	 *
	 * @param object $job Processing job.
	 * @param int    $destination_post_id Destination post ID.
	 * @return array<string,string>
	 */
	private function copy_featured_image_for_job( $job, $destination_post_id ) {
		$source_attachment_id = $this->get_source_featured_image_id( (int) $job->source_blog_id, (int) $job->source_post_id );
		if ( ! $source_attachment_id ) {
			return $this->processing_result( 'complete', __( 'No featured image to copy.', 'as-content-stream' ) );
		}

		$source_uuid = $this->get_or_create_source_uuid( (int) $job->source_blog_id, $source_attachment_id );
		$link = $this->get_link_for_source_target( $source_uuid, (int) $job->target_blog_id, sanitize_key( $job->target_language ) );
		$destination_attachment_id = $link && ! empty( $link->target_post_id ) ? (int) $link->target_post_id : 0;
		$destination_attachment_id = $this->copy_attachment_sql_to_destination( (int) $job->source_blog_id, $source_attachment_id, (int) $job->target_blog_id, $destination_attachment_id, sanitize_key( $job->target_language ), $source_uuid );

		if ( ! $destination_attachment_id ) {
			return $this->processing_result( 'failed', __( 'Unable to copy featured image attachment.', 'as-content-stream' ) );
		}

		$this->replace_postmeta_sql( (int) $job->target_blog_id, $destination_post_id, '_thumbnail_id', $destination_attachment_id );

		return $this->processing_result( 'complete', sprintf( __( 'Featured image copied. #%d', 'as-content-stream' ), $destination_attachment_id ) );
	}

	/**
	 * Get source featured image ID.
	 *
	 * @param int $blog_id Source blog ID.
	 * @param int $post_id Source post ID.
	 * @return int
	 */
	private function get_source_featured_image_id( $blog_id, $post_id ) {
		$restore = get_current_blog_id() !== $blog_id;
		if ( $restore ) {
			switch_to_blog( $blog_id );
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $post_id );

		if ( $restore ) {
			restore_current_blog();
		}

		return $thumbnail_id;
	}

	/**
	 * Copy an attachment row, file, and meta into the destination site.
	 *
	 * @param int    $source_blog_id Source blog ID.
	 * @param int    $source_attachment_id Source attachment ID.
	 * @param int    $target_blog_id Target blog ID.
	 * @param int    $existing_attachment_id Existing destination attachment ID.
	 * @param string $target_language Target language.
	 * @param string $source_uuid Source attachment UUID.
	 * @return int
	 */
	private function copy_attachment_sql_to_destination( $source_blog_id, $source_attachment_id, $target_blog_id, $existing_attachment_id, $target_language, $source_uuid ) {
		global $wpdb;

		$source_file = $this->get_attached_file_from_site( $source_blog_id, $source_attachment_id );
		if ( '' === $source_file || ! file_exists( $source_file ) ) {
			return 0;
		}

		$destination_file = $this->copy_file_to_uploads( $source_file, $target_blog_id );
		if ( '' === $destination_file ) {
			return 0;
		}

		$source_table = $wpdb->get_blog_prefix( $source_blog_id ) . 'posts';
		$target_table = $wpdb->get_blog_prefix( $target_blog_id ) . 'posts';
		$source_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$source_table} WHERE ID = %d LIMIT 1",
				$source_attachment_id
			),
			ARRAY_A
		);

		if ( empty( $source_row ) ) {
			return 0;
		}

		$destination_url = $this->upload_url_for_file( $target_blog_id, $destination_file );
		unset( $source_row['ID'] );
		$author_id = $this->ensure_stream_author_user();
		if ( is_wp_error( $author_id ) ) {
			return 0;
		}

		$source_row['post_author'] = (int) $author_id;
		$source_row['post_parent'] = 0;
		$source_row['guid'] = $destination_url;
		$source_row['post_status'] = 'inherit';

		if ( $existing_attachment_id ) {
			$source_row['ID'] = $existing_attachment_id;
			$written = $wpdb->replace( $target_table, $source_row );
			$destination_attachment_id = false === $written ? 0 : $existing_attachment_id;
		} else {
			$written = $wpdb->insert( $target_table, $source_row );
			$destination_attachment_id = $written ? (int) $wpdb->insert_id : 0;
		}

		if ( ! $destination_attachment_id ) {
			return 0;
		}

		$this->copy_source_postmeta_sql_to_destination_for_posts( $source_blog_id, $source_attachment_id, $target_blog_id, $destination_attachment_id );
		$this->copy_attachment_derivative_files( $source_blog_id, $source_attachment_id, $target_blog_id, $source_file, $destination_file );
		$this->replace_postmeta_sql( $target_blog_id, $destination_attachment_id, '_wp_attached_file', $this->relative_upload_path( $target_blog_id, $destination_file ) );
		$this->replace_attachment_metadata_file_path( $source_blog_id, $source_attachment_id, $target_blog_id, $destination_attachment_id, $destination_file );
		clean_post_cache( $destination_attachment_id );

		return $destination_attachment_id;
	}

	/**
	 * Copy postmeta rows for arbitrary source/destination post IDs.
	 *
	 * @param int $source_blog_id Source blog ID.
	 * @param int $source_post_id Source post ID.
	 * @param int $target_blog_id Target blog ID.
	 * @param int $target_post_id Target post ID.
	 * @return void
	 */
	private function copy_source_postmeta_sql_to_destination_for_posts( $source_blog_id, $source_post_id, $target_blog_id, $target_post_id ) {
		global $wpdb;

		$source_table = $wpdb->get_blog_prefix( $source_blog_id ) . 'postmeta';
		$target_table = $wpdb->get_blog_prefix( $target_blog_id ) . 'postmeta';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$source_table} WHERE post_id = %d",
				$source_post_id
			),
			ARRAY_A
		);

		$wpdb->delete( $target_table, array( 'post_id' => $target_post_id ), array( '%d' ) );
		foreach ( (array) $rows as $row ) {
			$wpdb->insert(
				$target_table,
				array(
					'post_id'    => $target_post_id,
					'meta_key'   => $row['meta_key'],
					'meta_value' => $row['meta_value'],
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Get an attached file path from a site.
	 *
	 * @param int $blog_id Blog ID.
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function get_attached_file_from_site( $blog_id, $attachment_id ) {
		$restore = get_current_blog_id() !== $blog_id;
		if ( $restore ) {
			switch_to_blog( $blog_id );
		}

		$file = (string) get_attached_file( $attachment_id );

		if ( $restore ) {
			restore_current_blog();
		}

		return $file;
	}

	/**
	 * Copy a file into a target site's uploads directory.
	 *
	 * @param string $source_file Source file path.
	 * @param int    $target_blog_id Target blog ID.
	 * @return string
	 */
	private function copy_file_to_uploads( $source_file, $target_blog_id ) {
		$restore = get_current_blog_id() !== $target_blog_id;
		if ( $restore ) {
			switch_to_blog( $target_blog_id );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['path'] ) ) {
			if ( $restore ) {
				restore_current_blog();
			}
			return '';
		}

		wp_mkdir_p( $uploads['path'] );
		$destination = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], basename( $source_file ) );
		$copied = copy( $source_file, $destination );

		if ( $restore ) {
			restore_current_blog();
		}

		return $copied ? $destination : '';
	}

	/**
	 * Get upload-relative path for a copied file.
	 *
	 * @param int    $blog_id Blog ID.
	 * @param string $file File path.
	 * @return string
	 */
	private function relative_upload_path( $blog_id, $file ) {
		$restore = get_current_blog_id() !== $blog_id;
		if ( $restore ) {
			switch_to_blog( $blog_id );
		}

		$uploads = wp_upload_dir();
		$relative = str_replace( trailingslashit( $uploads['basedir'] ), '', $file );

		if ( $restore ) {
			restore_current_blog();
		}

		return $relative;
	}

	/**
	 * Get a public upload URL for a file path.
	 *
	 * @param int    $blog_id Blog ID.
	 * @param string $file File path.
	 * @return string
	 */
	private function upload_url_for_file( $blog_id, $file ) {
		$restore = get_current_blog_id() !== $blog_id;
		if ( $restore ) {
			switch_to_blog( $blog_id );
		}

		$uploads = wp_upload_dir();
		$url = trailingslashit( $uploads['baseurl'] ) . str_replace( trailingslashit( $uploads['basedir'] ), '', $file );

		if ( $restore ) {
			restore_current_blog();
		}

		return $url;
	}

	/**
	 * Replace one postmeta key with SQL.
	 *
	 * @param int    $blog_id Blog ID.
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	private function replace_postmeta_sql( $blog_id, $post_id, $meta_key, $meta_value ) {
		global $wpdb;

		$table = $wpdb->get_blog_prefix( $blog_id ) . 'postmeta';
		$wpdb->delete(
			$table,
			array(
				'post_id'  => $post_id,
				'meta_key' => $meta_key,
			),
			array( '%d', '%s' )
		);
		$wpdb->insert(
			$table,
			array(
				'post_id'    => $post_id,
				'meta_key'   => $meta_key,
				'meta_value' => maybe_serialize( $meta_value ),
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Copy derivative image files listed in attachment metadata.
	 *
	 * @param int    $source_blog_id Source blog ID.
	 * @param int    $source_attachment_id Source attachment ID.
	 * @param int    $target_blog_id Target blog ID.
	 * @param string $source_file Source original file.
	 * @param string $destination_file Destination original file.
	 * @return void
	 */
	private function copy_attachment_derivative_files( $source_blog_id, $source_attachment_id, $target_blog_id, $source_file, $destination_file ) {
		$restore = get_current_blog_id() !== $source_blog_id;
		if ( $restore ) {
			switch_to_blog( $source_blog_id );
		}

		$metadata = wp_get_attachment_metadata( $source_attachment_id );

		if ( $restore ) {
			restore_current_blog();
		}

		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return;
		}

		$source_dir = trailingslashit( dirname( $source_file ) );
		$destination_dir = trailingslashit( dirname( $destination_file ) );
		wp_mkdir_p( $destination_dir );

		foreach ( $metadata['sizes'] as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}

			$source_size_file = $source_dir . basename( $size['file'] );
			$destination_size_file = $destination_dir . basename( $size['file'] );
			if ( file_exists( $source_size_file ) ) {
				copy( $source_size_file, $destination_size_file );
			}
		}
	}

	/**
	 * Update copied attachment metadata to reference the destination file path.
	 *
	 * @param int    $source_blog_id Source blog ID.
	 * @param int    $source_attachment_id Source attachment ID.
	 * @param int    $target_blog_id Target blog ID.
	 * @param int    $destination_attachment_id Destination attachment ID.
	 * @param string $destination_file Destination original file.
	 * @return void
	 */
	private function replace_attachment_metadata_file_path( $source_blog_id, $source_attachment_id, $target_blog_id, $destination_attachment_id, $destination_file ) {
		$restore = get_current_blog_id() !== $source_blog_id;
		if ( $restore ) {
			switch_to_blog( $source_blog_id );
		}

		$metadata = wp_get_attachment_metadata( $source_attachment_id );

		if ( $restore ) {
			restore_current_blog();
		}

		if ( ! is_array( $metadata ) ) {
			return;
		}

		$metadata['file'] = $this->relative_upload_path( $target_blog_id, $destination_file );
		$this->replace_postmeta_sql( $target_blog_id, $destination_attachment_id, '_wp_attachment_metadata', $metadata );
	}

	/**
	 * Ensure post ID dependencies in source meta exist on the destination.
	 *
	 * @param object $job Processing job.
	 * @return array<string,string>|null
	 */
	private function ensure_meta_dependencies_for_job( $job ) {
		$dependencies = $this->get_source_meta_post_dependencies( (int) $job->source_blog_id, (int) $job->source_post_id );

		foreach ( $dependencies as $source_post_id ) {
			if ( (int) $source_post_id === (int) $job->source_post_id ) {
				continue;
			}

			$source_uuid = $this->get_or_create_source_uuid( (int) $job->source_blog_id, (int) $source_post_id );
			$link = $this->get_link_for_source_target( $source_uuid, (int) $job->target_blog_id, sanitize_key( $job->target_language ) );
			if ( $link && ! empty( $link->target_post_id ) ) {
				continue;
			}

			$blocking_job_id = $this->create_blocking_processing_job( $job, (int) $source_post_id, $source_uuid );
			if ( $blocking_job_id ) {
				$this->block_processing_job( (int) $job->id, $blocking_job_id );

				return array(
					'status'  => 'blocked',
					'message' => sprintf(
						/* translators: 1: source post ID, 2: blocking job ID. */
						__( 'Blocked by related source post #%1$d; created priority job #%2$d.', 'as-content-stream' ),
						(int) $source_post_id,
						(int) $blocking_job_id
					),
				);
			}
		}

		return null;
	}

	/**
	 * Get source post IDs referenced in meta values.
	 *
	 * @param int $blog_id Source blog ID.
	 * @param int $post_id Source post ID.
	 * @return int[]
	 */
	private function get_source_meta_post_dependencies( $blog_id, $post_id ) {
		global $wpdb;

		$table = $wpdb->get_blog_prefix( $blog_id ) . 'postmeta';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$table} WHERE post_id = %d",
				$post_id
			),
			ARRAY_A
		);
		$dependencies = array();

		foreach ( (array) $rows as $row ) {
			if ( '_thumbnail_id' === $row['meta_key'] || 0 === strpos( (string) $row['meta_key'], '_' ) ) {
				continue;
			}

			foreach ( $this->extract_possible_post_ids( $row['meta_value'] ) as $possible_id ) {
				if ( $this->source_post_exists( $blog_id, $possible_id, false, true ) ) {
					$dependencies[] = $possible_id;
				}
			}
		}

		return array_values( array_unique( array_map( 'absint', $dependencies ) ) );
	}

	/**
	 * Extract possible post IDs from scalar or serialized meta values.
	 *
	 * @param mixed $value Meta value.
	 * @return int[]
	 */
	private function extract_possible_post_ids( $value ) {
		if ( is_string( $value ) && is_serialized( $value ) ) {
			return $this->extract_possible_post_ids( maybe_unserialize( $value ) );
		}

		if ( is_array( $value ) ) {
			$ids = array();
			foreach ( $value as $item ) {
				$ids = array_merge( $ids, $this->extract_possible_post_ids( $item ) );
			}
			return $ids;
		}

		if ( is_numeric( $value ) && (int) $value > 0 ) {
			return array( (int) $value );
		}

		return array();
	}

	/**
	 * Check whether a source post exists.
	 *
	 * @param int  $blog_id Blog ID.
	 * @param int  $post_id Post ID.
	 * @param bool $allow_attachment Whether attachments count.
	 * @param bool $require_streamable Whether internal structural post types should be ignored.
	 * @return bool
	 */
	private function source_post_exists( $blog_id, $post_id, $allow_attachment = true, $require_streamable = false ) {
		$restore = get_current_blog_id() !== $blog_id;
		if ( $restore ) {
			switch_to_blog( $blog_id );
		}

		$post = get_post( $post_id );
		$exists = $post instanceof WP_Post && ( $allow_attachment || 'attachment' !== $post->post_type );
		if ( $exists && $require_streamable ) {
			$exists = $this->is_streamable_post_type( $post->post_type );
		}

		if ( $restore ) {
			restore_current_blog();
		}

		return $exists;
	}

	/**
	 * Create a priority blocking job for a related source post.
	 *
	 * @param object $blocked_job Job being blocked.
	 * @param int    $source_post_id Related source post ID.
	 * @param string $source_uuid Related source UUID.
	 * @return int
	 */
	private function create_blocking_processing_job( $blocked_job, $source_post_id, $source_uuid ) {
		global $wpdb;

		self::create_processing_queue_table();

		$post_type = $this->get_post_type_from_site( (int) $blocked_job->source_blog_id, $source_post_id );
		if ( '' === $post_type || ! $this->is_streamable_post_type( $post_type ) ) {
			return 0;
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::processing_queue_table_name() . " WHERE status <> 'complete' AND source_blog_id = %d AND source_post_id = %d AND target_blog_id = %d AND target_language = %s LIMIT 1",
				(int) $blocked_job->source_blog_id,
				$source_post_id,
				(int) $blocked_job->target_blog_id,
				sanitize_key( $blocked_job->target_language )
			)
		);
		if ( $existing ) {
			return (int) $existing;
		}

		$payload = $this->build_source_payload_from_post( (int) $blocked_job->source_blog_id, $source_post_id, $source_uuid, sanitize_key( $blocked_job->target_language ) );
		$wpdb->insert(
			self::processing_queue_table_name(),
			array(
				'created_at'      => current_time( 'mysql', true ),
				'parent_queue_id' => (int) $blocked_job->parent_queue_id,
				'link_id'         => 0,
				'blocked_by'      => 0,
				'priority'        => (int) $blocked_job->priority + 10,
				'action'          => 'update',
				'status'          => 'pending',
				'source_blog_id'  => (int) $blocked_job->source_blog_id,
				'source_post_id'  => $source_post_id,
				'target_blog_id'  => (int) $blocked_job->target_blog_id,
				'target_language' => sanitize_key( $blocked_job->target_language ),
				'post_type'       => $post_type,
				'payload'         => wp_json_encode( $payload ),
				'result_message'  => sprintf(
					/* translators: %d: blocked job ID. */
					__( 'Priority dependency for blocked job #%d.', 'as-content-stream' ),
					(int) $blocked_job->id
				),
			),
			array( '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Mark a processing job as blocked by another job.
	 *
	 * @param int $job_id Blocked job ID.
	 * @param int $blocked_by Blocking job ID.
	 * @return void
	 */
	private function block_processing_job( $job_id, $blocked_by ) {
		global $wpdb;

		$wpdb->update(
			self::processing_queue_table_name(),
			array(
				'status'     => 'blocked',
				'blocked_by' => $blocked_by,
			),
			array( 'id' => $job_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Upsert a source/destination link record.
	 *
	 * @param object              $job Processing job.
	 * @param int                 $destination_post_id Destination post ID.
	 * @param array<string,mixed> $payload Queue payload.
	 * @return int
	 */
	private function upsert_link( $job, $destination_post_id, $payload ) {
		global $wpdb;

		self::create_links_table();

		$source_uuid = isset( $payload['source_uuid'] ) ? sanitize_text_field( $payload['source_uuid'] ) : '';
		if ( '' === $source_uuid ) {
			$source_uuid = wp_generate_uuid4();
		}

		$source_slug = isset( $payload['original_post_name'] ) && '' !== $payload['original_post_name'] ? sanitize_title( $payload['original_post_name'] ) : sanitize_title( isset( $payload['post_name'] ) ? $payload['post_name'] : '' );
		$target_slug = $this->get_post_slug_from_site( (int) $job->target_blog_id, $destination_post_id );
		$link = $this->get_link_for_source_target( $source_uuid, (int) $job->target_blog_id, sanitize_key( $job->target_language ) );
		if ( ! $link && 'attachment' !== sanitize_key( $job->post_type ) ) {
			$link = $this->get_link_for_concrete_map( (int) $job->source_blog_id, (int) $job->source_post_id, sanitize_key( $job->post_type ), (int) $job->target_blog_id, sanitize_key( $job->target_language ) );
		}
		if ( 'attachment' !== sanitize_key( $job->post_type ) ) {
			$this->force_destination_post_draft( (int) $job->target_blog_id, $destination_post_id );
		}
		$data = array(
			'updated_at'              => current_time( 'mysql', true ),
			'last_streamed_at'        => current_time( 'mysql', true ),
			'source_uuid'             => $source_uuid,
			'source_blog_id'          => (int) $job->source_blog_id,
			'source_post_id'          => (int) $job->source_post_id,
			'source_post_type'        => sanitize_key( $job->post_type ),
			'source_slug'             => $source_slug,
			'target_blog_id'          => (int) $job->target_blog_id,
			'target_post_id'          => $destination_post_id,
			'target_language'         => sanitize_key( $job->target_language ),
			'target_slug'             => $target_slug,
			'status'                  => 'attachment' === sanitize_key( $job->post_type ) ? 'media' : 'active',
			'last_action'             => sanitize_key( $job->action ),
			'last_queue_id'           => (int) $job->parent_queue_id,
			'last_processing_job_id'  => (int) $job->id,
		);
		$formats = array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d' );

		if ( $link ) {
			$wpdb->update( self::links_table_name(), $data, array( 'id' => (int) $link->id ), $formats, array( '%d' ) );
			$this->delete_duplicate_streaming_map_rows( (int) $link->id, $data );
			return (int) $link->id;
		}

		$data['created_at'] = current_time( 'mysql', true );
		$wpdb->insert(
			self::links_table_name(),
			$data,
			array_merge( $formats, array( '%s' ) )
		);

		$link_id = (int) $wpdb->insert_id;
		if ( $link_id ) {
			$this->delete_duplicate_streaming_map_rows( $link_id, $data );
		}

		return $link_id;
	}

	/**
	 * Delete duplicate map rows for the same concrete relationship.
	 *
	 * @param int                 $keep_id Link ID to keep.
	 * @param array<string,mixed> $data Link data.
	 * @return void
	 */
	private function delete_duplicate_streaming_map_rows( $keep_id, $data ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::links_table_name() . ' WHERE id <> %d AND source_blog_id = %d AND source_post_id = %d AND source_post_type = %s AND target_blog_id = %d AND target_language = %s',
				$keep_id,
				(int) $data['source_blog_id'],
				(int) $data['source_post_id'],
				sanitize_key( $data['source_post_type'] ),
				(int) $data['target_blog_id'],
				sanitize_key( $data['target_language'] )
			)
		);
	}

	/**
	 * Mark an existing link as deleted.
	 *
	 * @param object              $job Processing job.
	 * @param int                 $destination_post_id Destination post ID.
	 * @param array<string,mixed> $payload Queue payload.
	 * @return void
	 */
	private function mark_link_deleted( $job, $destination_post_id, $payload ) {
		global $wpdb;

		$source_uuid = isset( $payload['source_uuid'] ) ? sanitize_text_field( $payload['source_uuid'] ) : '';
		if ( '' === $source_uuid ) {
			return;
		}

		$link = $this->get_link_for_source_target( $source_uuid, (int) $job->target_blog_id, sanitize_key( $job->target_language ) );
		if ( ! $link ) {
			return;
		}

		$wpdb->update(
			self::links_table_name(),
			array(
				'updated_at'             => current_time( 'mysql', true ),
				'last_streamed_at'       => current_time( 'mysql', true ),
				'target_post_id'         => $destination_post_id,
				'status'                 => 'trashed',
				'last_action'            => 'delete',
				'last_queue_id'          => (int) $job->parent_queue_id,
				'last_processing_job_id' => (int) $job->id,
			),
			array( 'id' => (int) $link->id ),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Get one link by ID.
	 *
	 * @param int $link_id Link ID.
	 * @return object|null
	 */
	private function get_link( $link_id ) {
		global $wpdb;

		self::create_links_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::links_table_name() . ' WHERE id = %d LIMIT 1',
				$link_id
			)
		);
	}

	/**
	 * Get links, optionally filtered by source or destination post ID.
	 *
	 * @param int $lookup_id Optional post ID lookup.
	 * @return array<int,object>
	 */
	private function get_links( $lookup_id = 0, $limit = 50, $offset = 0, $snapshot_id = 0 ) {
		global $wpdb;

		self::create_links_table();
		$limit = min( 50, max( 1, absint( $limit ) ) );
		$offset = absint( $offset );
		$snapshot_id = absint( $snapshot_id );

		$post_types = $this->get_discoverable_source_post_types();
		$targets = $this->get_discovery_targets();
		$target_ids = array_map(
			static function ( $target ) {
				return (int) $target['blog_id'];
			},
			$targets
		);
		$language_counts = $this->get_language_counts( $this->discover_sites() );
		$target_language = $this->get_effective_target_language( $language_counts );

		if ( empty( $post_types ) || empty( $target_ids ) || '' === $target_language ) {
			return array();
		}

		$links_table = self::links_table_name();
		$posts_table = $wpdb->get_blog_prefix( get_main_site_id() ) . 'posts';
		$post_type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$target_placeholders = implode( ',', array_fill( 0, count( $target_ids ), '%d' ) );
		$sql = "SELECT l.* FROM {$links_table} l
			INNER JOIN {$posts_table} p
				ON p.ID = l.source_post_id
				AND p.post_status = %s
				AND p.post_type IN ({$post_type_placeholders})
			WHERE l.status = %s
				AND l.source_blog_id = %d
				AND l.target_language = %s
				AND l.target_blog_id IN ({$target_placeholders})";
		$args = array_merge(
			array( 'publish' ),
			$post_types,
			array( 'active', get_main_site_id(), sanitize_key( $target_language ) ),
			$target_ids
		);
		if ( $snapshot_id ) {
			$sql .= ' AND l.id <= %d';
			$args[] = $snapshot_id;
		}

		if ( $lookup_id ) {
			$sql .= ' AND (l.source_post_id = %d OR l.target_post_id = %d)';
			$args[] = $lookup_id;
			$args[] = $lookup_id;
			$sql .= ' ORDER BY l.updated_at DESC LIMIT %d OFFSET %d';
			$args[] = $limit;
			$args[] = $offset;

			return $wpdb->get_results(
				$wpdb->prepare( $sql, $args )
			);
		}

		$sql .= ' ORDER BY l.updated_at DESC LIMIT %d OFFSET %d';
		$args[] = $limit;
		$args[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Get the current Streaming Map snapshot ID.
	 *
	 * @param int $lookup_id Optional post ID lookup.
	 * @return int
	 */
	private function get_links_snapshot_id( $lookup_id = 0 ) {
		global $wpdb;

		self::create_links_table();

		$post_types = $this->get_discoverable_source_post_types();
		$targets = $this->get_discovery_targets();
		$target_ids = array_map(
			static function ( $target ) {
				return (int) $target['blog_id'];
			},
			$targets
		);
		$language_counts = $this->get_language_counts( $this->discover_sites() );
		$target_language = $this->get_effective_target_language( $language_counts );

		if ( empty( $post_types ) || empty( $target_ids ) || '' === $target_language ) {
			return 0;
		}

		$links_table = self::links_table_name();
		$posts_table = $wpdb->get_blog_prefix( get_main_site_id() ) . 'posts';
		$post_type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$target_placeholders = implode( ',', array_fill( 0, count( $target_ids ), '%d' ) );
		$sql = "SELECT MAX(l.id) FROM {$links_table} l
			INNER JOIN {$posts_table} p
				ON p.ID = l.source_post_id
				AND p.post_status = %s
				AND p.post_type IN ({$post_type_placeholders})
			WHERE l.status = %s
				AND l.source_blog_id = %d
				AND l.target_language = %s
				AND l.target_blog_id IN ({$target_placeholders})";
		$args = array_merge(
			array( 'publish' ),
			$post_types,
			array( 'active', get_main_site_id(), sanitize_key( $target_language ) ),
			$target_ids
		);

		if ( $lookup_id ) {
			$sql .= ' AND (l.source_post_id = %d OR l.target_post_id = %d)';
			$args[] = $lookup_id;
			$args[] = $lookup_id;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Find a link for a source UUID, destination site, and target language.
	 *
	 * @param string $source_uuid Source UUID.
	 * @param int    $target_blog_id Target blog ID.
	 * @param string $target_language Target language.
	 * @return object|null
	 */
	private function get_link_for_source_target( $source_uuid, $target_blog_id, $target_language ) {
		global $wpdb;

		self::create_links_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::links_table_name() . ' WHERE source_uuid = %s AND target_blog_id = %d AND target_language = %s LIMIT 1',
				sanitize_text_field( $source_uuid ),
				$target_blog_id,
				sanitize_key( $target_language )
			)
		);
	}

	/**
	 * Find a link for a concrete source/destination map.
	 *
	 * @param int    $source_blog_id Source blog ID.
	 * @param int    $source_post_id Source post ID.
	 * @param string $post_type Source post type.
	 * @param int    $target_blog_id Target blog ID.
	 * @param string $target_language Target language.
	 * @return object|null
	 */
	private function get_link_for_concrete_map( $source_blog_id, $source_post_id, $post_type, $target_blog_id, $target_language ) {
		global $wpdb;

		self::create_links_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::links_table_name() . ' WHERE source_blog_id = %d AND source_post_id = %d AND source_post_type = %s AND target_blog_id = %d AND target_language = %s ORDER BY updated_at DESC, id DESC LIMIT 1',
				$source_blog_id,
				$source_post_id,
				sanitize_key( $post_type ),
				$target_blog_id,
				sanitize_key( $target_language )
			)
		);
	}

	/**
	 * Get an existing source UUID from links.
	 *
	 * @param int $source_blog_id Source blog ID.
	 * @param int $source_post_id Source post ID.
	 * @return string
	 */
	private function get_source_uuid_from_links( $source_blog_id, $source_post_id ) {
		global $wpdb;

		self::create_links_table();

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT source_uuid FROM ' . self::links_table_name() . ' WHERE source_blog_id = %d AND source_post_id = %d ORDER BY id ASC LIMIT 1',
				$source_blog_id,
				$source_post_id
			)
		);
	}

	/**
	 * Get a post slug from a site.
	 *
	 * @param int $blog_id Blog ID.
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function get_post_slug_from_site( $blog_id, $post_id ) {
		$restore = get_current_blog_id() !== $blog_id;
		if ( $restore ) {
			switch_to_blog( $blog_id );
		}

		$post = get_post( $post_id );
		$slug = $post instanceof WP_Post ? sanitize_title( $post->post_name ) : '';

		if ( $restore ) {
			restore_current_blog();
		}

		return $slug;
	}

	/**
	 * Get a post title from a site.
	 *
	 * @param int $blog_id Blog ID.
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function get_post_title_from_site( $blog_id, $post_id ) {
		$restore = get_current_blog_id() !== $blog_id;
		if ( $restore ) {
			switch_to_blog( $blog_id );
		}

		$post = get_post( $post_id );
		$title = $post instanceof WP_Post ? sanitize_text_field( $post->post_title ) : '';

		if ( $restore ) {
			restore_current_blog();
		}

		return $title;
	}

	/**
	 * Store a link ID on a processing job.
	 *
	 * @param int $job_id Processing job ID.
	 * @param int $link_id Link ID.
	 * @return void
	 */
	private function set_processing_job_link_id( $job_id, $link_id ) {
		global $wpdb;

		$wpdb->update(
			self::processing_queue_table_name(),
			array( 'link_id' => $link_id ),
			array( 'id' => $job_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Determine whether a processing job completed.
	 *
	 * @param int $job_id Processing job ID.
	 * @return bool
	 */
	private function processing_job_is_complete( $job_id ) {
		global $wpdb;

		return 'complete' === (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT status FROM ' . self::processing_queue_table_name() . ' WHERE id = %d LIMIT 1',
				$job_id
			)
		);
	}

	/**
	 * Unblock jobs waiting on a completed dependency.
	 *
	 * @param int $job_id Blocking job ID.
	 * @return void
	 */
	private function unblock_processing_jobs_waiting_on( $job_id ) {
		global $wpdb;

		$wpdb->update(
			self::processing_queue_table_name(),
			array(
				'status'         => 'pending',
				'blocked_by'     => 0,
				'result_message' => __( 'Dependency complete; queued to continue.', 'as-content-stream' ),
			),
			array( 'blocked_by' => $job_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Assign WPML language metadata when WPML is available.
	 *
	 * @param int    $destination_post_id Destination post ID.
	 * @param object $job Processing job.
	 * @return void
	 */
	private function set_wpml_language_for_destination( $destination_post_id, $job ) {
		if ( ! has_action( 'wpml_set_element_language_details' ) ) {
			return;
		}

		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $destination_post_id,
				'element_type'         => 'post_' . sanitize_key( $job->post_type ),
				'trid'                 => false,
				'language_code'        => sanitize_key( $job->target_language ),
				'source_language_code' => null,
			)
		);
	}

	/**
	 * Complete the source item once child processing jobs are terminal.
	 *
	 * @param int $parent_queue_id Parent queue ID.
	 * @return void
	 */
	private function complete_source_queue_item_if_ready( $parent_queue_id ) {
		global $wpdb;

		$open_jobs = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::processing_queue_table_name() . " WHERE parent_queue_id = %d AND status <> 'complete'",
				$parent_queue_id
			)
		);

		if ( 0 !== $open_jobs ) {
			return;
		}

		$wpdb->update(
			self::queue_table_name(),
			array( 'status' => 'complete' ),
			array( 'id' => $parent_queue_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Clear pending queue items.
	 *
	 * @return void
	 */
	public function clear_queue() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to update Content Stream.', 'as-content-stream' ) );
		}

		check_admin_referer( self::NONCE_QUEUE );

		global $wpdb;
		$wpdb->delete( self::queue_table_name(), array( 'status' => 'pending' ), array( '%s' ) );

		wp_safe_redirect( $this->admin_url( array( 'tab' => 'create_queue', 'queue_cleared' => 1 ) ) );
		exit;
	}

	/**
	 * Explode one source queue item into processing jobs.
	 *
	 * @return void
	 */
	public function run_queue_item() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to update Content Stream.', 'as-content-stream' ) );
		}

		check_admin_referer( self::NONCE_QUEUE );

		$queue_id = isset( $_POST['queue_id'] ) ? absint( $_POST['queue_id'] ) : 0;
		if ( $queue_id ) {
			global $wpdb;
			self::create_queue_table();
			self::create_processing_queue_table();
			$item = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . self::queue_table_name() . ' WHERE id = %d AND status <> %s LIMIT 1',
					$queue_id,
					'complete'
				)
			);

			if ( $item ) {
				$this->explode_source_queue_item( $item );
			}
		}

		wp_safe_redirect( $this->admin_url( array( 'tab' => 'processing_queue' ) ) );
		exit;
	}

	/**
	 * Clear and rebuild non-complete Discovery queue rows.
	 *
	 * @return void
	 */
	public function rerun_discovery() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to update Content Stream.', 'as-content-stream' ) );
		}

		check_admin_referer( self::NONCE_QUEUE );

		global $wpdb;
		self::create_queue_table();
		self::create_processing_queue_table();

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::processing_queue_table_name() . ' WHERE action = %s AND status <> %s',
				'discover',
				'complete'
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::queue_table_name() . ' WHERE action = %s AND status <> %s',
				'discover',
				'complete'
			)
		);

		$this->refresh_discovery_queue();

		wp_safe_redirect( $this->admin_url( array( 'tab' => 'discovery_queue', 'discovery_refreshed' => 1 ) ) );
		exit;
	}

	/**
	 * Re-queue legacy skipped processing rows so they can be resolved under current rules.
	 *
	 * @return void
	 */
	public function normalize_skipped_processing_jobs() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		self::create_processing_queue_table();

		$wpdb->update(
			self::processing_queue_table_name(),
			array(
				'status'         => 'pending',
				'completed_at'   => null,
				'result_message' => __( 'Re-queued after skipped status was retired.', 'as-content-stream' ),
			),
			array( 'status' => 'skipped' ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Collapse duplicate Streaming Map rows into the newest current relationship.
	 *
	 * @return void
	 */
	public function normalize_streaming_map_rows() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		self::create_links_table();

		$table = self::links_table_name();
		$wpdb->query(
			"DELETE older FROM {$table} older
			INNER JOIN {$table} newer
				ON newer.source_blog_id = older.source_blog_id
				AND newer.source_post_id = older.source_post_id
				AND newer.source_post_type = older.source_post_type
				AND newer.target_blog_id = older.target_blog_id
				AND newer.target_language = older.target_language
				AND (
					newer.updated_at > older.updated_at
					OR (newer.updated_at = older.updated_at AND newer.id > older.id)
			)"
		);
		$wpdb->query(
			"DELETE older FROM {$table} older
			INNER JOIN {$table} newer
				ON newer.source_uuid = older.source_uuid
				AND newer.target_blog_id = older.target_blog_id
				AND newer.target_language = older.target_language
				AND (
					newer.updated_at > older.updated_at
					OR (newer.updated_at = older.updated_at AND newer.id > older.id)
			)"
		);

		$this->reconcile_current_streaming_map_rows();
	}

	/**
	 * Mark stale active Streaming Map rows as inactive.
	 *
	 * @return void
	 */
	private function reconcile_current_streaming_map_rows() {
		global $wpdb;

		$post_types = $this->get_discoverable_source_post_types();
		$targets = $this->get_discovery_targets();
		$target_ids = array_map(
			static function ( $target ) {
				return (int) $target['blog_id'];
			},
			$targets
		);
		$language_counts = $this->get_language_counts( $this->discover_sites() );
		$target_language = $this->get_effective_target_language( $language_counts );
		$links_table = self::links_table_name();
		$posts_table = $wpdb->get_blog_prefix( get_main_site_id() ) . 'posts';
		$now = current_time( 'mysql', true );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$links_table} SET updated_at = %s, status = %s WHERE status = %s AND source_post_type = %s",
				$now,
				'media',
				'active',
				'attachment'
			)
		);

		if ( empty( $post_types ) || empty( $target_ids ) || '' === $target_language ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$links_table} SET updated_at = %s, status = %s WHERE status = %s",
					$now,
					'inactive',
					'active'
				)
			);
			return;
		}

		$post_type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$target_placeholders = implode( ',', array_fill( 0, count( $target_ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$links_table} l
				LEFT JOIN {$posts_table} p
					ON p.ID = l.source_post_id
					AND p.post_status = %s
					AND p.post_type IN ({$post_type_placeholders})
				SET l.updated_at = %s, l.status = %s
				WHERE l.status = %s
					AND (
						l.source_blog_id <> %d
						OR p.ID IS NULL
						OR l.target_language <> %s
						OR l.target_blog_id NOT IN ({$target_placeholders})
					)",
				array_merge(
					array( 'publish' ),
					$post_types,
					array( $now, 'inactive', 'active', get_main_site_id(), sanitize_key( $target_language ) ),
					$target_ids
				)
			)
		);
	}

	/**
	 * Reconcile active Streaming Map rows against currently active WPML sites.
	 *
	 * @param array<int,array<string,mixed>> $sites Inspected destination sites.
	 * @return void
	 */
	private function reconcile_streaming_map_for_sites( $sites ) {
		if ( ! is_multisite() || ! is_main_site() ) {
			return;
		}

		global $wpdb;
		self::create_links_table();

		$inactive_ids = array();
		foreach ( $sites as $site ) {
			if ( empty( $site['blog_id'] ) || ! empty( $site['wpml_active'] ) ) {
				continue;
			}

			$inactive_ids[] = (int) $site['blog_id'];
		}

		if ( empty( $inactive_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $inactive_ids ), '%d' ) );
		$query_args = array_merge( array( current_time( 'mysql', true ), 'inactive_wpml', 'active' ), $inactive_ids );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::links_table_name() . " SET updated_at = %s, status = %s WHERE status = %s AND target_blog_id IN ({$placeholders})",
				$query_args
			)
		);
	}

	/**
	 * Refresh Discovery once when active WPML destination targets change.
	 *
	 * @param array<int,array<string,mixed>> $sites Inspected destination sites.
	 * @return void
	 */
	private function refresh_discovery_queue_after_target_change( $sites ) {
		if ( ! is_multisite() || ! is_main_site() ) {
			return;
		}

		$signature = $this->target_signature_for_sites( $sites );
		$previous = (string) get_site_option( self::OPTION_TARGET_SIGNATURE, '' );

		if ( '' === $previous ) {
			update_site_option( self::OPTION_TARGET_SIGNATURE, $signature );
			return;
		}

		if ( $previous === $signature ) {
			return;
		}

		update_site_option( self::OPTION_TARGET_SIGNATURE, $signature );
		$this->refresh_discovery_queue();
	}

	/**
	 * Build a stable signature for active WPML destination targets.
	 *
	 * @param array<int,array<string,mixed>> $sites Inspected destination sites.
	 * @return string
	 */
	private function target_signature_for_sites( $sites ) {
		$parts = array();
		foreach ( $sites as $site ) {
			if ( empty( $site['blog_id'] ) || empty( $site['wpml_active'] ) || empty( $site['languages'] ) || ! is_array( $site['languages'] ) ) {
				continue;
			}

			$languages = array_values( array_unique( array_filter( array_map( 'sanitize_key', $site['languages'] ) ) ) );
			sort( $languages );
			$parts[] = (int) $site['blog_id'] . ':' . implode( ',', $languages );
		}

		sort( $parts );

		return md5( implode( '|', $parts ) );
	}

	/**
	 * Clear terminal processing log rows.
	 *
	 * @return void
	 */
	public function clear_log() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to update Content Stream.', 'as-content-stream' ) );
		}

		check_admin_referer( self::NONCE_LOG );

		global $wpdb;
		self::create_processing_queue_table();
		$wpdb->query( "DELETE FROM " . self::processing_queue_table_name() . " WHERE status = 'complete'" );

		wp_safe_redirect( $this->admin_url( array( 'tab' => 'log', 'log_cleared' => 1 ) ) );
		exit;
	}

	/**
	 * Run a processing job manually.
	 *
	 * @return void
	 */
	public function run_processing_job() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to update Content Stream.', 'as-content-stream' ) );
		}

		check_admin_referer( self::NONCE_PROCESSING );

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		if ( $job_id ) {
			global $wpdb;
			self::create_processing_queue_table();
			$job = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . self::processing_queue_table_name() . ' WHERE id = %d AND status <> %s',
					$job_id,
					'complete'
				)
			);
			if ( $job ) {
				$is_blocked = 'blocked' === sanitize_key( $job->status ) || ! empty( $job->blocked_by );
				if ( ! $is_blocked ) {
					$this->process_processing_job_row( $job );
					$this->complete_source_queue_item_if_ready( (int) $job->parent_queue_id );
				}
			}
		}

		wp_safe_redirect( $this->admin_url( array( 'tab' => 'processing_queue' ) ) );
		exit;
	}

	/**
	 * Delete one processing job and unblock jobs waiting on it.
	 *
	 * @return void
	 */
	public function delete_processing_job() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to update Content Stream.', 'as-content-stream' ) );
		}

		check_admin_referer( self::NONCE_PROCESSING );

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		if ( $job_id ) {
			global $wpdb;
			self::create_processing_queue_table();
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . self::processing_queue_table_name() . ' WHERE id = %d AND status <> %s',
					$job_id,
					'complete'
				)
			);
			$wpdb->update(
				self::processing_queue_table_name(),
				array(
					'status'         => 'pending',
					'blocked_by'     => 0,
					'result_message' => sprintf(
						/* translators: %d: removed processing job ID. */
						__( 'Unblocked after processing job #%d was removed.', 'as-content-stream' ),
						$job_id
					),
				),
				array( 'blocked_by' => $job_id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
		}

		wp_safe_redirect( $this->admin_url( array( 'tab' => 'processing_queue', 'deleted' => 1 ) ) );
		exit;
	}

	/**
	 * Run a link manually.
	 *
	 * @return void
	 */
	public function run_link() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to update Content Stream.', 'as-content-stream' ) );
		}

		check_admin_referer( self::NONCE_PROCESSING );

		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
		if ( $link_id ) {
			$link = $this->get_link( $link_id );
			if ( $link ) {
				$job = $this->create_processing_job_from_link( $link );
				if ( $job ) {
					$this->process_processing_job_row( $job );
				}
			}
		}

		wp_safe_redirect( $this->admin_url( array( 'tab' => 'links' ) ) );
		exit;
	}

	/**
	 * Create one processing job from a link.
	 *
	 * @param object $link Link row.
	 * @return object|null
	 */
	private function create_processing_job_from_link( $link ) {
		global $wpdb;

		self::create_processing_queue_table();

		$payload = $this->build_source_payload_for_link( $link );
		$inserted = $wpdb->insert(
			self::processing_queue_table_name(),
			array(
				'created_at'      => current_time( 'mysql', true ),
				'parent_queue_id' => 0,
				'link_id'         => (int) $link->id,
				'action'          => 'update',
				'status'          => 'pending',
				'source_blog_id'  => (int) $link->source_blog_id,
				'source_post_id'  => (int) $link->source_post_id,
				'target_blog_id'  => (int) $link->target_blog_id,
				'target_language' => sanitize_key( $link->target_language ),
				'post_type'       => sanitize_key( $link->source_post_type ),
				'payload'         => wp_json_encode( $payload ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::processing_queue_table_name() . ' WHERE id = %d LIMIT 1',
				(int) $wpdb->insert_id
			)
		);
	}

	/**
	 * Build source payload for one link.
	 *
	 * @param object $link Link row.
	 * @return array<string,mixed>
	 */
	private function build_source_payload_for_link( $link ) {
		$payload = array(
			'source_uuid'        => sanitize_text_field( $link->source_uuid ),
			'post_name'          => sanitize_title( $link->source_slug ),
			'original_post_name' => sanitize_title( $link->source_slug ),
			'target_language'    => sanitize_key( $link->target_language ),
		);

		$restore = get_current_blog_id() !== (int) $link->source_blog_id;
		if ( $restore ) {
			switch_to_blog( (int) $link->source_blog_id );
		}

		$post = get_post( (int) $link->source_post_id );
		if ( $post instanceof WP_Post ) {
			$payload = array_merge(
				$payload,
				array(
					'post_title'        => $post->post_title,
					'post_status'       => $post->post_status,
					'post_name'         => $post->post_name,
					'post_date'         => $post->post_date,
					'post_date_gmt'     => $post->post_date_gmt,
					'post_modified'     => $post->post_modified,
					'post_modified_gmt' => $post->post_modified_gmt,
				)
			);
		}

		if ( $restore ) {
			restore_current_blog();
		}

		return $payload;
	}

	/**
	 * Build source payload from a source post.
	 *
	 * @param int    $blog_id Source blog ID.
	 * @param int    $post_id Source post ID.
	 * @param string $source_uuid Source UUID.
	 * @param string $target_language Target language.
	 * @return array<string,mixed>
	 */
	private function build_source_payload_from_post( $blog_id, $post_id, $source_uuid, $target_language ) {
		$payload = array(
			'source_uuid'     => sanitize_text_field( $source_uuid ),
			'target_language' => sanitize_key( $target_language ),
		);
		$restore = get_current_blog_id() !== $blog_id;
		if ( $restore ) {
			switch_to_blog( $blog_id );
		}

		$post = get_post( $post_id );
		if ( $post instanceof WP_Post ) {
			$payload = array_merge(
				$payload,
				array(
					'post_title'         => $post->post_title,
					'post_status'        => $post->post_status,
					'post_name'          => $post->post_name,
					'post_date'          => $post->post_date,
					'post_date_gmt'      => $post->post_date_gmt,
					'post_modified'      => $post->post_modified,
					'post_modified_gmt'  => $post->post_modified_gmt,
					'original_post_name' => $this->get_original_post_name( $post ),
				)
			);
		}

		if ( $restore ) {
			restore_current_blog();
		}

		return $payload;
	}

	/**
	 * Get a post type from a site.
	 *
	 * @param int $blog_id Blog ID.
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function get_post_type_from_site( $blog_id, $post_id ) {
		$restore = get_current_blog_id() !== $blog_id;
		if ( $restore ) {
			switch_to_blog( $blog_id );
		}

		$post = get_post( $post_id );
		$post_type = $post instanceof WP_Post ? sanitize_key( $post->post_type ) : '';

		if ( $restore ) {
			restore_current_blog();
		}

		return $post_type;
	}

	/**
	 * Capture create/update events after WordPress has finished inserting the post.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post      $post Post object.
	 * @param bool         $update Whether this is an update.
	 * @param WP_Post|null $post_before Previous post object.
	 * @return void
	 */
	public function capture_after_insert_post( $post_id, $post, $update, $post_before ) {
		if ( ! $this->is_source_site() ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'trash' === $post->post_status || $this->is_revision_or_autosave( $post_id, $post ) ) {
			return;
		}

		if ( ! $this->is_streamable_post_type( $post->post_type ) ) {
			return;
		}

		$action = ( $update && $post_before instanceof WP_Post && 'auto-draft' !== $post_before->post_status ) ? 'update' : 'create';
		$this->enqueue_source_action( $action, get_current_blog_id(), $post_id, $post->post_type, $post );
	}

	/**
	 * Capture trash events before WordPress mutates the slug.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $previous_status Previous post status.
	 * @return void
	 */
	public function capture_trash_post( $post_id, $previous_status = '' ) {
		if ( ! $this->is_source_site() ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || $this->is_revision_or_autosave( $post_id, $post ) ) {
			return;
		}

		if ( ! $this->is_streamable_post_type( $post->post_type ) ) {
			return;
		}

		$this->enqueue_source_action( 'delete', get_current_blog_id(), $post_id, $post->post_type, $post );
	}

	/**
	 * Capture delete events.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function capture_delete_post( $post_id, $post ) {
		if ( ! $this->is_source_site() || ! $post instanceof WP_Post || $this->is_revision_or_autosave( $post_id, $post ) ) {
			return;
		}

		if ( ! $this->is_streamable_post_type( $post->post_type ) ) {
			return;
		}

		$this->enqueue_source_action( 'delete', get_current_blog_id(), $post_id, $post->post_type, $post );
	}

	/**
	 * Queue source-site work for later processing.
	 *
	 * @param string  $action Action name.
	 * @param int     $source_blog_id Source site ID.
	 * @param int     $source_post_id Source post ID.
	 * @param string  $post_type Post type.
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	private function enqueue_source_action( $action, $source_blog_id, $source_post_id, $post_type, $post ) {
		if ( isset( $this->queue_locks[ $source_post_id ] ) ) {
			return;
		}

		$this->queue_locks[ $source_post_id ] = true;
		$source_uuid = $this->get_or_create_source_uuid( $source_blog_id, $source_post_id );

		$this->insert_queue_item(
			array(
				'action'          => $action,
				'source_blog_id'  => $source_blog_id,
				'source_post_id'  => $source_post_id,
				'target_blog_id'  => 0,
				'target_language' => '',
				'post_type'       => $post_type,
				'payload'         => array(
					'source_uuid'        => $source_uuid,
					'post_title'         => $post->post_title,
					'post_status'        => $post->post_status,
					'post_name'          => $post->post_name,
					'post_date'          => $post->post_date,
					'post_date_gmt'      => $post->post_date_gmt,
					'post_modified'      => $post->post_modified,
					'post_modified_gmt'  => $post->post_modified_gmt,
					'original_post_name' => $this->get_original_post_name( $post ),
				),
			)
		);

		$this->store_capture_status(
			'queued',
			__( 'Queued source-site content action.', 'as-content-stream' ),
			$source_post_id,
			$post
		);

		unset( $this->queue_locks[ $source_post_id ] );
	}

	/**
	 * Insert queue item.
	 *
	 * @param array<string,mixed> $item Queue item.
	 * @return void
	 */
	private function insert_queue_item( $item ) {
		global $wpdb;

		self::create_queue_table();

		$payload = wp_json_encode( $item['payload'] );
		$action  = sanitize_key( $item['action'] );

		if ( 'update' === $action && $this->pending_source_action_exists( $item, 'create' ) ) {
			return;
		}

		if ( 'delete' === $action ) {
			$this->delete_pending_source_actions( $item, array( 'create', 'update' ) );
		}

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::queue_table_name() . ' WHERE status = %s AND source_blog_id = %d AND source_post_id = %d AND action = %s AND post_type = %s AND target_blog_id = %d LIMIT 1',
				'pending',
				absint( $item['source_blog_id'] ),
				absint( $item['source_post_id'] ),
				$action,
				sanitize_key( $item['post_type'] ),
				absint( $item['target_blog_id'] )
			)
		);

		if ( $existing_id ) {
			$wpdb->update(
				self::queue_table_name(),
				array(
					'created_at'      => current_time( 'mysql', true ),
					'target_language' => sanitize_key( $item['target_language'] ),
					'payload'         => $payload,
					'last_error'      => null,
				),
				array( 'id' => absint( $existing_id ) ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		$wpdb->insert(
			self::queue_table_name(),
			array(
				'created_at'      => current_time( 'mysql', true ),
				'action'          => $action,
				'status'          => 'pending',
				'source_blog_id'  => absint( $item['source_blog_id'] ),
				'source_post_id'  => absint( $item['source_post_id'] ),
				'target_blog_id'  => absint( $item['target_blog_id'] ),
				'target_language' => sanitize_key( $item['target_language'] ),
				'post_type'       => sanitize_key( $item['post_type'] ),
				'payload'         => $payload,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Check whether a matching pending action already exists.
	 *
	 * @param array<string,mixed> $item Queue item.
	 * @param string              $action Action to check.
	 * @return bool
	 */
	private function pending_source_action_exists( $item, $action ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::queue_table_name() . ' WHERE status = %s AND source_blog_id = %d AND source_post_id = %d AND action = %s AND post_type = %s AND target_blog_id = %d LIMIT 1',
				'pending',
				absint( $item['source_blog_id'] ),
				absint( $item['source_post_id'] ),
				sanitize_key( $action ),
				sanitize_key( $item['post_type'] ),
				absint( $item['target_blog_id'] )
			)
		);
	}

	/**
	 * Delete matching pending actions.
	 *
	 * @param array<string,mixed> $item Queue item.
	 * @param string[]            $actions Actions to delete.
	 * @return void
	 */
	private function delete_pending_source_actions( $item, $actions ) {
		global $wpdb;

		foreach ( $actions as $action ) {
			$wpdb->delete(
				self::queue_table_name(),
				array(
					'status'         => 'pending',
					'source_blog_id' => absint( $item['source_blog_id'] ),
					'source_post_id' => absint( $item['source_post_id'] ),
					'action'         => sanitize_key( $action ),
					'post_type'      => sanitize_key( $item['post_type'] ),
					'target_blog_id' => absint( $item['target_blog_id'] ),
				),
				array( '%s', '%d', '%d', '%s', '%s', '%d' )
			);
		}
	}

	/**
	 * Determine whether the row is a revision or autosave.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	private function is_revision_or_autosave( $post_id, $post ) {
		return 'revision' === $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id );
	}

	/**
	 * Determine whether a post type should be streamed as source content.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private function is_streamable_post_type( $post_type ) {
		$post_type = sanitize_key( $post_type );
		$excluded = array(
			'attachment',
			'custom_css',
			'customize_changeset',
			'nav_menu_item',
			'oembed_cache',
			'revision',
			'user_request',
			'wp_block',
			'wp_font_face',
			'wp_font_family',
			'wp_global_styles',
			'wp_navigation',
			'wp_template',
			'wp_template_part',
		);

		return '' !== $post_type && ! in_array( $post_type, $excluded, true );
	}

	/**
	 * Get an existing source UUID from links, or create one for a new relationship batch.
	 *
	 * @param int $source_blog_id Source blog ID.
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function get_or_create_source_uuid( $source_blog_id, $post_id ) {
		$uuid = $this->get_source_uuid_from_links( $source_blog_id, $post_id );

		return '' !== $uuid ? $uuid : wp_generate_uuid4();
	}

	/**
	 * Decode queue JSON payload.
	 *
	 * @param string|null $payload Payload JSON.
	 * @return array<string,string>
	 */
	private function decode_queue_payload( $payload ) {
		$decoded = json_decode( (string) $payload, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Get edit URL for a source post.
	 *
	 * @param int $blog_id Blog ID.
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function source_edit_url( $blog_id, $post_id ) {
		$restore = is_multisite() && get_current_blog_id() !== $blog_id;

		if ( $restore ) {
			switch_to_blog( $blog_id );
		}

		$url = get_edit_post_link( $post_id, 'raw' );

		if ( $restore ) {
			restore_current_blog();
		}

		return $url ? $url : '';
	}

	/**
	 * Get an admin URL for a site.
	 *
	 * @param int $blog_id Blog ID.
	 * @param string $path Optional admin path.
	 * @return string
	 */
	private function site_admin_url( $blog_id, $path = '' ) {
		return get_admin_url( $blog_id, $path );
	}

	/**
	 * Get admin list URL for a post type and language on a site.
	 *
	 * @param int    $blog_id Blog ID.
	 * @param string $post_type Post type.
	 * @param string $language Language code.
	 * @return string
	 */
	private function post_type_list_url( $blog_id, $post_type, $language ) {
		return add_query_arg(
			array(
				'post_status' => 'all',
				'post_type'   => sanitize_key( $post_type ),
				'lang'        => sanitize_key( $language ),
			),
			$this->site_admin_url( $blog_id, 'edit.php' )
		);
	}

	/**
	 * Get a processing row's destination post ID if one is known.
	 *
	 * @param object              $item Processing row.
	 * @param array<string,mixed> $payload Processing payload.
	 * @return int
	 */
	private function destination_post_id_for_processing_item( $item, $payload ) {
		if ( ! empty( $item->link_id ) ) {
			$link = $this->get_link( (int) $item->link_id );
			if ( $link && ! empty( $link->target_post_id ) ) {
				return (int) $link->target_post_id;
			}
		}

		$restore = get_current_blog_id() !== (int) $item->target_blog_id;
		if ( $restore ) {
			switch_to_blog( (int) $item->target_blog_id );
		}

		$post_id = $this->find_destination_post_id( $item, $payload );

		if ( $restore ) {
			restore_current_blog();
		}

		return (int) $post_id;
	}

	/**
	 * Get edit URL for a destination post matched by source UUID.
	 *
	 * @param int                 $blog_id Blog ID.
	 * @param string              $post_type Post type.
	 * @param array<string,mixed> $payload Queue payload.
	 * @return string
	 */
	private function destination_edit_url( $blog_id, $post_type, $payload ) {
		$source_uuid = isset( $payload['source_uuid'] ) ? sanitize_text_field( $payload['source_uuid'] ) : '';
		if ( '' === $source_uuid ) {
			return '';
		}

		$target_language = isset( $payload['target_language'] ) ? sanitize_key( $payload['target_language'] ) : '';
		if ( '' !== $target_language ) {
			$link = $this->get_link_for_source_target( $source_uuid, $blog_id, $target_language );
			if ( $link && ! empty( $link->target_post_id ) ) {
				return $this->source_edit_url( $blog_id, (int) $link->target_post_id );
			}
		}

		$restore = is_multisite() && get_current_blog_id() !== $blog_id;
		if ( $restore ) {
			switch_to_blog( $blog_id );
		}

		$slug = isset( $payload['original_post_name'] ) && '' !== $payload['original_post_name'] ? sanitize_title( $payload['original_post_name'] ) : sanitize_title( isset( $payload['post_name'] ) ? $payload['post_name'] : '' );
		$post = '' !== $slug ? get_page_by_path( $slug, OBJECT, sanitize_key( $post_type ) ) : null;
		$url = $post instanceof WP_Post ? get_edit_post_link( (int) $post->ID, 'raw' ) : '';

		if ( $restore ) {
			restore_current_blog();
		}

		return $url ? $url : '';
	}

	/**
	 * Get the original source slug before WordPress trash suffixes are applied.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function get_original_post_name( $post ) {
		$desired_slug = get_post_meta( $post->ID, '_wp_desired_post_slug', true );

		if ( is_string( $desired_slug ) && '' !== $desired_slug ) {
			return $desired_slug;
		}

		return preg_replace( '/__trashed(?:-\d+)?$/', '', (string) $post->post_name );
	}

	/**
	 * Discover network sites and capabilities.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function discover_sites() {
		if ( ! is_multisite() ) {
			return array( $this->inspect_site( get_current_blog_id() ) );
		}

		$sites   = get_sites(
			array(
				'number'   => 0,
				'deleted'  => 0,
				'archived' => 0,
				'spam'     => 0,
			)
		);
		$results = array();

		foreach ( $sites as $site ) {
			if ( (int) $site->blog_id === (int) get_main_site_id() ) {
				continue;
			}

			$results[] = $this->inspect_site( (int) $site->blog_id );
		}

		$this->reconcile_streaming_map_for_sites( $results );
		$this->refresh_discovery_queue_after_target_change( $results );

		return $results;
	}

	/**
	 * Inspect one site.
	 *
	 * @param int $blog_id Blog ID.
	 * @return array<string,mixed>
	 */
	private function inspect_site( $blog_id ) {
		$restore = is_multisite() && get_current_blog_id() !== $blog_id;

		if ( $restore ) {
			switch_to_blog( $blog_id );
		}

		$details = get_blog_details( $blog_id );
		$wpml_active = $this->is_wpml_plugin_active_on_site();
		$languages   = $wpml_active ? $this->get_wpml_languages() : array();
		$result  = array(
			'blog_id'     => $blog_id,
			'name'        => $details ? $details->blogname : sprintf( __( 'Site %d', 'as-content-stream' ), $blog_id ),
			'url'         => get_home_url( $blog_id, '/' ),
			'wpml_active' => $wpml_active,
			'languages'   => $languages,
		);

		if ( $restore ) {
			restore_current_blog();
		}

		return $result;
	}

	/**
	 * Determine whether the WPML plugin is active for the current switched site.
	 *
	 * @return bool
	 */
	private function is_wpml_plugin_active_on_site() {
		$wpml_plugin      = 'sitepress-multilingual-cms/sitepress.php';
		$active_plugins   = (array) get_option( 'active_plugins', array() );
		$sitewide_plugins = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();

		return in_array( $wpml_plugin, $active_plugins, true ) || isset( $sitewide_plugins[ $wpml_plugin ] );
	}

	/**
	 * Get WPML language codes configured for the current switched active WPML site.
	 *
	 * @return string[]
	 */
	private function get_wpml_languages() {
		$languages = array();
		$wpml_settings = (array) get_option( 'icl_sitepress_settings', array() );

		if ( isset( $wpml_settings['active_languages'] ) && is_array( $wpml_settings['active_languages'] ) ) {
			$languages = $this->normalize_wpml_language_codes( $wpml_settings['active_languages'] );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'icl_languages';

		if ( empty( $languages ) && $this->table_exists( $table_name ) ) {
			$languages = $wpdb->get_col( "SELECT code FROM {$table_name} WHERE active = 1 ORDER BY code ASC" );
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $languages ) ) ) );
	}

	/**
	 * Normalize WPML active language data to language codes.
	 *
	 * @return string[]
	 */
	private function normalize_wpml_language_codes( $active_languages ) {
		$languages = array();

		foreach ( $active_languages as $key => $language ) {
			if ( is_string( $key ) && ! is_numeric( $key ) ) {
				$languages[] = $key;
				continue;
			}

			if ( is_array( $language ) ) {
				if ( ! empty( $language['code'] ) ) {
					$languages[] = $language['code'];
				} elseif ( ! empty( $language['language_code'] ) ) {
					$languages[] = $language['language_code'];
				} elseif ( ! empty( $language['default_locale'] ) ) {
					$languages[] = substr( (string) $language['default_locale'], 0, 2 );
				}
			} elseif ( is_object( $language ) ) {
				if ( ! empty( $language->code ) ) {
					$languages[] = $language->code;
				} elseif ( ! empty( $language->language_code ) ) {
					$languages[] = $language->language_code;
				} elseif ( ! empty( $language->default_locale ) ) {
					$languages[] = substr( (string) $language->default_locale, 0, 2 );
				}
			} elseif ( is_string( $language ) && ! is_numeric( $language ) ) {
				$languages[] = $language;
			}
		}

		return $languages;
	}

	/**
	 * Check whether a table exists.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	private function table_exists( $table_name ) {
		global $wpdb;

		return $table_name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
	}

	/**
	 * Refresh discovery parent queue rows for unmapped published source content.
	 *
	 * @return void
	 */
	private function refresh_discovery_queue() {
		if ( ! is_multisite() || ! is_main_site() ) {
			return;
		}

		$this->delete_stale_discovery_queue_items();

		foreach ( $this->get_discovery_source_items() as $item ) {
			if ( $this->source_queue_action_exists( (int) $item['source_blog_id'], (int) $item['source_post_id'], sanitize_key( $item['post_type'] ), 'discover' ) ) {
				continue;
			}

			$this->insert_queue_item(
				array(
					'action'          => 'discover',
					'source_blog_id'  => (int) $item['source_blog_id'],
					'source_post_id'  => (int) $item['source_post_id'],
					'target_blog_id'  => 0,
					'target_language' => '',
					'post_type'       => sanitize_key( $item['post_type'] ),
					'payload'         => array(
						'source_uuid'        => $this->get_or_create_source_uuid( (int) $item['source_blog_id'], (int) $item['source_post_id'] ),
						'post_title'         => $item['post_title'],
						'post_status'        => 'publish',
						'post_name'          => $item['post_name'],
						'post_date'          => $item['post_date'],
						'post_date_gmt'      => $item['post_date_gmt'],
						'post_modified'      => $item['post_modified'],
						'post_modified_gmt'  => $item['post_modified_gmt'],
						'original_post_name' => $item['post_name'],
					),
				)
			);
		}
	}

	/**
	 * Check whether Discovery Queue should be shown.
	 *
	 * @return bool
	 */
	private function has_discovery_queue_items() {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::queue_table_name() . ' WHERE action = %s AND status <> %s LIMIT 1',
				'discover',
				'complete'
			)
		);
	}

	/**
	 * Get Discovery tile stats by source post type.
	 *
	 * @return array<int,array<string,int|string>>
	 */
	private function get_discovery_stats() {
		$stats = array();
		$targets = $this->get_discovery_targets();
		$target_count = count( $targets );

		if ( 0 === $target_count ) {
			return $stats;
		}

		foreach ( $this->get_published_source_posts() as $post ) {
			$post_type = sanitize_key( $post['post_type'] );
			if ( ! isset( $stats[ $post_type ] ) ) {
				$stats[ $post_type ] = array(
					'post_type' => $post_type,
					'published' => 0,
					'mapped'    => 0,
					'unmapped'  => 0,
				);
			}

			$stats[ $post_type ]['published']++;
			if ( $this->source_post_has_full_discovery_map( (int) $post['ID'], $post_type, $targets ) ) {
				$stats[ $post_type ]['mapped']++;
			} else {
				$stats[ $post_type ]['unmapped']++;
			}
		}

		return array_values( $stats );
	}

	/**
	 * Get published source content requiring Discovery.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_discovery_source_items() {
		$targets = $this->get_discovery_targets();
		if ( empty( $targets ) ) {
			return array();
		}

		$items = array();
		foreach ( $this->get_published_source_posts() as $post ) {
			if ( ! $this->source_post_has_full_discovery_map( (int) $post['ID'], sanitize_key( $post['post_type'] ), $targets ) ) {
				$items[] = array(
					'source_blog_id'    => get_main_site_id(),
					'source_post_id'    => (int) $post['ID'],
					'post_type'         => sanitize_key( $post['post_type'] ),
					'post_title'        => $post['post_title'],
					'post_name'         => $post['post_name'],
					'post_date'         => $post['post_date'],
					'post_date_gmt'     => $post['post_date_gmt'],
					'post_modified'     => $post['post_modified'],
					'post_modified_gmt' => $post['post_modified_gmt'],
				);
			}
		}

		return $items;
	}

	/**
	 * Get active discovery destination targets.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_discovery_targets() {
		$language_counts = $this->get_language_counts( $this->discover_sites() );
		$target_language = $this->get_effective_target_language( $language_counts );

		return $this->get_processing_targets( $target_language );
	}

	/**
	 * Get published streamable posts from the core site.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_published_source_posts() {
		global $wpdb;

		$post_types = $this->get_discoverable_source_post_types();
		if ( empty( $post_types ) ) {
			return array();
		}

		$table = $wpdb->get_blog_prefix( get_main_site_id() ) . 'posts';
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type, post_title, post_name, post_date, post_date_gmt, post_modified, post_modified_gmt FROM {$table} WHERE post_status = %s AND post_type IN ({$placeholders}) ORDER BY post_type ASC, post_title ASC",
				array_merge( array( 'publish' ), $post_types )
			),
			ARRAY_A
		);

		return array_values( (array) $rows );
	}

	/**
	 * Count published streamable posts from the core site.
	 *
	 * @return int
	 */
	private function get_published_source_content_count() {
		global $wpdb;

		$post_types = $this->get_discoverable_source_post_types();
		if ( empty( $post_types ) ) {
			return 0;
		}

		$table = $wpdb->get_blog_prefix( get_main_site_id() ) . 'posts';
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE post_status = %s AND post_type IN ({$placeholders})",
				array_merge( array( 'publish' ), $post_types )
			)
		);
	}

	/**
	 * Get public source post types currently registered on the core site.
	 *
	 * @return string[]
	 */
	private function get_discoverable_source_post_types() {
		$restore = get_current_blog_id() !== get_main_site_id();
		if ( $restore ) {
			switch_to_blog( get_main_site_id() );
		}

		$post_types = get_post_types( array( 'public' => true ), 'names' );

		if ( $restore ) {
			restore_current_blog();
		}

		return array_values(
			array_filter(
				array_map( 'sanitize_key', (array) $post_types ),
				function ( $post_type ) {
					return $this->is_streamable_post_type( $post_type );
				}
			)
		);
	}

	/**
	 * Delete stale Discovery rows for inactive or non-public source post types.
	 *
	 * @return void
	 */
	private function delete_stale_discovery_queue_items() {
		global $wpdb;

		$post_types = $this->get_discoverable_source_post_types();
		if ( empty( $post_types ) ) {
			$wpdb->delete(
				self::queue_table_name(),
				array(
					'action'         => 'discover',
					'source_blog_id' => get_main_site_id(),
				),
				array( '%s', '%d' )
			);
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$posts_table = $wpdb->get_blog_prefix( get_main_site_id() ) . 'posts';
		$query_args = array_merge( array( 'publish' ), $post_types, array( get_main_site_id(), 'discover', 'complete' ) );
		$wpdb->query(
			$wpdb->prepare(
				'DELETE q FROM ' . self::queue_table_name() . " q LEFT JOIN {$posts_table} p ON p.ID = q.source_post_id AND p.post_status = %s AND p.post_type IN ({$placeholders}) WHERE q.source_blog_id = %d AND q.action = %s AND q.status <> %s AND p.ID IS NULL",
				$query_args
			)
		);
	}

	/**
	 * Check whether a source post is mapped to every current target.
	 *
	 * @param int                       $source_post_id Source post ID.
	 * @param string                    $post_type Source post type.
	 * @param array<int,array<string,mixed>> $targets Processing targets.
	 * @return bool
	 */
	private function source_post_has_full_discovery_map( $source_post_id, $post_type, $targets ) {
		global $wpdb;

		if ( empty( $targets ) ) {
			return true;
		}

		$target_ids = array_map(
			static function ( $target ) {
				return (int) $target['blog_id'];
			},
			$targets
		);
		$language_counts = $this->get_language_counts( $this->discover_sites() );
		$target_language = $this->get_effective_target_language( $language_counts );
		$placeholders = implode( ',', array_fill( 0, count( $target_ids ), '%d' ) );
		$query_args = array_merge(
			array( get_main_site_id(), $source_post_id, sanitize_key( $post_type ), sanitize_key( $target_language ) ),
			$target_ids
		);
		$mapped = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT target_blog_id) FROM ' . self::links_table_name() . " WHERE source_blog_id = %d AND source_post_id = %d AND source_post_type = %s AND target_language = %s AND status = 'active' AND target_blog_id IN ({$placeholders})",
				$query_args
			)
		);

		return $mapped >= count( $target_ids );
	}

	/**
	 * Check whether a non-complete source queue action already exists.
	 *
	 * @param int    $source_blog_id Source blog ID.
	 * @param int    $source_post_id Source post ID.
	 * @param string $post_type Post type.
	 * @param string $action Action.
	 * @return bool
	 */
	private function source_queue_action_exists( $source_blog_id, $source_post_id, $post_type, $action ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::queue_table_name() . ' WHERE status <> %s AND source_blog_id = %d AND source_post_id = %d AND action = %s AND post_type = %s AND target_blog_id = 0 LIMIT 1',
				'complete',
				$source_blog_id,
				$source_post_id,
				sanitize_key( $action ),
				sanitize_key( $post_type )
			)
		);
	}

	/**
	 * Get queue rows.
	 *
	 * @return array<int,object>
	 */
	private function get_queue_items( $action, $limit = 50, $offset = 0, $snapshot_id = 0 ) {
		global $wpdb;

		$action = sanitize_key( $action );
		$limit = min( 50, max( 1, absint( $limit ) ) );
		$offset = absint( $offset );
		$snapshot_id = absint( $snapshot_id );
		$order_by = 'discover' === $action ? 'post_type ASC, source_post_id ASC' : 'id DESC';
		$snapshot_sql = $snapshot_id ? ' AND id <= %d' : '';
		$args = array( $action );
		if ( $snapshot_id ) {
			$args[] = $snapshot_id;
		}
		$args[] = $limit;
		$args[] = $offset;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::queue_table_name() . " WHERE action = %s AND status != 'complete'{$snapshot_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d",
				$args
			)
		);
	}

	/**
	 * Get current queue snapshot ID.
	 *
	 * @param string $action Queue action.
	 * @return int
	 */
	private function get_queue_snapshot_id( $action ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(id) FROM ' . self::queue_table_name() . " WHERE action = %s AND status != 'complete'",
				sanitize_key( $action )
			)
		);
	}

	/**
	 * Get processing queue rows.
	 *
	 * @param bool $terminal Whether to get terminal log rows.
	 * @param int  $lookup_id Optional post ID lookup for logs.
	 * @return array<int,object>
	 */
	private function get_processing_queue_items( $terminal, $lookup_id = 0, $limit = 50, $offset = 0, $snapshot_id = 0 ) {
		global $wpdb;

		self::create_processing_queue_table();
		$limit = min( 50, max( 1, absint( $limit ) ) );
		$offset = absint( $offset );
		$snapshot_id = absint( $snapshot_id );
		$snapshot_sql = $snapshot_id ? ' AND p.id <= %d' : '';
		$snapshot_where_sql = $snapshot_id ? ' AND id <= %d' : '';

		$status_sql = $terminal ? "status = 'complete'" : "status <> 'complete'";
		if ( $terminal && $lookup_id ) {
			self::create_links_table();
			$args = array( 'complete', $lookup_id, $lookup_id );
			if ( $snapshot_id ) {
				$args[] = $snapshot_id;
			}
			$args[] = $limit;
			$args[] = $offset;

			return $wpdb->get_results(
				$wpdb->prepare(
					'SELECT p.* FROM ' . self::processing_queue_table_name() . " p LEFT JOIN " . self::links_table_name() . " l ON l.id = p.link_id WHERE p.status = %s AND (p.source_post_id = %d OR l.target_post_id = %d){$snapshot_sql} ORDER BY p.id DESC LIMIT %d OFFSET %d",
					$args
				)
			);
		}

		$args = array();
		if ( $snapshot_id ) {
			$args[] = $snapshot_id;
		}
		$args[] = $limit;
		$args[] = $offset;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::processing_queue_table_name() . ' WHERE ' . $status_sql . $snapshot_where_sql . ' ORDER BY id DESC LIMIT %d OFFSET %d',
				$args
			)
		);
	}

	/**
	 * Get current processing queue snapshot ID.
	 *
	 * @param bool $terminal Whether to get terminal log rows.
	 * @param int  $lookup_id Optional post ID lookup for logs.
	 * @return int
	 */
	private function get_processing_queue_snapshot_id( $terminal, $lookup_id = 0 ) {
		global $wpdb;

		self::create_processing_queue_table();
		$status_sql = $terminal ? "status = 'complete'" : "status <> 'complete'";
		if ( $terminal && $lookup_id ) {
			self::create_links_table();

			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT MAX(p.id) FROM ' . self::processing_queue_table_name() . ' p LEFT JOIN ' . self::links_table_name() . ' l ON l.id = p.link_id WHERE p.status = %s AND (p.source_post_id = %d OR l.target_post_id = %d)',
					'complete',
					$lookup_id,
					$lookup_id
				)
			);
		}

		return (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . self::processing_queue_table_name() . ' WHERE ' . $status_sql );
	}

	/**
	 * Get processing queue counts by status.
	 *
	 * @return array<string,int>
	 */
	private function get_processing_queue_counts() {
		global $wpdb;

		self::create_processing_queue_table();

		$rows   = $wpdb->get_results( 'SELECT status, COUNT(*) AS total FROM ' . self::processing_queue_table_name() . ' GROUP BY status' );
		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ $row->status ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Get non-complete processing queue count.
	 *
	 * @return int
	 */
	private function get_processing_queue_open_count() {
		global $wpdb;

		self::create_processing_queue_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::processing_queue_table_name() . " WHERE status <> 'complete'" );
	}

	/**
	 * Get processing progress counts for the current batch or active queue.
	 *
	 * @param int $parent_queue_id Source queue parent ID.
	 * @return array<string,int>
	 */
	private function get_processing_progress_counts( $parent_queue_id ) {
		global $wpdb;

		self::create_processing_queue_table();

		if ( $parent_queue_id ) {
			return array(
				'total' => (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM ' . self::processing_queue_table_name() . ' WHERE parent_queue_id = %d',
						$parent_queue_id
					)
				),
				'done'  => (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM ' . self::processing_queue_table_name() . ' WHERE parent_queue_id = %d AND status = %s',
						$parent_queue_id,
						'complete'
					)
				),
			);
		}

		return array(
			'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::processing_queue_table_name() . " WHERE status <> 'complete'" ),
			'done'  => 0,
		);
	}

	/**
	 * Get active processing job count.
	 *
	 * @return int
	 */
	private function get_active_processing_job_count() {
		global $wpdb;

		self::create_processing_queue_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::processing_queue_table_name() . " WHERE status IN ('pending', 'in_progress')" );
	}

	/**
	 * Check whether processing jobs already exist for a parent.
	 *
	 * @param int $parent_queue_id Parent queue ID.
	 * @return bool
	 */
	private function processing_jobs_exist_for_parent( $parent_queue_id ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::processing_queue_table_name() . " WHERE parent_queue_id = %d AND status <> 'complete' LIMIT 1",
				$parent_queue_id
			)
		);
	}

	/**
	 * Check whether a processing job already exists for a parent/target pair.
	 *
	 * @param int $parent_queue_id Parent queue ID.
	 * @param int $target_blog_id Target blog ID.
	 * @return bool
	 */
	private function processing_job_exists_for_target( $parent_queue_id, $target_blog_id ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::processing_queue_table_name() . ' WHERE parent_queue_id = %d AND target_blog_id = %d LIMIT 1',
				$parent_queue_id,
				$target_blog_id
			)
		);
	}

	/**
	 * Get active destination targets for a target language.
	 *
	 * @param string $target_language Target language.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_processing_targets( $target_language ) {
		$targets = array();

		if ( '' === $target_language ) {
			return $targets;
		}

		foreach ( $this->discover_sites() as $site ) {
			if ( empty( $site['wpml_active'] ) || empty( $site['languages'] ) || ! is_array( $site['languages'] ) ) {
				continue;
			}

			if ( in_array( $target_language, $site['languages'], true ) ) {
				$targets[] = $site;
			}
		}

		return $targets;
	}

	/**
	 * Get queue count by status.
	 *
	 * @return array<string,int>
	 */
	private function get_queue_counts() {
		global $wpdb;

		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM " . self::queue_table_name() . ' GROUP BY status' );
		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ $row->status ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Get non-complete parent queue counts by action.
	 *
	 * @return array<string,int>
	 */
	private function get_queue_action_counts() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT action, COUNT(*) AS total FROM " . self::queue_table_name() . " WHERE status <> 'complete' GROUP BY action" );
		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ sanitize_key( $row->action ) ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Get streamed content count.
	 *
	 * @return int
	 */
	private function get_streamed_content_count() {
		global $wpdb;

		self::create_links_table();

		$post_types = $this->get_discoverable_source_post_types();
		$targets = $this->get_discovery_targets();
		$target_ids = array_map(
			static function ( $target ) {
				return (int) $target['blog_id'];
			},
			$targets
		);
		$language_counts = $this->get_language_counts( $this->discover_sites() );
		$target_language = $this->get_effective_target_language( $language_counts );

		if ( empty( $post_types ) || empty( $target_ids ) || '' === $target_language ) {
			return 0;
		}

		$links_table = self::links_table_name();
		$posts_table = $wpdb->get_blog_prefix( get_main_site_id() ) . 'posts';
		$post_type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$target_placeholders = implode( ',', array_fill( 0, count( $target_ids ), '%d' ) );
		$args = array_merge(
			array( 'publish' ),
			$post_types,
			array( 'active', get_main_site_id(), sanitize_key( $target_language ) ),
			$target_ids
		);

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$links_table} l
				INNER JOIN {$posts_table} p
					ON p.ID = l.source_post_id
					AND p.post_status = %s
					AND p.post_type IN ({$post_type_placeholders})
				WHERE l.status = %s
					AND l.source_blog_id = %d
					AND l.target_language = %s
					AND l.target_blog_id IN ({$target_placeholders})",
				$args
			)
		);
	}

	/**
	 * Get completed processing log count.
	 *
	 * @return int
	 */
	private function get_processing_log_count() {
		global $wpdb;

		self::create_processing_queue_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::processing_queue_table_name() . " WHERE status = 'complete'" );
	}

	/**
	 * Get heartbeat status.
	 *
	 * @return array<string,mixed>
	 */
	private function get_heartbeat_status() {
		$enabled = (bool) get_site_option( self::OPTION_PROCESSING_ENABLED, false );
		$telemetry = get_site_option( self::OPTION_TELEMETRY, array() );
		$telemetry = is_array( $telemetry ) ? $telemetry : array();
		$next = wp_next_scheduled( self::CRON_HOOK );
		$heartbeat_seconds = self::get_heartbeat_seconds();
		$next_check_seconds = $enabled && $next ? max( 0, $next - time() ) : 0;
		$next_check_percent = 0;
		if ( $enabled && $next ) {
			$elapsed = max( 0, $heartbeat_seconds - min( $heartbeat_seconds, $next_check_seconds ) );
			$next_check_percent = min( 100, round( ( $elapsed / $heartbeat_seconds ) * 100 ) );
		}
		$queue_pulse = $this->get_queue_pulse_status();
		$queue_action_counts = $this->get_queue_action_counts();
		$sites = $this->discover_sites();
		$wpml_sites = array_filter(
			$sites,
			static function ( $site ) {
				return $site['wpml_active'];
			}
		);
		$language_counts = $this->get_language_counts( $sites );
		$target_language = $this->get_effective_target_language( $language_counts );
		$target_language_labels = array();
		foreach ( $language_counts as $language => $count ) {
			$target_language_labels[ $language ] = sprintf( '%s (%d)', $language, $count );
		}
		$current_source_id = isset( $telemetry['current_source_id'] ) ? (int) $telemetry['current_source_id'] : 0;

		return array(
			'enabled'                => $enabled,
			'heartbeat_seconds'      => $heartbeat_seconds,
			'next_check_seconds'     => $next_check_seconds,
			'next_check_percent'     => $next_check_percent,
			'phase'                  => isset( $telemetry['phase'] ) ? sanitize_key( $telemetry['phase'] ) : 'idle',
			'current_source_id'      => $current_source_id,
			'parent_pending'         => $queue_pulse['parent_pending'],
			'parent_in_progress'     => $queue_pulse['parent_in_progress'],
			'parent_pressure_percent' => $queue_pulse['parent_pressure_percent'],
			'child_queued'           => $queue_pulse['child_queued'],
			'child_blocked'          => $queue_pulse['child_blocked'],
			'child_failed'           => $queue_pulse['child_failed'],
			'child_obstructed_percent' => $queue_pulse['child_obstructed_percent'],
			'last_batch_duration_ms' => isset( $telemetry['last_batch_duration_ms'] ) ? (int) $telemetry['last_batch_duration_ms'] : 0,
			'last_message'           => isset( $telemetry['last_message'] ) ? (string) $telemetry['last_message'] : __( 'No processing runs yet.', 'as-content-stream' ),
			'queue_counts'           => $queue_pulse['queue_counts'],
			'processing_counts'      => $queue_pulse['processing_counts'],
			'status_discovery'       => isset( $queue_action_counts['discover'] ) ? (int) $queue_action_counts['discover'] : 0,
			'status_create'          => isset( $queue_action_counts['create'] ) ? (int) $queue_action_counts['create'] : 0,
			'status_update'          => isset( $queue_action_counts['update'] ) ? (int) $queue_action_counts['update'] : 0,
			'status_delete'          => isset( $queue_action_counts['delete'] ) ? (int) $queue_action_counts['delete'] : 0,
			'status_processing'      => $this->get_processing_queue_open_count(),
			'status_streamed'        => $this->get_streamed_content_count(),
			'status_published_content' => $this->get_published_source_content_count(),
			'status_log'             => $this->get_processing_log_count(),
			'status_sites'           => count( $sites ),
			'status_wpml_sites'      => count( $wpml_sites ),
			'target_language'        => $target_language,
			'target_language_labels' => $target_language_labels,
		);
	}

	/**
	 * Get lightweight queue pulse status.
	 *
	 * @return array<string,mixed>
	 */
	private function get_queue_pulse_status() {
		$queue_counts = $this->get_queue_counts();
		$processing_counts = $this->get_processing_queue_counts();
		$parent_pending = isset( $queue_counts['pending'] ) ? (int) $queue_counts['pending'] : 0;
		$parent_in_progress = isset( $queue_counts['in_progress'] ) ? (int) $queue_counts['in_progress'] : 0;
		$parent_total = $parent_pending + $parent_in_progress;
		$child_pending = isset( $processing_counts['pending'] ) ? (int) $processing_counts['pending'] : 0;
		$child_in_progress = isset( $processing_counts['in_progress'] ) ? (int) $processing_counts['in_progress'] : 0;
		$child_blocked = isset( $processing_counts['blocked'] ) ? (int) $processing_counts['blocked'] : 0;
		$child_failed = isset( $processing_counts['failed'] ) ? (int) $processing_counts['failed'] : 0;
		$child_queued = $child_pending + $child_in_progress;
		$child_obstructed = $child_blocked + $child_failed;
		$child_total = $child_queued + $child_obstructed;

		return array(
			'parent_pending'         => $parent_pending,
			'parent_in_progress'     => $parent_in_progress,
			'parent_pressure_percent' => $parent_total > 0 ? min( 100, round( ( $parent_in_progress / $parent_total ) * 100 ) ) : 0,
			'child_queued'           => $child_queued,
			'child_blocked'          => $child_blocked,
			'child_failed'           => $child_failed,
			'child_obstructed_percent' => $child_total > 0 ? min( 100, round( ( $child_obstructed / $child_total ) * 100 ) ) : 0,
			'queue_counts'           => $queue_counts,
			'processing_counts'      => $processing_counts,
		);
	}

	/**
	 * Get the configured cron heartbeat interval.
	 *
	 * @return int
	 */
	private static function get_heartbeat_seconds() {
		$seconds = (int) get_site_option( self::OPTION_HEARTBEAT_SECONDS, MINUTE_IN_SECONDS );

		return $seconds > 0 ? $seconds : MINUTE_IN_SECONDS;
	}

	/**
	 * Store heartbeat telemetry.
	 *
	 * @param array<string,mixed> $telemetry Telemetry values.
	 * @return void
	 */
	private function store_telemetry( $telemetry ) {
		$existing = get_site_option( self::OPTION_TELEMETRY, array() );
		$existing = is_array( $existing ) ? $existing : array();

		update_site_option(
			self::OPTION_TELEMETRY,
			array_merge(
				$existing,
				$telemetry,
				array( 'last_run_at' => current_time( 'mysql' ) )
			)
		);
	}

	/**
	 * Get elapsed milliseconds.
	 *
	 * @param float $started Start microtime.
	 * @return int
	 */
	private function duration_ms( $started ) {
		return max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) );
	}

	/**
	 * Format queue counts.
	 *
	 * @param array<string,int> $counts Counts.
	 * @return string
	 */
	private function format_counts( $counts ) {
		if ( empty( $counts ) ) {
			return __( 'empty', 'as-content-stream' );
		}

		$parts = array();
		foreach ( $counts as $status => $count ) {
			$parts[] = sprintf( '%s: %d', $status, $count );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Format an internal stream action for display.
	 *
	 * @param string $action Action key.
	 * @return string
	 */
	private function format_action_label( $action ) {
		$action = sanitize_key( $action );

		if ( 'discover' === $action ) {
			return __( 'Discovery', 'as-content-stream' );
		}

		return ucfirst( $action );
	}

	/**
	 * Get target language.
	 *
	 * @return string
	 */
	private function get_target_language() {
		return sanitize_key( (string) get_site_option( self::OPTION_TARGET_LANGUAGE, '' ) );
	}

	/**
	 * Get language counts across destination WPML sites.
	 *
	 * @param array<int,array<string,mixed>> $sites Destination sites.
	 * @return array<string,int>
	 */
	private function get_language_counts( $sites ) {
		$counts = array();

		foreach ( $sites as $site ) {
			if ( empty( $site['wpml_active'] ) || empty( $site['languages'] ) || ! is_array( $site['languages'] ) ) {
				continue;
			}

			foreach ( $site['languages'] as $language ) {
				$language = sanitize_key( $language );
				if ( '' === $language ) {
					continue;
				}

				$counts[ $language ] = isset( $counts[ $language ] ) ? $counts[ $language ] + 1 : 1;
			}
		}

		arsort( $counts );

		return $counts;
	}

	/**
	 * Get saved target language or most common available language.
	 *
	 * @param array<string,int> $language_counts Language counts.
	 * @return string
	 */
	private function get_effective_target_language( $language_counts ) {
		$target_language = $this->get_target_language();

		if ( '' !== $target_language && isset( $language_counts[ $target_language ] ) ) {
			return $target_language;
		}

		if ( empty( $language_counts ) ) {
			return '';
		}

		$languages = array_keys( $language_counts );

		return (string) reset( $languages );
	}

	/**
	 * Store the last capture result for admin visibility.
	 *
	 * @param string       $status Status.
	 * @param string       $message Message.
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post Post object.
	 * @return void
	 */
	private function store_capture_status( $status, $message, $post_id = 0, $post = null ) {
		update_site_option(
			self::OPTION_CAPTURE_STATUS,
			array(
				'checked_at' => current_time( 'mysql' ),
				'status'     => sanitize_key( $status ),
				'message'    => sanitize_text_field( $message ),
				'blog_id'    => get_current_blog_id(),
				'post_id'    => absint( $post_id ),
				'post_type'  => $post instanceof WP_Post ? sanitize_key( $post->post_type ) : '',
				'post_title' => $post instanceof WP_Post ? sanitize_text_field( $post->post_title ) : '',
			)
		);
	}

	/**
	 * Render the latest capture status.
	 *
	 * @return void
	 */
	private function render_capture_status() {
		$status = get_site_option( self::OPTION_CAPTURE_STATUS, array() );

		if ( empty( $status ) || ! is_array( $status ) ) {
			echo '<p>' . esc_html__( 'No content changes have been captured yet.', 'as-content-stream' ) . '</p>';
			return;
		}

		$message = isset( $status['message'] ) ? $status['message'] : '';
		$details = array_filter(
			array(
				isset( $status['checked_at'] ) ? $status['checked_at'] : '',
				isset( $status['post_type'] ) ? $status['post_type'] : '',
				isset( $status['post_title'] ) ? $status['post_title'] : '',
				isset( $status['post_id'] ) && $status['post_id'] ? '#' . absint( $status['post_id'] ) : '',
			)
		);

		echo '<p><strong>' . esc_html( ucfirst( isset( $status['status'] ) ? $status['status'] : 'status' ) ) . ':</strong> ' . esc_html( $message ) . '</p>';
		echo '<p class="description">' . esc_html( implode( ' | ', $details ) ) . '</p>';
	}

	/**
	 * Ensure the integration role and stream author exist on destination WPML sites.
	 *
	 * @param array<int,array<string,mixed>> $sites Destination WPML sites.
	 * @return array<string,mixed>
	 */
	private function ensure_stream_author_for_sites( $sites ) {
		$status = array(
			'checked'    => 0,
			'ready'      => 0,
			'user_id'    => 0,
			'user_label' => self::STREAM_AUTHOR_LOGIN,
			'messages'   => array(),
		);

		if ( ! is_multisite() || empty( $sites ) ) {
			return $status;
		}

		$user_id = $this->ensure_stream_author_user();
		if ( is_wp_error( $user_id ) ) {
			$status['messages'][] = $user_id->get_error_message();
			return $status;
		}

		$status['user_id']    = (int) $user_id;
		$status['user_label'] = sprintf( '%s #%d', self::STREAM_AUTHOR_LOGIN, (int) $user_id );

		foreach ( $sites as $site ) {
			if ( empty( $site['wpml_active'] ) || empty( $site['blog_id'] ) ) {
				continue;
			}

			$status['checked']++;
			$result = $this->ensure_stream_author_on_site( (int) $site['blog_id'], (int) $user_id );

			if ( is_wp_error( $result ) ) {
				$status['messages'][] = sprintf(
					/* translators: 1: site name, 2: error message. */
					__( '%1$s: %2$s', 'as-content-stream' ),
					isset( $site['name'] ) ? $site['name'] : $this->site_label( (int) $site['blog_id'] ),
					$result->get_error_message()
				);
				continue;
			}

			$status['ready']++;
		}

		if ( 0 === (int) $status['checked'] ) {
			$status['messages'][] = __( 'No active WPML destination sites found.', 'as-content-stream' );
		} elseif ( (int) $status['ready'] === (int) $status['checked'] ) {
			$status['messages'][] = __( 'All active WPML destination sites have the stream author ready.', 'as-content-stream' );
		}

		return $status;
	}

	/**
	 * Ensure the global stream author user exists.
	 *
	 * @return int|WP_Error
	 */
	private function ensure_stream_author_user() {
		$user_id = username_exists( self::STREAM_AUTHOR_LOGIN );

		if ( $user_id ) {
			update_user_meta( (int) $user_id, self::STREAM_AUTHOR_META, 1 );
			return (int) $user_id;
		}

		$email_user_id = email_exists( self::STREAM_AUTHOR_EMAIL );
		if ( $email_user_id ) {
			update_user_meta( (int) $email_user_id, self::STREAM_AUTHOR_META, 1 );
			return (int) $email_user_id;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => self::STREAM_AUTHOR_LOGIN,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'user_email'   => self::STREAM_AUTHOR_EMAIL,
				'display_name' => __( 'Content Stream', 'as-content-stream' ),
				'nickname'     => __( 'Content Stream', 'as-content-stream' ),
				'description'  => __( 'System author for Content Stream destination posts.', 'as-content-stream' ),
				'role'         => '',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( (int) $user_id, self::STREAM_AUTHOR_META, 1 );

		return (int) $user_id;
	}

	/**
	 * Ensure the role and stream author membership exist on a switched site.
	 *
	 * @param int $blog_id Blog ID.
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	private function ensure_stream_author_on_site( $blog_id, $user_id ) {
		$restore = get_current_blog_id() !== $blog_id;

		if ( $restore ) {
			switch_to_blog( $blog_id );
		}

		if ( ! get_role( self::STREAM_AUTHOR_ROLE ) ) {
			add_role( self::STREAM_AUTHOR_ROLE, __( 'Integration', 'as-content-stream' ), array() );
		}

		$result = true;
		if ( ! is_user_member_of_blog( $user_id, $blog_id ) ) {
			$result = add_user_to_blog( $blog_id, $user_id, self::STREAM_AUTHOR_ROLE );
		} else {
			$user = new WP_User( $user_id, '', $blog_id );
			if ( ! in_array( self::STREAM_AUTHOR_ROLE, (array) $user->roles, true ) ) {
				$user->add_role( self::STREAM_AUTHOR_ROLE );
			}
		}

		if ( $restore ) {
			restore_current_blog();
		}

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Block direct login for the system stream author.
	 *
	 * @param WP_User|WP_Error|null $user     Authenticated user.
	 * @param string                $username Username.
	 * @param string                $password Password.
	 * @return WP_User|WP_Error|null
	 */
	public function block_stream_author_authentication( $user, $username, $password ) {
		unset( $password );

		if ( self::STREAM_AUTHOR_LOGIN === sanitize_user( $username, true ) ) {
			return new WP_Error( 'as_content_stream_author_login_blocked', __( 'The Content Stream system author cannot log in.', 'as-content-stream' ) );
		}

		return $user;
	}

	/**
	 * Determine whether the current site is the monitored source site.
	 *
	 * @return bool
	 */
	private function is_source_site() {
		if ( ! is_multisite() ) {
			return true;
		}

		return (int) get_current_blog_id() === (int) get_main_site_id();
	}

	/**
	 * Get admin URL for current context.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return string
	 */
	private function admin_url( $args = array() ) {
		$admin_url = is_multisite() ? get_admin_url( get_main_site_id(), 'admin.php' ) : admin_url( 'admin.php' );
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), $admin_url );
	}

	/**
	 * Get form action URL.
	 *
	 * @param string $action Action name.
	 * @return string
	 */
	private function form_action_url( $action ) {
		$admin_post_url = is_multisite() ? get_admin_url( get_main_site_id(), 'admin-post.php' ) : admin_url( 'admin-post.php' );
		return add_query_arg( 'action', $action, $admin_post_url );
	}

	/**
	 * Format list for display.
	 *
	 * @param string[] $items Items.
	 * @return string
	 */
	private function format_list( $items ) {
		return empty( $items ) ? '-' : implode( ', ', $items );
	}

	/**
	 * Build a site label.
	 *
	 * @param int $blog_id Blog ID.
	 * @return string
	 */
	private function site_label( $blog_id ) {
		$details = get_blog_details( $blog_id );

		if ( ! $details ) {
			return sprintf( __( 'Site %d', 'as-content-stream' ), $blog_id );
		}

		return sprintf( '%s (%d)', $details->blogname, $blog_id );
	}
}
