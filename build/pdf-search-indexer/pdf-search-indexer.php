<?php
/**
 * Plugin Name: PDF Search Indexer
 * Plugin URI: https://github.com/scotthoenes/pdf-search-indexer
 * Description: Extract and index text from PDF attachments to make them searchable in WordPress.
 * Version: 1.0.1
 * Author: Scott Hoenes
 * Author URI: https://github.com/scotthoenes
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pdf-search-indexer
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.2
 *
 * @package PDFSearchIndexer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'PDF_SEARCH_INDEXER_VERSION', '1.0.1' );
define( 'PDF_SEARCH_INDEXER_PLUGIN_FILE', __FILE__ );
define( 'PDF_SEARCH_INDEXER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PDF_SEARCH_INDEXER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PDF_SEARCH_INDEXER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Include the PDF Parser library
// Check if vendor directory exists before requiring it
if ( file_exists( PDF_SEARCH_INDEXER_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require PDF_SEARCH_INDEXER_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	// Display admin notice if the library is missing
	function pdf_search_indexer_missing_library_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<h3><?php esc_html_e( 'PDF Search Indexer - Missing Dependencies', 'pdf-search-indexer' ); ?></h3>
			<p><?php esc_html_e( 'The PDF Search Indexer plugin requires the PDF Parser library to function properly.', 'pdf-search-indexer' ); ?></p>
			<p><strong><?php esc_html_e( 'Installation Options:', 'pdf-search-indexer' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'If you have Composer installed, run:', 'pdf-search-indexer' ); ?> <code>composer install</code> <?php esc_html_e( 'in the plugin directory', 'pdf-search-indexer' ); ?></li>
				<li><?php esc_html_e( 'Or download a pre-built version with dependencies included from the plugin repository', 'pdf-search-indexer' ); ?></li>
			</ol>
			<p><em><?php esc_html_e( 'The plugin will remain inactive until dependencies are installed.', 'pdf-search-indexer' ); ?></em></p>
		</div>
		<?php
	}
	add_action( 'admin_notices', 'pdf_search_indexer_missing_library_notice' );
	return; // Stop plugin execution
}

use Smalot\PdfParser\Parser;

// Timeout handling removed for WordPress.org compliance

// Lightweight internal logger for plugin (stores messages in progress option)
if ( ! function_exists( 'pdf_search_indexer_log' ) ) {
	/**
	 * Log a message to the plugin's progress store (no trigger_error/error_log).
	 *
	 * @param string $message Message to store (sanitized).
	 * @param string $level   One of: info|notice|warning|error.
	 */
	function pdf_search_indexer_log( $message, $level = 'info' ) {
		$level   = in_array( $level, array( 'info', 'notice', 'warning', 'error' ), true ) ? $level : 'info';
		$entry   = array(
			'level'     => $level,
			'message'   => sanitize_text_field( (string) $message ),
			'timestamp' => current_time( 'mysql' ),
		);
		$progress = get_option( 'pdf_search_indexer_progress' );
		if ( ! is_array( $progress ) ) {
			$progress = array();
		}
		if ( ! isset( $progress['log'] ) || ! is_array( $progress['log'] ) ) {
			$progress['log'] = array();
		}
		array_unshift( $progress['log'], $entry );
		if ( count( $progress['log'] ) > 100 ) {
			$progress['log'] = array_slice( $progress['log'], 0, 100 );
		}
		update_option( 'pdf_search_indexer_progress', $progress, false );

		// Allow external listeners to hook into plugin log events without using dev functions
		do_action( 'pdf_search_indexer_log', $entry );
	}
}

// Add this function to monitor resources during processing
if (!function_exists('pdf_search_indexer_check_resources')) {
    function pdf_search_indexer_check_resources() {
        $memory_usage = memory_get_usage(true) / (1024 * 1024); // MB
        $memory_limit = ini_get('memory_limit');
        
        // Convert PHP memory limit to MB for comparison
        if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
            $memory_limit = $matches[1];
            if ($matches[2] == 'G') {
                $memory_limit *= 1024;
            }
        }
        
        // If using more than 80% of allowed memory, abort
        if ($memory_usage > ($memory_limit * 0.8)) {
            return false;
        }
        
        return true;
    }
}

