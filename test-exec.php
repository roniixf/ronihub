<?php
require_once 'config.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== ENV ===\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "OS: " . PHP_OS . "\n";
echo "getcwd: " . getcwd() . "\n";
echo "PATH: " . (getenv('PATH') ?: '(empty)') . "\n";
echo "disable_functions: " . (ini_get('disable_functions') ?: '(none)') . "\n\n";

echo "=== EXEC ===\n";
echo "exec: " . (function_exists('exec') ? 'YES' : 'NO') . "\n";
echo "shell_exec: " . (function_exists('shell_exec') ? 'YES' : 'NO') . "\n\n";

echo "=== YT-DLP PATH ===\n";
echo "Detected: " . YTDLP_PATH . "\n";
echo "Exists: " . (file_exists(YTDLP_PATH) ? 'YES' : 'NO') . "\n";
echo "Executable: " . (is_executable(YTDLP_PATH) ? 'YES' : 'NO') . "\n\n";

echo "=== TEST VERSION ===\n";
$out = []; $ret = 0;
exec(escapeshellarg(YTDLP_PATH) . ' --version 2>&1', $out, $ret);
echo "Return: $ret\n";
echo "Output: " . implode("\n", $out) . "\n\n";

echo "=== TEST IG DUMP (5s) ===\n";
$out2 = []; $ret2 = 0;
exec(escapeshellarg(YTDLP_PATH) . ' --dump-json --no-warnings --socket-timeout 15 ' . escapeshellarg('https://www.instagram.com/reel/DdskcIgpbN4/') . ' 2>&1', $out2, $ret2);
echo "Return: $ret2\n";
echo "Output: " . substr(implode("\n", $out2), 0, 300) . "\n";
