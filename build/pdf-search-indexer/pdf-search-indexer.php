<?php
/**
 * Plugin Name: PDF Search Indexer
 * Plugin URI: https://github.com/scotthoenes/pdf-search-indexer
 * Description: Extract and index text from PDF attachments to make them searchable in WordPress.
 * Version: 1.0.0
 * Author: Scott Hoenes
 * Author URI: https://github.com/scotthoenes
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pdf-search-indexer
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.2
 * Network: false
 *
 * @package PDFSearchIndexer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'PDF_SEARCH_INDEXER_VERSION', '1.0.0' );
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

// Load plugin text domain for internationalization
function pdf_search_indexer_load_textdomain() {
	load_plugin_textdomain(
		'pdf-search-indexer',
		false,
		dirname( PDF_SEARCH_INDEXER_PLUGIN_BASENAME ) . '/languages/'
	);
}
add_action( 'plugins_loaded', 'pdf_search_indexer_load_textdomain' );

// Add a timeout function to prevent hanging
if (!function_exists('pdf_search_indexer_timeout_handler')) {
    function pdf_search_indexer_timeout_handler() {
    error_log("PDF Search Indexer: Operation timed out");
    // Restore PHP settings
    ini_set('memory_limit', $GLOBALS['original_memory_limit']);
    set_time_limit($GLOBALS['original_time_limit']);
    die("PDF processing timed out");
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
            error_log("PDF Search Indexer: Memory usage too high ($memory_usage MB / $memory_limit MB) - Aborting");
            return false;
        }
        
        return true;
    }
}

// Function to extract text from PDF
function extract_pdf_text($file_path) {
    // Store original limits globally so timeout handler can access them
    $GLOBALS['original_time_limit'] = ini_get('max_execution_time');
    $GLOBALS['original_memory_limit'] = ini_get('memory_limit');
    
    // Set timeout handler
    set_time_limit(300); // 5 minutes
    
    // Register timeout function with a 4-minute timeout (less than the 5-minute PHP timeout)
    register_shutdown_function('pdf_search_indexer_timeout_handler');
    $timeout = 240; // 4 minutes
    
    // Check file size and use alternative approach for large files
    $max_size = get_option('pdf_search_indexer_max_size', 20); // Default 20MB
    $file_size = filesize($file_path) / (1024 * 1024); // Convert to MB
    
    // Hard limit - skip extremely large files entirely
    $hard_limit = 50; // 50MB
    if ($file_size > $hard_limit) {
        error_log("PDF Search Indexer: Extremely large file detected ($file_size MB): $file_path - Skipping");
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
    
    // Set time limit for processing
    $original_time_limit = ini_get('max_execution_time');
    set_time_limit(300); // 5 minutes
    
    // ADDED: Reduce memory usage for all files
    $original_memory_limit = ini_get('memory_limit');
    
    // For large files, use an even more conservative approach
    if ($file_size > $max_size) {
        error_log("PDF Search Indexer: Large file detected ($file_size MB): $file_path - Using alternative processing");
        
        try {
            // For large files, use a more memory-efficient approach
            $parser = new Parser();
            
            // Only increase memory limit if current usage is lower than target
            $target_memory = 512 * 1024 * 1024; // 512MB in bytes
            $current_memory = memory_get_usage(true);
            $original_memory_limit = ini_get('memory_limit');
            
            if ($current_memory < $target_memory) {
                ini_set('memory_limit', '512M');
            } else {
                error_log("PDF Search Indexer: Memory already at " . round($current_memory / (1024 * 1024)) . "MB, not increasing");
            }
            
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
                        error_log("PDF Search Indexer: Detected secured PDF file: $file_path");
                        set_time_limit($original_time_limit);
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
            
            // Restore original memory limit
            ini_set('memory_limit', $original_memory_limit);
            
            // Restore original time limit
            set_time_limit($original_time_limit);
            
            return $text;
        } catch (Exception $e) {
            error_log("PDF Search Indexer: Error processing large file: " . $e->getMessage());
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
                error_log("PDF Search Indexer: Skipping secured PDF file: $file_path");
                // Restore original time limit
                set_time_limit($original_time_limit);
                return "This PDF is password-protected or secured and cannot be indexed. Filename: $filename";
            }
            
            // Restore original time limit
            set_time_limit($original_time_limit);
            
            // Create a basic searchable entry with filename
            $filename = basename($file_path);
            return "Large PDF file: $filename\nSize: " . round($file_size, 2) . "MB\nThis file was partially indexed due to its size.";
        }
    }
    
    // Normal processing for regular-sized files
    try {
        // Set a reasonable memory limit for regular files too
        ini_set('memory_limit', '256M');
        
        $parser = new Parser();
        $pdf = $parser->parseFile($file_path);
        $text = $pdf->getText();
        
        // Restore original memory limit
        ini_set('memory_limit', $original_memory_limit);
        
        // Restore original time limit
        set_time_limit($original_time_limit);
        
        return $text;
    } catch (Exception $e) {
        // Always restore limits even on error
        ini_set('memory_limit', $original_memory_limit);
        set_time_limit($original_time_limit);
        
        error_log("PDF Search Indexer: Error processing file: " . $e->getMessage());
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
            error_log("PDF Search Indexer: Skipping secured PDF file: $file_path");
            // Restore original time limit
            set_time_limit($original_time_limit);
            return "This PDF is password-protected or secured and cannot be indexed. Filename: $filename";
        }
        
        // Restore original time limit
        set_time_limit($original_time_limit);
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
            $wpdb->replace($table_name, array(
                'attachment_id' => $attachment->ID,
                'indexed_content' => $pdf_text
            ));
            
            
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

// Hook to create table on plugin activation
register_activation_hook(__FILE__, 'pdf_search_indexer_create_table');

// Hook to index existing PDFs on plugin activation
// After the plugin header, add this new option during activation
function pdf_search_indexer_activate() {
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
    // Get current progress data
    $progress = get_option('pdf_search_indexer_progress', array(
        'current_file' => '',
        'started_at' => '',
        'last_update' => '',
        'processed_count' => 0,
        'total_count' => 0,
        'batch_number' => 0,
        'consecutive_errors' => 0 // Add error tracking
    ));
    
    // Safety check - if too many consecutive batches with errors
    if (isset($progress['consecutive_errors']) && $progress['consecutive_errors'] > 5) {
        error_log("PDF Search Indexer: Too many consecutive errors, stopping batch processing");
        // Reset error counter but don't schedule next batch
        $progress['consecutive_errors'] = 0;
        update_option('pdf_search_indexer_progress', $progress);
        return;
    }
    
    // Update batch number
    $progress['batch_number']++;
    $progress['last_update'] = current_time('mysql');
    
    // If this is the first batch, set the start time and count total PDFs
    if ($progress['batch_number'] == 1 || empty($progress['started_at'])) {
        $progress['started_at'] = current_time('mysql');
        $progress['processed_count'] = 0; // Reset processed count
        
        // Count total PDFs and already indexed PDFs
        global $wpdb;
        $total_pdfs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'application/pdf'");
        $indexed_pdfs_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pdf_search_index");

        $progress['total_count'] = $total_pdfs;
        $progress['processed_count'] = $indexed_pdfs_count;
    }
    
    update_option('pdf_search_indexer_progress', $progress);
    
    // Process PDFs in smaller batches to avoid timeouts
    // Query for PDFs that are not in the custom index table
    global $wpdb;
    $indexed_pdf_ids = $wpdb->get_col("SELECT attachment_id FROM {$wpdb->prefix}pdf_search_index");

    $args = array(
        'post_type' => 'attachment',
        'post_mime_type' => 'application/pdf',
        'posts_per_page' => 1, // Process just one PDF at a time
        'post_status' => 'inherit',
        'post__not_in' => !empty($indexed_pdf_ids) ? $indexed_pdf_ids : array(0), // Use post__not_in to exclude indexed PDFs
    );

    $pdf_attachments = get_posts($args);
    
    if (!empty($pdf_attachments)) {
        foreach ($pdf_attachments as $attachment) {
            $file_path = get_attached_file($attachment->ID);
            
            // Skip if file doesn't exist
            if (!file_exists($file_path)) {
                continue;
            }
            
            // Update progress with current file
            $progress = get_option('pdf_search_indexer_progress');
            $progress['current_file'] = basename($file_path);
            $progress['last_update'] = current_time('mysql');
            update_option('pdf_search_indexer_progress', $progress);
            
            // Add status indicator
            update_post_meta($attachment->ID, '_pdf_indexing_status', 'processing');
            
            $pdf_text = extract_pdf_text($file_path);
            
            // Store the extracted text in the custom table
            global $wpdb;
            $table_name = $wpdb->prefix . 'pdf_search_index';
            $wpdb->replace($table_name, array(
                'attachment_id' => $attachment->ID,
                'indexed_content' => $pdf_text
            ));
            
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

            $log_entry = array(
                'file' => basename($file_path),
                'status' => get_post_meta($attachment->ID, '_pdf_indexing_status', true),
                'timestamp' => current_time('mysql')
            );

            // Add to the beginning of the log
            array_unshift($progress['log'], $log_entry);

            // Keep the log to a reasonable size (e.g., last 50 entries)
            if (count($progress['log']) > 50) {
                $progress['log'] = array_slice($progress['log'], 0, 50);
            }

            update_option('pdf_search_indexer_progress', $progress);
            
            // ADDED: Force garbage collection after each PDF to free memory
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        // Schedule another batch if there are more PDFs to process
        // MODIFIED: Increase delay between batches to 60 seconds instead of 30
        if (count($pdf_attachments) >= 1) {
            wp_schedule_single_event(time() + 60, 'pdf_search_indexer_batch_process');
        } else {
            // Reset progress when done
            $progress = get_option('pdf_search_indexer_progress');
            $progress['current_file'] = '';
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
        $wpdb->query("TRUNCATE TABLE $table_name");

        // 1.a. Clear out the old post_content from attachments to free up space
        $wpdb->query("UPDATE {$wpdb->posts} SET post_content = '' WHERE post_type = 'attachment' AND post_mime_type = 'application/pdf'");

        // 2. Delete old post meta data
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_pdf_content_index'");

        // 3. Reset indexing status for all PDFs
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_pdf_indexing_status'");

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
    
    // Get indexing status
    global $wpdb;
    $table_name = $wpdb->prefix . 'pdf_search_index';

    $total_pdfs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'application/pdf'");
    $indexed_pdfs = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $secured_pdfs = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE indexed_content LIKE '%password-protected or secured%'");
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
        </div>
    </div>
    <?php
}

// Register the settings page
function pdf_search_indexer_register_settings() {
    register_setting('pdf_search_indexer_options', 'pdf_search_indexer_enable_indexing');
    register_setting('pdf_search_indexer_options', 'pdf_search_indexer_max_size');
    
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

// Register activation hook
register_activation_hook(__FILE__, 'pdf_search_indexer_activate');

// Clear scheduled events on plugin deactivation
function pdf_search_indexer_deactivate() {
    wp_clear_scheduled_hook('pdf_search_indexer_batch_process');
}
register_deactivation_hook(__FILE__, 'pdf_search_indexer_deactivate');

// Add custom cron schedule for Greenshift plugin
function pdf_search_indexer_add_cron_schedules($schedules) {
    // Add a 'every_three_days' schedule if it doesn't exist
    if (!isset($schedules['every_three_days'])) {
        $schedules['every_three_days'] = array(
            'interval' => 259200, // 3 days in seconds
            'display' => __('Every 3 Days')
        );
    }
    
    return $schedules;
}
add_filter('cron_schedules', 'pdf_search_indexer_add_cron_schedules');