// Function to extract text from PDF
function extract_pdf_text($file_path) {
    // Process PDF with standard WordPress timeout handling
    
    // Check file size and use alternative approach for large files
    $max_size = get_option('pdf_search_indexer_max_size', 20); // Default 20MB
    $file_size = filesize($file_path) / (1024 * 1024); // Convert to MB
    
    // Hard limit - skip extremely large files entirely
    $hard_limit = 50; // 50MB
    if ($file_size > $hard_limit) {
        $filename = basename($file_path);
        $error_message = "Very large PDF file: $filename\nSize: " . round($file_size, 2) . "MB\nThis file was not indexed due to its extreme size.";
        
        // Log the error
        $progress = get_option('pdf_search_indexer_progress');
        $error_entry = array(
            'file' => $filename,
            'error' => 'File too large',
            'message' => $error_message,
            'timestamp' => current_time('mysql')
        );
        array_unshift($progress['errors'], $error_entry);
        if (count($progress['errors']) > 50) {
            $progress['errors'] = array_slice($progress['errors'], 0, 50);
        }
        update_option('pdf_search_indexer_progress', $progress);

        return $error_message;
    }
    
    // Process with standard WordPress limits
    
    // For large files, use an even more conservative approach
    if ($file_size > $max_size) {
        try {
            // For large files, use a more memory-efficient approach
            $parser = new Parser();
            
            // Use current memory limits without modification
            
            // Try to get basic file info without full parsing
            $filename = basename($file_path);
            
            // First try to check if the file is secured before full parsing
            try {
                // Simple check for encryption by reading first few bytes
                global $wp_filesystem;
                if (!$wp_filesystem) {
                    require_once ABSPATH . '/wp-admin/includes/file.php';
                    WP_Filesystem();
                }
                
                if ($wp_filesystem->exists($file_path)) {
                    $header = $wp_filesystem->get_contents($file_path, false, 0, 1024);
                    
                    // Very basic check for encryption indicators
                    if (strpos($header, '/Encrypt') !== false) {
                        return "This PDF is password-protected or secured and cannot be indexed. Filename: $filename";
                    }
                }
            } catch (Exception $e) {
                // Continue with normal processing if this check fails
            }
            
            // Create a basic entry with filename in case parsing fails
            $basic_text = "Large PDF file: $filename\nSize: " . round($file_size, 2) . "MB\n";
            
            // Try to parse with a timeout
            $pdf = $parser->parseFile($file_path);
            
            // Get basic metadata instead of full text for very large files
            $details = $pdf->getDetails();
            $text = "Large PDF file indexed with limited content. Size: " . round($file_size, 2) . "MB\n\n";
            
            // Add metadata to make the file searchable
            if (isset($details['Title'])) $text .= "Title: " . $details['Title'] . "\n";
            if (isset($details['Subject'])) $text .= "Subject: " . $details['Subject'] . "\n";
            if (isset($details['Keywords'])) $text .= "Keywords: " . $details['Keywords'] . "\n";
            if (isset($details['Author'])) $text .= "Author: " . $details['Author'] . "\n";
            
            // Try to get text from first few pages only - with a fallback
            try {
                $pages = $pdf->getPages();
                $max_pages = 3; // Reduce to only 3 pages for very large files
                $count = 0;
                
                foreach ($pages as $page) {
                    if ($count >= $max_pages) break;
                    $text .= $page->getText() . "\n\n";
                    $count++;
                }
                
                $text .= "\n[Note: Only first $max_pages pages were indexed due to file size]";
            } catch (Exception $e) {
                $text .= "\n[Could not extract page content: " . $e->getMessage() . "]";
            }
            
            return $text;
        } catch (Exception $e) {
            $filename = basename($file_path);

            // Log the error
            $progress = get_option('pdf_search_indexer_progress');
            $error_entry = array(
                'file' => $filename,
                'error' => 'Processing error',
                'message' => $e->getMessage(),
                'timestamp' => current_time('mysql')
            );
            array_unshift($progress['errors'], $error_entry);
            if (count($progress['errors']) > 50) {
                $progress['errors'] = array_slice($progress['errors'], 0, 50);
            }
            update_option('pdf_search_indexer_progress', $progress);
            
            // Check if it's a secured PDF error
            if (strpos($e->getMessage(), 'Secured pdf file are currently not supported') !== false) {
                return "This PDF is password-protected or secured and cannot be indexed. Filename: $filename";
            }
            
            // Create a basic searchable entry with filename
            return "Large PDF file: $filename\nSize: " . round($file_size, 2) . "MB\nThis file was partially indexed due to its size.";
        }
    }
    
    // Normal processing for regular-sized files
    try {
        $parser = new Parser();
        $pdf = $parser->parseFile($file_path);
        $text = $pdf->getText();
        
        // Explicitly release large objects
        $pdf = null;
        $parser = null;
        if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); }
        
        return $text;
    } catch (Exception $e) {
        $filename = basename($file_path);

        // Log the error
        $progress = get_option('pdf_search_indexer_progress');
        if (!is_array($progress)) { $progress = array(); }
        if (!isset($progress['errors']) || !is_array($progress['errors'])) { $progress['errors'] = array(); }
        $error_entry = array(
            'file' => $filename,
            'error' => 'Processing error',
            'message' => $e->getMessage(),
            'timestamp' => current_time('mysql')
        );
        array_unshift($progress['errors'], $error_entry);
        if (count($progress['errors']) > 50) {
            $progress['errors'] = array_slice($progress['errors'], 0, 50);
        }
        update_option('pdf_search_indexer_progress', $progress);
        
        // Check if it's a secured PDF error
        if (strpos($e->getMessage(), 'Secured pdf file are currently not supported') !== false) {
            return "This PDF is password-protected or secured and cannot be indexed. Filename: $filename";
        }
        return "Error processing PDF: " . $e->getMessage();
    }
}

// Function to index PDF attachments
function index_pdf_attachments($post_id) {
    // Check if indexing is enabled
    if (get_option('pdf_search_indexer_enable_indexing', '1') != '1') {
        return;
    }
    
    $attachments = get_attached_media('application/pdf', $post_id);

    if (!empty($attachments)) {
        foreach ($attachments as $attachment) {
            $file_path = get_attached_file($attachment->ID);
            
            // Skip if file doesn't exist
            if (!file_exists($file_path)) {
                continue;
            }
            
            
            
            $pdf_text = extract_pdf_text($file_path);
            
            // Store the extracted text in the custom table
            global $wpdb;
            $table_name = $wpdb->prefix . 'pdf_search_index';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table operations require direct queries
            $wpdb->replace($table_name, array(
                'attachment_id' => $attachment->ID,
                'indexed_content' => $pdf_text
            ));
            
            // Invalidate cache after data change
            wp_cache_delete('pdf_search_indexer_stats', 'pdf_search_indexer');
            wp_cache_delete('pdf_search_indexer_counts', 'pdf_search_indexer');
            wp_cache_delete('pdf_search_indexer_indexed_ids', 'pdf_search_indexer');
            wp_cache_delete('pdf_search_indexer_remaining_id', 'pdf_search_indexer');

            // Reset failure count on success
            delete_post_meta($attachment->ID, '_pdf_index_failed_count');

            // Update status - mark as secured if needed
            if (strpos($pdf_text, 'password-protected or secured') !== false) {
                update_post_meta($attachment->ID, '_pdf_indexing_status', 'secured');
            } else {
                update_post_meta($attachment->ID, '_pdf_indexing_status', 'completed');
            }
            update_post_meta($attachment->ID, '_pdf_indexed_date', current_time('mysql'));

            // Update progress count and log
            $progress = get_option('pdf_search_indexer_progress');
            $progress['processed_count']++;
            $progress['last_update'] = current_time('mysql');
            $progress['heartbeat'] = time();
            $progress['consecutive_errors'] = 0;
            if (!isset($progress['log']) || !is_array($progress['log'])) {
                $progress['log'] = array();
            }
            $log_entry = array(
                'file' => basename($file_path),
                'status' => get_post_meta($attachment->ID, '_pdf_indexing_status', true),
                'timestamp' => current_time('mysql')
            );
            array_unshift($progress['log'], $log_entry);
            if (count($progress['log']) > 50) {
                $progress['log'] = array_slice($progress['log'], 0, 50);
            }
            update_option('pdf_search_indexer_progress', $progress);
        }
    }
}

// Hook into attachment updates
add_action('add_attachment', 'index_pdf_attachments');
add_action('edit_attachment', 'index_pdf_attachments');

