/**
 * app.js — Frontend-Logik der Link-Sammlung (öffentliche Seite).
 * Zwei Aufgaben: 1) Live-Suche über alle Links, 2) Uhrzeit/Datum-Anzeige.
 * Zusätzlich: Fallback für automatisch geladene Favicons.
 * Vanilla JS, keine Abhängigkeiten.
 */

/**
 * Wird per onerror am <img class="link-icon"> aufgerufen, falls das
 * automatisch geladene Favicon nicht abrufbar ist (z.B. interne/private URLs).
 * Ersetzt das <img> durch den Buchstaben-Fallback.
 */
function handleFaviconError(img) {
    const fallback = document.createElement('span');
    fallback.className = 'link-icon link-icon-fallback';
    fallback.textContent = img.dataset.fallback || '?';
    img.replaceWith(fallback);
}

(function () {
    'use strict';

    // --- Uhrzeit / Datum ----------------------------------------------------

    function updateClock() {
        const el = document.getElementById('live-clock');
        if (!el) return;
        const now = new Date();
        const datePart = now.toLocaleDateString('de-CH', {
            weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric'
        });
        const timePart = now.toLocaleTimeString('de-CH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        el.textContent = `${datePart}, ${timePart}`;
        el.setAttribute('datetime', now.toISOString());
    }

    updateClock();
    setInterval(updateClock, 1000);

    // --- About/Copyright-Dialog ------------------------------------------

    const aboutBtn = document.getElementById('about-btn');
    const aboutDialog = document.getElementById('about-dialog');
    const aboutClose = document.getElementById('about-close');

    if (aboutBtn && aboutDialog) {
        aboutBtn.addEventListener('click', () => aboutDialog.showModal());
        aboutClose.addEventListener('click', () => aboutDialog.close());
        // Klick auf den Backdrop (ausserhalb des Inhalts) schliesst den Dialog
        aboutDialog.addEventListener('click', (e) => {
            if (e.target === aboutDialog) aboutDialog.close();
        });
    }

    // --- Live-Suche ----------------------------------------------------------

    const searchInput = document.getElementById('link-search');
    const searchCount = document.getElementById('search-count');
    const noResults = document.getElementById('no-results');
    const categoryCards = document.querySelectorAll('[data-category-card]');

    if (!searchInput) return;

    function applyFilter() {
        const query = searchInput.value.trim().toLowerCase();
        let totalVisible = 0;

        categoryCards.forEach((card) => {
            const items = card.querySelectorAll('li[data-title]');
            let visibleInCard = 0;

            items.forEach((li) => {
                const matches = query === '' || li.dataset.title.includes(query);
                li.hidden = !matches;
                if (matches) visibleInCard++;
            });

            card.hidden = visibleInCard === 0;
            totalVisible += visibleInCard;

            const navPill = document.querySelector(`.category-nav-pill[href="#${card.id}"]`);
            if (navPill) navPill.hidden = visibleInCard === 0;
        });

        if (query === '') {
            searchCount.textContent = '';
        } else {
            searchCount.textContent = `${totalVisible} Treffer`;
        }

        if (noResults) {
            noResults.hidden = !(query !== '' && totalVisible === 0);
        }
    }

    searchInput.addEventListener('input', applyFilter);

    // Tastenkürzel "/" fokussiert die Suche (ausser wenn bereits in einem Feld getippt wird)
    document.addEventListener('keydown', (e) => {
        if (e.key === '/' && document.activeElement !== searchInput
            && !['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) {
            e.preventDefault();
            searchInput.focus();
        }
        if (e.key === 'Escape' && document.activeElement === searchInput) {
            searchInput.value = '';
            applyFilter();
            searchInput.blur();
        }
    });
})();
