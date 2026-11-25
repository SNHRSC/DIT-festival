<?php
// Festival API — JSON store (data/*.php กันเปิดตรงผ่านเว็บ)
// v1.1 — รองรับ PHP 5.6+, ไม่ให้ warning ปนใน JSON, session แยกเฉพาะ admin
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();
// แสดง fatal error เป็น JSON (แทน HTTP 500 หน้าเปล่า)
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
    while (ob_get_level()) ob_end_clean();
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(array('ok' => false, 'error' => 'PHP error: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')'));
  }
});
// polyfill เผื่อเซิร์ฟเวอร์ไม่มี mbstring / ฟังก์ชันใหม่
if (!function_exists('mb_strlen')) { function mb_strlen($s) { return preg_match_all('/./us', (string)$s, $m); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $st, $len = null) { preg_match_all('/./us', (string)$s, $m); return implode('', $len === null ? array_slice($m[0], $st) : array_slice($m[0], $st, $len)); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s) { return strtolower($s); } }
if (!function_exists('mb_strpos')) { function mb_strpos($h, $n) { return strpos($h, $n); } }
if (!function_exists('hash_equals')) { function hash_equals($a, $b) { if (strlen($a) !== strlen($b)) return false; $r = 0; for ($i = 0; $i < strlen($a); $i++) $r |= ord($a[$i]) ^ ord($b[$i]); return $r === 0; } }
if (!function_exists('http_response_code')) { function http_response_code($c) { header('X-Status: ' . $c, true, $c); } }
if (!defined('PASSWORD_DEFAULT')) define('PASSWORD_DEFAULT', 1);
if (!function_exists('password_hash')) {
  function password_hash($p, $algo) { $salt = substr(strtr(base64_encode(pack('H*', rnd_hex(16))), '+', '.'), 0, 22); return crypt($p, '$2y$10$' . $salt); }
  function password_verify($p, $h) { return is_string($h) && strlen($h) > 20 && hash_equals($h, crypt($p, $h)); }
}
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function g($a, $k, $d = '') { return (is_array($a) && isset($a[$k])) ? $a[$k] : $d; }
function rnd_hex($n) {
  if (function_exists('random_bytes')) return bin2hex(random_bytes($n));
  if (function_exists('openssl_random_pseudo_bytes')) return bin2hex(openssl_random_pseudo_bytes($n));
  $s = ''; for ($i = 0; $i < $n; $i++) $s .= chr(mt_rand(0, 255)); return bin2hex($s);
}
function start_session() {
  if (session_id() !== '') return;
  session_name('FESTADMIN');
  $p = session_save_path();
  if ($p === '' || !@is_writable(preg_replace('/^\d+;/', '', $p))) {
    $dir = DATA_DIR . '/sess';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
  }
  @session_start();
}

define('PAGE_VER', '2.2'); // ต้องตรงกับ PAGE_VER ใน loykrathong/index.html
define('GUARD', "<?php exit; ?>\n");