// Integrate with WordPress search
function pdf_search_indexer_search_join($join) {
    global $wpdb;
    if (is_search()) {
        $join .= ' LEFT JOIN ' . $wpdb->prefix . 'pdf_search_index ON ' . $wpdb->posts . '.ID = ' . $wpdb->prefix . 'pdf_search_index.attachment_id';
    }
    return $join;
}
add_filter('posts_join', 'pdf_search_indexer_search_join');

function pdf_search_indexer_search_where($where) {
    global $wpdb;
    if (is_search()) {
        $where = preg_replace(
            "/\(\s*" . $wpdb->posts . ".post_title LIKE '([^']*)'\s*\)/",
            "(" . $wpdb->posts . ".post_title LIKE '$1' OR " . $wpdb->prefix . "pdf_search_index.indexed_content LIKE '$1')", $where
        );
    }
    return $where;
}
add_filter('posts_where', 'pdf_search_indexer_search_where');

// Function to create the custom table
function pdf_search_indexer_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pdf_search_index';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        attachment_id bigint(20) NOT NULL,
        indexed_content longtext NOT NULL,
        PRIMARY KEY  (id),
        KEY attachment_id (attachment_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Combined activation function
function pdf_search_indexer_activate_plugin() {
    pdf_search_indexer_create_table();
    pdf_search_indexer_init_options();
}

// Register single activation hook
register_activation_hook(__FILE__, 'pdf_search_indexer_activate_plugin');

// Initialize plugin options
function pdf_search_indexer_init_options() {
    // Don't run indexing on activation anymore
    // Just set up the plugin options
    if (get_option('pdf_search_indexer_enable_indexing') === false) {
        add_option('pdf_search_indexer_enable_indexing', '1');
    }
    if (get_option('pdf_search_indexer_max_size') === false) {
        add_option('pdf_search_indexer_max_size', '20');
    }
    // Add option to track processing progress
    if (get_option('pdf_search_indexer_progress') === false) {
        add_option('pdf_search_indexer_progress', array(
            'current_file' => '',
            'started_at' => '',
            'last_update' => '',
            'log' => array(), // Add a log array
            'errors' => array(), // Add an error log array
            'processed_count' => 0,
            'total_count' => 0,
            'batch_number' => 0
        ));
    }
}

// Modify the index_existing_pdfs function to properly track progress
function index_existing_pdfs() {
    // Check database connection health before starting
    global $wpdb;
    if ( !$wpdb || $wpdb->last_error ) {
        pdf_search_indexer_log( 'Database connection issue detected: ' . ( $wpdb->last_error ? sanitize_text_field( $wpdb->last_error ) : '' ), 'warning' );
        return;
    }

    // Acquire a short-lived lock to avoid concurrent overlapping batches
    $lock_key = 'pdf_search_indexer_processing_lock';
    if (get_transient($lock_key)) {
        // Another batch is in progress or just finished
        return;
    }
    set_transient($lock_key, 1, 120); // 2 minutes
    
    // Get current progress data
    $progress = get_option('pdf_search_indexer_progress', array(
        'current_file' => '',
        'started_at' => '',
        'last_update' => '',
        'processed_count' => 0,
        'total_count' => 0,
        'batch_number' => 0,
        'consecutive_errors' => 0, // Add error tracking
        'heartbeat' => 0,
        'errors' => array(),
        'log' => array(),
    ));
    if (!is_array($progress)) { $progress = array(); }
    if (!isset($progress['errors']) || !is_array($progress['errors'])) { $progress['errors'] = array(); }
    if (!isset($progress['log']) || !is_array($progress['log'])) { $progress['log'] = array(); }
    
    // Update heartbeat at start of processing
    $progress['heartbeat'] = time();
    
    // Safety check - if too many consecutive batches with errors
    if (isset($progress['consecutive_errors']) && $progress['consecutive_errors'] > 5) {
        // Reset error counter but don't schedule next batch
        $progress['consecutive_errors'] = 0;
        update_option('pdf_search_indexer_progress', $progress);
        pdf_search_indexer_log( 'Stopping due to too many consecutive errors', 'warning' );
        delete_transient($lock_key);
        return;
    }
    
    // Update batch number
    $progress['batch_number']++;
    $progress['last_update'] = current_time('mysql');
    
    // If this is the first batch, set the start time and count total PDFs
    if ($progress['batch_number'] == 1 || empty($progress['started_at'])) {
        $progress['started_at'] = current_time('mysql');
        $progress['processed_count'] = 0; // Reset processed count
        
        // Count total PDFs and already indexed PDFs with caching
        $cache_key = 'pdf_search_indexer_counts';
        $counts = wp_cache_get($cache_key, 'pdf_search_indexer');
        
        if (false === $counts) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Counting queries for statistics
            $total_pdfs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'application/pdf'");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query for statistics
            $indexed_pdfs_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pdf_search_index");
            
            $counts = array(
                'total_pdfs' => $total_pdfs,
                'indexed_pdfs_count' => $indexed_pdfs_count
            );
            
            // Cache for 2 minutes during processing
            wp_cache_set($cache_key, $counts, 'pdf_search_indexer', 120);
        } else {
            $total_pdfs = $counts['total_pdfs'];
            $indexed_pdfs_count = $counts['indexed_pdfs_count'];
        }

        $progress['total_count'] = $total_pdfs;
        $progress['processed_count'] = $indexed_pdfs_count;
    }
    
    update_option('pdf_search_indexer_progress', $progress);
    
    // Process one PDF per batch using efficient query (avoid huge post__not_in lists)
    $cache_key = 'pdf_search_indexer_next_pdf_id';
    $next_pdf_id = wp_cache_get($cache_key, 'pdf_search_indexer');
    
    if (false === $next_pdf_id) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Necessary for batch processing
        $next_pdf_id = (int) $wpdb->get_var(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->prefix}pdf_search_index i ON i.attachment_id = p.ID
             WHERE p.post_type = 'attachment' AND p.post_mime_type = 'application/pdf' AND i.attachment_id IS NULL
             ORDER BY p.ID ASC
             LIMIT 1"
        );
        wp_cache_set($cache_key, $next_pdf_id, 'pdf_search_indexer', 60); // Cache for 60 seconds
    }

    if ($next_pdf_id) {
        $batch_errors = 0;
        try {
            if ($wpdb->last_error) {
                pdf_search_indexer_log( 'Database error detected before processing: ' . ( $wpdb->last_error ? sanitize_text_field( $wpdb->last_error ) : '' ), 'error' );
                $batch_errors++;
            } else {
                $file_path = get_attached_file($next_pdf_id);
                if ($file_path && file_exists($file_path)) {
                    // Update progress with current file
                    $progress = get_option('pdf_search_indexer_progress');
                    $progress['current_file'] = basename($file_path);
                    $progress['last_update'] = current_time('mysql');
                    update_option('pdf_search_indexer_progress', $progress);

                    // Add status indicator
                    update_post_meta($next_pdf_id, '_pdf_indexing_status', 'processing');

                    $pdf_text = extract_pdf_text($file_path);

                    $table_name = $wpdb->prefix . 'pdf_search_index';
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table operations require direct queries
                    $result = $wpdb->replace($table_name, array(
                        'attachment_id' => $next_pdf_id,
                        'indexed_content' => $pdf_text
                    ));

                    if ($result === false || $wpdb->last_error) {
                        pdf_search_indexer_log( 'Database error during replace operation: ' . ( $wpdb->last_error ? sanitize_text_field( $wpdb->last_error ) : '' ), 'error' );
                        $batch_errors++;
                        delete_post_meta($next_pdf_id, '_pdf_indexing_status');

                        // Track failure count and, if threshold reached, mark as failed and insert placeholder
                        $fail_count = (int) get_post_meta($next_pdf_id, '_pdf_index_failed_count', true);
                        $fail_count++;
                        update_post_meta($next_pdf_id, '_pdf_index_failed_count', $fail_count);
                        if ($fail_count >= 3) {
                            $placeholder = '[ERROR] Database write failed during indexing.';
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table operations require direct queries
                            $wpdb->replace($table_name, array(
                                'attachment_id' => $next_pdf_id,
                                'indexed_content' => $placeholder
                            ));
                            wp_cache_delete('pdf_search_indexer_remaining_id', 'pdf_search_indexer');
                            update_post_meta($next_pdf_id, '_pdf_indexing_status', 'failed');
                            update_post_meta($next_pdf_id, '_pdf_indexed_date', current_time('mysql'));

                            // Log
                            $progress = get_option('pdf_search_indexer_progress');
                            $error_entry = array(
                                'file' => basename($file_path),
                                'error' => 'Database error',
                                'message' => $wpdb->last_error,
                                'timestamp' => current_time('mysql')
                            );
                            array_unshift($progress['errors'], $error_entry);
                            if (count($progress['errors']) > 50) { $progress['errors'] = array_slice($progress['errors'], 0, 50); }
                            update_option('pdf_search_indexer_progress', $progress);
                        }
                    } else {
                        // Invalidate cache after data change
                        wp_cache_delete('pdf_search_indexer_stats', 'pdf_search_indexer');
                        wp_cache_delete('pdf_search_indexer_counts', 'pdf_search_indexer');
                        wp_cache_delete('pdf_search_indexer_indexed_ids', 'pdf_search_indexer');
                        wp_cache_delete('pdf_search_indexer_remaining_id', 'pdf_search_indexer');

                        // Reset failure count on success
                        delete_post_meta($next_pdf_id, '_pdf_index_failed_count');

                        // Update status - mark as secured if needed
                        if (strpos($pdf_text, 'password-protected or secured') !== false) {
                            update_post_meta($next_pdf_id, '_pdf_indexing_status', 'secured');
                        } else {
                            update_post_meta($next_pdf_id, '_pdf_indexing_status', 'completed');
                        }
                        update_post_meta($next_pdf_id, '_pdf_indexed_date', current_time('mysql'));

                        // Update progress count and log
                        $progress = get_option('pdf_search_indexer_progress');
                        $progress['processed_count']++;
                        $progress['last_update'] = current_time('mysql');
                        $progress['heartbeat'] = time();
                        $progress['consecutive_errors'] = 0;
                        if (!isset($progress['log']) || !is_array($progress['log'])) {
                            $progress['log'] = array();
                        }
                        $log_entry = array(
                            'file' => basename($file_path),
                            'status' => get_post_meta($next_pdf_id, '_pdf_indexing_status', true),
                            'timestamp' => current_time('mysql')
                        );
                        array_unshift($progress['log'], $log_entry);
                        if (count($progress['log']) > 50) {
                            $progress['log'] = array_slice($progress['log'], 0, 50);
                        }
                        update_option('pdf_search_indexer_progress', $progress);
                    }
                } else {
                    // File missing; mark as failed to avoid infinite retries
                    $table_name = $wpdb->prefix . 'pdf_search_index';
                    $placeholder = '[ERROR] File missing on disk.';
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table operations require direct queries
                    $wpdb->replace($table_name, array(
                        'attachment_id' => $next_pdf_id,
                        'indexed_content' => $placeholder
                    ));
                    wp_cache_delete('pdf_search_indexer_remaining_id', 'pdf_search_indexer');
                    update_post_meta($next_pdf_id, '_pdf_indexing_status', 'failed');
                    update_post_meta($next_pdf_id, '_pdf_indexed_date', current_time('mysql'));
                }
            }
        } catch (Exception $e) {
            pdf_search_indexer_log( 'Exception during processing: ' . sanitize_text_field( $e->getMessage() ), 'error' );
            $batch_errors++;
            delete_post_meta($next_pdf_id, '_pdf_indexing_status');

            // Failure count and optional placeholder after 3 tries
            $fail_count = (int) get_post_meta($next_pdf_id, '_pdf_index_failed_count', true);
            $fail_count++;
            update_post_meta($next_pdf_id, '_pdf_index_failed_count', $fail_count);
            if ($fail_count >= 3) {
                $table_name = $wpdb->prefix . 'pdf_search_index';
                $msg = '[ERROR] ' . sanitize_text_field($e->getMessage());
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table operations require direct queries
                $wpdb->replace($table_name, array(
                    'attachment_id' => $next_pdf_id,
                    'indexed_content' => substr($msg, 0, 500)
                ));
                wp_cache_delete('pdf_search_indexer_remaining_id', 'pdf_search_indexer');
                update_post_meta($next_pdf_id, '_pdf_indexing_status', 'failed');
                update_post_meta($next_pdf_id, '_pdf_indexed_date', current_time('mysql'));
            }

            // Log
            $progress = get_option('pdf_search_indexer_progress');
            $error_entry = array(
                'file' => (string) $next_pdf_id,
                'error' => 'Exception',
                'message' => $e->getMessage(),
                'timestamp' => current_time('mysql')
            );
            array_unshift($progress['errors'], $error_entry);
            if (count($progress['errors']) > 50) { $progress['errors'] = array_slice($progress['errors'], 0, 50); }
            update_option('pdf_search_indexer_progress', $progress);
        }

        // ADDED: Force garbage collection after each PDF to free memory
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        // Add small delay between files to reduce database load
        usleep(100000); // 0.1 second delay

        // Update consecutive errors count
        if ($batch_errors > 0) {
            $progress = get_option('pdf_search_indexer_progress');
            $progress['consecutive_errors'] = isset($progress['consecutive_errors']) ? $progress['consecutive_errors'] + 1 : 1;
            update_option('pdf_search_indexer_progress', $progress);
            pdf_search_indexer_log( 'Batch completed with ' . intval( $batch_errors ) . ' errors', 'notice' );
        }

        // Schedule another batch if there are more PDFs to process
        $cache_key = 'pdf_search_indexer_remaining_id';
        $remaining_id = wp_cache_get($cache_key, 'pdf_search_indexer');
        
        if (false === $remaining_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Necessary for batch processing status
            $remaining_id = (int) $wpdb->get_var(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->prefix}pdf_search_index i ON i.attachment_id = p.ID
                 WHERE p.post_type = 'attachment' AND p.post_mime_type = 'application/pdf' AND i.attachment_id IS NULL
                 LIMIT 1"
            );
            wp_cache_set($cache_key, $remaining_id, 'pdf_search_indexer', 30); // Cache for 30 seconds
        }

        if ($remaining_id) {
            // Update heartbeat before scheduling next batch
            $progress = get_option('pdf_search_indexer_progress');
            $progress['heartbeat'] = time();
            update_option('pdf_search_indexer_progress', $progress);

            // Backoff on consecutive errors
            $delay = 60;
            if (!empty($progress['consecutive_errors'])) {
                $delay = min(300, 60 * (int) $progress['consecutive_errors']); // up to 5 minutes
            }

            if (!wp_next_scheduled('pdf_search_indexer_batch_process')) {
                wp_schedule_single_event(time() + $delay, 'pdf_search_indexer_batch_process');
            }
        } else {
            // Reset progress when done
            $progress = get_option('pdf_search_indexer_progress');
            $progress['current_file'] = '';
            $progress['heartbeat'] = 0; // Clear heartbeat when done
            $progress['last_update'] = current_time('mysql');
            update_option('pdf_search_indexer_progress', $progress);
        }
    } else {
        // Reset progress when done
        $progress = get_option('pdf_search_indexer_progress');
        $progress['current_file'] = '';
        $progress['batch_number'] = 0;
        $progress['processed_count'] = 0;
        $progress['total_count'] = 0;
        $progress['last_update'] = current_time('mysql');
        update_option('pdf_search_indexer_progress', $progress);
    }

    // Release lock
    delete_transient($lock_key);
}

