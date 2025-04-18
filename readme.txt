=== PDF Search Indexer ===
Contributors: scotthoenes
Tags: pdf, search, indexing, documents, media
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Extract and index text from PDF attachments to make them searchable in WordPress.

== Description ==

PDF Search Indexer allows WordPress to search within the content of PDF files uploaded to your media library. When PDFs are uploaded, the plugin automatically extracts the text content and makes it searchable through the standard WordPress search functionality.

= Features =

* Automatically extracts text from PDF files uploaded to the media library
* Indexes PDF content for WordPress search
* Handles large PDF files with optimized processing
* Detects and manages password-protected PDFs
* Provides a dashboard to monitor indexing progress
* Batch processing to prevent timeouts with large libraries

= Requirements =

* PHP 7.2 or higher
* WordPress 5.0 or higher
* Composer (to install dependencies)

== Installation ==

1. Upload the `pdf-search-indexer` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Install the required PDF Parser library by running `composer require smalot/pdfparser` in the plugin directory
4. Configure the plugin settings under Settings > PDF Search Indexer

== Frequently Asked Questions ==

= How does the plugin handle large PDF files? =

For PDFs larger than the maximum size setting, the plugin uses an alternative processing method that extracts metadata and text from only the first few pages to prevent memory issues.

= Can the plugin index password-protected PDFs? =

No, password-protected or secured PDFs cannot be indexed. The plugin will detect these files and mark them accordingly.

= How can I reindex all my PDFs? =

Go to Settings > PDF Search Indexer and click the "Start Indexing" button. The plugin will process PDFs in batches to prevent timeouts.

== Screenshots ==

1. The settings page with indexing controls and progress display

== Changelog ==

= 1.0 =
* Initial release

== Upgrade Notice ==

= 1.0 =
Initial release