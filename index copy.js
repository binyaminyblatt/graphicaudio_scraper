import axios from "axios";
import * as cheerio from "cheerio";
import fs from "fs";

/**
 * Scrape a single product page
 */
function cleanISBN(raw) {
  if (!raw) return null;
  // Remove "ISBN", colons, spaces
  let cleaned = raw.replace(/ISBN/i, "").replace(/[:\s]/g, "");
  // Extract only digits and X (for ISBN-10)
  const match = cleaned.match(/[0-9X]+/);
  return match ? match[0] : null;
}

function cleanText(str) {
  return str ? str.replace(/\s+/g, ' ').trim() : null;
}
function cleanGenre(str) {
  if (!str) return null;
  return str.replace(/Genre:/i, '').trim();
}

function cleanReleaseDate(str) {
  if (!str) return null;
  // Remove the prefix
  const dateStr = str.replace(/Release Date:/i, '').trim();
  // Convert to Date object
  const date = new Date(dateStr);
  // Return ISO string or null if invalid
  return isNaN(date) ? null : date.toISOString();
}

function parseEpisodeTitle(raw) {
  if (!raw) return { seriesEpisodeNumber: null, episodeTitle: null, episodePart: null, episodeCode: null };

  // Example formats:
  // "Episode number 1 : The Final Empire (1 of 3)"
  // "Episode number 2 : The Well of Ascension"
  const match = raw.match(/Episode\s+number\s+(\d+)\s*:\s*(.*?)(?:\((\d+)\s+of\s+\d+\))?$/i);

  const seriesEpisodeNumber = match ? parseInt(match[1], 10) : null;
  const episodeTitle = match ? match[2].trim() : raw.trim();
  const episodePart = match && match[3] ? match[3] : "1"; // default to 1 if missing
  const episodeCode = seriesEpisodeNumber ? `${seriesEpisodeNumber}.${episodePart}` : null;

  return { seriesEpisodeNumber, episodeTitle, episodePart, episodeCode };
}


async function scrapeProduct(url) {
  const { data: html } = await axios.get(url);
  const $ = cheerio.load(html);
    const isbnRaw = $('.product-isbn').text().trim();
    const isbn = cleanISBN(isbnRaw);
    const rawTitle = cleanText($('.episode-name').text());
    const { seriesEpisodeNumber, episodeTitle, episodePart, episodeCode } = parseEpisodeTitle(rawTitle);

  return {
    link: url,
    cover: $(".gallery-placeholder__image").attr("src") || null,
    seriesName: cleanText($('.series-name').text()),
    title: episodeTitle,
    episodeNumber: seriesEpisodeNumber,
    episodePart: episodePart,
    episodeCode: episodeCode,
    subtitle: cleanText($(".dramatized-adaptation").text().trim()),
    author: cleanText($('div[itemprop="author"]').text().trim()),
    releaseDate: cleanReleaseDate(cleanText($(".product-releasedate").text().trim())),
    isbn: isbn,
    genre: cleanGenre(cleanText($(".product-genre").text().trim())),
    description: cleanText($(".product-description").text().trim()),
    copyright: cleanText($(".product-copyright").text().trim()),
    cast: [
      ...new Set(
        $(".additional-attributes-wrapper .attribute a.credit")
          .map((i, el) => cleanText($(el).text().trim()))
          .get()
      ),
    ],
  };
}

/**
 * Scrape all products from a listing page
 */
async function scrapeListing(listUrl) {
  const { data: listHtml } = await axios.get(listUrl);
  const $list = cheerio.load(listHtml);

  // Collect product URLs (excluding -SET-)
  const productUrls = $list("li.product-item")
    .filter(function () {
      const sku = $list(this).attr("data-sku");
      return sku && !sku.includes("-SET-");
    })
    .map(function () {
      return $list(this).find("a").first().attr("href");
    })
    .get()
    .filter(Boolean); // remove null/undefined

  console.log(`Found ${productUrls.length} product URLs`);

  // Scrape each product page
  const results = [];
  for (const url of productUrls) {
    console.log(`Scraping: ${url}`);
    try {
      const product = await scrapeProduct(url);
      results.push(product);
    } catch (err) {
      console.error(`❌ Failed to scrape ${url}:`, err.message);
    }
  }

  return results;
}

// Example usage:
// const catalogUrl = "https://www.graphicaudiointernational.net/our-productions/direct-store-exclusives.html";
const catalogUrl = "https://www.graphicaudiointernational.net/our-productions.html?product_list_limit=all";

scrapeListing(catalogUrl).then((data) => {
  // Save to JSON file
  const filePath = "./results.json";
  fs.writeFileSync(filePath, JSON.stringify(data, null, 2));
  console.log(`\n✅ Saved ${data.length} products to ${filePath}`);
});