// Move this code inside a function that's hooked to an admin action
// Remove this standalone if block:
// if (isset($_POST['pdf_search_indexer_stop']) && check_admin_referer('pdf_search_indexer_stop_nonce')) {
//    ...
// }

// Instead, create a proper function to handle admin POST requests
function pdf_search_indexer_handle_admin_actions() {
    // Handle data migration and re-indexing
    if (isset($_POST['pdf_search_indexer_migrate_reindex']) && check_admin_referer('pdf_search_indexer_migrate_reindex_nonce')) {
        global $wpdb;

        // 1. Clear the custom index table
        $table_name = $wpdb->prefix . 'pdf_search_index';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table truncation for reset functionality
        $wpdb->query("TRUNCATE TABLE `" . esc_sql($table_name) . "`");

        // 1.a. Clear out the old post_content from attachments to free up space
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bulk update for cleanup functionality
        $wpdb->query("UPDATE {$wpdb->posts} SET post_content = '' WHERE post_type = 'attachment' AND post_mime_type = 'application/pdf'");

        // 2. Delete old post meta data
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bulk delete for cleanup functionality
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_pdf_content_index'");

        // 3. Reset indexing status for all PDFs
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bulk delete for cleanup functionality
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_pdf_indexing_status'");
        
        // Invalidate all caches after data changes
        wp_cache_delete('pdf_search_indexer_stats', 'pdf_search_indexer');
        wp_cache_delete('pdf_search_indexer_counts', 'pdf_search_indexer');
        wp_cache_delete('pdf_search_indexer_indexed_ids', 'pdf_search_indexer');

        // 3. Start the re-indexing process
        wp_schedule_single_event(time(), 'pdf_search_indexer_batch_process');

        add_settings_error(
            'pdf_search_indexer',
            'migration_started',
            'Old data has been cleared, and re-indexing has started. This may take some time.',
            'success'
        );
    }

    // Only run in admin
    if (!is_admin()) {
        return;
    }
    
    // Handle start/resume indexing request
    if (isset($_POST['pdf_search_indexer_reindex']) && check_admin_referer('pdf_search_indexer_reindex_nonce')) {
        // Check if there's already a scheduled batch process
        $next_scheduled = wp_next_scheduled('pdf_search_indexer_batch_process');
        
        if (!$next_scheduled) {
            // Schedule the first batch to start immediately
            wp_schedule_single_event(time(), 'pdf_search_indexer_batch_process');
            
            add_settings_error(
                'pdf_search_indexer',
                'indexing_started',
                'PDF indexing has been started. The process will run in the background.',
                'success'
            );
        } else {
            add_settings_error(
                'pdf_search_indexer',
                'indexing_already_running',
                'PDF indexing is already running. Next batch scheduled for ' . gmdate('H:i:s', $next_scheduled) . '.',
                'info'
            );
        }
    }
    
    // Handle manual watchdog trigger
    if (isset($_POST['pdf_search_indexer_watchdog']) && check_admin_referer('pdf_search_indexer_watchdog_nonce')) {
        pdf_search_indexer_watchdog();
        
        add_settings_error(
            'pdf_search_indexer',
            'watchdog_triggered',
            'Watchdog check completed. If the process was stalled, it has been restarted.',
            'success'
        );
    }
    
    // Handle stop processing request
    if (isset($_POST['pdf_search_indexer_stop']) && check_admin_referer('pdf_search_indexer_stop_nonce')) {
        // Clear any scheduled batch processes
        wp_clear_scheduled_hook('pdf_search_indexer_batch_process');
        
        $count = 0;
        
        // Reset progress tracking
        $progress = array(
            'current_file' => '',
            'started_at' => '',
            'last_update' => current_time('mysql'),
            'processed_count' => 0,
            'total_count' => 0,
            'batch_number' => 0
        );
        update_option('pdf_search_indexer_progress', $progress);
        
        add_settings_error(
            'pdf_search_indexer',
            'indexing_stopped',
            'PDF indexing has been stopped. ' . esc_html($count) . ' PDFs that were being processed have been reset to pending status.',
            'success'
        );
    }
    
    
}
// Hook this function to admin_init
add_action('admin_init', 'pdf_search_indexer_handle_admin_actions');

