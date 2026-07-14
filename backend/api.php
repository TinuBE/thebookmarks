<?php
/**
 * api.php
 * Zentraler API-Endpoint fürs Backend (AJAX). Alle Antworten als JSON.
 * Erwartet Parameter "action" (POST oder GET).
 */
require_once __DIR__ . '/../includes/functions.php';
require_login(true);

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function respond(bool $success, array $extra = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success], $extra));
    exit;
}

try {
    switch ($action) {

        // ---------------------------------------------------------------
        case 'get_all':
            respond(true, [
                'config' => get_config(),
                'data' => get_links_data(),
            ]);
            break;

        // --- Kategorien ---------------------------------------------------
        case 'add_category':
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                respond(false, ['error' => 'Name darf nicht leer sein'], 400);
            }
            $data = get_links_data();
            $data['categories'] = $data['categories'] ?? [];
            $maxOrder = 0;
            foreach ($data['categories'] as $c) {
                $maxOrder = max($maxOrder, $c['order'] ?? 0);
            }
            $newCat = ['id' => generate_id('cat'), 'name' => $name, 'order' => $maxOrder + 1];
            $data['categories'][] = $newCat;
            save_links_data($data);
            log_action('Kategorie erstellt', $name);
            respond(true, ['category' => $newCat]);
            break;

        case 'rename_category':
            $id = (string)($_POST['id'] ?? '');
            $name = trim((string)($_POST['name'] ?? ''));
            if ($id === '' || $name === '') {
                respond(false, ['error' => 'Ungültige Angaben'], 400);
            }
            $data = get_links_data();
            $found = false;
            $oldName = '';
            foreach ($data['categories'] as &$c) {
                if ($c['id'] === $id) {
                    $oldName = $c['name'];
                    $c['name'] = $name;
                    $found = true;
                    break;
                }
            }
            unset($c);
            if (!$found) {
                respond(false, ['error' => 'Kategorie nicht gefunden'], 404);
            }
            save_links_data($data);
            log_action('Kategorie umbenannt', "{$oldName} → {$name}");
            respond(true);
            break;

        case 'reorder_categories':
            // Erwartet order[] = Array von Kategorie-IDs in neuer Reihenfolge
            $order = $_POST['order'] ?? [];
            if (!is_array($order)) {
                respond(false, ['error' => 'Ungültige Reihenfolge'], 400);
            }
            $data = get_links_data();
            $position = 1;
            foreach ($order as $catId) {
                foreach ($data['categories'] as &$c) {
                    if ($c['id'] === $catId) {
                        $c['order'] = $position;
                    }
                }
                unset($c);
                $position++;
            }
            save_links_data($data);
            log_action('Kategorien neu sortiert');
            respond(true);
            break;

        case 'delete_category':
            $id = (string)($_POST['id'] ?? '');
            $data = get_links_data();
            $deletedName = '';
            foreach ($data['categories'] as $c) {
                if ($c['id'] === $id) {
                    $deletedName = $c['name'];
                }
            }
            $data['categories'] = array_values(array_filter($data['categories'], fn($c) => $c['id'] !== $id));
            // zugehörige Links ebenfalls entfernen
            $data['links'] = array_values(array_filter($data['links'], fn($l) => $l['category_id'] !== $id));
            save_links_data($data);
            log_action('Kategorie gelöscht', $deletedName);
            respond(true);
            break;

        // --- Links ---------------------------------------------------------
        case 'add_link':
            $title = trim((string)($_POST['title'] ?? ''));
            $url = trim((string)($_POST['url'] ?? ''));
            $categoryId = (string)($_POST['category_id'] ?? '');
            $description = trim((string)($_POST['description'] ?? ''));
            $type = ($_POST['type'] ?? 'web') === 'rdp' ? 'rdp' : 'web';
            $rdpUsername = trim((string)($_POST['rdp_username'] ?? ''));
            if (strlen($description) > 500) {
                $description = substr($description, 0, 500);
            }

            if ($title === '' || $categoryId === '') {
                respond(false, ['error' => 'Titel und Kategorie sind Pflicht'], 400);
            }

            if ($type === 'rdp') {
                $url = normalize_rdp_target($url);
                if (!is_valid_rdp_target($url)) {
                    respond(false, ['error' => 'Server-Adresse ist ungültig (erwartet: Hostname/IP, optional mit :Port)'], 400);
                }
            } else {
                if (!is_valid_url($url)) {
                    respond(false, ['error' => 'URL ist ungültig (http/https erforderlich)'], 400);
                }
            }

            $data = get_links_data();
            $maxOrder = 0;
            foreach ($data['links'] as $l) {
                if ($l['category_id'] === $categoryId) {
                    $maxOrder = max($maxOrder, $l['order'] ?? 0);
                }
            }
            $newLink = [
                'id' => generate_id('link'),
                'title' => $title,
                'url' => $url,
                'category_id' => $categoryId,
                'description' => $description,
                'type' => $type,
                'rdp_username' => $type === 'rdp' ? $rdpUsername : '',
                'order' => $maxOrder + 1,
            ];
            $data['links'][] = $newLink;
            save_links_data($data);
            log_action('Link erstellt', $title . ($type === 'rdp' ? ' (RDP)' : ''));
            respond(true, ['link' => $newLink]);
            break;

        case 'edit_link':
            $id = (string)($_POST['id'] ?? '');
            $title = trim((string)($_POST['title'] ?? ''));
            $url = trim((string)($_POST['url'] ?? ''));
            $categoryId = (string)($_POST['category_id'] ?? '');
            $description = trim((string)($_POST['description'] ?? ''));
            $type = ($_POST['type'] ?? 'web') === 'rdp' ? 'rdp' : 'web';
            $rdpUsername = trim((string)($_POST['rdp_username'] ?? ''));
            if (strlen($description) > 500) {
                $description = substr($description, 0, 500);
            }

            if ($id === '' || $title === '' || $categoryId === '') {
                respond(false, ['error' => 'Pflichtfelder fehlen'], 400);
            }

            if ($type === 'rdp') {
                $url = normalize_rdp_target($url);
                if (!is_valid_rdp_target($url)) {
                    respond(false, ['error' => 'Server-Adresse ist ungültig (erwartet: Hostname/IP, optional mit :Port)'], 400);
                }
            } else {
                if (!is_valid_url($url)) {
                    respond(false, ['error' => 'URL ist ungültig (http/https erforderlich)'], 400);
                }
            }

            $data = get_links_data();
            $found = false;
            foreach ($data['links'] as &$l) {
                if ($l['id'] === $id) {
                    $l['title'] = $title;
                    $l['url'] = $url;
                    $l['category_id'] = $categoryId;
                    $l['description'] = $description;
                    $l['type'] = $type;
                    $l['rdp_username'] = $type === 'rdp' ? $rdpUsername : '';
                    $found = true;
                    break;
                }
            }
            unset($l);
            if (!$found) {
                respond(false, ['error' => 'Link nicht gefunden'], 404);
            }
            save_links_data($data);
            log_action('Link bearbeitet', $title . ($type === 'rdp' ? ' (RDP)' : ''));
            respond(true);
            break;

        case 'delete_link':
            $id = (string)($_POST['id'] ?? '');
            $data = get_links_data();
            $deletedTitle = '';
            foreach ($data['links'] as $l) {
                if ($l['id'] === $id) {
                    $deletedTitle = $l['title'];
                }
            }
            $data['links'] = array_values(array_filter($data['links'], fn($l) => $l['id'] !== $id));
            save_links_data($data);
            log_action('Link gelöscht', $deletedTitle);
            respond(true);
            break;

        case 'reorder_links':
            // order[] = Link-IDs in neuer Reihenfolge (innerhalb einer Kategorie)
            $order = $_POST['order'] ?? [];
            if (!is_array($order)) {
                respond(false, ['error' => 'Ungültige Reihenfolge'], 400);
            }
            $data = get_links_data();
            $position = 1;
            foreach ($order as $linkId) {
                foreach ($data['links'] as &$l) {
                    if ($l['id'] === $linkId) {
                        $l['order'] = $position;
                    }
                }
                unset($l);
                $position++;
            }
            save_links_data($data);
            log_action('Links neu sortiert');
            respond(true);
            break;

        // --- Einstellungen ---------------------------------------------
        case 'update_settings':
            $config = get_config();
            $siteTitle = trim((string)($_POST['site_title'] ?? ''));
            if ($siteTitle !== '') {
                $config['site_title'] = $siteTitle;
            }

            if (!empty($_FILES['logo_file']['name'])) {
                $result = handle_image_upload($_FILES['logo_file'], 'logo');
                if (!$result['success']) {
                    respond(false, ['error' => $result['error']], 400);
                }
                $config['logo'] = $result['path'];
            }

            if (!empty($_FILES['background_file']['name'])) {
                $result = handle_image_upload($_FILES['background_file'], 'background');
                if (!$result['success']) {
                    respond(false, ['error' => $result['error']], 400);
                }
                $config['background'] = $result['path'];
            }

            save_config($config);
            log_action('Einstellungen geändert', $siteTitle !== '' ? "Titel: {$siteTitle}" : '');
            respond(true, ['config' => $config]);
            break;

        case 'change_password':
            $newPassword = (string)($_POST['new_password'] ?? '');
            if (strlen($newPassword) < 8) {
                respond(false, ['error' => 'Passwort muss mindestens 8 Zeichen haben'], 400);
            }
            $config = get_config();
            $config['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT);
            save_config($config);
            log_action('Passwort geändert');
            respond(true);
            break;

        // --- Favicon-Cache ------------------------------------------------
        case 'refresh_favicons':
            $includePrivate = !empty($_POST['include_private']);
            $result = refresh_favicon_cache(20, $includePrivate);
            $logDetail = "{$result['success']} ok, {$result['failed']} fehlgeschlagen, "
                . "{$result['skipped_cached']} bereits gecacht, {$result['skipped_time_budget']} wegen Zeitbudget übersprungen, "
                . "{$result['skipped_private']} interne Hosts nicht versucht (von {$result['total']})";
            if (!empty($result['failed_details'])) {
                $logDetail .= ' — ' . implode('; ', array_slice($result['failed_details'], 0, 3));
            }
            log_action('Favicon-Cache aktualisiert', $logDetail);
            respond(true, $result);
            break;

        case 'clear_favicon_cache':
            clear_favicon_cache();
            log_action('Favicon-Cache geleert');
            respond(true);
            break;

        case 'favicon_diagnostics':
            respond(true, ['diagnostics' => favicon_cache_diagnostics()]);
            break;

        // --- Import / Export -----------------------------------------------
        case 'import_data':
            if (empty($_FILES['import_file']['name'])) {
                respond(false, ['error' => 'Keine Datei ausgewählt'], 400);
            }
            if ($_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                respond(false, ['error' => 'Upload-Fehler'], 400);
            }
            $raw = file_get_contents($_FILES['import_file']['tmp_name']);
            $imported = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($imported)) {
                respond(false, ['error' => 'Datei ist kein gültiges JSON'], 400);
            }
            if (!isset($imported['categories']) || !isset($imported['links'])
                || !is_array($imported['categories']) || !is_array($imported['links'])) {
                respond(false, ['error' => 'JSON hat nicht die erwartete Struktur (categories/links fehlen)'], 400);
            }

            // Sicherheitskopie der aktuellen Daten vor dem Überschreiben
            $backupPath = DATA_DIR . '/links.backup-' . date('Ymd-His') . '.json';
            @copy(LINKS_FILE, $backupPath);

            save_links_data(['categories' => $imported['categories'], 'links' => $imported['links']]);
            log_action('Daten importiert', count($imported['categories']) . ' Kategorien, ' . count($imported['links']) . ' Links');
            respond(true, [
                'categories' => count($imported['categories']),
                'links' => count($imported['links']),
            ]);
            break;

        // --- Audit-Log ----------------------------------------------------
        case 'get_audit_log':
            $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 50);
            respond(true, ['entries' => get_audit_log($limit)]);
            break;

        case 'clear_audit_log':
            clear_audit_log();
            respond(true);
            break;

        default:
            respond(false, ['error' => 'Unbekannte Aktion'], 400);
    }
} catch (Throwable $e) {
    respond(false, ['error' => 'Serverfehler: ' . $e->getMessage()], 500);
}
