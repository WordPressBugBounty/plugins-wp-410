<?php
/**
 * Admin settings page template.
 *
 * @package MCLV_410_Plugin
 *
 * @var array|null  $notice           One-time notice: array( 'type' => ..., 'message' => ... ), or null.
 * @var object      $regular_result   Paginated regular URLs, from get_links_page( false, ... ).
 * @var object      $wildcard_result  Paginated wildcard patterns, from get_links_page( true, ... ).
 * @var object      $logged_result    Paginated logged 404 entries, from get_404s_page( ... ).
 * @var int         $max_404_length   Maximum number of 404s to keep.
 * @var bool        $has_410_template Whether a 410.php template exists in the theme.
 * @var array|null  $cache_notice     Cache-notice info from get_cache_notice(), or null.
 * @var object      $plugin           Reference to the plugin instance for helper methods.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h2>HTTP 410 (Gone) responses</h2>

	<?php if ( $notice ) : ?>
		<?php $mclv_410_notice_class = in_array( $notice['type'], array( 'error', 'warning' ), true ) ? $notice['type'] : 'success'; ?>
		<div id="message" class="notice notice-<?php echo esc_attr( $mclv_410_notice_class ); ?> is-dismissible">
			<p><?php echo wp_kses_post( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $cache_notice ) : ?>
	<div class="notice notice-info">
		<?php if ( ! empty( $cache_notice['detected'] ) ) : ?>
			<p><strong>Page caching is enabled</strong> (<?php echo esc_html( implode( ', ', $cache_notice['detected'] ) ); ?> detected). This is a caching plugin this plugin has been tested with, so no action should be required.</p>
		<?php else : ?>
			<p><strong>Page caching is enabled</strong> on this site (<code>WP_CACHE</code> is set to <code>true</code>). This is expected and fine if you are using W3 Total Cache or WP Super Cache.</p>
			<p>If you are using a different caching system - including another caching plugin, host-level caching, or a CDN - it may need to be configured to bypass the cache for pages that should return a 410 response, otherwise a stale cached response could be served instead.</p>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<p>This plugin will issue a HTTP 410 response to articles that no longer exist on your blog. This informs robots that the requested page has been permanently removed, and that they should stop trying to access it.</p>
	<p><strong>A 410 response will only be issued if WordPress has not found something valid to display for the requested URL.</strong></p>

	<!-- Obsolete URLs Section -->
	<h3>Obsolete URLs</h3>
	<?php if ( 0 === $regular_result->total && 0 === $wildcard_result->total ) : ?>
		<p>There are currently no obsolete URLs in this list. You can add some manually below.</p>
	<?php elseif ( 0 === $regular_result->total ) : ?>
		<p>There are no specific URLs in this list. You can add some manually below, or they will be logged from 404 errors.</p>
	<?php else : ?>
		<form action="" method="post">
			<?php wp_nonce_field( MCLV_410_Plugin::NONCE_ACTION ); ?>
			<input type="hidden" name="mclv_410_action" value="delete_regular" />
			<input type="hidden" name="mclv_410_page" value="<?php echo esc_attr( $regular_result->page ); ?>" />
			<p>The following specific URLs will receive a 410 response. If you create or update an article whose URL matches one below, it will automatically be removed from the list.</p>
			<?php
			$mclv_410_regular_invalid = $plugin->render_url_table( $regular_result->items, 'mclv_gone_old_links', 'mclv-410', 'select-all-410', 'regular_links_to_remove[]' );
			if ( $mclv_410_regular_invalid ) {
				echo '<p class="invalid">Warning: WordPress is not able to issue 410 responses for the URLs marked in red above. This is because those URLs are not handled by your WordPress installation. This can be because the domain name and path does not match that of your WordPress site, or because pretty permalinks are disabled.</p>';
			}
			$plugin->render_pagination( $regular_result, 'mclv_410_page' );
			?>
			<p class="submit">
				<input class="button button-primary" type="submit" value="Delete selected URLs" />
			</p>
		</form>
	<?php endif; ?>

	<?php if ( $wildcard_result->total > 0 ) : ?>
	<!-- Wildcard Patterns Section -->
	<h3>⚠️ Wildcard Patterns</h3>
	<div class="mclv-410-wildcard-notice">
		<p><strong>These patterns use wildcards (<code>*</code>) and can match multiple URLs.</strong> Use with caution as they have a broader impact than specific URLs.</p>
	</div>
	<form action="" method="post">
		<?php wp_nonce_field( MCLV_410_Plugin::NONCE_ACTION ); ?>
		<input type="hidden" name="mclv_410_action" value="delete_wildcard" />
		<input type="hidden" name="mclv_wild_page" value="<?php echo esc_attr( $wildcard_result->page ); ?>" />
		<?php
		$mclv_410_wildcard_invalid = $plugin->render_url_table( $wildcard_result->items, 'mclv_gone_wildcards', 'mclv-wildcard', 'select-all-wildcards', 'wildcard_links_to_remove[]' );
		if ( $mclv_410_wildcard_invalid ) {
			echo '<p class="invalid">Warning: Some wildcard patterns marked in red are not valid for your WordPress installation.</p>';
		}
		$plugin->render_pagination( $wildcard_result, 'mclv_wild_page' );
		?>
		<p class="submit">
			<input class="button button-primary" type="submit" value="Delete selected wildcards" />
		</p>
	</form>
	<?php endif; ?>

	<!-- Recent 404 Errors Section -->
	<h3>Recent 404 errors</h3>
	<p>Recent 404 (Page Not Found) errors on your site are shown here, so that you can easily add them to the list above.</p>

	<form action="" method="post">
		<?php wp_nonce_field( MCLV_410_Plugin::NONCE_ACTION ); ?>
		<input type="hidden" name="mclv_410_action" value="set_max_404s" />
		<p>
			<label>Maximum number of 404 errors to keep:
				<input type="number" size="3" min="0" max="10000" name="max_404_list_length" value="<?php echo esc_attr( $max_404_length ); ?>" />
			</label>
			<input class="button button-secondary" type="submit" value="Save" />
			(setting this to zero will disable logging).
		</p>
	</form>

	<?php if ( 0 === $logged_result->total ) : ?>
		<?php if ( $max_404_length > 0 ) : ?>
			<p>There are currently no 404 errors reported.</p>
		<?php endif; ?>
	<?php else : ?>
		<form action="" method="post">
			<?php wp_nonce_field( MCLV_410_Plugin::NONCE_ACTION ); ?>
			<input type="hidden" name="mclv_410_action" value="promote_404s" />
			<input type="hidden" name="mclv_404_page" value="<?php echo esc_attr( $logged_result->page ); ?>" />
			<p>Below are recent 404 (Page Not Found) errors that have occurred on your site. You can add these to the list of obsolete URLs.</p>
			<div class="mclv-410-table-wrap">
				<table id="mclv_gone_404s" class="wp-list-table widefat fixed">
					<thead>
						<th class="check-column">
							<input type="checkbox" id="select-all-404" />
							<label for="select-all-404" class="screen-reader-text">Select all</label>
						</th>
						<th>URL</th>
					</thead>
					<tbody>
						<?php foreach ( $logged_result->items as $mclv_410_row ) : ?>
							<tr>
								<td>
									<input type="checkbox" name="add_404s[]" id="mclv-404-<?php echo esc_attr( $mclv_410_row->gone_id ); ?>" value="<?php echo esc_attr( $mclv_410_row->gone_id ); ?>" />
								</td>
								<td>
									<label for="mclv-404-<?php echo esc_attr( $mclv_410_row->gone_id ); ?>"><code><?php echo esc_html( $mclv_410_row->gone_key ); ?></code></label>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php $plugin->render_pagination( $logged_result, 'mclv_404_page' ); ?>
			<p class="submit">
				<input class="button button-primary" type="submit" value="Add selected entries to 410 list" />
			</p>
		</form>
	<?php endif; ?>

	<!-- Manually Add URLs Section -->
	<h3>Manually add URLs</h3>
	<form action="" method="post">
		<?php wp_nonce_field( MCLV_410_Plugin::NONCE_ACTION ); ?>
		<input type="hidden" name="mclv_410_action" value="add_manual_urls" />
		<p>You can manually add items to the list by entering them below. Please enter one <strong>fully qualified</strong> URL per line.</p>
		<p>Use <code>*</code> as a wildcard character. So <code>http://www.example.com/*/music/</code> will match all URLs ending in <code>/music/</code>.</p>
		<p>You can add up to <?php echo esc_html( number_format_i18n( MCLV_410_Plugin::MAX_MANUAL_URLS ) ); ?> URLs per submission.</p>
		<textarea name="links_to_add" rows="8" cols="80"></textarea>
		<p class="submit">
			<input class="button button-primary" type="submit" value="Add entries to 410 list" />
		</p>
	</form>

	<!-- 410 Response Message Section -->
	<h3>410 response message</h3>
	<p>By default, the plugin issues the following plain-text message as part of the 410 response: <code>Sorry, the page you requested has been permanently removed.</code></p>
	<?php if ( $has_410_template ) : ?>
		<p><strong>A template file <code>410.php</code> has been detected in your theme directory. This file will be used to display 410 responses.</strong> To revert back to the default message, remove the file from your theme directory.</p>
	<?php else : ?>
		<p>If you would like to use your own template instead, simply place a file called <code>410.php</code> in your theme directory, containing your template. Have a look at your theme's <code>404.php</code> template to see what it should look like.</p>
	<?php endif; ?>
</div>