// Add this to the settings page HTML, after the progress bar
function pdf_search_indexer_settings_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Display any settings errors/notices
    settings_errors('pdf_search_indexer');
    
    // Get indexing status with caching
    global $wpdb;
    $table_name = $wpdb->prefix . 'pdf_search_index';

    // Cache key for statistics
    $cache_key = 'pdf_search_indexer_stats';
    $stats = wp_cache_get($cache_key, 'pdf_search_indexer');
    
    if (false === $stats) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Counting queries for statistics
        $total_pdfs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'application/pdf'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query for statistics
        $indexed_pdfs = $wpdb->get_var("SELECT COUNT(*) FROM `" . esc_sql($table_name) . "`");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query for statistics
        $secured_pdfs = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `" . esc_sql($table_name) . "` WHERE indexed_content LIKE %s", '%password-protected or secured%'));
        
        $stats = array(
            'total_pdfs' => $total_pdfs,
            'indexed_pdfs' => $indexed_pdfs,
            'secured_pdfs' => $secured_pdfs
        );
        
        // Cache for 5 minutes
        wp_cache_set($cache_key, $stats, 'pdf_search_indexer', 300);
    } else {
        $total_pdfs = $stats['total_pdfs'];
        $indexed_pdfs = $stats['indexed_pdfs'];
        $secured_pdfs = $stats['secured_pdfs'];
    }
    $pending_pdfs = $total_pdfs - $indexed_pdfs;

    // Since we are not tracking 'processing' status in the same way, we can estimate it
    $next_scheduled = wp_next_scheduled('pdf_search_indexer_batch_process');
    $processing_count = $next_scheduled ? 1 : 0;
    
    // Calculate progress percentage
    $total_to_process = $indexed_pdfs + $secured_pdfs + $processing_count + $pending_pdfs;
    $progress_percentage = ($total_to_process > 0) ? round(($indexed_pdfs + $secured_pdfs) / $total_to_process * 100) : 0;
    
    // Check if there's a scheduled batch process
    $next_scheduled = wp_next_scheduled('pdf_search_indexer_batch_process');
    // In the process_status variable definition
    $process_status = $next_scheduled ? 'Active (next batch at ' . gmdate('H:i:s', $next_scheduled) . ')' : 'Inactive';
    
    // Fix these lines that are causing the syntax error - they need to be inside PHP tags
    ?>
    <div class="wrap">
        <h1>PDF Search Indexer Settings</h1>
        <form method="post" action="">
    <?php
    wp_nonce_field('pdf_search_indexer_migrate_reindex_nonce');
    ?>
    <p>
        <button type="submit" name="pdf_search_indexer_migrate_reindex" class="button button-primary">Clean and Re-index All PDFs</button>
    </p>
    <p class="description">This will delete all existing indexed PDF data from the postmeta table and start a fresh re-indexing process into the new custom table. This is recommended after updating the plugin.</p>
