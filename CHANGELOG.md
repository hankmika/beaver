# Changelog

## [2.1.0](https://github.com/hankmika/beaver/compare/d311616...v2.1.0) (2026-11-19)

### Upgrading from 2.0

- **Passwords:** `CONF_USERS` values must be bcrypt hashes. Generate one with `php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"`.
- **Config:** add `define('CONF_MAINT_KEY', '');` to `beaver_config.php` (see `beaver_config.template`). Without it, maintenance mode fails.
- **Apache:** replace `.htaccess` with `Server config/apache.htaccess`. Raw files are now served through `index.php`.
- **nginx:** update your server block from `Server config/nginx_sample.conf`.
- **Markdown:** single line breaks inside a paragraph now join the lines. End a line with two spaces to force a break.

### Security

- Path containment uses `realpath()` boundary checks instead of a bypassable `../` blocklist.
- File and folder names are escaped, and links are absolute and percent-encoded (XSS).
- Passwords are checked with bcrypt instead of SHA-1.
- PHP errors are hidden outside maintenance mode.
- PHP source files and Beaver's own files are never listed or displayed, and files hidden by `CONF_IGNORE` or `.beavercfg` can't be opened as pages.
- `?raw` files are served by `index.php`, so private and maintenance mode apply to them; PHP files return 404 and raw files are sandboxed.

### Features

- `CONF_MAINT_KEY`: preview the site during maintenance with `?key=<value>`.
- Download link for file types that can't be shown inline.
- Markdown: tables, nested and task lists, blockquotes and alerts, fenced and indented code, footnotes, reference links, heading anchors, strikethrough, and a few inline HTML tags.
- Raw files support range and conditional requests (media seeking, browser caching).

### Bug Fixes

- Sorting by date uses full paths.
- `CONF_IGNORE` extensions are hidden from listings.
- Markdown text is no longer escaped twice.
- Removed `FILTER_SANITIZE_STRING`, which no longer exists in PHP 8.

## 2.0.0 (2025-02-21)

- `.beavercfg` per-folder configuration.
- Fixed double URL-encoding in Mobile Safari.
