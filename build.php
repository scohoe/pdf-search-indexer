<?php
/**
 * Build script for PDF Search Indexer
 * 
 * This script creates a distribution-ready version of the plugin
 * with all dependencies included for WordPress Plugin Directory submission.
 *
 * @package PDFSearchIndexer
 */

// Ensure we're running from command line
if ( php_sapi_name() !== 'cli' ) {
	die( 'This script must be run from the command line.' );
}

echo "Building PDF Search Indexer for distribution...\n";

$plugin_dir = __DIR__;
$build_dir = $plugin_dir . '/build';
$dist_dir = $build_dir . '/pdf-search-indexer';

// Clean up previous build
if ( is_dir( $build_dir ) ) {
	echo "Cleaning previous build...\n";
	remove_directory( $build_dir );
}

// Create build directory
mkdir( $build_dir, 0755, true );
mkdir( $dist_dir, 0755, true );

echo "Copying plugin files...\n";

// Files to include in distribution
$files_to_copy = [
	'pdf-search-indexer.php',
	'readme.txt',
	'README.md',
	'LICENSE',
	'uninstall.php',
	'admin.js',
	'composer.json',
	'languages/',
];

// Copy files
foreach ( $files_to_copy as $file ) {
	$source = $plugin_dir . '/' . $file;
	$dest = $dist_dir . '/' . $file;
	
	if ( is_dir( $source ) ) {
		copy_directory( $source, $dest );
	} elseif ( file_exists( $source ) ) {
		copy( $source, $dest );
	}
}

echo "Installing Composer dependencies...\n";

// Install composer dependencies in the build directory
chdir( $dist_dir );
exec( 'composer install --no-dev --optimize-autoloader', $output, $return_var );

if ( $return_var !== 0 ) {
	echo "Error: Failed to install Composer dependencies.\n";
	echo "Make sure Composer is installed and accessible.\n";
	exit( 1 );
}

// Remove composer files from distribution
unlink( $dist_dir . '/composer.json' );
if ( file_exists( $dist_dir . '/composer.lock' ) ) {
	unlink( $dist_dir . '/composer.lock' );
}

echo "Creating distribution archive...\n";

// Create ZIP archive
$zip_file = $build_dir . '/pdf-search-indexer.zip';
$zip = new ZipArchive();

if ( $zip->open( $zip_file, ZipArchive::CREATE ) === TRUE ) {
	add_directory_to_zip( $zip, $dist_dir, 'pdf-search-indexer' );
	$zip->close();
	echo "Distribution created: $zip_file\n";
} else {
	echo "Error: Failed to create ZIP archive.\n";
	exit( 1 );
}

echo "Build completed successfully!\n";
echo "Distribution ready at: $zip_file\n";

/**
 * Recursively copy a directory
 */
function copy_directory( $src, $dst ) {
	$dir = opendir( $src );
	@mkdir( $dst, 0755, true );
	
	while ( false !== ( $file = readdir( $dir ) ) ) {
		if ( ( $file != '.' ) && ( $file != '..' ) ) {
			if ( is_dir( $src . '/' . $file ) ) {
				copy_directory( $src . '/' . $file, $dst . '/' . $file );
			} else {
				copy( $src . '/' . $file, $dst . '/' . $file );
			}
		}
	}
	
	closedir( $dir );
}

/**
 * Recursively remove a directory
 */
function remove_directory( $dir ) {
	if ( is_dir( $dir ) ) {
		$objects = scandir( $dir );
		foreach ( $objects as $object ) {
			if ( $object != '.' && $object != '..' ) {
				if ( is_dir( $dir . '/' . $object ) ) {
					remove_directory( $dir . '/' . $object );
				} else {
					unlink( $dir . '/' . $object );
				}
			}
		}
		rmdir( $dir );
	}
}

/**
 * Add directory to ZIP archive
 */
function add_directory_to_zip( $zip, $dir, $base_dir = '' ) {
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	
	foreach ( $files as $name => $file ) {
		if ( ! $file->isDir() ) {
			$file_path = $file->getRealPath();
			$relative_path = $base_dir . '/' . substr( $file_path, strlen( $dir ) + 1 );
			$zip->addFile( $file_path, $relative_path );
		}
	}
}