<?php
/**
 * functions.php
 * Zentrale Helper-Funktionen: JSON lesen/schreiben, Session-Auth, Utilities.
 * Flat-File-Architektur ohne Datenbank (JSON als Speicher).
 */

declare(strict_types=1);

// --- Pfade -------------------------------------------------------------
define('DATA_DIR', __DIR__ . '/../data');
define('CONFIG_FILE', DATA_DIR . '/config.json');
define('LINKS_FILE', DATA_DIR . '/links.json');
define('FAVICON_CACHE_FILE', DATA_DIR . '/favicon_cache.json');
define('AUDIT_LOG_FILE', DATA_DIR . '/audit_log.json');
define('AUDIT_LOG_MAX_ENTRIES', 300);
define('UPLOAD_DIR', __DIR__ . '/../uploads');
define('FAVICON_DIR', __DIR__ . '/../uploads/favicons');
define('UPLOAD_URL', 'uploads'); // relativer Pfad fürs Frontend
define('FAVICON_URL', 'uploads/favicons');

// --- Session -------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Liest eine JSON-Datei und gibt sie als assoziatives Array zurück.
 * Wirft eine Exception, falls die Datei fehlt oder ungültig ist.
 */
function read_json(string $path): array
{
    if (!file_exists($path)) {
        throw new RuntimeException("Datei nicht gefunden: {$path}");
    }
    $content = file_get_contents($path);
    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Ungültiges JSON in {$path}: " . json_last_error_msg());
    }
    return $data ?? [];
}

/**
 * Schreibt ein Array als JSON in eine Datei (mit Locking gegen Race-Conditions).
 */
function write_json(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    $fp = fopen($path, 'c+');
    if (!$fp) {
        return false;
    }
    $ok = false;
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        $ok = fwrite($fp, $json) !== false;
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $ok;
}

/**
 * Wie read_json(), gibt aber $default zurück statt zu werfen, falls die
 * Datei (noch) nicht existiert. Für optionale Dateien wie Cache/Log.
 */
function read_json_or_default(string $path, array $default = []): array
{
    if (!file_exists($path)) {
        return $default;
    }
    try {
        return read_json($path);
    } catch (Throwable $e) {
        return $default;
    }
}

// --- Audit-Log --------------------------------------------------------------

/**
 * Protokolliert eine Backend-Aktion in data/audit_log.json (neueste zuerst,
 * auf AUDIT_LOG_MAX_ENTRIES begrenzt).
 */
function log_action(string $action, string $details = ''): void
{
    $log = read_json_or_default(AUDIT_LOG_FILE, ['entries' => []]);
    $log['entries'] = $log['entries'] ?? [];
    array_unshift($log['entries'], [
        'timestamp' => date('c'),
        'action' => $action,
        'details' => $details,
    ]);
    if (count($log['entries']) > AUDIT_LOG_MAX_ENTRIES) {
        $log['entries'] = array_slice($log['entries'], 0, AUDIT_LOG_MAX_ENTRIES);
    }
    write_json(AUDIT_LOG_FILE, $log);
}

function get_audit_log(int $limit = 50): array
{
    $log = read_json_or_default(AUDIT_LOG_FILE, ['entries' => []]);
    $entries = $log['entries'] ?? [];
    return array_slice($entries, 0, max(1, $limit));
}

function clear_audit_log(): bool
{
    return write_json(AUDIT_LOG_FILE, ['entries' => []]);
}

// --- Favicon-Cache ------------------------------------------------------------

/**
 * Erkennt, ob ein Host als "intern" gilt: private/reservierte IP-Bereiche
 * (10.x, 172.16-31.x, 192.168.x, 127.x, link-local ...), IP-lose Hostnamen ohne
 * Punkt (z.B. "srvvcs01") oder gängige interne TLD-Suffixe (.local/.lan/...).
 * Für solche Hosts kann der Google-Favicon-Dienst die Seite nicht erreichen —
 * dort wird stattdessen direkt das favicon.ico der Zielseite selbst verwendet.
 */
