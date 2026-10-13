# Beaver
Beaver 2.0 is a Gopher-inspired web content browser.

Beaver allows you to list and display files located on your web server in a minimalistic, managed and secure way. It follows the server's directory structure, starting at *CONF_ROOT*, lists any files and directories while allowing the user to view common file types within the application. You can also add custom configuration for each directory by adding a hidden *.beavercfg* file.

## Files

The application itself consists of index.php and beaver_config.php. There are also other support files that can be used based on your configuration and needs.

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

Bypasses default behavior of Apache-based servers and allows Beaver to serve files and folders indirectly in a managed way. 

### Server config / normalize_nfc.sh

Converts Mac OS based UTF-8 NFD filenames to standard/Linux NFC

### Server config / .nginx_sample.conf

Sample configuration file for NGINX-based servers.

---

## .beavercfg

Beaver handles .beavercfg files by default. These are INI-style formatted files that can set up specific settings for their parent folder. Sample .beavercfg file:

```ini
[settings]
sort_by = name  ; Options: name, date
ignored_files = .git, node_modules, secret.txt  ; Ignore specific files/folders
show_dates = true  ; Show last modified date in listing
show_times = true  ; Show last modified date/time in listing
```