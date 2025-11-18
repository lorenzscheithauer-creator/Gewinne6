<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

const BASE_URL = 'https://www.12gewinn.de/';
const LOG_FILE = __DIR__ . '/logs/scraper.log';

if (!is_dir(dirname(LOG_FILE))) {
    mkdir(dirname(LOG_FILE), 0777, true);
}

function logMessage(string $message): void
{
    $entry = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    file_put_contents(LOG_FILE, $entry, FILE_APPEND);
}

function getDatabaseConnection(): PDO
{
    $host = 'localhost';
    $dbname = 'gewinnspiele';
    $username = 'root';
    $password = '';

    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $dbname);
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        logMessage('Database connection failed: ' . $e->getMessage());
        exit(1);
    }
}

function fetchContent(string $url, ?string &$effectiveUrl = null): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Gewinnspiele-Scraper/1.0)',
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ACCEPT_ENCODING => 'gzip,deflate',
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        logMessage('cURL error for ' . $url . ': ' . curl_error($ch));
        curl_close($ch);
        return null;
    }

    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
    curl_close($ch);
    return $response;
}

function resolveUrl(string $base, string $relative): string
{
    if (preg_match('~^https?://~i', $relative)) {
        return $relative;
    }

    $baseParts = parse_url($base);
    if (!$baseParts) {
        return $relative;
    }

    $scheme = $baseParts['scheme'] ?? 'https';
    $host = $baseParts['host'] ?? '';
    $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
    $path = $baseParts['path'] ?? '/';

    $path = preg_replace('#/[^/]*$#', '/', $path);
    if (str_starts_with($relative, '/')) {
        $path = '';
    }

    $resolved = $scheme . '://' . $host . $port . $path . ltrim($relative, '/');
    return $resolved;
}

function normalizeDate(?string $rawDate): ?string
{
    if ($rawDate === null) {
        return null;
    }

    $clean = trim(str_replace(['Einsendeschluss', 'Einsendeschluß'], '', $rawDate));
    $clean = preg_replace('/[^0-9\.\-\/]/', ' ', $clean);
    $clean = trim(preg_replace('/\s+/', ' ', $clean));

    $formats = ['d.m.Y', 'd.m.y', 'd-m-Y', 'd-m-y', 'd/m/Y', 'd/m/y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $clean);
        if ($date !== false) {
            return $date->format('Y-m-d');
        }
    }

    $timestamp = strtotime($clean);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }

    return null;
}

function saveToDatabase(PDO $pdo, string $link, ?string $description, string $status, ?string $endDate): void
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM gewinnspiele WHERE link_zur_webseite = :link');
        $stmt->execute([':link' => $link]);
        $exists = (int)$stmt->fetchColumn() > 0;
        if ($exists) {
            return;
        }

        $insert = $pdo->prepare('INSERT INTO gewinnspiele (link_zur_webseite, beschreibung, status, endet_am) VALUES (:link, :beschreibung, :status, :endet_am)');
        $insert->execute([
            ':link' => $link,
            ':beschreibung' => $description,
            ':status' => $status,
            ':endet_am' => $endDate,
        ]);
    } catch (PDOException $e) {
        logMessage('Database error: ' . $e->getMessage());
    }
}

function extractItemsFromPage(string $html, string $baseUrl, PDO $pdo): void
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML($html)) {
        logMessage('Failed to parse HTML for ' . $baseUrl);
        return;
    }
    $xpath = new DOMXPath($dom);
    $items = $xpath->query('//article//div[@id="top10"]//div[contains(@class, "Item")]');
    if (!$items) {
        return;
    }

    foreach ($items as $item) {
        $dateText = null;
        $span = $xpath->query('.//span[contains(@class, "HADetail") and contains(translate(normalize-space(text()), "ß", "ss"), "Einsendeschluss")]', $item)->item(0);
        if (!$span) {
            $span = $xpath->query('.//span[contains(@class, "HADetail") and contains(., "Einsendeschluß")]', $item)->item(0);
        }
        if ($span) {
            $node = $span->nextSibling;
            while ($node !== null) {
                if ($node->nodeType === XML_TEXT_NODE) {
                    $text = trim($node->nodeValue);
                    if ($text !== '') {
                        $dateText = $text;
                        break;
                    }
                }
                if ($node->nodeType === XML_ELEMENT_NODE && strtolower($node->nodeName) !== 'br') {
                    $text = trim($node->textContent);
                    if ($text !== '') {
                        $dateText = $text;
                        break;
                    }
                }
                $node = $node->nextSibling;
            }
        }

        $linkNode = $xpath->query('.//a[contains(@class, "Button-zum-GS")]', $item)->item(0);
        $link = $linkNode ? trim($linkNode->getAttribute('href')) : null;
        if ($link) {
            $link = resolveUrl($baseUrl, $link);
        }

        if ($link === null) {
            continue;
        }

        $normalizedDate = normalizeDate($dateText);
        saveToDatabase($pdo, $link, null, 'aktiv', $normalizedDate);
    }
}

function scrapeMenu2Items(string $url, PDO $pdo, array &$visited = []): void
{
    if (isset($visited[$url])) {
        return;
    }
    $visited[$url] = true;

    $effectiveUrl = $url;
    $html = fetchContent($url, $effectiveUrl);
    if ($html === null) {
        return;
    }

    extractItemsFromPage($html, $effectiveUrl, $pdo);

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML($html)) {
        logMessage('Failed to parse HTML for menu extraction: ' . $effectiveUrl);
        return;
    }

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//li[contains(@class, "Menu2")]//a[@href]');
    if (!$nodes) {
        return;
    }

    foreach ($nodes as $node) {
        $href = trim($node->getAttribute('href'));
        if ($href === '') {
            continue;
        }
        $nextUrl = resolveUrl($effectiveUrl, $href);
        scrapeMenu2Items($nextUrl, $pdo, $visited);
    }
}

$pdo = getDatabaseConnection();
scrapeMenu2Items(BASE_URL, $pdo);
echo 'Scraping completed.';
