<?php
/**
* A script to check a site for broken links or images.
*
* @license https://www.gnu.org/licenses/gpl-3.0-standalone.html GPL-3.0-or-later
*/

/**
* @const Path to log file. No log will be created if no path has been set.
*/
define('BROKENLINKS_LOG_FILE', '');
/**
* @const Path to INI file where preset values are stored.
*        Useful, if script gets used often with the same settings.
*/
define('BROKENLINKS_PRESETS_FILE', 'presets.ini');

/** @const Message type: debug message. Do not change. */
define('BROKENLINKS_MESSAGE_DEBUG', 0);
/** @const Message type: informational message. Do not change. */
define('BROKENLINKS_MESSAGE_INFO', 1);
/** @const Result type: processing has been skipped due to resource protocol. Do not change. */
define('BROKENLINKS_RESULT_SKIPPEDPROTOCOL', 2);
/** @const Result type: processing has been skipped because link has been ignored by user. Do not change. */
define('BROKENLINKS_RESULT_SKIPPEDLINK', 3);
/** @const Result type: processing has been skipped because it is a redirect which has been ignored by user. Do not change. */
define('BROKENLINKS_RESULT_SKIPPEDREDIRECTION', 4);
/** @const Result type: link is a redirect. Do not change. */
define('BROKENLINKS_RESULT_REDIRECT', 5);
/** @const Result type: link is broken. Do not change. */
define('BROKENLINKS_RESULT_ERROR', 6);
/** @const Result type: link is okay. Do not change. */
define('BROKENLINKS_RESULT_OK', 7);
/** @const Result type: the URL contains a blacklisted fragment. Do not change. */
define('BROKENLINKS_RESULT_BLACKLISTED', 8);

// Storage of URLs which have already been traversed
$brokenlinks_known_traversed = array();
//Storage of URLs which have already been checked and the corresponding results
$brokenlinks_known_checked = array();

// Timestamps when the processed started/ended
$brokenlinks_stats_start = 0;
$brokenlinks_stats_end = 0;

// Array for storing some statistics
$brokenlinks_stats_count = array(
	'total' => 0,
	'ok' => 0,
	'error' => 0,
	'skipped' => 0,
	'blacklisted' => 0,
	'redirect' => 0
);

echo '<!DOCTYPE HTML>'.PHP_EOL;
echo '<html lang="de">'.PHP_EOL;
echo '<head>'.PHP_EOL;
echo '<title>PP Link-Checker</title>'.PHP_EOL;
echo '</head>'.PHP_EOL;
echo '<body>'.PHP_EOL;

