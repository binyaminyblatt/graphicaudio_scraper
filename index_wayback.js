import "win-ca";
import axios from "axios";
import * as cheerio from "cheerio";
import fs from "fs";
import https from "https";

const urlsFile = "./wayback_urls.json";
const resultsFile = "./wayback_results.json";
const catalogUrls = [
  "https://web.archive.org/web/20160330095611/http://www.graphicaudio.net/our-productions/series/stony-man.html",
  "https://web.archive.org/web/20150924164152/http://www.graphicaudio.net/our-productions/series/stony-man.html",
  "https://web.archive.org/web/20141021082059/http://www.graphicaudio.net/our-productions/series/stony-man.html",
  "https://web.archive.org/web/20170516122208/http://www.graphicaudio.net/our-productions/series/dc-comics.html",
  "https://web.archive.org/web/20170629201734/http://www.graphicaudio.net/our-productions/series/dc-comics.html",
  "https://web.archive.org/web/20170617171221/http://www.graphicaudio.net/our-productions/series/dc-comics.html",
  "https://web.archive.org/web/20190131215209/https://www.graphicaudio.net/our-productions/series/dc-comics.html",
  "https://web.archive.org/web/20190722173511/https://www.graphicaudio.net/our-productions/series/dc-comics.html",
  "https://web.archive.org/web/20160316170752/http://www.graphicaudio.net/our-productions/series/marvel.html",
  "https://web.archive.org/web/20190718231003/https://www.graphicaudio.net/our-productions/series/marvel.html",
  "https://web.archive.org/web/20160316152405/https://www.graphicaudio.net/our-productions/series/marvel.html",
  "https://web.archive.org/web/20160316170752/https://www.graphicaudio.net/our-productions/series/marvel.html",
  "https://web.archive.org/web/20150109020426/http://www.graphicaudio.net/our-productions/genres/western/great-american-westerns-western.html",
  "http://web.archive.org/web/20160316133514/http://www.graphicaudio.net/our-productions/authors/a-e/don-pendleton.html",
  "https://web.archive.org/web/20140720100944/http://www.graphicaudio.net/our-productions/authors/a-e/don-pendleton.html",
  "https://web.archive.org/web/20191217052259/http://www.graphicaudio.net/our-productions/authors/a-e/don-pendleton.html",
  "https://web.archive.org/web/20211118235432/http://www.graphicaudio.net/our-productions/authors/a-e/don-pendleton.html",

];
const singleProductUrls = [
  ["https://web.archive.org/web/20170216010540/http://www.graphicaudio.net/peacer-unmasked.html", {"cover": "https://web.archive.org/web/20170422213306im_/http://www.graphicaudio.net/media/catalog/product/cache/1/small_image/265x/9df78eab33525d08d6e5fb8d27136e95/p/e/peacer00.jpg"}],
  ["https://web.archive.org/web/20100731052852/http://www.graphicaudio.net/p-34-snakes-on-a-plane-the-audiobook.aspx", {"cover": "https://pictures.abebooks.com/isbn/9781599501666-us-300._FMwebp_.jpg"}],
];

/** Utility functions */
function cleanISBN(raw) {
  if (!raw) return null;
  let cleaned = raw.replace(/ISBN/i, "").replace(/[:\s]/g, "");
  const match = cleaned.match(/[0-9X]+/);
  return match ? match[0] : null;
}
function cleanText(str) {
  return str ? str.replace(/\s+/g, " ").trim() : null;
}
function cleanGenre(str) {
  return str ? str.replace(/Genre:/i, "").trim() : null;
}
function cleanReleaseDate(str) {
  if (!str) return null;
  const dateStr = str.replace(/Release Date:/i, "").trim();
  const date = new Date(dateStr);
  return isNaN(date) ? null : date.toISOString();
}
function parseEpisodeTitle(raw) {
  if (!raw) {
    return {
      seriesEpisodeNumber: null,
      episodeTitle: null,
      episodePart: null,
      totalParts: null,
      episodeCode: null,
      rawTitle: null,
    };
  }

  // Match "4 : Rhythm of War (5 of 6)" or "4 : Rhythm of War" (without parentheses)
  const match = raw.match(/(\d+)\s*:\s*(.*?)(?:\((\d+)\s+of\s+(\d+)\))?$/);

  const seriesEpisodeNumber = match ? parseInt(match[1], 10) : null;
  const episodeTitle = match ? match[2].trim() : raw.trim();
  const episodePart = match && match[3] ? match[3] : "1";
  const totalParts = match && match[4] ? match[4] : "1";
  const episodeCode = seriesEpisodeNumber ? `${seriesEpisodeNumber}.${episodePart}` : null;

  return {
    seriesEpisodeNumber,  // 4
    episodeTitle,         // "Rhythm of War"
    episodePart,          // "5"
    totalParts,           // "6"
    episodeCode,          // "4.5"
  };
}

