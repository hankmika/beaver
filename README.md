# Beaver
Beaver is a Gopher-inspired web content browser. See [CHANGELOG.md](CHANGELOG.md) for versions and upgrade notes.

Beaver allows you to list and display files located on your web server in a minimalistic, managed and secure way. It follows the server's directory structure, starting at *CONF_ROOT*, lists any files and directories while allowing the user to view common file types within the application. You can also add custom configuration for each directory by adding a hidden *.beavercfg* file.

## Installation

1. Copy `beaver/index.php` into the folder you want Beaver to serve.
2. Copy `beaver/beaver_config.template` next to it as `beaver_config.php` and fill it in (see [beaver_config.php](#beaver_configphp)).
3. **Set up your web server. This step is required.** Beaver only works when every request is passed to `index.php`. Without it, the server shows files directly, past Beaver's checks (hidden files, private mode, maintenance mode).
   - **Apache:** copy `Server config/apache.htaccess` next to `index.php` as `.htaccess`. Needs `mod_rewrite` and an `AllowOverride` that permits rewrite rules.
   - **nginx:** add the `location` blocks from `Server config/nginx_sample.conf` to your server block, adjusting the PHP-FPM address.

## Files

The application itself consists of `index.php` and `beaver_config.php`, found in the `beaver/` folder (the config as `beaver_config.template`). Web server configuration lives in `Server config/`.

### index.php

Main logic and HTML/CSS template. Single file is used to minimize clutter.

### beaver_config.php

Contains app configuration constant definitions;

- **MAINTENANCE_MODE** (true/false): Maintenance mode enables error reporting and locks the website behind a "Down for maintenance" page. The site can still be previewed by appending `?key=<CONF_MAINT_KEY>` to the URL (see CONF_MAINT_KEY).
- **CONF_MAINT_KEY** (string; default: empty): Secret used to preview the site while MAINTENANCE_MODE is on, via `?key=<value>`. When empty, no bypass is possible and the site is fully locked.
- **PRIVATE_MODE** (true/false): Locks the website and requires user authentication to view content.
- **CONF_ROOT** (path relative to index.php location; default: /): Sets up the root directory.
- **CONF_USEGCFG** (true/false): Toggles the use of .beavercfg files.
- **CONF_LANG** (language code string; default: auto): Selects language by language code string. Default is "auto" which selects language based on browser settings if provided, if not, English is selected.
- **CONF_BASE** (URL): Full HTTP address of the website.
- **CONF_SCHEME** (color scheme array): Sets up colors for the website in light and dark mode. Default: 'light_bg' => '#E8DDCB', 'light_text' => '#033649', 'light_link' => '#031634', 'dark_bg' => '#131313', 'dark_text' => '#5cc147', 'dark_link' => '#7aff5f'
- **CONF_IGNORE** (file extension list): All file extensions listed here will not be listed. Default: 'php', 'phtml', 'php5', 'php6', 'html', 'css'
- **CONF_CHARSET** (charset string): Character set to be used by the website.
- **CONF_TITLE** (title string): HTML title to be used by the website.
- **CONF_ROBOTS** (robots string): HTML robots meta setting. Default: index,follow
- **CONF_USERS** (users array): Allowed usernames mapped to bcrypt password hashes, required if PRIVATE_MODE is on. Generate a hash with `php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"`. Default: empty (e.g. 'root' => '$2y$10$...').

### Server config / apache.htaccess

Required on Apache, deployed as `.htaccess` next to `index.php` (see [Installation](#installation)). Passes every request to `index.php` so Beaver serves all files and folders in a managed way.

### Server config / nginx_sample.conf

Required on nginx, as part of your server block (see [Installation](#installation)). Sample configuration that passes every request to `index.php`.

### Server config / normalize_nfc.sh

Converts Mac OS based UTF-8 NFD filenames to standard/Linux NFC

---

## .beavercfg

Beaver handles .beavercfg files by default. These are INI-style formatted files that can set up specific settings for their parent folder. Sample .beavercfg file:

```ini
[settings]
sort_by = name  ; Options: name, date
ignored_files = .git, node_modules, notes.txt  ; Ignore specific files/folders
show_dates = true  ; Show last modified date in listing
show_times = true  ; Show last modified date/time in listing
```

Ignored files and folders are left out of listings, but ignored folders can still be browsed by URL and ignored files can still be fetched with `?raw`. Don't rely on `ignored_files` to keep anything secret.