function out($a, $code = 200) { if (ob_get_length()) ob_clean(); http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function fail($msg, $code = 400) { out(['ok' => false, 'error' => $msg], $code); }

function store_path($name) { return DATA_DIR . '/' . preg_replace('/[^a-z0-9\-_]/i', '', $name) . '.php'; }
function load_raw($path, $default) {
  if (!is_file($path)) return $default;
  $s = file_get_contents($path);
  if (strpos($s, GUARD) === 0) $s = substr($s, strlen(GUARD));
  $d = json_decode($s, true);
  return is_array($d) ? $d : $default;
}
/** อ่าน-แก้-เขียน แบบล็อกไฟล์ */
function with_lock($path, $default, callable $fn) {
  if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
  $fp = @fopen($path, 'c+');
  if (!$fp) fail('เขียนข้อมูลไม่ได้ (สิทธิ์โฟลเดอร์ data)', 500);
  flock($fp, LOCK_EX);
  $s = stream_get_contents($fp);
  if (strpos($s, GUARD) === 0) $s = substr($s, strlen(GUARD));
  $d = json_decode($s, true); if (!is_array($d)) $d = $default;
  $res = $fn($d);
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, GUARD . json_encode($d, JSON_UNESCAPED_UNICODE));
  fflush($fp); flock($fp, LOCK_UN); fclose($fp);
  return $res;
}
function clean($s, $max) {
  $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
  $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
  return mb_substr($s, 0, $max);
}
function fest() {
  global $FESTIVALS;
  $f = g($_GET, 'f', g($_POST, 'f', ''));
  if (!isset($FESTIVALS[$f])) fail('ไม่พบเทศกาล', 404);
  return $f;
}
function public_item($it) {
  return ['id'=>$it['id'],'name'=>$it['name'],'dept'=>$it['dept'],'kind'=>$it['kind'],'text'=>$it['text'],
          'base'=>$it['base'],'flower'=>$it['flower'],'t'=>$it['t'],'bless'=>g($it,'bless',0),
          'photo'=>g($it,'empid','') !== '', 'rc'=>count(pub_replies($it)), 'design'=>g($it,'design',null)];  // ไม่เปิดเผยรหัสพนักงาน
}
/** แบบกระทงจากหน้าจัดกระทงทีละขั้น (isometric) */
function clean_design($d) {
  if (!is_array($d)) return null;
  $pick = function ($v, $ok, $def) { return in_array($v, $ok, true) ? $v : $def; };
  return array(
    'b'  => $pick(g($d,'b',''),  array('banana','bread','coconut','lotus'), 'banana'),
    'ps' => $pick(g($d,'ps',''), array('kulab','phanom','leb'), 'kulab'),
    'pl' => max(1, min(3, (int)g($d,'pl',2))),
    'fc' => $pick(g($d,'fc',''), array('pink','white','yellow','purple','orange'), 'pink'),
    'dc' => $pick(g($d,'dc',''), array('rak','bannai','none'), 'none'),
    'cc' => $pick(g($d,'cc',''), array('white','yellow','pink'), 'white'),
  );
}
function pub_replies($it) {
  $o = array();
  foreach (g($it,'replies',array()) as $r) if (empty($r['hidden']))
    $o[] = array('id'=>$r['id'], 'name'=>g($r,'name',''), 'dept'=>g($r,'dept',''), 'text'=>$r['text'], 't'=>$r['t'], 'photo'=>g($r,'empid','') !== '');
  return $o;
}
/** คำตอบล่าสุด (สำหรับแจ้งเตือน real-time) */
function recent_replies($d, $since) {
  $o = array();
  foreach ($d['items'] as $it) { if (!empty($it['hidden'])) continue;
    foreach (g($it,'replies',array()) as $r) if (empty($r['hidden']) && $r['t'] >= $since)
      $o[] = array('k'=>$it['id'], 'id'=>$r['id'], 'name'=>g($r,'name',''), 'text'=>$r['text'], 't'=>$r['t'], 'photo'=>g($r,'empid','') !== ''); }
  return $o;
}
function file_rev($p) { clearstatcache(true, $p); return is_file($p) ? filemtime($p) . '-' . filesize($p) : '0'; }
/* ===== น้องเก็บเหรียญ (sync ทุกเครื่อง) =====
   เซิร์ฟเวอร์เป็นคนตัดสินว่าน้องมาเมื่อไร และเก็บกระทงใบไหน → ทุกเครื่องเห็นเหมือนกัน
   ข้อมูลใน data/<fest>_kid.php: next_at, ev{id,at,targets}, col[] (กระทงที่ถูกเก็บ — คำอธิษฐานยังอยู่ในรายการ) */