if (isset($_POST['brokenlinks_site']) && 
  strlen($_POST['brokenlinks_site']) && 
  isset($_POST['brokenlinks_ignored_links']) &&
  isset($_POST['brokenlinks_maxdepth']) &&
  isset($_POST['brokenlinks_ignored_redirectiontargets']) &&
  isset($_POST['brokenlinks_blacklist']) &&
  isset($_POST['brokenlinks_verifycerts'])) {
	/** @internal Maximum depth for following links. */
	define('BROKENLINKS_MAX_DEPTH', (int)$_POST['brokenlinks_maxdepth']);
	/** @internal The site URL. */
	define('BROKENLINKS_SITE_URL', $_POST['brokenlinks_site']);
	/** @internal Links to be ignored. */
	define('BROKENLINKS_IGNORED_LINKS', trim($_POST['brokenlinks_ignored_links']));
	/** @internal Redirection targets to be ignored. */
	define('BROKENLINKS_IGNORED_REDIRECTIONTARGETS', trim($_POST['brokenlinks_ignored_redirectiontargets']));
	/** @internal Black list, matching URL fragments will be reported. */
	define('BROKENLINKS_BLACKLIST', trim($_POST['brokenlinks_blacklist']));
	/** @internal Boolean value indicating whether site's certificate should be verified. */
	define('BROKENLINKS_VERIFYCERTS', ($_POST['brokenlinks_verifycerts'] == 'yes'));
	
	brokenlinks_start();
} else {
	$presets = brokenlinks_getpresets();
	
	echo '<form method="post">';
	
	echo '<fieldset>';
	echo '<legend>URL</legend>';
	echo '<input style="width:500px" type="url" name="brokenlinks_site" id="brokenlinks_site">';
	echo ' <button type="submit">Untersuchung starten</button>';
	echo '</fieldset>';
	
	echo '<br>';
	
	echo '<fieldset>';
	echo '<legend>Weitere Optionen</legend>';

	echo '<label for="brokenlinks_maxdepth">Maximale Tiefe:</label><br>';
	echo '<input min="0" max="10" value="0" type="number" name="brokenlinks_maxdepth" id="brokenlinks_maxdepth">';
	echo '<br><br>';

	echo 'Zertifikate überprüfen:<br>';
	echo '<label><input type="radio" id="brokenlinks_verifycerts_yes" name="brokenlinks_verifycerts" value="yes" checked> Ja</label>';
	echo '<label><input type="radio" id="brokenlinks_verifycerts_no" name="brokenlinks_verifycerts" value="no"> Nein</label>';
	echo '<br><br>';
	
	echo '<label for="brokenlinks_ignored_links">Ignorierte Links (ein Eintrag pro Zeile):</label><br>';
	echo '<textarea cols="80" rows="5" name="brokenlinks_ignored_links" id="brokenlinks_ignored_links">';
	echo implode("\n", $presets['ignored_links']);
	echo '</textarea>';
	echo '<br><br>';

	echo '<label for="brokenlinks_ignored_redirectiontargets">Ignorierte Weiterleitungsziele (ein Eintrag pro Zeile):</label><br>';
	echo '<textarea cols="80" rows="5" name="brokenlinks_ignored_redirectiontargets" id="brokenlinks_ignored_redirectiontargets">';
	echo implode("\n", $presets['ignored_redirectiontargets']);
	echo '</textarea>';
	echo '<br><br>';

	echo '<label for="brokenlinks_blacklist">Schwarze Liste (URL-Fragmente, ein Eintrag pro Zeile):</label><br>';
	echo '<textarea cols="80" rows="5" name="brokenlinks_blacklist" id="brokenlinks_blacklist">';
	echo implode("\n", $presets['blacklist']);
	echo '</textarea>';

	echo '</fieldset>';
	echo '</form>';
}

echo '</body>'.PHP_EOL;
echo '</html>';

/**
* Load presets from defined presets file.
* @see BROKENLINKS_PRESETS_FILE
* @return Array The loaded presets.
*/
function brokenlinks_getpresets() {
	$ignored_links = array();
	$ignored_redirectiontargets = array();
	$blacklist = array();
	
	if (BROKENLINKS_PRESETS_FILE && is_file(BROKENLINKS_PRESETS_FILE)) {
		$parsed_file = @parse_ini_file(BROKENLINKS_PRESETS_FILE, true);
		
		if (is_array($parsed_file)) {
			if (isset($parsed_file['ignored_links']) && is_array($parsed_file['ignored_links']) && isset($parsed_file['ignored_links']['items']) && is_array($parsed_file['ignored_links']['items'])) {
				$ignored_links = $parsed_file['ignored_links']['items'];
			}
			if (isset($parsed_file['ignored_redirectiontargets']) && is_array($parsed_file['ignored_redirectiontargets']) && isset($parsed_file['ignored_redirectiontargets']['items']) && is_array($parsed_file['ignored_redirectiontargets']['items'])) {
				$ignored_redirectiontargets = $parsed_file['ignored_redirectiontargets']['items'];
			}
			if (isset($parsed_file['blacklist']) && is_array($parsed_file['blacklist']) && isset($parsed_file['blacklist']['items']) && is_array($parsed_file['blacklist']['items'])) {
				$blacklist = $parsed_file['blacklist']['items'];
			}
		}
	}
	
	return array(
		'ignored_links' => $ignored_links, 
		'ignored_redirectiontargets' => $ignored_redirectiontargets, 
		'blacklist' => $blacklist
	);
}

