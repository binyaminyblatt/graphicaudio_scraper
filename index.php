<?php
/* --------------------------------------------------------
   CONFIG
---------------------------------------------------------*/
define("JSON_URL",
    "https://raw.githubusercontent.com/binyaminyblatt/graphicaudio_scraper/refs/heads/main/results.json"
);
define("CACHE_FILE", __DIR__ . "/cache.json");
define("CACHE_TTL", 3600);
define("IMAGE_DIR", __DIR__ . "/covers");

define("WAYBACK_URL", "https://raw.githubusercontent.com/binyaminyblatt/graphicaudio_scraper/refs/heads/main/wayback_results.json");
define("WAYBACK_CACHE_FILE", __DIR__ . "/wayback_cache.json");
define("REFRESH_KEY", "YOUR_SECRET_KEY_HERE"); // change this!
define("AUDIOBOOKSHELF_KEY", "abs"); // change this!
// Use "abs" (default) for no authentication
define("DEBUG_LOG", __DIR__ . "/debug.log");
define("DEBUG", false); // set to true to enable debug logging and allow GET refresh (not recommended for production)
define("LOW_BANDWIDTH_LIMIT_ENABLE", true);



function getAuthHeader() {
    foreach ([
        "HTTP_AUTHORIZATION",
        "Authorization",
        "REDIRECT_HTTP_AUTHORIZATION",
        "HTTP_X_AUTHORIZATION"
    ] as $key) {
        if (!empty($_SERVER[$key])) return trim($_SERVER[$key]);
    }
    return null;
}


if (LOW_BANDWIDTH_LIMIT_ENABLE){
    $BandwidthKey = getAuthHeader();

    if ($BandwidthKey === REFRESH_KEY) {
        // Exempt from rate limiting
        define("LOW_BANDWIDTH_LIMIT", false);
    }else{
        define("LOW_BANDWIDTH_LIMIT", true);
    }
}else{
    define("LOW_BANDWIDTH_LIMIT", false);
}

if (!is_dir(IMAGE_DIR)) mkdir(IMAGE_DIR, 0777, true);

