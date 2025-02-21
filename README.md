# Beaver
Beaver 2.0 is a Gopher-inspired web content browser.

Beaver allows you to list and display files located on your web server in a minimalistic, managed and secure way. It follows the server's directory structure, starting at *CONF_ROOT*, lists any files and directories while allowing the user to view common file types within the application. You can also add custom configuration for each directory by adding a hidden *.beavercfg* file.

## Files

The application itself consists of index.php and beaver_config.php. There are also other support files that can be used based on your configuration and needs.

### index.php

Main logic and HTML/CSS template. Single file is used to minimize clutter.

### beaver_config.php

Contains app configuration constant definitions;

- **MAINTENANCE_MODE** (true/false): Maintenance mode disables cache, enables error reporting and locks the website for unauthenticated users.
- **PRIVATE_MODE** (true/false): Locks the website and requires user authentication to view content.
- **CONF_ROOT** (path relative to index.php location; default: /): Sets up the root directory.
- **CONF_USEGCFG** (true/false): Toggles the use of .gophercfg files.
- **CONF_LANG** (language code string; default: auto): Selects language by language code string. Default is "auto" which selects language based on browser settings if provided, if not, English is selected.
- **CONF_BASE** (URL): Full HTTP address of the website.
- **CONF_SCHEME** (color scheme array): Sets up colors for the website in light and dark mode. Default: 'light_bg' => '#E8DDCB', 'light_text' => '#033649', 'light_link' => '#031634', 'dark_bg' => '#131313', 'dark_text' => '#5cc147', 'dark_link' => '#7aff5f'
- **CONF_IGNORE** (file extension list): All file extensions listed here will not be listed. Default: 'php', 'phtml', 'php5', 'php6', 'html', 'css'
- **CONF_CHARSET** (charset string): Character set to be used by the website.
- **CONF_TITLE** (title string): HTML title to be used by the website.
- **CONF_ROBOTS** (robots string): HTML robots meta setting. Default: index,follow
- **CONF_USERS** (users array): Allowed usernames and passwords required if PRIVATE_MODE or MAINTENANCE_MODE is on. Passwords are sha1 encoded. Default: 'root' => '0beec7b5ea3f0fdbc95d0dd47f3c5bc275da8a33' (password is foo)

### .beavercfg

This file can be located in any folder to manage Beaver's behavior while listing it.

### Server config / apache.htaccess

Bypasses default behavior of Apache-based servers and allows Beaver to serve files and folders indirectly in a managed way. 

### Server config / normalize_nfc.sh

Converts Mac OS based UTF-8 NFD filenames to standard/Linux NFC

### Server config / .nginx_sample.conf

Sample configuration file for NGINX-based servers.