/**
* Start processing. Make sure all settings have been defined in relevant constants.
*/
function brokenlinks_start() {
	global $brokenlinks_stats_start, 
		   $brokenlinks_stats_end, 
		   $brokenlinks_stats_count;
	
	// Set default stream context
	
	if (!BROKENLINKS_VERIFYCERTS) {
		$opts_ssl = array(
			'ssl'=>array(
				'verify_peer' => false,
				'verify_peer_name' => false,
			),
		);
		stream_context_set_default($opts_ssl);
	} 
	
	// Start logging
	
	$brokenlinks_stats_start = time();
	brokenlinks_log_start();
	
	echo 'Untersuchung von '.brokenlinks_url_linkify(BROKENLINKS_SITE_URL).' gestartet um '.date('G:i:s', $brokenlinks_stats_start);
	if (BROKENLINKS_LOG_FILE) echo ' (<a href="'.BROKENLINKS_LOG_FILE.'">Log</a>)';
	echo '<br>';
	
	// Try to set max_execution_time to unlimited
	@set_time_limit(0);
	echo 'Maximale Laufzeit der Untersuchung: ';
	if (ini_get('max_execution_time') > 0) {
		echo ini_get('max_execution_time').' Sekunden';
	} else {
		echo 'unbegrenzt';
	}
	echo '<br><br>';
	
	// Do actual link checking
	
	brokenlinks_traverse(BROKENLINKS_SITE_URL);
	
	// Print stats and finish log file
	
	if ($brokenlinks_stats_count['total'] == 0) {
		brokenlinks_message(BROKENLINKS_MESSAGE_INFO, 'Keine zu untersuchenden Links gefunden');
	}
	
	$brokenlinks_stats_end = time();
	
	echo '<br>';
	echo 'Untersuchung beendet um '.date('G:i:s', $brokenlinks_stats_end);
	
	brokenlinks_log_end();
	brokenlinks_stats_print();
}

