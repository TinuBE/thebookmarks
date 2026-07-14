<?php
require_once __DIR__ . '/includes/functions.php';

$config = get_config();
$data = get_links_data();

$siteTitle = h($config['site_title'] ?? 'Link-Sammlung');
$logo = $config['logo'] ?? '';
$background = $config['background'] ?? '';

// Kategorien nach "order" sortieren
$categories = $data['categories'] ?? [];
usort($categories, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

// Links nach Kategorie gruppieren
$linksByCategory = [];
foreach (($data['links'] ?? []) as $link) {
    $linksByCategory[$link['category_id']][] = $link;
}
foreach ($linksByCategory as &$catLinks) {
    usort($catLinks, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
}
unset($catLinks);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $siteTitle ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="apple-touch-icon" sizes="180x180" href="/uploads/favicons/local/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/uploads/favicons/local/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/uploads/favicons/local/favicon-16x16.png">
<link rel="manifest" href="/uploads/favicons/local/site.webmanifest">
</head>
<body<?= $background ? ' style="background-image: url(\'' . h($background) . '\'), linear-gradient(135deg, var(--color-bg-start), var(--color-bg-end))"' : '' ?> class="<?= $background ? 'has-bg' : '' ?>">

<header class="site-header">
    <?php if ($logo): ?>
        <img src="<?= h($logo) ?>" alt="Logo" class="site-logo">
    <?php endif; ?>
    <h1><?= $siteTitle ?></h1>
    <time id="live-clock" class="live-clock" aria-live="off"></time>
    <button type="button" id="about-btn" class="admin-link" title="Über / Copyright" aria-haspopup="dialog">?</button>
    <a href="login.php" class="admin-link" title="Backend">&#9881;</a>
</header>

<div class="search-bar">
    <input type="search" id="link-search" class="search-input" placeholder="Links durchsuchen&hellip;" autocomplete="off" aria-label="Links durchsuchen">
    <span id="search-count" class="search-count"></span>
</div>

<?php if (!empty($categories)): ?>
<nav class="category-nav" aria-label="Kategorien">
    <?php foreach ($categories as $cat): ?>
        <?php if (empty($linksByCategory[$cat['id']] ?? [])) continue; ?>
        <a href="#cat-<?= h($cat['id']) ?>" class="category-nav-pill"><?= h($cat['name']) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<main class="category-grid" id="category-grid">
    <?php if (empty($categories)): ?>
        <p class="empty-state">Noch keine Kategorien vorhanden. <a href="login.php">Im Backend anlegen &rarr;</a></p>
    <?php endif; ?>

    <?php foreach ($categories as $cat): ?>
        <?php $catLinks = $linksByCategory[$cat['id']] ?? []; ?>
        <?php if (empty($catLinks)) continue; ?>
        <section class="category-card glass" id="cat-<?= h($cat['id']) ?>" data-category-card>
            <h2><?= h($cat['name']) ?></h2>
            <ul class="link-list">
                <?php foreach ($catLinks as $link): ?>
                    <?php $linkType = $link['type'] ?? 'web'; ?>
                    <li data-title="<?= h(mb_strtolower_safe($link['title'])) ?>">
                        <?php if ($linkType === 'rdp'): ?>
                            <a href="<?= h(rdp_data_uri($link['url'], $link['rdp_username'] ?? '')) ?>"
                               download="<?= h(safe_filename($link['title'])) ?>.rdp" title="RDP-Verbindung herunterladen &amp; öffnen">
                                <span class="link-icon link-icon-fallback link-icon-rdp">&#128421;</span>
                                <span class="link-text">
                                    <span class="link-title"><?= h($link['title']) ?></span>
                                    <?php if (!empty($link['description'])): ?>
                                        <span class="link-description"><?= h($link['description']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </a>
                        <?php else: ?>
                            <a href="<?= h($link['url']) ?>" target="_blank" rel="noopener noreferrer">
                                <img class="link-icon" src="<?= h(get_favicon_display_url($link['url'])) ?>" alt=""
                                     loading="lazy" data-fallback="<?= h(first_char($link['title'])) ?>"
                                     onerror="handleFaviconError(this)">
                                <span class="link-text">
                                    <span class="link-title"><?= h($link['title']) ?></span>
                                    <?php if (!empty($link['description'])): ?>
                                        <span class="link-description"><?= h($link['description']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>

    <p id="no-results" class="empty-state" hidden>Keine Links gefunden.</p>
</main>

<footer class="site-footer">
    <span>Link-Sammlung</span>
</footer>

<dialog id="about-dialog" class="about-dialog">
    <div class="about-content">
        <button type="button" id="about-close" class="about-close" aria-label="Schliessen">&times;</button>
        <h2>Über diese Seite</h2>
        <p class="about-copyright">
            &copy; 2026 by<br>
            Martin Loosli<br>
            MTF Solutions AG<br>
            Stauffacherstrasse 31<br>
            3014 Bern<br>
            Schweiz
        </p>
    </div>
</dialog>

<script src="assets/js/app.js"></script>
</body>
</html>