function is_private_host(string $host): bool
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        // Liefert false, wenn die IP in einem privaten/reservierten Bereich liegt
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
    if (strpos($host, '.') === false) {
        return true; // z.B. "srvvcs01" — bare Hostname, praktisch immer intern
    }
    $internalSuffixes = ['.local', '.lan', '.internal', '.intra', '.corp', '.home'];
    $hostLower = strtolower($host);
    foreach ($internalSuffixes as $suffix) {
        if (substr($hostLower, -strlen($suffix)) === $suffix) {
            return true;
        }
    }
    return false;
}

/**
 * Baut die direkte favicon.ico-URL der Zielseite selbst (Standardpfad),
 * unter Beibehaltung von Schema/Host/Port aus der Link-URL.
 */
function direct_favicon_url(string $linkUrl): string
{
    $parts = parse_url($linkUrl);
    if (empty($parts['host'])) {
        return '';
    }
    $scheme = $parts['scheme'] ?? 'http';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return "{$scheme}://{$parts['host']}{$port}/favicon.ico";
}

/**
 * Liefert die Anzeige-URL für ein Favicon: bevorzugt lokal gecacht
 * (uploads/favicons/); sonst bei internen Hosts direkt das favicon.ico der
 * Zielseite, bei öffentlichen Hosts Live-Fallback auf den Google-Favicon-Dienst.
 */
function get_favicon_display_url(string $linkUrl): string
{
    $host = parse_url($linkUrl, PHP_URL_HOST);
    if (!$host) {
        return '';
    }
    $cache = read_json_or_default(FAVICON_CACHE_FILE, []);
    if (isset($cache[$host]['path']) && file_exists(__DIR__ . '/../' . $cache[$host]['path'])) {
        return $cache[$host]['path'];
    }
    if (is_private_host($host)) {
        return direct_favicon_url($linkUrl);
    }
    return favicon_url($linkUrl);
}

/**
 * Sammelt Diagnose-Informationen zur PHP-/Server-Umgebung, um Probleme beim
 * Favicon-Download (z.B. Schreibrechte, fehlende Extensions, blockierte
 * Netzwerkzugriffe) ohne Server-Zugriff von aussen eingrenzen zu können.
 */
