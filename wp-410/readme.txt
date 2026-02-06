=== HTTP 410 (Gone) responses ===
Contributors: solarissmoke, XanderCalvert
Tags: error, gone, robots
Requires at least: 3.7
Tested up to: 6.9
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends HTTP 410 (Gone) responses for deleted content, telling search engines the page is permanently removed.

== Description ==

This plugin issues an HTTP `410` response for URLs corresponding to content that has been permanently removed from your site. Originally created by Samir Shah, now maintained by Matt Calvert. When a post or page is deleted, the plugin logs the old URL and returns a `410` response when that URL is requested. You can also manually manage the list of obsolete URLs.

The [HTTP Specification](http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html#sec10.4.11) defines the `410 Gone` response for resources that have been permanently removed. It informs search engines and crawlers that the content will not return, improving crawl efficiency and SEO clarity.

This plugin is actively maintained by Matt Calvert as a personal project, informed by previous professional experience with similar 410-handling logic. No proprietary or employer-owned code has been used.

== Frequently Asked Questions ==

= Can I customise the 410 response message? =

The default message is a simple plain text message that reads "Sorry, the page you requested has been permanently removed." This is because many people want to minimise the bandwidth that is used by error responses.

If you want to customise the message, just place a template file called `410.php` in your theme folder, and the plugin will automatically use that instead. Take a look at your theme's `404.php` file to see how the template needs to be structured. You can also hook into the `mclv_410_response` action to trigger any specific events for queries resulting in a 410 response.

= Will this plugin work if a caching/performance plugin is active ? =

The plugin has been tested with the following caching plugins, and should work even if they are active:

- W3 Total Cache
- WP Super Cache

I have not tested it with other caching plugins, and there is a high chance that it **will not work** with many of them. Most of them will cache the response as if it is a 404 (page not found) response, and issue a 404 response header instead of a 410 response header.

== Changelog ==

= 1.0.3 =
* Official release build for WordPress.org plugin directory.

= 1.0.2 =
* **Bugfix:** Updated version number in plugin header and asset versions.

= 1.0.1 =
* **Bugfix:** Fixed array assignment typo in `note_inserted_post()` method (changed `[] .=` to `[] =`).
* **Bugfix:** Added defensive check for missing/invalid post objects to prevent errors when `get_post()` returns null.

= 1.0.0 =
* **New:** Wildcard patterns now displayed in a separate section with visual warning for better visibility.
* **New:** Admin settings page refactored into separate template file for cleaner code structure.
* Properly enqueue admin CSS and JavaScript using wp_enqueue_style() and wp_enqueue_script().
* Moved CSS and JavaScript to separate files (`css/admin.css` and `js/admin.js`).
* Converted admin JavaScript from jQuery to vanilla JS (no jQuery dependency).
* Improved data sanitization and validation for all user inputs including $_SERVER variables.
* Secured uninstall.php with proper WP_UNINSTALL_PLUGIN check.
* Renamed all function/class/element prefixes from `wp_410` to `mclv_410` for WordPress.org compliance.
* Fixed all PHPCS coding standards errors and warnings.
* **Deprecated:** The `wp_410_response` action hook is deprecated. Use `mclv_410_response` instead. The old hook still works but will trigger a deprecation notice.

= 0.9.3 =
* Added GitHub Actions workflow to automatically build a distributable plugin ZIP on tagged releases.

= 0.9.2 
* Fixed bug where you couldn't select url in 404 menu.

= 0.9.1 =
* Significant internal refactor to meet modern WordPress Coding Standards (PHPCS).
* Added full PHPCS ruleset and GitHub Actions workflow for automated linting.
* Improved SQL handling by adding proper prepared statements (security hardening).
* Replaced deprecated functions and improved URL parsing.
* Ensured proper escaping throughout the admin interface.
* General clean-up of inline documentation and comments.
* No front-facing or behavioural changes; fully backwards compatible.

= 0.9.0 =
* Maintenance release by new maintainer (Matt Calvert).
* Modernised plugin header and readme; added Tested up to 6.6.
* General code clean-up and internal preparation for future improvements.
* No behavioural changes in this release.

= 0.8.6 =
* Don't rely on WordPress to correctly report whether the site is using SSL.

= 0.8.5 =
* Fix admin form CSRF checking.

= 0.8.4 =
* Add CSRF validation to settings page.

= 0.8.3 =
* Fix magic quotes handling on settings page.

= 0.8.2 =
* Overhaul settings page UI.
* Add option to specify how many 404 errors to keep.

= 0.8.1 =
* Add select all helpers to 410/404 lists.

= 0.8 =
* Don't automatically add links to the list when posts are deleted (most deletions are drafts).

= 0.7.2 =
* Add support for popular caching plugins (W3 Total Cache and WP Super Cache).

= 0.7.1 =
* Database tweaks (change ID to unsigned MEDIUMINT)

= 0.7 =
* Added logging of 404 errors so they can be easily added to the list of obsolete URLs.

= 0.6.1 =
* Bugfix: don't accept URLs that don't resolve to WordPress
* Warn about invalid URLs when permalink settings change

= 0.6 =
* Moved storage of old URLs from the Options API to the database, to avoid issues with long lists.

= 0.5 =
* Added the option to use your own template to display the 410 response. Just add a file called `410.php` to your theme folder.

= 0.4 =
* Bugfix: With batch deletes, only the first item being deleted was noted by the plugin

= 0.3 =
* Bugfix: URLs containing non-ascii characters were not always recognised
* Bugfix: URLs were displayed in encoded form on the settings page
* Added a `mclv_410_response` action to allow users to customise the response message when a deleted article is requested

= 0.2 =
* Added wildcard support to URLs
* Bugfix: don't check URLs of deleted revisions and new draft posts

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The plugin settings can be accessed via the 'Plugins' menu in the administration area