/* --------------------------------------------------------
   Helper function to download with cURL using native CA
---------------------------------------------------------*/
function downloadWithCurl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    if (defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // timeout
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

/* --------------------------------------------------------
   Sanitize user input
---------------------------------------------------------*/
function clean($input, $type) {
    $input = trim($input);

    switch ($type) {
        case "asin":
            // ASINs are alphanumeric only
            return preg_replace("/[^A-Za-z0-9]/", "", $input);

        case "isbn":
            // ISBNs are digits only
            return preg_replace("/[^0-9]/", "", $input);

        case "series":
            // allow letters, numbers, spaces, dashes, apostrophes
            return preg_replace("/[^A-Za-z0-9 \-']/","", $input);

        default:
            return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }
}

/* --------------------------------------------------------
   Load JSON from APCu cache / remote
---------------------------------------------------------*/
function loadData() {
    // ✅ If APCu exists, use it
    if (function_exists("apcu_exists") && apcu_exists("ga_data")) {
        return apcu_fetch("ga_data");
    }

    // ✅ File cache fallback
    if (file_exists(CACHE_FILE)) {
        $age = time() - filemtime(CACHE_FILE);
        if ($age < CACHE_TTL) {
            $json = file_get_contents(CACHE_FILE);
            return json_decode($json, true);
        }
    }

    // ✅ Download fresh JSON
    $json = downloadWithCurl(JSON_URL);
    if (!$json) die("Could not download JSON data");

    file_put_contents(CACHE_FILE, $json);

    $data = json_decode($json, true);

    if (function_exists("apcu_store")) {
        apcu_store("ga_data", $data, CACHE_TTL);
    }

    return $data;
}

/* --------------------------------------------------------
   Load Wayback JSON from APCu cache / remote
---------------------------------------------------------*/
function loadWaybackData() {
    // ✅ If APCu exists, use it
    if (function_exists("apcu_exists") && apcu_exists("ga_wayback_data")) {
        return apcu_fetch("ga_wayback_data");
    }

    // ✅ File cache fallback
    if (file_exists(WAYBACK_CACHE_FILE)) {
        $age = time() - filemtime(WAYBACK_CACHE_FILE);
        if ($age < CACHE_TTL) {
            $json = file_get_contents(WAYBACK_CACHE_FILE);
            return json_decode($json, true);
        }
    }

    // ✅ Download fresh JSON
    $json = downloadWithCurl(WAYBACK_URL);
    if (!$json) die("Could not download JSON data");

    file_put_contents(WAYBACK_CACHE_FILE, $json);

    $wayback_data = json_decode($json, true);

    if (function_exists("apcu_store")) {
        apcu_store("ga_wayback_data", $wayback_data, CACHE_TTL);
    }

    return $wayback_data;
}


$ga_data = loadData();
$wayback_data = loadWaybackData();

/* --------------------------------------------------------
   Lookup helpers
---------------------------------------------------------*/
function findByField($data, $field, $value) {
    $matches = [];

    foreach ($data as $item) {
        if (!empty($item[$field]) && strtolower($item[$field]) === strtolower($value)) {
            $item["titleOther"] = cleanRawTitle($item["rawtitle"]);
            return $item; // exact match
        }

        if (!empty($item[$field])) {
            similar_text(strtolower($item[$field]), strtolower($value), $score);
            if ($score >= 70) {
                $item["titleOther"] = cleanRawTitle($item["rawtitle"]);
                $item["_confidence"] = round($score, 2);
                $matches[] = $item;
            }
        }
    }
    return $matches ?: null;
}

function findSeries($data, $name) {
    $matches = [];

    foreach ($data as $item) {
        if (!empty($item["seriesName"])) {
            similar_text(strtolower($item["seriesName"]), strtolower($name), $score);
            if ($score >= 70) {
                $item["_confidence"] = round($score, 2);
                $matches[] = $item;
            }
        }
    }
    return $matches ?: null;
}

/* --------------------------------------------------------
   Serve cached cover image
---------------------------------------------------------*/
function serveCover($item) {
    if (empty($item["cover"])) {
        http_response_code(404);
        die("No cover available for this entry.");
    }

    if (LOW_BANDWIDTH_LIMIT) {
        header("Location: " . $item["cover"], true, 302);
        exit;
    }

    $filename = IMAGE_DIR . "/" . md5($item["cover"]) . ".jpg";

    if (!file_exists($filename)) {
        // `@` prevents warnings on bad URLs / image download failures
        @file_put_contents($filename, downloadWithCurl($item["cover"]));
    }

    header("Content-Type: image/jpeg");
    readfile($filename);
    exit;
}

/* --------------------------------------------------------
   Force refresh JSON (requires key)
---------------------------------------------------------*/
function refreshData($key) {
    global $waybackMode;
    if ($key !== REFRESH_KEY) {
        http_response_code(403);
        die("Invalid refresh key.");
    }

    // Remove APCu cache
    if (function_exists("apcu_delete")) {
        apcu_delete("ga_data");
        apcu_delete("ga_wayback_data");
    }

    // Remove file cache
    if (file_exists(CACHE_FILE)) {
        unlink(CACHE_FILE);
        unlink(WAYBACK_CACHE_FILE);
    }

    // Download fresh JSON and store again
    if ($waybackMode) {
        loadData(); // also refresh main dataset to keep them in sync
        return loadWaybackData();
    } else {
        loadWaybackData(); // also refresh wayback dataset to keep them in sync
        return loadData();
    }

}

/* --------------------------------------------------------
   Search endpoint (/search/{query})
---------------------------------------------------------*/
function calcConfidence($item, $key, $query) {
    if (!empty($item[$key])) {
        similar_text(strtolower($item[$key]), $query, $score);
        return $score;
    }
    return 0;
}

function searchData($data, $query) {
    $query = strtolower($query);
    $results = [];
    $minConfidence = 70;

    foreach ($data as $item) {
        $item["titleOther"] = cleanRawTitle($item["rawtitle"]);
        $confidence = max(
            calcConfidence($item, "title", $query),
            calcConfidence($item, "titleOther", $query),
            calcConfidence($item, "seriesName", $query),
            calcConfidence($item, "rawtitle", $query),
            calcConfidence($item, "author", $query)
        );

        if ($confidence >= $minConfidence) {
            $item["_confidence"] = round($confidence, 2);
            $results[] = $item;
        }
    }
    if ($results) {
        usort($results, fn($a, $b) => $b["_confidence"] <=> $a["_confidence"]);
    }

    return $results ?: null;
}
/* --------------------------------------------------------
   Audiobookshelf Metadata Provider
---------------------------------------------------------*/
function coverUrlFromISBN($isbn) {
    // builds:  https://currentdomain/isbn/{isbn}/cover
    // If the request is under /wayback, keep that prefix so the correct dataset is used.
    global $waybackMode;

    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
          . "://"
          . $_SERVER['HTTP_HOST'];

    $prefix = !empty($waybackMode) ? "/wayback" : "";

    return $base . $prefix . "/isbn/" . urlencode($isbn) . "/cover";
}

function cleanRawTitle($rawtitle) {
    if (!$rawtitle) return null;

    // Split by ':' and take the last part
    $parts = explode(":", $rawtitle);
    $last = end($parts);

    // Trim whitespace
    return trim($last);
}

/**
 * Format JSON output for Audiobookshelf
 */
function outputABSResult($items) {
    $response = ["matches" => []];

    foreach ($items as $item) {
        $narrator = null;
        if (!empty($item["cast"]) && is_array($item["cast"])) {
            $narrator = implode(", ", $item["cast"]);
        }
        $publishedYear = null;

        if (!empty($item["releaseDate"])) {
            $timestamp = strtotime($item["releaseDate"]);
            if ($timestamp !== false) {
                $publishedYear = date("Y", $timestamp);
            }
        }
        if (LOW_BANDWIDTH_LIMIT) {
            // Remove description to save bandwidth
            $cover = $item['cover'] ?? null;
        } else {
            $cover = isset($item["isbn"]) && $item["isbn"] !== "" ? coverUrlFromISBN($item["isbn"]) : null;
        }

        $abs = [
            'url'          => $item["link"] ?? null,
            "title"        => cleanRawTitle($item["rawtitle"]),
            "subtitle"     => $item["subtitle"] ?? null,
            "author"       => $item["author"] ?? null,
            "authors"      => !empty($item["author"]) ? [$item["author"]] : [],
            "narrator"     => $narrator,
            "narrators"    => !empty($item["cast"]) ? $item["cast"] : [],
            "publisher"    => "GraphicAudio",
            "publishedYear"=> $publishedYear ?? null,
            "description"  => $item["description"]  ?? null,
            "cover"        => $cover,
            "isbn"         => $item["isbn"] ?? null,
            "asin"         => $item["asin"] ?? null,
            "genres"       => !empty($item["genre"]) ? [$item["genre"]] : [],
            "tags"         => [],
            "language"     => "English"
        ];
        // Only include series if values exist
        if (!empty($item["seriesName"])) {
            $abs["series"] = [[
                "series"   => $item["seriesName"],
                "sequence" => is_numeric($item["episodeCode"]) ? floatval($item["episodeCode"]) : null
            ]];
        }

        $response["matches"][] = $abs;
    }
    // --- DEBUG LOGGING ---
    if (DEBUG) {
        $logData  = "----- " . date("c") . " -----\n";
        $logData .= "REQUEST URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "\n";
        $logData .= "QUERY PARAMS: " . json_encode($_GET) . "\n";
        $logData .= "RESPONSE JSON: " . json_encode($response, JSON_UNESCAPED_SLASHES) . "\n\n";
        file_put_contents(DEBUG_LOG, $logData, FILE_APPEND);
    }
    header("Content-Type: application/json");
    echo json_encode($response);
}

/* --------------------------------------------------------
   Bandwidth rate limiting
---------------------------------------------------------*/
if (LOW_BANDWIDTH_LIMIT) {
    if (!function_exists("rateLimit")) {
        function rateLimit() {
            $file = __DIR__ . "/rate.log";
            $limit = 2000; // limit requests/hour
            $window = 3600;

            $now = time();
            $log = [];

            if (file_exists($file)) {
                $log = json_decode(file_get_contents($file), true);
            }

            // purge old entries
            $log = array_filter($log, fn($t) => ($now - $t) < $window);

            if (count($log) >= $limit) {
                http_response_code(429);
                die("Rate limit exceeded (low bandwidth mode)");
            }

            $log[] = $now;
            file_put_contents($file, json_encode($log));
        }
    }

    rateLimit();
}

/* --------------------------------------------------------
    ISBN Toolbox
---------------------------------------------------------*/
function isValidISBN10($isbn) {
    if (!preg_match('/^[0-9]{9}[0-9X]$/i', $isbn)) return false;

    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += ((int)$isbn[$i]) * ($i + 1);
    }

    $check = strtoupper($isbn[9]);
    $sum += ($check === 'X') ? 10 * 10 : ((int)$check) * 10;

    return $sum % 11 === 0;
}

