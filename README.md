# 📚 GraphicAudio Scraper + Lookup API  

A personal project that scrapes metadata from **GraphicAudio** and exposes a lightweight lookup API that can also serve as an **Audiobookshelf Custom Metadata Provider**.

---

## ⚠️ Legal / Disclaimer

This project is a **personal hobby project**.

✅ You may use this project for personal archival or library metadata.  
❌ This project is **not affiliated with GraphicAudio**, nor endorsed by them.  
All trademarks, cover images, metadata, and intellectual property belong to their respective owners.

---

## 🚀 Overview

This project contains **two components**:

| Component  | Language | Purpose |
|------------|----------|---------|
| `index.js` | Node.js | Scrapes GraphicAudio product pages and saves results to `results.json` |
| `index.php` | PHP | Serves metadata via HTTP APIs, including ABS custom metadata provider |

The scraper produces a structured JSON file:

```txt

results.json

```

The PHP API loads that JSON (cached locally or via APCu), and exposes endpoints such as:

```txt

/isbn/{isbn}
/asin/{asin}
/series/{series-name}
/search/{query}
/audiobookshelf/search?query={isbn|asin|text}

````

---

# 📥 1. Scraper (Node.js)

### ✅ Requirements

- Node.js 20
- `npm i`

### 📁 Files

| File | Purpose |
|------|---------|
| `index.js` | Scrapes entire GraphicAudio catalog |
| `urls.json` | Cached product URLs (improves resume) |
| `results.json` | Output metadata JSON from scraping |

### ▶️ Run

```bash

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
define("REFRESH_KEY", "CHANGE_ME");
define("AUDIOBOOKSHELF_KEY", "abs"); // "abs" = no auth required
```

If you want **ABS to require an API key**, set:

```php
define("AUDIOBOOKSHELF_KEY", "MYSECRETKEY123");
```

---

## 🧠 API Endpoints

### 📘 Lookup by ISBN

```txt
/isbn/{isbn}
```

Get cover:

```txt
/isbn/{isbn}/cover
```

### 🔍 Search by Title, Author, or Series

```txt
/search/{query}
```

### 📚 List episodes in a series (fuzzy match)

```txt
/series/{series-name}
```

### 🎧 Audiobookshelf Metadata Provider

```txt
/audiobookshelf/search?query=stormlight
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
```

---

## 💾 Covers

Covers are downloaded automatically and cached in `/covers/`.
Once cached, they serve instantly without hitting GraphicAudio again.

---

## ✅ Status

| Feature                          | Status |
| -------------------------------- | ------ |
| Full catalog scraping            | ✅      |
| ISBN lookup                      | ✅      |
| ASIN lookup                      | ✅      |
| Series fuzzy detection           | ✅      |
| Audiobookshelf metadata provider | ✅      |
| Cached covers                    | ✅      |

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
```

Next run:

```bash
node scraper.js
```

To force the PHP endpoint to refresh:

```bash
curl -X PUT "https://yourdomain/refresh?key=SECRET"
```

---

## ⭐ Contributing

PRs welcome — especially improvements to scraper logic or metadata mapping.

---

## 📄 License

MIT License.

---
