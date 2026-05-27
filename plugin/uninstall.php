<?php
/**
 * Uninstall script for Bricks API Bridge.
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Optionally cleans up backup meta keys and plugin options from the database.
 *
 * @package Bricks_API_Bridge
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Whether to clean up all plugin data on uninstall.
 *
 * Set to true to remove all backup meta entries, per-page scripts,
 * and plugin options from the database.
 *
 * @var bool
 */
$cleanup = apply_filters( 'bricks_api_bridge_cleanup_on_uninstall', false );

if ( $cleanup ) {
	global $wpdb;

	// Delete legacy backup meta keys.
	$wpdb->delete(
		$wpdb->postmeta,
		array( 'meta_key' => '_bricks_page_data_backup' ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	);
	$wpdb->delete(
		$wpdb->postmeta,
		array( 'meta_key' => '_bricks_backup_timestamp' ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	);

	// Delete 5-slot backup meta keys.
	for ( $i = 1; $i <= 5; $i++ ) {
		$wpdb->delete(
			$wpdb->postmeta,
			array( 'meta_key' => '_bricks_backup_' . $i ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		);
	}

	// Delete per-page scripts meta.
	$wpdb->delete(
		$wpdb->postmeta,
		array( 'meta_key' => '_bab_footer_scripts' ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	);

	// Delete plugin options.
	delete_option( 'bab_autolearn_data' );
	delete_option( 'bab_build_learnings' );
	delete_option( 'bab_section_presets' );

	// Delete transients.
	delete_transient( 'bab_theme_styles' );
	delete_transient( 'bab_color_palette' );
	delete_transient( 'bab_fonts' );
	delete_transient( 'bab_global_css' );
	delete_transient( 'bab_css_variables' );
	delete_transient( 'bab_classes_migrated' );
	delete_transient( 'bab_classes_normalized_v2' );
}
