<?php
/**
 * export.php
 * Liefert Kategorien + Links als herunterladbare JSON-Datei aus.
 * Enthält bewusst NICHT: password_hash, Logo-/Hintergrundpfade (serverspezifisch).
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();

$data = get_links_data();
$export = [
    'categories' => $data['categories'] ?? [],
    'links' => $data['links'] ?? [],
    'exported_at' => date('c'),
];

$json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$filename = 'linksammlung-export-' . date('Ymd-His') . '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($json));
echo $json;
