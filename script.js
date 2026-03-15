/* ShortNN — Frontend Logic */
(function () {
    'use strict';

    const $ = id => document.getElementById(id);

    // ── DOM refs ──
    const settingsToggle = $('settingsToggle');
    const settingsPanel = $('settingsPanel');
    const basePathInput = $('basePath');
    const saveSettings = $('saveSettings');
    const longUrlInput = $('longUrl');
    const slugInput = $('customSlug');
    const shortenBtn = $('shortenBtn');
    const resultBox = $('result');
    const resultLink = $('resultLink');
    const copyBtn = $('copyBtn');
    const errorBox = $('error');
    const urlTableBody = $('urlTableBody');
    const emptyState = $('emptyState');
    const totalCount = $('totalCount');
    const toast = $('toast');

    // Stats panel refs
    const mainContent = $('mainContent');
    const statsPanel = $('statsPanel');
    const closeStats = $('closeStats');
    const statsTitle = $('statsTitle');
    const statsUrl = $('statsUrl');
    const statTotal = $('statTotal');
    const statHumans = $('statHumans');
    const statSuspicious = $('statSuspicious');
    const statBots = $('statBots');
    const statUniqueIps = $('statUniqueIps');
    const countryList = $('countryList');
    const visitorLog = $('visitorLog');

    const API = 'api.php';

    // ── Auth token ──
    let authToken = { tk: 0, ts: '' };

    async function fetchToken() {
        try {
            const res = await fetch(`${API}?action=token`);
            const data = await res.json();
            if (data.success) authToken = { tk: data.tk, ts: data.ts };
        } catch { /* silent */ }
    }

    fetchToken();
    setInterval(fetchToken, 300000);

    // ── Settings ──
    function getBasePath() {
        let base = localStorage.getItem('snn_basePath') || '';
        if (base && !base.endsWith('/')) base += '/';
        return base;
    }

    const savedBase = localStorage.getItem('snn_basePath');
    if (savedBase) basePathInput.value = savedBase;

    settingsToggle.addEventListener('click', () => {
        settingsPanel.classList.toggle('open');
    });

    saveSettings.addEventListener('click', () => {
        const val = basePathInput.value.trim();
        localStorage.setItem('snn_basePath', val);
        showToast('Settings saved');
        loadUrls();
    });

    // ── Helpers ──
    function buildShortUrl(code) {
        const base = getBasePath();
        return `${base}${encodeURIComponent(code)}`;
    }

    function hideResult() { resultBox.style.display = 'none'; }
    function hideError() { errorBox.style.display = 'none'; }

    function showToast(msg) {
        toast.textContent = msg;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 2500);
    }

    function countryFlag(code) {
        if (!code || code === 'XX') return '🌐';
        return String.fromCodePoint(...[...code.toUpperCase()].map(c => 0x1F1E6 - 65 + c.charCodeAt(0)));
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Create ──
    shortenBtn.addEventListener('click', async () => {
        hideResult();
        hideError();

        const url = longUrlInput.value.trim();
        const slug = slugInput.value.trim();

        if (!url) {
            longUrlInput.focus();
            return;
        }

        shortenBtn.disabled = true;
        shortenBtn.querySelector('span').textContent = 'Shortening…';

        try {
            const body = new URLSearchParams({
                url, slug,
                _tk: authToken.tk,
                _ts: authToken.ts,
                website: $('honeypot').value,
            });

            const res = await fetch(`${API}?action=create`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
            });

            const data = await res.json();

            if (data.success) {
                const shortUrl = buildShortUrl(data.code);
                resultLink.href = shortUrl;
                resultLink.textContent = shortUrl;
                resultBox.style.display = 'block';
                longUrlInput.value = '';
                slugInput.value = '';
                loadUrls();
            } else {
                errorBox.textContent = data.error || 'Failed to shorten URL';
                errorBox.style.display = 'block';
            }
        } catch {
            errorBox.textContent = 'Network error. Please try again.';
            errorBox.style.display = 'block';
        } finally {
            shortenBtn.disabled = false;
            shortenBtn.querySelector('span').textContent = 'Shorten';
        }
    });

    longUrlInput.addEventListener('keydown', e => { if (e.key === 'Enter') shortenBtn.click(); });

    // ── Copy ──
    copyBtn.addEventListener('click', async () => {
        const text = resultLink.textContent;
        try {
            await navigator.clipboard.writeText(text);
            showToast('Copied to clipboard!');
        } catch {
            showToast('Copy failed');
        }
    });

    // ── Load URL List ──
    let currentActiveCode = null;

    async function loadUrls() {
        try {
            const res = await fetch(`${API}?action=list`);
            const data = await res.json();
            if (!data.success) return;

            const urls = data.urls;
            const entries = Object.entries(urls);
            totalCount.textContent = entries.length;

            if (entries.length === 0) {
                urlTableBody.innerHTML = '';
                emptyState.style.display = 'block';
                return;
            }

            emptyState.style.display = 'none';
            urlTableBody.innerHTML = entries.map(([code, info]) => {
                const shortUrl = buildShortUrl(code);
                const created = new Date(info.created).toLocaleDateString('en-IN', {
                    day: 'numeric', month: 'short', year: 'numeric'
                });
                const isActive = code === currentActiveCode;
                return `
                <tr class="${isActive ? 'active-row' : ''}" data-code="${escapeHtml(code)}">
                    <td><a href="${shortUrl}" target="_blank" class="code-link">${escapeHtml(code)}</a></td>
                    <td><span class="dest-url" title="${escapeHtml(info.url)}">${escapeHtml(info.url)}</span></td>
                    <td><span class="date">${created}</span></td>
                    <td>
                        <span class="visits">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            ${info.visits}
                        </span>
                    </td>
                    <td style="text-align:right;white-space:nowrap;">
                        <button class="btn-icon btn-stats" onclick="window._openStats('${escapeHtml(code)}')" title="View Stats">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="18" y="3" width="4" height="18"/><rect x="10" y="8" width="4" height="13"/><rect x="2" y="13" width="4" height="8"/></svg>
                        </button>
                        <button class="btn-icon btn-danger" onclick="window._deleteUrl('${escapeHtml(code)}')" title="Delete">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        } catch { /* silent */ }
    }

    // ── Delete ──
    window._deleteUrl = async function (code) {
        if (!confirm(`Delete "${code}"?`)) return;
        try {
            const res = await fetch(`${API}?action=delete`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ code }),
            });
            const data = await res.json();
            if (data.success) {
                showToast('Deleted');
                if (currentActiveCode === code) closeStatsPanel();
                loadUrls();
            }
        } catch { /* silent */ }
    };

    // ════════════════════════════════════
    // Stats Panel (inline, not modal)
    // ════════════════════════════════════

    function openStatsPanel(code) {
        currentActiveCode = code;

        // Show panel
        statsPanel.classList.add('open');
        mainContent.classList.add('has-detail');

        // Highlight active row
        document.querySelectorAll('tr.active-row').forEach(r => r.classList.remove('active-row'));
        const row = document.querySelector(`tr[data-code="${code}"]`);
        if (row) row.classList.add('active-row');

        // Load stats
        statsTitle.textContent = `Stats — ${code}`;
        statsUrl.textContent = 'Loading…';
        statTotal.textContent = '…';
        statHumans.textContent = '…';
        statSuspicious.textContent = '…';
        statBots.textContent = '…';
        statUniqueIps.textContent = '…';
        countryList.innerHTML = '<p class="country-empty">Loading…</p>';
        visitorLog.innerHTML = '<p class="visitor-empty">Loading…</p>';

        fetch(`${API}?action=stats&code=${encodeURIComponent(code)}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;

                statsUrl.textContent = data.url;
                statTotal.textContent = data.total;
                statHumans.textContent = data.summary.humans;
                statSuspicious.textContent = data.summary.suspicious;
                statBots.textContent = data.summary.bots;
                statUniqueIps.textContent = data.summary.unique_ips;

                // Countries
                const countries = data.summary.countries;
                const countryEntries = Object.entries(countries);
                if (countryEntries.length === 0) {
                    countryList.innerHTML = '<p class="country-empty">No visits yet</p>';
                } else {
                    const maxCount = Math.max(...countryEntries.map(([, c]) => c));
                    countryList.innerHTML = countryEntries.map(([name, count]) => {
                        const pct = (count / maxCount * 100).toFixed(0);
                        const cc = data.visits.find(v => v.country === name)?.country_code || 'XX';
                        return `
                        <div class="country-row">
                            <span class="country-flag">${countryFlag(cc)}</span>
                            <span class="country-name">${escapeHtml(name)}</span>
                            <div class="country-bar-wrap"><div class="country-bar" style="width:${pct}%"></div></div>
                            <span class="country-count">${count}</span>
                        </div>`;
                    }).join('');
                }

                // Visitors
                if (!data.visits || data.visits.length === 0) {
                    visitorLog.innerHTML = '<p class="visitor-empty">No visitors recorded yet</p>';
                } else {
                    visitorLog.innerHTML = data.visits.map(v => {
                        const time = new Date(v.timestamp).toLocaleString('en-IN', {
                            day: 'numeric', month: 'short', year: 'numeric',
                            hour: '2-digit', minute: '2-digit'
                        });
                        const location = [v.city, v.country].filter(Boolean).join(', ') || 'Unknown';
                        const vType = v.type || (v.is_bot ? 'bot' : 'human');
                        const score = v.suspicion_score ?? 0;

                        let badge;
                        if (vType === 'bot') badge = '<span class="badge-bot">BOT</span>';
                        else if (vType === 'suspicious') badge = '<span class="badge-suspicious">SUSPICIOUS</span>';
                        else badge = '<span class="badge-human">Human</span>';

                        const scoreBadge = score > 0 ? `<span class="score-badge">Score: ${score}</span>` : '';

                        const flagPills = (v.flags && v.flags.length > 0)
                            ? `<div class="visitor-flags">${v.flags.map(f => `<span class="flag-pill">${escapeHtml(f)}</span>`).join('')}</div>`
                            : '';

                        return `
                        <div class="visitor-entry">
                            <div class="visitor-top">
                                <span><span class="visitor-ip">${escapeHtml(v.ip)}</span>${badge}${scoreBadge}</span>
                                <span class="visitor-time">${time}</span>
                            </div>
                            <div class="visitor-location">${countryFlag(v.country_code)} ${escapeHtml(location)}${v.isp ? ' · ' + escapeHtml(v.isp) : ''}</div>
                            <div class="visitor-ua">${escapeHtml(v.ua || '(empty)')}</div>
                            ${flagPills}
                        </div>`;
                    }).join('');
                }
            })
            .catch(() => {
                statsUrl.textContent = 'Failed to load stats';
            });
    }

    function closeStatsPanel() {
        currentActiveCode = null;
        statsPanel.classList.remove('open');
        mainContent.classList.remove('has-detail');
        document.querySelectorAll('tr.active-row').forEach(r => r.classList.remove('active-row'));
    }

    window._openStats = openStatsPanel;
    closeStats.addEventListener('click', closeStatsPanel);

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && statsPanel.classList.contains('open')) closeStatsPanel();
    });

    // ── Init ──
    loadUrls();
    setInterval(loadUrls, 30000);

    // ────────────────────────────────
    // Safety Status Banner
    // ────────────────────────────────
    const safetyBanner = $('safetyBanner');

    async function loadSafetyStatus() {
        try {
            const res = await fetch(`${API}?action=safety_status`);
            const data = await res.json();
            if (!data.success) return;

            const s = data.status;
            if (!s) {
                safetyBanner.style.display = 'none';
                return;
            }

            const checkedTime = s.checked_at ? new Date(s.checked_at).toLocaleString('en-IN', {
                day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
            }) : '';
            const timeTag = checkedTime ? `<span class="sb-time">Last scan: ${checkedTime}</span>` : '';

            if (s.error) {
                safetyBanner.className = 'safety-banner warning';
                safetyBanner.innerHTML = `⚠️ <strong>Safe Browsing scan error:</strong> ${escapeHtml(s.error)} — URLs still working. ${timeTag}`;
                safetyBanner.style.display = 'block';
            } else if (!s.all_safe && s.flagged && s.flagged.length > 0) {
                const selfFlagged = s.flagged.filter(f => f.is_self);
                const urlFlagged = s.flagged.filter(f => !f.is_self);

                let msg = '🚨 <strong>Safe Browsing Alert:</strong> ';
                if (selfFlagged.length > 0) msg += `<strong>Your shortener domain</strong> is flagged (${selfFlagged[0].threat})! `;
                if (urlFlagged.length > 0) {
                    msg += `${urlFlagged.length} destination URL${urlFlagged.length > 1 ? 's' : ''} flagged: `;
                    msg += urlFlagged.map(f => `<strong>${escapeHtml(f.code)}</strong> (${f.threat})`).join(', ');
                    msg += '. ';
                }
                msg += `URLs still working — review and remove if needed. ${timeTag}`;

                safetyBanner.className = 'safety-banner danger';
                safetyBanner.innerHTML = msg;
                safetyBanner.style.display = 'block';
            } else {
                safetyBanner.className = 'safety-banner safe';
                safetyBanner.innerHTML = `🛡️ All ${s.total_checked} URLs passed Safe Browsing check. ${timeTag}`;
                safetyBanner.style.display = 'block';
            }

            // Settings panel status
            const sbStatus = $('safeBrowsingStatus');
            if (sbStatus) {
                const res2 = await fetch(`${API}?action=config`);
                const cfg = await res2.json();
                if (cfg.success) {
                    sbStatus.innerHTML = cfg.safeBrowsingEnabled
                        ? '<span style="color:var(--green);">🛡️ Google Safe Browsing: Active</span>'
                        : '<span style="color:var(--text-dim);">⚠️ Safe Browsing: Not configured — set API key in <code>config.php</code></span>';
                }
            }
        } catch { /* silent */ }
    }

    loadSafetyStatus();
    setInterval(loadSafetyStatus, 180000);

    // ────────────────────────────────
    // Manual URL Safety Checker
    // ────────────────────────────────
    const checkUrlInput = $('checkUrlInput');
    const checkUrlBtn = $('checkUrlBtn');
    const checkUrlResult = $('checkUrlResult');

    if (checkUrlBtn) {
        checkUrlBtn.addEventListener('click', async () => {
            const url = checkUrlInput.value.trim();
            if (!url) { checkUrlInput.focus(); return; }

            checkUrlBtn.disabled = true;
            checkUrlBtn.textContent = 'Checking…';
            checkUrlResult.style.display = 'none';

            try {
                const res = await fetch(`${API}?action=check_url&url=${encodeURIComponent(url)}`);
                const data = await res.json();

                if (!data.success) {
                    checkUrlResult.style.color = 'var(--red)';
                    checkUrlResult.style.background = 'rgba(255,107,107,0.06)';
                    checkUrlResult.textContent = data.error || 'Check failed';
                } else if (data.safe) {
                    checkUrlResult.style.color = 'var(--green)';
                    checkUrlResult.style.background = 'rgba(0,206,201,0.06)';
                    checkUrlResult.innerHTML = `✅ <strong>${escapeHtml(data.url)}</strong> — appears safe`;
                } else {
                    checkUrlResult.style.color = 'var(--red)';
                    checkUrlResult.style.background = 'rgba(255,107,107,0.06)';
                    checkUrlResult.innerHTML = `🚨 <strong>${escapeHtml(data.url)}</strong> — flagged as <strong>${escapeHtml(data.threat)}</strong>`;
                }
                checkUrlResult.style.display = 'block';
            } catch {
                checkUrlResult.style.color = 'var(--amber)';
                checkUrlResult.style.background = 'rgba(253,203,110,0.06)';
                checkUrlResult.textContent = '⚠️ Could not reach the Safe Browsing API';
                checkUrlResult.style.display = 'block';
            } finally {
                checkUrlBtn.disabled = false;
                checkUrlBtn.textContent = 'Check';
            }
        });

        checkUrlInput.addEventListener('keydown', e => { if (e.key === 'Enter') checkUrlBtn.click(); });
    }
})();
