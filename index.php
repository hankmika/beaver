<?php
// Beaver 2.0
// By Jindrich "Hank" Mika, 2006-2025, hankmika.com

// Get config
require __DIR__ . '/beaver_config.php';

// Avoid leaking paths via PHP notices in production (maintenance mode re-enables below)
ini_set('display_errors', '0');

// HTTP headers
header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\';');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer-when-downgrade');

// Set up language
if (CONF_LANG == 'auto') {
	$browserLang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) : 'en';
	setLang($browserLang);
}
else setLang(CONF_LANG);

// Maintenance mode on/off
if (MAINTENANCE_MODE) {
	error_reporting(E_ALL);
	ini_set('error_reporting', E_ALL);
	ini_set('display_errors', '1');

	// Bypass requires a non-empty secret from config that matches ?key= (constant-time)
	$bypass = CONF_MAINT_KEY !== '' && isset($_GET['key']) && hash_equals(CONF_MAINT_KEY, (string)$_GET['key']);

	if (!$bypass) {
		die(_t('Down for maintenance.'));
	}
	else {
		header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
		header("Cache-Control: post-check=0, pre-check=0", false);
		header("Pragma: no-cache");
	}
}

if (PRIVATE_MODE) {
	// Check if the Authorization header is present and valid
	if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
		// Send a 401 response to prompt the browser for credentials
		header('WWW-Authenticate: Basic realm="Restricted Area"');
		header('HTTP/1.0 401 Unauthorized');
		echo "Authentication required.";
		exit;
	}

	$username = $_SERVER['PHP_AUTH_USER'];
	$password = $_SERVER['PHP_AUTH_PW'];

	// Verify the username and password (bcrypt via password_verify; dummy hash resists user enumeration)
	$hash = CONF_USERS[$username] ?? '$2y$10$usesomesillystringforsalt1uHfjkP9i.qp1234567890abcdefghi.';
	if (!password_verify($password, $hash) || !isset(CONF_USERS[$username])) {
		// Send a 401 response for invalid credentials
		header('WWW-Authenticate: Basic realm="Restricted Area"');
		header('HTTP/1.0 401 Unauthorized');
		echo "Invalid username or password.";
		exit;
	}
}

// Set correct language string
function setLang($lng) {
	if (isset(LNG_MATRIX[$lng])) define('LNG', $lng);
	else define('LNG', 'en');
}

// Translate a string using translation matrix
function _t($str) {
	if (isset(LNG_MATRIX[LNG][$str])) return LNG_MATRIX[LNG][$str];
	else return $str;
}

// Light path normalization. NOTE: traversal containment is enforced by the realpath()
// boundary check at the call site, not here — this only tidies the relative path.
function sanitizePath($path) {
	// Remove any null bytes (to prevent injection attacks)
	$path = str_replace("\0", '', $path);

	// Normalize directory separators and collapse double slashes
	$path = str_replace(['\\', '//'], '/', $path);

	// Drop leading ./ and ../ segments (cosmetic; realpath is authoritative)
	$path = preg_replace('#(^|/)\.\./#', '', $path);

	// Trim leading and trailing slashes (but keep internal ones)
	$path = trim($path, '/');

	return $path;
}


