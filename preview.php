<?php
require_once 'config.php';

$source = $_GET['source'] ?? ''; // URL halaman IG/TikTok
$mode = $_GET['mode'] ?? 'stream';

if (empty($source) || !isValidUrl($source)) {
    http_response_code(400);
    exit('Source required');
}

// Cek ffmpeg
$ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null'));
$ytdlp = YTDLP_PATH;
if (!file_exists($ytdlp)) $ytdlp = 'yt-dlp';

// Buat file temporary unik per session
$session_id = md5($source);
$cache_dir = DOWNLOAD_DIR . '/preview_' . $session_id;

// Cek kalau udah ada cache
$cached_file = null;
if (is_dir($cache_dir)) {
    $files = glob($cache_dir . '/preview.*');
    foreach ($files as $f) {
        if (preg_match('/\.(mp4|webm|mkv)$/i', $f) && filesize($f) > 1000) {
            $cached_file = $f;
            break;
        }
    }
}

// Kalau belum ada cache, download & merge
if (!$cached_file) {
    @mkdir($cache_dir, 0755, true);

    $output_tpl = $cache_dir . '/preview.%(ext)s';

    // Prioritas: gabung video+audio jadi satu file MP4
    // -f "bv*+ba/b" = best video + best audio, atau best combined
    // --merge-output-format mp4 = output jadi mp4
    $cmd = escapeshellarg($ytdlp)
         . ' -f "bv*[height<=720]+ba/b[height<=720]/bv*+ba/b" '
         . '--merge-output-format mp4 '
         . '--no-warnings '
         . '--no-playlist '
         . '--socket-timeout 30 '
         . '--no-part '
         . '-o ' . escapeshellarg($output_tpl) . ' '
         . escapeshellarg($source) . ' 2>&1';

    exec($cmd, $out, $return);

    // Cari file
    $files = glob($cache_dir . '/preview.*');
    foreach ($files as $f) {
        if (preg_match('/\.(mp4|webm|mkv)$/i', $f) && filesize($f) > 1000) {
            $cached_file = $f;
            break;
        }
    }
}

if (!$cached_file || !file_exists($cached_file)) {
    http_response_code(500);
    exit('Gagal generate preview');
}

// Serve video dengan support Range (untuk seek/play)
$filesize = filesize($cached_file);
$ext = strtolower(pathinfo($cached_file, PATHINFO_EXTENSION));
$mime = ($ext === 'webm') ? 'video/webm' : 'video/mp4';

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=3600');
header('Access-Control-Allow-Origin: *');

$start = 0;
$end = $filesize - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        $start = intval($m[1]);
        if (!empty($m[2])) {
            $end = intval($m[2]);
        }
        header('HTTP/1.1 206 Partial Content');
    }
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);
header('Content-Range: bytes ' . $start . '-' . $end . '/' . $filesize);

$fp = fopen($cached_file, 'rb');
fseek($fp, $start);
$buffer = 8192;
$remaining = $length;

while ($remaining > 0 && !feof($fp)) {
    $chunk = fread($fp, min($buffer, $remaining));
    echo $chunk;
    flush();
    $remaining -= strlen($chunk);
}
fclose($fp);
