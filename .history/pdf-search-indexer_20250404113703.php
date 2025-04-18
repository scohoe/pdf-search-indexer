<?php
/*
Plugin Name: PDF Search Indexer
Description: A plugin to extract and index text from PDF attachments for WordPress search.
Version: 1.0
Author: Your Name
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include the PDF Parser library
require __DIR__ . '/vendor/autoload.php';

use Smalot\PdfParser\Parser;

// Function to extract text from PDF
function extract_pdf_text($file_path) {
    // Check file size and skip if too large
    $max_size = get_option('pdf_search_indexer_max_size', 20); // Default 20MB
    $file_size = filesize($file_path) / (1024 * 1024); // Convert to MB
    
    if ($file_size > $max_size) {
        error_log("PDF Search Indexer: Skipping large file ($file_size MB): $file_path");
        return "This file was too large to index. File size: " . round($file_size, 2) . "MB";
    }
    
    // Set time limit for processing
    $original_time_limit = ini_get('max_execution_time');
    set_time_limit(300); // 5 minutes
    
    try {
        $parser = new Parser();
        $pdf = $parser->parseFile($file_path);
        $text = $pdf->getText();
        
        // Restore original time limit
        set_time_limit($original_time_limit);
        
        return $text;
    } catch (Exception $e) {
        error_log("PDF Search Indexer: Error processing file: " . $e->getMessage());
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
            
            // Update status
            update_post_meta($attachment->ID, '_pdf_indexing_status', 'completed');
            update_post_meta($attachment->ID, '_pdf_indexed_date', current_time('mysql'));
        }
    }
}

// Hook into attachment updates
add_action('add_attachment', 'index_pdf_attachments');
add_action('edit_attachment', 'index_pdf_attachments');

// Function to index all existing PDFs in the media library
function index_existing_pdfs() {
    $args = array(
        'post_type' => 'attachment',
        'post_mime_type' => 'application/pdf',
        'posts_per_page' => -1, // Get all PDFs
        'post_status' => 'inherit'
    );

    $pdf_attachments = get_posts($args);

    if (!empty($pdf_attachments)) {
        foreach ($pdf_attachments as $attachment) {
            $file_path = get_attached_file($attachment->ID);
            $pdf_text = extract_pdf_text($file_path);

            // Store the extracted text in a custom field
            update_post_meta($attachment->ID, '_pdf_content_index', $pdf_text);
        }
    }
}

// Hook to index existing PDFs on plugin activation
function pdf_search_indexer_activate() {
    index_existing_pdfs();
}
register_activation_hook(__FILE__, 'pdf_search_indexer_activate');

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

// Settings page HTML - REMOVE THIS FIRST DECLARATION
function pdf_search_indexer_settings_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
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
    </div>
    <?php
}

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

// Max size field HTML
function pdf_search_indexer_max_size_html() {
    $setting = get_option('pdf_search_indexer_max_size', 20);
    ?>
    <input type="number" name="pdf_search_indexer_max_size" value="<?php echo esc_attr($setting); ?>" min="1" step="1" />
    <p class="description">PDFs larger than this size (in MB) will be skipped during indexing.</p>
    <?php
}

// KEEP ONLY THIS VERSION of the settings HTML function
function pdf_search_indexer_settings_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Handle manual reindex request
    if (isset($_POST['pdf_search_indexer_reindex']) && check_admin_referer('pdf_search_indexer_reindex_nonce')) {
        index_existing_pdfs();
        echo '<div class="notice notice-success"><p>PDF reindexing has been initiated.</p></div>';
    }
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
        
        <h2>Manual Reindex</h2>
        <form method="post">
            <?php wp_nonce_field('pdf_search_indexer_reindex_nonce'); ?>
            <p>Use this button to manually reindex all PDF files in your media library.</p>
            <input type="submit" name="pdf_search_indexer_reindex" class="button button-primary" value="Reindex All PDFs">
        </form>
    </div>
    <?php
}
add_action('admin_init', 'pdf_search_indexer_register_settings');

// Enable indexing checkbox HTML
function pdf_search_indexer_enable_indexing_html() {
    $setting = get_option('pdf_search_indexer_enable_indexing');
    ?>
    <input type="checkbox" name="pdf_search_indexer_enable_indexing" value="1" <?php checked(1, $setting); ?> />