function isValidISBN13($isbn) {
    if (!preg_match('/^[0-9]{13}$/', $isbn)) return false;

    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int)$isbn[$i] * ($i % 2 === 0 ? 1 : 3);
    }

    $check = (10 - ($sum % 10)) % 10;
    return $check === (int)$isbn[12];
}

function isValidISBN($query) {
    if (strlen($query) == 10) return isValidISBN10($query);
    if (strlen($query) == 13) return isValidISBN13($query);
    return false;
}



/* --------------------------------------------------------
   Very small router
---------------------------------------------------------*/
$request = trim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
$parts = explode("/", $request);

// Support a “/wayback/*” prefix that uses archived data from wayback_results.json
$waybackMode = false;
$dataSource = $ga_data;
if (isset($parts[0]) && $parts[0] === "wayback") {
    $waybackMode = true;
    array_shift($parts);
    $dataSource = $wayback_data;
}

if (!empty($parts) && $parts[0] === "refresh") {
    if ($_SERVER["REQUEST_METHOD"] !== "PUT") {
        if (!DEBUG) {
            http_response_code(405);
            header("Allow: PUT");
            die("Method Not Allowed — refresh requires PUT");
        } else {
            // Allow GET for refresh in debug mode for easier testing
            refreshData($_GET['key'] ?? '');
            die("✅ Cache cleared, data refreshed. (Debug mode allows GET)");
        }
    }

    // Get key from URL query parameter
    $key = $_GET['key'] ?? null;

    if (!$key) {
        http_response_code(400);
        die("Missing key in URL");
    }

    refreshData($key);
    die("✅ Cache cleared, data refreshed.");
}

