<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  امنیت — محدودسازی، دسترسی، توکن، دفتر ثبت
 * ═══════════════════════════════════════════════════════════════
 */
declare(strict_types=1);

// ── نشانی واقعی کاربر ──────────────────────────────────────────
/**
 * فقط وقتی به سرآیند اعتماد می‌کنیم که در تنظیمات صراحتاً نامش آمده باشد.
 * وگرنه هر کسی می‌تواند با جعل سرآیند، محدودیت را دور بزند.
 */
function client_ip(): string {
    $header = CLIENT_IP_HEADER;
    if ($header !== '') {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        $raw = (string)($_SERVER[$key] ?? '');
        if ($raw !== '') {
            // ممکن است زنجیره باشد؛ اولین مقدار، نشانی کاربر است.
            $first = trim(explode(',', $raw)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
        }
    }
    $addr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($addr, FILTER_VALIDATE_IP) ? $addr : '0.0.0.0';
}

// ── محدودسازی روی پایگاه داده ──────────────────────────────────
// نسخه‌ی قبلی شمارنده را در نشست مرورگر نگه می‌داشت؛ با دور انداختن
// کوکی، شمارنده صفر می‌شد و قفل بی‌اثر بود. حالا کلید روی نشانی
// کاربر است و در پایگاه داده می‌ماند.

function rl_bucket(string $key): string {
    return substr(hash('sha256', $key), 0, 48);
}

/** آیا اجازه‌ی تلاش دارد؟ */
function rate_limit_check(string $key): bool {
    try {
        $stmt = db()->prepare('SELECT attempts, first_at, locked_until FROM rate_limits WHERE bucket = ?');
        $stmt->execute([rl_bucket($key)]);
        $row = $stmt->fetch();
    } catch (\Throwable $e) {
        return true; // اگر جدول در دسترس نبود، جلوی کاربر را نگیر
    }
    if (!$row) return true;

    $now = time();
    if ((int)$row['locked_until'] > $now) return false;

    // پنجره تمام شده؟ شمارنده از نو.
    if (($now - (int)$row['first_at']) > RATE_LIMIT_WINDOW_SEC) {
        rate_limit_reset($key);
        return true;
    }
    return (int)$row['attempts'] < RATE_LIMIT_MAX_ATTEMPTS;
}

/** ثبت یک تلاش ناموفق */
function rate_limit_fail(string $key): void {
    $now     = time();
    $expired = 'IF(? - first_at > ?, 1, attempts + 1)';
    try {
        db()->prepare(
            'INSERT INTO rate_limits (bucket, attempts, first_at, locked_until)
             VALUES (?, 1, ?, 0)
             ON DUPLICATE KEY UPDATE
               locked_until = IF(' . $expired . ' >= ?, ?, locked_until),
               attempts     = ' . $expired . ',
               first_at     = IF(? - first_at > ?, ?, first_at)'
        )->execute([
            rl_bucket($key), $now,
            // locked_until
            $now, RATE_LIMIT_WINDOW_SEC, RATE_LIMIT_MAX_ATTEMPTS, $now + RATE_LIMIT_LOCKOUT_SEC,
            // attempts
            $now, RATE_LIMIT_WINDOW_SEC,
            // first_at
            $now, RATE_LIMIT_WINDOW_SEC, $now,
        ]);
    } catch (\Throwable $e) {}
}

function rate_limit_reset(string $key): void {
    try {
        db()->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([rl_bucket($key)]);
    } catch (\Throwable $e) {}
}

function rate_limit_remaining_seconds(string $key): int {
    try {
        $stmt = db()->prepare('SELECT locked_until FROM rate_limits WHERE bucket = ?');
        $stmt->execute([rl_bucket($key)]);
        $until = (int)($stmt->fetchColumn() ?: 0);
    } catch (\Throwable $e) { return 0; }
    return max(0, $until - time());
}

/** پاک کردن ردیف‌های کهنه — گاه‌به‌گاه صدا زده می‌شود */
function rate_limit_gc(): void {
    try {
        if (random_int(1, 50) !== 1) return;
        db()->prepare('DELETE FROM rate_limits WHERE locked_until < ? AND first_at < ?')
            ->execute([time(), time() - 86400]);
    } catch (\Throwable $e) {}
}

// ── سقف ساخت حساب ──────────────────────────────────────────────
/**
 * چند حساب در ۲۴ ساعت گذشته از این نشانی ساخته شده؟
 * باگ نسخه‌ی قبلی: بعد از هر ثبت‌نام موفق شمارنده صفر می‌شد،
 * پس ساخت حساب عملاً بی‌سقف بود.
 */
function registrations_from_ip(string $ip): int {
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM signup_log WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 DAY)'
        );
        $stmt->execute([$ip]);
        return (int)$stmt->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

