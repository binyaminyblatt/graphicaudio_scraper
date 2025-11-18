import axios from "axios";
import fs from "fs";
import { exit } from "process";

const RESULTS_FILE = "./results.json";
const API_URL = "https://audimeta.de";
const UA =
  "GraphicAudio-Lookup/1.0 (ISBN-ASIN Matcher; +https://graphicaudio-api.endl.site/; " +
  "GitHub: https://github.com/binyaminyblatt/graphicaudio_scraper.git)";

/** Load JSON */
let data = JSON.parse(fs.readFileSync(RESULTS_FILE, "utf-8"));

async function smartGet(url, headers, attempt = 0) {
  try {
    const res = await axios.get(url, { headers });

    const remaining = Number(res.headers["x-ratelimit-remaining"]);
    const limit = Number(res.headers["x-ratelimit-limit"]);

    // Adaptive delay: slow down when remaining is low
    if (!isNaN(remaining) && !isNaN(limit)) {
      if (remaining < 5) {
        const delay = 2000; // 2 seconds
        console.log(`⏳ Rate low (${remaining}/${limit}) — waiting ${delay}ms`);
        await new Promise((r) => setTimeout(r, delay));
      } else {
        // Light delay based on how much quota is left
        const ratio = remaining / limit;
        const delay = Math.round(300 + (1 - ratio) * 1200);
        console.log(`⏳ Throttle: waiting ${delay}ms (remaining ${remaining})`);
        await new Promise((r) => setTimeout(r, delay));
      }
    }

    return res;
  } catch (err) {
    if (err.response?.status === 429) {
      // exponential retry backoff
      const wait = Math.min(2000 * (attempt + 1), 15000);
      console.warn(`🚫 429 Too Many Requests — waiting ${wait}ms`);
      await new Promise((r) => setTimeout(r, wait));
      return smartGet(url, headers, attempt + 1);
    }

    throw err;
  }
}

async function fetchByISBN(isbn) {
  const url = `${API_URL}/db/book?isbn=${isbn}&page=1&limit=1`;
  console.log(`Fetching URL: ${url}`);
  return smartGet(url, { "User-Agent": UA, accept: "application/json" });
}

async function fetchByTitle(title) {
  const url = `${API_URL}/search?cache=false&page=1&limit=10&region=us&title=${encodeURIComponent(title)}`;
  console.log(`Fetching URL: ${url}`);
  return smartGet(url, { "User-Agent": UA, accept: "application/json" });
}

async function fetchASIN(entry) {
  const { isbn, title, subtitle } = entry;

  /** Try ISBN lookup first */
  if (isbn) {
    try {
      const { data } = await fetchByISBN(isbn);

      if (data?.items?.length) {
        return data.items[0].asin || null;
      }
    } catch (err) {
      console.error(`❌ ISBN lookup error for ${isbn}: ${err.message}`);
    }
  }

  /** Fallback: try title lookup */
  if (title) {
    console.log(`🔎 Trying title lookup for "${title} ${subtitle}"...`);
    try {
      const { data } = await fetchByTitle(`${title} ${subtitle}`);

      if (data?.items?.length) {
        // Try to find a matching ISBN
        const item = data.items.find((x) => x.isbn === isbn) || data.items[0];
        return item.asin || null;
      }
    } catch (err) {
      console.error(
        `❌ Title lookup error for "${title} ${subtitle}": ${err.message}`
      );
    }
  }

  return null; // nothing found
}

async function updateASINs() {
  let updated = 0;

  for (let entry of data) {
    if (!entry.asin && entry.isbn) {
      console.log(`🔍 Looking up ASIN for ISBN ${entry.isbn}...`);
      const asin = await fetchASIN(entry);

      if (asin) {
        entry.asin = asin;
        updated++;
        console.log(`✅ Found ASIN: ${asin}`);
      } else {
        console.log(`⚠️ No ASIN found for ISBN ${entry.isbn}`);
      }
    } else {
      console.log(`ℹ️ Entry already has ASIN: ${entry.asin}`);
      continue;
    }
  }

  fs.writeFileSync(RESULTS_FILE, JSON.stringify(data, null, 2));
  console.log(`\n💾 Done! Updated ${updated} entries with ASINs.`);
}

updateASINs();