if (!defined('KID_GAP_MIN')) define('KID_GAP_MIN', 40);
if (!defined('KID_GAP_MAX')) define('KID_GAP_MAX', 75);
if (!defined('KID_KEEP'))    define('KID_KEEP', 5);     // เหลือกระทงในแม่น้ำอย่างน้อยกี่ใบ
if (!defined('KID_FRESH'))   define('KID_FRESH', 120);  // ไม่เก็บกระทงที่เพิ่งลอยไม่ถึงกี่วินาที
/* ===== กำหนดวัน + เปิด/ปิดกิจกรรม ===== */
function fest_settings($f) {
  $all = load_raw(store_path('_settings'), array());
  $s = isset($all[$f]) && is_array($all[$f]) ? $all[$f] : array();
  return array(
    'mode'    => in_array(g($s,'mode',''), array('auto','open','closed'), true) ? $s['mode'] : 'open',
    'start'   => (int)g($s,'start',0),
    'end'     => (int)g($s,'end',0),
    'endmsg'  => (string)g($s,'endmsg','') !== '' ? (string)$s['endmsg'] : 'ขอบคุณชาว DIT ทุกคนที่ร่วมลอยกระทงและส่งคำอวยพรถึงกัน ขอให้ทุกคำอธิษฐานเป็นจริง แล้วพบกันใหม่ในกิจกรรมเทศกาลครั้งหน้านะ 🙏',
    'archive' => g($s,'archive',true) ? true : false,
  );
}
/** สถานะ: open | soon | ended */
function fest_status($f) {
  global $FESTIVALS;
  $s = fest_settings($f); $now = time();
  if (empty($FESTIVALS[$f]['open']) || $s['mode'] === 'closed') return 'ended';
  if ($s['mode'] === 'open') return 'open';
  if ($s['start'] && $now < $s['start']) return 'soon';
  if ($s['end'] && $now > $s['end']) return 'ended';
  return 'open';
}
function status_payload($f) {
  $s = fest_settings($f); $st = fest_status($f);
  return array('status'=>$st, 'open'=>$st === 'open', 'start'=>$s['start'], 'end'=>$s['end'], 'endmsg'=>$s['endmsg'], 'archive'=>$s['archive'], 'mode'=>$s['mode']);
}
function need_open($f) {
  $st = fest_status($f);
  if ($st === 'ended') fail('กิจกรรมจบแล้ว ขอบคุณที่ร่วมกิจกรรม 🙏', 403);
  if ($st === 'soon') fail('กิจกรรมยังไม่เริ่ม รออีกนิดนะ', 403);
}
function kid_tick($f) {
  $kp = store_path($f . '_kid');
  $k = load_raw($kp, array('next_at'=>0, 'ev'=>null, 'col'=>array()));
  if (time() < (int)g($k,'next_at',0) || fest_status($f) !== 'open') return $k;
  return with_lock($kp, array('next_at'=>0, 'ev'=>null, 'col'=>array()), function (&$k) use ($f) {
    $now = time();
    if ($now < (int)g($k,'next_at',0)) return $k;
    if (empty($k['next_at'])) { $k['next_at'] = $now + mt_rand(20, 40); return $k; }
    $d = load_raw(store_path($f), array('items'=>array()));
    $col = array_flip(g($k,'col',array()));
    $left = array(); $cand = array();
    foreach ($d['items'] as $it) {
      if (!empty($it['hidden']) || isset($col[$it['id']])) continue;
      $left[] = $it['id'];
      if ($it['t'] < $now - KID_FRESH) $cand[] = $it['id'];
    }
    $n = min(mt_rand(2, 4), count($cand), count($left) - KID_KEEP);
    if ($n >= 1) {
      shuffle($cand); $tg = array_slice($cand, 0, $n);
      $k['ev'] = array('id'=>rnd_hex(4), 'at'=>$now + 3, 'targets'=>$tg, 'kid'=>mt_rand(0, 2));
      $k['col'] = array_merge(g($k,'col',array()), $tg);
    }
    $k['next_at'] = $now + mt_rand(KID_GAP_MIN, KID_GAP_MAX);
    return $k;
  });
}
/* ===== เก็บเหรียญในกระทง (ผู้เล่น) ===== */
if (!defined('GRAB_FRESH')) define('GRAB_FRESH', 60);   // กระทงต้องลอยมาแล้วกี่วินาทีจึงเก็บได้
if (!defined('GRAB_GAP'))   define('GRAB_GAP', 2);      // เก็บได้ 1 ใบต่อกี่วินาที ต่อคน
function coin_value($id) { $v = array(1, 1, 1, 2, 2, 5, 5, 10); return $v[abs(crc32('coin|' . $id)) % count($v)]; }
function pkey($empid) { return substr(sha1('fest-photo|' . $empid), 0, 12); }
function coin_board($f, $me) {
  $c = load_raw(store_path($f . '_coins'), array());
  $rows = array();
  foreach ($c as $e => $r) $rows[] = array('name'=>g($r,'name',''), 'dept'=>g($r,'dept',''), 'total'=>(int)g($r,'total',0), 'n'=>(int)g($r,'n',0), 'p'=>pkey($e), 'me'=>($me !== '' && $me === (string)$e));
  usort($rows, function ($a, $b) { return $b['total'] - $a['total'] ?: $a['n'] - $b['n']; });
  $mine = null; foreach ($rows as $i => $r) if ($r['me']) { $mine = $r; $mine['rank'] = $i + 1; }
  return array('top'=>array_slice($rows, 0, 10), 'me'=>$mine, 'players'=>count($rows));
}
function kid_payload($k, $krev) {
  $ev = g($k,'ev',null);
  $rv = count(g($k,'col',array())) . '-' . ($ev ? $ev['id'] : '0');
  $o = array('krev'=>$rv);
  if ($ev && time() - $ev['at'] < 60) $o['kid'] = $ev;
  if ($krev !== $rv) $o['col'] = g($k,'col',array());
  return $o;
}
function valid_empid($e) { return (bool)preg_match('/^[0-9A-Za-z]{3,12}$/', $e); }
/** ดึงรูปพนักงานผ่านเซิร์ฟเวอร์ (หน้าเว็บเป็น https ส่วนเครื่องรูปเป็น http) + cache 7 วัน */
function photo_bytes($empid) {
  $tpl = defined('PHOTO_URL') ? PHOTO_URL : 'http://192.168.211.8/picture/%s.jpg';
  $dir = DATA_DIR . '/photo';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $c = $dir . '/' . $empid . '.jpg'; $miss = $dir . '/' . $empid . '.miss';
  if (is_file($c) && time() - filemtime($c) < 7 * 86400) return file_get_contents($c);
  if (is_file($miss) && time() - filemtime($miss) < 3600) return null;
  $url = sprintf($tpl, rawurlencode($empid)); $b = false;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => true));
    $b = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code != 200) $b = false;
  } else {
    $b = @file_get_contents($url, false, stream_context_create(array('http' => array('timeout' => 6))));
  }
  $isImg = is_string($b) && strlen($b) > 100 && (substr($b, 0, 2) === "\xFF\xD8" || substr($b, 0, 4) === "\x89PNG");
  if (!$isImg) { @touch($miss); return null; }
  @file_put_contents($c, $b); @unlink($miss);
  return $b;
}
function is_admin() { start_session(); return !empty($_SESSION['fest_admin']); }
function need_admin() {
  if (!is_admin()) fail('กรุณาเข้าสู่ระบบผู้ดูแล', 401);
  $tok = g($_SERVER, 'HTTP_X_CSRF', '');
  if (!hash_equals((string)g($_SESSION, 'csrf', ''), (string)$tok)) fail('CSRF ไม่ถูกต้อง', 403);
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = g($_GET, 'action', '');

switch ($action) {

case 'list': {
  $f = fest();
  $d = load_raw(store_path($f), ['items' => []]);
  $items = array_values(array_filter($d['items'], function ($i) { return empty($i['hidden']); }));
  $sp = status_payload($f);
  if ($sp['status'] !== 'open' && !$sp['archive']) $items = array(); // ปิดแล้ว + ไม่ให้ดูย้อนหลัง
  out(['ok'=>true, 'count'=>count($items), 'total'=>count($d['items']),
       'pv'=>PAGE_VER, 'rev'=>file_rev(store_path($f)), 'items'=>array_map('public_item', $items), 'now'=>time()] + $sp + kid_payload(kid_tick($f), ''));
}

case 'since': { // real-time: ส่งเฉพาะกระทงใหม่ตั้งแต่ลำดับ from (เบา: ถ้าไฟล์ไม่เปลี่ยนตอบทันที)
  $f = fest(); $path = store_path($f);
  $rev = file_rev($path);
  $kp = kid_payload(kid_tick($f), (string)g($_GET,'krev',''));
  $sp = status_payload($f);
  if ((string)g($_GET,'rev','') === $rev) out(['ok'=>true, 'same'=>1, 'rev'=>$rev, 'now'=>time()] + $sp + $kp);
  $from = max(0, (int)g($_GET,'from',0));
  $d = load_raw($path, ['items'=>[]]);
  $new = [];
  foreach (array_slice($d['items'], $from) as $it) if (empty($it['hidden'])) $new[] = public_item($it);
  $vis = 0; foreach ($d['items'] as $it) if (empty($it['hidden'])) $vis++;
  $rs = max(time() - 30, (int)g($_GET,'rt',0));
  if ($sp['status'] !== 'open' && !$sp['archive']) $new = array();
  out(['ok'=>true, 'rev'=>$rev, 'total'=>count($d['items']), 'count'=>$vis, 'items'=>$new, 'now'=>time(),
       'replies'=>recent_replies($d, $rs)] + $sp + $kp);
}

case 'add': {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);
  $_GET['f'] = g($in,'f',''); $f = fest();
  need_open($f);
  $text = clean(g($in,'text',''), $FESTIVALS[$f]['max_len']);
  if (mb_strlen($text) < 2) fail('กรุณาเขียนคำอธิษฐาน/คำอวยพร');
  $name = clean(g($in,'name',''), 30);
  $dept = clean(g($in,'dept',''), 40);
  $empid = preg_replace('/[^0-9A-Za-z]/', '', (string)g($in,'empid',''));
  if ($empid !== '' && !valid_empid($empid)) fail('รหัสพนักงานไม่ถูกต้อง');
  $low = mb_strtolower($text . ' ' . $name . ' ' . $dept);
  foreach ($BAD_WORDS as $w) if ($w !== '' && mb_strpos($low, mb_strtolower($w)) !== false) fail('กรุณาใช้ถ้อยคำสุภาพนะคะ 🙏');
  $kind  = in_array(g($in,'kind',''), ['wish','bless'], true) ? $in['kind'] : 'wish';
  $base  = in_array(g($in,'base',''), ['leaf','bread','lotus','coconut'], true) ? $in['base'] : 'leaf';
  $flower= in_array(g($in,'flower',''), ['pink','white','yellow','purple','orange'], true) ? $in['flower'] : 'pink';

  // rate limit ต่อ IP (เก็บเป็น hash ไม่เก็บ IP จริง)
  $ipk = substr(hash('sha256', g($_SERVER,'REMOTE_ADDR','') . '|fest'), 0, 16);
  $wait = with_lock(store_path('_rate'), [], function (&$r) use ($ipk) {
    $now = time();
    foreach ($r as $k => $t) if ($now - $t > 3600) unset($r[$k]);
    if (isset($r[$ipk]) && $now - $r[$ipk] < RATE_LIMIT_SEC) return RATE_LIMIT_SEC - ($now - $r[$ipk]);
    $r[$ipk] = $now; return 0;
  });
  if ($wait > 0) fail("ลอยใบต่อไปได้ในอีก {$wait} วินาที", 429);

  $item = ['id'=>rnd_hex(6), 'name'=>$name, 'dept'=>$dept, 'kind'=>$kind, 'text'=>$text,
           'base'=>$base, 'flower'=>$flower, 'empid'=>$empid, 'design'=>clean_design(g($in,'design',null)), 't'=>time(), 'bless'=>0, 'hidden'=>false];
  $ok = with_lock(store_path($f), ['items'=>[]], function (&$d) use ($item) {
    if (count($d['items']) >= MAX_ITEMS) return false;
    $d['items'][] = $item; return true;
  });
  if (!$ok) fail('แม่น้ำเต็มแล้ว 😅 แจ้ง HR');
  out(['ok'=>true, 'item'=>public_item($item)]);
}

case 'replies': { // คำตอบของกระทง 1 ใบ
  $f = fest(); $id = (string)g($_GET,'id','');
  $d = load_raw(store_path($f), array('items'=>array()));
  foreach ($d['items'] as $it) if ($it['id'] === $id && empty($it['hidden'])) out(array('ok'=>true, 'replies'=>pub_replies($it), 'bless'=>g($it,'bless',0)));
  fail('ไม่พบ', 404);
}

case 'reply': { // ตอบ/คอมเมนต์คำอวยพร
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);
  $_GET['f'] = g($in,'f',''); $f = fest();
  need_open($f);
  $id = (string)g($in,'id','');
  $text = clean(g($in,'text',''), 120);
  if (mb_strlen($text) < 1) fail('กรุณาพิมพ์ข้อความ');
  $name = clean(g($in,'name',''), 30); $dept = clean(g($in,'dept',''), 40);
  $empid = preg_replace('/[^0-9A-Za-z]/', '', (string)g($in,'empid',''));
  if ($empid !== '' && !valid_empid($empid)) $empid = '';
  $low = mb_strtolower($text . ' ' . $name . ' ' . $dept);
  foreach ($BAD_WORDS as $w) if ($w !== '' && mb_strpos($low, mb_strtolower($w)) !== false) fail('กรุณาใช้ถ้อยคำสุภาพนะคะ 🙏');
  $ipk = 'r|' . substr(hash('sha256', g($_SERVER,'REMOTE_ADDR','') . '|fest'), 0, 16);
  $wait = with_lock(store_path('_rate'), [], function (&$r) use ($ipk) {
    $now = time(); if (isset($r[$ipk]) && $now - $r[$ipk] < 2) return 2 - ($now - $r[$ipk]);
    $r[$ipk] = $now; return 0; });
  if ($wait > 0) fail("ส่งคำตอบถัดไปได้ในอีก {$wait} วินาที", 429);
  $rep = array('id'=>rnd_hex(5), 'name'=>$name, 'dept'=>$dept, 'empid'=>$empid, 'text'=>$text, 't'=>time(), 'hidden'=>false);
  $res = with_lock(store_path($f), array('items'=>array()), function (&$d) use ($id, $rep) {
    foreach ($d['items'] as &$it) if ($it['id'] === $id && empty($it['hidden'])) {
      if (!isset($it['replies'])) $it['replies'] = array();
      if (count($it['replies']) >= 200) return 'full';
      $it['replies'][] = $rep; return pub_replies($it);
    }
    return null;
  });
  if ($res === null) fail('ไม่พบกระทงใบนี้', 404);
  if ($res === 'full') fail('ใบนี้มีคำตอบเต็มแล้ว');
  out(array('ok'=>true, 'replies'=>$res));
}

