<?php
/**
 * Plugin Name:       EdistoBeach Site
 * Description:       First-party custom functionality for EdistoBeach.com. The version-controlled home for site-specific code that does not belong in the theme.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            EdistoBeach.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       edistobeach-site
 *
 * @package EdistoBeach_Site
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Plugin version. Keep in sync with the `Version` header above.
 */
define( 'EBS_VERSION', '1.0.0' );

/**
 * Absolute path to the main plugin file and to the plugin directory
 * (with a trailing slash).
 */
define( 'EBS_PLUGIN_FILE', __FILE__ );
define( 'EBS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Rewrite-rules schema version.
 *
 * Bump this integer whenever a bundled module changes the rewrite rules it
 * registers. ebs_maybe_flush_rewrite_rules() compares it against the stored
 * `ebs_rewrite_rules_version` option and flushes exactly once per environment,
 * which is what makes plain file deploys (that never fire activation hooks)
 * safe.
 */
define( 'EBS_REWRITE_RULES_VERSION', 1 );

/*
 * Feature modules.
 *
 * First-party, REQUIRED components: each is a plain file of `ebs_`-prefixed
 * functions that wires its own hooks when included, loaded with a direct
 * require_once (no existence checks) so a missing file fails loudly rather than
 * silently disabling business-critical behaviour.
 */
require_once EBS_PLUGIN_DIR . 'includes/directorist-permalinks.php';
require_once EBS_PLUGIN_DIR . 'includes/seo-pagination.php';

/**
 * One-time rewrite-rules flush, guarded by EBS_REWRITE_RULES_VERSION.
 *
 * Runs late on `init`, after every module has registered its rules. This is the
 * deploy-safe path: file-only deploys update plugin files without re-activating
 * the plugin, so bumping EBS_REWRITE_RULES_VERSION in code makes the next
 * request on each environment flush exactly once. On every other request the
 * version check matches and this returns immediately -- no repeated flushing.
 */
function ebs_maybe_flush_rewrite_rules() {
	if ( (int) get_option( 'ebs_rewrite_rules_version' ) === EBS_REWRITE_RULES_VERSION ) {
		return;
	}

	flush_rewrite_rules( false );
	update_option( 'ebs_rewrite_rules_version', EBS_REWRITE_RULES_VERSION );
}
add_action( 'init', 'ebs_maybe_flush_rewrite_rules', 99 );

/**
 * Activation.
 *
 * The plugin file is loaded before this hook fires, but `init` has already run
 * for this request, so the module that registers rewrite rules on `init` must
 * be invoked explicitly here before the flush.
 */
function ebs_activate() {
	ebs_directorist_register_listing_rewrite_rules();
	flush_rewrite_rules( false );
	update_option( 'ebs_rewrite_rules_version', EBS_REWRITE_RULES_VERSION );
}
register_activation_hook( __FILE__, 'ebs_activate' );

/**
 * Deactivation.
 *
 * This plugin adds its rewrite rules on `init`, so by the time this hook runs
 * they are already in the in-memory WP_Rewrite object for the current request.
 * Calling flush_rewrite_rules() here would persist them straight back into the
 * database. Instead we delete the stored `rewrite_rules` option. WordPress
 * treats an empty option as "needs rebuild" and regenerates the full set on the
 * next request (WP_Rewrite::wp_rewrite_rules(), persisted on `wp_loaded`) -- that
 * request runs with this plugin inactive, its `init` hook never fires, and the
 * rebuilt set is therefore clean. The version-guard option is also removed so a
 * later reactivation re-flushes.
 */
function ebs_deactivate() {
	delete_option( 'rewrite_rules' );
	delete_option( 'ebs_rewrite_rules_version' );
}
register_deactivation_hook( __FILE__, 'ebs_deactivate' );