/**
* Check URL. If processing is not skipped, we will try to get headers from that URL.
* @param string $url The URL to be checked.
* @param string $description Human readable description of the link.
* @param string $source URL of the page where $url has been found.
* @param int $depth Current depth of processing.
*/
function brokenlinks_checkurl($url, $description, $source, $depth) {
	global $brokenlinks_known_traversed, 
		   $brokenlinks_known_checked, 
		   $brokenlinks_stats_count;
	
	if (strlen($url) == 0 || substr($url, 0, 1) == '#') {
		// We do not need to test empty URLs or pure anchors
		return;
	}
	
	$brokenlinks_stats_count['total']++;
	
	$url_parsed = parse_url($url);
	
	if (isset($url_parsed['scheme'])) {
		if (!($url_parsed['scheme'] == 'http' || $url_parsed['scheme'] == 'https')) {
			brokenlinks_message(BROKENLINKS_RESULT_SKIPPEDPROTOCOL, $url, $depth, $source);
			$brokenlinks_stats_count['skipped']++;
			return;
		}
	} else {
		if (substr($url, 0, 1) == '/') {
			$site_url_parsed = parse_url($_POST['brokenlinks_site']);
			$url = brokenlinks_url_construct($site_url_parsed['scheme'].'://'.$site_url_parsed['host'], $url);
		} elseif (substr($url, 0, 1) == '?' || substr($source, -1) == '/') {
			$url = brokenlinks_url_construct($source, $url);
		} else {
			$url = brokenlinks_url_construct(dirname($source), $url);
		}
	}
	
	$brokenlinks_ignored_links = array_filter(explode("\n", BROKENLINKS_IGNORED_LINKS), 'trim');
	$brokenlinks_ignored_links = array_filter(array_map('trim', $brokenlinks_ignored_links));
	if (in_array($url, $brokenlinks_ignored_links)) {
		brokenlinks_message(BROKENLINKS_RESULT_SKIPPEDLINK, $url, $depth, $source);
		$brokenlinks_stats_count['skipped']++;
		return;
	}
	
	$brokenlinks_blacklist = array_filter(explode("\n", BROKENLINKS_BLACKLIST), 'trim');
	$brokenlinks_blacklist = array_filter(array_map('trim', $brokenlinks_blacklist));
	foreach ($brokenlinks_blacklist as $brokenlinks_blacklist_item) {
		if (strpos($url, $brokenlinks_blacklist_item) !== false) {
			brokenlinks_message(BROKENLINKS_RESULT_BLACKLISTED, $url, $depth, $source);
			$brokenlinks_stats_count['blacklisted']++;
			break;
		}
	}
	
	$known = isset($brokenlinks_known_checked[$url]);
	if (!$known) {
		$headers = @get_headers($url, 1);
		$extra = '';
		$contenttype = '';
		$headers_final = '';
	
		if (is_array($headers)) {
			switch(substr($headers[0], 9, 3)) {
				case '200':
					$result = 'OK';
					if (isset($headers['Content-Type'])) {
						if (is_array($headers['Content-Type'])){
							$contenttype = array_pop($headers['Content-Type']);
						} else {
							$contenttype = $headers['Content-Type'];
						}
					}
					break;
				case '404':
				case '403':
				case '401':
				case '410':
				case '400':
				case '500':
				case '502':
				case '503':
					$result = 'ERROR';
					$headers_final = $headers[0];
					breaK;
				case '301':
				case '302':
					$result = 'REDIRECT';
					if (isset($headers['Location'])) {
						$tmp_last = max(array_keys($headers));
						if (is_array($headers['Location'])){
							$extra = array_pop($headers['Location']);
						} else {
							$extra = $headers['Location'];
						}
						$headers_final = $headers[$tmp_last];
					}
					
					if (isset($headers['Content-Type'])) {
						if (is_array($headers['Content-Type'])){
							$contenttype = array_pop($headers['Content-Type']);
						} else {
							$contenttype = $headers['Content-Type'];
						}
					}
					
					break;
				default:
					$result = 'ERROR';
					$headers_final = $headers[0];
					var_dump($headers);
			}
		} else {
			$result = 'ERROR';
			if (isset($url_parsed['host']) && !checkdnsrr($url_parsed['host'].'.', 'A')) {
				$extra = 'Kann Domain nicht auflösen';
			} else {
				$extra = 'Unbekannter Fehler';
			}
		}

		$brokenlinks_known_checked[$url]['Result'] = $result;
		$brokenlinks_known_checked[$url]['Extra'] = $extra;
		$brokenlinks_known_checked[$url]['Contenttype'] = $contenttype;
		$brokenlinks_known_checked[$url]['FinalHeaders'] = $headers_final;
	} else {
		$result = $brokenlinks_known_checked[$url]['Result'];
		$extra = $brokenlinks_known_checked[$url]['Extra'];
		$contenttype = $brokenlinks_known_checked[$url]['Contenttype'];
		$headers_final = $brokenlinks_known_checked[$url]['FinalHeaders'];
	}
	
	$message['main'] = $url;
	$message['extra'] = $extra;
	$message['headers'] = $headers_final;
	$message['description'] = $description;
	
	switch($result) {
		case 'OK':
			brokenlinks_message(BROKENLINKS_RESULT_OK, $message, $depth, $source);
			$brokenlinks_stats_count['ok']++;
			brokenlinks_traverse($url, $depth+1, $contenttype);
			break;
		case 'ERROR':
			brokenlinks_message(BROKENLINKS_RESULT_ERROR, $message, $depth, $source);
			$brokenlinks_stats_count['error']++;
			break;
		case 'REDIRECT':
			$brokenlinks_ignored_redirectiontargets = explode("\n", BROKENLINKS_IGNORED_REDIRECTIONTARGETS);
			$brokenlinks_ignored_redirectiontargets = array_filter(array_map('trim', $brokenlinks_ignored_redirectiontargets));
			if (!in_array($extra, $brokenlinks_ignored_redirectiontargets)) {
				brokenlinks_message(BROKENLINKS_RESULT_REDIRECT, $message, $depth, $source);
				$brokenlinks_stats_count['redirect']++;
				brokenlinks_traverse($extra, $depth+1, $contenttype);
			} else {
				brokenlinks_message(BROKENLINKS_RESULT_SKIPPEDREDIRECTION, $message, $depth, $source);
				$brokenlinks_stats_count['skipped']++;
			}
			break;
		default:
			brokenlinks_message(BROKENLINKS_RESULT_ERROR, $message, $depth, $source);
			$brokenlinks_stats_count['error']++;
	}	
	if ($known) brokenlinks_message(BROKENLINKS_MESSAGE_INFO, 'Link wurde bereits untersucht', $depth+1);
}

