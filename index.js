import axios from "axios";
import * as cheerio from "cheerio";
import fs from "fs";
import {getFilteredUrls} from "./SitemapUrls.js";


const urlsFile = "./urls.json";
const resultsFile = "./results.json";

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
async function scrapeProduct(url) {
  const { data: html } = await axios.get(url);
  const $ = cheerio.load(html);
  const isbnRaw = $(".product-isbn").text().trim();
  const isbn = cleanISBN(isbnRaw);
  const rawTitle = cleanText($(".episode-name").text());
  const cover = $(".gallery-placeholder__image").attr("src") || null;
  if (cover.endsWith("tempcover.jpg")) {
    cover = null;
  }
  const { seriesEpisodeNumber, episodeTitle, episodePart, totalParts, episodeCode } = parseEpisodeTitle(rawTitle);

  return {
    link: url,
    cover: cover,
    seriesName: cleanText($(".series-name").text()),
    title: episodeTitle,
    rawtitle: rawTitle,
    episodeNumber: seriesEpisodeNumber,
    episodePart: episodePart,
    episodeCode: episodeCode,
    totalParts: totalParts,
    subtitle: cleanText($(".dramatized-adaptation").text()),
    author: cleanText($('div[itemprop="author"]').text()),
    releaseDate: cleanReleaseDate(cleanText($(".product-releasedate").text())),
    isbn: isbn,
    genre: cleanGenre(cleanText($(".product-genre").text())),
    description: cleanText($(".product-description").text()),
    copyright: cleanText($(".product-copyright").text()),
    cast: [
      ...new Set(
        $(".additional-attributes-wrapper .attribute a.credit")
          .map((i, el) => cleanText($(el).text()))
          .get()
      ),
    ],
  };
}

/** Get or cache product URLs */
async function getProductUrls() {
  if (fs.existsSync(urlsFile)) {
    console.log("📂 Loading cached product URLs...");
    return JSON.parse(fs.readFileSync(urlsFile));
  }
  console.log("🌐 Fetching catalog (this may take a while)...");

  const sitemapUrls = await getFilteredUrls();

  console.log(`🌐 Using sitemap URLs: ${sitemapUrls.length}`);

  fs.writeFileSync(urlsFile, JSON.stringify(sitemapUrls, null, 2));

  console.log(`✅ Cached ${sitemapUrls.length} product URLs`);
  return sitemapUrls;
}

/** Main scraper */
async function scrapeAll() {
  const productUrls = await getProductUrls();

  let results = [];
  let scrapedUrls = new Set();

  if (fs.existsSync(resultsFile)) {
    results = JSON.parse(fs.readFileSync(resultsFile));
    scrapedUrls = new Set(results.map((r) => r.link));
    console.log(`🔄 Resuming: already scraped ${scrapedUrls.size} products`);
  }

  for (const url of productUrls) {
    if (scrapedUrls.has(url) || scrapedUrls.has(url.replace("graphicaudiointernational.net", "graphicaudio.net")) || scrapedUrls.has(url.replace("graphicaudio.net", "graphicaudiointernational.net"))) {
      console.log(`⏩ Skipping: ${url}`);
      continue;
    }
    console.log(`➡️ Scraping: ${url}`);
    try {
      const product = await scrapeProduct(url);

      if (!product.title) {
        console.error(`❌ Missing title for ${url}, skipping...`);
        continue;
      }
      if (!product.cover) {
        console.warn(`❌ Missing cover image for ${url}, skipping...`);
        continue;
      }

      results.push(product);
      fs.writeFileSync(resultsFile, JSON.stringify(results, null, 2));
      console.log(`💾 Saved: ${url}`);
    } catch (err) {
      console.error(`❌ Failed: ${url} - ${err.message}`);
    }
  }

  console.log(`\n🎉 Done! Total products scraped: ${results.length}`);
}

scrapeAll();
