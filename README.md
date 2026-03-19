# 📚 GraphicAudio Scraper + Lookup API  

> A personal project that scrapes metadata from **GraphicAudio** and exposes a lightweight lookup API that can also serve as an **Audiobookshelf Custom Metadata Provider**.
> ⚠️ Note: While there is a public instance of this API, it’s hosted on a free plan with a very low data cap. If you’d like access, please send me a message and I can provide it.

---

## ⚠️ Legal / Disclaimer

This project is a **personal hobby project**.

✅ You may use this project for personal archival or library metadata.  
❌ This project is **not affiliated with GraphicAudio**, nor endorsed by them.  
All trademarks, cover images, metadata, and intellectual property belong to their respective owners.

---

## 🚀 Overview

This project contains **three components**:

| Component  | Language | Purpose |
|------------|----------|---------|
| `index.js` | Node.js | Scrapes GraphicAudio product pages and saves results to `results.json` |
| `index_wayback.js` | Node.js | Scrapes archived GraphicAudio pages from Wayback Machine and saves to `wayback_results.json` |
| `index.php` | PHP | Serves metadata via HTTP APIs, including ABS custom metadata provider |

The scrapers produce structured JSON files:

```txt
results.json        # Live catalog
wayback_results.json # Archived catalog
```

The PHP API loads these JSON files (cached locally or via APCu), and exposes endpoints such as:

```txt
/isbn/{isbn}
/wayback/isbn/{isbn}
/asin/{asin}
/wayback/asin/{asin}
/series/{series-name}
/wayback/series/{series-name}
/search/{query}
/wayback/search/{query}
/audiobookshelf/search?query={isbn|asin|text}
/wayback/audiobookshelf/search?query={isbn|asin|text}
````

---

# 📥 1. Scraper (Node.js)

### ✅ Requirements

- Node.js 20
- `npm i`

### 📁 Files

| File           | Purpose                               |
|----------------|---------------------------------------|
| `index.js`     | Scrapes entire GraphicAudio catalog   |
| `urls.json`    | Cached product URLs (improves resume) |
| `results.json` | Output metadata JSON from scraping    |

### ▶️ Run

```sh
node index.js
````

The script will:

1. Download the GraphicAudio product list
2. Extract each product URL
3. Visit each product page
4. Save scraped data into `results.json`

### ✨ Features

- Resumable scraping — will not duplicate previously scraped entries
- Cleans ISBN, title, series numbering, etc.
- Detects multipart episodes (example: `4.5` from `4 : Rhythm of War (5 of 6)`)
- Saves covers only when valid (ignores `tempcover.jpg`)

🔧 **Metadata captured per entry includes:**

```json
{
  "link": "https://www.graphicaudio.net/amelia-peabody-4-lion-in-the-valley.html",
  "cover": "https://www.graphicaudio.net/media/catalog/product/cache/0164cd528593768540930b5b640a411b/a/m/amelia_peabody_4_lion_in_the_valley.jpg",
  "seriesName": "Amelia Peabody",
  "title": "Lion in the Valley",
  "rawtitle": "Episode number 4 : Lion in the Valley",
  "episodeNumber": 4,
  "episodePart": "1",
  "episodeCode": "4.1",
  "totalParts": "1",
  "subtitle": "[Dramatized Adaptation]",
  "author": "Elizabeth Peters",
  "releaseDate": "2025-11-17T00:00:00.000Z",
  "isbn": "9798896520030",
  "genre": "Mystery",
  "description": "The 1895-96 season promises to be an exceptional one ...",
  "copyright": "Copyright © 1986 Elizabeth Peters. All rights reserved...",
  "cast": [
    "Ken Jackson",
    "Nanette Savard",
    "Amelia Peabody",
    "Michael Glenn",
    "Radcliffe Emerson",
    ...
  ]
}
```

---

# 🕰️ 1b. Wayback Machine Scraper (Node.js)

This project also includes a secondary scraper that pulls **archived GraphicAudio pages** from the Internet Archive's **Wayback Machine**. It can find older product pages that are no longer available on the live site.

### ✅ Requirements

- Node.js 20
- `npm i` (same dependencies as the primary scraper)

### 📁 Files

| File                | Purpose                                                                 |
|---------------------|-------------------------------------------------------------------------|
| `index_wayback.js`  | Scrapes archived catalog snapshots and product pages via web.archive.org |
| `wayback_urls.json` | Cached list of product URLs extracted from archived catalog snapshots   |
| `wayback_results.json` | Output metadata JSON from the archived pages                          |

### ▶️ Run

```sh
node index_wayback.js
```

### 🧠 What it does

- Uses a curated list of Wayback Machine catalog snapshots (`catalogUrls`) to discover product pages.
- Scrapes each archived product page and adds a `wayback_link` field pointing to the archived snapshot.
- Stores the original live URL in `link` (stripping the Wayback prefix). **Note:** this URL may no longer work; use `wayback_link` to access the archived page reliably.
- Supports resuming: rerunning will skip entries already saved in `wayback_results.json`.

