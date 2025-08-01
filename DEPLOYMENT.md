# WordPress Plugin Directory Deployment Guide

This guide outlines the steps to prepare and submit the PDF Search Indexer plugin to the WordPress Plugin Directory.

## Pre-Submission Checklist

### ✅ Code Quality
- [x] Follows WordPress Coding Standards
- [x] Proper escaping and sanitization implemented
- [x] No security vulnerabilities
- [x] GPL v2+ license compatibility
- [x] No hardcoded URLs or paths
- [x] Proper error handling

### ✅ Plugin Structure
- [x] Main plugin file with proper header
- [x] readme.txt file following WordPress standards
- [x] Internationalization support (text domain, .pot file)
- [x] Uninstall.php for cleanup
- [x] Proper plugin constants defined
- [x] No external dependencies requiring user installation

### ✅ Documentation
- [x] Comprehensive readme.txt
- [x] Clear installation instructions
- [x] FAQ section
- [x] Changelog
- [x] Screenshots (when applicable)

### ✅ Testing
- [x] Tested with latest WordPress version
- [x] Tested with minimum required WordPress version
- [x] Tested with minimum required PHP version
- [x] No PHP errors or warnings
- [x] Works with common themes
- [x] Multisite compatibility (if applicable)

## Dependency Management

### Current Issue
The plugin currently requires the `smalot/pdfparser` library, which needs to be installed via Composer. The WordPress Plugin Directory doesn't allow plugins that require users to install dependencies manually.

### Solutions

#### Option 1: Bundle Dependencies (Recommended)
1. Run the build script: `php build.php`
2. This creates a distribution version with all dependencies included
3. Submit the built version to WordPress Plugin Directory

#### Option 2: Alternative PDF Parser
Consider switching to a pure PHP PDF parser that doesn't require external dependencies.

#### Option 3: Server-Side Processing
Implement a fallback that uses server-side tools when available (like `pdftotext`).

## Build Process

### Creating Distribution Version

1. **Install Dependencies**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

2. **Run Build Script**
   ```bash
   php build.php
   ```

3. **Verify Build**
   - Check that `build/pdf-search-indexer.zip` is created
   - Verify all dependencies are included in `vendor/` directory
   - Test the built version on a clean WordPress installation

### Manual Build (Alternative)

If the build script doesn't work:

1. Create a new directory: `pdf-search-indexer-dist`
2. Copy these files:
   - `pdf-search-indexer.php`
   - `readme.txt`
   - `README.md`
   - `LICENSE`
   - `uninstall.php`
   - `admin.js`
   - `languages/` directory
3. Install Composer dependencies:
   ```bash
   cd pdf-search-indexer-dist
   composer install --no-dev --optimize-autoloader
   ```
4. Remove development files:
   - `composer.json`
   - `composer.lock`
   - `.gitignore`
   - `build.php`
   - `DEPLOYMENT.md`

## Submission Process

### 1. WordPress.org Account
- Create account at [WordPress.org](https://wordpress.org/)
- Verify email address

### 2. Plugin Submission
1. Go to [Plugin Developer Center](https://wordpress.org/plugins/developers/)
2. Click "Submit Your Plugin"
3. Upload the distribution ZIP file
4. Fill out the submission form:
   - Plugin name: "PDF Search Indexer"
   - Description: Use the description from readme.txt
   - Tags: pdf, search, indexing, documents, media, attachment, file search, content search

### 3. Review Process
- WordPress team will review the plugin (usually 1-14 days)
- They may request changes or ask questions
- Respond promptly to any feedback

### 4. Post-Approval
- Plugin will be available in WordPress Plugin Directory
- Users can install directly from WordPress admin
- Set up SVN repository for future updates

## SVN Repository Management

Once approved, WordPress provides an SVN repository:

### Initial Setup
```bash
svn co https://plugins.svn.wordpress.org/pdf-search-indexer
cd pdf-search-indexer
```

### Directory Structure
- `trunk/` - Development version
- `tags/` - Released versions
- `assets/` - Screenshots, banners, icons

### Releasing Updates
1. Update `trunk/` with new version
2. Update version numbers in:
   - Main plugin file header
   - readme.txt stable tag
   - Constants in code
3. Create new tag:
   ```bash
   svn cp trunk tags/1.0.1
   svn ci -m "Tagging version 1.0.1"
   ```

## Assets for Plugin Directory

### Required
- **Icon**: 256x256px PNG (icon-256x256.png)
- **Banner**: 1544x500px PNG (banner-1544x500.png)

### Optional
- **Banner High-DPI**: 3088x1000px PNG (banner-3088x1000.png)
- **Icon High-DPI**: 512x512px PNG (icon-512x512.png)
- **Screenshots**: Various sizes, referenced in readme.txt

### Asset Guidelines
- Use plugin branding/colors
- High quality, professional appearance
- No copyrighted material
- Follow WordPress design guidelines

## Common Rejection Reasons

1. **Security Issues**
   - Unescaped output
   - SQL injection vulnerabilities
   - Missing nonce verification

2. **Code Quality**
   - Not following WordPress coding standards
   - Using deprecated functions
   - Poor error handling

3. **Licensing Issues**
   - Incompatible licenses
   - Missing license information
   - Bundled proprietary code

4. **Functionality**
   - Plugin doesn't work as described
   - Requires external services without disclosure
   - Conflicts with WordPress core

## Post-Submission Checklist

- [ ] Monitor plugin review status
- [ ] Respond to reviewer feedback promptly
- [ ] Prepare assets (icons, banners) for approval
- [ ] Set up development workflow for future updates
- [ ] Plan marketing and documentation strategy
- [ ] Monitor user feedback and support requests

## Support and Maintenance

After approval:

1. **Monitor Support Forum**
   - Respond to user questions
   - Address bug reports
   - Provide helpful documentation

2. **Regular Updates**
   - WordPress compatibility updates
   - Security patches
   - Feature improvements
   - Bug fixes

3. **Version Management**
   - Semantic versioning (MAJOR.MINOR.PATCH)
   - Proper changelog maintenance
   - Backward compatibility considerations

## Resources

- [WordPress Plugin Developer Handbook](https://developer.wordpress.org/plugins/)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- [Plugin Review Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [SVN Guide](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)