// Percent-encode each segment of a relative path to build a safe URL
function encodePathUrl($path) {
	if ($path === '') return '';
	return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function renderPath($path) {
	$deconstructedPath = explode('/', $path);
	$renderedPath = '';
	$currentPath = '';

	if (!empty($deconstructedPath[0])) {
		$renderedPath = '/<a href="/">root</a>';

		foreach($deconstructedPath as $item) {
			$currentPath .= '/' . $item;
			$renderedPath .= '/<a href="' . htmlspecialchars('/' . encodePathUrl(ltrim($currentPath, '/')), ENT_QUOTES) . '">' . htmlspecialchars($item, ENT_QUOTES) . '</a>';
		}
	}

	return $renderedPath;
}

function displayFile($fsPath, $urlPath) {
	if (!file_exists($fsPath)) {
		print _t('File does not exist.');
		return;
	}

	$fileMimeType = mime_content_type($fsPath);
	$fileUrl = htmlspecialchars('/' . encodePathUrl($urlPath), ENT_QUOTES);

	switch(true) {
		case str_starts_with($fileMimeType, 'image/'):
			// For images (jpeg, png, gif)
			print '<img src="' . $fileUrl . '?raw" alt="' . _t('Image file') . '">';
			break;

		case str_starts_with($fileMimeType, 'text/'):
			// For text files
			$content = file_get_contents($fsPath);

			if (in_array(strtolower(getFileExtension($fsPath)), ['md', 'markdown'], true)) {
				print '<div class="text">' . parseMarkdown($content, $urlPath) . '</div>';
			}
			else print '<pre>' . htmlspecialchars($content) . '</pre>';
			break;

		case str_starts_with($fileMimeType, 'video/'):
			// For video files
			print '<video controls>
					<source src="' . $fileUrl . '?raw" type="' . htmlspecialchars($fileMimeType, ENT_QUOTES) . '">
					' . _t('Your browser does not support the video tag.') . '
				  </video>';
			break;

		case str_starts_with($fileMimeType, 'audio/'):
			// For audio files
			print '<audio controls>
					<source src="' . $fileUrl . '?raw" type="' . htmlspecialchars($fileMimeType, ENT_QUOTES) . '">
					' . _t('Your browser does not support the audio tag.') . '
				  </audio>';
			break;

		default:
			// Not renderable inline — offer the raw file for download
			print _t('Unsupported file type: ') . htmlspecialchars($fileMimeType) . '<br><a href="' . $fileUrl . '?raw">' . _t('Download') . '</a>';
	}
}

// Markdown (CommonMark + GitHub extensions) to HTML. Raw HTML is escaped, except a few harmless inline tags.
function parseMarkdown($markdownText, $urlPath = '') {
	$dir = dirname($urlPath);
	$md = ['page' => '/' . encodePathUrl($urlPath), 'dir' => $dir === '.' ? '' : $dir, 'refs' => [], 'notes' => [], 'noteOrder' => [], 'slugs' => [], 'stash' => []];

	$text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], preg_replace('/^\xEF\xBB\xBF/', '', $markdownText));

	// Expand leading tabs to 4-column stops
	$text = preg_replace_callback('/^[ \t]+/m', function($m) {
		$out = '';
		foreach (str_split($m[0]) as $c) $out .= $c === "\t" ? str_repeat(' ', 4 - strlen($out) % 4) : $c;
		return $out;
	}, $text);

	// Collect link reference and footnote definitions (outside fenced code)
	$lines = explode("\n", $text);
	$body = [];
	$fence = '';
	for ($i = 0, $n = count($lines); $i < $n; $i++) {
		$line = $lines[$i];
		if ($fence !== '') {
			if (preg_match('/^ {0,3}' . preg_quote($fence, '/') . '+\s*$/', $line)) $fence = '';
		}
		elseif (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) $fence = $m[1];
		elseif (preg_match('/^ {0,3}\[\^([^\]\s]+)\]:[ \t]?(.*)$/', $line, $m)) {
			$note = [$m[2]];
			while ($i + 1 < $n) {
				$next = $lines[$i + 1];
				if (preg_match('/^ {4}/', $next)) $note[] = substr($next, 4);
				elseif (trim($next) === '' && isset($lines[$i + 2]) && preg_match('/^ {4}/', $lines[$i + 2])) $note[] = '';
				elseif (trim($next) !== '' && trim(end($note)) !== '' && !mdIsBlockStart($next) && !preg_match('/^ {0,3}\[[^\]]+\]:/', $next)) $note[] = $next;
				else break;
				$i++;
			}
			$md['notes'][mb_strtolower($m[1])] = $note;
			continue;
		}
		elseif (preg_match('/^ {0,3}\[([^\]]+)\]:\s*<?([^\s>]+)>?(?:\s+(?:"([^"]*)"|\'([^\']*)\'|\(([^)]*)\)))?\s*$/', $line, $m)) {
			$md['refs'][mdRefKey($m[1])] ??= [$m[2], ($m[3] ?? '') . ($m[4] ?? '') . ($m[5] ?? '')];
			continue;
		}
		$body[] = $line;
	}

	$html = mdBlocks($body, $md);

	// Footnotes, in order of first reference
	if (!empty($md['noteOrder'])) {
		$html .= '<section class="footnotes"><hr><ol>';
		for ($k = 0; $k < count($md['noteOrder']); $k++) {
			$num = $k + 1;
			$html .= '<li id="fn-' . $num . '">' . mdBlocks($md['notes'][$md['noteOrder'][$k]], $md, true) . ' <a href="' . $md['page'] . '#fnref-' . $num . '">&#8617;&#xFE0E;</a></li>';
		}
		$html .= '</ol></section>';
	}

	return $html;
}