function favicon_cache_diagnostics(): array
{
    $diag = [];

    $diag['php_version'] = PHP_VERSION;
    $diag['os'] = PHP_OS_FAMILY ?? PHP_OS;
    $diag['sapi'] = php_sapi_name();
    $diag['curl_extension'] = function_exists('curl_init') ? 'aktiv' : 'NICHT aktiv (Fallback auf file_get_contents)';
    $diag['allow_url_fopen'] = ini_get('allow_url_fopen') ? 'On' : 'Off';
    $diag['openssl_extension'] = extension_loaded('openssl') ? 'aktiv' : 'NICHT aktiv (HTTPS-Downloads könnten fehlschlagen)';
    $diag['open_basedir'] = ini_get('open_basedir') ?: '(nicht gesetzt)';
    $diag['disable_functions'] = ini_get('disable_functions') ?: '(keine)';

    // Prozessbenutzer ermitteln (auf Windows meist nicht per posix verfügbar)
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pwuid = posix_getpwuid(posix_geteuid());
        $diag['process_user'] = $pwuid['name'] ?? '(unbekannt)';
    } elseif (function_exists('get_current_user')) {
        $diag['process_user'] = get_current_user() . ' (Dateibesitzer des Skripts, nicht zwingend der ausführende Prozessbenutzer — unter IIS z.B. die App-Pool-Identität)';
    } else {
        $diag['process_user'] = '(nicht ermittelbar)';
    }

    $diag['favicon_dir_configured'] = FAVICON_DIR;
    $diag['favicon_dir_realpath'] = realpath(FAVICON_DIR) ?: '(existiert nicht / nicht auflösbar)';
    $diag['favicon_dir_exists'] = is_dir(FAVICON_DIR) ? 'ja' : 'nein';
    $diag['favicon_dir_is_writable_check'] = is_dir(FAVICON_DIR) && is_writable(FAVICON_DIR) ? 'ja (laut PHP is_writable)' : 'nein (laut PHP is_writable — auf Windows nicht immer zuverlässig, echter Schreibtest folgt)';

    // Echter Schreibtest (aussagekräftiger als is_writable(), v.a. unter Windows)
    if (is_dir(FAVICON_DIR)) {
        $testFile = FAVICON_DIR . '/.diag-write-test-' . uniqid();
        $result = @file_put_contents($testFile, 'diag');
        if ($result === false) {
            $lastError = error_get_last();
            $diag['write_test_uploads_favicons'] = 'FEHLGESCHLAGEN: ' . ($lastError['message'] ?? 'unbekannter Fehler');
        } else {
            $diag['write_test_uploads_favicons'] = 'erfolgreich (' . $result . ' Bytes geschrieben)';
            @unlink($testFile);
        }
    } else {
        $diag['write_test_uploads_favicons'] = 'übersprungen (Ordner existiert nicht)';
    }

    // WICHTIG: favicon_cache.json, links.json, config.json und audit_log.json liegen alle
    // in data/ — einem ANDEREN Verzeichnis als uploads/favicons/. Wenn nur uploads/ beschreibbar
    // gemacht wurde, kann trotzdem der komplette Cache-Index (data/favicon_cache.json) nicht
    // gespeichert werden, obwohl die einzelnen Icon-Dateien erfolgreich herunterladen würden.
    $diag['data_dir_configured'] = DATA_DIR;
    $diag['data_dir_realpath'] = realpath(DATA_DIR) ?: '(existiert nicht / nicht auflösbar)';
    if (is_dir(DATA_DIR)) {
        $testFile2 = DATA_DIR . '/.diag-write-test-' . uniqid();
        $result2 = @file_put_contents($testFile2, 'diag');
        if ($result2 === false) {
            $lastError2 = error_get_last();
            $diag['write_test_data'] = 'FEHLGESCHLAGEN: ' . ($lastError2['message'] ?? 'unbekannter Fehler') . ' — data/-Ordner braucht ebenfalls Schreibrechte für die App-Pool-Identität!';
        } else {
            $diag['write_test_data'] = 'erfolgreich (' . $result2 . ' Bytes geschrieben)';
            @unlink($testFile2);
        }
    } else {
        $diag['write_test_data'] = 'übersprungen (Ordner existiert nicht)';
    }

    // Wie viele Links/Hosts würde "Favicons aktualisieren" überhaupt verarbeiten?
    $linksData = get_links_data();
    $webLinks = 0;
    $uniqueHosts = [];
    foreach (($linksData['links'] ?? []) as $link) {
        if (($link['type'] ?? 'web') === 'rdp') {
            continue;
        }
        $webLinks++;
        $h = parse_url($link['url'] ?? '', PHP_URL_HOST);
        if ($h) {
            $uniqueHosts[$h] = true;
        }
    }
    $diag['links_total'] = count($linksData['links'] ?? []);
    $diag['links_web_type'] = $webLinks;
    $diag['unique_hosts_found'] = count($uniqueHosts);
    $diag['favicon_cache_file_exists'] = file_exists(FAVICON_CACHE_FILE) ? 'ja' : 'nein (wird beim ersten erfolgreichen Lauf angelegt)';

    // Test-Download einer bekannten externen Ressource (prüft Netzwerkzugriff getrennt vom Schreibtest)
    $error = null;
    $testDownload = fetch_remote_binary('https://www.google.com/favicon.ico', 6, $error);
    if ($testDownload !== null) {
        $diag['network_test_google'] = 'erfolgreich (' . strlen($testDownload) . ' Bytes empfangen)' . ($error ? ' — ' . $error : '');
    } else {
        $diag['network_test_google'] = 'FEHLGESCHLAGEN: ' . ($error ?? 'unbekannter Fehler');
    }

    return $diag;
}

