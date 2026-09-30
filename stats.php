<?php
/*
 * 심화 트랙 백엔드 뼈대 · 두온교육 「강의 사이트 제작 노하우」 참가자 키트
 * - PHP 가 도는 호스팅(NAS·웹호스팅) 전용. Vercel · GitHub Pages 는 PHP 미지원 → 이 파일 없이 정적 사이트로 운영
 * - 액션: ping · check_admin · get_state · set_slide · set_lock · set_pdf
 * - 상태 파일: data/state.json.php (첫 줄 403 가드로 웹 직접 접근 차단)
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// 배포 전 서버 환경변수 WEBSLIDE_KIT_ADMIN_PASSWORD 설정 · 미설정 시 관리자 기능 잠금
$ADMIN_PASSWORD = (string) (getenv('WEBSLIDE_KIT_ADMIN_PASSWORD') ?: '');

$DATA_DIR = __DIR__ . '/data';
if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0755, true);

// data/*.json.php 첫 줄 가드: 웹으로 직접 열면 403 후 종료
define('DATA_GUARD', '<' . '?php http_response_code(403); exit; ?' . '>' . "\n");

function strip_guard($raw) {
    if (is_string($raw) && strncmp($raw, '<' . '?php', 5) === 0) {
        $nl = strpos($raw, "\n");
        return $nl === false ? '' : substr($raw, $nl + 1);
    }
    return $raw;
}

function load_json($f, $d = []) {
    if (!file_exists($f)) return $d;
    $raw = @file_get_contents($f);
    if ($raw === false || $raw === '') return $d;
    $j = json_decode(strip_guard($raw), true);
    return is_array($j) ? $j : $d;
}

// 쓰기는 성공할 때만: 인코딩 실패 → 기존 파일 그대로 · 임시 파일에 다 쓴 뒤 rename 으로 교체
// 임시 파일도 .php + 가드라서 웹으로 열면 403
function save_json($f, $data) {
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) return false;
    $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(6)) . '.php';
    if (@file_put_contents($tmp, DATA_GUARD . $json, LOCK_EX) === false) { @unlink($tmp); return false; }
    if (!@rename($tmp, $f)) { @unlink($tmp); return false; }
    return true;
}

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function check_password($pw) {
    global $ADMIN_PASSWORD;
    if ($ADMIN_PASSWORD === '') return false; // 기본값 그대로면 관리자 기능 전체 잠금
    return is_string($pw) && $pw !== '' && hash_equals($ADMIN_PASSWORD, $pw);
}

function require_admin() {
    $pw = $_POST['pw'] ?? '';
    if (!check_password($pw)) out(['error' => 'unauthorized'], 401);
}

function require_post() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['error' => 'method_not_allowed'], 405);
}

// Apache 용 data/.htaccess 자동 생성 (nginx 는 파일 첫 줄 PHP 가드가 실제 방어)
$HT = $DATA_DIR . '/.htaccess';
if (!file_exists($HT)) @file_put_contents($HT,
    "<IfModule mod_authz_core.c>\n Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n Order allow,deny\n Deny from all\n</IfModule>\n");

$STATE = $DATA_DIR . '/state.json.php';

function blank_state() {
    return ['slide' => 0, 'locked' => 1, 'pdf' => 1];
}

// 상태 한 칸 변경 → 저장 성공 시에만 ok
function update_state($key, $value) {
    global $STATE;
    $s = load_json($STATE, blank_state());
    $s[$key] = $value;
    if (!save_json($STATE, $s)) out(['error' => 'save_failed'], 500);
    out(['ok' => true, $key => $value]);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'ping';

if ($action === 'ping') out(['ok' => true]);

if ($action === 'check_admin') {
    require_post();
    out(['ok' => check_password($_POST['pw'] ?? '')]);
}

if ($action === 'get_state') {
    $s = load_json($STATE, blank_state());
    foreach (blank_state() as $k => $v) if (!isset($s[$k])) $s[$k] = $v;
    $s['now'] = time();
    out($s);
}

if ($action === 'set_slide') {
    require_post();
    require_admin();
    update_state('slide', max(0, (int)($_POST['value'] ?? 0)));
}

if ($action === 'set_lock') {
    require_post();
    require_admin();
    update_state('locked', ($_POST['value'] ?? '1') === '1' ? 1 : 0);
}

if ($action === 'set_pdf') {
    require_post();
    require_admin();
    update_state('pdf', ($_POST['value'] ?? '1') === '1' ? 1 : 0);
}

out(['error' => 'unknown_action'], 400);