function mdRefKey($label) {
	return mb_strtolower(preg_replace('/\s+/', ' ', trim($label)));
}

function mdIsHr($line) {
	return (bool)preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $line);
}

function mdListMarker($line) {
	if (!preg_match('/^( {0,3})([-*+]|(\d{1,9})[.)])( +|$)(.*)$/', $line, $m) || mdIsHr($line)) return null;

	// Content starts after the marker and up to 4 spaces; more means indented code inside the item
	$spaces = strlen($m[4]);
	$content = $spaces > 4 ? substr($m[4], 1) . $m[5] : $m[5];
	if ($spaces === 0 || $spaces > 4) $spaces = 1;

	return [
		'type' => $m[3] !== '' ? substr($m[2], -1) : $m[2],
		'ordered' => $m[3] !== '',
		'start' => (int)$m[3],
		'width' => strlen($m[1]) + strlen($m[2]) + $spaces,
		'content' => $content
	];
}

// Whether a line starts a block that interrupts a paragraph
function mdIsBlockStart($line) {
	if (preg_match('/^ {0,3}(#{1,6}([ \t]|$)|>|`{3,}|~{3,})/', $line) || mdIsHr($line)) return true;
	$item = mdListMarker($line);
	return $item !== null && trim($item['content']) !== '' && (!$item['ordered'] || $item['start'] === 1);
}

function mdIsTableStart($lines, $i) {
	return isset($lines[$i + 1]) && str_contains($lines[$i], '|') && str_contains($lines[$i + 1], '|')
		&& preg_match('/^ {0,3}\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $lines[$i + 1])
		&& count(mdTableRow($lines[$i])) === count(mdTableRow($lines[$i + 1]));
}

function mdTableRow($line) {
	$line = trim($line);
	if (str_starts_with($line, '|')) $line = substr($line, 1);
	if (str_ends_with($line, '|') && !str_ends_with($line, '\\|')) $line = substr($line, 0, -1);
	return array_map(function($cell) { return trim(str_replace('\\|', '|', $cell)); }, preg_split('/(?<!\\\\)\|/', $line));
}

function mdBlocks($lines, &$md, $tight = false) {
	$html = '';
	$n = count($lines);
	$i = 0;

	while ($i < $n) {
		$line = $lines[$i];

		if (trim($line) === '') {
			$i++;
		}
		// Fenced code
		elseif (preg_match('/^( {0,3})(`{3,}|~{3,})\s*([^\s`]*)/', $line, $m)) {
			$code = [];
			for ($i++; $i < $n && !preg_match('/^ {0,3}' . preg_quote($m[2], '/') . '+\s*$/', $lines[$i]); $i++) {
				$code[] = preg_replace('/^ {0,' . strlen($m[1]) . '}/', '', $lines[$i]);
			}
			$i++;
			$lang = $m[3] !== '' ? ' class="language-' . htmlspecialchars($m[3], ENT_QUOTES) . '"' : '';
			$html .= '<pre><code' . $lang . '>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES | ENT_SUBSTITUTE) . "</code></pre>\n";
		}
		// ATX heading
		elseif (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $m)) {
			$html .= mdHeading(strlen($m[1]), $m[2] ?? '', $md);
			$i++;
		}
		elseif (mdIsHr($line)) {
			$html .= "<hr>\n";
			$i++;
		}
		// Blockquote, including GitHub alerts (> [!NOTE])
		elseif (preg_match('/^ {0,3}>/', $line)) {
			$quote = [];
			for (; $i < $n && trim($lines[$i]) !== ''; $i++) {
				if (preg_match('/^ {0,3}> ?(.*)$/', $lines[$i], $m)) $quote[] = $m[1];
				elseif (!mdIsBlockStart($lines[$i])) $quote[] = $lines[$i];
				else break;
			}
			if (preg_match('/^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*$/i', $quote[0], $m)) {
				array_shift($quote);
				$type = strtolower($m[1]);
				$html .= '<blockquote class="alert alert-' . $type . '"><p class="alert-title">' . _t(ucfirst($type)) . '</p>' . mdBlocks($quote, $md) . "</blockquote>\n";
			}
			else $html .= '<blockquote>' . mdBlocks($quote, $md) . "</blockquote>\n";
		}
		elseif (mdIsTableStart($lines, $i)) {
			$html .= mdTable($lines, $i, $md);
		}
		elseif (mdListMarker($line) !== null) {
			$html .= mdList($lines, $i, $md);
		}
		// Indented code
		elseif (preg_match('/^ {4}/', $line)) {
			$code = [];
			for (; $i < $n && (trim($lines[$i]) === '' || preg_match('/^ {4}/', $lines[$i])); $i++) $code[] = substr($lines[$i], 4);
			while (!empty($code) && trim(end($code)) === '') array_pop($code);
			$html .= '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES | ENT_SUBSTITUTE) . "</code></pre>\n";
		}
		// Paragraph, or a setext heading when underlined with === or ---
		else {
			$para = [];
			$level = 0;
			for (; $i < $n && trim($lines[$i]) !== ''; $i++) {
				if (!empty($para)) {
					if (preg_match('/^ {0,3}(=+|-+)\s*$/', $lines[$i], $m)) {
						$level = $m[1][0] === '=' ? 1 : 2;
						$i++;
						break;
					}
					if (mdIsBlockStart($lines[$i]) || mdIsTableStart($lines, $i)) break;
				}
				$para[] = ltrim($lines[$i]);
			}
			$content = rtrim(implode("\n", $para));

			if ($level) $html .= mdHeading($level, $content, $md);
			elseif ($tight) $html .= mdInline($content, $md) . "\n";
			else $html .= '<p>' . mdInline($content, $md) . "</p>\n";
		}
	}

	return $html;
}

