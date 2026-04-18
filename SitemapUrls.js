import axios from "axios";
import { parseStringPromise } from "xml2js";

const SITEMAP_INDEX = "https://www.graphicaudio.net/sitemap.xml";

async function getAllSitemapUrls() {
  console.log("🌐 Fetching sitemap index...");

  const { data } = await axios.get(SITEMAP_INDEX);
  const parsed = await parseStringPromise(data);

  let sitemapUrls = [];

  // Case 1: sitemap index
  if (parsed.sitemapindex) {
    sitemapUrls = parsed.sitemapindex.sitemap.map(s => s.loc[0]);
  }
  // Case 2: already a sitemap
  else if (parsed.urlset) {
    sitemapUrls = [SITEMAP_INDEX];
  }

  console.log(`📄 Found ${sitemapUrls.length} sitemap files`);

  let allUrls = [];

  for (const smUrl of sitemapUrls) {
    console.log(`➡️ Fetching: ${smUrl}`);

    try {
      const { data: smData } = await axios.get(smUrl);
      const smParsed = await parseStringPromise(smData);

      if (!smParsed.urlset) continue;

      const urls = smParsed.urlset.url.map(u => u.loc[0]);
      console.log(`   ↳ ${urls.length} URLs`);

      allUrls.push(...urls);
    } catch (err) {
      console.error(`❌ Failed: ${smUrl} - ${err.message}`);
    }
  }

  return allUrls;
}

function isExcluded(url) {
  return (
    url.endsWith("-mp3-cd.html") ||
    url.endsWith("-series-set.html") ||
    url.endsWith("-download-set.html") ||
    !url.endsWith(".html") ||
    url.includes("-mp3-cd") ||
    url.includes("cd") ||
    url.includes("-series-set")||
    url.includes("-download-set")||
    url.includes("our-productions") ||
    url.includes("contact-us") ||
    url.includes("about-us") ||
    url.includes("trending")||
    url.includes("fan-favorites")|
    url.includes("gift-card")
  );
}
function normalizeUrl(url) {
  return url
    .replace(/^https?:\/\//, "")   // remove protocol
    .replace(/^www\./, "")         // remove www
    .replace("graphicaudiointernational.net", "graphicaudio.net")
    .replace(/\/$/, "")            // remove trailing slash
    .split("?")[0]                 // remove query params
    .trim();
}
async function getFilteredUrls() {
  const urls = await getAllSitemapUrls();

  urls.sort();
  const filtered = urls.filter(url => !isExcluded(url));

  console.log(`\n🔢 Total raw URLs: ${urls.length}`);
  console.log(`🔢 Total filtered URLs: ${filtered.length}`);
  const newUrls = filtered.sort();
  return newUrls;
}

export { getFilteredUrls };