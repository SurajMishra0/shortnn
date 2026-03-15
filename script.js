(() => {
    'use strict';

    const $ = id => document.getElementById(id);
    const longUrlInput = $('longUrl');
    const customSlugInput = $('customSlug');
    const honeypotInput = $('honeypot');
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

    const modalOverlay = $('modalOverlay');
    const statsModal = $('statsModal');
    const closeModal = $('closeModal');
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

    // ────────────────────────────────
    // Tab Switching
    // ────────────────────────────────
    const tabShorten = $('tabBtnShorten');
    const tabSafety = $('tabBtnSafety');
    const contentShorten = $('tabContentShorten');
    const contentSafety = $('tabContentSafety');

    if (tabShorten && tabSafety) {
        tabShorten.addEventListener('click', () => {
            tabShorten.classList.add('active');
            tabShorten.querySelector('.tab-indicator').style.display = 'block';
            tabShorten.style.color = 'var(--text)';

            tabSafety.classList.remove('active');
            tabSafety.querySelector('.tab-indicator').style.display = 'none';
            tabSafety.style.color = 'var(--text-dim)';

            contentShorten.style.display = 'block';
            contentSafety.style.display = 'none';
        });

        tabSafety.addEventListener('click', () => {
            tabSafety.classList.add('active');
            tabSafety.querySelector('.tab-indicator').style.display = 'block';
            tabSafety.style.color = 'var(--text)';

            tabShorten.classList.remove('active');
            tabShorten.querySelector('.tab-indicator').style.display = 'none';
            tabShorten.style.color = 'var(--text-dim)';

            contentSafety.style.display = 'block';
            contentShorten.style.display = 'none';
        });
    }

    // ────────────────────────────────
    // Antibot: Pre-fetch token on page load (instant, invisible)
    // ────────────────────────────────
    let authToken = { tk: 0, ts: '' };

    async function fetchToken() {
        try {
            const res = await fetch(`${API}?action=token`);
            const data = await res.json();
            if (data.success) {
                authToken = { tk: data.tk, ts: data.ts };
            }
        } catch { /* silent */ }
    }

    // Grab token immediately on load — by the time user types a URL, it's ready
    fetchToken();
    // Refresh token every 5 minutes to keep it valid
    setInterval(fetchToken, 300000);

    // ────────────────────────────────

    function countryFlag(code) {
        if (!code || code === 'XX') return '🌍';
        return String.fromCodePoint(
            ...[...code.toUpperCase()].map(c => 0x1F1E6 + c.charCodeAt(0) - 65)
        );
    }

    function getBasePath() {
        return localStorage.getItem('shortnn_basePath') || (window.location.origin + window.location.pathname.replace(/\/[^/]*$/, '/'));
    }

    basePathInput.value = localStorage.getItem('shortnn_basePath') || '';

    settingsToggle.addEventListener('click', () => settingsPanel.classList.toggle('open'));

    saveSettingsBtn.addEventListener('click', () => {
        let val = basePathInput.value.trim();
        if (val && !val.endsWith('/')) val += '/';
        localStorage.setItem('shortnn_basePath', val);
        showToast('Settings saved');
        settingsPanel.classList.remove('open');
    });

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
            const body = new URLSearchParams({
                url,
                _tk: String(authToken.tk),
                _ts: authToken.ts,
                website: honeypotInput ? honeypotInput.value : '',
            });
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

            // Refresh token for next creation
            fetchToken();
            loadUrls();
        } catch (err) {
            showError(err.message);
        } finally {
            shortenBtn.disabled = false;
            shortenBtn.querySelector('span').textContent = 'Shorten';
        }
    });

    longUrlInput.addEventListener('keydown', e => { if (e.key === 'Enter') shortenBtn.click(); });
    customSlugInput.addEventListener('keydown', e => { if (e.key === 'Enter') shortenBtn.click(); });

    copyBtn.addEventListener('click', () => {
        navigator.clipboard.writeText(resultLink.textContent).then(() => showToast('Copied to clipboard!')).catch(() => { });
    });

    function buildShortUrl(code) {
        const base = getBasePath();
        return `${base}${encodeURIComponent(code)}`;
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

        } catch { /* silent */ }
    }

    window.deleteUrl = async (code) => {
        if (!confirm(`Delete short URL "${code}"?`)) return;
        try {
            const res = await fetch(`${API}?action=delete`, {
                method: 'POST',
                body: new URLSearchParams({ code })
            });
            const data = await res.json();
            if (data.success) { showToast('Deleted'); loadUrls(); }
        } catch {
            showToast('Failed to delete');
        }
    };

    // ── Stats Modal ──
    window.openStats = async (code) => {
        statsTitle.textContent = `Stats — ${code}`;
        statsUrl.textContent = 'Loading…';
        $('viewFullStatsBtn').href = `stats.php?c=${encodeURIComponent(code)}`;

        statTotal.textContent = '…';
        statHumans.textContent = '…';
        statSuspicious.textContent = '…';
        statBots.textContent = '…';
        statUniqueIps.textContent = '…';
        countryList.innerHTML = '<p class="country-empty">Loading…</p>';
        visitorLog.innerHTML = '<p class="visitor-empty">Loading…</p>';

        modalOverlay.classList.add('open');
        statsModal.classList.add('open');
        document.body.style.overflow = 'hidden';

        try {
            const res = await fetch(`${API}?action=stats&code=${encodeURIComponent(code)}`);
            const data = await res.json();

            if (!data.success) { showToast(data.error || 'Failed'); closeStatsModal(); return; }

            statsUrl.textContent = data.url;
            statTotal.textContent = data.total;
            statHumans.textContent = data.summary.humans;
            statSuspicious.textContent = data.summary.suspicious;
            statBots.textContent = data.summary.bots;
            statUniqueIps.textContent = data.summary.unique_ips;

            const countries = data.summary.countries;
            const countryEntries = Object.entries(countries);
            if (countryEntries.length === 0) {
                countryList.innerHTML = '<p class="country-empty">No visitor data yet</p>';
            } else {
                const maxCount = Math.max(...countryEntries.map(([, c]) => c));
                countryList.innerHTML = countryEntries.map(([name, count]) => {
                    const pct = (count / maxCount) * 100;
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

            if (data.visits.length === 0) {
                visitorLog.innerHTML = '<p class="visitor-empty">No visitors yet</p>';
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
                        <div class="visitor-ua">${escapeHtml(v.ua)}</div>
                        ${flagPills}
                    </div>`;
                }).join('');
            }
        } catch { showToast('Failed to load stats'); closeStatsModal(); }
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

    loadUrls();
    setInterval(loadUrls, 30000);

    // ────────────────────────────────
    // Safety Status Banner (from cron scan)
    // ────────────────────────────────
    const safetyBanner = $('safetyBanner');

    async function loadSafetyStatus() {
        try {
            const res = await fetch(`${API}?action=safety_status`);
            const data = await res.json();
            if (!data.success) return;

            const s = data.status;
            if (!s) {
                // No scan has run yet — show nothing or a subtle hint
                safetyBanner.style.display = 'none';
                return;
            }

            const checkedTime = s.checked_at ? new Date(s.checked_at).toLocaleString('en-IN', {
                day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
            }) : '';
            const timeTag = checkedTime ? `<span class="sb-time">Last scan: ${checkedTime}</span>` : '';

            if (s.error) {
                // API error — show warning but don't block
                safetyBanner.className = 'safety-banner warning';
                safetyBanner.innerHTML = `⚠️ <strong>Safe Browsing scan error:</strong> ${escapeHtml(s.error)} — URLs still working normally. ${timeTag}`;
                safetyBanner.style.display = 'block';
            } else if (!s.all_safe && s.flagged && s.flagged.length > 0) {
                // Flagged URLs!
                const selfFlagged = s.flagged.filter(f => f.is_self);
                const urlFlagged = s.flagged.filter(f => !f.is_self);

                let msg = '🚨 <strong>Safe Browsing Alert:</strong> ';
                if (selfFlagged.length > 0) {
                    msg += `<strong>Your shortener domain</strong> is flagged (${selfFlagged[0].threat})! `;
                }
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
                // All safe
                safetyBanner.className = 'safety-banner safe';
                safetyBanner.innerHTML = `🛡️ All ${s.total_checked} URLs passed Safe Browsing check. ${timeTag}`;
                safetyBanner.style.display = 'block';
            }

            // Also update settings panel
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
    setInterval(loadSafetyStatus, 180000); // Refresh every 3 minutes

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
                    checkUrlResult.textContent = data.error || 'Check failed';
                } else if (data.safe) {
                    checkUrlResult.style.color = 'var(--green)';
                    checkUrlResult.innerHTML = `✅ <strong>${escapeHtml(data.url)}</strong> — appears safe`;
                } else {
                    checkUrlResult.style.color = 'var(--red)';
                    checkUrlResult.innerHTML = `🚨 <strong>${escapeHtml(data.url)}</strong> — flagged as <strong>${escapeHtml(data.threat)}</strong>`;
                }
                checkUrlResult.style.display = 'block';
            } catch {
                checkUrlResult.style.color = 'var(--amber)';
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
