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
	const NONCE_SETTINGS         = 'as_content_stream_settings';
	const NONCE_QUEUE            = 'as_content_stream_queue';
	const PAGE_SLUG              = 'as-content-stream';

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
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_core_site_menu' ) );
		add_action( 'admin_post_as_content_stream_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_as_content_stream_clear_queue', array( $this, 'clear_queue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		add_action( 'wp_after_insert_post', array( $this, 'capture_after_insert_post' ), 20, 4 );
		add_action( 'wp_trash_post', array( $this, 'capture_trash_post' ), 20, 2 );
		add_action( 'before_delete_post', array( $this, 'capture_delete_post' ), 20, 2 );
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
	 * Get queue table name.
	 *
	 * @return string
	 */
	private static function queue_table_name() {
		global $wpdb;

		return $wpdb->base_prefix . 'as_content_stream_queue';
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
		$language_counts = $this->get_language_counts( $sites );
		$target_language = $this->get_effective_target_language( $language_counts );
		$wpml_sites   = array_filter(
			$sites,
			static function ( $site ) {
				return $site['wpml_active'];
			}
		);
		?>
		<div class="as-content-grid">
			<div class="as-content-panel">
				<h2><?php esc_html_e( 'Target Language', 'as-content-stream' ); ?></h2>
				<form method="post" action="<?php echo esc_url( $this->form_action_url( 'as_content_stream_save_settings' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_SETTINGS ); ?>
					<label class="screen-reader-text" for="as-content-target-language"><?php esc_html_e( 'Target language', 'as-content-stream' ); ?></label>
					<select id="as-content-target-language" class="regular-text" name="target_language">
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
				<h2><?php esc_html_e( 'Network Status', 'as-content-stream' ); ?></h2>
				<p><strong><?php esc_html_e( 'Sites:', 'as-content-stream' ); ?></strong> <?php echo esc_html( count( $sites ) ); ?></p>
				<p><strong><?php esc_html_e( 'WPML active sites:', 'as-content-stream' ); ?></strong> <?php echo esc_html( count( $wpml_sites ) ); ?></p>
				<p><strong><?php esc_html_e( 'Pending queue items:', 'as-content-stream' ); ?></strong> <?php echo esc_html( isset( $queue_counts['pending'] ) ? $queue_counts['pending'] : 0 ); ?></p>
			</div>
			<div class="as-content-panel">
				<h2><?php esc_html_e( 'Last Capture', 'as-content-stream' ); ?></h2>
				<?php $this->render_capture_status(); ?>
			</div>
		</div>
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
	 * Save settings.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! is_multisite() || ! is_main_site() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to update AS Content Stream.', 'as-content-stream' ) );
		}

		check_admin_referer( self::NONCE_SETTINGS );

		$language_counts = $this->get_language_counts( $this->discover_sites() );
		$target_language = isset( $_POST['target_language'] ) ? sanitize_key( wp_unslash( $_POST['target_language'] ) ) : '';
		if ( '' !== $target_language && ! isset( $language_counts[ $target_language ] ) ) {
			$target_language = '';
		}

		update_site_option( self::OPTION_TARGET_LANGUAGE, $target_language );

		wp_safe_redirect( $this->admin_url( array( 'tab' => 'settings', 'updated' => 1 ) ) );
		exit;
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
				'SELECT * FROM ' . self::queue_table_name() . ' WHERE action = %s ORDER BY id DESC LIMIT 100',
				$action
			)
		);
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
