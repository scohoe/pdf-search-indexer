# PowerShell Build Script for PDF Search Indexer
# Creates a distribution-ready version of the plugin

Write-Host "Building PDF Search Indexer for distribution..." -ForegroundColor Green

$pluginDir = Get-Location
$buildDir = Join-Path $pluginDir "build"
$distDir = Join-Path $buildDir "pdf-search-indexer"

# Clean up previous build
if (Test-Path $buildDir) {
    Write-Host "Cleaning previous build..." -ForegroundColor Yellow
    Remove-Item $buildDir -Recurse -Force
}

# Create build directory
New-Item -ItemType Directory -Path $buildDir -Force | Out-Null
New-Item -ItemType Directory -Path $distDir -Force | Out-Null

Write-Host "Copying plugin files..." -ForegroundColor Yellow

# Files to include in distribution
$filesToCopy = @(
    'pdf-search-indexer.php',
    'readme.txt',
    'README.md',
    'LICENSE',
    'uninstall.php',
    'admin.js',
    'languages'
)

# Copy files
foreach ($file in $filesToCopy) {
    $source = Join-Path $pluginDir $file
    $dest = Join-Path $distDir $file
    
    if (Test-Path $source) {
        if (Test-Path $source -PathType Container) {
            # It's a directory
            Copy-Item $source $dest -Recurse -Force
            Write-Host "  Copied directory: $file" -ForegroundColor Gray
        } else {
            # It's a file
            Copy-Item $source $dest -Force
            Write-Host "  Copied file: $file" -ForegroundColor Gray
        }
    } else {
        Write-Host "  Warning: $file not found" -ForegroundColor Red
    }
}

# Check if Composer is available
$composerAvailable = $false
try {
    $null = Get-Command composer -ErrorAction Stop
    $composerAvailable = $true
} catch {
    Write-Host "Composer not found in PATH" -ForegroundColor Red
}

if ($composerAvailable) {
    Write-Host "Installing Composer dependencies..." -ForegroundColor Yellow
    
    # Copy composer.json temporarily
    Copy-Item (Join-Path $pluginDir "composer.json") (Join-Path $distDir "composer.json") -Force
    
    # Install dependencies
    Push-Location $distDir
    try {
        & composer install --no-dev --optimize-autoloader 2>&1 | Write-Host
        
        if ($LASTEXITCODE -eq 0) {
            Write-Host "Dependencies installed successfully" -ForegroundColor Green
            
            # Remove composer files from distribution
            Remove-Item (Join-Path $distDir "composer.json") -Force -ErrorAction SilentlyContinue
            Remove-Item (Join-Path $distDir "composer.lock") -Force -ErrorAction SilentlyContinue
        } else {
            Write-Host "Error: Failed to install Composer dependencies" -ForegroundColor Red
            Pop-Location
            exit 1
        }
    } catch {
        Write-Host "Error running Composer: $_" -ForegroundColor Red
        Pop-Location
        exit 1
    }
    Pop-Location
} else {
    Write-Host "Skipping Composer dependencies (Composer not available)" -ForegroundColor Yellow
    Write-Host "Note: You'll need to manually install dependencies for the plugin to work" -ForegroundColor Yellow
}

# Create ZIP archive
Write-Host "Creating distribution archive..." -ForegroundColor Yellow

$zipFile = Join-Path $buildDir "pdf-search-indexer.zip"

try {
    # Use PowerShell 5.0+ Compress-Archive if available
    if (Get-Command Compress-Archive -ErrorAction SilentlyContinue) {
        Compress-Archive -Path "$distDir\*" -DestinationPath $zipFile -Force
        Write-Host "Distribution created: $zipFile" -ForegroundColor Green
    } else {
        Write-Host "Compress-Archive not available. Please manually create ZIP from: $distDir" -ForegroundColor Yellow
    }
} catch {
    Write-Host "Error creating ZIP archive: $_" -ForegroundColor Red
    Write-Host "Manual ZIP creation required from: $distDir" -ForegroundColor Yellow
}

Write-Host "Build completed!" -ForegroundColor Green
Write-Host "Distribution directory: $distDir" -ForegroundColor Cyan
if (Test-Path $zipFile) {
    Write-Host "Distribution archive: $zipFile" -ForegroundColor Cyan
}

Write-Host "`nNext steps:" -ForegroundColor Yellow
Write-Host "1. Test the plugin from the distribution directory" -ForegroundColor White
Write-Host "2. If Composer dependencies weren't installed, run 'composer install --no-dev' in the distribution directory" -ForegroundColor White
Write-Host "3. Create ZIP archive if not automatically created" -ForegroundColor White
Write-Host "4. Submit to WordPress Plugin Directory" -ForegroundColor White