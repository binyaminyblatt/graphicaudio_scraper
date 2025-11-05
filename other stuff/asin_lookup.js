import axios from "axios";
import fs from "fs";

const RESULTS_FILE = "./results.json";
const API_URL = "https://audimeta.de/db/book";

/** Load JSON */
let data = JSON.parse(fs.readFileSync(RESULTS_FILE, "utf-8"));

async function fetchASIN(isbn) {
  try {
    const url = `${API_URL}?isbn=${isbn}&page=1&limit=1`;
    const { data } = await axios.get(url);
    
    if (data && data.items && data.items.length > 0) {
      const item = data.items[0];
      return item.asin || null;  // assuming API returns "asin" field
    }
    return null;
  } catch (err) {
    console.error(`❌ Failed to fetch ASIN for ISBN ${isbn}: ${err.message}`);
    return null;
  }
}

async function updateASINs() {
  let updated = 0;

  for (let entry of data) {
    if (!entry.asin && entry.isbn) {
      console.log(`🔍 Looking up ASIN for ISBN ${entry.isbn}...`);
      const asin = await fetchASIN(entry.isbn);

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