/**
* Process the specified URL and check the links on the page.
* @param string $url The URL to be processed.
* @param int $depth Current depth of processing, will be compared to maximum depth specified by user.
* @param string $contenttype The content type of the resource . We only care about text/html.
*/
function brokenlinks_traverse($url, $depth = 0, $contenttype = '') {
	global $brokenlinks_known_traversed;
	
	if (in_array($url, $brokenlinks_known_traversed)) {
		return;
	}
	
	if (substr($url, 0, strlen(BROKENLINKS_SITE_URL)) != BROKENLINKS_SITE_URL) {
		brokenlinks_message(BROKENLINKS_MESSAGE_INFO, 'URL ist außerhalb der angegebenen Seite', $depth);
		return;
	}
	
	if ($depth > BROKENLINKS_MAX_DEPTH) {
		brokenlinks_message(BROKENLINKS_MESSAGE_INFO, 'Maximale Tiefe erreicht', $depth);
		return;
	}
	
	if ($contenttype != '' && substr($contenttype, 0, 9) != 'text/html') {
		brokenlinks_message(BROKENLINKS_MESSAGE_INFO, 'Content-Type ist \''.$contenttype.'\', es wird aber nur text/html untersucht', $depth);
		return;
	}
	
	$brokenlinks_known_traversed[] = $url;
	
	brokenlinks_message(BROKENLINKS_MESSAGE_INFO, "Lade '".$url."'", $depth);
	$doc = brokenlinks_loadhtml($url);
	
	if ($doc) {
		$as = $doc->getElementsByTagName('a');
		foreach($as as $a) {
			$url_to_check = $a->getAttribute('href');
			$description = trim(html_entity_decode($a->nodeValue));
			brokenlinks_checkurl($url_to_check, $description, $url, $depth);
		}
		$imgs = $doc->getElementsByTagName('img');
		foreach($imgs as $img) {
			$url_to_check = $img->getAttribute('src');
			$description = trim(html_entity_decode($img->getAttribute('alt')));
			brokenlinks_checkurl($url_to_check, $description, $url, $depth);
		}
	}	
}

/**
* @internal Helper function for handling PHP errors so they can be logged properly.
* @param int $code Error code. Not used.
* @param string $message Error message. Will be logged.
*/
function brokenlinks_error_handler($code, $message) {
	brokenlinks_message(BROKENLINKS_MESSAGE_DEBUG, 'PHP-Fehler: '.$message);
}