/**
 * Lädt Favicons für alle in links.json vorkommenden Hosts herunter und
 * speichert sie lokal in uploads/favicons/. Interne Hosts: direkt von der
 * Zielseite selbst (favicon.ico). Öffentliche Hosts: über den Google-Dienst.
 *
 * WICHTIG bei vielen Links (z.B. viele interne Geräte mit je eigener IP):
 * Ein einzelner Lauf könnte sonst PHPs max_execution_time überschreiten und
 * mitten im Lauf sang- und klanglos abbrechen (leere/kaputte Antwort, kein
 * Fehler im Frontend sichtbar). Dagegen mehrere Schutzmassnahmen:
 *  1) Bereits erfolgreich gecachte Hosts werden übersprungen — mehrfaches
 *     Klicken "füllt" den Cache schrittweise auf, statt jedes Mal alles neu
 *     zu versuchen. "Cache leeren" erzwingt einen kompletten Neuversuch.
 *  2) Ein Zeitbudget (Default 20s) bricht den Lauf sauber ab und meldet,
 *     wie viele Hosts noch offen sind — statt dass PHP/IIS den Prozess killt.
 *  3) Interne/private Hosts werden standardmässig GAR NICHT versucht
 *     ($includePrivateHosts = false): hat der Server (anders als die Browser
 *     der Anwender) keinen Netzwerkzugriff auf diese Geräte, würde sonst
 *     JEDER Lauf das komplette Zeitbudget mit Verbindungstimeouts auf genau
 *     diese Hosts verschwenden, bevor auch nur ein externer Host (die i.d.R.
 *     schnell erfolgreich sind) drankommt. Da der Browser der Anwender
 *     interne Favicons ohnehin direkt selbst lädt (siehe get_favicon_display_url()),
 *     ist serverseitiges Cachen dafür nur ein Bonus, kein Muss.
 *
 * Gibt eine Ergebnis-Zusammenfassung inkl. Fehlerdetails zurück (für Diagnose).
 */