case 'hide_reply': {
  need_admin();
  $_GET['f'] = g($in,'f',''); $f = fest();
  $id = (string)g($in,'id',''); $rid = (string)g($in,'rid',''); $hide = !empty($in['hidden']);
  $ok = with_lock(store_path($f), array('items'=>array()), function (&$d) use ($id, $rid, $hide) {
    foreach ($d['items'] as &$it) if ($it['id'] === $id && isset($it['replies'])) foreach ($it['replies'] as &$r) if ($r['id'] === $rid) { $r['hidden'] = $hide; return true; }
    return false;
  });
  $ok ? out(array('ok'=>true)) : fail('ไม่พบ', 404);
}

case 'bless': { // ร่วมอวยพร (+1)
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);
  $_GET['f'] = g($in,'f',''); $f = fest();
  need_open($f);
  $id = (string)(g($in,'id',''));
  $n = with_lock(store_path($f), ['items'=>[]], function (&$d) use ($id) {
    foreach ($d['items'] as &$it) if ($it['id'] === $id) { $it['bless'] = g($it,'bless',0) + 1; return $it['bless']; }
    return null;
  });
  if ($n === null) fail('ไม่พบกระทง', 404);
  out(['ok'=>true, 'bless'=>$n]);
}

// ===== admin =====
case 'photo': { // รูปพนักงาน: ?k=<id กระทง> หรือ ?e=<รหัสพนักงาน> (พรีวิว)
  $empid = '';
  if (g($_GET,'k','') !== '') {
    $f = fest(); $k = (string)$_GET['k'];
    $d = load_raw(store_path($f), array('items'=>array()));
    foreach ($d['items'] as $it) if ($it['id'] === $k && empty($it['hidden'])) { $empid = (string)g($it,'empid',''); break; }
  } elseif (g($_GET,'r','') !== '') { // รูปผู้ตอบ: ?r=<reply id>&kk=<id กระทง>
    $f = fest(); $rid = (string)$_GET['r']; $kk = (string)g($_GET,'kk','');
    $d = load_raw(store_path($f), array('items'=>array()));
    foreach ($d['items'] as $it) if ($it['id'] === $kk && empty($it['hidden']))
      foreach (g($it,'replies',array()) as $r) if ($r['id'] === $rid && empty($r['hidden'])) { $empid = (string)g($r,'empid',''); break 2; }
  } elseif (g($_GET,'p','') !== '') { // รูปในตารางอันดับ (ไม่เปิดเผยรหัส)
    $f = fest(); $pk = (string)$_GET['p'];
    foreach (load_raw(store_path($f . '_coins'), array()) as $e => $c) if (pkey($e) === $pk) { $empid = (string)$e; break; }
  } else {
    $empid = preg_replace('/[^0-9A-Za-z]/', '', (string)g($_GET,'e',''));
  }
  if ($empid === '' || !valid_empid($empid)) { http_response_code(404); exit; }
  $b = photo_bytes($empid);
  while (ob_get_level()) ob_end_clean();
  if ($b === null) { http_response_code(404); header('Content-Type: text/plain'); exit; }
  header('Content-Type: ' . (substr($b, 0, 4) === "\x89PNG" ? 'image/png' : 'image/jpeg'), true);
  header('Cache-Control: private, max-age=86400', true);
  echo $b; exit;
}

