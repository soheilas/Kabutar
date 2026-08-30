<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  راه‌انداز برنامه
 * ═══════════════════════════════════════════════════════════════
 *  این فایل را ویرایش نکن. تمام تنظیمات در config.local.php است.
 *  (نمونه‌اش را در config.sample.php ببین.)
 */
declare(strict_types=1);

// ── ۱. خواندن فایل تنظیمات ─────────────────────────────────────
// اول بیرون از پوشه‌ی عمومی می‌گردیم، بعد کنار خود برنامه.
function app_config(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    foreach ([
        dirname(__DIR__) . '/chat-config.php',
        __DIR__ . '/config.local.php',
    ] as $candidate) {
        if (!is_file($candidate)) continue;
        $loaded = require $candidate;
        if (is_array($loaded)) { return $cache = $loaded; }
    }
    http_response_code(500);
    exit('فایل تنظیمات پیدا نشد. config.sample.php را به config.local.php کپی کن.');
}

/** خواندن یک تنظیم با مسیر نقطه‌ای، مثل cfg('db.host') */
function cfg(string $path, $default = null) {
    $node = app_config();
    foreach (explode('.', $path) as $seg) {
        if (!is_array($node) || !array_key_exists($seg, $node)) return $default;
        $node = $node[$seg];
    }
    return $node;
}

// ── ۲. ثابت‌ها (برای سازگاری با کد موجود) ──────────────────────
define('SITE_NAME',        (string)cfg('site.name', 'گفتگو'));
define('SITE_SHORT_NAME',  (string)cfg('site.short_name', cfg('site.name', 'گفتگو')));
define('SITE_URL',         rtrim((string)cfg('site.url', ''), '/'));
define('SITE_THEME_COLOR', (string)cfg('site.theme_color', '#080e17'));
define('DEFAULT_ROOM_NAME',(string)cfg('site.default_room', 'عمومی'));

define('DB_HOST',    (string)cfg('db.host', 'localhost'));
define('DB_NAME',    (string)cfg('db.name', ''));
define('DB_USER',    (string)cfg('db.user', ''));
define('DB_PASS',    (string)cfg('db.pass', ''));
define('DB_CHARSET', (string)cfg('db.charset', 'utf8mb4'));

define('MSG_ENCRYPT_KEY',  (string)cfg('security.msg_encrypt_key', ''));
define('PROXY_SECRET',     (string)cfg('security.proxy_secret', ''));
define('CSRF_PROTECTION_ENABLED', (bool)cfg('security.csrf_enabled', true));
define('SESSION_LIFETIME', max(1, (int)cfg('security.session_lifetime_days', 30)) * 86400);
define('CLIENT_IP_HEADER', (string)cfg('security.client_ip_header', ''));

define('RATE_LIMIT_MAX_ATTEMPTS', (int)cfg('limits.login_max_attempts', 10));
define('RATE_LIMIT_WINDOW_SEC',   (int)cfg('limits.login_window_sec', 300));
define('RATE_LIMIT_LOCKOUT_SEC',  (int)cfg('limits.login_lockout_sec', 900));
define('REGISTER_MAX_PER_IP_DAY', (int)cfg('limits.register_max_per_ip_day', 3));
define('REGISTER_MIN_PASSWORD',   (int)cfg('limits.register_min_password', 8));
define('ADMIN_SET_MIN_PASSWORD',  (int)cfg('limits.admin_set_min_password', 8));

define('MAX_FILE_SIZE',     max(1, (int)cfg('limits.max_file_size_mb', 10)) * 1024 * 1024);
define('MAX_FILE_SIZE_VIP', max(1, (int)cfg('limits.max_file_size_vip_mb', 25)) * 1024 * 1024);
define('MAX_MESSAGE_LEN',   (int)cfg('limits.max_message_len', 2000));

define('FEATURE_CALLS',        (bool)cfg('features.calls', true));
define('FEATURE_REGISTRATION', (bool)cfg('features.registration', true));

define('ASSET_VERSION', (string)cfg('asset_version', '1'));
define('UPLOAD_DIR',    __DIR__ . '/storage/uploads');
define('AVATAR_URL',    'assets/avatar3.svg');

// ── ۳. نشست ────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    ini_set('session.cookie_lifetime', (string)SESSION_LIFETIME);
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── ۴. بارگذاری کتابخانه‌ها ────────────────────────────────────
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/schema.php';