/**
* Print message to screen. If a log file has been set, the message will be logged there as well.
* @param int $type The type of the message, see relevant constants.
* @param string $message The message.
* @param int $depth The current depth of processing. The message will be indented to indicate the depth.
* @param string $sourcepage The URL of the page which is being processed currently. 
                            For example, allows to find where the broken link is.
*/
function brokenlinks_message($type, $message, $depth = 0, $sourcepage = '') {
	echo str_repeat('&nbsp;', $depth*4);
	
	$icon = '';
	switch ($type) {
		case BROKENLINKS_MESSAGE_DEBUG:
			$result = 'DEBUG:';
			break;
		case BROKENLINKS_MESSAGE_INFO:
			$result = 'INFO:';
			break;
		case BROKENLINKS_RESULT_OK:
			$icon = '&#x2705;';
			$result = 'OK:';
			break;
		case BROKENLINKS_RESULT_ERROR:
			$icon = '&#x1f534;';
			$result = 'FEHLER:';
			break;
		case BROKENLINKS_RESULT_REDIRECT:
			$icon = '&#x1f500;';
			$result = 'WEITERLEITUNG:';
			break;
		case BROKENLINKS_RESULT_SKIPPEDLINK:
			$icon = '&#x1f535;';
			$result = 'ÜBERSPRUNGENER LINK:';
			break;
		case BROKENLINKS_RESULT_SKIPPEDREDIRECTION:
			$icon = '&#x1f535;';
			$result = 'ÜBERSPRUNGENES WEITERLEITUNGSZIEL:';
			break;
		case BROKENLINKS_RESULT_SKIPPEDPROTOCOL:
			$icon = '&#x1f535;';
			$result = 'ÜBERSPRUNGENES PROTOKOLL:';
			break;
		case BROKENLINKS_RESULT_BLACKLISTED:
			$icon = '&#x1f3f4;';
			$result = 'URL-FRAGMENT IN SCHWARZER LISTE:';
			break;
		default:
			$result = 'UNBEKANNTE MELDUNG:';
	}
	
	if ($icon) echo $icon.' ';
	if ($result) echo $result.' ';
	
	if (is_array($message)) {
		echo brokenlinks_url_linkify($message['main']);
		
		$message_constructed = '';
		if ($message['extra']) {
			$message_constructed .= brokenlinks_url_linkify($message['extra']);
		}
		if ($message['headers']) {
			if ($message_constructed) $message_constructed .= ' ';
			$message_constructed .= '=> '.$message['headers'];
		}
		if ($message['description']) {
			if ($message_constructed) $message_constructed .= ', ';
			$message_constructed .= brokenlinks_url_linkify($message['description']);
		}
		if ($message_constructed) {
			echo ' (';
			echo $message_constructed;
			echo ')';
		}
	} else {
		echo brokenlinks_url_linkify($message);
	}
	
	echo '<br>';
	
	flush();
	ob_flush();
	
	if (BROKENLINKS_LOG_FILE && ($type == BROKENLINKS_RESULT_ERROR || $type == BROKENLINKS_RESULT_BLACKLISTED)) {
		$data = $result."\n";
		$data .= "\t".'AUF SEITE: '.$sourcepage."\n";
		
		if (is_array($message)) {
			$data .= "\t".'LINK: '.$message['main']."\n";
			if ($message['description']) {
				$data .= "\t".'LINKBESCHREIBUNG: '.$message['description']."\n";
			}
			if ($message['extra']) {
				$data .= "\t".'DETAILS/WEITERLEITUNGSZIEL: '.$message['extra']."\n";
			}
			if ($message['headers']) {
				$data .= "\t".'RÜCKGABECODE: '.$message['headers']."\n";
			}
			$data .= "\n";
		} else {
			$data .= "\t".$message."\n\n";
		}
		file_put_contents(BROKENLINKS_LOG_FILE, $data, FILE_APPEND);
	}
}

/**
* Write log intro to file.
*/
function brokenlinks_log_start() {
	if (BROKENLINKS_LOG_FILE) {	
		$data = '-- START: '.date(DATE_RSS);
		$data .= "\n";
		$data .= 'Untersuchung von '.BROKENLINKS_SITE_URL."\n";
		$data .= "\n";
		
		file_put_contents(BROKENLINKS_LOG_FILE, $data);
	}
}

/**
* Write log outro with statistics to file.
*/
function brokenlinks_log_end() {
	global $brokenlinks_stats_start, $brokenlinks_stats_count;
	
	if (BROKENLINKS_LOG_FILE) {
		$data = '';
		if ($brokenlinks_stats_count['total'] == 0) {
			$data = 'Keine zu untersuchenden Links gefunden';
			$data .= "\n";
		}
		
		$data .= "\n";
		$data .= 'Dauer: '.(time() - $brokenlinks_stats_start)."s\n";
		$data .= 'OK: '.($brokenlinks_stats_count['ok'])."\n";
		$data .= 'Weiterleitungen: '.($brokenlinks_stats_count['redirect'])."\n";
		$data .= 'Fehler: '.($brokenlinks_stats_count['error'])."\n";
		$data .= 'Schwarze Liste: '.($brokenlinks_stats_count['blacklisted'])."\n";
		$data .= 'Übersprungen: '.($brokenlinks_stats_count['skipped'])."\n";
		$data .= 'Insgesamt: '.($brokenlinks_stats_count['total'])."\n";
		
		$data .= "-- ENDE: ".date(DATE_RSS);
		
		file_put_contents(BROKENLINKS_LOG_FILE, $data, FILE_APPEND);
	}
}

