<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  نصب‌کننده‌ی کبوتر
 * ═══════════════════════════════════════════════════════════════
 *
 *  یک صفحه، یک فرم. نام سایت، مشخصات پایگاه داده و حساب مدیر را
 *  می‌گیرد، اتصال را آزمایش می‌کند، فایل تنظیمات را می‌نویسد،
 *  جدول‌ها را می‌سازد و واردت می‌کند.
 *
 *  این فایل به‌محض اینکه نصب کامل شد، خودش را می‌بندد.
 *
 *  رمز پیش‌فرض ندارد و عمداً هم نخواهد داشت: کبوتر متن‌باز است، پس
 *  هر رمز پیش‌فرضی در مخزن عمومی برای همه پیداست و ربات‌ها نصب‌های
 *  تازه را با همان می‌گیرند. رمز را خودت می‌سازی.
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

const KABUTAR_MIN_PHP    = '8.0.0';
const KABUTAR_NEEDED_EXT = ['pdo_mysql', 'openssl', 'mbstring', 'fileinfo'];

// ───────────────────────────────────────────────────────────────
//  کمک‌تابع‌ها
// ───────────────────────────────────────────────────────────────

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function fa_num($v): string {
    return strtr((string)$v, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴',
                              '5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}

/** رشته را برای گذاشتن داخل رشته‌ی تک‌کوتیشنی پی‌اچ‌پی امن می‌کند */
function php_str(string $v): string {
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'";
}

/** نام کوتاه برای نصب روی گوشی — وسط کلمه نمی‌برد */
function short_name(string $name): string {
    if (mb_strlen($name) <= 14) return $name;
    $cut = mb_substr($name, 0, 14);
    $sp  = mb_strrpos($cut, ' ');
    return $sp !== false && $sp >= 4 ? mb_substr($cut, 0, $sp) : $cut;
}

/** جاهایی که می‌شود فایل تنظیمات را نوشت، به ترتیب امنیت */
function config_targets(): array {
    return [
        ['path' => dirname(__DIR__) . '/chat-config.php',
         'label' => 'بیرون از پوشه‌ی عمومی (امن‌ترین)'],
        ['path' => __DIR__ . '/config.local.php',
         'label' => 'کنار برنامه'],
    ];
}

function first_writable_target(): ?array {
    foreach (config_targets() as $t) {
        $dir = dirname($t['path']);
        if (is_writable($t['path']) || (!file_exists($t['path']) && is_writable($dir))) {
            return $t;
        }
    }
    return null;
}

function existing_config(): ?string {
    foreach (config_targets() as $t) {
        if (is_file($t['path'])) return $t['path'];
    }
    return null;
}

/** آیا نصب از قبل کامل شده؟ */
function already_installed(): bool {
    $cfgPath = existing_config();
    if ($cfgPath === null) return false;
    $cfg = @include $cfgPath;
    if (!is_array($cfg) || empty($cfg['db']['name'])) return false;
    try {
        $pdo = new PDO(
            'mysql:host=' . ($cfg['db']['host'] ?? 'localhost') . ';dbname=' . $cfg['db']['name'] .
            ';charset=' . ($cfg['db']['charset'] ?? 'utf8mb4'),
            (string)($cfg['db']['user'] ?? ''), (string)($cfg['db']['pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (\Throwable $ex) {
        return false;
    }
}

function pdo_error_code(PDOException $ex): int {
    return preg_match('/\[(\d{4})\]/', $ex->getMessage(), $m) ? (int)$m[1] : 0;
}

/**
 * فهرست پایگاه داده‌هایی که این کاربر می‌بیند.
 *
 * خطای ۱۰۴۴ دو معنی دارد و از بیرون شبیه هم‌اند: یا کاربر به آن پایگاه
 * داده وصل نشده، یا آن پایگاه داده با آن نام اصلاً وجود ندارد. به‌جای
 * حدس زدن، همان‌جا وصل می‌شویم بدون انتخاب پایگاه داده و می‌پرسیم چه
 * چیزی در دسترس است. روی لینوکس نام‌ها به بزرگی و کوچکی حروف حساس‌اند،
 * پس اختلاف حرف بزرگ و کوچک هم همین‌جا معلوم می‌شود.
 */
function visible_databases(string $host, string $user, string $pass): ?array {
    try {
        $pdo = new PDO('mysql:host=' . $host, $user, $pass,
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $all = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
        $skip = ['information_schema', 'performance_schema', 'mysql', 'sys'];
        return array_values(array_filter($all, static fn($d) => !in_array($d, $skip, true)));
    } catch (\Throwable $ex) {
        return null;
    }
}

function db_error_hint(PDOException $ex, string $host = '', string $user = '',
                       string $pass = '', string $wanted = ''): string {
    $code = pdo_error_code($ex);

    // برای ۱۰۴۴ و ۱۰۴۹ می‌شود دقیق‌تر راهنمایی کرد
    if (($code === 1044 || $code === 1049) && $host !== '') {
        $list = visible_databases($host, $user, $pass);

        if ($list !== null && $list !== []) {
            // شاید فقط حرف بزرگ و کوچک فرق دارد
            foreach ($list as $name) {
                if (strcasecmp($name, $wanted) === 0 && $name !== $wanted) {
                    return 'نام پایگاه داده «' . e($wanted) . '» است ولی روی سرور «' . e($name)
                         . '» ساخته شده. نام‌ها به بزرگی و کوچکی حروف حساس‌اند — همان را دقیقاً بنویس.';
                }
            }
            return 'پایگاه داده‌ای به نام «' . e($wanted) . '» برای این کاربر پیدا نشد. '
                 . 'این کاربر به این‌ها دسترسی دارد: ' . e(implode('، ', array_slice($list, 0, 8)))
                 . (count($list) > 8 ? ' و چند تای دیگر' : '') . '.';
        }

        if ($list !== null) {
            return 'این کاربر به هیچ پایگاه داده‌ای دسترسی ندارد. در پنل هاست، کاربر را به '
                 . 'پایگاه داده وصل کن و همه‌ی دسترسی‌ها را بده.';
        }
    }

    return [
        1045 => 'نام کاربری یا رمز پایگاه داده اشتباه است.',
        1044 => 'این کاربر به آن پایگاه داده دسترسی ندارد، یا پایگاه داده‌ای با این نام وجود ندارد. '
              . 'در پنل هاست نام دقیق را ببین (بزرگی و کوچکی حروف مهم است) و کاربر را به آن وصل کن.',
        1049 => 'پایگاه داده‌ای با این نام پیدا نشد. نام دقیق را از پنل هاست بردار.',
        2002 => 'سرور پایگاه داده در دسترس نیست. معمولاً مقدار درست localhost است.',
        2003 => 'سرور پایگاه داده در دسترس نیست. معمولاً مقدار درست localhost است.',
    ][$code] ?? 'اتصال برقرار نشد. مقادیر را دوباره بررسی کن.';
}

// ───────────────────────────────────────────────────────────────
//  صفحه‌ی پایان — پیش از قفل، چون همین الان نصب تمام شده
//  فقط برای کسی که همان لحظه وارد شده، نه هر رهگذری
// ───────────────────────────────────────────────────────────────
if (isset($_GET['done'])) {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    if (!empty($_SESSION['user_id'])) { show_done_page(); }
    header('Location: index.php');
    exit;
}

// ───────────────────────────────────────────────────────────────
//  اگر از قبل نصب شده، در بسته است
// ───────────────────────────────────────────────────────────────
if (already_installed()) {
    http_response_code(403);
    ?><!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>نصب انجام شده</title><style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{background:#080e17;color:#e6edf4;font-family:system-ui,Tahoma,sans-serif;min-height:100vh;
         display:flex;align-items:center;justify-content:center;padding:24px;line-height:2}
    .b{max-width:460px;background:#0f1c2a;border:1px solid #1e3248;border-radius:16px;padding:32px;text-align:center}
    h1{font-size:20px;margin-bottom:10px;color:#22cc77}
    p{color:#a8c6e0;font-size:14px;margin-bottom:16px}
    code{background:#0b1520;padding:3px 8px;border-radius:5px;color:#8fd0ff;direction:ltr;display:inline-block}
    a{display:inline-block;margin-top:8px;background:linear-gradient(135deg,#3aa4ff,#7c5cfc);
      color:#fff;text-decoration:none;padding:11px 26px;border-radius:10px;font-weight:700}
    </style></head><body><div class="b">
      <div style="font-size:44px">✅</div>
      <h1>کبوتر از قبل نصب شده</h1>
      <p>برای امنیت، همین حالا فایل <code>install.php</code> را پاک کن.</p>
      <a href="index.php">رفتن به سایت</a>
    </div></body></html><?php
    exit;
}

// ───────────────────────────────────────────────────────────────
//  بررسی پیش‌نیازها
// ───────────────────────────────────────────────────────────────
$checks = [];

$checks[] = [
    'label' => 'نسخه‌ی پی‌اچ‌پی ' . fa_num(KABUTAR_MIN_PHP) . ' یا بالاتر',
    'ok'    => version_compare(PHP_VERSION, KABUTAR_MIN_PHP, '>='),
    'note'  => 'نسخه‌ی فعلی: ' . PHP_VERSION,
];

foreach (KABUTAR_NEEDED_EXT as $ext) {
    $checks[] = [
        'label' => 'افزونه‌ی ' . $ext,
        'ok'    => extension_loaded($ext),
        'note'  => extension_loaded($ext) ? 'فعال' : 'از پنل هاست فعالش کن',
    ];
}

$uploadDir = __DIR__ . '/storage/uploads';
$uploadOk  = is_dir($uploadDir) ? is_writable($uploadDir) : @mkdir($uploadDir, 0755, true);
$checks[] = [
    'label' => 'پوشه‌ی فایل‌های کاربران قابل نوشتن',
    'ok'    => (bool)$uploadOk,
    'note'  => $uploadOk ? 'آماده' : 'به storage/uploads دسترسی نوشتن بده',
];

$target = first_writable_target();
$checks[] = [
    'label' => 'محل نوشتن فایل تنظیمات',
    'ok'    => $target !== null,
    'note'  => $target ? $target['label'] : 'به پوشه‌ی برنامه دسترسی نوشتن بده',
];

$allOk = true;
foreach ($checks as $c) { if (!$c['ok']) $allOk = false; }

// ───────────────────────────────────────────────────────────────
//  پردازش فرم
// ───────────────────────────────────────────────────────────────
$errors = [];
$in = [
    'site_name' => trim((string)($_POST['site_name'] ?? 'کبوتر')),
    'tagline'   => trim((string)($_POST['tagline']   ?? 'پیام‌رسان امن و خصوصی')),
    'db_host'   => trim((string)($_POST['db_host']   ?? 'localhost')),
    'db_name'   => trim((string)($_POST['db_name']   ?? '')),
    'db_user'   => trim((string)($_POST['db_user']   ?? '')),
    'db_pass'   =>       (string)($_POST['db_pass']   ?? ''),
    'admin_user'=> trim((string)($_POST['admin_user']?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $allOk) {
    $adminPass  = (string)($_POST['admin_pass'] ?? '');
    $adminPass2 = (string)($_POST['admin_pass2'] ?? '');

    if ($in['site_name'] === '')                                    $errors[] = 'نام سایت را بنویس.';
    if ($in['db_name'] === '' || $in['db_user'] === '')             $errors[] = 'نام پایگاه داده و کاربرش را بنویس.';
    if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $in['admin_user']))   $errors[] = 'نام کاربری مدیر باید ۳ تا ۳۰ کاراکتر و فقط حروف انگلیسی، عدد و زیرخط باشد.';
    if (strlen($adminPass) < 8)                                     $errors[] = 'رمز مدیر باید حداقل ۸ کاراکتر باشد.';
    if ($adminPass !== $adminPass2)                                 $errors[] = 'دو رمزی که نوشتی یکی نیستند.';

    // ── آزمایش زنده‌ی اتصال ──
    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO(
                'mysql:host=' . $in['db_host'] . ';dbname=' . $in['db_name'] . ';charset=utf8mb4',
                $in['db_user'], $in['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                 PDO::ATTR_EMULATE_PREPARES => false]
            );
            $pdo->query('SELECT 1');
        } catch (PDOException $ex) {
            error_log('Kabutar install: ' . $ex->getMessage());
            $errors[] = db_error_hint($ex, $in['db_host'], $in['db_user'], $in['db_pass'], $in['db_name']);
            $pdo = null;
        }
    }

    // ── نوشتن فایل تنظیمات ──
    if (!$errors && $pdo) {
        $key = bin2hex(random_bytes(16));            // ۳۲ کاراکتر
        $cfg = "<?php\n"
             . "/**\n"
             . " * تنظیمات این نصب — ساخته‌شده توسط نصب‌کننده\n"
             . " *\n"
             . " * هشدار: msg_encrypt_key را بعد از اولین پیام هرگز عوض نکن.\n"
             . " * پیام‌های خصوصی با همین کلید رمز می‌شوند و با کلید دیگر ناخوانا می‌شوند.\n"
             . " */\n\n"
             . "return [\n"
             . "    'site' => [\n"
             . "        'name'         => " . php_str($in['site_name']) . ",\n"
             . "        'short_name'   => " . php_str(short_name($in['site_name'])) . ",\n"
             . "        'tagline'      => " . php_str($in['tagline']) . ",\n"
             . "        'url'          => " . php_str(site_base_url()) . ",\n"
             . "        'theme_color'  => '#080e17',\n"
             . "        'default_room' => 'عمومی',\n"
             . "    ],\n\n"
             . "    'db' => [\n"
             . "        'host'    => " . php_str($in['db_host']) . ",\n"
             . "        'name'    => " . php_str($in['db_name']) . ",\n"
             . "        'user'    => " . php_str($in['db_user']) . ",\n"
             . "        'pass'    => " . php_str($in['db_pass']) . ",\n"
             . "        'charset' => 'utf8mb4',\n"
             . "    ],\n\n"
             . "    'security' => [\n"
             . "        'msg_encrypt_key'       => " . php_str($key) . ",\n"
             . "        'proxy_secret'          => '',\n"
             . "        'csrf_enabled'          => true,\n"
             . "        'session_lifetime_days' => 30,\n"
             . "        'client_ip_header'      => '',\n"
             . "    ],\n\n"
             . "    'limits' => [\n"
             . "        'login_max_attempts'      => 10,\n"
             . "        'login_window_sec'        => 300,\n"
             . "        'login_lockout_sec'       => 900,\n"
             . "        'register_max_per_ip_day' => 3,\n"
             . "        'register_min_password'   => 8,\n"
             . "        'admin_set_min_password'  => 8,\n"
             . "        'max_file_size_mb'        => 10,\n"
             . "        'max_file_size_vip_mb'    => 25,\n"
             . "        'max_message_len'         => 2000,\n"
             . "    ],\n\n"
             . "    'calls' => [\n"
             . "        'stun'          => 'stun:stun.l.google.com:19302',\n"
             . "        'turn_url'      => '',\n"
             . "        'turn_user'     => '',\n"
             . "        'turn_pass'     => '',\n"
             . "        'turn_secret'   => '',\n"
             . "        'turn_ttl_secs' => 3600,\n"
             . "    ],\n\n"
             . "    'features' => [\n"
             . "        'calls'        => true,\n"
             . "        'registration' => true,\n"
             . "    ],\n\n"
             . "    'debug' => false,\n\n"
             . "    'asset_version' => " . php_str(date('Y-m-d')) . ",\n"
             . "];\n";

        $target = first_writable_target();
        if ($target === null || @file_put_contents($target['path'], $cfg) === false) {
            $errors[] = 'فایل تنظیمات نوشته نشد. به پوشه‌ی برنامه دسترسی نوشتن بده و دوباره تلاش کن.';
        } else {
            @chmod($target['path'], 0600);

            // ── ساخت جدول‌ها و حساب مدیر ──
            try {
                require_once __DIR__ . '/config.php';   // حالا فایل تنظیمات وجود دارد
                $pdo = db();                            // خودش auto_migrate را اجرا می‌کند

                if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
                    $pdo->prepare('INSERT INTO users (username, password_hash, is_admin, admin_level)
                                   VALUES (?, ?, 1, ?)')
                        ->execute([$in['admin_user'],
                                   password_hash($adminPass, PASSWORD_DEFAULT),
                                   ADMIN_LEVEL_OWNER]);
                    $uid = (int)$pdo->lastInsertId();

                    try {
                        $rid = get_default_room_id($pdo);
                        if ($rid > 0) {
                            $pdo->prepare('INSERT IGNORE INTO room_members (room_id, user_id) VALUES (?, ?)')
                                ->execute([$rid, $uid]);
                        }
                    } catch (\Throwable $ex) {}

                    session_regenerate_id(true);
                    $_SESSION['user_id']  = $uid;
                    $_SESSION['username'] = $in['admin_user'];
                }

                header('Location: install.php?done=1');
                exit;
            } catch (\Throwable $ex) {
                error_log('Kabutar install (migrate): ' . $ex->getMessage());
                $errors[] = 'جدول‌ها ساخته نشدند. مطمئن شو کاربر پایگاه داده اجازه‌ی ساخت جدول دارد.';
            }
        }
    }
}

/** نشانی پایه‌ی سایت را از خود درخواست حدس می‌زند */
function site_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir  = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https://' : 'http://') . $host . $dir;
}

// ───────────────────────────────────────────────────────────────
//  صفحه‌ی پایان
// ───────────────────────────────────────────────────────────────
function show_done_page(): void {
    ?><!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>نصب کامل شد</title><style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{background:#080e17;color:#e6edf4;font-family:system-ui,Tahoma,sans-serif;min-height:100vh;
         display:flex;align-items:center;justify-content:center;padding:24px;line-height:2}
    .b{max-width:500px;background:#0f1c2a;border:1px solid #1e3248;border-radius:16px;padding:34px;text-align:center}
    h1{font-size:22px;margin-bottom:12px;color:#22cc77}
    p{color:#a8c6e0;font-size:14px;margin-bottom:14px}
    .warn{background:rgba(255,79,79,.1);border:1px solid rgba(255,79,79,.3);border-radius:10px;
          padding:13px;color:#ff9b9b;font-size:13px;text-align:right;margin-bottom:18px}
    code{background:#0b1520;padding:3px 8px;border-radius:5px;color:#8fd0ff;direction:ltr;display:inline-block}
    a{display:inline-block;background:linear-gradient(135deg,#3aa4ff,#7c5cfc);color:#fff;
      text-decoration:none;padding:12px 30px;border-radius:10px;font-weight:700}
    </style></head><body><div class="b">
      <div style="font-size:52px">🕊️</div>
      <h1>نصب کامل شد</h1>
      <p>حساب مدیر ساخته شد و وارد شدی.</p>
      <div class="warn">
        <b>یک کار مانده:</b> همین حالا فایل <code>install.php</code> را از سرور پاک کن.
        تا وقتی آن‌جاست، اگر روزی جدول کاربران خالی شود کسی می‌تواند دوباره نصبش کند.
      </div>
      <a href="chat.php">رفتن به گفتگو</a>
    </div></body></html><?php
    exit;
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>نصب کبوتر</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{background:#080e17;color:#e6edf4;font-family:system-ui,'Segoe UI',Tahoma,sans-serif;
       min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;line-height:1.9}
  .card{width:100%;max-width:560px;background:#0f1c2a;border:1px solid #1e3248;
        border-radius:18px;padding:32px}
  .logo{text-align:center;margin-bottom:6px;font-size:44px}
  h1{text-align:center;font-size:23px;font-weight:800;margin-bottom:4px}
  .sub{text-align:center;color:#5c7c96;font-size:13px;margin-bottom:24px}
  h2{font-size:14px;color:#7fa3c0;margin:22px 0 10px;padding-bottom:7px;border-bottom:1px solid #1e3248}
  label{display:block;font-size:13px;font-weight:600;margin:12px 0 5px}
  .hint{color:#5c7c96;font-weight:400;font-size:12px}
  input{width:100%;background:#142030;color:#ddeaf8;border:1px solid #1e3248;border-radius:10px;
        padding:11px 13px;font:inherit;font-size:14px;transition:border-color .15s,box-shadow .15s}
  input:focus{outline:none;border-color:#3aa4ff;box-shadow:0 0 0 3px rgba(58,164,255,.18)}
  input::placeholder{color:#41607a}
  .row{display:flex;gap:12px}.row>div{flex:1}
  button{width:100%;margin-top:24px;background:linear-gradient(135deg,#3aa4ff,#7c5cfc);color:#fff;
         border:0;border-radius:11px;padding:14px;font:inherit;font-size:15px;font-weight:800;cursor:pointer}
  button:disabled{opacity:.4;cursor:not-allowed}
  .checks{list-style:none;margin-bottom:6px}
  .checks li{display:flex;align-items:center;gap:9px;font-size:13px;padding:5px 0}
  .checks .ico{width:19px;text-align:center;flex:0 0 19px}
  .checks .ok .ico{color:#22cc77}.checks .bad .ico{color:#ff4f4f}
  .checks .note{margin-inline-start:auto;color:#5c7c96;font-size:12px;direction:ltr}
  .err{background:rgba(255,79,79,.1);border:1px solid rgba(255,79,79,.32);border-radius:11px;
       padding:13px 15px;margin-bottom:16px;font-size:13px;color:#ff9b9b}
  .err ul{margin:0;padding-inline-start:18px}
  .note-box{background:rgba(58,164,255,.07);border:1px solid rgba(58,164,255,.22);border-radius:10px;
            padding:11px 13px;font-size:12.5px;color:#a8c6e0;margin-top:14px}
  code{background:#0b1520;padding:2px 7px;border-radius:5px;color:#8fd0ff;
       font-family:ui-monospace,Consolas,monospace;font-size:12px;direction:ltr;display:inline-block}
</style>
</head>
<body>

<div class="card">
  <div class="logo">🕊️</div>
  <h1>نصب کبوتر</h1>
  <p class="sub">یک فرم، و تمام</p>

  <h2>پیش‌نیازها</h2>
  <ul class="checks">
    <?php foreach ($checks as $c): ?>
      <li class="<?= $c['ok'] ? 'ok' : 'bad' ?>">
        <span class="ico"><?= $c['ok'] ? '✓' : '✕' ?></span>
        <span><?= e($c['label']) ?></span>
        <span class="note"><?= e($c['note']) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php if (!$allOk): ?>
    <div class="err">موارد بالا را درست کن و صفحه را تازه کن.</div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="err"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" autocomplete="off">

    <h2>سایت</h2>
    <label for="sn">نام سایت</label>
    <input id="sn" name="site_name" required value="<?= e($in['site_name']) ?>" placeholder="مثلاً: گفتگوی ما">

    <label for="tg">شعار <span class="hint">(زیر نام نشان داده می‌شود)</span></label>
    <input id="tg" name="tagline" value="<?= e($in['tagline']) ?>">

    <h2>پایگاه داده</h2>
    <label for="dh">میزبان</label>
    <input id="dh" name="db_host" required value="<?= e($in['db_host']) ?>" placeholder="localhost">

    <label for="dn">نام پایگاه داده</label>
    <input id="dn" name="db_name" required value="<?= e($in['db_name']) ?>">

    <div class="row">
      <div>
        <label for="du">کاربر</label>
        <input id="du" name="db_user" required value="<?= e($in['db_user']) ?>">
      </div>
      <div>
        <label for="dp">رمز</label>
        <input id="dp" name="db_pass" type="password" value="">
      </div>
    </div>

    <h2>حساب مدیر</h2>
    <label for="au">نام کاربری <span class="hint">(فقط انگلیسی)</span></label>
    <input id="au" name="admin_user" required pattern="[A-Za-z0-9_]{3,30}"
           value="<?= e($in['admin_user']) ?>" placeholder="admin">

    <div class="row">
      <div>
        <label for="ap">رمز <span class="hint">(حداقل ۸ کاراکتر)</span></label>
        <input id="ap" name="admin_pass" type="password" required minlength="8">
      </div>
      <div>
        <label for="ap2">تکرار رمز</label>
        <input id="ap2" name="admin_pass2" type="password" required minlength="8">
      </div>
    </div>

    <button type="submit" <?= $allOk ? '' : 'disabled' ?>>نصب کن و وارد شو ←</button>

    <div class="note-box">
      کلید رمزنگاری پیام‌های خصوصی خودکار ساخته می‌شود و در فایل تنظیمات می‌نشیند.
      بعد از نصب، <code>install.php</code> را پاک کن.
    </div>
  </form>
</div>

</body>
</html>