</form>
<hr>
<form action="options.php" method="post">
            <?php
            settings_fields('pdf_search_indexer_options');
            do_settings_sections('pdf-search-indexer');
            submit_button();
            ?>
        </form>
        
        <hr>
        
        <h2>Indexing Status</h2>
        
        <div class="pdf-indexer-progress-container" style="margin-bottom: 20px;">
            <div class="progress-text" style="margin-bottom: 5px;">Overall Progress: <?php echo esc_html($progress_percentage); ?>% Complete</div>
            <div style="background-color: #e5e5e5; height: 20px; border-radius: 3px; overflow: hidden;">
                <div class="progress-bar" style="background-color: #0073aa; height: 100%; width: <?php echo esc_attr($progress_percentage); ?>%;"></div>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <p>Total PDFs: <span id="total-pdfs"><?php echo esc_html($total_pdfs); ?></span></p>
                <p>Indexed PDFs: <span id="indexed-pdfs"><?php echo esc_html($indexed_pdfs); ?></span></p>
                <p>Secured PDFs (cannot be indexed): <?php echo esc_html($secured_pdfs); ?></p>
                <p>Currently Processing: <?php echo esc_html($processing_count); ?></p>
                <p>Pending PDFs: <?php echo esc_html($pending_pdfs); ?></p>
                <p>Background Process: <span id="process-status"><?php echo esc_html($process_status); ?></span></p>
                
                <?php if ($next_scheduled): ?>
                <div style="margin-top: 10px; padding: 8px; background: #e7f7ff; border: 1px solid #b0e0ff; border-radius: 3px;">
                    <p style="margin: 0;"><strong>Processing Status:</strong> Active</p>
                    <p style="margin: 5px 0 0 0;">Next batch scheduled for: <?php echo esc_html(gmdate('H:i:s', $next_scheduled)); ?></p>
                    
                    <?php 
                    // Get progress data
                    $progress = get_option('pdf_search_indexer_progress', array(
                        'current_file' => '',
                        'started_at' => '',
                        'last_update' => '',
                        'processed_count' => 0,
                        'total_count' => 0,
                        'batch_number' => 0
                    ));
                    
                    // Show batch information
                    if ($progress['batch_number'] > 0): 
                    ?>
                    <p style="margin: 5px 0 0 0;">Current batch: <?php echo esc_html($progress['batch_number']); ?></p>
                    <p style="margin: 5px 0 0 0;">Processed: <?php echo esc_html($progress['processed_count']); ?> of <?php echo esc_html($progress['total_count']); ?> files</p>
                    <p style="margin: 5px 0 0 0;">Started at: <?php echo esc_html($progress['started_at']); ?></p>
                    <p style="margin: 5px 0 0 0;">Last activity: <?php echo esc_html($progress['last_update']); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div>
                <h3>Processing Details</h3>
                
                <?php
                // Show currently processing file
                $current_file = !empty($progress['current_file']) ? $progress['current_file'] : 'None';
                echo '<p><strong>Currently Processing:</strong> <span id="currently-processing">' . esc_html($current_file) . '</span></p>';
                ?>

                <div style="margin-top: 15px;">
                    <h4>Processing Log:</h4>
                    <ul id="processing-log" style="margin-top: 5px; background: #f8f8f8; padding: 10px; border-radius: 3px; max-height: 300px; overflow-y: auto;">
                        <?php
                        if (!empty($progress['log'])) {
                            foreach ($progress['log'] as $log_entry) {
                                $status_label = ucfirst($log_entry['status']);
                                $status_color = ($log_entry['status'] === 'secured') ? '#e27730' : (($log_entry['status'] === 'completed') ? '#46b450' : '#0073aa');
                                echo '<li style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">';
                                echo '<strong>' . esc_html($log_entry['file']) . '</strong><br>';
                                echo '<span style="color: ' . esc_attr($status_color) . '; font-weight: bold;">' . esc_html($status_label) . '</span> - ';
                                echo '<span style="color: #777; font-size: 12px;">' . esc_html(date_i18n('M j, Y g:i a', strtotime($log_entry['timestamp']))) . '</span>';
                                echo '</li>';
                            }
                        } else {
                            echo '<li>No processing activity yet.</li>';
                        }
                        ?>
                    </ul>
                </div>

                <div style="margin-top: 15px;">
                    <h4>Error Log:</h4>
                    <ul id="error-log" style="margin-top: 5px; background: #fff0f0; padding: 10px; border-radius: 3px; max-height: 300px; overflow-y: auto;">
                        <?php
                        if (!empty($progress['errors'])) {
                            foreach ($progress['errors'] as $error_entry) {
                                echo '<li style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #fcc;">';
                                echo '<strong>' . esc_html($error_entry['file']) . '</strong><br>';
                                echo '<span style="color: #d63638; font-weight: bold;">' . esc_html($error_entry['error']) . '</span> - ';
                                echo '<span style="color: #777; font-size: 12px;">' . esc_html(date_i18n('M j, Y g:i a', strtotime($error_entry['timestamp']))) . '</span><br>';
                                echo '<small style="color: #555;">' . esc_html($error_entry['message']) . '</small>';
                                echo '</li>';
                            }
                        } else {
                            echo '<li>No errors recorded.</li>';
                        }
                        ?>
                    </ul>
                </div>
                <?php

                // Get PDFs currently in processing state
                $args = array(
                    'post_type' => 'attachment',
                    'post_mime_type' => 'application/pdf',
                    'posts_per_page' => 5,
                    'post_status' => 'inherit',
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Necessary to query PDFs by processing status for admin display
                    'meta_query' => array(
                        array(
                            'key' => '_pdf_indexing_status',
                            'value' => 'processing',
                            'compare' => '='
                        )
                    )
                );
                
                $processing_pdfs_list = get_posts($args);
                
                if (!empty($processing_pdfs_list)) {
                    echo '<div style="margin-top: 15px;">';
                    echo '<h4>Files Currently Being Processed:</h4>';
                    echo '<ul style="margin-top: 5px; background: #f0f6fc; padding: 10px; border-radius: 3px;">';
                    
                    foreach ($processing_pdfs_list as $pdf) {
                        echo '<li style="margin-bottom: 5px;">';
                        echo '<span style="color: #0073aa;">' . esc_html(basename(get_attached_file($pdf->ID))) . '</span>';
                        echo '</li>';
                    }
                    
                    echo '</ul>';
                    echo '</div>';
                }
                ?>
            </div>
        </div>
        
        <div style="margin-top: 20px;">
            <form method="post" action="">
                <?php wp_nonce_field('pdf_search_indexer_reindex_nonce'); ?>
                <input type="submit" name="pdf_search_indexer_reindex" class="button button-primary" value="Start/Resume PDF Indexing">
            </form>
            
            <?php if ($next_scheduled): ?>
            <form method="post" action="" style="margin-top: 10px;">
                <?php wp_nonce_field('pdf_search_indexer_stop_nonce'); ?>
                <input type="submit" name="pdf_search_indexer_stop" class="button" value="Stop PDF Indexing">
            </form>
            <p>The next batch will start at: <strong><?php echo esc_html(gmdate('H:i:s', $next_scheduled)); ?></strong></p>
            <?php endif; ?>
            
            <form method="post" action="" style="margin-top: 10px;">
                <?php wp_nonce_field('pdf_search_indexer_watchdog_nonce'); ?>
                <input type="submit" name="pdf_search_indexer_watchdog" class="button button-secondary" value="Check/Restart Stalled Process">
            </form>
            <p><em>Use this button if the indexing process appears to be stuck or has stopped unexpectedly.</em></p>
        </div>
    </div>
    <?php
}