/**
* Print statistics to screen.
*/
function brokenlinks_stats_print() {
	global $brokenlinks_stats_start, $brokenlinks_stats_count;
	
	echo '<br>';
	echo '<fieldset>';
	echo '<legend>Statistik</legend>';
	echo '<table>';
	echo '<tr><td>&#x1f550;</td><td>Dauer:</td><td>'.(time() - $brokenlinks_stats_start).'s</td></tr>';
	echo '<tr><td>&#x2705;</td><td>OK:</td><td>'.($brokenlinks_stats_count['ok']).'</td></tr>';
	echo '<tr><td>&#x1f500;</td><td>Weiterleitungen:</td><td>'.($brokenlinks_stats_count['redirect']).'</td></tr>';
	echo '<tr><td>&#x1f534;</td><td>Fehler:</td><td>'.($brokenlinks_stats_count['error']).'</td></tr>';
	echo '<tr><td>&#x1f3f4;</td><td>Schwarze Liste:</td><td>'.($brokenlinks_stats_count['blacklisted']).'</td></tr>';
	echo '<tr><td>&#x1f535;</td><td>Übersprungen:</td><td>'.($brokenlinks_stats_count['skipped']).'</td></tr>';
	echo '<tr><td>&nbsp;&sum;</td><td>Insgesamt:</td><td>'.($brokenlinks_stats_count['total']).'</td></tr>';
	echo '</table>';
	echo '</fieldset>';
}

/** Transform text URL into clickable A tag for screen output.
* @param string $url The text representation of the URL.
* @return string If passed URL appears to be a valid URL, then it gets returned as A tag.
                 Otherwise, the URL gets returned as is.
*/
function brokenlinks_url_linkify($url) {
	// Transform text url into clickable url
	if (!filter_var($url, FILTER_VALIDATE_URL) === false) {
		return '<a href="'.$url.'">'.$url.'</a>';
	} else {
		return $url;
	}
}

/** Get HTML content of specified URL.
* @param string $brokenlinks_url The URL.
* @return DOMDocument|false Parsed HTML content. False, in case of an error.
*/
function brokenlinks_loadhtml($brokenlinks_url) {
	libxml_use_internal_errors(true);

	set_error_handler('brokenlinks_error_handler');
	
	$brokenlinks_content = file_get_contents($brokenlinks_url, false);
	$result = false;
	if ($brokenlinks_content) {
		// Check whether response is gzip compressed
		foreach($http_response_header as $c => $h) {
			if(stristr($h, 'content-encoding') and stristr($h, 'gzip')) {
				$brokenlinks_content = gzinflate(substr($brokenlinks_content,10,-8));
			}
		}
		
		// Parse response as HTML
		if ($brokenlinks_content) {
			$doc = new DOMDocument();
			$result = $doc->loadHTML($brokenlinks_content);
		} 
	}
	
	restore_error_handler();

	// Return parsed HTML if everything went well
	if (!$result) {
		return false;
	} else {
		return $doc;
	}
}

/** Combine two URL fragments and make sure only one slash is between them.
* @param string $first The first part.
* @param string $second The first part.
* @return string The constructed URL.
*/
function brokenlinks_url_construct($first, $second) {
	// If first part ends with slash, remove it
	if (substr($first, -1) == '/') $first = substr($first, 0, -1);
	
	// If second part does not start with slash, add it
	if (substr($second, 0, 1) != '/') $second = '/'.$second;
	
	// As result, glue both parts together
	return $first.$second; 
}