=== PDF Search Indexer ===
Contributors: scotthoenes
Donate link: https://liberapay.com/scohoe/
Tags: pdf, search, indexing, documents, media
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.0.1
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

= Support Development =

If you find this plugin useful, please consider supporting its development:

* [Support on Liberapay](https://liberapay.com/scohoe/)
* [Buy me a coffee](https://ko-fi.com/scohoe)
* Bitcoin: bc1qlsn7hdvmcj9s3fydxexu0elmtvxkqmpamz7azx

Your support helps maintain and improve this plugin for the WordPress community!

= Requirements =

* PHP 7.2 or higher
* WordPress 5.0 or higher
* Composer (to install dependencies)

== Installation ==

1. Upload the `pdf-search-indexer` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The plugin will automatically check for required dependencies and display instructions if needed
4. Configure the plugin settings under Settings > PDF Search Indexer

= Manual Installation =

1. Download the plugin zip file
2. Go to WordPress Admin > Plugins > Add New > Upload Plugin
3. Choose the zip file and click Install Now
4. Activate the plugin
5. Go to Settings > PDF Search Indexer to configure

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

= 1.0.0 =
* Initial release
* Automatic PDF text extraction and indexing
* Support for large PDF files with optimized processing
* Password-protected PDF detection
* Batch processing to prevent timeouts
* Admin dashboard for monitoring progress
* WordPress search integration

== Upgrade Notice ==

= 1.0.0 =
Initial release of PDF Search Indexer. Extract and index text from PDF attachments to make them searchable in WordPress.