<?php
/*
Plugin Name: PDF Search Indexer
Description: A plugin to extract and index text from PDF attachments for WordPress search.
Version: 1.0
Author: Scott Hoenes
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include the PDF Parser library
// Check if vendor directory exists before requiring it
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    // Display admin notice if the library is missing
    function pdf_search_indexer_missing_library_notice() {
        ?>
        <div class="error notice">
            <p><strong>PDF Search Indexer Error:</strong> Required PDF Parser library is missing. Please install it using Composer by running the following commands in the plugin directory:</p>
            <p><code>composer require smalot/pdfparser</code></p>
        </div>
        <?php
    }
    add_action('admin_notices', 'pdf_search_indexer_missing_library_notice');
    return; // Stop plugin execution
}

use Smalot\PdfParser\Parser;

// Function to extract text from PDF
function extract_pdf_text($file_path) {
    // Check file size and use alternative approach for large files
    $max_size = get_option('pdf_search_indexer_max_size', 20); // Default 20MB
    $file_size = filesize($file_path) / (1024 * 1024); // Convert to MB
    
    // Set time limit for processing
    $original_time_limit = ini_get('max_execution_time');
    set_time_limit(300); // 5 minutes
    
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
                $fp = fopen($file_path, 'rb');
                if ($fp) {
                    $header = fread($fp, 1024);
                    fclose($fp);
                    
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
            
            // Check if it's a secured PDF error
            if (strpos($e->getMessage(), 'Secured pdf file are currently not supported') !== false) {
                error_log("PDF Search Indexer: Skipping secured PDF file: $file_path");
                // Restore original time limit
                set_time_limit($original_time_limit);
                $filename = basename($file_path);
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
        $parser = new Parser();
        $pdf = $parser->parseFile($file_path);
        $text = $pdf->getText();
        
        // Restore original time limit
        set_time_limit($original_time_limit);
        
        return $text;
    } catch (Exception $e) {
        error_log("PDF Search Indexer: Error processing file: " . $e->getMessage());
        
        // Check if it's a secured PDF error
        if (strpos($e->getMessage(), 'Secured pdf file are currently not supported') !== false) {
            error_log("PDF Search Indexer: Skipping secured PDF file: $file_path");
            // Restore original time limit
            set_time_limit($original_time_limit);
            $filename = basename($file_path);
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
            
            // Add status indicator
            update_post_meta($attachment->ID, '_pdf_indexing_status', 'processing');
            
            $pdf_text = extract_pdf_text($file_path);
            
            // Store the extracted text in a custom field
            update_post_meta($attachment->ID, '_pdf_content_index', $pdf_text);
            
            // Update status - mark as secured if needed
            if (strpos($pdf_text, 'password-protected or secured') !== false) {
                update_post_meta($attachment->ID, '_pdf_indexing_status', 'secured');
            } else {
                update_post_meta($attachment->ID, '_pdf_indexing_status', 'completed');
            }
            update_post_meta($attachment->ID, '_pdf_indexed_date', current_time('mysql'));
        }
    }
}

// Hook into attachment updates
add_action('add_attachment', 'index_pdf_attachments');
add_action('edit_attachment', 'index_pdf_attachments');

// Hook to index existing PDFs on plugin activation
function pdf_search_indexer_activate() {
    // Don't run indexing on activation anymore
    // Just set up the plugin options
    if (get_option('pdf_search_indexer_enable_indexing') === false) {
        add_option('pdf_search_indexer_enable_indexing', '1');
    }
    if (get_option('pdf_search_indexer_max_size') === false) {
        add_option('pdf_search_indexer_max_size', '20');
    }
}
register_activation_hook(__FILE__, 'pdf_search_indexer_activate');

// Function to index all existing PDFs in the media library
function index_existing_pdfs() {
    // Process PDFs in smaller batches to avoid timeouts
    $args = array(
        'post_type' => 'attachment',
        'post_mime_type' => 'application/pdf',
        'posts_per_page' => 5, // Process only 5 PDFs at a time
        'post_status' => 'inherit',
        'meta_query' => array(
            'relation' => 'OR',
            array(
                'key' => '_pdf_indexing_status',
                'compare' => 'NOT EXISTS'
            ),
            array(
                'key' => '_pdf_indexing_status',
                'value' => 'processing',
                'compare' => '!='
            )
        )
    );

    $pdf_attachments = get_posts($args);
    
    if (!empty($pdf_attachments)) {
        foreach ($pdf_attachments as $attachment) {
            $file_path = get_attached_file($attachment->ID);
            
            // Skip if file doesn't exist
            if (!file_exists($file_path)) {
                continue;
            }
            
            // Add status indicator
            update_post_meta($attachment->ID, '_pdf_indexing_status', 'processing');
            
            $pdf_text = extract_pdf_text($file_path);
            
            // Store the extracted text in a custom field
            update_post_meta($attachment->ID, '_pdf_content_index', $pdf_text);
            
            // Update status - mark as secured if needed
            if (strpos($pdf_text, 'password-protected or secured') !== false) {
                update_post_meta($attachment->ID, '_pdf_indexing_status', 'secured');
            } else {
                update_post_meta($attachment->ID, '_pdf_indexing_status', 'completed');
            }
            update_post_meta($attachment->ID, '_pdf_indexed_date', current_time('mysql'));
        }
        
        // Schedule another batch if there are more PDFs to process
        if (count($pdf_attachments) >= 5) {
            wp_schedule_single_event(time() + 10, 'pdf_search_indexer_batch_process');
        }
    }
}

// Add a hook for the batch processing
add_action('pdf_search_indexer_batch_process', 'index_existing_pdfs');

// Add settings page
function pdf_search_indexer_settings_page() {
    add_options_page(
        'PDF Search Indexer Settings',
        'PDF Search Indexer',
        'manage_options',
        'pdf-search-indexer',
        'pdf_search_indexer_settings_html'
    );
}
add_action('admin_menu', 'pdf_search_indexer_settings_page');

// Register settings
function pdf_search_indexer_register_settings() {
    register_setting('pdf_search_indexer_options', 'pdf_search_indexer_enable_indexing');
    register_setting('pdf_search_indexer_options', 'pdf_search_indexer_max_size');
    
    add_settings_section(
        'pdf_search_indexer_main_section',
        'Main Settings',
        null,
        'pdf-search-indexer'
    );
    
    add_settings_field(
        'pdf_search_indexer_enable_indexing',
        'Enable PDF Indexing',
        'pdf_search_indexer_enable_indexing_html',
        'pdf-search-indexer',
        'pdf_search_indexer_main_section'
    );
    
    add_settings_field(
        'pdf_search_indexer_max_size',
        'Maximum PDF Size (MB)',
        'pdf_search_indexer_max_size_html',
        'pdf-search-indexer',
        'pdf_search_indexer_main_section'
    );
}
add_action('admin_init', 'pdf_search_indexer_register_settings');

// Max size field HTML
function pdf_search_indexer_max_size_html() {
    $setting = get_option('pdf_search_indexer_max_size', 20);
    ?>
    <input type="number" name="pdf_search_indexer_max_size" value="<?php echo esc_attr($setting); ?>" min="1" step="1" />
    <p class="description">PDFs larger than this size (in MB) will use alternative processing with limited content extraction.</p>
    <?php
}

// Enable indexing checkbox HTML
function pdf_search_indexer_enable_indexing_html() {
    $setting = get_option('pdf_search_indexer_enable_indexing');
    ?>
    <input type="checkbox" name="pdf_search_indexer_enable_indexing" value="1" <?php checked(1, $setting); ?> />
    <?php
}

// Settings page HTML
function pdf_search_indexer_settings_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Handle manual reindex request
    if (isset($_POST['pdf_search_indexer_reindex']) && check_admin_referer('pdf_search_indexer_reindex_nonce')) {
        // Mark the first batch of PDFs as "processing" immediately
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'application/pdf',
            'posts_per_page' => 5,
            'post_status' => 'inherit',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_pdf_indexing_status',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => '_pdf_indexing_status',
                    'value' => array('processing', 'completed', 'secured'),
                    'compare' => 'NOT IN'
                )
            )
        );
        
        $pdfs_to_process = get_posts($args);
        
        if (!empty($pdfs_to_process)) {
            foreach ($pdfs_to_process as $pdf) {
                update_post_meta($pdf->ID, '_pdf_indexing_status', 'processing');
            }
        }
        
        // Schedule the first batch to run immediately
        wp_schedule_single_event(time(), 'pdf_search_indexer_batch_process');
        echo '<div class="notice notice-success"><p>PDF reindexing has been initiated in the background. ' . count($pdfs_to_process) . ' PDFs have been queued for processing. This process will continue automatically and may take some time to complete.</p></div>';
    }
    
    // Handle stop processing request
    if (isset($_POST['pdf_search_indexer_stop']) && check_admin_referer('pdf_search_indexer_stop_nonce')) {
        // Clear any scheduled batch processes
        wp_clear_scheduled_hook('pdf_search_indexer_batch_process');
        
        // Reset any PDFs stuck in processing state
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'application/pdf',
            'posts_per_page' => -1,
            'post_status' => 'inherit',
            'meta_query' => array(
                array(
                    'key' => '_pdf_indexing_status',
                    'value' => 'processing',
                    'compare' => '='
                )
            )
        );
        
        $processing_pdfs = get_posts($args);
        $count = count($processing_pdfs);
        
        if (!empty($processing_pdfs)) {
            foreach ($processing_pdfs as $pdf) {
                update_post_meta($pdf->ID, '_pdf_indexing_status', 'pending');
            }
        }
        
        echo '<div class="notice notice-success"><p>PDF indexing has been stopped. ' . $count . ' PDFs that were being processed have been reset to pending status.</p></div>';
    }
    
    // Get indexing status
    $args = array(
        'post_type' => 'attachment',
        'post_mime_type' => 'application/pdf',
        'posts_per_page' => -1,
        'post_status' => 'inherit'
    );
    
    $total_pdfs = count(get_posts($args));
    
    $args = array(
        'post_type' => 'attachment',
        'post_mime_type' => 'application/pdf',
        'posts_per_page' => -1,
        'post_status' => 'inherit',
        'meta_query' => array(
            array(
                'key' => '_pdf_indexing_status',
                'value' => 'completed',
                'compare' => '='
            )
        )
    );
    
    $indexed_pdfs = count(get_posts($args));
    
    $args = array(
        'post_type' => 'attachment',
        'post_mime_type' => 'application/pdf',
        'posts_per_page' => -1,
        'post_status' => 'inherit',
        'meta_query' => array(
            array(
                'key' => '_pdf_indexing_status',
                'value' => 'secured',
                'compare' => '='
            )
        )
    );
    
    $secured_pdfs = count(get_posts($args));
    
    $args = array(
        'post_type' => 'attachment',
        'post_mime_type' => 'application/pdf',
        'posts_per_page' => -1,
        'post_status' => 'inherit',
        'meta_query' => array(
            array(
                'key' => '_pdf_indexing_status',
                'value' => 'processing',
                'compare' => '='
            )
        )
    );
    
    $processing_pdfs = get_posts($args);
    $processing_count = count($processing_pdfs);
    
    // Get pending PDFs (not yet processed)
    $args = array(
        'post_type' => 'attachment',
        'post_mime_type' => 'application/pdf',
        'posts_per_page' => -1,
        'post_status' => 'inherit',
        'meta_query' => array(
            'relation' => 'OR',
            array(
                'key' => '_pdf_indexing_status',
                'compare' => 'NOT EXISTS'
            ),
            array(
                'key' => '_pdf_indexing_status',
                'value' => 'pending',
                'compare' => '='
            )
        )
    );
    
    $pending_pdfs = count(get_posts($args));
    
    // Calculate progress percentage
    $total_to_process = $indexed_pdfs + $secured_pdfs + $processing_count + $pending_pdfs;
    $progress_percentage = ($total_to_process > 0) ? round(($indexed_pdfs + $secured_pdfs) / $total_to_process * 100) : 0;
    
    // Check if there's a scheduled batch process
    $next_scheduled = wp_next_scheduled('pdf_search_indexer_batch_process');
    $process_status = $next_scheduled ? 'Active (next batch at ' . date('H:i:s', $next_scheduled) . ')' : 'Inactive';
    
    ?>
    <div class="wrap">
        <h1>PDF Search Indexer Settings</h1>
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
            <div style="margin-bottom: 5px;">Overall Progress: <?php echo $progress_percentage; ?>% Complete</div>
            <div style="background-color: #e5e5e5; height: 20px; border-radius: 3px; overflow: hidden;">
                <div style="background-color: #0073aa; height: 100%; width: <?php echo $progress_percentage; ?>%;"></div>
            </div>
            <div style="display: flex; justify-content: space-between; margin-top: 5px; font-size: 12px;">
                <div>0%</div>
                <div>50%</div>
                <div>100%</div>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <p>Total PDFs: <?php echo $total_pdfs; ?></p>
                <p>Indexed PDFs: <?php echo $indexed_pdfs; ?></p>
                <p>Secured PDFs (cannot be indexed): <?php echo $secured_pdfs; ?></p>
                <p>Currently Processing: <?php echo $processing_count; ?></p>
                <p>Pending PDFs: <?php echo $pending_pdfs; ?></p>
                <p>Background Process: <?php echo $process_status; ?></p>
            </div>
            
            <?php if ($processing_count > 0): ?>
            <div>
                <h3>Currently Processing:</h3>
                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                    <ul style="margin: 0; padding-left: 20px;">
                    <?php foreach ($processing_pdfs as $pdf): 
                        $file_path = get_attached_file($pdf->ID);
                        $filename = basename($file_path);
                        $last_updated = get_post_meta($pdf->ID, '_pdf_indexed_date', true);
                        $last_updated = $last_updated ? 'Started: ' . $last_updated : 'Recently queued';
                    ?>
                        <li>
                            <strong><?php echo esc_html($filename); ?></strong><br>
                            <span style="font-size: 12px; color: #666;"><?php echo esc_html($last_updated); ?></span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <h2>Manual Controls</h2>
        <div style="display: flex; gap: 20px;">
            <form method="post">
                <?php wp_nonce_field('pdf_search_indexer_reindex_nonce'); ?>
                <p>Start indexing all PDF files in your media library. The process will run in the background.</p>
                <input type="submit" name="pdf_search_indexer_reindex" class="button button-primary" value="Start Indexing">
            </form>
            
            <form method="post">
                <?php wp_nonce_field('pdf_search_indexer_stop_nonce'); ?>
                <p>Stop any currently running indexing processes and reset PDFs stuck in processing state.</p>
                <input type="submit" name="pdf_search_indexer_stop" class="button button-secondary" value="Stop Processing" <?php echo ($processing_pdfs == 0 && !$next_scheduled) ? 'disabled' : ''; ?>>
            </form>
        </div>
        
        <?php if ($processing_count > 0 || $next_scheduled): ?>
        <script>
        // Auto-refresh the page every 30 seconds when processing is active
        setTimeout(function() {
            window.location.reload();
        }, 30000);
        </script>
        <?php endif; ?>
    </div>
    <?php
}

// Modify WordPress search to include PDF content
function custom_search_pdf_content($query) {
    if ($query->is_search() && !is_admin()) {
        $search_term = $query->get('s');

        // Search in the custom field containing PDF text
        $meta_query = array(
            'relation' => 'OR',
            array(
                'key' => '_pdf_content_index',
                'value' => $search_term,
                'compare' => 'LIKE'
            )
        );

        $query->set('meta_query', $meta_query);
    }
    return $query;
}

add_filter('pre_get_posts', 'custom_search_pdf_content');