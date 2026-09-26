<?php
define('APP_TOKEN', 'rg_secret_2026_9kx8m2');
/**
 * Konfigurasi Multi Downloader
 */

// Path ke yt-dlp binary
define('YTDLP_PATH', __DIR__ . '/bin/yt-dlp');
// Path ke folder downloads (untuk cache sementara)
define('DOWNLOAD_DIR', __DIR__ . '/downloads');

// Timeout untuk eksekusi yt-dlp (detik)
define('EXEC_TIMEOUT', 45);

// Max file size (untuk proxy)
define('MAX_FILESIZE', 500 * 1024 * 1024); // 500 MB

// Enable debug (set false di production)
define('DEBUG_MODE', false);

// User agent untuk request
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

// Auto-create downloads folder
if (!is_dir(DOWNLOAD_DIR)) {
    @mkdir(DOWNLOAD_DIR, 0755, true);
}

// Error reporting
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

/**
 * Detect platform dari URL
 */
function detectPlatform($url) {
    $patterns = [
        'instagram'   => '/(instagram\.com|instagr\.am)\/(p|reel|reels|tv|stories)\//i',
        'tiktok'      => '/(tiktok\.com|vt\.tiktok\.com|vm\.tiktok\.com)/i',
        'youtube'     => '/(youtube\.com|youtu\.be)/i',
        'facebook'    => '/(facebook\.com|fb\.watch|fb\.com)/i',
        'twitter'     => '/(twitter\.com|x\.com)/i',
        'threads'     => '/threads\.(net|com)/i',
        'pinterest'   => '/pinterest\.(com|co\.\w+)/i',
        'snapchat'    => '/snapchat\.com/i',
        'reddit'      => '/(reddit\.com|redd\.it)/i',
        'vimeo'       => '/vimeo\.com/i',
        'dailymotion' => '/(dailymotion\.com|dai\.ly)/i',
        'linkedin'    => '/linkedin\.com/i',
        'twitch'      => '/twitch\.tv/i',
        'soundcloud'  => '/soundcloud\.com/i',
    ];

    foreach ($patterns as $platform => $regex) {
        if (preg_match($regex, $url)) return $platform;
    }
    return null;
}

/**
 * Validate URL
 */
function isValidUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false 
        && preg_match('/^https?:\/\//i', $url);
}

/**
 * JSON response helper
 */
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Format bytes
 */
function formatBytes($bytes, $precision = 2) {
    if (!$bytes) return null;
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), $precision) . ' ' . $units[$i];
}