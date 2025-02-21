<?php
// Beaver 2.0
// By Jindrich "Hank" Mika, 2006-2025, hankmika.com

// Get config
require('./beaver_config.php');

// HTTP headers
header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\';');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer-when-downgrade');

// Set up language
if(CONF_LANG == 'auto') {
	$browserLang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
	setLang($browserLang);
}
else setLang(CONF_LANG);

// Maintenance mode on/off
if(MAINTENANCE_MODE) {
	error_reporting(E_ALL);
	ini_set('error_reporting', E_ALL);
	ini_set('display_errors', '1');

	if(empty($_GET["test"])) {
		die(_t('Down for maintenance.'));
	}
	else {
		header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
		header("Cache-Control: post-check=0, pre-check=0", false);
		header("Pragma: no-cache");
	}
}

if(PRIVATE_MODE) {
	// Check if the Authorization header is present and valid
	if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
		// Send a 401 response to prompt the browser for credentials
		header('WWW-Authenticate: Basic realm="Restricted Area"');
		header('HTTP/1.0 401 Unauthorized');
		echo "Authentication required.";
		exit;
	}

	$username = filter_var($_SERVER['PHP_AUTH_USER'], FILTER_SANITIZE_STRING);
	$password = $_SERVER['PHP_AUTH_PW'];

	// Verify the username and password
	if (!isset(CONF_USERS[$username]) || CONF_USERS[$username] !== sha1($password)) {
		// Send a 401 response for invalid credentials
		header('WWW-Authenticate: Basic realm="Restricted Area"');
		header('HTTP/1.0 401 Unauthorized');
		echo "Invalid username or password.";
		exit;
	}
}

// TBD: PRIVATE_MODE

// Set correct language string
function setLang($lng) {
	if(isset(LNG_MATRIX[$lng])) define('LNG', $lng);
	else define('LNG', 'en');
}

// Translate a string using translation matrix
function _t($str) {
	if(isset(LNG_MATRIX[LNG][$str])) return LNG_MATRIX[LNG][$str];
	else return $str;
}

// Path sanitization function
function sanitizePath($path) {
	// Remove any null bytes (to prevent injection attacks)
	$path = str_replace("\0", '', $path);

	// Normalize directory separators to prevent double slashes
	$path = str_replace(['\\', '//'], '/', $path);

	// Allow paths with **diacritics**, but prevent dangerous traversal
	// Replace only **exact matches** of '../' instead of all occurrences
	$path = preg_replace('#(^|/)\.\./#', '', $path);

	// Trim leading and trailing slashes (but keep internal ones)
	$path = trim($path, '/');

	return $path;
}


function renderPath($path) {
	$deconstructedPath = explode('/', $path);
	$renderedPath = '';
	$currentPath = '';

	if(!empty($deconstructedPath[0])) {
		$renderedPath = '/<a href="/">root</a>';

		foreach($deconstructedPath as $item) {
			$currentPath .=  '/' . $item;
			$renderedPath .= '/<a href="' . $currentPath . '">' . $item . '</a>';
		}
	}

	return $renderedPath;
}

function displayFile($filePath) {
	if(!file_exists($filePath)) {
		print _t('File does not exist.');
		return;
	}

	$fileMimeType = mime_content_type($filePath);
	$fileUrl = htmlspecialchars($filePath);

	switch(true) {
		case str_starts_with($fileMimeType, 'image/'):
			// For images (jpeg, png, gif)
			print '<img src="' . $fileUrl . '?raw" alt="' . _t('Image file') . '">';
			break;

		case str_starts_with($fileMimeType, 'text/'):
			// For text files
			$content = htmlspecialchars(file_get_contents($filePath));

			if(getFileExtension($filePath) == 'md') {
				print '<div class="text">' . parseMarkdown($content) . '</div>';
			}
			else print '<pre>' . $content . '</pre>';
			break;

		case str_starts_with($fileMimeType, 'video/'):
			// For video files
			print '<video controls>
					<source src="' . $fileUrl . '?raw" type="' . $fileMimeType . '">
					' . _t('Your browser does not support the video tag.') . '
				  </video>';
			break;

		case str_starts_with($fileMimeType, 'audio/'):
			// For audio files
			print '<audio controls>
					<source src="' . $fileUrl . '?raw" type="' . $fileMimeType . '">
					' . _t('Your browser does not support the audio tag.') . '
				  </audio>';
			break;

		default:
			print _t('Unsupported file type: ') . $fileMimeType;
	}
}

function parseMarkdown($markdownText) {
	$markdownText = htmlspecialchars($markdownText, ENT_QUOTES, 'UTF-8');

	// Parse headings
	$markdownText = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $markdownText);
	$markdownText = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $markdownText);
	$markdownText = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $markdownText);

	// Parse bold text **bold**
	$markdownText = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $markdownText);

	// Parse italic text *italic*
	$markdownText = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $markdownText);

	// Parse links [text](url)
	$markdownText = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $markdownText);

	// Parse unordered lists
	$markdownText = preg_replace_callback('/^(\s*[-*]\s.+?)(\n|$)/ms', function($matches) {
		// Split the list items and wrap them with <ul> and <li>
		$listItems = preg_replace('/^\s*[-*]\s(.+)/m', '<li>$1</li>', $matches[0]);
		return "<ul>$listItems</ul>";
	}, $markdownText);

	// Parse ordered lists
	$markdownText = preg_replace_callback('/^(\s*\d+\.\s.+?)(\n|$)/ms', function($matches) {
		// Split the list items and wrap them with <ol> and <li>
		$listItems = preg_replace('/^\s*\d+\.\s(.+)/m', '<li>$1</li>', $matches[0]);
		return "<ol>$listItems</ol>";
	}, $markdownText);

	// Parse line breaks
	$markdownText = nl2br($markdownText);

	return $markdownText;
}