// Register the settings page
function pdf_search_indexer_register_settings() {
    register_setting('pdf_search_indexer_options', 'pdf_search_indexer_enable_indexing', array(
        'sanitize_callback' => 'absint'
    ));
    register_setting('pdf_search_indexer_options', 'pdf_search_indexer_max_size', array(
        'sanitize_callback' => 'absint'
    ));
    
    add_settings_section(
        'pdf_search_indexer_settings_section',
        'PDF Indexing Settings',
        'pdf_search_indexer_settings_section_callback',
        'pdf-search-indexer'
    );
    
    add_settings_field(
        'pdf_search_indexer_enable_indexing',
        'Enable PDF Indexing',
        'pdf_search_indexer_enable_indexing_callback',
        'pdf-search-indexer',
        'pdf_search_indexer_settings_section'
    );
    
    add_settings_field(
        'pdf_search_indexer_max_size',
        'Maximum PDF Size (MB)',
        'pdf_search_indexer_max_size_callback',
        'pdf-search-indexer',
        'pdf_search_indexer_settings_section'
    );
}
add_action('admin_init', 'pdf_search_indexer_register_settings');

// Settings section description
function pdf_search_indexer_settings_section_callback() {
    echo '<p>Configure how PDF files are indexed for search.</p>';
}

// Enable indexing field
function pdf_search_indexer_enable_indexing_callback() {
    $value = get_option('pdf_search_indexer_enable_indexing', '1');
    ?>
    <input type="checkbox" id="pdf_search_indexer_enable_indexing" name="pdf_search_indexer_enable_indexing" value="1" <?php checked('1', $value); ?>>
    <label for="pdf_search_indexer_enable_indexing">Automatically index PDF files when uploaded</label>
    <?php
}

// Max size field
function pdf_search_indexer_max_size_callback() {
    $value = get_option('pdf_search_indexer_max_size', '20');
    ?>
    <input type="number" id="pdf_search_indexer_max_size" name="pdf_search_indexer_max_size" value="<?php echo esc_attr($value); ?>" min="1" max="100">
    <p class="description">PDFs larger than this size will be indexed with limited content extraction to avoid memory issues.</p>
    <?php
}

