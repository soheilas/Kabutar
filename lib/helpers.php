<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  توابع کمکی — پایگاه داده، نشست کاربر، فایل، رمزنگاری
 * ═══════════════════════════════════════════════════════════════
 */
declare(strict_types=1);

// ── پایگاه داده ────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    auto_migrate($pdo);
    return $pdo;
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** ارقام لاتین را به فارسی تبدیل می‌کند — برای نمایش، نه برای ورودی */
function fa($value): string {
    return strtr((string)$value, [
        '0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴',
        '5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹',
    ]);
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── تنظیمات قابل تغییر از پنل (در پایگاه داده) ─────────────────
function site_setting(string $key, string $default = ''): string {
    if (!isset($GLOBALS['_site_settings_cache']) || $GLOBALS['_site_settings_cache'] === null) {
        $GLOBALS['_site_settings_cache'] = [];
        try {
            foreach (db()->query('SELECT `key`,`value` FROM site_settings')->fetchAll() as $r) {
                $GLOBALS['_site_settings_cache'][$r['key']] = $r['value'];
            }
        } catch (\Throwable $e) {}
    }
    return $GLOBALS['_site_settings_cache'][$key] ?? $default;
}

function site_setting_set(string $key, string $value): void {
    try {
        db()->prepare('INSERT INTO site_settings (`key`,`value`) VALUES (?,?)
                       ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=NOW()')
            ->execute([$key, $value]);
        $GLOBALS['_site_settings_cache'] = null;
    } catch (\Throwable $e) {}
}

// ── رمزنگاری پیام‌های خصوصی ────────────────────────────────────
function msg_encrypt(?string $text): ?string {
    if ($text === null || $text === '') return $text;
    if (MSG_ENCRYPT_KEY === '') return $text;
    try {
        $key = substr(str_pad(MSG_ENCRYPT_KEY, 32, "\0"), 0, 32);
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($text, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($enc === false) return $text;
        return base64_encode($iv . $enc);
    } catch (\Throwable $e) { return $text; }
}

function msg_decrypt(?string $text): ?string {
    if ($text === null || $text === '') return $text;
    if (MSG_ENCRYPT_KEY === '') return $text;
    $decoded = base64_decode($text, true);
    if ($decoded === false || strlen($decoded) < 17) return $text;
    try {
        $key = substr(str_pad(MSG_ENCRYPT_KEY, 32, "\0"), 0, 32);
        $iv  = substr($decoded, 0, 16);
        $enc = substr($decoded, 16);
        $dec = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $dec === false ? $text : $dec;
    } catch (\Throwable $e) { return $text; }
}

function is_encrypted(?string $text): bool {
    if (!$text || MSG_ENCRYPT_KEY === '') return false;
    $decoded = base64_decode($text, true);
    return $decoded !== false && strlen($decoded) >= 17;
}

// ── حالت بروزرسانی ─────────────────────────────────────────────
function check_maintenance(): void {
    if (site_setting('maintenance_mode') !== '1') return;
    if (!empty($_SESSION['user_id']) && current_user_is_admin()) return;
    if (!empty($_SESSION['user_id'])) session_destroy();

    $msg   = h(site_setting('maintenance_message', 'سیستم در حال بروزرسانی است.'));
    $title = h(SITE_NAME);
    http_response_code(503);
    header('Retry-After: 600');
    echo <<<HTML
<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8">
<title>{$title} — بروزرسانی</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{background:#080e17;color:#e6edf4;font-family:system-ui,sans-serif;
       min-height:100vh;display:flex;align-items:center;justify-content:center;text-align:center}
  .box{padding:40px;max-width:400px}
  .icon{font-size:56px;margin-bottom:20px}
  h1{font-size:22px;font-weight:800;margin-bottom:12px}
  p{color:#6b8a9e;font-size:14px;line-height:1.7}
</style></head><body>
<div class="box"><div class="icon">🔧</div><h1>در حال بروزرسانی</h1><p>{$msg}</p></div>
</body></html>
HTML;
    exit;
}

// ── کاربر جاری ─────────────────────────────────────────────────
function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_username(): string {
    return (string)($_SESSION['username'] ?? '');
}

function require_login(): void {
    check_maintenance();
    if (empty($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
    // بررسی مسدودی
    try {
        $stmt = db()->prepare('SELECT banned_until, is_banned_permanently FROM users WHERE id = ?');
        $stmt->execute([current_user_id()]);
        $row = $stmt->fetch();
        if (!$row) {                       // حساب حذف شده
            session_destroy();
            header('Location: index.php');
            exit;
        }
        $banned = !empty($row['is_banned_permanently'])
               || (!empty($row['banned_until']) && strtotime((string)$row['banned_until']) > time());
        if ($banned) {
            session_destroy();
            header('Location: index.php?banned=1');
            exit;
        }
    } catch (\Throwable $e) {}
    touch_user(current_user_id());
}

function touch_user(int $userId, bool $force = false): void {
    if ($userId <= 0) return;
    $now      = time();
    $lastPing = (int)($_SESSION['last_active_ping'] ?? 0);
    if (!$force && $lastPing && ($now - $lastPing) < 15) return;
    $_SESSION['last_active_ping'] = $now;
    try {
        db()->prepare('UPDATE users SET last_active = NOW() WHERE id = ?')->execute([$userId]);
    } catch (\Throwable $e) {}
}

function current_user_is_vip(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (current_user_is_admin()) return $cached = true;
    $uid = current_user_id();
    if ($uid <= 0) return $cached = false;
    try {
        $stmt = db()->prepare('SELECT is_vip FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        return $cached = (bool)$stmt->fetchColumn();
    } catch (\Throwable $e) { return $cached = false; }
}

// ── فایل ───────────────────────────────────────────────────────
function sanitize_filename(string $name): string {
    $name = str_replace(["\0", "\r", "\n"], '', trim($name));
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
    return preg_replace('/_+/', '_', $name) ?? '';
}

function ensure_upload_dir(): void {
    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        throw new RuntimeException('پوشه آپلود قابل نوشتن نیست.');
    }
}

/** فایل آپلودی یک پیام را از دیسک پاک می‌کند (اگر جای دیگری استفاده نشده باشد) */
function delete_upload(?string $token): void {
    if (!$token || !preg_match('/^[a-f0-9]{32}$/', $token)) return;
    try {
        $stmt = db()->prepare('SELECT 1 FROM messages WHERE file_token = ? LIMIT 1');
        $stmt->execute([$token]);
        if ($stmt->fetchColumn()) return;                 // هنوز پیامی به آن اشاره دارد
        $stmt = db()->prepare('SELECT 1 FROM users WHERE avatar_token = ? LIMIT 1');
        $stmt->execute([$token]);
        if ($stmt->fetchColumn()) return;                 // تصویر نمایه‌ی کسی است
    } catch (\Throwable $e) { return; }
    $path = UPLOAD_DIR . '/' . $token;
    if (is_file($path)) @unlink($path);
}

function asset_version(string $path): string {
    $fullPath = __DIR__ . '/../' . ltrim($path, '/');
    if (is_file($fullPath)) return (string)filemtime($fullPath) . '-' . ASSET_VERSION;
    return ASSET_VERSION;
}

// ── اتاق‌ها ────────────────────────────────────────────────────
function get_default_room_id(PDO $pdo): int {
    $stmt = $pdo->prepare('SELECT id FROM rooms WHERE name = ? LIMIT 1');
    $stmt->execute([DEFAULT_ROOM_NAME]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    $stmt->execute(['General']);
    $legacyId = $stmt->fetchColumn();
    if ($legacyId) {
        $pdo->prepare('UPDATE rooms SET name = ? WHERE id = ?')->execute([DEFAULT_ROOM_NAME, $legacyId]);
        return (int)$legacyId;
    }
    $pdo->prepare('INSERT INTO rooms (name) VALUES (?)')->execute([DEFAULT_ROOM_NAME]);
    return (int)$pdo->lastInsertId();
}

// ── اجازه‌ی گفتگوی خصوصی ───────────────────────────────────────
function ensure_private_chat_permissions_table(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS private_chat_permissions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            requester_id INT UNSIGNED NOT NULL,
            recipient_id INT UNSIGNED NOT NULL,
            status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
            responded_by INT UNSIGNED NULL,
            responded_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_private_pair (requester_id, recipient_id),
            KEY idx_private_recipient_status (recipient_id, status, updated_at),
            KEY idx_private_requester_status (requester_id, status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (\Throwable $e) {}
}

function private_chat_relation(PDO $pdo, int $userA, int $userB): array {
    $r = ['can_chat' => false, 'incoming_pending_id' => 0, 'outgoing_pending_id' => 0, 'accepted_id' => 0];
    if ($userA <= 0 || $userB <= 0 || $userA === $userB) return $r;
    ensure_private_chat_permissions_table($pdo);
    try {
        $stmt = $pdo->prepare('SELECT id, requester_id, recipient_id, status FROM private_chat_permissions
            WHERE (requester_id = ? AND recipient_id = ?) OR (requester_id = ? AND recipient_id = ?) ORDER BY id DESC');
        $stmt->execute([$userA, $userB, $userB, $userA]);
        foreach ($stmt->fetchAll() as $row) {
            $status = strtolower((string)($row['status'] ?? ''));
            $id  = (int)($row['id'] ?? 0);
            $req = (int)($row['requester_id'] ?? 0);
            $rec = (int)($row['recipient_id'] ?? 0);
            if ($status === 'accepted') {
                $r['can_chat'] = true;
                if (!$r['accepted_id']) $r['accepted_id'] = $id;
                continue;
            }
            if ($status !== 'pending') continue;
            if ($req === $userA && $rec === $userB) {
                if (!$r['outgoing_pending_id']) $r['outgoing_pending_id'] = $id;
            } elseif ($req === $userB && $rec === $userA) {
                if (!$r['incoming_pending_id']) $r['incoming_pending_id'] = $id;
            }
        }
    } catch (\Throwable $e) { return $r; }
    return $r;
}

function private_chat_has_history(PDO $pdo, int $userA, int $userB): bool {
    if ($userA <= 0 || $userB <= 0 || $userA === $userB) return false;
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM messages WHERE (sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?) LIMIT 1');
        $stmt->execute([$userA, $userB, $userB, $userA]);
        return (bool)$stmt->fetchColumn();
    } catch (\Throwable $e) { return false; }
}

function private_chat_is_allowed(PDO $pdo, int $userA, int $userB): bool {
    // مدیر همیشه مجاز به گفتگو با همه است
    if (current_user_is_admin()) return true;

    // هر کسی می‌تواند بدون درخواست به مدیر پیام بدهد
    if (admin_level_of($userB) >= ADMIN_LEVEL_ADMIN) return true;

    $rel = private_chat_relation($pdo, $userA, $userB);
    return !empty($rel['can_chat']) || private_chat_has_history($pdo, $userA, $userB);
}
