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
 * AS Content Stream bootstrap, admin UI, site discovery, and queue capture.
 */
class AS_Content_Stream {
	const OPTION_TARGET_LANGUAGE = 'as_content_stream_target_language';
	const OPTION_CAPTURE_STATUS  = 'as_content_stream_capture_status';
	const OPTION_PROCESSING_ENABLED = 'as_content_stream_processing_enabled';
	const OPTION_TELEMETRY       = 'as_content_stream_telemetry';
	const NONCE_SETTINGS         = 'as_content_stream_settings';
	const NONCE_QUEUE            = 'as_content_stream_queue';
	const NONCE_LOG              = 'as_content_stream_log';
	const NONCE_HEARTBEAT        = 'as_content_stream_heartbeat';
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

		if ( get_site_option( self::OPTION_PROCESSING_ENABLED, false ) ) {
			self::schedule_cron();
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
		add_action( 'admin_post_as_content_stream_clear_log', array( $this, 'clear_log' ) );
		add_action( 'admin_post_as_content_stream_test_tick', array( $this, 'run_test_tick' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_as_content_stream_heartbeat', array( $this, 'ajax_heartbeat' ) );
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
			KEY parent_status (parent_queue_id, status),
			KEY status (status),
			KEY action_status (action, status),
			KEY target_blog (target_blog_id)
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
	 * Register the core site admin menu.
	 *
	 * @return void
	 */
	public function register_core_site_menu() {
		if ( ! is_multisite() || ! is_main_site() ) {
			return;
		}

		add_menu_page(
			__( 'AS Content Stream', 'as-content-stream' ),
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
			wp_die( esc_html__( 'AS Content Stream is only available in the core site admin.', 'as-content-stream' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage AS Content Stream.', 'as-content-stream' ) );
		}

		self::create_queue_table();

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'create_queue';
		$tabs       = array(
			'settings'     => __( 'Settings', 'as-content-stream' ),
			'sites'        => __( 'Sites & WPML', 'as-content-stream' ),
			'create_queue' => __( 'Create Queue', 'as-content-stream' ),
			'update_queue' => __( 'Update Queue', 'as-content-stream' ),
			'delete_queue' => __( 'Delete Queue', 'as-content-stream' ),
			'processing_queue' => __( 'Processing Queue', 'as-content-stream' ),
			'log'          => __( 'Log', 'as-content-stream' ),
		);

		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'create_queue';
		}

		?>
		<div class="wrap as-content-stream">
			<h1><?php esc_html_e( 'AS Content Stream', 'as-content-stream' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'AS Content Stream tabs', 'as-content-stream' ); ?>">
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
				case 'update_queue':
					$this->render_queue_tab( 'update' );
					break;
				case 'delete_queue':
					$this->render_queue_tab( 'delete' );
					break;
				case 'processing_queue':
					$this->render_processing_queue_tab();
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
					<th><?php esc_html_e( 'Site', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'WPML', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Languages', 'as-content-stream' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $sites ) ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No sites found.', 'as-content-stream' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $sites as $site ) : ?>
					<tr>
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
		$queue_counts = $this->get_queue_counts();
		$processing_counts = $this->get_processing_queue_counts();
		$language_counts = $this->get_language_counts( $sites );
		$target_language = $this->get_effective_target_language( $language_counts );
		$processing_enabled = (bool) get_site_option( self::OPTION_PROCESSING_ENABLED, false );
		$heartbeat = $this->get_heartbeat_status();
		$wpml_sites   = array_filter(
			$sites,
			static function ( $site ) {
				return $site['wpml_active'];
			}
		);
		$stream_author_status = $this->ensure_stream_author_for_sites( $wpml_sites );
		?>
		<div class="as-content-grid">
			<div class="as-content-panel">
				<h2><?php esc_html_e( 'Target Language', 'as-content-stream' ); ?></h2>
				<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_save_settings' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_SETTINGS ); ?>
					<input type="hidden" name="settings_context" value="target_language">
					<label class="screen-reader-text" for="as-content-target-language"><?php esc_html_e( 'Target language', 'as-content-stream' ); ?></label>
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
					<p class="description"><?php esc_html_e( 'Defaults to the most common destination language. Save to override with another available language.', 'as-content-stream' ); ?></p>
					<?php submit_button( __( 'Save Settings', 'as-content-stream' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
			<div class="as-content-panel">
				<h2><?php esc_html_e( 'Processing', 'as-content-stream' ); ?></h2>
				<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_save_settings' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_SETTINGS ); ?>
					<input type="hidden" name="settings_context" value="processing">
					<input type="hidden" name="target_language" value="<?php echo esc_attr( $target_language ); ?>">
					<label class="as-content-toggle">
						<input type="checkbox" name="processing_enabled" value="1" <?php checked( $processing_enabled ); ?>>
						<span><?php esc_html_e( 'Enable processing cron', 'as-content-stream' ); ?></span>
					</label>
					<p class="description"><?php esc_html_e( 'When enabled, cron checks every minute and processes one source queue item at a time.', 'as-content-stream' ); ?></p>
					<?php submit_button( __( 'Save Processing', 'as-content-stream' ), 'primary', 'submit', false ); ?>
				</form>
				<?php if ( ! $processing_enabled ) : ?>
					<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_test_tick' ) ); ?>" class="as-content-test-form">
						<?php wp_nonce_field( self::NONCE_TEST_TICK ); ?>
						<?php submit_button( __( 'Run One Test Tick', 'as-content-stream' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>
			<div class="as-content-panel">
				<h2><?php esc_html_e( 'Network Status', 'as-content-stream' ); ?></h2>
				<p><strong><?php esc_html_e( 'Sites:', 'as-content-stream' ); ?></strong> <?php echo esc_html( count( $sites ) ); ?></p>
				<p><strong><?php esc_html_e( 'WPML active sites:', 'as-content-stream' ); ?></strong> <?php echo esc_html( count( $wpml_sites ) ); ?></p>
				<p><strong><?php esc_html_e( 'Pending queue items:', 'as-content-stream' ); ?></strong> <?php echo esc_html( isset( $queue_counts['pending'] ) ? $queue_counts['pending'] : 0 ); ?></p>
				<p><strong><?php esc_html_e( 'Processing jobs:', 'as-content-stream' ); ?></strong> <?php echo esc_html( $this->format_counts( $processing_counts ) ); ?></p>
			</div>
			<div class="as-content-panel">
				<h2><?php esc_html_e( 'Stream Author', 'as-content-stream' ); ?></h2>
				<p><strong><?php esc_html_e( 'User:', 'as-content-stream' ); ?></strong> <?php echo esc_html( $stream_author_status['user_label'] ); ?></p>
				<p><strong><?php esc_html_e( 'Role:', 'as-content-stream' ); ?></strong> <?php echo esc_html( self::STREAM_AUTHOR_ROLE ); ?></p>
				<p><strong><?php esc_html_e( 'Sites checked:', 'as-content-stream' ); ?></strong> <?php echo esc_html( (int) $stream_author_status['checked'] ); ?></p>
				<p><strong><?php esc_html_e( 'Sites ready:', 'as-content-stream' ); ?></strong> <?php echo esc_html( (int) $stream_author_status['ready'] ); ?></p>
				<?php if ( ! empty( $stream_author_status['messages'] ) ) : ?>
					<p class="description"><?php echo esc_html( implode( ' ', $stream_author_status['messages'] ) ); ?></p>
				<?php endif; ?>
			</div>
			<div class="as-content-panel">
				<h2><?php esc_html_e( 'Heartbeat', 'as-content-stream' ); ?></h2>
				<div id="as-content-heartbeat" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_HEARTBEAT ) ); ?>">
					<p><strong><?php esc_html_e( 'Status:', 'as-content-stream' ); ?></strong> <span data-as-heartbeat="enabled"><?php echo esc_html( $heartbeat['enabled'] ? __( 'On', 'as-content-stream' ) : __( 'Off', 'as-content-stream' ) ); ?></span></p>
					<p><strong><?php esc_html_e( 'Next check:', 'as-content-stream' ); ?></strong> <span data-as-heartbeat="next_check"><?php echo esc_html( $heartbeat['next_check_seconds'] ); ?></span> <?php esc_html_e( 'seconds', 'as-content-stream' ); ?></p>
					<p><strong><?php esc_html_e( 'Current phase:', 'as-content-stream' ); ?></strong> <span data-as-heartbeat="phase"><?php echo esc_html( $heartbeat['phase'] ); ?></span></p>
					<div class="as-content-progress" aria-hidden="true">
						<span data-as-heartbeat-bar style="width: <?php echo esc_attr( $heartbeat['progress_percent'] ); ?>%;"></span>
					</div>
					<p><span data-as-heartbeat="batch_done"><?php echo esc_html( $heartbeat['batch_done'] ); ?></span> / <span data-as-heartbeat="batch_total"><?php echo esc_html( $heartbeat['batch_total'] ); ?></span> <?php esc_html_e( 'items in current batch', 'as-content-stream' ); ?></p>
					<p><strong><?php esc_html_e( 'Last batch:', 'as-content-stream' ); ?></strong> <span data-as-heartbeat="last_duration"><?php echo esc_html( $heartbeat['last_batch_duration_ms'] ); ?></span>ms</p>
					<p class="description" data-as-heartbeat="last_message"><?php echo esc_html( $heartbeat['last_message'] ); ?></p>
				</div>
			</div>
		</div>
		<script>
			(function () {
				var root = document.getElementById('as-content-heartbeat');
				if (!root || !window.ajaxurl) {
					return;
				}
				function setText(name, value) {
					var node = root.querySelector('[data-as-heartbeat="' + name + '"]');
					if (node) {
						node.textContent = value;
					}
				}
				function refresh() {
					var data = new window.FormData();
					data.append('action', 'as_content_stream_heartbeat');
					data.append('nonce', root.getAttribute('data-nonce'));
					window.fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function (response) { return response.json(); })
						.then(function (response) {
							if (!response || !response.success || !response.data) {
								return;
							}
							var status = response.data;
							setText('enabled', status.enabled ? 'On' : 'Off');
							setText('next_check', status.next_check_seconds);
							setText('phase', status.phase);
							setText('batch_done', status.batch_done);
							setText('batch_total', status.batch_total);
							setText('last_duration', status.last_batch_duration_ms);
							setText('last_message', status.last_message);
							var bar = root.querySelector('[data-as-heartbeat-bar]');
							if (bar) {
								bar.style.width = status.progress_percent + '%';
							}
						})
						.catch(function () {});
				}
				window.setInterval(refresh, 5000);
			}());
		</script>
		<?php
	}

	/**
	 * Render queue.
	 *
	 * @return void
	 */
	private function render_queue_tab( $action ) {
		$items  = $this->get_queue_items( $action );
		$counts = $this->get_queue_counts();
		?>
		<div class="as-content-queue-actions">
			<p><strong><?php esc_html_e( 'Queue status:', 'as-content-stream' ); ?></strong> <?php echo esc_html( $this->format_counts( $counts ) ); ?></p>
			<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_clear_queue' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_QUEUE ); ?>
				<?php submit_button( __( 'Clear Pending Items', 'as-content-stream' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<table class="widefat striped as-content-queue">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Created', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Action', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Status', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Source', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Post Title', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Post Name', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Original Post Name', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Post Type', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Inspect', 'as-content-stream' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr><td colspan="9"><?php esc_html_e( 'No queue items yet.', 'as-content-stream' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $items as $item ) : ?>
					<?php $payload = $this->decode_queue_payload( $item->payload ); ?>
					<tr>
						<td><?php echo esc_html( $item->created_at ); ?></td>
						<td><?php echo esc_html( ucfirst( $item->action ) ); ?></td>
						<td><?php echo esc_html( ucfirst( $item->status ) ); ?></td>
						<td><?php echo esc_html( $this->site_label( (int) $item->source_blog_id ) . ' #' . (int) $item->source_post_id ); ?></td>
						<td><?php echo esc_html( isset( $payload['post_title'] ) ? $payload['post_title'] : '' ); ?></td>
						<td><?php echo esc_html( isset( $payload['post_name'] ) ? $payload['post_name'] : '' ); ?></td>
						<td><?php echo esc_html( isset( $payload['original_post_name'] ) ? $payload['original_post_name'] : '' ); ?></td>
						<td><?php echo esc_html( $item->post_type ); ?></td>
						<td>
							<?php $edit_url = $this->source_edit_url( (int) $item->source_blog_id, (int) $item->source_post_id ); ?>
							<?php if ( $edit_url ) : ?>
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'as-content-stream' ); ?></a>
							<?php else : ?>
								<?php echo esc_html( '-' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render processing queue.
	 *
	 * @return void
	 */
	private function render_processing_queue_tab() {
		$items = $this->get_processing_queue_items( false );
		?>
		<table class="widefat striped as-content-queue">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Created', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Parent', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Action', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Status', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Source', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Destination', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Language', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Attempts', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Result', 'as-content-stream' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr><td colspan="10"><?php esc_html_e( 'No processing jobs yet.', 'as-content-stream' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $items as $item ) : ?>
					<tr>
						<td><?php echo esc_html( $item->created_at ); ?></td>
						<td><?php echo esc_html( '#' . (int) $item->parent_queue_id ); ?></td>
						<td><?php echo esc_html( ucfirst( $item->action ) ); ?></td>
						<td><?php echo esc_html( ucfirst( $item->status ) ); ?></td>
						<td><?php echo esc_html( $this->site_label( (int) $item->source_blog_id ) . ' #' . (int) $item->source_post_id ); ?></td>
						<td><?php echo esc_html( $this->site_label( (int) $item->target_blog_id ) ); ?></td>
						<td><?php echo esc_html( $item->target_language ); ?></td>
						<td><?php echo esc_html( (int) $item->attempts ); ?></td>
						<td><?php echo esc_html( (int) $item->duration_ms . 'ms' ); ?></td>
						<td><?php echo esc_html( $item->result_message ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render processing log.
	 *
	 * @return void
	 */
	private function render_log_tab() {
		$items = $this->get_processing_queue_items( true );
		?>
		<div class="as-content-queue-actions">
			<p><?php esc_html_e( 'Showing the latest 100 completed processing jobs.', 'as-content-stream' ); ?></p>
			<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_clear_log' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_LOG ); ?>
				<?php submit_button( __( 'Clear Log', 'as-content-stream' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<table class="widefat striped as-content-queue">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Completed', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Parent', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Action', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Status', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Source', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Destination', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Language', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'as-content-stream' ); ?></th>
					<th><?php esc_html_e( 'Result', 'as-content-stream' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr><td colspan="9"><?php esc_html_e( 'No completed processing jobs yet.', 'as-content-stream' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $items as $item ) : ?>
					<tr>
						<td><?php echo esc_html( $item->completed_at ); ?></td>
						<td><?php echo esc_html( '#' . (int) $item->parent_queue_id ); ?></td>
						<td><?php echo esc_html( ucfirst( $item->action ) ); ?></td>
						<td><?php echo esc_html( ucfirst( $item->status ) ); ?></td>
						<td><?php echo esc_html( $this->site_label( (int) $item->source_blog_id ) . ' #' . (int) $item->source_post_id ); ?></td>
						<td><?php echo esc_html( $this->site_label( (int) $item->target_blog_id ) ); ?></td>
						<td><?php echo esc_html( $item->target_language ); ?></td>
						<td><?php echo esc_html( (int) $item->duration_ms . 'ms' ); ?></td>
						<td><?php echo esc_html( $item->result_message ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to update AS Content Stream.', 'as-content-stream' ) );
		}

		check_admin_referer( self::NONCE_SETTINGS );

		$settings_context = isset( $_POST['settings_context'] ) ? sanitize_key( wp_unslash( $_POST['settings_context'] ) ) : '';
		$language_counts = $this->get_language_counts( $this->discover_sites() );
		$target_language = isset( $_POST['target_language'] ) ? sanitize_key( wp_unslash( $_POST['target_language'] ) ) : '';
		if ( '' !== $target_language && ! isset( $language_counts[ $target_language ] ) ) {
			$target_language = '';
		}

		update_site_option( self::OPTION_TARGET_LANGUAGE, $target_language );

		if ( 'processing' === $settings_context ) {
			$processing_enabled = ! empty( $_POST['processing_enabled'] );
			update_site_option( self::OPTION_PROCESSING_ENABLED, $processing_enabled ? 1 : 0 );

			if ( $processing_enabled ) {
				self::schedule_cron();
			} else {
				self::unschedule_cron();
			}
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
	 * Add one-minute cron schedule.
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_cron_schedules( $schedules ) {
		$schedules['as_content_stream_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute', 'as-content-stream' ),
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
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'as_content_stream_minute', self::CRON_HOOK );
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
	 * @return void
	 */
	public function process_tick( $force = false ) {
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

		if ( ! $source_item ) {
			$this->store_telemetry(
				array(
					'phase'        => 'idle',
					'batch_total'  => 0,
					'batch_done'   => 0,
					'last_message' => __( 'No source queue items are pending.', 'as-content-stream' ),
					'last_batch_duration_ms' => $this->duration_ms( $started ),
				)
			);
			return;
		}

		$this->explode_source_queue_item( $source_item );
		$result = $this->process_processing_jobs( (int) $source_item->id );
		$this->complete_source_queue_item_if_ready( (int) $source_item->id );

		$this->store_telemetry(
			array(
				'phase'        => $source_item->action,
				'current_source_id' => (int) $source_item->id,
				'batch_total'  => (int) $result['total'],
				'batch_done'   => (int) $result['done'],
				'last_message' => sprintf(
					/* translators: 1: processed count, 2: total count. */
					__( 'Processed %1$d of %2$d processing jobs.', 'as-content-stream' ),
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
			wp_die( esc_html__( 'You do not have permission to run AS Content Stream processing.', 'as-content-stream' ) );
		}

		check_admin_referer( self::NONCE_TEST_TICK );

		if ( ! get_site_option( self::OPTION_PROCESSING_ENABLED, false ) && 0 === $this->get_active_processing_job_count() ) {
			$this->process_tick( true );
		}

		wp_safe_redirect( $this->admin_url( array( 'tab' => 'processing_queue', 'updated' => 1 ) ) );
		exit;
	}

	/**
	 * Get next source queue item in create, update, delete order.
	 *
	 * @return object|null
	 */
	private function get_next_source_queue_item() {
		global $wpdb;

		foreach ( array( 'create', 'update', 'delete' ) as $action ) {
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
	 * @return void
	 */
	private function explode_source_queue_item( $source_item ) {
		global $wpdb;

		if ( $this->processing_jobs_exist_for_parent( (int) $source_item->id ) ) {
			return;
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
			return;
		}

		foreach ( $targets as $target ) {
			$wpdb->insert(
				self::processing_queue_table_name(),
				array(
					'created_at'      => current_time( 'mysql', true ),
					'parent_queue_id' => (int) $source_item->id,
					'action'          => sanitize_key( $source_item->action ),
					'status'          => 'pending',
					'source_blog_id'  => (int) $source_item->source_blog_id,
					'source_post_id'  => (int) $source_item->source_post_id,
					'target_blog_id'  => (int) $target['blog_id'],
					'target_language' => sanitize_key( $target_language ),
					'post_type'       => sanitize_key( $source_item->post_type ),
					'payload'         => $source_item->payload,
				),
				array( '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Process pending child jobs for a source item.
	 *
	 * @param int $parent_queue_id Parent queue ID.
	 * @return array<string,int>
	 */
	private function process_processing_jobs( $parent_queue_id ) {
		global $wpdb;

		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::processing_queue_table_name() . ' WHERE parent_queue_id = %d AND status = %s ORDER BY id ASC',
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

			$result = $this->process_processing_job_noop( $job );
			$wpdb->update(
				self::processing_queue_table_name(),
				array(
					'status'         => $result['status'],
					'completed_at'   => current_time( 'mysql', true ),
					'duration_ms'    => $this->duration_ms( $started ),
					'result_message' => $result['message'],
				),
				array( 'id' => (int) $job->id ),
				array( '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
			$done++;
		}

		return array(
			'total' => $total,
			'done'  => $done,
		);
	}

	/**
	 * Placeholder processing function.
	 *
	 * @param object $job Processing job.
	 * @return array<string,string>
	 */
	private function process_processing_job_noop( $job ) {
		return array(
			'status'  => 'complete',
			'message' => __( 'No-op processor completed. Streaming actions are not implemented yet.', 'as-content-stream' ),
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
				'SELECT COUNT(*) FROM ' . self::processing_queue_table_name() . " WHERE parent_queue_id = %d AND status IN ('pending', 'in_progress')",
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
			wp_die( esc_html__( 'You do not have permission to update AS Content Stream.', 'as-content-stream' ) );
		}

		check_admin_referer( self::NONCE_QUEUE );

		global $wpdb;
		$wpdb->delete( self::queue_table_name(), array( 'status' => 'pending' ), array( '%s' ) );

		wp_safe_redirect( $this->admin_url( array( 'tab' => 'create_queue', 'queue_cleared' => 1 ) ) );
		exit;
	}

	/**
	 * Clear terminal processing log rows.
	 *
	 * @return void
	 */
	public function clear_log() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to update AS Content Stream.', 'as-content-stream' ) );
		}

		check_admin_referer( self::NONCE_LOG );

		global $wpdb;
		self::create_processing_queue_table();
		$wpdb->query( "DELETE FROM " . self::processing_queue_table_name() . " WHERE status IN ('complete', 'skipped', 'failed')" );

		wp_safe_redirect( $this->admin_url( array( 'tab' => 'log', 'log_cleared' => 1 ) ) );
		exit;
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

		$this->insert_queue_item(
			array(
				'action'          => $action,
				'source_blog_id'  => $source_blog_id,
				'source_post_id'  => $source_post_id,
				'target_blog_id'  => 0,
				'target_language' => '',
				'post_type'       => $post_type,
				'payload'         => array(
					'post_title'  => $post->post_title,
					'post_status' => $post->post_status,
					'post_name'   => $post->post_name,
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
	 * Get queue rows.
	 *
	 * @return array<int,object>
	 */
	private function get_queue_items( $action ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::queue_table_name() . " WHERE action = %s AND status != 'complete' ORDER BY id DESC LIMIT 100",
				$action
			)
		);
	}

	/**
	 * Get processing queue rows.
	 *
	 * @param bool $terminal Whether to get terminal log rows.
	 * @return array<int,object>
	 */
	private function get_processing_queue_items( $terminal ) {
		global $wpdb;

		self::create_processing_queue_table();

		$status_sql = $terminal ? "status IN ('complete', 'skipped', 'failed')" : "status NOT IN ('complete', 'skipped', 'failed')";

		return $wpdb->get_results( 'SELECT * FROM ' . self::processing_queue_table_name() . ' WHERE ' . $status_sql . ' ORDER BY id DESC LIMIT 100' );
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
				'SELECT id FROM ' . self::processing_queue_table_name() . ' WHERE parent_queue_id = %d LIMIT 1',
				$parent_queue_id
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
	 * Get heartbeat status.
	 *
	 * @return array<string,mixed>
	 */
	private function get_heartbeat_status() {
		$enabled = (bool) get_site_option( self::OPTION_PROCESSING_ENABLED, false );
		$telemetry = get_site_option( self::OPTION_TELEMETRY, array() );
		$telemetry = is_array( $telemetry ) ? $telemetry : array();
		$next = wp_next_scheduled( self::CRON_HOOK );
		$batch_total = isset( $telemetry['batch_total'] ) ? (int) $telemetry['batch_total'] : 0;
		$batch_done = isset( $telemetry['batch_done'] ) ? (int) $telemetry['batch_done'] : 0;
		$progress = $batch_total > 0 ? min( 100, round( ( $batch_done / $batch_total ) * 100 ) ) : 0;

		return array(
			'enabled'                => $enabled,
			'next_check_seconds'     => $enabled && $next ? max( 0, $next - time() ) : 0,
			'phase'                  => isset( $telemetry['phase'] ) ? sanitize_key( $telemetry['phase'] ) : 'idle',
			'current_source_id'      => isset( $telemetry['current_source_id'] ) ? (int) $telemetry['current_source_id'] : 0,
			'batch_total'            => $batch_total,
			'batch_done'             => $batch_done,
			'batch_remaining'        => max( 0, $batch_total - $batch_done ),
			'progress_percent'       => $progress,
			'last_batch_duration_ms' => isset( $telemetry['last_batch_duration_ms'] ) ? (int) $telemetry['last_batch_duration_ms'] : 0,
			'last_message'           => isset( $telemetry['last_message'] ) ? (string) $telemetry['last_message'] : __( 'No processing runs yet.', 'as-content-stream' ),
			'queue_counts'           => $this->get_queue_counts(),
			'processing_counts'      => $this->get_processing_queue_counts(),
		);
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
				'display_name' => __( 'AS Content Stream', 'as-content-stream' ),
				'nickname'     => __( 'AS Content Stream', 'as-content-stream' ),
				'description'  => __( 'System author for streamed content.', 'as-content-stream' ),
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
			return new WP_Error( 'as_content_stream_author_login_blocked', __( 'The AS Content Stream system author cannot log in.', 'as-content-stream' ) );
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
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Get form action URL.
	 *
	 * @param string $action Action name.
	 * @return string
	 */
	private function form_action_url( $action ) {
		return add_query_arg( 'action', $action, admin_url( 'admin-post.php' ) );
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
