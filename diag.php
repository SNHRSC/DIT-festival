<?php
// ตรวจสภาพเซิร์ฟเวอร์สำหรับ Festival — ลบไฟล์นี้ได้หลังใช้งาน
ini_set('display_errors', '1'); error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
echo "PHP version: " . PHP_VERSION . "\n";
echo "SAPI: " . php_sapi_name() . " / OS: " . PHP_OS . "\n";
foreach (array('json','mbstring','session','openssl','hash') as $x) echo "ext $x: " . (extension_loaded($x) ? 'OK' : 'MISSING') . "\n";
foreach (array('json_encode','mb_substr','password_hash','password_verify','hash_equals','random_bytes','http_response_code','flock') as $f) echo "fn $f: " . (function_exists($f) ? 'OK' : 'MISSING') . "\n";
$d = dirname(__FILE__) . '/data';
echo "data dir: " . (is_dir($d) ? 'exists' : 'MISSING') . ", writable: " . (is_writable($d) ? 'YES' : 'NO') . "\n";
$t = $d . '/_diag_test.txt';
$ok = @file_put_contents($t, 'x'); echo "write test: " . ($ok ? 'OK' : 'FAIL') . "\n"; if ($ok) @unlink($t);
echo "session.save_path: " . session_save_path() . " writable: " . (is_writable(preg_replace('/^\d+;/', '', session_save_path())) ? 'YES' : 'NO') . "\n";
echo "-- loading config.php --\n";
include dirname(__FILE__) . '/config.php';
echo "config OK, version " . (defined('APP_VERSION') ? APP_VERSION : '?') . "\n";
echo "done\n";
