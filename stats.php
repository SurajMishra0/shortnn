<?php
$code = $_GET['c'] ?? '';
if (!$code) {
    header("Location: index.html");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stats — <?= htmlspecialchars($code) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=2">
    <style>
        .stats-page {
            max-width: 900px;
            margin: 3rem auto;
            padding: 0 1rem;
        }
        .stats-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--border);
            padding-bottom: 1rem;
        }
        .stats-header h1 {
            margin: 0 0 0.5rem 0;
            font-size: 1.8rem;
        }
        .stats-header a.back-link {
            color: var(--text-dim);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
        }
        .stats-header a.back-link:hover {
            color: var(--text);
        }
    </style>
</head>
<body>
    <div class="stats-page">
        <div class="stats-header">
            <div>
                <a href="index.html" class="back-link">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    Dashboard
                </a>
                <h1 style="margin-top: 1rem;">Stats — <?= htmlspecialchars($code) ?></h1>
                <a href="#" id="shortUrlLink" target="_blank" style="color:var(--accent-light); font-weight:600; font-size:1.1rem; text-decoration:none;">Loading...</a>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="stats-summary" id="statsSummary" style="margin-bottom: 3rem;">
            <div class="stat-card"><span class="stat-value" id="statTotal">0</span><span class="stat-label">Total Visits</span></div>
            <div class="stat-card"><span class="stat-value stat-humans" id="statHumans">0</span><span class="stat-label">Humans</span></div>
            <div class="stat-card"><span class="stat-value stat-suspicious" id="statSuspicious">0</span><span class="stat-label">Suspicious</span></div>
            <div class="stat-card"><span class="stat-value stat-bots" id="statBots">0</span><span class="stat-label">Bots</span></div>
            <div class="stat-card"><span class="stat-value" id="statUniqueIps" style="color:var(--text-dim);">0</span><span class="stat-label">Unique IPs</span></div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 2fr; gap: 2rem;">
            <!-- Country Breakdown -->
            <div class="card glass">
                <h3 style="margin-top:0;">Countries</h3>
                <div class="country-list" id="countryList" style="max-height: none;"></div>
            </div>

            <!-- Visitor Log -->
            <div class="card glass">
                <h3 style="margin-top:0;">Traffic Log</h3>
                <div class="visitor-log" id="visitorLog" style="max-height: 600px;"></div>
            </div>
        </div>
    </div>

    <script>
    const code = <?= json_encode($code) ?>;
    const $ = id => document.getElementById(id);
    
    function countryFlag(c) {
        if (!c || c === 'XX') return '🌍';
        return String.fromCodePoint(...[...c.toUpperCase()].map(x => 0x1F1E6 + x.charCodeAt(0) - 65));
    }
    
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    async function loadStats() {
        try {
            const res = await fetch(`api.php?action=stats&code=${encodeURIComponent(code)}`);
            const data = await res.json();

            if (!data.success) {
                document.body.innerHTML = `<div class="stats-page"><h1>Error</h1><p>${escapeHtml(data.error || 'Failed to load stats')}</p><a href="index.html">Back</a></div>`;
                return;
            }

            // Fallback for shortener absolute URL
            const basePath = localStorage.getItem('shortnn_basePath') || (window.location.origin + window.location.pathname.replace(/stats\.php$/, ''));
            const shortUrl = basePath + code;
            
            $('shortUrlLink').href = shortUrl;
            $('shortUrlLink').textContent = shortUrl;

            $('statTotal').textContent = data.total;
            $('statHumans').textContent = data.summary.humans;
            $('statSuspicious').textContent = data.summary.suspicious;
            $('statBots').textContent = data.summary.bots;
            $('statUniqueIps').textContent = data.summary.unique_ips;

            const countries = data.summary.countries;
            const countryEntries = Object.entries(countries);
            if (countryEntries.length === 0) {
                $('countryList').innerHTML = '<p class="country-empty">No visitor data yet</p>';
            } else {
                const maxCount = Math.max(...countryEntries.map(([, c]) => c));
                $('countryList').innerHTML = countryEntries.map(([name, count]) => {
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
                $('visitorLog').innerHTML = '<p class="visitor-empty">No visitors yet</p>';
            } else {
                $('visitorLog').innerHTML = data.visits.map(v => {
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
        } catch (e) {
            console.error(e);
        }
    }

    loadStats();
    setInterval(loadStats, 10000);
    </script>
</body>
</html>
