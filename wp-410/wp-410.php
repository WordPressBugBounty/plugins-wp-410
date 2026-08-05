<?php
/**
 * Plugin Name:       HTTP 410 (Gone) responses
 * Plugin URI:        https://wordpress.org/plugins/wp-410/
 * Description:       Sends HTTP 410 (Gone) responses to requests for pages that no longer exist on your blog.
 * Version:           1.2.1
 * Requires at least: 5.0
 * Requires PHP:      7.4
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
	 * Maximum number of primary keys to include in a single bulk UPDATE/DELETE query.
	 *
	 * Keeps generated SQL and prepared-statement placeholder counts within safe limits
	 * when a very large number of rows is selected at once.
	 *
	 * @var int
	 */
	const BULK_CHUNK_SIZE = 200;

	/**
	 * Maximum number of URLs accepted in a single manual "add URLs" submission.
	 *
	 * The manual-add textarea is a single POST field, so it cannot trigger the
	 * max_input_vars failure that affects checkbox lists, but each line still
	 * runs its own duplicate-check and insert query in a loop. A very large
	 * paste could still exhaust the request's execution time limit, so the
	 * submission is rejected up front rather than processed partway.
	 *
	 * @var int
	 */
	const MAX_MANUAL_URLS = 500;

	/**
	 * Nonce action/name shared by all settings-page forms.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'mclv-410-settings';

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
	 * Used on the front end, where every stored pattern must be checked against
	 * the current request, so it intentionally does not paginate.
	 *
	 * @return object[] Array of link rows keyed by gone_key.
	 */
	private function get_links() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, data changes frequently, caching would show stale results.
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT gone_id, gone_key, gone_regex FROM {$this->table} WHERE is_404 = %d",
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
	 * Run a paginated SELECT against the plugin's table.
	 *
	 * Shared by get_links_page() and get_404s_page(): both need the same
	 * count -> clamp-page -> LIMIT/OFFSET shape, differing only in their WHERE
	 * and ORDER BY clauses.
	 *
	 * @param string $where_sql  SQL WHERE clause (without the WHERE keyword), using %d/%s placeholders.
	 * @param array  $where_args Values for the WHERE clause placeholders, in order.
	 * @param string $order_sql  SQL ORDER BY clause (without the ORDER BY keyword).
	 * @param int    $page       Requested 1-based page number.
	 * @param int    $per_page   Number of rows per page.
	 * @return object Object with `items`, `total`, `page`, `per_page`, `total_pages`.
	 */
	private function paginate_query( $where_sql, array $where_args, $order_sql, $page, $per_page ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, count needed for pagination.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql/$where_args are built internally by callers (not from user input); the placeholder count varies by caller and always matches $where_args.
				"SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}",
				$where_args
			)
		);

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( max( 1, absint( $page ) ), $total_pages );
		$offset      = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, paginated listing fetched directly with LIMIT/OFFSET.
		$items = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholder count varies by caller ($where_sql) and always matches $where_args plus the two LIMIT/OFFSET values.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where_sql/$order_sql are built internally by callers, not from user input.
				"SELECT gone_id, gone_key, gone_regex FROM {$this->table} WHERE {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d",
				array_merge( $where_args, array( $per_page, $offset ) )
			)
		);

		return (object) array(
			'items'       => $items,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Fetch a single page of non-404 links (regular URLs or wildcard patterns).
	 *
	 * Uses LIMIT/OFFSET so the admin page never has to load the full list into
	 * memory merely to render or submit one page of rows.
	 *
	 * @param bool $wildcard Whether to fetch wildcard patterns (true) or plain URLs (false).
	 * @param int  $page     Requested 1-based page number.
	 * @param int  $per_page Number of rows per page.
	 * @return object Object with `items`, `total`, `page`, `per_page`, `total_pages`.
	 */
	private function get_links_page( $wildcard, $page, $per_page ) {
		// $like_op is a fixed internal string ('' or 'NOT'), never derived from user input.
		$like_op = $wildcard ? '' : 'NOT';

		return $this->paginate_query( "is_404 = %d AND gone_key {$like_op} LIKE %s", array( 0, '%*%' ), 'gone_key ASC', $page, $per_page );
	}

	/**
	 * Fetch a single page of logged 404 entries, most recent first.
	 *
	 * @param int $page     Requested 1-based page number.
	 * @param int $per_page Number of rows per page.
	 * @return object Object with `items`, `total`, `page`, `per_page`, `total_pages`.
	 */
	private function get_404s_page( $page, $per_page ) {
		$this->concat_404_list();

		return $this->paginate_query( 'is_404 = %d', array( 1 ), 'gone_id DESC', $page, $per_page );
	}

	/**
	 * Number of rows to display per page on the admin settings screen.
	 *
	 * @return int
	 */
	private function per_page() {
		/**
		 * Filters the number of rows shown per page on the plugin's admin lists.
		 *
		 * @since 1.2.0
		 *
		 * @param int $per_page Rows per page. Default 100.
		 */
		$per_page = apply_filters( 'mclv_410_admin_per_page', 100 );

		return max( 1, absint( $per_page ) );
	}

	/**
	 * Read and sanitise a pagination query argument.
	 *
	 * @param string $key Query string key to read.
	 * @return int Sanitised, 1-or-greater page number.
	 */
	private function get_current_page( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter, does not change state.
		$page = isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 1;

		return $page > 0 ? $page : 1;
	}

	/**
	 * Insert a new 410 or logged 404 entry and store its regex matcher.
	 *
	 * Skips when 404 logging is disabled or the key already exists.
	 *
	 * @param string $key    Fully qualified URL (supports * wildcards).
	 * @param bool   $is_404 Whether this entry represents a logged 404 hit.
	 * @return int|false Number of rows inserted (1), 0 if the key already exists or
	 *                    logging is disabled, or false on a database error.
	 */
	private function add_link( $key, $is_404 = false ) {
		// just supply the link.
		global $wpdb;

		// 404 logging enabled?
		if ( $is_404 && 0 === $this->max_404_list_length() ) {
			return 0;
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
		$inserted = $wpdb->insert(
			$this->table,
			array(
				'gone_key'   => $key,
				'gone_regex' => $regex,
				'is_404'     => intval( $is_404 ),
			)
		);

		if ( false === $inserted ) {
			$this->log_db_error( 'add_link' );
		}

		// Don't let 404 list grow forever.
		if ( $is_404 ) {
			$this->concat_404_list();
		}

		return $inserted;
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
	 * Retained for single-record use; bulk operations use bulk_promote_404_ids()
	 * instead of calling this in a loop.
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
	 * Retained for single-record use; bulk operations use bulk_delete_ids()
	 * instead of calling this in a loop.
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
	 * Promote a batch of logged 404 entries (by primary key) to 410 entries.
	 *
	 * IDs are split into chunks of self::BULK_CHUNK_SIZE and processed with a
	 * single `UPDATE ... WHERE gone_id IN (...)` query per chunk, rather than one
	 * query per row. Only rows that are currently logged 404s (is_404 = 1) are
	 * affected, so IDs belonging to existing 410 entries are silently ignored.
	 *
	 * @param int[] $ids Sanitised, non-zero, de-duplicated primary keys.
	 * @return array{requested:int,updated:int} Number of IDs requested and rows actually updated.
	 */
	private function bulk_promote_404_ids( array $ids ) {
		global $wpdb;

		$result = array(
			'requested' => count( $ids ),
			'updated'   => 0,
		);

		foreach ( array_chunk( $ids, self::BULK_CHUNK_SIZE ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, bulk update restricted to primary keys.
			$updated = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders contains only %d tokens generated from count( $chunk ); values are bound via prepare() below.
					"UPDATE {$this->table} SET is_404 = 0 WHERE is_404 = 1 AND gone_id IN ( {$placeholders} )",
					$chunk
				)
			);

			if ( false === $updated ) {
				$this->log_db_error( 'bulk_promote_404_ids' );
				continue;
			}

			$result['updated'] += $updated;
		}

		return $result;
	}

	/**
	 * Delete a batch of links (by primary key), optionally restricted to
	 * wildcard or non-wildcard entries.
	 *
	 * IDs are split into chunks of self::BULK_CHUNK_SIZE and processed with a
	 * single `DELETE ... WHERE gone_id IN (...)` query per chunk. Only rows with
	 * is_404 = 0 (i.e. entries shown in the Obsolete URLs / Wildcard Patterns
	 * tables, not the logged-404 table) are ever affected.
	 *
	 * @param int[]     $ids      Sanitised, non-zero, de-duplicated primary keys.
	 * @param bool|null $wildcard True to restrict to wildcard patterns, false to restrict
	 *                            to plain URLs, null for no additional restriction.
	 * @return array{requested:int,deleted:int} Number of IDs requested and rows actually deleted.
	 */
	private function bulk_delete_ids( array $ids, $wildcard = null ) {
		global $wpdb;

		$result = array(
			'requested' => count( $ids ),
			'deleted'   => 0,
		);

		$like_clause = '';
		$like_args   = array();

		if ( null !== $wildcard ) {
			$like_clause = $wildcard ? ' AND gone_key LIKE %s' : ' AND gone_key NOT LIKE %s';
			$like_args   = array( '%*%' );
		}

		foreach ( array_chunk( $ids, self::BULK_CHUNK_SIZE ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$args         = array_merge( $chunk, $like_args );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, bulk delete restricted to primary keys.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders/$like_clause contain only fixed tokens generated internally; values are bound via prepare() below.
					"DELETE FROM {$this->table} WHERE is_404 = 0 AND gone_id IN ( {$placeholders} ){$like_clause}",
					$args
				)
			);

			if ( false === $deleted ) {
				$this->log_db_error( 'bulk_delete_ids' );
				continue;
			}

			$result['deleted'] += $deleted;
		}

		return $result;
	}

	/**
	 * Sanitise a submitted list of primary keys.
	 *
	 * Accepts only arrays (a scalar submitted where an array was expected is
	 * treated as "nothing submitted" rather than causing a fatal error), casts
	 * every value with absint(), and discards zero and duplicate values.
	 *
	 * @param mixed $raw Raw value from $_POST, already unslashed.
	 * @return int[] List of unique, positive integer IDs.
	 */
	private function sanitize_id_list( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();

		foreach ( $raw as $value ) {
			$id = absint( $value );
			if ( $id > 0 ) {
				$ids[ $id ] = $id; // Keyed by ID to de-duplicate.
			}
		}

		return array_values( $ids );
	}

	/**
	 * Log a database error for site-owner debugging, without exposing it to the browser.
	 *
	 * @param string $context Short label identifying which operation failed.
	 * @return void
	 */
	private function log_db_error( $context ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			global $wpdb;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only triggered when WP_DEBUG is enabled, for site-owner diagnostics.
			error_log( sprintf( '[HTTP 410] %1$s failed: %2$s', $context, $wpdb->last_error ) );
		}
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
	 * obsolete URLs, recent 404s, and other plugin configuration options. Form
	 * submissions are processed on the page's `load-{hook}` action, which runs
	 * before any admin HTML has been output, so the handler can safely redirect.
	 *
	 * @return void
	 */
	public function settings_menu() {
		$hook_suffix = add_submenu_page( 'plugins.php', 'HTTP 410 (Gone) responses', 'HTTP 410 (Gone) responses', 'manage_options', 'mclv_410_settings', array( $this, 'settings_page' ) );

		if ( $hook_suffix ) {
			add_action( 'load-' . $hook_suffix, array( $this, 'handle_settings_form_submissions' ) );
		}
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
			'1.2.1'
		);

		// Enqueue admin JavaScript.
		wp_enqueue_script(
			'mclv-410-admin',
			plugin_dir_url( __FILE__ ) . 'js/admin.js',
			array(),
			'1.2.1',
			array( 'in_footer' => true )
		);
	}

	/**
	 * Render the plugin settings page.
	 *
	 * Form submissions are handled earlier, on the `load-{hook}` action (see
	 * settings_menu()), so this method only has to gather paginated data for
	 * display and load the view template.
	 *
	 * @return void
	 */
	public function settings_page() {
		$notice   = $this->consume_notice();
		$per_page = $this->per_page();

		$regular_result  = $this->get_links_page( false, $this->get_current_page( 'mclv_410_page' ), $per_page );
		$wildcard_result = $this->get_links_page( true, $this->get_current_page( 'mclv_wild_page' ), $per_page );
		$logged_result   = $this->get_404s_page( $this->get_current_page( 'mclv_404_page' ), $per_page );

		// Prepare variables for the view.
		$max_404_length   = $this->max_404_list_length();
		$has_410_template = (bool) locate_template( '410.php' );
		$cache_notice     = $this->get_cache_notice();
		$plugin           = $this;

		// Load the view template.
		include plugin_dir_path( __FILE__ ) . 'views/admin-settings.php';
	}

	/**
	 * Handle settings-page form submissions.
	 *
	 * Runs on `load-{hook}`, before any admin HTML is output, so it is free to
	 * redirect. Every operation is identified by an explicit `mclv_410_action`
	 * hidden field (checked against an allow-list) rather than by which submit
	 * button was clicked, and both the action field and the nonce are emitted
	 * before any variable-length checkbox list in the form markup. This avoids
	 * the original failure mode, where a very large selection could push the
	 * nonce/action fields past PHP's max_input_vars limit, causing them to be
	 * silently dropped and the request to be misread as "nothing submitted".
	 *
	 * @return void
	 */
	public function handle_settings_form_submissions() {
		if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		$redirect_args = $this->collect_redirect_page_args();

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->set_notice( 'error', __( 'You do not have permission to perform this action.', 'wp-410' ) );
			$this->redirect_to_settings( $redirect_args );
		}

		$action          = isset( $_POST['mclv_410_action'] ) ? sanitize_key( wp_unslash( $_POST['mclv_410_action'] ) ) : '';
		$allowed_actions = array( 'promote_404s', 'delete_regular', 'delete_wildcard', 'add_manual_urls', 'set_max_404s' );

		if ( '' === $action || ! in_array( $action, $allowed_actions, true ) ) {
			// Nothing recognised was submitted (e.g. a plain page load) - nothing to do.
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->set_notice( 'error', __( 'The submitted request was incomplete or has expired. Please try a smaller selection, or reload the page and try again.', 'wp-410' ) );
			$this->redirect_to_settings( $redirect_args );
		}

		switch ( $action ) {
			case 'promote_404s':
				$this->handle_promote_404s();
				break;
			case 'delete_regular':
				$this->handle_delete_links( false );
				break;
			case 'delete_wildcard':
				$this->handle_delete_links( true );
				break;
			case 'add_manual_urls':
				$this->handle_add_manual_urls();
				break;
			case 'set_max_404s':
				$this->handle_set_max_404s();
				break;
		}

		$this->redirect_to_settings( $redirect_args );
	}

	/**
	 * Promote selected logged-404 entries (submitted as IDs) to 410 entries.
	 *
	 * @return void
	 */
	private function handle_promote_404s() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce already verified in handle_settings_form_submissions() before dispatch; value sanitised immediately below via sanitize_id_list().
		$raw = isset( $_POST['add_404s'] ) ? wp_unslash( $_POST['add_404s'] ) : array();
		$ids = $this->sanitize_id_list( $raw );

		if ( empty( $ids ) ) {
			$this->set_notice( 'error', __( 'No entries were selected.', 'wp-410' ) );
			return;
		}

		$result          = $this->bulk_promote_404_ids( $ids );
		$success_message = sprintf(
			/* translators: %s: number of entries. */
			_n( '%s logged 404 entry was added to the 410 list.', '%s logged 404 entries were added to the 410 list.', $result['updated'], 'wp-410' ),
			number_format_i18n( $result['updated'] )
		);

		list( $type, $message ) = $this->notice_for_bulk_result( $result, 'updated', $success_message );

		$this->set_notice( $type, $message );
	}

	/**
	 * Delete selected regular or wildcard 410 entries (submitted as IDs).
	 *
	 * @param bool $wildcard True to operate on wildcard patterns, false for plain URLs.
	 * @return void
	 */
	private function handle_delete_links( $wildcard ) {
		$field = $wildcard ? 'wildcard_links_to_remove' : 'regular_links_to_remove';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce already verified in handle_settings_form_submissions() before dispatch; value sanitised immediately below via sanitize_id_list().
		$raw = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : array();
		$ids = $this->sanitize_id_list( $raw );

		if ( empty( $ids ) ) {
			$this->set_notice( 'error', __( 'No entries were selected.', 'wp-410' ) );
			return;
		}

		$result          = $this->bulk_delete_ids( $ids, $wildcard );
		$success_message = sprintf(
			/* translators: %s: number of entries. */
			_n( '%s entry was deleted.', '%s entries were deleted.', $result['deleted'], 'wp-410' ),
			number_format_i18n( $result['deleted'] )
		);

		list( $type, $message ) = $this->notice_for_bulk_result( $result, 'deleted', $success_message );

		$this->set_notice( $type, $message );
	}

	/**
	 * Build a (type, message) pair describing the outcome of a bulk operation.
	 *
	 * @param array  $result          Result array containing 'requested' and either 'updated' or 'deleted'.
	 * @param string $count_key       Which key in $result holds the affected-row count ('updated' or 'deleted').
	 * @param string $success_message Pre-built, already-translated message to use when every requested row was affected.
	 * @return array{0:string,1:string} Notice type ('success'|'warning'|'error') and message.
	 */
	private function notice_for_bulk_result( array $result, $count_key, $success_message ) {
		$requested = $result['requested'];
		$affected  = isset( $result[ $count_key ] ) ? $result[ $count_key ] : 0;
		$skipped   = $requested - $affected;

		if ( $affected === $requested ) {
			return array( 'success', $success_message );
		}

		if ( 0 === $affected ) {
			return array( 'error', __( 'None of the selected entries could be processed. They may already have been changed or removed by another request.', 'wp-410' ) );
		}

		return array(
			'warning',
			sprintf(
				/* translators: 1: number processed, 2: number skipped. */
				__( '%1$s entries were updated, but %2$s could not be processed. They may already have been changed or removed by another request.', 'wp-410' ),
				number_format_i18n( $affected ),
				number_format_i18n( $skipped )
			),
		);
	}

	/**
	 * Process the manual "add URLs" textarea, kept separate from logged-404 promotion.
	 *
	 * @return void
	 */
	private function handle_add_manual_urls() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce already verified in handle_settings_form_submissions() before dispatch.
		$raw = isset( $_POST['links_to_add'] ) ? sanitize_textarea_field( wp_unslash( $_POST['links_to_add'] ) ) : '';
		$raw = trim( $raw );

		if ( '' === $raw ) {
			$this->set_notice( 'error', __( 'No URLs were entered.', 'wp-410' ) );
			return;
		}

		$lines = preg_split( '/(\r?\n)+/', $raw, -1, PREG_SPLIT_NO_EMPTY );

		if ( count( $lines ) > self::MAX_MANUAL_URLS ) {
			$this->set_notice(
				'error',
				sprintf(
					/* translators: 1: number of URLs submitted, 2: maximum allowed per submission. */
					esc_html__( 'You submitted %1$s URLs, but only %2$s can be added at a time. Please split your list into smaller batches and try again.', 'wp-410' ),
					number_format_i18n( count( $lines ) ),
					number_format_i18n( self::MAX_MANUAL_URLS )
				)
			);
			return;
		}

		$added      = 0;
		$duplicates = 0;
		$failed     = 0;
		$invalid    = array();

		foreach ( $lines as $link ) {
			$link = sanitize_text_field( $link );

			if ( '' === $link ) {
				continue;
			}

			if ( ! $this->is_valid_url( $link ) ) {
				$invalid[] = $link;
				continue;
			}

			$result = $this->add_link( $link );

			if ( false === $result ) {
				++$failed;
			} elseif ( 0 === $result ) {
				++$duplicates;
			} else {
				++$added;
			}
		}

		$parts = array();

		if ( $added > 0 ) {
			/* translators: %s: number of URLs. */
			$parts[] = sprintf( _n( '%s URL was added to the 410 list.', '%s URLs were added to the 410 list.', $added, 'wp-410' ), number_format_i18n( $added ) );
		}

		if ( $duplicates > 0 ) {
			/* translators: %s: number of URLs. */
			$parts[] = sprintf( _n( '%s URL was already on the list.', '%s URLs were already on the list.', $duplicates, 'wp-410' ), number_format_i18n( $duplicates ) );
		}

		if ( $failed > 0 ) {
			/* translators: %s: number of URLs. */
			$parts[] = sprintf( _n( '%s URL could not be saved due to a database error.', '%s URLs could not be saved due to a database error.', $failed, 'wp-410' ), number_format_i18n( $failed ) );
		}

		$type = 'success';
		if ( $failed > 0 || ! empty( $invalid ) ) {
			$type = $added > 0 ? 'warning' : 'error';
		}

		if ( empty( $parts ) ) {
			$parts[] = __( 'No valid URLs were found to add.', 'wp-410' );
			$type    = 'error';
		}

		$message = implode( ' ', array_map( 'esc_html', $parts ) );

		if ( ! empty( $invalid ) ) {
			$message .= ' ' . esc_html__( 'The following entries could not be recognised as URLs that your WordPress site handles, and were not added. This can be because the domain name and path does not match that of your WordPress site, or because pretty permalinks are disabled.', 'wp-410' );
			$message .= ' <code>' . implode( '</code>, <code>', array_map( 'esc_html', $invalid ) ) . '</code>';
		}

		$this->set_notice( $type, $message );
	}

	/**
	 * Update the maximum-404s-to-keep option.
	 *
	 * @return void
	 */
	private function handle_set_max_404s() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce already verified in handle_settings_form_submissions() before dispatch.
		$max = isset( $_POST['max_404_list_length'] ) ? absint( wp_unslash( $_POST['max_404_list_length'] ) ) : 50;
		$max = min( $max, 10000 );
		update_option( 'mclv_410_max_404s', $max );

		$this->set_notice(
			'success',
			sprintf(
				/* translators: %s: maximum number of logged 404 entries. */
				esc_html__( 'The maximum number of logged 404 entries was set to %s.', 'wp-410' ),
				number_format_i18n( $max )
			)
		);
	}

	/**
	 * Collect the current per-section page numbers from POST, for use as
	 * redirect query args so the user lands back on the page they were on.
	 *
	 * @return array<string,int>
	 */
	private function collect_redirect_page_args() {
		$args = array();

		foreach ( array( 'mclv_410_page', 'mclv_wild_page', 'mclv_404_page' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only pagination bookkeeping used only to build a redirect URL, does not change state.
			$page = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;
			if ( $page > 1 ) {
				$args[ $key ] = $page;
			}
		}

		return $args;
	}

	/**
	 * Redirect back to the plugin's own settings page and stop execution.
	 *
	 * Implements POST/Redirect/GET so that reloading the settings page after a
	 * successful operation does not resubmit and repeat it.
	 *
	 * @param array<string,int> $args Extra query args (e.g. pagination) to preserve.
	 * @return void
	 */
	private function redirect_to_settings( array $args = array() ) {
		$url = add_query_arg( array_merge( array( 'page' => 'mclv_410_settings' ), $args ), admin_url( 'plugins.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Store a one-time admin notice for the current user, to be displayed after redirect.
	 *
	 * @param string $type    One of 'success', 'warning', 'error'.
	 * @param string $message Notice message. May contain a small safe HTML subset (e.g. <code>).
	 * @return void
	 */
	private function set_notice( $type, $message ) {
		set_transient(
			$this->notice_transient_key(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Retrieve and clear the current user's pending admin notice, if any.
	 *
	 * @return array{type:string,message:string}|null
	 */
	private function consume_notice() {
		$key    = $this->notice_transient_key();
		$notice = get_transient( $key );

		if ( false !== $notice ) {
			delete_transient( $key );
			return $notice;
		}

		return null;
	}

	/**
	 * Build the per-user transient key used to pass a one-time notice across a redirect.
	 *
	 * @return string
	 */
	private function notice_transient_key() {
		return 'mclv_410_notice_' . get_current_user_id();
	}

	/**
	 * Determine what, if anything, to tell the admin about page caching.
	 *
	 * Only ever reports that page caching is enabled (from WP_CACHE) - it does
	 * not claim to have detected an unsupported plugin, since WP_CACHE alone
	 * cannot distinguish between W3 Total Cache, WP Super Cache, another plugin,
	 * or host-level caching.
	 *
	 * @return array{detected:string[],has_drop_in:bool}|null Null when WP_CACHE is not enabled.
	 */
	private function get_cache_notice() {
		if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
			return null;
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$known_plugins  = array(
			'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
			'wp-super-cache/wp-cache.php'       => 'WP Super Cache',
		);

		$detected = array();
		foreach ( $known_plugins as $file => $name ) {
			if ( in_array( $file, $active_plugins, true ) ) {
				$detected[] = $name;
			}
		}

		return array(
			'detected'    => $detected,
			'has_drop_in' => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
		);
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
	 * Render a paginated table of URLs for the admin interface.
	 *
	 * Checkbox `id`/`value` attributes use the row's `gone_id` primary key
	 * rather than the URL itself, avoiding both invalid/duplicate HTML IDs and
	 * (together with the pagination in get_links_page()/get_404s_page()) the
	 * unbounded request size that previously broke bulk submission on large lists.
	 *
	 * @param object[] $items         Row objects with gone_id/gone_key, e.g. from get_links_page().
	 * @param string   $table_id      HTML ID for the table.
	 * @param string   $checkbox_id   Prefix for checkbox IDs.
	 * @param string   $select_all_id ID for the select-all checkbox.
	 * @param string   $checkbox_name Name attribute for checkboxes (default: 'old_links_to_remove[]').
	 * @return bool Whether any invalid URLs were found.
	 */
	public function render_url_table( $items, $table_id, $checkbox_id, $select_all_id, $checkbox_name = 'old_links_to_remove[]' ) {
		$invalid_links_exist = false;

		echo '<div class="mclv-410-table-wrap"><table id="' . esc_attr( $table_id ) . '" class="wp-list-table widefat fixed">';
		echo '<thead><th class="check-column"><input type="checkbox" id="' . esc_attr( $select_all_id ) . '" /><label for="' . esc_attr( $select_all_id ) . '" class="screen-reader-text"> Select all</label></th><th>URL</th></thead>';
		echo '<tbody>';

		foreach ( $items as $item ) {
			$valid = $this->is_valid_url( $item->gone_key );

			if ( ! $valid ) {
				$invalid_links_exist = true;
			}

			$id_attr = absint( $item->gone_id );
			$class   = $valid ? '' : ' class="invalid"';

			$row_html  = '<tr' . $class . '>';
			$row_html .= '<td><input type="checkbox" name="' . esc_attr( $checkbox_name ) . '" id="' . esc_attr( $checkbox_id ) . '-' . $id_attr . '" value="' . $id_attr . '" /></td>';
			$row_html .= '<td><label for="' . esc_attr( $checkbox_id ) . '-' . $id_attr . '"><code>' . esc_html( $item->gone_key ) . '</code></label></td>';
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
	 * Render simple previous/next pagination controls for a paginated result.
	 *
	 * Deliberately avoids rendering one link per page (which would not scale to
	 * very large lists); works without JavaScript since it is plain links.
	 *
	 * @param object $result     Result object from get_links_page()/get_404s_page().
	 * @param string $page_param Query string key to set for the target page (e.g. 'mclv_410_page').
	 * @return void
	 */
	public function render_pagination( $result, $page_param ) {
		if ( $result->total_pages <= 1 ) {
			return;
		}

		$base_url = remove_query_arg( array( 'mclv_410_page', 'mclv_wild_page', 'mclv_404_page' ) );

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo '<span class="displaying-num">' . esc_html(
			sprintf(
				/* translators: 1: current page, 2: total pages, 3: total items. */
				__( 'Page %1$s of %2$s (%3$s items)', 'wp-410' ),
				number_format_i18n( $result->page ),
				number_format_i18n( $result->total_pages ),
				number_format_i18n( $result->total )
			)
		) . '</span> ';

		if ( $result->page > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( $page_param, $result->page - 1, $base_url ) ) . '">&laquo; ' . esc_html__( 'Previous', 'wp-410' ) . '</a> ';
		}

		if ( $result->page < $result->total_pages ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( $page_param, $result->page + 1, $base_url ) ) . '">' . esc_html__( 'Next', 'wp-410' ) . ' &raquo;</a>';
		}

		echo '</div></div>';
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
