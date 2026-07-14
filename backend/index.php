<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

$config = get_config();
$siteTitle = h($config['site_title'] ?? 'Link-Sammlung');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Backend &ndash; <?= $siteTitle ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="apple-touch-icon" sizes="180x180" href="/uploads/favicons/local/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/uploads/favicons/local/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/uploads/favicons/local/favicon-16x16.png">
<link rel="manifest" href="/uploads/favicons/local/site.webmanifest">
</head>
<body class="backend-body">

<header class="site-header">
    <h1>Backend: <?= $siteTitle ?></h1>
    <nav class="backend-nav">
        <a href="../index.php" target="_blank">Frontend ansehen</a>
        <a href="../logout.php">Abmelden</a>
    </nav>
</header>

<main class="backend-main">

    <!-- Alert-Bereich für JS-Feedback -->
    <div id="alert-box"></div>

    <section class="panel glass">
        <h2>Einstellungen</h2>
        <form id="settings-form" class="settings-form" enctype="multipart/form-data">
            <div class="form-row">
                <label for="site_title">Seitentitel</label>
                <input type="text" id="site_title" name="site_title" value="<?= h($config['site_title'] ?? '') ?>" required>
            </div>
            <div class="form-row">
                <label for="logo_file">Logo</label>
                <input type="file" id="logo_file" name="logo_file" accept="image/*">
                <?php if (!empty($config['logo'])): ?>
                    <img class="preview-thumb" src="../<?= h($config['logo']) ?>" alt="Logo-Vorschau">
                <?php endif; ?>
            </div>
            <div class="form-row">
                <label for="background_file">Hintergrundbild</label>
                <input type="file" id="background_file" name="background_file" accept="image/*">
                <?php if (!empty($config['background'])): ?>
                    <img class="preview-thumb" src="../<?= h($config['background']) ?>" alt="Hintergrund-Vorschau">
                <?php endif; ?>
            </div>
            <button type="submit">Einstellungen speichern</button>
        </form>
    </section>

    <section class="panel glass">
        <h2>Kategorien</h2>
        <form id="category-form" class="inline-form">
            <input type="text" id="category_name" placeholder="Neue Kategorie..." required>
            <button type="submit">Hinzufügen</button>
        </form>
        <p class="panel-hint">Ziehen (☰) zum Sortieren.</p>
        <ul id="category-list" class="admin-list"></ul>
    </section>

    <section class="panel glass">
        <h2>Links</h2>
        <p class="panel-hint">Favicons werden automatisch anhand der URL geladen &ndash; kein manuelles Icon nötig. Innerhalb einer Kategorie per Ziehen (☰) sortierbar.</p>
        <form id="link-form" class="link-form">
            <input type="hidden" id="link_id" value="">
            <div class="form-row">
                <label for="link_title">Titel</label>
                <input type="text" id="link_title" required>
            </div>
            <div class="form-row">
                <label for="link_type">Typ</label>
                <select id="link_type">
                    <option value="web">Web-Link</option>
                    <option value="rdp">RDP-Verbindung</option>
                </select>
            </div>
            <div class="form-row">
                <label for="link_url" id="link_url_label">URL</label>
                <input type="text" id="link_url" placeholder="https://..." required>
                <p class="field-hint" id="link_url_hint" hidden>Server-Adresse, z.B. <code>192.168.1.10</code> oder <code>server:3389</code>. Öffnet beim Klick eine .rdp-Datei (Remotedesktopverbindung / mstsc.exe).</p>
            </div>
            <div class="form-row" id="link_rdp_username_row" hidden>
                <label for="link_rdp_username">Benutzername (optional)</label>
                <input type="text" id="link_rdp_username" placeholder="z.B. DOMÄNE\\benutzer">
            </div>
            <div class="form-row">
                <label for="link_description">Beschreibung (optional)</label>
                <textarea id="link_description" rows="2" placeholder="Kurzbeschreibung, wird unter dem Link angezeigt"></textarea>
            </div>
            <div class="form-row">
                <label for="link_category">Kategorie</label>
                <select id="link_category" required></select>
            </div>
            <div class="form-actions">
                <button type="submit" id="link-submit-btn">Link hinzufügen</button>
                <button type="button" id="link-cancel-btn" class="btn-secondary" style="display:none;">Abbrechen</button>
            </div>
        </form>
        <div id="link-list" class="admin-tree"></div>
    </section>

    <section class="panel glass">
        <h2>Wartung</h2>

        <div class="maintenance-block">
            <h3>Favicon-Cache</h3>
            <p class="panel-hint">Lädt Favicons aller Links einmalig lokal herunter (<code>uploads/favicons/</code>) &ndash; schneller und unabhängig vom Google-Dienst. Interne Geräte laden ihr Favicon im Frontend ohnehin direkt selbst (über den Browser der Anwender) &ndash; serverseitiges Cachen ist dafür standardmässig deaktiviert, da der Server oft keinen Netzwerkzugriff auf diese Geräte hat.</p>
            <label class="checkbox-row">
                <input type="checkbox" id="favicon-include-private">
                Auch interne Geräte versuchen (nur sinnvoll, falls dieser Server im gleichen Netzwerk wie die Geräte steht &ndash; sonst nur langsame Fehlschläge)
            </label>
            <div class="form-actions">
                <button type="button" id="refresh-favicons-btn">Favicons aktualisieren</button>
                <button type="button" id="clear-favicons-btn" class="btn-secondary">Cache leeren</button>
                <button type="button" id="favicon-diag-btn" class="btn-secondary">Diagnose anzeigen</button>
            </div>
            <dl id="favicon-diag-output" class="diag-output" hidden></dl>
        </div>

        <div class="maintenance-block">
            <h3>Export</h3>
            <p class="panel-hint">Lädt alle Kategorien &amp; Links als JSON-Datei herunter (Backup / Migration).</p>
            <a href="export.php" class="btn-link-download">JSON exportieren</a>
        </div>

        <div class="maintenance-block">
            <h3>Import</h3>
            <p class="panel-hint">
                <strong>Achtung:</strong> überschreibt alle bestehenden Kategorien &amp; Links.
                Vor dem Import wird automatisch eine Sicherheitskopie (<code>data/links.backup-*.json</code>) angelegt.
            </p>
            <form id="import-form" class="inline-form" enctype="multipart/form-data">
                <input type="file" id="import_file" accept="application/json,.json" required>
                <button type="submit" class="btn-danger">Importieren</button>
            </form>
        </div>
    </section>

    <section class="panel glass">
        <h2>Änderungsprotokoll</h2>
        <div class="form-actions" style="margin-bottom: 14px;">
            <button type="button" id="clear-log-btn" class="btn-secondary">Protokoll leeren</button>
        </div>
        <ul id="audit-log-list" class="admin-list"></ul>
    </section>

</main>

<script src="../assets/js/admin.js"></script>
</body>
</html>