function mdHeading($level, $text, &$md) {
	$inner = mdInline(trim($text), $md);

	// GitHub-style anchor id, unique within the document
	$slug = mb_strtolower(html_entity_decode(strip_tags($inner), ENT_QUOTES, 'UTF-8'));
	$slug = trim(preg_replace('/\s+/u', '-', preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $slug)), '-');
	if ($slug === '') $slug = 'section';
	$id = $slug;
	for ($k = 1; isset($md['slugs'][$id]); $k++) $id = $slug . '-' . $k;
	$md['slugs'][$id] = true;

	return '<h' . $level . ' id="' . htmlspecialchars($id, ENT_QUOTES) . '">' . $inner . '</h' . $level . ">\n";
}

function mdTable($lines, &$i, &$md) {
	$aligns = array_map(function($cell) {
		if (str_starts_with($cell, ':') && str_ends_with($cell, ':')) return 'center';
		if (str_ends_with($cell, ':')) return 'right';
		return str_starts_with($cell, ':') ? 'left' : '';
	}, mdTableRow($lines[$i + 1]));

	$row = function($cells, $tag) use ($aligns, &$md) {
		$html = '<tr>';
		foreach ($aligns as $k => $align) {
			$html .= '<' . $tag . ($align ? ' style="text-align:' . $align . '"' : '') . '>' . mdInline($cells[$k] ?? '', $md) . '</' . $tag . '>';
		}
		return $html . '</tr>';
	};

	$html = '<div class="table"><table><thead>' . $row(mdTableRow($lines[$i]), 'th') . '</thead>';
	$rows = '';
	for ($i += 2; $i < count($lines) && trim($lines[$i]) !== '' && !mdIsBlockStart($lines[$i]); $i++) {
		$rows .= $row(mdTableRow($lines[$i]), 'td');
	}
	if ($rows !== '') $html .= '<tbody>' . $rows . '</tbody>';

	return $html . "</table></div>\n";
}