// Add the settings page to the admin menu
function pdf_search_indexer_add_settings_page() {
    add_options_page(
        'PDF Search Indexer Settings',
        'PDF Search Indexer',
        'manage_options',
        'pdf-search-indexer',
        'pdf_search_indexer_settings_html'
    );
}
add_action('admin_menu', 'pdf_search_indexer_add_settings_page');

// Enqueue scripts for the settings page
function pdf_search_indexer_enqueue_scripts($hook) {
    if ($hook !== 'settings_page_pdf-search-indexer') {
        return;
    }
    
    wp_enqueue_script(
        'pdf-search-indexer-admin',
        plugin_dir_url(__FILE__) . 'admin.js',
        array('jquery'),
        '1.0',
        true
    );
    
    wp_localize_script('pdf-search-indexer-admin', 'pdfIndexer', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('pdf_search_indexer_get_status')
    ));
}
add_action('admin_enqueue_scripts', 'pdf_search_indexer_enqueue_scripts');

// Add settings link on plugin page
function pdf_search_indexer_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=pdf-search-indexer">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}
$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'pdf_search_indexer_settings_link');

// AJAX handler to get current status
function pdf_search_indexer_get_status() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }

    $progress = get_option('pdf_search_indexer_progress', array());
    $next_scheduled = wp_next_scheduled('pdf_search_indexer_batch_process');
    $process_status = $next_scheduled ? 'Active (next batch at ' . gmdate('H:i:s', $next_scheduled) . ')' : 'Inactive';

    $response = array(
        'progress' => $progress,
        'process_status' => $process_status
    );

    wp_send_json_success($response);
}
add_action('wp_ajax_pdf_search_indexer_get_status', 'pdf_search_indexer_get_status');

// Register the batch processing hook
add_action('pdf_search_indexer_batch_process', 'index_existing_pdfs');

// Add watchdog function to detect and restart stalled processes
function pdf_search_indexer_watchdog() {
    $progress = get_option('pdf_search_indexer_progress');
    
    // Only check if we think a process should be running
    if (empty($progress['heartbeat']) || $progress['heartbeat'] == 0) {
        return; // No process running
    }
    
    $current_time = time();
    $last_heartbeat = $progress['heartbeat'];
    $stall_threshold = 180; // 3 minutes for faster recovery
    
    // Check if process has stalled (no heartbeat for more than threshold)
    if (($current_time - $last_heartbeat) > $stall_threshold) {
        // Check if there's actually a scheduled event
        $next_scheduled = wp_next_scheduled('pdf_search_indexer_batch_process');
        
        if (!$next_scheduled) {
            // Process has stalled and no event is scheduled - restart it
            pdf_search_indexer_log( 'Watchdog detected stalled process, restarting...', 'notice' );
            
            // Clear any existing scheduled events first
            wp_clear_scheduled_hook('pdf_search_indexer_batch_process');
            
            // Check if there are still PDFs to process
            global $wpdb;
            // Attempt to use object cache to avoid repeated direct queries
            $remaining_cache_key = 'pdf_search_indexer_remaining_id';
            $remaining_id = wp_cache_get( $remaining_cache_key, 'pdf_search_indexer' );
            if ( false === $remaining_id ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only check on core and custom table with object cache
                $remaining_id = (int) $wpdb->get_var(
                    "SELECT p.ID
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->prefix}pdf_search_index i ON i.attachment_id = p.ID
                     WHERE p.post_type = 'attachment' AND p.post_mime_type = 'application/pdf' AND i.attachment_id IS NULL
                     LIMIT 1"
                );
                wp_cache_set( $remaining_cache_key, $remaining_id, 'pdf_search_indexer', 60 );
            }
            
            if ($remaining_id) {
                // Restart the process
                wp_schedule_single_event(time() + 30, 'pdf_search_indexer_batch_process');
                
                // Update progress to indicate restart
                $progress['heartbeat'] = time();
                $progress['last_update'] = current_time('mysql');
                $progress['current_file'] = 'Restarting after stall...';
                
                // Initialize log array if it doesn't exist
                if (!isset($progress['log']) || !is_array($progress['log'])) {
                    $progress['log'] = array();
                }
                
                $log_entry = array(
                    'file' => 'System',
                    'status' => 'Process restarted by watchdog',
                    'timestamp' => current_time('mysql')
                );
                array_unshift($progress['log'], $log_entry);
                
                if (count($progress['log']) > 50) {
                    $progress['log'] = array_slice($progress['log'], 0, 50);
                }
                
                update_option('pdf_search_indexer_progress', $progress);
            } else {
                // No more PDFs to process, clear the heartbeat
                $progress['heartbeat'] = 0;
                $progress['current_file'] = '';
                update_option('pdf_search_indexer_progress', $progress);
            }
        }
    }
}

// Hook watchdog to run on a custom 5-minute interval
add_action('pdf_search_indexer_watchdog', 'pdf_search_indexer_watchdog');

// Schedule watchdog on init so that schedules are registered
function pdf_search_indexer_schedule_watchdog_event() {
    if (!wp_next_scheduled('pdf_search_indexer_watchdog')) {
        wp_schedule_event(time(), 'every_five_minutes', 'pdf_search_indexer_watchdog');
    }
}
add_action('init', 'pdf_search_indexer_schedule_watchdog_event');

// Register activation hook


// Clear scheduled events on plugin deactivation
function pdf_search_indexer_deactivate() {
    wp_clear_scheduled_hook('pdf_search_indexer_batch_process');
    wp_clear_scheduled_hook('pdf_search_indexer_watchdog');
}
register_deactivation_hook(__FILE__, 'pdf_search_indexer_deactivate');

// Add custom cron schedules
function pdf_search_indexer_add_cron_schedules($schedules) {
    // Add a 5-minute schedule for the watchdog
    if (!isset($schedules['every_five_minutes'])) {
        $schedules['every_five_minutes'] = array(
            'interval' => 300,
            'display'  => __('Every 5 Minutes', 'pdf-search-indexer'),
        );
    }
    // Keep the existing 3-day schedule (if used elsewhere)
    if (!isset($schedules['every_three_days'])) {
        $schedules['every_three_days'] = array(
            'interval' => 259200, // 3 days in seconds
            'display' => __('Every 3 Days', 'pdf-search-indexer')
        );
    }
    
    return $schedules;
}
add_filter('cron_schedules', 'pdf_search_indexer_add_cron_schedules');