<?php
/**
 * Admin settings page template.
 *
 * @package MCLV_410_Plugin
 *
 * @var array  $regular_links   Regular (non-wildcard) 410 URLs.
 * @var array  $wildcard_links  Wildcard pattern 410 URLs.
 * @var array  $logged_404s     Recent 404 errors.
 * @var int    $max_404_length  Maximum number of 404s to keep.
 * @var bool   $has_410_template Whether a 410.php template exists in the theme.
 * @var object $plugin          Reference to the plugin instance for helper methods.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h2>HTTP 410 (Gone) responses</h2>

	<?php if ( WP_CACHE ) : ?>
	<div class="updated">
		<p><strong style="color: #900">Warning:</strong> It seems that a caching/performance plugin is active on this site. This plugin has only been tested with the following caching plugins:</p>
		<ul style="list-style: disc; margin-left: 2em">
			<li>W3 Total Cache</li>
			<li>WP Super Cache</li>
		</ul>
		<p><strong>Other caching plugins may cache responses to requests for pages that have been removed</strong>, in which case this plugin will not be able to intercept the requests and issue a 410 response header.</p>
	</div>
	<?php endif; ?>

	<p>This plugin will issue a HTTP 410 response to articles that no longer exist on your blog. This informs robots that the requested page has been permanently removed, and that they should stop trying to access it.</p>
	<p><strong>A 410 response will only be issued if WordPress has not found something valid to display for the requested URL.</strong></p>

	<!-- Obsolete URLs Section -->
	<h3>Obsolete URLs</h3>
	<?php if ( empty( $regular_links ) && empty( $wildcard_links ) ) : ?>
		<p>There are currently no obsolete URLs in this list. You can add some manually below.</p>
	<?php elseif ( empty( $regular_links ) ) : ?>
		<p>There are no specific URLs in this list. You can add some manually below, or they will be logged from 404 errors.</p>
	<?php else : ?>
		<form action="" method="post">
			<p>The following specific URLs will receive a 410 response. If you create or update an article whose URL matches one below, it will automatically be removed from the list.</p>
			<?php
			$mclv_410_regular_invalid = $plugin->render_url_table( $regular_links, 'mclv_gone_old_links', 'mclv-410', 'select-all-410', 'regular_links_to_remove[]' );
			if ( $mclv_410_regular_invalid ) {
				echo '<p class="invalid">Warning: WordPress is not able to issue 410 responses for the URLs marked in red above. This is because those URLs are not handled by your WordPress installation. This can be because the domain name and path does not match that of your WordPress site, or because pretty permalinks are disabled.</p>';
			}
			?>
			<?php wp_nonce_field( 'mclv-410-settings' ); ?>
			<p class="submit">
				<input class="button button-primary" type="submit" name="delete-regular-urls" value="Delete selected URLs" />
			</p>
		</form>
	<?php endif; ?>

	<?php if ( ! empty( $wildcard_links ) ) : ?>
	<!-- Wildcard Patterns Section -->
	<h3>⚠️ Wildcard Patterns</h3>
	<div class="mclv-410-wildcard-notice">
		<p><strong>These patterns use wildcards (<code>*</code>) and can match multiple URLs.</strong> Use with caution as they have a broader impact than specific URLs.</p>
	</div>
	<form action="" method="post">
		<?php
		$mclv_410_wildcard_invalid = $plugin->render_url_table( $wildcard_links, 'mclv_gone_wildcards', 'mclv-wildcard', 'select-all-wildcards', 'wildcard_links_to_remove[]' );
		if ( $mclv_410_wildcard_invalid ) {
			echo '<p class="invalid">Warning: Some wildcard patterns marked in red are not valid for your WordPress installation.</p>';
		}
		?>
		<?php wp_nonce_field( 'mclv-410-settings' ); ?>
		<p class="submit">
			<input class="button button-primary" type="submit" name="delete-wildcard-urls" value="Delete selected wildcards" />
		</p>
	</form>
	<?php endif; ?>

	<!-- Recent 404 Errors Section -->
	<h3>Recent 404 errors</h3>
	<p>Recent 404 (Page Not Found) errors on your site are shown here, so that you can easily add them to the list above.</p>

	<form action="" method="post">
		<p>
			<label>Maximum number of 404 errors to keep:
				<input type="number" size="3" name="max_404_list_length" value="<?php echo esc_attr( $max_404_length ); ?>" />
			</label>
			<input class="button button-secondary" type="submit" name="set-404-list-length" value="Save" />
			(setting this to zero will disable logging).
		</p>
		<?php wp_nonce_field( 'mclv-410-settings' ); ?>
	</form>

	<form action="" method="post">
		<?php if ( empty( $logged_404s ) ) : ?>
			<?php if ( $max_404_length > 0 ) : ?>
				<p>There are currently no 404 errors reported.</p>
			<?php endif; ?>
		<?php else : ?>
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
						<?php foreach ( array_keys( $logged_404s ) as $mclv_410_key ) : ?>
							<tr>
								<td>
									<input type="checkbox" name="add_404s[]" id="mclv-404-<?php echo esc_attr( $mclv_410_key ); ?>" value="<?php echo esc_attr( $mclv_410_key ); ?>" />
								</td>
								<td>
									<label for="mclv-404-<?php echo esc_attr( $mclv_410_key ); ?>"><code><?php echo esc_html( $mclv_410_key ); ?></code></label>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php wp_nonce_field( 'mclv-410-settings' ); ?>
			<p class="submit">
				<input class="button button-primary" type="submit" name="add-to-410-list" value="Add selected entries to 410 list" />
			</p>
		<?php endif; ?>
	</form>

	<!-- Manually Add URLs Section -->
	<h3>Manually add URLs</h3>
	<form action="" method="post">
		<p>You can manually add items to the list by entering them below. Please enter one <strong>fully qualified</strong> URL per line.</p>
		<p>Use <code>*</code> as a wildcard character. So <code>http://www.example.com/*/music/</code> will match all URLs ending in <code>/music/</code>.</p>
		<textarea name="links_to_add" rows="8" cols="80"></textarea>
		<?php wp_nonce_field( 'mclv-410-settings' ); ?>
		<p class="submit">
			<input class="button button-primary" type="submit" name="add-to-410-list" value="Add entries to 410 list" />
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