function mdList($lines, &$i, &$md) {
	$n = count($lines);
	$first = mdListMarker($lines[$i]);
	$items = [];
	$loose = false;

	while ($i < $n && ($item = mdListMarker($lines[$i])) !== null && $item['type'] === $first['type']) {
		$body = [$item['content']];
		$blank = false;

		// Item continues with blank lines, lines indented to its content, or lazy paragraph lines
		for ($i++; $i < $n; $i++) {
			$line = $lines[$i];
			if (trim($line) === '') {
				$body[] = '';
				$blank = true;
			}
			elseif (strlen($line) - strlen(ltrim($line, ' ')) >= $item['width']) {
				$body[] = substr($line, $item['width']);
				$blank = false;
			}
			elseif (!$blank && mdListMarker($line) === null && !mdIsBlockStart($line)) {
				$body[] = $line;
			}
			else break;
		}

		$trailing = 0;
		while (count($body) > 1 && trim(end($body)) === '') {
			array_pop($body);
			$trailing++;
		}
		if (in_array('', array_map('trim', array_slice($body, 1)), true)) $loose = true;
		if ($trailing && $i < $n && ($next = mdListMarker($lines[$i])) !== null && $next['type'] === $first['type']) $loose = true;

		$items[] = $body;
	}

	$tag = $first['ordered'] ? 'ol' : 'ul';
	$html = '<' . $tag . ($first['ordered'] && $first['start'] !== 1 ? ' start="' . $first['start'] . '"' : '') . ">\n";
	foreach ($items as $body) {
		// Task list item: [ ] or [x]
		$task = '';
		if (preg_match('/^\[([ xX])\][ \t]+/', $body[0], $m)) {
			$task = '<input type="checkbox" disabled' . ($m[1] === ' ' ? '' : ' checked') . '> ';
			$body[0] = substr($body[0], strlen($m[0]));
		}
		$html .= '<li' . ($task ? ' class="task"' : '') . '>' . $task . mdBlocks($body, $md, !$loose) . "</li>\n";
	}

	return $html . '</' . $tag . ">\n";
}

function mdUrl($url, &$md, $raw = false) {
	$url = str_replace(' ', '%20', trim($url));

	if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $m)) {
		if (!in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'ftp', 'tel'], true)) return null;
	}
	elseif (str_starts_with($url, '#')) {
		// <base href> points at the site root, so in-page anchors need the page path
		$url = $md['page'] . $url;
	}
	elseif (!str_starts_with($url, '//')) {
		// Relative to the Markdown file's folder; local images are served raw
		if (!str_starts_with($url, '/')) $url = '/' . ($md['dir'] !== '' ? encodePathUrl($md['dir']) . '/' : '') . $url;
		if ($raw) {
			$parts = explode('#', $url, 2);
			$parts[0] .= (str_contains($parts[0], '?') ? '&' : '?') . 'raw';
			$url = implode('#', $parts);
		}
	}

	return htmlspecialchars($url, ENT_QUOTES);
}

function mdLink($image, $label, $url, $title, &$md) {
	$href = mdUrl($url, $md, $image);
	$title = $title !== '' ? ' title="' . htmlspecialchars($title, ENT_QUOTES) . '"' : '';
	$inner = mdInline($label, $md);

	if ($image) {
		$alt = htmlspecialchars(html_entity_decode(strip_tags($inner), ENT_QUOTES, 'UTF-8'), ENT_QUOTES);
		return $href === null ? $alt : '<img src="' . $href . '" alt="' . $alt . '"' . $title . '>';
	}

	return $href === null ? $inner : '<a href="' . $href . '"' . $title . '>' . $inner . '</a>';
}