function getFileExtension($filePath) {
	// Use pathinfo to extract the file extension
	$fileInfo = pathinfo($filePath);
	
	// Return the extension if it exists, or an empty string
	return isset($fileInfo['extension']) ? $fileInfo['extension'] : '';
}

?>

<!DOCTYPE html>
<html>
	<head>
		<base href="<?= CONF_BASE ?>">
		<meta http-equiv="Content-Type" content="text/html; charset=<?= CONF_CHARSET ?>">
		<title><?= CONF_TITLE ?></title>
		
		<!--
		 ____  _____    ___     _______ ____    ____    ___  
		| __ )| ____|  / \ \   / / ____|  _ \  |___ \  / _ \ 
		|  _ \|  _|   / _ \ \ / /|  _| | |_) |   __) || | | |
		| |_) | |___ / ___ \ V / | |___|  _ <   / __/ | |_| |
		|____/|_____/_/   \_\_/  |_____|_| \_\ |_____(_)___/ 
															 
		-->

		<meta name="robots" content="<?= CONF_ROBOTS ?>">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<meta name="format-detection" content="telephone=no">
		<link rel="icon" href="<?= CONF_ICON ?>">

		<style type="text/css">
		html,body{padding:0;margin:0;background-color:<?=CONF_SCHEME['dark_bg'];?>;color:<?=CONF_SCHEME['dark_text'];?>;font-family:Courier,sans-serif}nav{display:inline;word-wrap:break-word}h1{font-size:1.5em;margin:0}h2{font-size:1.2em;margin:0}h3{font-size:1em;margin:0}pre{padding:0;margin:1em 0;font-family:Courier,sans-serif;max-width:100%;width:70em;white-space:pre-wrap;word-wrap:break-word}img,video,audio,div.text{display:block;margin:1em 0;max-width:60em;width:auto;max-height:80vh}a,a:visited{color:<?=CONF_SCHEME['dark_link'];?>}ul{list-style:none;padding:0;margin:1em 0}ul.files a,ul.files a:visited,ul.dirs a,ul.dirs a:visited,nav a,nav a:visited{text-decoration:none}ul.dirs a:hover,ul.files a:hover,nav a:hover{text-decoration:underline}ul.dirs a{font-weight:bold}ul.files a,ul.files a:visited{color:<?=CONF_SCHEME['dark_text'];?>}li{margin:0.2em 0}.wrapper{padding:1em}@media (prefers-color-scheme:light){html,body{background-color:<?=CONF_SCHEME['light_bg'];?>;color:<?=CONF_SCHEME['light_text'];?>}a,a:visited{color:<?=CONF_SCHEME['light_link'];?>}ul.files a,ul.files a:visited{color:<?=CONF_SCHEME['light_text'];?>}}
		</style>
	</head>
	<body>
		<div class="wrapper">
			<?php
			// Get the current path and sanitize
			$path = sanitizePath(urldecode(urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))));

			// Get the current directory
			$systemPath = preg_replace('#/+$#u', '', __DIR__ . CONF_ROOT . $path);

			// Scan the directory to get its contents, apply filter
			if(is_dir($systemPath)) {
				$entries = scandir($systemPath);
				$entries = array_diff($entries, ['.', '..', 'beaver_config.php', 'index.php', '.htaccess', '.beavercfg']);

				print '<strong>' . _t('Index') . (empty($path) ? _t(':') : _t(' of:')) . '</strong> <nav>' . renderPath($path) . '</nav>';

				if(!empty($entries)) {
					// Separate directories and files
					$directories = [];
					$files = [];

					foreach($entries as $entry) {
						if(is_dir($systemPath . '/' . $entry)) {
							$directories[] = $entry;
						} 
						elseif(is_file($systemPath . '/' . $entry)) {
							$files[] = $entry;
						}
					}

					// TBD toggle by .beavercfg setting
					$sortByDate = false;

					// Sorting logic
					if($sortByDate) {
						// Sort by date modified
						usort($directories, function ($a, $b) {
							return filemtime($b) <=> filemtime($a); // Descending order
						});
						usort($files, function ($a, $b) {
							return filemtime($b) <=> filemtime($a); // Descending order
						});
					} 
					else {
						// Sort alphabetically
						sort($directories, SORT_NATURAL | SORT_FLAG_CASE);
						sort($files, SORT_NATURAL | SORT_FLAG_CASE);
					}

					// Print directories
					if(!empty($directories)) {
						print '<ul class="dirs">';

						foreach ($directories as $directory) {
							print '<li><a href="' . $path . '/' . $directory . '">[' . $directory . ']</a></li>' . PHP_EOL;
						}

						print '</ul>';
					}

					// Print files
					if(!empty($files)) {
						print '<ul class="files">';

						foreach ($files as $file) {
							print '<li><a href="' . $path . '/' . $file . '">' . $file . '</a></li>' . PHP_EOL;
						}

						print '</ul>';
					}
				}
				else print '<ul><li>' . _t('Nothing to display.') . '</li></ul>';
			}
			elseif(is_file($systemPath)) {
				print '<strong>' . _t('Display of:') . '</strong> ' . renderPath($path);

				// TBD file display
				displayFile($path);

			}
			else print _t('ERROR: Invalid path.');
			?>
		</div>
	</body>
</html>