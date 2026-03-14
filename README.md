# ShortNN — URL Shortener

A fast, self-contained URL shortener with visitor analytics, bot detection, and a sleek dark UI. No database required — runs on PHP + flat-file JSON storage.

## Features

- **Shorten URLs** with custom slugs or auto-generated codes
- **Visit tracking** with detailed analytics per link
- **Visitor details** — IP, user agent, country, city, ISP
- **Bot detection** — identifies crawlers, scrapers, and monitoring tools
- **Country breakdown** with flag emojis and visual bars
- **Configurable base path** for deployment flexibility
- **Dark, modern UI** with glassmorphism and smooth animations
- **Responsive** — works on mobile

## Quick Start

```bash
# Clone the repo
git clone https://github.com/SurajMishra0/shortnn.git
cd shortnn

# Start PHP dev server
php -S localhost:8000

# Open in browser
open http://localhost:8000/index.html
```

## Tech Stack

- **Frontend:** HTML, CSS, vanilla JavaScript
- **Backend:** PHP (no framework)
- **Storage:** JSON flat files
- **Geolocation:** [ip-api.com](http://ip-api.com) (free, no API key)

## File Structure

```
shortnn/
├── index.html        # Dashboard UI
├── style.css         # Dark theme styling
├── script.js         # Frontend logic
├── api.php           # Backend API
├── r.php             # Redirect handler + visitor logging
└── data/
    ├── urls.json     # URL mappings
    └── visits/       # Per-link visitor logs
```

## License

MIT
