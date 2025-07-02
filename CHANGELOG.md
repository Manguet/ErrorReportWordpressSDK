# Changelog

All notable changes to the Error Explorer WordPress SDK will be documented in this file.

## [1.0.0] - 2025-06-08

### Added
- Initial release of Error Explorer WordPress SDK
- WordPress plugin for easy installation and configuration
- Automatic error detection and reporting for PHP errors, exceptions, and WordPress-specific errors
- Comprehensive error context capture including:
  - Request data (GET, POST, headers, IP, user agent)
  - Session data (user information, session details)
  - Server data (PHP version, memory usage, server environment)
  - WordPress-specific data (version, theme, plugins, multisite info)
- Breadcrumb system for tracking user actions before errors
- Admin interface for configuration in WordPress Settings
- Test error functionality for verification
- Data sanitization for sensitive information (passwords, tokens, API keys)
- Support for WordPress multisite environments
- Configurable data capture options
- Error fingerprinting for intelligent grouping
- Complete PHPUnit test suite
- Comprehensive documentation and examples

### Security
- Automatic sanitization of sensitive data in request parameters and headers
- Protection against information disclosure in error reports
- Secure webhook URL handling

### WordPress Integration
- Native WordPress plugin architecture
- Admin settings page with user-friendly configuration
- WordPress hooks and actions integration
- Support for WordPress debugging modes
- WooCommerce integration examples
- Theme and plugin integration guides

### Developer Features
- Easy manual error reporting API
- Breadcrumb logging for user journey tracking
- Configurable error handlers
- Environment-aware reporting
- Test project for SDK development and verification