case 'grab': { // ผู้เล่นเก็บเหรียญจากกระทง
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);
  $_GET['f'] = g($in,'f',''); $f = fest();
  need_open($f);
  $empid = preg_replace('/[^0-9A-Za-z]/', '', (string)g($in,'empid',''));
  if (!valid_empid($empid)) fail('กรุณาใส่รหัสพนักงาน');
  $id = (string)g($in,'id','');
  $name = clean(g($in,'name',''), 30); $dept = clean(g($in,'dept',''), 40);
  $d = load_raw(store_path($f), array('items'=>array()));
  $item = null; $left = 0;
  $kk = load_raw(store_path($f . '_kid'), array('col'=>array()));
  $colset = array_flip(g($kk,'col',array()));
  foreach ($d['items'] as $it) {
    if (!empty($it['hidden']) || isset($colset[$it['id']])) continue;
    $left++; if ($it['id'] === $id) $item = $it;
  }
  if (!$item) fail('มีคนเก็บกระทงใบนี้ไปแล้ว 😅', 409);
  if ((string)g($item,'empid','') === $empid) fail('กระทงของตัวเองเก็บไม่ได้นะ 😄', 409);
  if ($item['t'] > time() - GRAB_FRESH) fail('กระทงเพิ่งลอย รอให้ลอยไปสักพักก่อนนะ', 409);
  if ($left <= KID_KEEP) fail('เหลือกระทงในแม่น้ำน้อยแล้ว ปล่อยให้ลอยต่อนะ 🌿', 409);
  // จองกระทง (atomic) ใน col
  $ok = with_lock(store_path($f . '_kid'), array('next_at'=>0, 'ev'=>null, 'col'=>array()), function (&$k) use ($id) {
    $col = g($k,'col',array()); if (in_array($id, $col, true)) return false;
    $col[] = $id; $k['col'] = $col; return true;
  });
  if (!$ok) fail('มีคนเก็บกระทงใบนี้ไปแล้ว 😅', 409);
  $val = coin_value($id);
  $res = with_lock(store_path($f . '_coins'), array(), function (&$c) use ($empid, $val, $name, $dept) {
    $r = isset($c[$empid]) ? $c[$empid] : array('total'=>0, 'n'=>0, 'name'=>'', 'dept'=>'', 't'=>0);
    if (time() - (int)g($r,'t',0) < GRAB_GAP) return null;
    $r['total'] = (int)$r['total'] + $val; $r['n'] = (int)$r['n'] + 1; $r['t'] = time();
    if ($name !== '') $r['name'] = $name; if ($dept !== '') $r['dept'] = $dept;
    $c[$empid] = $r; return $r;
  });
  if ($res === null) { // เร็วเกิน → คืนกระทงกลับแม่น้ำ
    with_lock(store_path($f . '_kid'), array('col'=>array()), function (&$k) use ($id) { $k['col'] = array_values(array_diff(g($k,'col',array()), array($id))); });
    fail('ใจเย็น ๆ เก็บทีละใบนะ', 429);
  }
  out(array('ok'=>true, 'value'=>$val, 'total'=>$res['total'], 'n'=>$res['n'], 'by'=>$id));
}

