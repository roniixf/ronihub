<?php
/**
 * Multi Downloader API
 * Endpoint: POST /api.php
 * Body: { "url": "https://..." }
 */

require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
$url = trim($input['url'] ?? '');

if (!$url) {
    jsonResponse(['success' => false, 'message' => 'URL tidak boleh kosong'], 400);
}

if (!isValidUrl($url)) {
    jsonResponse(['success' => false, 'message' => 'URL tidak valid'], 400);
}

$platform = detectPlatform($url);
if (!$platform) {
    jsonResponse([
        'success' => false,
        'message' => 'Platform tidak didukung. Coba: Instagram, TikTok, YouTube, Facebook, Twitter, dll.'
    ], 400);
}

// Cek yt-dlp binary
if (!file_exists(YTDLP_PATH)) {
    jsonResponse([
        'success' => false,
        'message' => 'yt-dlp binary tidak ditemukan di: ' . YTDLP_PATH
    ], 500);
}

if (!is_executable(YTDLP_PATH)) {
    jsonResponse([
        'success' => false,
        'message' => 'yt-dlp tidak executable. Set permission: chmod +x bin/yt-dlp'
    ], 500);
}

// Cek exec function
if (!function_exists('proc_open') && !function_exists('exec') && !function_exists('shell_exec')) {
    jsonResponse([
        'success' => false,
        'message' => 'Server tidak mengizinkan eksekusi binary (exec/proc_open disabled)'
    ], 500);
}

try {
    $data = runYtDlp($url);
    
    if (empty($data)) {
        throw new Exception('Tidak ada data yang ditemukan');
    }

    $info = $data[0];
    $medias = extractMedias($info);

    if (empty($medias)) {
        throw new Exception('Tidak ada media yang bisa didownload');
    }

    // Build response
    $response = [
        'success'    => true,
        'platform'   => $platform,
        'username'   => $info['uploader'] ?? $info['channel'] ?? $info['creator'] ?? 'unknown',
        'title'      => $info['title'] ?? '',
        'caption'    => $info['description'] ?? '',
        'thumbnail'  => $info['thumbnail'] ?? (($medias[0]['type'] ?? '') === 'image' ? $medias[0]['url'] : null),
        'duration'   => $info['duration'] ?? null,
        'duration_string' => $info['duration_string'] ?? null,
        'medias'     => $medias,
        'source_url' => $url,
    ];

    jsonResponse($response);

} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Gagal memproses: ' . $e->getMessage()
    ], 500);
}


// ==================== FUNCTIONS ====================

/**
 * Run yt-dlp dan return array of info
 */
function runYtDlp($url) {
    $args = [
        YTDLP_PATH,
        '--dump-json',
        '--no-warnings',
        '--no-playlist',
        '--flat-playlist',
        '--no-check-certificates',
        '--user-agent', USER_AGENT,
        '--referer', 'https://www.instagram.com/',
        '--socket-timeout', '15',
        '--retries', '3',
        $url
    ];

    $cmd = implode(' ', array_map('escapeshellarg', $args));
    
    $output = executeCommand($cmd);
    
    if (empty($output)) {
        throw new Exception('Tidak ada output dari yt-dlp');
    }

    // Parse JSON per line (bisa multi-line untuk playlist)
    $lines = array_filter(explode("\n", trim($output)));
    $result = [];
    
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if ($decoded) {
            $result[] = $decoded;
        }
    }

    if (empty($result)) {
        throw new Exception('Gagal parse JSON. Output: ' . substr($output, 0, 200));
    }

    return $result;
}

/**
 * Eksekusi command dengan proc_open (lebih aman & support timeout)
 */
function executeCommand($cmd) {
    if (function_exists('proc_open')) {
        return executeWithProcOpen($cmd);
    }
    
    // Fallback ke exec
    if (function_exists('exec')) {
        $output = [];
        $returnVar = 0;
        exec($cmd . ' 2>&1', $output, $returnVar);
        return implode("\n", $output);
    }
    
    // Fallback ke shell_exec
    if (function_exists('shell_exec')) {
        return shell_exec($cmd . ' 2>&1');
    }

    throw new Exception('Tidak ada method eksekusi command yang tersedia');
}

