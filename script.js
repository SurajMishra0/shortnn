(() => {
    'use strict';

    // ── DOM refs ──
    const $ = id => document.getElementById(id);
    const longUrlInput = $('longUrl');
    const customSlugInput = $('customSlug');
    const shortenBtn = $('shortenBtn');
    const resultBox = $('result');
    const resultLink = $('resultLink');
    const copyBtn = $('copyBtn');
    const errorBox = $('error');
    const urlTableBody = $('urlTableBody');
    const totalCount = $('totalCount');
    const emptyState = $('emptyState');
    const settingsToggle = $('settingsToggle');
    const settingsPanel = $('settingsPanel');
    const basePathInput = $('basePath');
    const saveSettingsBtn = $('saveSettings');
    const toast = $('toast');

    // Modal refs
    const modalOverlay = $('modalOverlay');
    const statsModal = $('statsModal');
    const closeModal = $('closeModal');
    const statsTitle = $('statsTitle');
    const statsUrl = $('statsUrl');
    const statTotal = $('statTotal');
    const statHumans = $('statHumans');
    const statBots = $('statBots');
    const countryList = $('countryList');
    const visitorLog = $('visitorLog');

    const API = 'api.php';

    // ── Country code → flag emoji ──
    function countryFlag(code) {
        if (!code || code === 'XX') return '🌍';
        return String.fromCodePoint(
            ...[...code.toUpperCase()].map(c => 0x1F1E6 + c.charCodeAt(0) - 65)
        );
    }

    // ── Settings ──
    function getBasePath() {
        return localStorage.getItem('shortnn_basePath') || (window.location.origin + window.location.pathname.replace(/\/[^/]*$/, '/'));
    }

    basePathInput.value = localStorage.getItem('shortnn_basePath') || '';

    settingsToggle.addEventListener('click', () => {
        settingsPanel.classList.toggle('open');
    });

    saveSettingsBtn.addEventListener('click', () => {
        let val = basePathInput.value.trim();
        if (val && !val.endsWith('/')) val += '/';
        localStorage.setItem('shortnn_basePath', val);
        showToast('Settings saved');
        settingsPanel.classList.remove('open');
    });

    // ── Toast ──
    let toastTimer;
    function showToast(msg) {
        toast.textContent = msg;
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 2500);
    }

    // ── Create Short URL ──
    shortenBtn.addEventListener('click', async () => {
        const url = longUrlInput.value.trim();
        if (!url) { longUrlInput.focus(); return; }

        hideResult();
        hideError();
        shortenBtn.disabled = true;
        shortenBtn.querySelector('span').textContent = 'Creating…';

        try {
            const body = new URLSearchParams({ url });
            const slug = customSlugInput.value.trim();
            if (slug) body.append('slug', slug);

            const res = await fetch(`${API}?action=create`, { method: 'POST', body });
            const data = await res.json();

            if (!data.success) throw new Error(data.error || 'Something went wrong');

            const shortUrl = buildShortUrl(data.code);
            resultLink.href = shortUrl;
            resultLink.textContent = shortUrl;
            resultBox.style.display = 'block';

            longUrlInput.value = '';
            customSlugInput.value = '';
            loadUrls();
        } catch (err) {
            showError(err.message);
        } finally {
            shortenBtn.disabled = false;
            shortenBtn.querySelector('span').textContent = 'Shorten';
        }
    });

    // Allow pressing Enter in the URL input
    longUrlInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') shortenBtn.click();
    });
    customSlugInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') shortenBtn.click();
    });

    // ── Copy ──
    copyBtn.addEventListener('click', () => {
        const url = resultLink.textContent;
        navigator.clipboard.writeText(url).then(() => showToast('Copied to clipboard!')).catch(() => { });
    });

    // ── Helpers ──
    function buildShortUrl(code) {
        const base = getBasePath();
        return `${base}r.php?c=${encodeURIComponent(code)}`;
    }

    function hideResult() { resultBox.style.display = 'none'; }
    function hideError() { errorBox.style.display = 'none'; }
    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.style.display = 'block';
    }

    // ── Load URL list ──
    async function loadUrls() {
        try {
            const res = await fetch(`${API}?action=list`);
            const data = await res.json();
            if (!data.success) return;

            const urls = data.urls;
            const codes = Object.keys(urls);
            totalCount.textContent = codes.length;

            if (codes.length === 0) {
                urlTableBody.innerHTML = '';
                emptyState.style.display = 'block';
                document.querySelector('table').style.display = 'none';
                return;
            }

            emptyState.style.display = 'none';
            document.querySelector('table').style.display = 'table';

            // Sort by created date descending
            codes.sort((a, b) => new Date(urls[b].created) - new Date(urls[a].created));

            urlTableBody.innerHTML = codes.map(code => {
                const entry = urls[code];
                const shortUrl = buildShortUrl(code);
                const created = new Date(entry.created).toLocaleDateString('en-IN', {
                    day: 'numeric', month: 'short', year: 'numeric'
                });

                return `
                <tr>
                    <td><a href="${shortUrl}" target="_blank" class="code-link">${escapeHtml(code)}</a></td>
                    <td><span class="dest-url" title="${escapeHtml(entry.url)}">${escapeHtml(entry.url)}</span></td>
                    <td><span class="date">${created}</span></td>
                    <td>
                        <span class="visits">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            ${entry.visits}
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;gap:0.35rem;">
                            <button class="btn-icon btn-stats" onclick="openStats('${escapeHtml(code)}')" title="View Stats">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            </button>
                            <button class="btn-icon btn-danger" onclick="deleteUrl('${escapeHtml(code)}')" title="Delete">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m4 0V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>`;
            }).join('');

        } catch {
            // Silently fail on load
        }
    }

    // ── Delete ──
    window.deleteUrl = async (code) => {
        if (!confirm(`Delete short URL "${code}"?`)) return;
        try {
            const res = await fetch(`${API}?action=delete`, {
                method: 'POST',
                body: new URLSearchParams({ code })
            });
            const data = await res.json();
            if (data.success) {
                showToast('Deleted');
                loadUrls();
            }
        } catch {
            showToast('Failed to delete');
        }
    };

    // ── Stats Modal ──
    window.openStats = async (code) => {
        statsTitle.textContent = `Stats — ${code}`;
        statsUrl.textContent = 'Loading…';
        statTotal.textContent = '…';
        statHumans.textContent = '…';
        statBots.textContent = '…';
        countryList.innerHTML = '<p class="country-empty">Loading…</p>';
        visitorLog.innerHTML = '<p class="visitor-empty">Loading…</p>';

        // Open modal
        modalOverlay.classList.add('open');
        statsModal.classList.add('open');
        document.body.style.overflow = 'hidden';

        try {
            const res = await fetch(`${API}?action=stats&code=${encodeURIComponent(code)}`);
            const data = await res.json();

            if (!data.success) {
                showToast(data.error || 'Failed to load stats');
                closeStatsModal();
                return;
            }

            statsUrl.textContent = data.url;
            statTotal.textContent = data.total;
            statHumans.textContent = data.summary.humans;
            statBots.textContent = data.summary.bots;

            // ── Country breakdown ──
            const countries = data.summary.countries;
            const countryEntries = Object.entries(countries);
            if (countryEntries.length === 0) {
                countryList.innerHTML = '<p class="country-empty">No visitor data yet</p>';
            } else {
                const maxCount = Math.max(...countryEntries.map(([, c]) => c));
                countryList.innerHTML = countryEntries.map(([name, count]) => {
                    const pct = (count / maxCount) * 100;
                    // Try to find country code from visits
                    const visit = data.visits.find(v => v.country === name);
                    const cc = visit ? visit.country_code : 'XX';
                    return `
                    <div class="country-row">
                        <span class="country-flag">${countryFlag(cc)}</span>
                        <span class="country-name">${escapeHtml(name)}</span>
                        <div class="country-bar-wrap">
                            <div class="country-bar" style="width:${pct}%"></div>
                        </div>
                        <span class="country-count">${count}</span>
                    </div>`;
                }).join('');
            }

            // ── Visitor log ──
            if (data.visits.length === 0) {
                visitorLog.innerHTML = '<p class="visitor-empty">No visitors yet</p>';
            } else {
                visitorLog.innerHTML = data.visits.map(v => {
                    const time = new Date(v.timestamp).toLocaleString('en-IN', {
                        day: 'numeric', month: 'short', year: 'numeric',
                        hour: '2-digit', minute: '2-digit'
                    });
                    const location = [v.city, v.country].filter(Boolean).join(', ') || 'Unknown';
                    const badge = v.is_bot
                        ? '<span class="badge-bot">BOT</span>'
                        : '<span class="badge-human">Human</span>';

                    return `
                    <div class="visitor-entry">
                        <div class="visitor-top">
                            <span>
                                <span class="visitor-ip">${escapeHtml(v.ip)}</span>
                                ${badge}
                            </span>
                            <span class="visitor-time">${time}</span>
                        </div>
                        <div class="visitor-location">${countryFlag(v.country_code)} ${escapeHtml(location)}${v.isp ? ' · ' + escapeHtml(v.isp) : ''}</div>
                        <div class="visitor-ua">${escapeHtml(v.ua)}</div>
                    </div>`;
                }).join('');
            }

        } catch {
            showToast('Failed to load stats');
            closeStatsModal();
        }
    };

    function closeStatsModal() {
        modalOverlay.classList.remove('open');
        statsModal.classList.remove('open');
        document.body.style.overflow = '';
    }

    closeModal.addEventListener('click', closeStatsModal);
    modalOverlay.addEventListener('click', closeStatsModal);
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && statsModal.classList.contains('open')) closeStatsModal();
    });

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Init ──
    loadUrls();

    // Auto-refresh visit counts every 30s
    setInterval(loadUrls, 30000);
})();