> ⚠️ **Disclaimer: Internet Archive Data Accuracy and Completeness**  
> Data from the Wayback Machine may be **inaccurate or incomplete**. Archived pages can have missing metadata (covers, ISBNs, descriptions), broken links, or outdated information. The Internet Archive captures snapshots at different times, so not all data may be present or correct. Use this data with caution and verify against other sources when possible.
> ⚠️ Note: Wayback snapshots vary in completeness. Some archived pages may have missing metadata (cover image, ISBN, etc.), depending on the snapshot.

---

# 🌐 2. Lookup API + Audiobookshelf Provider (PHP)

### ✅ Requirements

- PHP 8.1+
- Optional: APCu extension (improves caching performance)

### 📁 Files

| File         | Purpose                                       |
| ------------ | --------------------------------------------- |
| `index.php`  | Main API router                               |
| `cache.json` | Cached version of results.json (auto created) |
| `/covers`    | Cached cover images                           |

### 🔧 Configure `index.php`

Edit these constants:

```php
define("JSON_URL", "https://raw.githubusercontent.com/USERNAME/REPO/main/results.json");
define("WAYBACK_URL", "https://raw.githubusercontent.com/USERNAME/REPO/main/wayback_results.json");
define("REFRESH_KEY", "CHANGE_ME");
define("AUDIOBOOKSHELF_KEY", "abs"); // "abs" = no auth required
```

If you want **ABS to require an API key**, set:

```php
define("AUDIOBOOKSHELF_KEY", "MYSECRETKEY123");
```

### 🐳 Docker Deployment

> ⚠️ **Note:** The Docker setup is not tested. Use at your own risk and test thoroughly before production deployment.

For easy deployment, use Docker:

#### Build and Run with Docker Compose

```sh
# Build and start the container
docker-compose up -d

# The API will be available at http://localhost:8080
```

#### Manual Docker Build

```sh
# Build the image
docker build -t graphicaudio-api .

# Run the container
docker run -d -p 8080:80 \
  -v $(pwd)/cache:/var/www/html/cache \
  -v $(pwd)/covers:/var/www/html/covers \
  -e REFRESH_KEY=your_key_here \
  graphicaudio-api
```

#### Docker Environment Variables

- `REFRESH_KEY`: Secret key for cache refresh endpoint (auto-generated if not set)
- `AUDIOBOOKSHELF_KEY`: API key for Audiobookshelf (use "abs" for no auth)
- `LOW_BANDWIDTH_LIMIT_ENABLE`: Enable rate limiting (default: true)
- `DEBUG`: Enable debug logging (default: false)
- `JSON_URL`: Custom URL for results.json (optional)
- `WAYBACK_URL`: Custom URL for wayback_results.json (optional)

**Note:** If `REFRESH_KEY` is not set, a random 64-character hex key will be generated on container startup and printed to the console.

#### GitHub Actions Docker Builds

The repository includes automated Docker builds via GitHub Actions:

- **Triggers**: Pushes to `main` branch and pull requests affecting Docker-related files
- **Registry**: Images are pushed to GitHub Container Registry (`ghcr.io`)
- **Tags**:
  - `latest` for main branch
  - Branch name for other branches
  - PR number for pull requests
  - Commit SHA for unique builds

To use the pre-built image:

```sh
# Pull the latest image
docker pull ghcr.io/binyaminyblatt/graphicaudio_scraper:latest

# Run with docker-compose using the pre-built image
docker-compose up -d
```

Make sure to update your `docker-compose.yml` to use the registry image:

```yaml
services:
  graphicaudio-api:
    image: ghcr.io/binyaminyblatt/graphicaudio_scraper:latest
    # ... rest of your config
```

---

## 🧠 API Endpoints

The API supports two datasets:

- **Live Dataset**: Uses `results.json` (current GraphicAudio catalog)
- **Wayback Dataset**: Uses `wayback_results.json` (archived pages from Wayback Machine)

All endpoints work with both datasets. To query the Wayback dataset, prepend `/wayback/` to any endpoint (e.g., `/wayback/isbn/{isbn}`).

### 📘 Lookup by ISBN

```txt
/isbn/{isbn}
/wayback/isbn/{isbn}
```

Get cover:

```txt
/isbn/{isbn}/cover
/wayback/isbn/{isbn}/cover
```

### � Lookup by ASIN

```txt
/asin/{asin}
/wayback/asin/{asin}
```

Get cover:

```txt
/asin/{asin}/cover
/wayback/asin/{asin}/cover
```

### �🔍 Search by Title, Author, or Series

```txt
/search/{query}
/wayback/search/{query}
```

### 📚 List episodes in a series (fuzzy match)

```txt
/series/{series-name}
/wayback/series/{series-name}
```

### 🎧 Audiobookshelf Metadata Provider

