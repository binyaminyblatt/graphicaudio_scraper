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
   Very small router
---------------------------------------------------------*/
$request = trim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
$parts = explode("/", $request);

if ($parts[0] === "asin" && !empty($parts[1])) {
    $asin = clean($parts[1], "asin");
    $result = findByField($data, "asin", $asin);
    if (!$result) die("ASIN not found");

    if (isset($parts[2]) && $parts[2] === "cover") serveCover($result);

    header("Content-Type: application/json");
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

if ($parts[0] === "isbn" && !empty($parts[1])) {
    $isbn = clean($parts[1], "isbn");
    $result = findByField($data, "isbn", $isbn);
    if (!$result) die("ISBN not found");

    if (isset($parts[2]) && $parts[2] === "cover") serveCover($result);

    header("Content-Type: application/json");
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

if ($parts[0] === "series" && !empty($parts[1])) {
    $series = clean(urldecode($parts[1]), "series");
    $result = findSeries($data, $series);
    if (!$result) die("Series not found");

    header("Content-Type: application/json");
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

/* --------------------------------------------------------
   Default landing page
---------------------------------------------------------*/
?>
<!DOCTYPE html>
<html>
<head><title>GraphicAudio Lookup API</title></head>
<body>
<h2>GraphicAudio Lookup API</h2>
<p>Usage:</p>
<ul>
  <li>GET <code>/asin/{asin}</code> not all books have a ASIN</li>
  <li>GET <code>/isbn/{isbn}</code></li>
  <li>GET <code>/series/{series}</code></li>
  <li>Append <code>/cover</code> to retrieve cached cover</li>
</ul>
</body>
</html>