function refresh_favicon_cache(int $timeBudgetSeconds = 20, bool $includePrivateHosts = false): array
{
    if (function_exists('set_time_limit')) {
        @set_time_limit($timeBudgetSeconds + 15); // Sicherheitsmarge über dem eigenen Zeitbudget
    }
    $startTime = microtime(true);

    $data = get_links_data();
    $publicHostSamples = [];
    $privateHostSamples = [];
    foreach (($data['links'] ?? []) as $link) {
        if (($link['type'] ?? 'web') === 'rdp') {
            continue; // RDP-Ziele haben kein Web-Favicon
        }
        $url = $link['url'] ?? '';
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            continue;
        }
        if (is_private_host($host)) {
            if (!isset($privateHostSamples[$host])) {
                $privateHostSamples[$host] = $url;
            }
        } else {
            if (!isset($publicHostSamples[$host])) {
                $publicHostSamples[$host] = $url;
            }
        }
    }

    // WICHTIG: Öffentliche Hosts ZUERST verarbeiten. Interne Geräte sind oft vom
    // Server aus gar nicht erreichbar (anderes Netzsegment als die Anwender-PCs),
    // dann frisst jeder Versuch das komplette Zeitbudget mit Timeouts auf, bevor
    // auch nur ein einziger (schnell erfolgreicher) externer Host drankommt.
    // Ausserdem ist serverseitiges Cachen für interne Hosts ohnehin nur ein Bonus:
    // der Browser der Anwender lädt deren favicon.ico bereits direkt selbst (siehe
    // get_favicon_display_url()) — das funktioniert unabhängig vom Server-Cache.
    $hostSamples = $publicHostSamples;
    $skippedPrivateNotAttempted = 0;
    if ($includePrivateHosts) {
        $hostSamples += $privateHostSamples;
    } else {
        $skippedPrivateNotAttempted = count($privateHostSamples);
    }
    $fullTotal = count($publicHostSamples) + count($privateHostSamples);

    if (!is_dir(FAVICON_DIR)) {
        @mkdir(FAVICON_DIR, 0755, true);
    }

    // Frühzeitige, klare Diagnose statt N unklarer Einzelfehler pro Host:
    // Verzeichnis muss existieren UND für den Webserver-Prozess beschreibbar sein
    // (auf Windows/IIS oft die App-Pool-Identität, z.B. "IIS APPPOOL\<Name>" oder IUSR).
    if (!is_dir(FAVICON_DIR)) {
        return [
            'success' => 0, 'failed' => 0, 'skipped_cached' => 0, 'skipped_time_budget' => 0,
            'skipped_private' => $skippedPrivateNotAttempted, 'total' => $fullTotal,
            'failed_details' => ['uploads/favicons/ konnte nicht angelegt werden — Schreibrechte auf uploads/ prüfen.'],
        ];
    }
    $writeTestFile = FAVICON_DIR . '/.write-test-' . uniqid();
    $writeResult = @file_put_contents($writeTestFile, 'test');
    if ($writeResult === false) {
        $lastError = error_get_last();
        $reason = $lastError['message'] ?? 'unbekannt';
        $realPath = realpath(FAVICON_DIR) ?: FAVICON_DIR;
        return [
            'success' => 0, 'failed' => 0, 'skipped_cached' => 0, 'skipped_time_budget' => 0,
            'skipped_private' => $skippedPrivateNotAttempted, 'total' => $fullTotal,
            'failed_details' => [
                "uploads/favicons/ ist nicht beschreibbar. PHP-Fehler: \"{$reason}\". "
                . "Tatsächlicher Pfad, den PHP verwendet: {$realPath} — bitte prüfen, ob das exakt der "
                . "Ordner ist, dessen Rechte angepasst wurden (IIS-Virtual-Directories/Mappings können hier abweichen). "
                . 'Nutze "Diagnose anzeigen" für weitere Details (PHP-Prozessbenutzer, open_basedir, etc.).',
            ],
        ];
    }
    @unlink($writeTestFile);

    // Zweiter, unabhängiger Schreibtest für data/ — dort liegt favicon_cache.json.
    // uploads/favicons/ beschreibbar zu machen reicht NICHT: ohne Schreibrechte auf data/
    // würden zwar ggf. einzelne Icon-Dateien landen, aber der Cache-Index (favicon_cache.json)
    // könnte nie gespeichert werden — das Frontend fände die Dateien dann nie wieder.
    $dataWriteTestFile = DATA_DIR . '/.write-test-' . uniqid();
    $dataWriteResult = @file_put_contents($dataWriteTestFile, 'test');
    if ($dataWriteResult === false) {
        $lastError = error_get_last();
        $reason = $lastError['message'] ?? 'unbekannt';
        $realDataPath = realpath(DATA_DIR) ?: DATA_DIR;
        return [
            'success' => 0, 'failed' => 0, 'skipped_cached' => 0, 'skipped_time_budget' => 0,
            'skipped_private' => $skippedPrivateNotAttempted, 'total' => $fullTotal,
            'failed_details' => [
                "data/ ist nicht beschreibbar (dort liegt favicon_cache.json). PHP-Fehler: \"{$reason}\". "
                . "Tatsächlicher Pfad: {$realDataPath} — die App-Pool-Identität braucht Schreibrechte "
                . "auch auf diesen Ordner, nicht nur auf uploads/. Unter Windows/IIS: Rechtsklick auf "
                . 'den "data"-Ordner → Eigenschaften → Sicherheit → gleiche Berechtigung wie für "uploads" eintragen.',
            ],
        ];
    }
    @unlink($dataWriteTestFile);

    $cache = read_json_or_default(FAVICON_CACHE_FILE, []);
    $success = 0;
    $failed = 0;
    $skippedCached = 0;
    $skippedTimeBudget = 0;
    $failedDetails = [];

    foreach ($hostSamples as $host => $sampleUrl) {
        // Bereits erfolgreich gecachte Hosts überspringen (Datei muss noch existieren)
        if (isset($cache[$host]['path']) && file_exists(__DIR__ . '/../' . $cache[$host]['path'])) {
            $skippedCached++;
            continue;
        }

        // Zeitbudget prüfen, BEVOR ein weiterer (potenziell langsamer) Request gestartet wird
        if (microtime(true) - $startTime > $timeBudgetSeconds) {
            $skippedTimeBudget++;
            continue;
        }

        $isPrivate = is_private_host($host);
        $remoteUrl = $isPrivate
            ? direct_favicon_url($sampleUrl)
            : 'https://www.google.com/s2/favicons?sz=64&domain=' . rawurlencode($host);

        // Kurze Timeouts: interne Geräte antworten (wenn erreichbar) i.d.R. sofort;
        // ist der Server vom LAN der Geräte getrennt, soll ein einzelner Timeout
        // nicht unnötig viel vom Zeitbudget auffressen.
        $error = null;
        $imageData = fetch_remote_binary($remoteUrl, $isPrivate ? 2 : 5, $error);
        if ($imageData === null) {
            $failed++;
            $failedDetails[] = $host . ($isPrivate ? ' (intern, direkt)' : ' (extern, Google)') . ': ' . ($error ?? 'unbekannter Fehler');
            continue;
        }
        $ext = $isPrivate ? 'ico' : 'png';
        $filename = preg_replace('/[^a-z0-9.-]/i', '_', $host) . '.' . $ext;
        $destination = FAVICON_DIR . '/' . $filename;
        if (file_put_contents($destination, $imageData) === false) {
            $failed++;
            $failedDetails[] = $host . ': lokal nicht speicherbar (Schreibrechte prüfen)';
            continue;
        }
        $cache[$host] = ['path' => FAVICON_URL . '/' . $filename, 'fetched_at' => time(), 'source' => $isPrivate ? 'direct' : 'google'];
        $success++;
    }

    $cacheWriteOk = write_json(FAVICON_CACHE_FILE, $cache);
    if (!$cacheWriteOk) {
        $failedDetails[] = 'data/favicon_cache.json konnte trotz erfolgreichem Vortest nicht gespeichert werden (evtl. durch Sicherheitssoftware blockiert oder Datei durch anderen Prozess gesperrt).';
    }
    return [
        'success' => $success,
        'failed' => $failed,
        'skipped_cached' => $skippedCached,
        'skipped_time_budget' => $skippedTimeBudget,
        'skipped_private' => $skippedPrivateNotAttempted,
        'total' => $fullTotal,
        'cache_index_saved' => $cacheWriteOk,
        'failed_details' => $failedDetails,
    ];
}

