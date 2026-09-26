<?php
require_once 'config.php';

$url = $_GET['url'] ?? '';
$filename = $_GET['filename'] ?? '';
$source_url = $_GET['source'] ?? ''; // URL halaman asli (IG/TikTok)
$mode = $_GET['mode'] ?? 'proxy';

if (empty($url) && empty($source_url)) {
    http_response_code(400);
    exit('URL required');
}

// =============================================
// MODE DOWNLOAD: pakai yt-dlp biar video+audio
// =============================================
if ($mode === 'download' && !empty($source_url)) {
    $ytdlp = function_exists('find_ytdlp_path') ? YTDLP_PATH : '/data/data/com.termux/files/usr/bin/yt-dlp';
    
    // Buat file temporary
    $tmp_dir = DOWNLOAD_DIR . '/tmp_' . uniqid();
    @mkdir($tmp_dir, 0755, true);
    
    // yt-dlp merge video+audio → mp4
    $output_tpl = $tmp_dir . '/video.%(ext)s';
    
    $cmd = escapeshellarg($ytdlp)
         . ' -f "best[ext=mp4]/best" '
         . '--merge-output-format mp4 '
         . '--no-warnings '
         . '--no-playlist '
         . '--socket-timeout 30 '
         . '-o ' . escapeshellarg($output_tpl) . ' '
         . escapeshellarg($source_url) . ' 2>&1';
    
    exec($cmd, $output, $return);
    
    if ($return !== 0) {
        error_log("[proxy] yt-dlp merge failed: " . implode("\n", $output));
    }
    
    // Cari file hasil
    $files = glob($tmp_dir . '/video.*');
    $video_file = null;
    foreach ($files as $f) {
        if (preg_match('/\.(mp4|webm|mkv)$/i', $f)) {
            $video_file = $f;
            break;
        }
    }
    
    if ($video_file && file_exists($video_file)) {
        // Set headers untuk download
        $dlName = $filename ?: basename($video_file);
        header('Content-Type: video/mp4');
        header('Content-Length: ' . filesize($video_file));
        header('Content-Disposition: attachment; filename="' . basename($dlName) . '"');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-cache');
        
        readfile($video_file);
        
        // Cleanup
        @unlink($video_file);
        @rmdir($tmp_dir);
        exit;
    }
    
    // Cleanup kalau gagal
    @rmdir($tmp_dir);
    http_response_code(500);
    exit('Gagal download & merge video');
}

// =============================================
// MODE PROXY: stream langsung dari CDN URL
// =============================================
if ($mode === 'direct') {
    header('Location: ' . $url);
    exit;
}

$host = parse_url($url, PHP_URL_HOST);
$scheme = parse_url($url, PHP_URL_SCHEME);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 600,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
    CURLOPT_HTTPHEADER => [
        'Referer: ' . $scheme . '://' . $host . '/',
        'Origin: ' . $scheme . '://' . $host,
        'Accept: video/webm,video/mp4,video/*;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Range: bytes=0-',
    ],
    CURLOPT_ENCODING => '',
    CURLOPT_HEADERFUNCTION => function($ch, $header) {
        $len = strlen($header);
        $header = trim($header);
        $lower = strtolower($header);
        $forward = ['content-type:', 'content-length:', 'content-range:', 'accept-ranges:'];
        foreach ($forward as $f) {
            if (strpos($lower, $f) === 0) {
                header($header);
                break;
            }
        }
        return $len;
    },
    CURLOPT_WRITEFUNCTION => function($ch, $data) {
        echo $data;
        flush();
        return strlen($data);
    },
]);

$dlName = $filename ?: 'download_' . time() . '.mp4';
header('Content-Disposition: attachment; filename="' . basename($dlName) . '"');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');

curl_exec($ch);
curl_close($ch);