case 'coins': { // ยอดสะสม + อันดับ
  $f = fest();
  $me = preg_replace('/[^0-9A-Za-z]/', '', (string)g($_GET,'e',''));
  out(array('ok'=>true) + coin_board($f, $me));
}

case 'export_coins': {
  if (!is_admin()) { http_response_code(401); exit('unauthorized'); }
  $f = fest(); $c = load_raw(store_path($f . '_coins'), array());
  uasort($c, function ($a, $b) { return (int)$b['total'] - (int)$a['total']; });
  while (ob_get_level()) ob_end_clean();
  header('Content-Type: text/csv; charset=utf-8', true);
  header('Content-Disposition: attachment; filename="' . $f . '_coins_' . date('Ymd_Hi') . '.csv"');
  echo "\xEF\xBB\xBF"; $o = fopen('php://output', 'w');
  fputcsv($o, array('อันดับ','รหัสพนักงาน','ชื่อ','แผนก','ยอดสะสม (บาท)','จำนวนกระทง','เก็บล่าสุด'));
  $i = 0; foreach ($c as $e => $r) fputcsv($o, array(++$i, $e, g($r,'name',''), g($r,'dept',''), (int)g($r,'total',0), (int)g($r,'n',0), g($r,'t',0) ? date('Y-m-d H:i', $r['t']) : ''));
  exit;
}

