# ShortNN — URL Shortener

A fast, self-contained URL shortener with **visitor analytics**, **3-tier bot detection**, and a sleek dark UI. No database required — runs on PHP + flat-file JSON storage.

## Features

- **Shorten URLs** with custom slugs or auto-generated codes
- **Clean short URLs** — `domain.com/slug` via `.htaccess` rewrite
- **Visit tracking** with detailed analytics per link
- **3-tier visitor classification** — Human / Suspicious / Bot
  - 9 heuristic signals including datacenter IP detection, UA analysis, rapid-visit detection, subnet flooding
  - Per-visit suspicion score (0-100) with flag explanations
- **Country & ISP breakdown** with flag emojis and visual bars
- **Google Safe Browsing** — checks destination URLs for malware/phishing before shortening
- **Antibot protection** on URL creation — rate limiting, honeypot, JS token, UA blocking
- **Dark, modern UI** with glassmorphism and smooth animations
- **Responsive** — works on mobile

## Quick Start

```bash
git clone https://github.com/SurajMishra0/shortnn.git
cd shortnn

# Copy config and add your Safe Browsing API key (optional)
cp config.example.php config.php

# Start PHP dev server
php -S localhost:8000

# Open in browser
open http://localhost:8000/index.html
```

## Google Safe Browsing (Optional)

1. Get a free API key from [Google Cloud Console → Safe Browsing API](https://console.cloud.google.com/apis/api/safebrowsing.googleapis.com) (10k lookups/day free)
2. Edit `config.php` and set your key:
   ```php
   'safe_browsing_api_key' => 'YOUR_KEY_HERE',
   ```

## Visitor Classification

Each visit is scored on 9 signals:

| Signal | Example | Score |
|--------|---------|-------|
| Known bot UA | Googlebot, Scrapy, curl | → **Bot** (100) |
| Datacenter ISP | AWS, Google Cloud, OVH, DigitalOcean | +30 |
| No browser token | Missing Chrome/Firefox/Safari in UA | +25 |
| Short/empty UA | `CustomApp/1.0` or empty | +20-40 |
| Missing headers | No Accept-Language | +10-15 |
| Rapid same-IP | >5 visits in 60s | +25 |
| Subnet flooding | >10 visits from same /24 | +20 |
| Automation tools | Selenium, Playwright, axios, Postman | +35 |

**Score ≥ 50 → Suspicious** · **Known bot pattern → Bot** · **Otherwise → Human**

## Tech Stack

- **Frontend:** HTML, CSS, vanilla JavaScript
- **Backend:** PHP (no framework)
- **Storage:** JSON flat files
- **Geolocation:** [ip-api.com](http://ip-api.com) (free, no API key)
- **URL Safety:** Google Safe Browsing API v4 (free, optional)

## File Structure

```
shortnn/
├── .htaccess          # Clean URL rewrite rules
├── index.html         # Dashboard UI
├── style.css          # Dark theme styling
├── script.js          # Frontend logic
├── api.php            # Backend API
├── r.php              # Redirect + visitor classification
├── config.example.php # Config template
└── data/
    ├── urls.json      # URL mappings
    └── visits/        # Per-link visitor logs
```

## Deployment on cPanel

1. Upload all files to `public_html/` (or a subdirectory)
2. Copy `config.example.php` → `config.php` and set your API key
3. Ensure `data/` directory exists with `755` permissions
4. Set base path in Settings (⚙️), e.g. `https://yourdomain.com/`
5. `.htaccess` handles clean URLs automatically on Apache

## License

MIT