```txt
/audiobookshelf/search?query=stormlight
/wayback/audiobookshelf/search?query=stormlight
```

Auto-detects:

| Query type      | Handled as   |
| --------------- | ------------ |
| `9781234567890` | ISBN         |
| `B09C4Y7T1Q`    | ASIN         |
| `Stormlight`    | fuzzy search |

ABS receives results formatted like:

```json
{
  "matches": [
    {
      "title": "Rhythm of War",
      "series": [{ "series": "Stormlight Archive", "sequence": "4.5" }],
      "author": "Brandon Sanderson",
      "publishedYear": "2020",
      "cover": "https://yourdomain/isbn/9781427280583/cover",
      "narrator": "Narrator One"
    }
  ]
}
```

### 🚨 Force cache refresh

```txt
PUT /refresh?key=YOURKEY
PUT /wayback/refresh?key=YOURKEY
```

---

## 💾 Covers

Covers are downloaded automatically and cached in `/covers/`.
Once cached, they serve instantly without hitting GraphicAudio again.

---

## ✅ Status

| Feature                          | Status       |
| -------------------------------- | ------------ |
| Full catalog scraping            | ✅           |
| Wayback Machine scraping         | ✅           |
| ISBN lookup                      | ✅           |
| ASIN lookup                      | ✅           |
| Series fuzzy detection           | ✅           |
| Audiobookshelf metadata provider | ✅           |
| Cached covers                    | ✅           |
| /wayback/* endpoints             | ✅           |
| Docker deployment                | ⚠️ Untested  |
| GitHub Actions CI/CD             | ✅           |

---

### ⚠️ ASIN Note

- **ASINs are not available on the GraphicAudio website.**
  The scraper cannot retrieve them directly from GraphicAudio pages.
- If you want ASINs, you must **manually match** GraphicAudio titles with Audible or another source.
- Once you add an ASIN to a product entry in `results.json`, the PHP API can serve it via:

```txt
/asin/{asin}
/asin/{asin}/cover
```

- Example JSON with ASIN field added:

```json
{
  "link": "https://www.graphicaudio.net/amelia-peabody-4-lion-in-the-valley.html",
  "cover": "https://www.graphicaudio.net/media/catalog/product/cache/0164cd528593768540930b5b640a411b/a/m/amelia_peabody_4_lion_in_the_valley.jpg",
  "seriesName": "Amelia Peabody",
  "title": "Lion in the Valley",
  "rawtitle": "Episode number 4 : Lion in the Valley",
  "episodeNumber": 4,
  "episodePart": "1",
  "episodeCode": "4.1",
  "totalParts": "1",
  "subtitle": "[Dramatized Adaptation]",
  "author": "Elizabeth Peters",
  "releaseDate": "2025-11-17T00:00:00.000Z",
  "isbn": "9798896520030",
  "asin": "B08EXAMPLE",        // <- Add this manually
  "genre": "Mystery",
  "description": "The 1895-96 season promises to be an exceptional one ...",
  "copyright": "Copyright © 1986 Elizabeth Peters. All rights reserved...",
  "cast": [
    "Ken Jackson",
    "Nanette Savard",
    "Amelia Peabody",
    "Michael Glenn",
    "Radcliffe Emerson",
    ...
  ]
}
```

- Once added, the PHP API `findByField()` will recognize it automatically.

---

## 🧑‍💻 Development

To edit or improve results, simply delete:

```txt
urls.json
results.json
wayback_urls.json
wayback_results.json
```

Next run:

```sh
node index.js
node index_wayback.js
```

To force the PHP endpoint to refresh:

```sh
curl -X PUT "https://yourdomain/refresh?key=YOURKEY"
curl -X PUT "https://yourdomain/wayback/refresh?key=YOURKEY"
```

---

## 🗂️ Dataset Usage Policy

You are free to use the scraped dataset for any purpose (personal or commercial). This project's code is released under the **MIT License**, but the dataset itself is derived from GraphicAudio content and should be used responsibly.

- ✅ You may use the dataset in your own projects.
- ✅ You may redistribute the dataset (credit to this repository is appreciated but not required).
- ⭐ If you like what I'm doing, please star this repository!
- 💛 If you use the Wayback dataset heavily, consider supporting the Internet Archive (e.g., <https://archive.org/donate>) to help keep these snapshots available.
- 🙏 **Please do not** set up GitHub Actions to run `index_wayback.js` on a loop. While this project is MIT-licensed and you technically can, I sincerely ask that you respect the costs of running the Internet Archive. The Wayback scraper should be run manually or on rare occasions, not automatically on a schedule. The Internet Archive pages are static and won't change over time, so there's no benefit to running it repeatedly—only unnecessary cost.
- ⚠️ Remember the dataset contains third-party content (GraphicAudio), so respect copyright and fair use.

## ⭐ Contributing

PRs welcome — especially improvements to scraper logic or metadata mapping.

---

## 📄 License

MIT License.

---
