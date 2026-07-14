/**
 * admin.js — Backend-Logik für die Link-Sammlung.
 * Vanilla JS, kein Build-Step. Kommuniziert per fetch() mit api.php.
 */
(function () {
    'use strict';

    const API = 'api.php';
    let state = { config: {}, data: { categories: [], links: [] } };
    let editingLinkId = null;
    const openCategories = new Set(); // merkt sich aufgeklappte Kategorien über Re-Renders hinweg

    // --- Helpers -----------------------------------------------------------

    function showAlert(message, type = 'success') {
        const box = document.getElementById('alert-box');
        const div = document.createElement('div');
        div.className = `alert alert-${type}`;
        div.textContent = message;
        box.innerHTML = '';
        box.appendChild(div);
        setTimeout(() => div.remove(), 4500);
    }

    async function apiCall(action, payload = {}, isFormData = false) {
        let options;
        if (isFormData) {
            payload.append('action', action);
            options = { method: 'POST', body: payload };
        } else {
            const body = new URLSearchParams();
            body.append('action', action);
            Object.entries(payload).forEach(([key, value]) => {
                if (Array.isArray(value)) {
                    value.forEach((v) => body.append(`${key}[]`, v));
                } else {
                    body.append(key, value);
                }
            });
            options = { method: 'POST', body };
        }
        const res = await fetch(API, options);
        const json = await res.json();
        if (!json.success) {
            throw new Error(json.error || 'Unbekannter Fehler');
        }
        return json;
    }

    function categoryName(id) {
        const cat = state.data.categories.find((c) => c.id === id);
        return cat ? cat.name : '–';
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // --- Drag & Drop (generisch, für Kategorien- und Link-Listen) -----------

    function makeSortable(container, itemSelector, onReorder) {
        let draggedEl = null;

        container.addEventListener('dragstart', (e) => {
            const item = e.target.closest(itemSelector);
            if (!item || !container.contains(item)) return;
            draggedEl = item;
            item.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });

        container.addEventListener('dragend', () => {
            if (draggedEl) draggedEl.classList.remove('dragging');
            draggedEl = null;
        });

        container.addEventListener('dragover', (e) => {
            if (!draggedEl) return;
            e.preventDefault();
            const after = getDragAfterElement(container, itemSelector, e.clientY);
            if (after == null) {
                container.appendChild(draggedEl);
            } else {
                container.insertBefore(draggedEl, after);
            }
        });

        container.addEventListener('drop', async (e) => {
            e.preventDefault();
            if (!draggedEl) return;
            const ids = [...container.querySelectorAll(itemSelector)].map((el) => el.dataset.id);
            try {
                await onReorder(ids);
            } catch (err) {
                showAlert(err.message, 'error');
                await loadAll();
            }
        });
    }

    function getDragAfterElement(container, itemSelector, y) {
        const items = [...container.querySelectorAll(`${itemSelector}:not(.dragging)`)];
        return items.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset, element: child };
            }
            return closest;
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    async function reorderCategories(ids) {
        await apiCall('reorder_categories', { order: ids });
        ids.forEach((id, idx) => {
            const cat = state.data.categories.find((c) => c.id === id);
            if (cat) cat.order = idx + 1;
        });
        showAlert('Reihenfolge gespeichert.');
    }

    async function reorderLinks(categoryId, ids) {
        await apiCall('reorder_links', { order: ids });
        ids.forEach((id, idx) => {
            const link = state.data.links.find((l) => l.id === id);
            if (link) link.order = idx + 1;
        });
        showAlert('Reihenfolge gespeichert.');
    }

    // --- Laden & Rendern ------------------------------------------------------

    async function loadAll() {
        const json = await apiCall('get_all');
        state.config = json.config;
        state.data = json.data;
        renderCategories();
        renderLinks();
        renderCategorySelect();
    }

    function renderCategories() {
        const list = document.getElementById('category-list');
        const cats = [...state.data.categories].sort((a, b) => a.order - b.order);
        list.innerHTML = '';
        cats.forEach((cat) => {
            const li = document.createElement('li');
            li.dataset.id = cat.id;
            li.draggable = true;
            li.innerHTML = `
                <span class="drag-handle" title="Ziehen zum Sortieren">&#9776;</span>
                <span class="item-title">${escapeHtml(cat.name)}</span>
                <span class="item-actions">
                    <button data-action="rename-cat" data-id="${cat.id}">Umbenennen</button>
                    <button data-action="delete-cat" data-id="${cat.id}" class="btn-danger">Löschen</button>
                </span>
            `;
            list.appendChild(li);
        });
        if (cats.length === 0) {
            list.innerHTML = '<li><span class="item-meta">Noch keine Kategorien.</span></li>';
        }
    }

    function renderCategorySelect() {
        const select = document.getElementById('link_category');
        const cats = [...state.data.categories].sort((a, b) => a.order - b.order);
        select.innerHTML = cats.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
    }

    function renderLinks() {
        const container = document.getElementById('link-list');
        const cats = [...state.data.categories].sort((a, b) => a.order - b.order);
        container.innerHTML = '';

        if (cats.length === 0) {
            container.innerHTML = '<p class="item-meta">Noch keine Kategorien.</p>';
            return;
        }

        cats.forEach((cat) => {
            const catLinks = state.data.links
                .filter((l) => l.category_id === cat.id)
                .sort((a, b) => a.order - b.order);

            const details = document.createElement('details');
            details.className = 'category-tree';
            details.dataset.categoryId = cat.id;
            if (openCategories.has(cat.id)) details.open = true;

            const summary = document.createElement('summary');
            summary.innerHTML = `
                <span class="tree-cat-name">${escapeHtml(cat.name)}</span>
                <span class="tree-count">${catLinks.length}</span>
            `;
            details.appendChild(summary);

            const ul = document.createElement('ul');
            ul.className = 'tree-links';

            if (catLinks.length === 0) {
                ul.innerHTML = '<li><span class="item-meta">Keine Links in dieser Kategorie.</span></li>';
            } else {
                catLinks.forEach((link) => {
                    const li = document.createElement('li');
                    li.dataset.id = link.id;
                    li.draggable = true;
                    const rdpBadge = link.type === 'rdp' ? '<span class="type-badge type-badge-rdp">RDP</span>' : '';
                    li.innerHTML = `
                        <span class="drag-handle" title="Ziehen zum Sortieren">&#9776;</span>
                        <span class="item-title">${escapeHtml(link.title)} ${rdpBadge}</span>
                        <span class="item-actions">
                            <button data-action="edit-link" data-id="${link.id}">Bearbeiten</button>
                            <button data-action="delete-link" data-id="${link.id}" class="btn-danger">Löschen</button>
                        </span>
                    `;
                    ul.appendChild(li);
                });
                makeSortable(ul, 'li[data-id]', (ids) => reorderLinks(cat.id, ids));
            }

            details.appendChild(ul);
            container.appendChild(details);
        });
    }

    // --- Event-Handler: Kategorien -----------------------------------------

    document.getElementById('category-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const input = document.getElementById('category_name');
        const name = input.value.trim();
        if (!name) return;
        try {
            await apiCall('add_category', { name });
            input.value = '';
            await loadAll();
            showAlert('Kategorie hinzugefügt.');
        } catch (err) {
            showAlert(err.message, 'error');
        }
    });

    const categoryListEl = document.getElementById('category-list');
    makeSortable(categoryListEl, 'li[data-id]', reorderCategories);

    categoryListEl.addEventListener('click', async (e) => {
        const btn = e.target.closest('button');
        if (!btn) return;
        const id = btn.dataset.id;
        const action = btn.dataset.action;

        if (action === 'delete-cat') {
            if (!confirm('Kategorie inkl. aller zugehörigen Links wirklich löschen?')) return;
            try {
                await apiCall('delete_category', { id });
                await loadAll();
                showAlert('Kategorie gelöscht.');
            } catch (err) {
                showAlert(err.message, 'error');
            }
        }

        if (action === 'rename-cat') {
            const current = categoryName(id);
            const newName = prompt('Neuer Name:', current);
            if (!newName || newName.trim() === '') return;
            try {
                await apiCall('rename_category', { id, name: newName.trim() });
                await loadAll();
                showAlert('Kategorie umbenannt.');
            } catch (err) {
                showAlert(err.message, 'error');
            }
        }
    });

    // --- Event-Handler: Links ------------------------------------------------

    const linkForm = document.getElementById('link-form');
    const linkSubmitBtn = document.getElementById('link-submit-btn');
    const linkCancelBtn = document.getElementById('link-cancel-btn');
    const linkTypeSelect = document.getElementById('link_type');
    const linkUrlLabel = document.getElementById('link_url_label');
    const linkUrlInput = document.getElementById('link_url');
    const linkUrlHint = document.getElementById('link_url_hint');
    const linkRdpUsernameRow = document.getElementById('link_rdp_username_row');

    function applyLinkTypeUI() {
        const isRdp = linkTypeSelect.value === 'rdp';
        linkUrlLabel.textContent = isRdp ? 'Server-Adresse' : 'URL';
        linkUrlInput.placeholder = isRdp ? '192.168.1.10 oder server:3389' : 'https://...';
        linkUrlHint.hidden = !isRdp;
        linkRdpUsernameRow.hidden = !isRdp;
    }

    linkTypeSelect.addEventListener('change', applyLinkTypeUI);
    applyLinkTypeUI();

    linkForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const title = document.getElementById('link_title').value.trim();
        const url = document.getElementById('link_url').value.trim();
        const description = document.getElementById('link_description').value.trim();
        const category_id = document.getElementById('link_category').value;
        const type = linkTypeSelect.value;
        const rdp_username = document.getElementById('link_rdp_username').value.trim();

        try {
            if (editingLinkId) {
                await apiCall('edit_link', { id: editingLinkId, title, url, description, category_id, type, rdp_username });
                showAlert('Link aktualisiert.');
            } else {
                await apiCall('add_link', { title, url, description, category_id, type, rdp_username });
                showAlert('Link hinzugefügt.');
            }
            resetLinkForm();
            await loadAll();
        } catch (err) {
            showAlert(err.message, 'error');
        }
    });

    linkCancelBtn.addEventListener('click', resetLinkForm);

    function resetLinkForm() {
        editingLinkId = null;
        linkForm.reset();
        linkSubmitBtn.textContent = 'Link hinzufügen';
        linkCancelBtn.style.display = 'none';
        applyLinkTypeUI();
    }

    const linkListEl = document.getElementById('link-list');

    // Aufklapp-Zustand merken (nicht-bubbelndes 'toggle'-Event via Capture-Phase abfangen)
    linkListEl.addEventListener('toggle', (e) => {
        const details = e.target;
        if (!details.matches('details.category-tree')) return;
        if (details.open) {
            openCategories.add(details.dataset.categoryId);
        } else {
            openCategories.delete(details.dataset.categoryId);
        }
    }, true);

    linkListEl.addEventListener('click', async (e) => {
        const btn = e.target.closest('button');
        if (!btn) return;
        const id = btn.dataset.id;
        const action = btn.dataset.action;

        if (action === 'delete-link') {
            if (!confirm('Link wirklich löschen?')) return;
            try {
                await apiCall('delete_link', { id });
                await loadAll();
                showAlert('Link gelöscht.');
            } catch (err) {
                showAlert(err.message, 'error');
            }
        }

        if (action === 'edit-link') {
            const link = state.data.links.find((l) => l.id === id);
            if (!link) return;
            editingLinkId = id;
            document.getElementById('link_title').value = link.title;
            document.getElementById('link_url').value = link.url;
            document.getElementById('link_description').value = link.description || '';
            document.getElementById('link_category').value = link.category_id;
            linkTypeSelect.value = link.type || 'web';
            document.getElementById('link_rdp_username').value = link.rdp_username || '';
            applyLinkTypeUI();
            linkSubmitBtn.textContent = 'Änderungen speichern';
            linkCancelBtn.style.display = 'inline-block';
            linkForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    // --- Event-Handler: Einstellungen ------------------------------------

    document.getElementById('settings-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData();
        formData.append('site_title', document.getElementById('site_title').value.trim());
        const logoFile = document.getElementById('logo_file').files[0];
        const bgFile = document.getElementById('background_file').files[0];
        if (logoFile) formData.append('logo_file', logoFile);
        if (bgFile) formData.append('background_file', bgFile);

        try {
            await apiCall('update_settings', formData, true);
            showAlert('Einstellungen gespeichert. Seite wird neu geladen ...');
            setTimeout(() => location.reload(), 1200);
        } catch (err) {
            showAlert(err.message, 'error');
        }
    });

    // --- Event-Handler: Wartung (Favicon-Cache, Import) ----------------------

    document.getElementById('refresh-favicons-btn').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const includePrivate = document.getElementById('favicon-include-private').checked;
        btn.disabled = true;
        btn.textContent = 'Lädt …';
        try {
            const res = await apiCall('refresh_favicons', { include_private: includePrivate ? '1' : '' });

            if (res.total === 0) {
                showAlert('Keine Links mit Web-URL gefunden — nichts zu tun.');
                return;
            }

            let msg = `Favicon-Cache: ${res.success} neu geladen`;
            if (res.skipped_cached) msg += `, ${res.skipped_cached} bereits vorhanden`;
            if (res.failed) msg += `, ${res.failed} fehlgeschlagen`;
            if (res.skipped_private) {
                msg += `, ${res.skipped_private} interne Hosts nicht versucht (Häkchen "Auch interne Geräte versuchen" aktivieren, falls gewünscht)`;
            }
            if (res.skipped_time_budget) {
                msg += `, ${res.skipped_time_budget} wegen Zeitlimit übersprungen (einfach nochmal auf "Favicons aktualisieren" klicken, um fortzufahren)`;
            }
            msg += ` (von ${res.total} Hosts total).`;

            if (res.failed_details && res.failed_details.length) {
                msg += ' Fehler: ' + res.failed_details.slice(0, 4).join(' | ');
                if (res.failed_details.length > 4) msg += ` (+${res.failed_details.length - 4} weitere, siehe Protokoll)`;
            }

            showAlert(msg, res.failed ? 'error' : 'success');
            await loadAuditLog();
        } catch (err) {
            showAlert('Fehler beim Aktualisieren: ' + err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Favicons aktualisieren';
        }
    });

    document.getElementById('clear-favicons-btn').addEventListener('click', async () => {
        if (!confirm('Lokalen Favicon-Cache wirklich leeren?')) return;
        try {
            await apiCall('clear_favicon_cache');
            showAlert('Favicon-Cache geleert.');
            await loadAuditLog();
        } catch (err) {
            showAlert(err.message, 'error');
        }
    });

    document.getElementById('favicon-diag-btn').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const output = document.getElementById('favicon-diag-output');
        btn.disabled = true;
        btn.textContent = 'Prüfe …';
        try {
            const res = await apiCall('favicon_diagnostics');
            const labels = {
                php_version: 'PHP-Version',
                os: 'Betriebssystem',
                sapi: 'PHP-SAPI',
                curl_extension: 'cURL-Extension',
                allow_url_fopen: 'allow_url_fopen',
                openssl_extension: 'OpenSSL-Extension',
                open_basedir: 'open_basedir',
                disable_functions: 'disable_functions',
                process_user: 'Prozessbenutzer',
                favicon_dir_configured: 'uploads/favicons/ — konfigurierter Pfad',
                favicon_dir_realpath: 'uploads/favicons/ — tatsächlicher Pfad',
                favicon_dir_exists: 'uploads/favicons/ — existiert',
                favicon_dir_is_writable_check: 'uploads/favicons/ — is_writable()',
                write_test_uploads_favicons: 'uploads/favicons/ — echter Schreibtest',
                data_dir_configured: 'data/ — konfigurierter Pfad',
                data_dir_realpath: 'data/ — tatsächlicher Pfad',
                write_test_data: 'data/ — echter Schreibtest',
                links_total: 'Links insgesamt',
                links_web_type: 'davon Web-Links (RDP ausgeschlossen)',
                unique_hosts_found: 'Eindeutige Hosts gefunden',
                favicon_cache_file_exists: 'data/favicon_cache.json existiert',
                network_test_google: 'Netzwerktest (google.com/favicon.ico)',
            };
            output.innerHTML = Object.entries(res.diagnostics).map(([key, value]) => `
                <dt>${escapeHtml(labels[key] || key)}</dt>
                <dd class="${String(value).includes('FEHLGESCHLAGEN') || String(value).includes('NICHT') ? 'diag-bad' : ''}">${escapeHtml(String(value))}</dd>
            `).join('');
            output.hidden = false;
            output.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } catch (err) {
            showAlert(err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Diagnose anzeigen';
        }
    });

    document.getElementById('import-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fileInput = document.getElementById('import_file');
        if (!fileInput.files[0]) return;
        if (!confirm('Import überschreibt ALLE bestehenden Kategorien & Links. Fortfahren?')) return;

        const formData = new FormData();
        formData.append('import_file', fileInput.files[0]);

        try {
            const res = await apiCall('import_data', formData, true);
            showAlert(`Import erfolgreich: ${res.categories} Kategorien, ${res.links} Links.`);
            fileInput.value = '';
            await loadAll();
            await loadAuditLog();
        } catch (err) {
            showAlert(err.message, 'error');
        }
    });

    // --- Änderungsprotokoll ------------------------------------------------

    async function loadAuditLog() {
        const list = document.getElementById('audit-log-list');
        try {
            const res = await apiCall('get_audit_log', { limit: 50 });
            list.innerHTML = '';
            if (res.entries.length === 0) {
                list.innerHTML = '<li><span class="item-meta">Noch keine Einträge.</span></li>';
                return;
            }
            res.entries.forEach((entry) => {
                const li = document.createElement('li');
                const date = new Date(entry.timestamp);
                const formatted = date.toLocaleString('de-CH', { dateStyle: 'short', timeStyle: 'short' });
                li.innerHTML = `
                    <span class="item-meta log-time">${escapeHtml(formatted)}</span>
                    <span class="item-title">${escapeHtml(entry.action)}</span>
                    <span class="item-meta">${escapeHtml(entry.details || '')}</span>
                `;
                list.appendChild(li);
            });
        } catch (err) {
            list.innerHTML = `<li><span class="item-meta">Fehler beim Laden: ${escapeHtml(err.message)}</span></li>`;
        }
    }

    document.getElementById('clear-log-btn').addEventListener('click', async () => {
        if (!confirm('Änderungsprotokoll wirklich vollständig leeren?')) return;
        try {
            await apiCall('clear_audit_log');
            await loadAuditLog();
            showAlert('Protokoll geleert.');
        } catch (err) {
            showAlert(err.message, 'error');
        }
    });

    // --- Init -------------------------------------------------------------

    loadAll().catch((err) => showAlert('Fehler beim Laden: ' + err.message, 'error'));
    loadAuditLog();
})();