/**
 * Leert den lokalen Favicon-Cache (löscht Dateien + Cache-Index).
 */
function clear_favicon_cache(): void
{
    if (is_dir(FAVICON_DIR)) {
        foreach (glob(FAVICON_DIR . '/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    write_json(FAVICON_CACHE_FILE, []);
}

/**
 * Lädt eine Remote-Ressource als Binärdaten (via cURL, Fallback file_get_contents).
 * Gibt null bei Fehlern zurück, wirft keine Exception (fehlertolerant für Batch-Läufe).
 * Optional: $error (by reference) erhält eine kurze Fehlerbeschreibung — bei Erfolg
 * trotzdem gesetzt, falls z.B. die Zertifikatsprüfung übersprungen werden musste (Hinweis, kein Fehler).
 */
function fetch_remote_binary(string $url, int $timeoutSeconds = 8, ?string &$error = null): ?string
{
    if (function_exists('curl_init')) {
        $attempt = static function (bool $verifySsl) use ($url, $timeoutSeconds) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
                CURLOPT_SSL_VERIFYPEER => $verifySsl,
                CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
                CURLOPT_USERAGENT => 'Linksammlung-FaviconCache/1.0',
            ]);
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $errStr = curl_error($ch);
            curl_close($ch);
            return [$result, $httpCode, $errno, $errStr];
        };

        [$result, $httpCode, $errno, $curlError] = $attempt(true);

        // CURLE_PEER_FAILED_VERIFICATION(51) / CURLE_SSL_CACERT(60) / CURLE_SSL_CACERT_BADFILE(77):
        // fehlendes/fehlerhaftes CA-Zertifikat-Bundle — unter Windows-PHP sehr verbreitet, da dort
        // standardmässig kein CA-Bundle mitgeliefert wird. In diesem Fall automatisch ohne
        // Zertifikatsprüfung erneut versuchen — vertretbar, da hier nur öffentliche Icon-Bilder
        // geladen werden (kein Austausch sensibler Daten).
        $sslErrorCodes = [51, 60, 77];
        $retriedWithoutVerify = false;
        if ($result === false && in_array($errno, $sslErrorCodes, true)) {
            [$result, $httpCode, , $curlError] = $attempt(false);
            $retriedWithoutVerify = true;
        }

        if ($result === false) {
            $error = 'cURL: ' . ($curlError ?: 'unbekannter Netzwerkfehler');
            return null;
        }
        if ($httpCode >= 400 || $httpCode === 0) {
            $error = 'HTTP ' . $httpCode;
            return null;
        }
        if ($retriedWithoutVerify) {
            $error = 'Hinweis: ohne SSL-Zertifikatsprüfung geladen (CA-Bundle fehlt vermutlich in der PHP-Konfiguration — siehe README "SSL-Zertifikatsprobleme")';
        }
        return $result;
    }

    // Fallback ohne cURL-Extension
    $context = stream_context_create(['http' => ['timeout' => $timeoutSeconds, 'ignore_errors' => true]]);
    $result = @file_get_contents($url, false, $context);
    if ($result === false) {
        $lastErr = error_get_last();
        $error = 'file_get_contents: ' . ($lastErr['message'] ?? 'unbekannter Fehler') . ' (cURL-Extension nicht verfügbar?)';
        return null;
    }
    // WICHTIG: ignore_errors=true liefert auch bei 4xx/5xx den Antwort-Body zurück
    // (z.B. eine Fehlerseite) — ohne diese Prüfung würde eine Fehlerseite fälschlich
    // als "erfolgreich geladenes Favicon" gespeichert werden.
    $statusCode = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $statusCode = (int) $m[1];
            break;
        }
    }
    if ($statusCode >= 400 || $statusCode === 0) {
        $error = 'HTTP ' . ($statusCode ?: '(kein Statuscode ermittelbar)');
        return null;
    }
    return $result;
}

