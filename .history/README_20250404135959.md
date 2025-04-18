# PDF Search Indexer

A WordPress plugin to extract and index text from PDF attachments for WordPress search.

## Description

PDF Search Indexer allows WordPress to search within the content of PDF files uploaded to your media library. When PDFs are uploaded, the plugin automatically extracts the text content and makes it searchable through the standard WordPress search functionality.

### Features

- Automatically extracts text from PDF files uploaded to the media library
- Indexes PDF content for WordPress search
- Handles large PDF files with optimized processing
- Detects and manages password-protected PDFs
- Provides a dashboard to monitor indexing progress
- Batch processing to prevent timeouts with large libraries

## Installation

1. Upload the `pdf-search-indexer` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Install the required PDF Parser library by running `composer require smalot/pdfparser` in the plugin directory
4. Configure the plugin settings under Settings > PDF Search Indexer

## Requirements

- PHP 7.2 or higher
- WordPress 5.0 or higher
- Composer (to install dependencies)

## Usage

After activation, the plugin will automatically begin indexing PDF files as they are uploaded to your media library. You can also manually trigger indexing of all existing PDFs from the settings page.

### Settings

- **Enable PDF Indexing**: Turn indexing on or off
- **Maximum PDF Size**: Set the maximum size (in MB) for full PDF processing

## Frequently Asked Questions

### How does the plugin handle large PDF files?

For PDFs larger than the maximum size setting, the plugin uses an alternative processing method that extracts metadata and text from only the first few pages to prevent memory issues.

### Can the plugin index password-protected PDFs?

No, password-protected or secured PDFs cannot be indexed. The plugin will detect these files and mark them accordingly.

## Changelog

### 1.0
- Initial release

## License

This plugin is licensed under the GPL v2 or later.