case 'settings_save': { // admin: กำหนดวัน + เปิด/ปิด
  need_admin();
  $_GET['f'] = g($in,'f',''); $f = fest();
  $mode = in_array(g($in,'mode',''), array('auto','open','closed'), true) ? $in['mode'] : 'open';
  $ts = function ($v) { $v = trim((string)$v); if ($v === '') return 0; $t = strtotime($v); return $t ? $t : 0; };
  // ใช้ timestamp จากเบราว์เซอร์ก่อน (ตรงเขตเวลาผู้ตั้ง) ถ้าไม่มีค่อยแปลงจากข้อความ (Asia/Bangkok)
  $start = (int)g($in,'start_ts',0) ?: $ts(g($in,'start',''));
  $end   = (int)g($in,'end_ts',0)   ?: $ts(g($in,'end',''));
  if ($mode === 'auto' && (!$start || !$end)) fail('โหมดอัตโนมัติต้องกำหนดวันเริ่มและวันจบ');
  if ($start && $end && $end <= $start) fail('วันจบต้องอยู่หลังวันเริ่ม');
  $msg = mb_substr(trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', (string)g($in,'endmsg',''))), 0, 400);
  $arc = !empty($in['archive']);
  with_lock(store_path('_settings'), array(), function (&$a) use ($f, $mode, $start, $end, $msg, $arc) {
    $a[$f] = array('mode'=>$mode, 'start'=>$start, 'end'=>$end, 'endmsg'=>$msg, 'archive'=>$arc, 'savedAt'=>time()); });
  out(array('ok'=>true) + status_payload($f));
}

case 'status': { $f = fest(); out(array('ok'=>true, 'now'=>time()) + status_payload($f)); }

case 'ping': // ตรวจระบบ
  $w = is_dir(DATA_DIR) && is_writable(DATA_DIR);
  start_session();
  out(['ok'=>true, 'php'=>PHP_VERSION, 'data_writable'=>$w, 'session'=>session_id() !== '', 'version'=>APP_VERSION]);

case 'me':
  out(['ok'=>true, 'admin'=>is_admin(), 'data_writable'=>is_dir(DATA_DIR) && is_writable(DATA_DIR), 'csrf'=>is_admin() ? $_SESSION['csrf'] : null,
       'setup'=>!is_file(store_path('_admin'))]);

case 'login': {
  $pw = (string)(g($in,'password',''));
  $path = store_path('_admin');
  if (!is_file($path)) { // ตั้งรหัสครั้งแรก
    if (mb_strlen($pw) < 8) fail('รหัสผ่านอย่างน้อย 8 ตัวอักษร');
    with_lock($path, [], function (&$a) use ($pw) { $a = ['hash'=>password_hash($pw, PASSWORD_DEFAULT), 'fails'=>0, 'lock'=>0]; });
  } else {
    $res = with_lock($path, [], function (&$a) use ($pw) {
      if (g($a,'lock',0) > time()) return 'lock';
      if (password_verify($pw, g($a,'hash',''))) { $a['fails'] = 0; return 'ok'; }
      $a['fails'] = g($a,'fails',0) + 1;
      if ($a['fails'] >= 5) { $a['lock'] = time() + 600; $a['fails'] = 0; }
      return 'bad';
    });
    if ($res === 'lock') fail('ใส่รหัสผิดหลายครั้ง ล็อก 10 นาที', 429);
    if ($res !== 'ok') fail('รหัสผ่านไม่ถูกต้อง', 401);
  }
  start_session();
  @session_regenerate_id(true);
  $_SESSION['fest_admin'] = true; $_SESSION['csrf'] = rnd_hex(16);
  out(['ok'=>true, 'csrf'=>$_SESSION['csrf']]);
}

case 'logout': start_session(); $_SESSION = []; @session_destroy(); out(['ok'=>true]);

case 'admin_list': {
  if (!is_admin()) fail('กรุณาเข้าสู่ระบบผู้ดูแล', 401);
  $f = fest();
  $d = load_raw(store_path($f), ['items'=>[]]);
  out(['ok'=>true, 'items'=>$d['items'], 'open'=>$FESTIVALS[$f]['open']]);
}

case 'hide': {
  need_admin();
  $_GET['f'] = g($in,'f',''); $f = fest();
  $id = (string)(g($in,'id','')); $hide = !empty($in['hidden']);
  $ok = with_lock(store_path($f), ['items'=>[]], function (&$d) use ($id, $hide) {
    foreach ($d['items'] as &$it) if ($it['id'] === $id) { $it['hidden'] = $hide; return true; }
    return false;
  });
  $ok ? out(['ok'=>true]) : fail('ไม่พบ', 404);
}

case 'export': {
  if (!is_admin()) { http_response_code(401); exit('unauthorized'); }
  $f = fest();
  $d = load_raw(store_path($f), ['items'=>[]]);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $f . '_' . date('Ymd_Hi') . '.csv"');
  echo "\xEF\xBB\xBF";
  $o = fopen('php://output', 'w');
  fputcsv($o, ['เวลา','รหัสพนักงาน','ชื่อ','แผนก','ประเภท','ข้อความ','กระทง','สีดอกไม้','ร่วมอวยพร','ซ่อน','จำนวนคำตอบ','คำตอบ']);
  foreach ($d['items'] as $it) {
    $txt = $it['text'];
    if (preg_match('/^[=+\-@]/', $txt)) $txt = "'" . $txt; // กันสูตร Excel
    $g = function ($s) { return preg_match('/^[=+\-@]/', $s) ? "'" . $s : $s; };
    fputcsv($o, [date('Y-m-d H:i', $it['t']), g($it,'empid',''), $g($it['name']), $g($it['dept']), $it['kind']==='bless'?'อวยพร':'ขอพร',
                 $txt, $it['base'], $it['flower'], g($it,'bless',0), !empty($it['hidden']) ? 'Y' : '',
                 count(g($it,'replies',array())),
                 $g(implode(' | ', array_map(function ($r) { return (g($r,'name','') ?: 'ไม่ระบุชื่อ') . ': ' . $r['text'] . (!empty($r['hidden']) ? ' [ซ่อน]' : ''); }, g($it,'replies',array()))))]);
  }
  exit;
}

default: fail('unknown action', 404);
}