function get_config(): array
{
    return read_json(CONFIG_FILE);
}

function save_config(array $config): bool
{
    return write_json(CONFIG_FILE, $config);
}

function get_links_data(): array
{
    return read_json(LINKS_FILE);
}

function save_links_data(array $data): bool
{
    return write_json(LINKS_FILE, $data);
}

/**
 * Erzeugt eine einfache eindeutige ID (kein echtes UUID, reicht für diesen Zweck).
 */
function generate_id(string $prefix = 'id'): string
{
    return $prefix . '-' . bin2hex(random_bytes(6)) . '-' . time();
}

// --- Auth ------------------------------------------------------------------

function is_logged_in(): bool
{
    return !empty($_SESSION['ls_logged_in']) && $_SESSION['ls_logged_in'] === true;
}

/**
 * Erzwingt Login. Bei fehlender Auth: Redirect (Seiten) oder JSON 401 (API).
 */
function require_login(bool $isApi = false): void
{
    if (is_logged_in()) {
        return;
    }
    if ($isApi) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Nicht angemeldet']);
        exit;
    }
    header('Location: ../login.php');
    exit;
}

function verify_password(string $password): bool
{
    $config = get_config();
    if (empty($config['password_hash'])) {
        return false;
    }
    return password_verify($password, $config['password_hash']);
}

/**
 * Liefert das erste (auch mehrbyte-fähige) Zeichen eines Strings, ohne
 * Abhängigkeit von der mbstring-Extension (nutzt PCRE-Unicode-Support).
 */
function first_char(string $str): string
{
    if ($str === '') {
        return '?';
    }
    if (preg_match('/./us', $str, $m)) {
        return $m[0];
    }
    return substr($str, 0, 1);
}

/**
 * Wandelt einen String in Kleinbuchstaben um, inkl. Umlauten, unabhängig
 * davon ob die mbstring-Extension aktiv ist (Fallback auf strtolower).
 */