function mdInline($text, &$md) {
	// Finished HTML is stashed behind \0n\0 placeholders so later rules don't touch it
	$stash = function($html) use (&$md) {
		$md['stash'][] = $html;
		return "\0" . (count($md['stash']) - 1) . "\0";
	};

	// Code spans
	$text = preg_replace_callback('/(`+)(.+?)(?<!`)\1(?!`)/s', function($m) use ($stash) {
		$code = preg_replace('/^ (.*) $/s', '$1', str_replace("\n", ' ', $m[2]));
		return $stash('<code>' . htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE) . '</code>');
	}, $text);

	// Backslash escapes and backslash hard breaks
	$text = preg_replace_callback('/\\\\([!"#$%&\'()*+,\-.\/:;<=>?@\[\\\\\]^_`{|}~\n])/', function($m) use ($stash) {
		return $stash($m[1] === "\n" ? "<br>\n" : htmlspecialchars($m[1], ENT_QUOTES));
	}, $text);

	// Autolinks <https://…> and <name@example.com>
	$text = preg_replace_callback('/<((?:https?|ftp):\/\/[^\s<>]+|mailto:[^\s<>]+|[\w.+-]+@[\w-]+(?:\.[\w-]+)+)>/i', function($m) use (&$md, $stash) {
		$url = preg_match('/^[\w.+-]+@/', $m[1]) ? 'mailto:' . $m[1] : $m[1];
		return $stash('<a href="' . mdUrl($url, $md) . '">' . htmlspecialchars($m[1], ENT_QUOTES) . '</a>');
	}, $text);

	// Footnote references [^id]
	$text = preg_replace_callback('/\[\^([^\]\s]+)\]/', function($m) use (&$md, $stash) {
		$id = mb_strtolower($m[1]);
		if (!isset($md['notes'][$id])) return $m[0];
		if (!in_array($id, $md['noteOrder'], true)) $md['noteOrder'][] = $id;
		$num = array_search($id, $md['noteOrder'], true) + 1;
		return $stash('<sup class="fn"><a href="' . $md['page'] . '#fn-' . $num . '" id="fnref-' . $num . '">' . $num . '</a></sup>');
	}, $text);

	// Inline images and links ![alt](url "title"), [text](url "title")
	$label = '((?:[^\[\]]|\[[^\[\]]*\])*)';
	$dest = '\(\s*(?:<([^>\n]*)>|((?:[^\s()]|\([^\s()]*\))*))(?:\s+("[^"]*"|\'[^\']*\'|\([^)]*\)))?\s*\)';
	$text = preg_replace_callback('/(!?)\[' . $label . '\]' . $dest . '/', function($m) use (&$md, $stash) {
		$title = isset($m[5]) && $m[5] !== '' ? substr($m[5], 1, -1) : '';
		return $stash(mdLink($m[1] === '!', $m[2], ($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''), $title, $md));
	}, $text);

	// Reference images and links [text][ref], [text][], [ref]
	$text = preg_replace_callback('/(!?)\[' . $label . '\](?:\[([^\]]*)\])?/', function($m) use (&$md, $stash) {
		$key = mdRefKey(isset($m[3]) && $m[3] !== '' ? $m[3] : $m[2]);
		if (!isset($md['refs'][$key])) return $m[0];
		return $stash(mdLink($m[1] === '!', $m[2], $md['refs'][$key][0], $md['refs'][$key][1], $md));
	}, $text);

	// Bare URLs
	$text = preg_replace_callback('/(?<![\w\/"\'=:.])(?:https?:\/\/|www\.)[^\s<>\0]*[^\s<>\0.,:;"\'!?)\]*_~]/i', function($m) use (&$md, $stash) {
		$url = stripos($m[0], 'www.') === 0 ? 'http://' . $m[0] : $m[0];
		return $stash('<a href="' . mdUrl($url, $md) . '">' . htmlspecialchars($m[0], ENT_QUOTES) . '</a>');
	}, $text);

	$text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

	// Allow a few attribute-less inline HTML tags
	$text = preg_replace('/&lt;(\/?)(br|sup|sub|kbd|mark|u|s|ins|del|b|i|em|strong|small)\s*\/?&gt;/i', '<$1$2>', $text);

	// Emphasis and strikethrough
	$text = preg_replace('/\*\*\*(?=\S)(.+?)(?<=\S)\*\*\*/s', '<strong><em>$1</em></strong>', $text);
	$text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text);
	$text = preg_replace('/(?<![\p{L}\p{N}_])__(?=\S)(.+?)(?<=\S)__(?![\p{L}\p{N}_])/su', '<strong>$1</strong>', $text);
	$text = preg_replace('/\*(?=[^\s*])(.+?)(?<=[^\s*])\*/s', '<em>$1</em>', $text);
	$text = preg_replace('/(?<![\p{L}\p{N}_])_(?=\S)(.+?)(?<=\S)_(?![\p{L}\p{N}_])/su', '<em>$1</em>', $text);
	$text = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $text);

	// Two trailing spaces make a hard line break
	$text = preg_replace('/ {2,}\n/', "<br>\n", $text);

	for ($k = 0; $k < 10 && str_contains($text, "\0"); $k++) {
		$text = preg_replace_callback('/\0(\d+)\0/', function($m) use (&$md) { return $md['stash'][$m[1]]; }, $text);
	}

	return $text;
}

function getFileExtension($filePath) {
	// Use pathinfo to extract the file extension
	$fileInfo = pathinfo($filePath);
	
	// Return the extension if it exists, or an empty string
	return isset($fileInfo['extension']) ? $fileInfo['extension'] : '';
}