// Lookup by ASIN
if (!empty($parts) && $parts[0] === "asin" && !empty($parts[1])) {
    $asin = clean($parts[1], "asin");
    $result = findByField($dataSource, "asin", $asin);
    if (!$result) die("ASIN not found");

    if (isset($parts[2]) && $parts[2] === "cover") serveCover($result);

    header("Content-Type: application/json");
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// Lookup by ISBN
if (!empty($parts) && $parts[0] === "isbn" && !empty($parts[1])) {
    $isbn = clean($parts[1], "isbn");
    $result = findByField($dataSource, "isbn", $isbn);
    if (!$result) die("ISBN not found");

    if (isset($parts[2]) && $parts[2] === "cover") serveCover($result);

    header("Content-Type: application/json");
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// Lookup by series name
if (!empty($parts) && $parts[0] === "series" && !empty($parts[1])) {
    $series = clean(urldecode($parts[1]), "series");
    $result = findSeries($dataSource, $series);
    if (!$result) die("Series not found");

    header("Content-Type: application/json");
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// General search by title or series
if (!empty($parts) && $parts[0] === "search" && !empty($parts[1])) {
    $query = clean(urldecode($parts[1]), "default");
    $query = str_replace("[Dramatized Adaptation]", "", $query);
    $query = str_replace("(Dramatized Adaptation)", "", $query);
    $result = searchData($dataSource, $query);
    if (!$result) die("No matching entries found");

    header("Content-Type: application/json");
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

if (!empty($parts) && $parts[0] === "debug") {
    if (DEBUG) {
        header("Content-Type: text/plain");
        echo file_get_contents(DEBUG_LOG);
        exit;
    } else {
        http_response_code(404);
        die("Debug logging is disabled.");
    }
}

// Audiobookshelf custom metadata provider search
if (!empty($parts) && $parts[0] === "audiobookshelf" && $parts[1] === "search") {

    // Authentication if key is set (otherwise open)
    if (AUDIOBOOKSHELF_KEY !='abs'){
        // Authentication via AUTHORIZATION header (Audiobookshelf Metadata Provider)
        $apiKey = getAuthHeader();

        if (!$apiKey || $apiKey !== AUDIOBOOKSHELF_KEY) {
            http_response_code(401);
            echo json_encode(["error" => "Unauthorized"]);
            exit;
        }
    }
    
    $queryRaw = $_GET["query"] ?? null;
    if (!$queryRaw) {
        http_response_code(400);
        echo json_encode(["error" => "query parameter required"]);
        exit;
    }

    $query = clean($queryRaw, "default");

    // Detect ISBN (numbers only, 10 or 13 digits)
    if (isValidISBN($query)) {
        $result = findByField($dataSource, "isbn", $query);

        if ($result) {
            outputABSResult([$result]);
            exit;
        }
    }

    // Detect ASIN (alphanumeric, exactly 10 chars)
    if (preg_match("/^[A-Za-z0-9]{10}$/", $query)) {
        $result = findByField($dataSource, "asin", $query);

        if ($result) {
            outputABSResult([$result]);
            exit;
        }
    }
    $query = str_replace("[Dramatized Adaptation]", "", $query);
    $query = str_replace("(Dramatized Adaptation)", "", $query);
    // Otherwise → fuzzy search
    $results = searchData($dataSource, $query);
    
    outputABSResult($results ?? []);
    exit;
}

/* --------------------------------------------------------
   Default landing page
---------------------------------------------------------*/
$prefix = !empty($waybackMode) ? "/wayback" : "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>GraphicAudio Lookup API</title>
<style>
    body {
        font-family: Arial, sans-serif;
        background: #f5f5f5;
        margin: 20px;
        padding: 20px;
        border-radius: 10px;
    }
    code {
        background: #eee;
        padding: 2px 5px;
        border-radius: 4px;
    }
    .endpoint {
        margin-bottom: 12px;
        background: #fff;
        padding: 10px;
        border-radius: 6px;
        border-left: 5px solid #0077cc;
    }
    h2 {
        margin-bottom: 8px;
    }
    .wayback{
        background: #d1ecf1; 
        border-left:5px solid #0c5460; 
        padding:10px; 
        border-radius:6px; 
        margin-bottom:16px;
    }
    .disclaimer{
        background: #fff3cd; 
        border-left:5px solid #ffcc00; 
        padding:10px; 
        border-radius:6px; 
        margin-bottom:16px;
        color: #856404;
    }
    .red-border {
        border-left-color: #cc0000 !important;
    }
    .purple-border {
        border-left-color: #aa00ff !important;
    }
    .yellow-border {
        border-left-color: #ffcc00 !important;
    }
    footer {
        margin-top: 25px;
        padding-top: 15px;
        font-size: 0.9rem;
        color: #555;
    }
</style>
</head>
<body>

<h2>GraphicAudio Lookup API</h2>
<p>This endpoint returns metadata scraped from GraphicAudio using the <?php if ($waybackMode): ?>Wayback Machine <code>wayback_results.json</code><?php else: ?><code>results.json</code><?php endif; ?> data file.</p>
<?php if ($waybackMode): ?>
    <p>To query the active dataset, remove <code>/wayback/</code> from any endpoint (e.g. <code>/isbn/{isbn}</code>).</p>
<?php else: ?>
    <p>To query the archived Wayback dataset, prepend <code>/wayback/</code> to any endpoint (e.g. <code>/wayback/isbn/{isbn}</code>).</p>
<?php endif; ?>
<?php if ($waybackMode): ?>
    <div class="wayback">
        <strong>Wayback Mode:</strong><br>
        You are currently viewing archived data from the Wayback Machine.  
        Results may be outdated and incomplete compared to the main dataset.
    </div>
<?php endif; ?>
<div class="disclaimer">
    <strong>Disclaimer:</strong><br>
    This API is a <em>personal hobby project</em>.  
    It is <strong>not affiliated with, endorsed by, or associated with GraphicAudio</strong>.  
    All trademarks and content belong to their respective owners.
</div>
<div class="endpoint yellow-border">
    <strong>Important Note about the Wayback Dataset:</strong><br>
    <p>The Wayback dataset contains entries that have since been removed from GraphicAudio before I started scraping.<br>
        I captured as much as I could, but the dataset is incomplete and may be missing metadata (covers, ISBNs, etc.).<br>
        If you find a Wayback Machine snapshot of a missing entry, please open a
        <a href="https://github.com/binyaminyblatt/graphicaudio-api/issues" target="_blank">GitHub issue</a>
        with the snapshot link and I’ll see if I can add it.<br>
         Note that some missing fields (especially covers) may not be recoverable.
        <br><strong>Big thanks to the Wayback Machine and archive.org for preserving this data!</strong>
    </p>
</div>
<h3>Available Endpoints</h3>

<div class="endpoint">
    <strong>Lookup by ASIN</strong> <small>Not all books have ASINs</small><br>
    <code><?php echo $prefix; ?>/asin/{asin}</code><br>
    Example: <code><?php echo $prefix; ?>/asin/B09C4Y7T1Q</code><br>
    Append <code>/cover</code> to download cached cover:<br>
    <code><?php echo $prefix; ?>/asin/B09C4Y7T1Q/cover</code>
</div>

<div class="endpoint">
    <strong>Lookup by ISBN</strong><br>
    <code><?php echo $prefix; ?>/isbn/{isbn}</code><br>
    Example: <code><?php echo $prefix; ?>/isbn/9781427280583</code><br>
    Append <code>/cover</code> to download cached cover:<br>
    <code><?php echo $prefix; ?>/isbn/9781427280583/cover</code>
</div>

<div class="endpoint">
    <strong>Lookup by series name (fuzzy matching)</strong><br>
    <code><?php echo $prefix; ?>/series/{series}</code><br>
    Example: <code><?php echo $prefix; ?>/series/The%20Stormlight%20Archive</code>
</div>

<div class="endpoint">
    <strong>🔍 Search (title, rawtitle, author or series)</strong><br>
    <code><?php echo $prefix; ?>/search/{query}</code><br>
    Example: <code><?php echo $prefix; ?>/search/Oathbringer</code>
</div>

<div class="endpoint purple-border">
    <strong>📚 Audiobookshelf Metadata Provider</strong><br>
    <code><?php echo $prefix; ?>/audiobookshelf/search?query={isbn|asin|text}</code><br>
    <?php if (AUDIOBOOKSHELF_KEY !='abs'): ?>
    Requires <code>Authorization: YOUR_API_KEY</code> header.<br>
    <?php else: ?>
    Ignores authentication (open access).<br>
    <?php endif; ?>
    <small>Automatically detects ISBN / ASIN / text search.</small><br>
    Example (ISBN): <code><?php echo $prefix; ?>/audiobookshelf/search?query=9798896520030</code><br>
    Example (Title search): <code><?php echo $prefix; ?>/audiobookshelf/search?query=Stormlight</code>
</div>

<div class="endpoint red-border">
    <strong>🚨 Force JSON Refresh (requires key)</strong><br>
    <code><?php echo $prefix; ?>/refresh?key=YOUR_SECRET_KEY_HERE</code><br>
    Clears APCu + cache.json and re-downloads fresh JSON. Must be a PUT with the key in the url<br>
    <em>Do not expose this key publicly.</em>
</div>

<hr>

<p>JSON source:<br>
<code><?php echo JSON_URL; ?></code></p>

<p>Wayback JSON source:<br>
<code><?php echo WAYBACK_URL; ?></code></p>


<footer>
    <p>
        This project is a hobby project created for personal use.
        It is <strong>not affiliated with, endorsed, or supported by GraphicAudio® 
        or any related entities.</strong><br>
        All trademarks and product names are the property of their respective owners.
    </p>
    <p>
        Source code available on GitHub:
        <a href="https://github.com/binyaminyblatt/graphicaudio_scraper" target="_blank">
            https://github.com/binyaminyblatt/graphicaudio_scraper
        </a>
    </p>
</footer>

</body>
</html>
