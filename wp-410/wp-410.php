<?php
/**
 * Plugin Name:       HTTP 410 (Gone) responses
 * Plugin URI:        https://wordpress.org/plugins/wp-410/
 * Description:       Sends HTTP 410 (Gone) responses to requests for pages that no longer exist on your blog.
 * Version:           1.0.3
 * Author:            Samir Shah
 * Author URI:        http://rayofsolaris.net/
 * Maintainer:        Matt Calvert
 * Maintainer URI:    https://calvert.media
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package           MCLV_410_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Main plugin class for HTTP 410 (Gone) responses.
 */
class MCLV_410_Plugin {

	/**
	 * Current database schema version.
	 *
	 * @var int
	 */
	const DB_VERSION = 5;

	/**
	 * Whether pretty permalinks are enabled for the current site.
	 *
	 * @var bool
	 */
	private $permalinks;

	/**
	 * Name of the plugin's database table.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Set initial state and register admin/front-end hooks.
	 *
	 * Determines permalink support, stores the plugin table name, and hooks
	 * upgrade checks plus admin or template redirects depending on context.
	 * Always listens for new posts to reconcile obsolete link entries.
	 */
	public function __construct() {
		$this->permalinks = (bool) get_option( 'permalink_structure' );
		$this->table      = $GLOBALS['wpdb']->prefix . '410_links';

		add_action( 'plugins_loaded', array( $this, 'upgrade_check' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'settings_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		} else {
			add_action( 'template_redirect', array( $this, 'check_for_410' ) );
		}

		// these could theoretically happen both with/without is_admin().
		add_action( 'wp_insert_post', array( $this, 'note_inserted_post' ) );
	}

	/**
	 * Create the plugin's custom database table if it does not exist.
	 *
	 * Uses dbDelta to ensure the latest schema (including indexes) is present.
	 *
	 * @return void
	 */
	private function install_table() {
		// remember, two spaces after PRIMARY KEY otherwise WP borks.
		$sql = "CREATE TABLE $this->table (
			gone_id MEDIUMINT unsigned NOT NULL AUTO_INCREMENT,
			gone_key VARCHAR(512) NOT NULL,
			gone_regex VARCHAR(512) NOT NULL,
			is_404 SMALLINT(1) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (gone_id),
			KEY is_404 (is_404)
		);";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Fetch all registered 410 links.
	 *
	 * @return object[] Array of link rows keyed by gone_key.
	 */
	private function get_links() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, data changes frequently, caching would show stale results.
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT gone_key, gone_regex FROM {$this->table} WHERE is_404 = %d",
				0
			),
			OBJECT_K
		);
	}

	/**
	 * Maximum number of 404 entries to retain.
	 *
	 * @return int
	 */
	private function max_404_list_length() {
		return get_option( 'mclv_410_max_404s', 50 );
	}

	/**
	 * Fetch recent logged 404 entries, trimmed to the configured limit.
	 *
	 * @return object[] Array of 404 link rows keyed by gone_key.
	 */
	private function get_404s() {
		global $wpdb;

		$this->concat_404_list();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, data changes frequently, caching would show stale results.
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT gone_key, gone_regex FROM {$this->table} WHERE is_404 = %d ORDER BY gone_id DESC",
				1
			),
			OBJECT_K
		);
	}

	/**
	 * Insert a new 410 or logged 404 entry and store its regex matcher.
	 *
	 * Skips when 404 logging is disabled or the key already exists.
	 *
	 * @param string $key    Fully qualified URL (supports * wildcards).
	 * @param bool   $is_404 Whether this entry represents a logged 404 hit.
	 * @return int|null      Number of rows affected when duplicate is found, otherwise void.
	 */
	private function add_link( $key, $is_404 = false ) {
		// just supply the link.
		global $wpdb;

		// 404 logging enabled?
		if ( $is_404 && 0 === $this->max_404_list_length() ) {
			return;
		}

		// build regex.
		$parts = preg_split( '/(\*)/', $key, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		foreach ( $parts as &$part ) {
			if ( '*' !== $part ) {
				$part = preg_quote( $part, '|' );
			}
		}
		$parts = str_replace( '*', '.*', $parts );
		$regex = '|^' . implode( '', $parts ) . '$|i';

		// avoid duplicates - messy but MySQL doesn't allow url-length unique keys.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, checking for duplicates before insert.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$this->table} WHERE gone_key = %s",
				$key
			)
		);

		if ( $count > 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, insert operation.
		$wpdb->insert(
			$this->table,
			array(
				'gone_key'   => $key,
				'gone_regex' => $regex,
				'is_404'     => intval( $is_404 ),
			)
		);

		// Don't let 404 list grow forever.
		if ( $is_404 ) {
			$this->concat_404_list();
		}
	}

	/**
	 * Trim the logged 404 list to the configured maximum length.
	 *
	 * @return void
	 */
	private function concat_404_list() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, count needed for immediate trim operation.
		$total_404s = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$this->table} WHERE is_404 = %d",
				1
			)
		);

		$n = $total_404s - $this->max_404_list_length();

		if ( $n > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, delete operation to trim list.
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"DELETE FROM {$this->table} WHERE is_404 = %d ORDER BY gone_id LIMIT %d",
					1,
					$n
				)
			);
		}
	}

	/**
	 * Promote a logged 404 entry to a 410 entry.
	 *
	 * @param string $key URL key to convert.
	 * @return int|false  Rows updated or false on error.
	 */
	private function convert_404( $key ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, update operation.
		return $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$this->table} SET is_404 = %d WHERE gone_key = %s LIMIT 1",
				0,
				$key
			)
		);
	}

	/**
	 * Delete a stored link (410 or 404) by its key.
	 *
	 * @param string $key URL key to remove.
	 * @return int|false  Rows deleted or false on error.
	 */
	private function remove_link( $key ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, delete operation.
		return $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$this->table} WHERE gone_key = %s",
				$key
			)
		);
	}

	/**
	 * Checks whether the plugin's stored database/version options need upgrading,
	 * and performs required migrations when moving between older plugin versions.
	 *
	 * This handles:
	 * - Installing the custom 410 table when upgrading from versions before DB version 5.
	 * - Migrating legacy stored links (options-based) into the database when upgrading from
	 *   versions prior to DB version 3.
	 * - Removing deprecated options once migration is complete.
	 *
	 * @return void
	 */
	public function upgrade_check() {
		$options_version = (int) get_option( 'mclv_410_options_version', 0 );

		if ( self::DB_VERSION === $options_version ) {
			return;
		}

		// last db change was in version 5.
		if ( $options_version < 5 ) {
			$this->install_table();
		}

		if ( $options_version < 3 ) {
			$old_links = get_option( 'mclv_410_links_list', array() );
			$new_links = array();    // just a simple array of links.

			if ( 0 === $options_version ) { // links were stored just as links.
				$new_links = array_map( 'rawurldecode', $old_links );
			} elseif ( 1 === $options_version ) { // links were stored as array( link => regex ). We only need the link.
				$new_links = array_map( 'rawurldecode', array_keys( $old_links ) );
			} else { // moved to using the database in DB_VERSION 3.
				$new_links = array_keys( $old_links );
			}

			foreach ( $new_links as $link ) {
				$this->add_link( $link );
			}

			delete_option( 'mclv_410_links_list' );   // remove old option.
		}

		update_option( 'mclv_410_options_version', self::DB_VERSION );
	}

	/**
	 * Registers the 410 plugin settings page within the WordPress admin Plugins menu.
	 *
	 * Adds a submenu item under "Plugins" that links to the management screen for
	 * obsolete URLs, recent 404s, and other plugin configuration options.
	 *
	 * @return void
	 */
	public function settings_menu() {
		add_submenu_page( 'plugins.php', 'HTTP 410 (Gone) responses', 'HTTP 410 (Gone) responses', 'manage_options', 'mclv_410_settings', array( $this, 'settings_page' ) );
	}

	/**
	 * Enqueue admin styles and scripts for the plugin settings page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		// Only load on our settings page.
		if ( 'plugins_page_mclv_410_settings' !== $hook_suffix ) {
			return;
		}

		// Enqueue admin CSS.
		wp_enqueue_style(
			'mclv-410-admin',
			plugin_dir_url( __FILE__ ) . 'css/admin.css',
			array(),
			'1.0.3'
		);

		// Enqueue admin JavaScript.
		wp_enqueue_script(
			'mclv-410-admin',
			plugin_dir_url( __FILE__ ) . 'js/admin.js',
			array(),
			'1.0.3',
			true
		);
	}

	/**
	 * Render and handle the plugin settings page.
	 *
	 * Processes add/delete/link-length form submissions, refreshes link lists,
	 * and loads the admin settings view template.
	 *
	 * @return void
	 */
	public function settings_page() {
		$links       = $this->get_links();
		$logged_404s = $this->get_404s();

		// Handle form submissions and show success message if action was taken.
		$action_taken = $this->handle_settings_form_submissions( $links, $logged_404s );
		if ( $action_taken ) {
			echo '<div id="message" class="updated fade"><p>Options updated.</p></div>';
		}

		// Separate wildcards from regular URLs.
		$wildcard_links = array();
		$regular_links  = array();

		foreach ( $links as $key => $link ) {
			if ( $this->is_wildcard_url( $key ) ) {
				$wildcard_links[ $key ] = $link;
			} else {
				$regular_links[ $key ] = $link;
			}
		}

		ksort( $wildcard_links );
		ksort( $regular_links );

		// Prepare variables for the view.
		$max_404_length   = $this->max_404_list_length();
		$has_410_template = (bool) locate_template( '410.php' );
		$plugin           = $this;

		// Load the view template.
		include plugin_dir_path( __FILE__ ) . 'views/admin-settings.php';
	}

	/**
	 * Handle form submissions on the settings page.
	 *
	 * @param array $links       Reference to the links array (modified in place).
	 * @param array $logged_404s Reference to the 404s array (modified in place).
	 * @return bool Whether an action was taken.
	 */
	private function handle_settings_form_submissions( &$links, &$logged_404s ) {
		// Delete regular URLs.
		if ( isset( $_POST['delete-regular-urls'] ) && ! empty( $_POST['regular_links_to_remove'] ) ) {
			check_admin_referer( 'mclv-410-settings' );
			$regular_links_to_remove = array_map( 'sanitize_text_field', wp_unslash( $_POST['regular_links_to_remove'] ) );
			foreach ( $regular_links_to_remove as $key ) {
				if ( isset( $links[ $key ] ) ) {
					$this->remove_link( $key );
					unset( $links[ $key ] );
				}
			}
			return true;
		}

		// Delete wildcard URLs.
		if ( isset( $_POST['delete-wildcard-urls'] ) && ! empty( $_POST['wildcard_links_to_remove'] ) ) {
			check_admin_referer( 'mclv-410-settings' );
			$wildcard_links_to_remove = array_map( 'sanitize_text_field', wp_unslash( $_POST['wildcard_links_to_remove'] ) );
			foreach ( $wildcard_links_to_remove as $key ) {
				if ( isset( $links[ $key ] ) ) {
					$this->remove_link( $key );
					unset( $links[ $key ] );
				}
			}
			return true;
		}

		if ( isset( $_POST['add-to-410-list'] ) ) {
			// Entries to add, either manually or from 404 list.
			check_admin_referer( 'mclv-410-settings' );
			$failed_to_add = array();

			if ( ! empty( $_POST['links_to_add'] ) ) {
				$links_to_add_raw = sanitize_textarea_field( wp_unslash( $_POST['links_to_add'] ) );
				foreach ( preg_split( '/(\r?\n)+/', $links_to_add_raw, -1, PREG_SPLIT_NO_EMPTY ) as $link ) {
					$link = sanitize_text_field( $link );
					if ( $this->is_valid_url( $link ) ) {
						$this->add_link( $link );
					} else {
						$failed_to_add[] = '<code>' . esc_html( $link ) . '</code>';
					}
				}
			}

			if ( ! empty( $_POST['add_404s'] ) ) {
				$add_404s = array_map( 'sanitize_text_field', wp_unslash( $_POST['add_404s'] ) );
				foreach ( $add_404s as $link ) {
					if ( isset( $logged_404s[ $link ] ) ) {
						$this->convert_404( $link );
					}
				}
			}

			// Refresh lists after adding.
			$links       = $this->get_links();
			$logged_404s = $this->get_404s();

			if ( $failed_to_add ) {
				$message  = '<div class="error"><p>The following entries could not be recognised as URLs that your WordPress site handles, and were not added to the list. ';
				$message .= 'This can be because the domain name and path does not match that of your WordPress site, or because pretty permalinks are disabled.</p>';
				$message .= '<p>- ' . implode( '<br> - ', $failed_to_add ) . '</p></div>';
				echo wp_kses_post( $message );
			}
			return true;
		}

		if ( isset( $_POST['set-404-list-length'] ) ) {
			check_admin_referer( 'mclv-410-settings' );
			$max_404_length = isset( $_POST['max_404_list_length'] ) ? absint( $_POST['max_404_list_length'] ) : 50;
			update_option( 'mclv_410_max_404s', $max_404_length );
			$logged_404s = $this->get_404s();
			return true;
		}

		return false;
	}

	/**
	 * Determine if a URL can be handled by the current WordPress install.
	 *
	 * Checks path prefix and, when permalinks are off, ensures the URL is not
	 * a pretty permalink format.
	 *
	 * @param string $link Fully qualified URL to validate.
	 * @return bool
	 */
	private function is_valid_url( $link ) {
		// Determine whether WP will handle a request for this URL.
		$wp_path   = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$link_path = wp_parse_url( $link, PHP_URL_PATH );

		if ( 0 !== strpos( $link_path, $wp_path ) ) {
			return false;
		}

		if ( ! $this->permalinks ) {
			$req = preg_replace( '|' . preg_quote( $wp_path, '|' ) . '/?|', '', $link_path );
			if ( strlen( $req ) && '?' !== $req[0] ) {  // this is a pretty permalink, but pretty permalinks are disabled.
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if a URL contains wildcard characters.
	 *
	 * @param string $url URL to check.
	 * @return bool True if URL contains wildcards.
	 */
	private function is_wildcard_url( $url ) {
		return false !== strpos( $url, '*' );
	}

	/**
	 * Render a table of URLs for the admin interface.
	 *
	 * @param array  $urls          Array of URL objects keyed by gone_key.
	 * @param string $table_id      HTML ID for the table.
	 * @param string $checkbox_id   Prefix for checkbox IDs.
	 * @param string $select_all_id ID for the select-all checkbox.
	 * @param string $checkbox_name Name attribute for checkboxes (default: 'old_links_to_remove[]').
	 * @return bool Whether any invalid URLs were found.
	 */
	public function render_url_table( $urls, $table_id, $checkbox_id, $select_all_id, $checkbox_name = 'old_links_to_remove[]' ) {
		$invalid_links_exist = false;

		echo '<div class="mclv-410-table-wrap"><table id="' . esc_attr( $table_id ) . '" class="wp-list-table widefat fixed">';
		echo '<thead><th class="check-column"><input type="checkbox" id="' . esc_attr( $select_all_id ) . '" /><label for="' . esc_attr( $select_all_id ) . '" class="screen-reader-text"> Select all</label></th><th>URL</th></thead>';
		echo '<tbody>';

		foreach ( array_keys( $urls ) as $k ) {
			$valid = $this->is_valid_url( $k );

			if ( ! $valid ) {
				$invalid_links_exist = true;
			}

			$k_attr = esc_attr( $k );
			$k_text = esc_html( $k );
			$class  = $valid ? '' : ' class="invalid"';

			$row_html  = '<tr' . $class . '>';
			$row_html .= '<td><input type="checkbox" name="' . esc_attr( $checkbox_name ) . '" id="' . esc_attr( $checkbox_id ) . '-' . $k_attr . '" value="' . $k_attr . '" /></td>';
			$row_html .= '<td><label for="' . esc_attr( $checkbox_id ) . '-' . $k_attr . '"><code>' . $k_text . '</code></label></td>';
			$row_html .= '</tr>';

			echo wp_kses(
				$row_html,
				array(
					'tr'    => array( 'class' => true ),
					'td'    => array(),
					'input' => array(
						'type'  => true,
						'name'  => true,
						'id'    => true,
						'value' => true,
					),
					'label' => array( 'for' => true ),
					'code'  => array(),
				)
			);
		}

		echo '</tbody></table></div>';

		return $invalid_links_exist;
	}

	/**
	 * Remove matching obsolete links when a post is created or updated.
	 *
	 * @param int $id Post ID.
	 * @return void
	 */
	public function note_inserted_post( $id ) {
		$post = get_post( $id );

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( 'revision' === $post->post_type || 'draft' === $post->post_status ) {
			return;
		}

		// Check our list of URLs against the new/updated post's permalink, and if they match, scratch it from our list.
		$created_links = array();

		$created_links[] = rawurldecode( get_permalink( $id ) );
		$created_links[] = get_post_comments_feed_link( $id );  // back compat.

		if ( $this->permalinks ) {
			$created_links[] = $created_links[0] . '*';
		}

		foreach ( $created_links as $link ) {
			$this->remove_link( $link );
		}
	}

	/**
	 * Intercept 404 requests and emit a 410 response for known obsolete URLs.
	 *
	 * Logs unknown 404s when logging is enabled.
	 *
	 * @return void
	 */
	public function check_for_410() {
		// Don't mess if WordPress has found something to display.
		if ( ! is_404() ) {
			return;
		}

		$links = $this->get_links();

		// Sanitize server variables.
		$http_host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		$req = ( is_ssl() ? 'https://' : 'http://' ) . $http_host . $request_uri;
		$req = rawurldecode( $req );

		foreach ( $links as $link ) {
			$match_result = preg_match( $link->gone_regex, $req );

			if ( false === $match_result ) {
				// Invalid regex – skip this pattern rather than breaking the request.
				continue;
			}

			if ( 1 === $match_result ) {
				define( 'DONOTCACHEPAGE', true );
				status_header( 410 );

				/**
				 * Fires when a 410 response is about to be sent.
				 *
				 * @since 1.0.0
				 */
				do_action( 'mclv_410_response' );

				/**
				 * Fires when a 410 response is about to be sent.
				 *
				 * @since 0.4
				 * @deprecated 1.0.0 Use 'mclv_410_response' instead.
				 */
				do_action_deprecated( 'wp_410_response', array(), '1.0.0', 'mclv_410_response' );

				if ( ! locate_template( '410.php', true ) ) {
					echo 'Sorry, the page you requested has been permanently removed.';
				}

				exit;
			}
		}

		// no hit, log 404.
		$this->add_link( $req, true );
	}
}

// Bootstrap the plugin.
new MCLV_410_Plugin();
