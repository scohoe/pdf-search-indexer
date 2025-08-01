<?php
/**
 * Uninstall PDF Search Indexer
 *
 * @package PDFSearchIndexer
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options
delete_option( 'pdf_search_indexer_enabled' );
delete_option( 'pdf_search_indexer_max_size' );
delete_option( 'pdf_search_indexer_progress' );

// Clear any scheduled events
wp_clear_scheduled_hook( 'pdf_search_indexer_batch_process' );

// Remove custom post meta for PDF content
global $wpdb;

$wpdb->delete(
	$wpdb->postmeta,
	array(
		'meta_key' => '_pdf_content'
	)
);

$wpdb->delete(
	$wpdb->postmeta,
	array(
		'meta_key' => '_pdf_indexed'
	)
);

$wpdb->delete(
	$wpdb->postmeta,
	array(
		'meta_key' => '_pdf_error'
	)
);

// Clean up any transients
delete_transient( 'pdf_search_indexer_status' );