function register_quota_exceeded(string $ip): bool {
    if (REGISTER_MAX_PER_IP_DAY <= 0) return false;
    return registrations_from_ip($ip) >= REGISTER_MAX_PER_IP_DAY;
}

function log_signup(string $ip, int $userId, string $username): void {
    try {
        db()->prepare('INSERT INTO signup_log (ip, user_id, username) VALUES (?,?,?)')
            ->execute([$ip, $userId, $username]);
    } catch (\Throwable $e) {}
}

// ── سطح دسترسی ─────────────────────────────────────────────────
// 0 = کاربر عادی   1 = مدیر   2 = سازنده
const ADMIN_LEVEL_NONE  = 0;
const ADMIN_LEVEL_ADMIN = 1;
const ADMIN_LEVEL_OWNER = 2;

function current_admin_level(): int {
    static $cached = null;
    if ($cached !== null) return $cached;
    $uid = current_user_id();
    if ($uid <= 0) return $cached = ADMIN_LEVEL_NONE;
    return $cached = admin_level_of($uid);
}

function current_user_is_admin(): bool {
    return current_admin_level() >= ADMIN_LEVEL_ADMIN;
}

/** سازنده — تنها کسی که می‌تواند مدیر تعیین کند و تنظیمات سایت را عوض کند */
function current_user_is_owner(): bool {
    return current_admin_level() >= ADMIN_LEVEL_OWNER;
}

function require_admin(): void {
    require_login();
    if (!current_user_is_admin()) {
        http_response_code(403);
        exit('دسترسی غیر مجاز.');
    }
}

function require_owner(): void {
    require_admin();
    if (!current_user_is_owner()) {
        http_response_code(403);
        exit('این بخش فقط برای سازنده است.');
    }
}

/** سطح دسترسی یک کاربر دلخواه */
function admin_level_of(int $userId): int {
    if ($userId <= 0) return ADMIN_LEVEL_NONE;
    try {
        $stmt = db()->prepare('SELECT is_admin, admin_level FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) return ADMIN_LEVEL_NONE;
        $level = (int)($row['admin_level'] ?? 0);
        // سازگاری با داده‌ی قدیمی: هر کس is_admin داشت، دست‌کم مدیر است.
        if ($level < ADMIN_LEVEL_ADMIN && !empty($row['is_admin'])) {
            $level = ADMIN_LEVEL_ADMIN;
        }
        return $level;
    } catch (\Throwable $e) { return ADMIN_LEVEL_NONE; }
}

/**
 * آیا کاربر جاری اجازه دارد روی این هدف کار مدیریتی کند؟
 * یک مدیر نمی‌تواند روی مدیر هم‌رده یا بالاتر از خودش دست بگذارد —
 * جلوی این را می‌گیرد که مدیری رمز سازنده را عوض کند و سایت را بگیرد.
 */
function can_act_on(int $targetUserId): bool {
    if ($targetUserId <= 0) return false;
    if ($targetUserId === current_user_id()) return false;
    return current_admin_level() > admin_level_of($targetUserId);
}

// ── توکن امنیتی درخواست ────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_is_valid(string $token): bool {
    if (!CSRF_PROTECTION_ENABLED) return true;
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function require_csrf_json(): void {
    if (!CSRF_PROTECTION_ENABLED) return;

    // راه واسط: فقط اگر کلیدی تنظیم شده باشد. خالی بودن یعنی این راه بسته است.
    if (PROXY_SECRET !== '') {
        $sent = (string)($_SERVER['HTTP_X_PROXY_SECRET'] ?? '');
        if ($sent !== '' && hash_equals(PROXY_SECRET, $sent)) return;
    }

    $token = (string)($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($token === '' || !csrf_is_valid($token)) {
        json_response(['ok' => false, 'error' => 'توکن امنیتی نامعتبر است.'], 400);
    }
}

// ── دفتر ثبت کارهای مدیر ───────────────────────────────────────
function admin_log(string $action, int $targetUserId = 0, string $detail = ''): void {
    try {
        db()->prepare(
            'INSERT INTO admin_log (admin_id, admin_name, action, target_user_id, detail, ip)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            current_user_id(),
            current_username(),
            $action,
            $targetUserId ?: null,
            mb_substr($detail, 0, 500),
            client_ip(),
        ]);
    } catch (\Throwable $e) {}
}
