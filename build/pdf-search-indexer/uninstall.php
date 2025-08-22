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
delete_option( 'pdf_search_indexer_enable_indexing' );
delete_option( 'pdf_search_indexer_max_size' );
delete_option( 'pdf_search_indexer_progress' );

// Clear any scheduled events
wp_clear_scheduled_hook( 'pdf_search_indexer_batch_process' );
wp_clear_scheduled_hook( 'pdf_search_indexer_watchdog' );

// Remove custom post meta for PDF content
global $wpdb;

// Use a more efficient query to delete all plugin meta keys at once
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup during uninstall, caching not applicable
$meta_keys = array(
    '_pdf_content', 
    '_pdf_indexed', 
    '_pdf_error',
    '_pdf_indexing_status',
    '_pdf_indexed_date',
    '_pdf_index_failed_count'
);

if ( ! empty( $meta_keys ) ) {
    // Build a placeholder string for the IN() clause.
    $placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
    $sql          = 'DELETE FROM ' . $wpdb->postmeta . ' WHERE meta_key IN (' . $placeholders . ')';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup during uninstall, caching not applicable
    $wpdb->query( $wpdb->prepare( $sql, $meta_keys ) );
}

// Drop the custom table
global $wpdb;
$table_name = $wpdb->prefix . 'pdf_search_index';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup during uninstall, caching not applicable
$wpdb->query( "DROP TABLE IF EXISTS `" . esc_sql( $table_name ) . "`" );

// Clean up any transients
delete_transient( 'pdf_search_indexer_status' );
delete_transient( 'pdf_search_indexer_processing_lock' );

// Clear object cache
wp_cache_delete( 'pdf_search_indexer_stats', 'pdf_search_indexer' );
wp_cache_delete( 'pdf_search_indexer_counts', 'pdf_search_indexer' );
wp_cache_delete( 'pdf_search_indexer_indexed_ids', 'pdf_search_indexer' );