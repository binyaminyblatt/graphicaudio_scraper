// validate-provider.js
const axios = require('axios').default;

async function validate(url, authHeader) {
  console.log('Requesting:', url);
  const opts = { timeout: 10000 };
  if (authHeader) opts.headers = { Authorization: authHeader };

  try {
    const res = await axios.get(url, opts);
    console.log('HTTP', res.status, res.headers['content-type']);
    const body = res.data;
    console.log('Top-level type:', typeof body);

    if (!body || !Array.isArray(body.matches)) {
      console.error('❌ Missing or invalid matches array. Body keys:', Object.keys(body || {}));
      console.error('RAW BODY:', JSON.stringify(body, null, 2));
      process.exit(2);
    }
    console.log('✅ matches is an array, length =', body.matches.length);

    for (let i = 0; i < body.matches.length; i++) {
      const m = body.matches[i];
      console.log(`--- item[${i}] keys:`, Object.keys(m));
      // basic checks similar to CustomProviderAdapter
      if (!m.title || typeof m.title !== 'string') console.warn(' title missing or not string');
      if (!m.authors || !Array.isArray(m.authors)) console.warn(' authors missing or not array');
      if (m.genres && !Array.isArray(m.genres)) console.warn(' genres present but not array');
      if (m.series && (!Array.isArray(m.series) || !m.series.length)) console.warn(' series present but invalid');
      if ('duration' in m && typeof m.duration !== 'number') console.warn(' duration present but not number');
    }

    console.log('Validation finished — fix the warnings above (if any).');
  } catch (err) {
    console.error('Request failed:', err.message);
    if (err.response) {
      console.error('Status:', err.response.status);
      console.error('Response data:', JSON.stringify(err.response.data, null, 2));
    }
    process.exit(1);
  }
}

const [,, baseUrl, auth] = process.argv;
if (!baseUrl) {
  console.error('Usage: node validate-provider.js https://yourdomain/audiobookshelf authValue(optional)');
  process.exit(1);
}
const url = baseUrl.replace(/\/$/, '') + '/search?query=test';
validate(url, auth);
