# PDF Search Indexer

A WordPress plugin to extract and index text from PDF attachments for WordPress search.

[![WordPress Plugin Version](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-7.2%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

## Description

PDF Search Indexer allows WordPress to search within the content of PDF files uploaded to your media library. When PDFs are uploaded, the plugin automatically extracts the text content and makes it searchable through the standard WordPress search functionality.

### Features

- 🔍 **Automatic PDF Text Extraction**: Extracts text from PDF files uploaded to the media library
- 🔎 **WordPress Search Integration**: Makes PDF content searchable through standard WordPress search
- 📊 **Large File Handling**: Optimized processing for large PDF files with memory management
- 🔒 **Security Aware**: Detects and manages password-protected PDFs
- 📈 **Progress Monitoring**: Admin dashboard to monitor indexing progress
- ⚡ **Batch Processing**: Prevents timeouts with large media libraries
- 🌐 **Internationalization Ready**: Translation-ready with .pot file included

## Installation

### From WordPress Plugin Directory (Recommended)

1. Go to your WordPress admin panel
2. Navigate to Plugins > Add New
3. Search for "PDF Search Indexer"
4. Install and activate the plugin
5. Configure settings under Settings > PDF Search Indexer

### Manual Installation

1. Download the latest release from the [WordPress Plugin Directory](https://wordpress.org/plugins/pdf-search-indexer/)
2. Upload the `pdf-search-indexer` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure settings under Settings > PDF Search Indexer

### Development Installation

For developers who want to contribute or customize:

1. Clone this repository
2. Run `composer install` to install dependencies
3. Use `php build.php` to create a distribution-ready version

## Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.2 or higher
- **Memory**: Recommended 256MB+ for processing large PDFs
- **Dependencies**: smalot/pdfparser (automatically included in distribution)

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

## Contributing

We welcome contributions to improve PDF Search Indexer! Here's how you can help:

### Development Setup

1. Fork the repository
2. Clone your fork locally
3. Run `composer install` to install dependencies
4. Make your changes
5. Test thoroughly
6. Submit a pull request

### Coding Standards

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- Use proper escaping and sanitization
- Include PHPDoc comments for functions and classes
- Test with multiple PHP and WordPress versions

### Reporting Issues

Please report bugs and feature requests through the [WordPress Plugin Directory support forum](https://wordpress.org/support/plugin/pdf-search-indexer/) or [GitHub Issues](https://github.com/scotthoenes/pdf-search-indexer/issues).

## Support

- **Documentation**: [WordPress Plugin Directory](https://wordpress.org/plugins/pdf-search-indexer/)
- **Support Forum**: [WordPress.org Support](https://wordpress.org/support/plugin/pdf-search-indexer/)
- **Issues**: [GitHub Issues](https://github.com/scotthoenes/pdf-search-indexer/issues)

## Donations

If you find this plugin useful and would like to support its development, consider making a donation:

- **Liberapay**: [Support on Liberapay](https://liberapay.com/scohoe/)
- **Ko-fi**: [Buy me a coffee](https://ko-fi.com/scohoe)
- **Bitcoin**: `bc1qlsn7hdvmcj9s3fydxexu0elmtvxkqmpamz7azx`

Your support helps maintain and improve this plugin for the WordPress community. Thank you! ❤️

## Changelog

### 1.0.0 (2025-01-01)
- Initial release
- Automatic PDF text extraction and indexing
- Support for large PDF files with optimized processing
- Password-protected PDF detection
- Batch processing to prevent timeouts
- Admin dashboard for monitoring progress
- WordPress search integration
- Internationalization support

## License

This plugin is licensed under the [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

```
Copyright (C) 2025 Scott Hoenes

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

## Credits

- **PDF Parser**: [smalot/pdfparser](https://github.com/smalot/pdfparser) - For PDF text extraction
- **WordPress Community**: For the amazing platform and ecosystem