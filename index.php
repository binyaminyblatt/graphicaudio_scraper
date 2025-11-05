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
define("REFRESH_KEY", "YOUR_SECRET_KEY_HERE"); // change this!
define("AUDIOBOOKSHELF_KEY", "abs"); // change this!
// Use "abs" (default) for no authentication
define("DEBUG_LOG", __DIR__ . "/debug.log");
define("DEBUG", false); // set to true to enable debug logging



if (!is_dir(IMAGE_DIR)) mkdir(IMAGE_DIR, 0777, true);

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
    $json = @file_get_contents(JSON_URL);
    if (!$json) die("Could not download JSON data");

    file_put_contents(CACHE_FILE, $json);

    $data = json_decode($json, true);

    if (function_exists("apcu_store")) {
        apcu_store("ga_data", $data, CACHE_TTL);
    }

    return $data;
}

$data = loadData();

/* --------------------------------------------------------
   Lookup helpers
---------------------------------------------------------*/
function findByField($data, $field, $value) {
    $matches = [];

    foreach ($data as $item) {
        if (!empty($item[$field]) && strtolower($item[$field]) === strtolower($value)) {
            return $item; // exact match
        }

        if (!empty($item[$field])) {
            similar_text(strtolower($item[$field]), strtolower($value), $score);
            if ($score >= 70) {
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

    $filename = IMAGE_DIR . "/" . md5($item["cover"]) . ".jpg";

    if (!file_exists($filename)) {
        // `@` prevents warnings on bad URLs / image download failures
        @file_put_contents($filename, @file_get_contents($item["cover"]));
    }

    header("Content-Type: image/jpeg");
    readfile($filename);
    exit;
}

/* --------------------------------------------------------
   Force refresh JSON (requires key)
---------------------------------------------------------*/
function refreshData($key) {
    if ($key !== REFRESH_KEY) {
        http_response_code(403);
        die("Invalid refresh key.");
    }

    // Remove APCu cache
    if (function_exists("apcu_delete")) {
        apcu_delete("ga_data");
    }

    // Remove file cache
    if (file_exists(CACHE_FILE)) {
        unlink(CACHE_FILE);
    }

    // Download fresh JSON and store again
    return loadData();
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
        $confidence = max(
            calcConfidence($item, "title", $query),
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
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
          . "://"
          . $_SERVER['HTTP_HOST'];

    return $base . "/isbn/" . urlencode($isbn) . "/cover";
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
        $abs = [
            'url'          => $item["link"] ?? null,
            "title"        => cleanRawTitle($item["rawtitle"]),
            "subtitle"     => $item["subtitle"] ?? null,
            "author"       => $item["author"] ?? null,
            "authors"      => [$item["author"]] ?? null,
            "narrator"     => $narrator,
            "narrators"    => !empty($item["cast"]) ? $item["cast"] : [],
            "publisher"    => "GraphicAudio",
            "publishedYear"=> $publishedYear ?? null,
            "description"  => $item["description"]  ?? null,
            "cover"        => isset($item["isbn"]) && $item["isbn"] !== "" ? coverUrlFromISBN($item["isbn"]) : null,
            "isbn"         => $item["isbn"] ?? null,
            "asin"         => $item["asin"] ?? null,
            "genres"       => [$item["genre"] ?? null],
            "tags"         => [],
            "language"     => "English"
        ];
        // Only include series if values exist
        if (!empty($item["seriesName"])) {
            $abs["series"] = [[
                "series"   => $item["seriesName"],
                "sequence" => $item["episodeCode"] ?? null
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
   Very small router
---------------------------------------------------------*/
$request = trim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
$parts = explode("/", $request);

if ($parts[0] === "refresh") {
    if ($_SERVER["REQUEST_METHOD"] !== "PUT") {
        http_response_code(405);
        header("Allow: PUT");
        die("Method Not Allowed — refresh requires PUT");
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
if ($parts[0] === "asin" && !empty($parts[1])) {
    $asin = clean($parts[1], "asin");
    $result = findByField($data, "asin", $asin);
    if (!$result) die("ASIN not found");

    if (isset($parts[2]) && $parts[2] === "cover") serveCover($result);

    header("Content-Type: application/json");
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// Lookup by ISBN
if ($parts[0] === "isbn" && !empty($parts[1])) {
    $isbn = clean($parts[1], "isbn");
    $result = findByField($data, "isbn", $isbn);
    if (!$result) die("ISBN not found");

    if (isset($parts[2]) && $parts[2] === "cover") serveCover($result);

    header("Content-Type: application/json");
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// Lookup by series name
if ($parts[0] === "series" && !empty($parts[1])) {
    $series = clean(urldecode($parts[1]), "series");
    $result = findSeries($data, $series);
    if (!$result) die("Series not found");

    header("Content-Type: application/json");
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// General search by title or series
if ($parts[0] === "search" && !empty($parts[1])) {
    $query = clean(urldecode($parts[1]), "default");
    $result = searchData($data, $query);
    if (!$result) die("No matching entries found");

    header("Content-Type: application/json");
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

if ($parts[0] === "debug") {
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
if ($parts[0] === "audiobookshelf" && $parts[1] === "search") {

    // Authentication if key is set (otherwise open)
    if (AUDIOBOOKSHELF_KEY !='abs'){
        // Authentication via AUTHORIZATION header (Audiobookshelf Metadata Provider)
        $apiKey =
            $_SERVER["HTTP_AUTHORIZATION"] ??
            $_SERVER["Authorization"] ??
            $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ??
            null;

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
    if (preg_match("/^[0-9]{10,13}$/", $query)) {
        $result = findByField($data, "isbn", $query);

        if ($result) {
            outputABSResult([$result]);
            exit;
        }
    }

    // Detect ASIN (alphanumeric, exactly 10 chars)
    if (preg_match("/^[A-Za-z0-9]{10}$/", $query)) {
        $result = findByField($data, "asin", $query);

        if ($result) {
            outputABSResult([$result]);
            exit;
        }
    }
    $query = str_replace("[Dramatized Adaptation]", "", $query);
    $query = str_replace("(Dramatized Adaptation)", "", $query);
    // Otherwise → fuzzy search
    $results = searchData($data, $query);
    
    outputABSResult($results ?? []);
    exit;
}

/* --------------------------------------------------------
   Default landing page
---------------------------------------------------------*/
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
</style>
</head>
<body>

<h2>GraphicAudio Lookup API</h2>
<p>This endpoint returns metadata scraped from Graphicaudio via <code>results.json</code>.</p>

<div style="background:#fff3cd; border-left:5px solid #ffcc00; padding:10px; border-radius:6px; margin-bottom:16px;">
    <strong>Disclaimer:</strong><br>
    This API is a <em>personal hobby project</em>.  
    It is <strong>not affiliated with, endorsed by, or associated with GraphicAudio</strong>.  
    All trademarks and content belong to their respective owners.
</div>

<h3>Available Endpoints</h3>

<div class="endpoint">
    <strong>Lookup by ASIN</strong> <small>Not all books have ASINs</small><br>
    <code>/asin/{asin}</code><br>
    Example: <code>/asin/B09C4Y7T1Q</code><br>
    Append <code>/cover</code> to download cached cover:<br>
    <code>/asin/B09C4Y7T1Q/cover</code>
</div>

<div class="endpoint">
    <strong>Lookup by ISBN</strong><br>
    <code>/isbn/{isbn}</code><br>
    Example: <code>/isbn/9781427280583</code><br>
    Append <code>/cover</code> to download cached cover:<br>
    <code>/isbn/9781427280583/cover</code>
</div>

<div class="endpoint">
    <strong>Lookup by series name (fuzzy matching)</strong><br>
    <code>/series/{series}</code><br>
    Example: <code>/series/The%20Stormlight%20Archive</code>
</div>

<div class="endpoint">
    <strong>🔍 Search (title, rawtitle, author or series)</strong><br>
    <code>/search/{query}</code><br>
    Example: <code>/search/Oathbringer</code>
</div>

<div class="endpoint" style="border-left-color:#aa00ff;">
    <strong>📚 Audiobookshelf Metadata Provider</strong><br>
    <code>/audiobookshelf/search?query={isbn|asin|text}</code><br>
    <?php if (AUDIOBOOKSHELF_KEY !='abs'): ?>
    Requires <code>Authorization: YOUR_API_KEY</code> header.<br>
    <?php else: ?>
    Ignores authentication (open access).<br>
    <?php endif; ?>
    <small>Automatically detects ISBN / ASIN / text search.</small><br>
    Example (ISBN): <code>/audiobookshelf/search?query=9798896520030</code><br>
    Example (Title search): <code>/audiobookshelf/search?query=Stormlight</code>
</div>

<div class="endpoint" style="border-left-color: #cc0000;">
    <strong>🚨 Force JSON Refresh (requires key)</strong><br>
    <code>/refresh?key=YOUR_SECRET_KEY</code><br>
    Clears APCu + cache.json and re-downloads fresh JSON. Must be a PUT with the key in the url<br>
    <em>Do not expose this key publicly.</em>
</div>

<hr>

<p>JSON source:<br>
<code><?php echo JSON_URL; ?></code></p>


<footer style="
    margin-top: 25px;
    padding-top: 15px;
    font-size: 0.9rem;
    color: #555;
">
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