/** Scrape a single product page */
async function scrapeProduct(url, cover_fallback = null) {
  const { data: html } = await safeGet(url);
  const $ = cheerio.load(html);

  let isbnRaw = $(".product-isbn").text().trim().replace(/-/g, "");
  if (!isbnRaw) {
    let divText = $("#content > table > tbody > tr:nth-child(1) > td:nth-child(2) > table > tbody > tr:nth-child(2) > td > div:nth-child(3) > div:nth-child(6)").text();
    const isbnMatch = divText.match(/ISBN:\s*([\d-X]+)/i);
    isbnRaw = isbnMatch ? isbnMatch[1].trim().replace(/-/g, "") : null;
  }
  let isbn = cleanISBN(isbnRaw);

  let rawTitle = cleanText($(".episode-name").text());
  if (!rawTitle) {
    rawTitle = cleanText($(".ProductNameText").text());
  }

  let cover = $(".product-image").attr("src") || null;
  if (cover && cover.endsWith("tempcover.jpg")) {
    cover = null;
  }
  if (!cover && cover_fallback) {
    cover = cover_fallback;
  }
  if (!cover) {
    cover = new URL($(`img[alt="Click here to view larger image"]`).attr("src"), url).href || null;
  }

  const { seriesEpisodeNumber, episodeTitle, episodePart, totalParts, episodeCode } = parseEpisodeTitle(rawTitle);

  let discription = $(".description").text();
  if (!discription) {
    discription = $("#content > table > tbody > tr:nth-child(1) > td:nth-child(2) > table > tbody > tr:nth-child(2) > td > div:nth-child(3) > div:nth-child(1)").text();
  }

  let copyright = $(".product-copyright").text();
  if (!copyright) { 
    copyright = $("#content > table > tbody > tr:nth-child(1) > td:nth-child(2) > table > tbody > tr:nth-child(2) > td > div:nth-child(3) > div:nth-child(3)").text();
  }

  let releaseDate = $(".product-releasedate").text();
  if (!releaseDate) {
    let divText = $("#content > table > tbody > tr:nth-child(1) > td:nth-child(2) > table > tbody > tr:nth-child(2) > td > div:nth-child(3) > div:nth-child(6)").text();
    const dateMatch = divText.match(/Release Date:\s*(.+?)\s*(?=ISBN:|$)/i);
    if (dateMatch) {
      const parsed = new Date(dateMatch[1].trim());
      releaseDate = isNaN(parsed) ? null : parsed.toISOString(); // ISO 8601 format
    }
  }

  let castArray = [];
  const castTable = $("#product-attribute-specs-table > tbody > tr:nth-child(2) > td");
  if (castTable.length) {
    castArray = castTable
      .map((i, el) => $(el).text())
      .get();
  } else {
    let castLink = $(`a[title="Cast and Production Credits"]`).attr("href");
    if (castLink) {
      castLink = new URL(castLink, url).href;
      const { data: castHtml } = await safeGet(castLink);
      const $cast = cheerio.load(castHtml);
      const starringLine = $cast("strong:contains('Starring:')")[0]?.nextSibling?.nodeValue || "";
      castArray = [starringLine];
    }
  }
  let author = $('.product-author').text().replace('by', "").trim();
  if (!author) {
    author = $("#breadcrumb > span > a:nth-child(2)").text().trim();
  }

  return {
    link: url.replace(/^https?:\/\/web\.archive\.org\/web\/\d+\//, ""),
    wayback_link: url,
    cover: cover,
    seriesName: cleanText($(".series-name").text()),
    title: episodeTitle,
    rawtitle: rawTitle,
    episodeNumber: seriesEpisodeNumber,
    episodePart: episodePart,
    episodeCode: episodeCode,
    totalParts: totalParts,
    subtitle: "[Dramatized Adaptation]",
    author: cleanText(author),
    releaseDate: cleanReleaseDate(cleanText(releaseDate)),
    isbn: isbn,
    genre: cleanGenre(cleanText($(".product-genre").text())),
    description: cleanText(discription),
    copyright: cleanText(copyright),
    cast: [
      ...new Set(
        castArray
          .flatMap(text => text.split(/,| and /i))          // split on comma or 'and'
          .map(t => cleanText(t))            // clean whitespace
          .filter(Boolean)                   // remove empty strings
          ),
    ],
  };
}

const httpsAgent = new https.Agent({
  keepAlive: true,
  rejectUnauthorized: false,
});

const baseConfig = {
  httpsAgent,
  maxRedirects: 10,
  timeout: 15000,
  validateStatus: null,
  headers: {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "en-US,en;q=0.9",
    "Connection": "keep-alive",
  },
};

const secureClient = axios.create(baseConfig);

const insecureClient = axios.create({
  ...baseConfig,
  httpsAgent: new https.Agent({
    keepAlive: true,
    rejectUnauthorized: false,
  }),
});

async function safeGet(url, retries = 3) {
  for (let attempt = 1; attempt <= retries; attempt++) {
    try {
      const res = await secureClient.get(url);

      // Retry on bad HTTP responses
      if (res.status >= 500 || res.status === 429) {
        throw new Error(`HTTP ${res.status}`);
      }

      return res;

    } catch (err) {
      const code = err.code || "";

      // Retry with insecure client if TLS issue
      if (code === "SELF_SIGNED_CERT_IN_CHAIN") {
        console.warn(`⚠️ TLS issue, retrying insecurely: ${url}`);
        return await insecureClient.get(url);
      }

      const isRetryable =
        code === "ECONNRESET" ||
        code === "ETIMEDOUT" ||
        code === "EAI_AGAIN" ||
        err.message.includes("socket hang up") ||
        err.message.includes("timeout");

      if (attempt === retries || !isRetryable) {
        throw err;
      }

      const delay = 2000 * attempt;
      console.warn(`⚠️ Attempt ${attempt} failed (${code || err.message}) → retrying in ${delay}ms`);

      await new Promise(res => setTimeout(res, delay));
    }
  }
}

/** Get or cache product URLs */
async function getProductUrls(catalogUrl) {
  // if (fs.existsSync(urlsFile)) {
  //   console.log("📂 Loading cached product URLs...");
  //   return JSON.parse(fs.readFileSync(urlsFile));
  // }

  console.log("🌐 Fetching catalog (this may take a while)...");
  const { data: listHtml } = await safeGet(catalogUrl);
  const $list = cheerio.load(listHtml);

  const productUrls = $list("div.wrapper ul.products-grid li.item")
    .filter(function () {
      const sku = $list(this).find("a").first().attr("href");
      return sku && !sku.includes("usb");
    })
    .map(function () {
      return $list(this).find("a").first().attr("href");
    })
    .get()
    .filter(Boolean);

  fs.writeFileSync(urlsFile, JSON.stringify(productUrls, null, 2));
  console.log(`✅ Cached ${productUrls.length} product URLs to ${urlsFile}`);
  return productUrls;
}

async function getfallbackcovers(catalogUrl) {
  const { data: listHtml } = await safeGet(catalogUrl);
  const $list = cheerio.load(listHtml);
  
  const productUrls = $list("div.wrapper ul.products-grid li.item")
    .filter(function () {
      const sku = $list(this).find("a").first().attr("href");
      return sku && !sku.includes("usb");
    })
    .map(function () {
      return $list(this).find("a img").first().attr("src");
    })
    .get()
    .filter(Boolean);
  return productUrls;
}
function isDuplicate(url, scrapedUrls) {
  // create a comparison key without changing the original URL
  const key = url
    .replace(/^https?:\/\/web\.archive\.org\/web\/\d+\//, "") // remove Wayback prefix
    .replace(/^https?:\/\//, "")                              // ignore http/https
    .replace("graphicaudiointernational.net", "graphicaudio.net"); // unify domains
  return scrapedUrls.has(key);
}




/** Main scraper */
async function scrapeAll() {
  // Keep results across all catalogs
  let results = [];
  let scrapedUrls = new Set();

  // Load existing results if any
  if (fs.existsSync(resultsFile)) {
    results = JSON.parse(fs.readFileSync(resultsFile));
    scrapedUrls = new Set(
      results.map(r =>
        r.link
          .replace(/^https?:\/\//, "")                              // ignore http/https
          .replace("graphicaudiointernational.net", "graphicaudio.net") // unify domains
      )
    );
    console.log(`🔄 Resuming: already scraped ${scrapedUrls.size} products`);
  } else {
    console.log("🚀 Starting fresh scrape...");
    fs.writeFileSync(resultsFile, JSON.stringify([], null, 2));
  }
  for (const catalogUrl of catalogUrls) {
    const productUrls = await getProductUrls(catalogUrl);
    const fallbackCovers = await getfallbackcovers(catalogUrl);

    for (let i = 0; i < Math.min(productUrls.length, fallbackCovers.length); i++) {
      const url = productUrls[i];
      const cover = fallbackCovers[i];
      if (isDuplicate(url, scrapedUrls)) {
        console.log(`⏩ Skipping: ${url}`);
        continue;
      }
      console.log(`➡️ Scraping: ${url}`);
      try {
        const product = await scrapeProduct(url, cover);

        if (!product.title) {
          console.error(`❌ Missing title for ${url}, skipping...`);
          continue;
        }
        // if (!product.cover) {
        //   console.warn(`❌ Missing cover image for ${url}, skipping...`);
        //   continue;
        // }

        results.push(product);
        scrapedUrls.add(product.link
          .replace(/^https?:\/\//, "")
          .replace("graphicaudiointernational.net", "graphicaudio.net"));
        fs.writeFileSync(resultsFile, JSON.stringify(results, null, 2));
        console.log(`💾 Saved: ${url}`);
      } catch (err) {
        if (err.message.includes("404")) {
          console.error(`🚫 404 Not Found: ${url}, skipping... we'll get it next time!`);
          continue;
        }else if (err.message.includes("socket hang up")) {
          console.error(`⚠️ Network error (socket hang up) for ${url}, skipping... we'll get it next time! sleep for a bit to let the server recover`);
          await new Promise(res => setTimeout(res, 5000)); // wait 5s before retrying
          continue;
        }
        console.error(`❌ Failed: ${url} - ${err.message}`);
      }
      await new Promise(res => setTimeout(res, 2000)); // wait 2s between requests
    }
    
  }
  for (const array of singleProductUrls) {
    const [url, overrides] = array; 
    if (isDuplicate(url, scrapedUrls)) {
        console.log(`⏩ Skipping: ${url}`);
        continue;
      }
      console.log(`➡️ Scraping: ${url}`);
      try {
        let product = await scrapeProduct(url);

        if (!product.title) {
          console.error(`❌ Missing title for ${url}, skipping...`);
          continue;
        }
        // if (!product.cover) {
        //   console.warn(`❌ Missing cover image for ${url}, skipping...`);
        //   continue;
        // }
        if (overrides && typeof overrides === "object") {
          product = {
            ...product,
            ...overrides
          };
        }
        results.push(product);
        scrapedUrls.add(product.link
          .replace(/^https?:\/\//, "")
          .replace("graphicaudiointernational.net", "graphicaudio.net"));
        fs.writeFileSync(resultsFile, JSON.stringify(results, null, 2));
        console.log(`💾 Saved: ${url}`);
      } catch (err) {
        if (err.message.includes("404")) {
          console.error(`🚫 404 Not Found: ${url}, skipping... we'll get it next time!`);
          continue;
        }else if (err.message.includes("socket hang up")) {
          console.error(`⚠️ Network error (socket hang up) for ${url}, skipping... we'll get it next time! sleep for a bit to let the server recover`);
          await new Promise(res => setTimeout(res, 5000)); // wait 5s before retrying
          continue;
        }
        console.error(`❌ Failed: ${url} - ${err.message}`);
      }
      // await new Promise(res => setTimeout(res, 2000)); // wait 2s between requests
  }
  console.log(`\n🎉 Done! Total products scraped: ${results.length}`);
}

scrapeAll();