/**
 * Eksekusi dengan proc_open + timeout
 */
function executeWithProcOpen($cmd) {
    $descriptors = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];

    $process = proc_open($cmd, $descriptors, $pipes, null, null);
    
    if (!is_resource($process)) {
        throw new Exception('Gagal menjalankan proses');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $errorOutput = '';
    $startTime = time();

    while (true) {
        $status = proc_get_status($process);
        
        $output .= stream_get_contents($pipes[1]);
        $errorOutput .= stream_get_contents($pipes[2]);

        if (!$status['running']) break;
        
        if ((time() - $startTime) > EXEC_TIMEOUT) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new Exception('Timeout: proses memakan waktu lebih dari ' . EXEC_TIMEOUT . ' detik');
        }

        usleep(100000); // 100ms
    }

    $output .= stream_get_contents($pipes[1]);
    $errorOutput .= stream_get_contents($pipes[2]);
    
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    // Kalau ada error tapi output kosong, lempar error
    if (empty(trim($output)) && !empty(trim($errorOutput))) {
        throw new Exception(trim($errorOutput));
    }

    return $output;
}

/**
 * Extract medias dari info
 */
function extractMedias($info) {
    $medias = [];
    $seen = [];
    
    $addMedia = function($item) use (&$medias, &$seen) {
        if (empty($item['url']) || in_array($item['url'], $seen)) return;
        $seen[] = $item['url'];
        
        $isVideo = (!empty($item['vcodec']) && $item['vcodec'] !== 'none')
                || (($item['ext'] ?? '') === 'mp4')
                || (($item['ext'] ?? '') === 'webm');
        
        $medias[] = [
            'type'     => $isVideo ? 'video' : 'image',
            'url'      => $item['url'],
            'ext'      => $item['ext'] ?? ($isVideo ? 'mp4' : 'jpg'),
            'quality'  => !empty($item['height']) ? $item['height'] . 'p' : 'HD',
            'width'    => $item['width'] ?? null,
            'height'   => $item['height'] ?? null,
            'filesize' => $item['filesize'] ?? $item['filesize_approx'] ?? null,
            'duration' => $item['duration'] ?? null,
        ];
    };
    
    // Carousel / playlist
    if (!empty($info['entries']) && is_array($info['entries'])) {
        foreach ($info['entries'] as $entry) {
            $addMedia($entry);
        }
        return $medias;
    }
    
    // === PRIORITAS 1: format yang punya BOTH video + audio ===
    if (!empty($info['formats']) && is_array($info['formats'])) {
        // Filter format dengan video + audio lengkap
        $both = array_filter($info['formats'], function($f) {
            return !empty($f['url'])
                && !empty($f['vcodec']) && $f['vcodec'] !== 'none'
                && !empty($f['acodec']) && $f['acodec'] !== 'none';
        });
        
        if (!empty($both)) {
            usort($both, function($a, $b) {
                return ($b['height'] ?? 0) - ($a['height'] ?? 0);
            });
            // Ambil max 1080p
            $best = null;
            foreach ($both as $v) {
                if (($v['height'] ?? 0) <= 1080) { $best = $v; break; }
            }
            if (!$best) $best = $both[0];
            $addMedia($best);
            return $medias;
        }
        
        // === PRIORITAS 2: kalau gak ada, coba cari video-only HD ===
        // (nanti di-download via yt-dlp yang auto-merge pakai ffmpeg)
        $videos = array_filter($info['formats'], function($f) {
            return !empty($f['url']) && !empty($f['vcodec']) && $f['vcodec'] !== 'none';
        });
        
        if (!empty($videos)) {
            usort($videos, function($a, $b) {
                return ($b['height'] ?? 0) - ($a['height'] ?? 0);
            });
            $best = null;
            foreach ($videos as $v) {
                if (($v['height'] ?? 0) <= 1080) { $best = $v; break; }
            }
            if (!$best) $best = $videos[0];
            $addMedia($best);
            return $medias;
        }
    }
    
    // Fallback: single URL
    if (!empty($info['url'])) {
        $addMedia($info);
    }
    
    return $medias;
}