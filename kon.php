<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== ENV CHECK ===\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "PHP_OS: " . PHP_OS . "\n";
echo "getcwd(): " . getcwd() . "\n";
echo "__DIR__: " . __DIR__ . "\n";
echo "PATH: " . (getenv('PATH') ?: '(empty)') . "\n";
echo "HOME: " . (getenv('HOME') ?: '(empty)') . "\n";
echo "disable_functions: " . (ini_get('disable_functions') ?: '(none)') . "\n\n";

echo "=== EXEC CHECK ===\n";
echo "exec exists: " . (function_exists('exec') ? 'YES' : 'NO') . "\n";
echo "shell_exec exists: " . (function_exists('shell_exec') ? 'YES' : 'NO') . "\n";
echo "proc_open exists: " . (function_exists('proc_open') ? 'YES' : 'NO') . "\n\n";

echo "=== FILE CHECK ===\n";
$wrapper = __DIR__ . '/bin/yt-dlp';
echo "Wrapper path: $wrapper\n";
echo "  exists: " . (file_exists($wrapper) ? 'YES' : 'NO') . "\n";
echo "  executable: " . (is_executable($wrapper) ? 'YES' : 'NO') . "\n";
echo "  readable: " . (is_readable($wrapper) ? 'YES' : 'NO') . "\n";

$ytdlp = '/data/data/com.termux/files/usr/bin/yt-dlp';
echo "yt-dlp global: $ytdlp\n";
echo "  exists: " . (file_exists($ytdlp) ? 'YES' : 'NO') . "\n";
echo "  executable: " . (is_executable($ytdlp) ? 'YES' : 'NO') . "\n\n";

echo "=== TEST 1: exec wrapper ===\n";
if (function_exists('exec')) {
    $out = []; $ret = 0;
    exec("$wrapper --version 2>&1", $out, $ret);
    echo "Return: $ret\n";
    echo "Output: " . implode("\n", $out) . "\n\n";
    
    echo "=== TEST 2: exec yt-dlp global (full path) ===\n";
    $out2 = []; $ret2 = 0;
    exec("$ytdlp --version 2>&1", $out2, $ret2);
    echo "Return: $ret2\n";
    echo "Output: " . implode("\n", $out2) . "\n\n";
    
    echo "=== TEST 3: exec via bash ===\n";
    $bash = '/data/data/com.termux/files/usr/bin/bash';
    $out3 = []; $ret3 = 0;
    exec("$bash -c '$ytdlp --version' 2>&1", $out3, $ret3);
    echo "Return: $ret3\n";
    echo "Output: " . implode("\n", $out3) . "\n\n";
    
    echo "=== TEST 4: yt-dlp dump-json (test link IG) ===\n";
    $out4 = []; $ret4 = 0;
    exec("$ytdlp --dump-json --no-warnings 'https://www.instagram.com/reel/DdskcIgpbN4/' 2>&1", $out4, $ret4);
    echo "Return: $ret4\n";
    $out4_str = implode("\n", $out4);
    echo "Output (first 500 chars):\n" . substr($out4_str, 0, 500) . "\n";
} else {
    echo "exec() not available!\n";
}