function mb_strtolower_safe(string $str): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($str, 'UTF-8');
    }
    // Fallback: ASCII-Kleinschreibung + gängige deutsche Umlaute manuell ersetzen
    $map = ['Ä' => 'ä', 'Ö' => 'ö', 'Ü' => 'ü', 'À' => 'à', 'É' => 'é'];
    return strtolower(strtr($str, $map));
}

/**
 * Leitet eine Favicon-URL aus einer Ziel-URL ab (via Google-Favicon-Dienst).
 * Für interne/private Hosts liefert der Dienst i.d.R. ein generisches Icon;
 * schlägt der Request ganz fehl, greift im Frontend der Buchstaben-Fallback (onerror).
 */
function favicon_url(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return '';
    }
    return 'https://www.google.com/s2/favicons?sz=64&domain=' . rawurlencode($host);
}

/**
 * Escaped Text für sichere HTML-Ausgabe.
 */
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Validiert eine URL grob (http/https Pflicht).
 */
function is_valid_url(string $url): bool
{
    return (bool) filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url);
}

// --- RDP-Links ----------------------------------------------------------

/**
 * Entfernt ein optionales "rdp://"-Präfix von einer RDP-Zieladresse.
 */
function normalize_rdp_target(string $raw): string
{
    return preg_replace('#^rdp://#i', '', trim($raw));
}

/**
 * Validiert eine RDP-Zieladresse: Hostname/IP, optional mit ":Port".
 */
function is_valid_rdp_target(string $target): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9.\-]+(:[0-9]{1,5})?$/', $target);
}

/**
 * Baut den Inhalt einer .rdp-Datei (Windows Remotedesktopverbindung / mstsc.exe).
 */
function build_rdp_file_content(string $target, string $username = ''): string
{
    $lines = [
        'full address:s:' . $target,
        'prompt for credentials:i:1',
        'screen mode id:i:2',
        'use multimon:i:0',
        'desktopwidth:i:1920',
        'desktopheight:i:1080',
        'session bpp:i:32',
        'compression:i:1',
        'keyboardhook:i:2',
        'audiocapturemode:i:0',
        'videoplaybackmode:i:1',
        'networkautodetect:i:1',
        'bandwidthautodetect:i:1',
        'displayconnectionbar:i:1',
        'authentication level:i:2',
        'redirectclipboard:i:1',
        'redirectprinters:i:0',
        'autoreconnection enabled:i:1',
    ];
    if ($username !== '') {
        array_splice($lines, 1, 0, ['username:s:' . $username]);
    }
    return implode("\r\n", $lines) . "\r\n";
}

/**
 * Liefert eine data:-URI mit dem .rdp-Dateiinhalt, direkt als href für <a download> nutzbar
 * (kein separates PHP-Skript/kein Extra-Request nötig).
 */
function rdp_data_uri(string $target, string $username = ''): string
{
    $content = build_rdp_file_content($target, $username);
    return 'data:application/x-rdp;base64,' . base64_encode($content);
}

/**
 * Erzeugt einen dateisystemsicheren Dateinamen (für den download-Attribut-Wert).
 */
function safe_filename(string $name): string
{
    $name = preg_replace('/[^a-zA-Z0-9äöüÄÖÜ_-]+/u', '_', $name);
    return trim($name, '_') ?: 'verbindung';
}

/**
 * Verschiebt eine hochgeladene Bilddatei nach uploads/ und gibt den relativen Pfad zurück.
 * Erlaubt: jpg, jpeg, png, gif, webp, svg. Max 5 MB.
 */
function handle_image_upload(array $file, string $targetBasename): array
{
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload-Fehler (Code ' . ($file['error'] ?? '?') . ')'];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'error' => 'Datei zu gross (max. 5 MB)'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!array_key_exists($ext, $allowed)) {
        return ['success' => false, 'error' => 'Dateityp nicht erlaubt (erlaubt: ' . implode(', ', array_keys($allowed)) . ')'];
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    $filename = $targetBasename . '-' . time() . '.' . $ext;
    $destination = UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => 'Datei konnte nicht gespeichert werden'];
    }

    return ['success' => true, 'path' => UPLOAD_URL . '/' . $filename];
}