function isHiddenFile($name, $config) {
	$builtIn = ['beaver_config.php', 'index.php', '.htaccess', '.beavercfg'];
	if (in_array($name, $builtIn, true) || in_array($name, $config['ignored_files'], true)) return true;

	$ext = strtolower(getFileExtension($name));
	if (preg_match('/^(php\d*|phtml|phar|phps)$/', $ext)) return true;

	return in_array($ext, array_map('strtolower', CONF_IGNORE), true);
}

function readBeaverConfig($configPath) {
	$config = [
		'sort_by' => 'name', // Default config
		'ignored_files' => [],
		'show_dates' => false,
		'show_times' => false
	];

	if (CONF_USEGCFG && file_exists($configPath)) {
		$parsedConfig = parse_ini_file($configPath, true);
		if ($parsedConfig) {
			if (!empty($parsedConfig['settings']['sort_by'])) {
				$config['sort_by'] = strtolower(trim($parsedConfig['settings']['sort_by']));
			}
			if (!empty($parsedConfig['settings']['ignored_files'])) {
				$config['ignored_files'] = array_map('trim', explode(',', $parsedConfig['settings']['ignored_files']));
			}
			if (!empty($parsedConfig['settings']['show_dates'])) {
				$config['show_dates'] = filter_var($parsedConfig['settings']['show_dates'], FILTER_VALIDATE_BOOLEAN);
			}
			if (!empty($parsedConfig['settings']['show_times'])) {
				$config['show_times'] = filter_var($parsedConfig['settings']['show_times'], FILTER_VALIDATE_BOOLEAN);
			}
		}
	}

	return $config;
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
		html,body{padding:0;margin:0;background-color:<?=CONF_SCHEME['dark_bg'];?>;color:<?=CONF_SCHEME['dark_text'];?>;font-family:Courier,sans-serif}nav{display:inline;word-wrap:break-word}h1{font-size:1.5em;margin:0}h2{font-size:1.2em;margin:0}h3{font-size:1em;margin:0}pre{padding:0;margin:1em 0;font-family:Courier,sans-serif;max-width:100%;width:70em;white-space:pre-wrap;word-wrap:break-word}img,video,audio,div.text{display:block;margin:1em 0;max-width:60em;width:auto;max-height:80vh}a,a:visited{color:<?=CONF_SCHEME['dark_link'];?>}ul{list-style:none;padding:0;margin:1em 0}ul.files a,ul.files a:visited,ul.dirs a,ul.dirs a:visited,nav a,nav a:visited{text-decoration:none}ul.dirs a:hover,ul.files a:hover,nav a:hover{text-decoration:underline}ul.dirs a{font-weight:bold}ul.files a,ul.files a:visited{color:<?=CONF_SCHEME['dark_text'];?>}li{margin:0.2em 0}.wrapper{padding:1em}div.text{max-height:none;line-height:1.4}.text h1,.text h2,.text h3,.text h4,.text h5,.text h6{margin:1.2em 0 .5em;line-height:1.2}.text h4,.text h5,.text h6{font-size:1em}.text p{margin:.7em 0}.text ul,.text ol{padding-left:2em;margin:.7em 0}.text ul{list-style:disc}.text ul ul{list-style:circle}.text ol{list-style:decimal}.text li>ul,.text li>ol{margin:.2em 0}.text li.task{list-style:none}.text li.task input{margin:0 .4em 0 -1.4em}.text hr{border:0;border-top:1px dashed;margin:1.5em 0;opacity:.6}.text blockquote{margin:.8em 0;padding:0 1em;border-left:3px solid;opacity:.9}.text .alert-title{font-weight:bold}.text code{background:rgba(127,127,127,.18);padding:0 .2em}.text pre{width:auto;padding:.6em .8em;background:rgba(127,127,127,.12)}.text pre code{background:none;padding:0}.text div.table{max-width:100%;overflow-x:auto;margin:.8em 0}.text table{border-collapse:collapse}.text th,.text td{border:1px solid rgba(127,127,127,.5);padding:.3em .6em;text-align:left;vertical-align:top}.text th{font-weight:bold}.text img{display:inline-block;max-width:100%;margin:.2em 0;vertical-align:middle}.text sup.fn a{text-decoration:none}.text .footnotes{font-size:.9em}@media (prefers-color-scheme:light){html,body{background-color:<?=CONF_SCHEME['light_bg'];?>;color:<?=CONF_SCHEME['light_text'];?>}a,a:visited{color:<?=CONF_SCHEME['light_link'];?>}ul.files a,ul.files a:visited{color:<?=CONF_SCHEME['light_text'];?>}}
		</style>
	</head>
	<body>
		<div class="wrapper">
			<?php
			// Resolve the requested path and validate it stays within the configured root
			$root = realpath(__DIR__ . CONF_ROOT);
			$systemPath = $root === false ? false : realpath($root . '/' . sanitizePath(urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '')));

			// realpath() returns false for non-existent paths; str_starts_with guards against escaping the root
			if ($systemPath === false || !($systemPath === $root || str_starts_with($systemPath, $root . DIRECTORY_SEPARATOR))) {
				print _t('ERROR: Invalid path.');
			}
			elseif (!is_dir($systemPath) && isHiddenFile(basename($systemPath), readBeaverConfig(dirname($systemPath) . '/.beavercfg'))) {
				print _t('ERROR: Invalid path.');
			}
			elseif (is_dir($systemPath)) {
				// Relative path derived from the validated location, used for links and breadcrumbs
				$path = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($systemPath, strlen($root)), '/'));

				$config = readBeaverConfig($systemPath . '/.beavercfg');

				$defaultIgnored = ['.', '..', 'beaver_config.php', 'index.php', '.htaccess', '.beavercfg'];
				$entries = array_diff(scandir($systemPath), array_merge($defaultIgnored, $config['ignored_files']));

				print '<strong>' . _t('Index') . (empty($path) ? _t(':') : _t(' of:')) . '</strong> <nav>' . renderPath($path) . '</nav>';

				if (!empty($entries)) {
					// Separate directories and files
					$directories = [];
					$files = [];

					foreach($entries as $entry) {
						if (is_dir($systemPath . '/' . $entry)) {
							$directories[] = $entry;
						} 
						elseif (is_file($systemPath . '/' . $entry) && !isHiddenFile($entry, $config)) {
							$files[] = $entry;
						}
					}

					// Sorting logic
					if ($config['sort_by'] === 'date') {
						// Sort by date modified
						usort($directories, function ($a, $b) use ($systemPath) {
							return filemtime("$systemPath/$b") <=> filemtime("$systemPath/$a"); // Descending order
						});
						usort($files, function ($a, $b) use ($systemPath) {
							return filemtime("$systemPath/$b") <=> filemtime("$systemPath/$a"); // Descending order
						});
					} 
					else {
						// Sort alphabetically
						sort($directories, SORT_NATURAL | SORT_FLAG_CASE);
						sort($files, SORT_NATURAL | SORT_FLAG_CASE);
					}

					// Print directories
					if (!empty($directories)) {
						print '<ul class="dirs">';
						foreach ($directories as $directory) {
							$modTime = date($config['show_times'] ? "Y-m-d H:i:s" : "Y-m-d", filemtime($systemPath . '/' . $directory));
							$name = htmlspecialchars($directory, ENT_QUOTES);
							$label = $config['show_dates'] || $config['show_times'] ? "[{$name}] <em>({$modTime})</em>" : "[{$name}]";
							$href = htmlspecialchars('/' . encodePathUrl(ltrim($path . '/' . $directory, '/')), ENT_QUOTES);
							print '<li><a href="' . $href . '">' . $label . '</a></li>' . PHP_EOL;
						}
						print '</ul>';
					}

					// Print files
					if (!empty($files)) {
						print '<ul class="files">';
						foreach ($files as $file) {
							$modTime = date($config['show_times'] ? "Y-m-d H:i:s" : "Y-m-d", filemtime($systemPath . '/' . $file));
							$name = htmlspecialchars($file, ENT_QUOTES);
							$label = $config['show_dates'] || $config['show_times'] ? "{$name} <em>({$modTime})</em>" : $name;
							$href = htmlspecialchars('/' . encodePathUrl(ltrim($path . '/' . $file, '/')), ENT_QUOTES);
							print '<li><a href="' . $href . '">' . $label . '</a></li>' . PHP_EOL;
						}
						print '</ul>';
					}

				}
				else print '<ul><li>' . _t('Nothing to display.') . '</li></ul>';
			}
			else {
				// Validated regular file
				$path = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($systemPath, strlen($root)), '/'));
				print '<strong>' . _t('Display of:') . '</strong> ' . renderPath($path);
				displayFile($systemPath, $path);
			}
			?>
		</div>
	</body>
</html>