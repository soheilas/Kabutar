<?php
require_once __DIR__ . '/config.php';

// maintenance mode — حتی برای کاربران login نشده
$maintenanceMode = site_setting('maintenance_mode') === '1';
if ($maintenanceMode && empty($_SESSION['user_id'])) {
    check_maintenance();
}

if (!empty($_SESSION['user_id'])) {
    $redirect = $_SESSION['redirect_after_login'] ?? 'chat.php';
    unset($_SESSION['redirect_after_login']);
    // اگه invite link داره، به join.php بره
    if (!empty($_GET['invite'])) {
        $redirect = 'join.php?invite=' . urlencode($_GET['invite']);
    }
    header('Location: ' . $redirect);
    exit;
}

// ذخیره invite برای بعد از لاگین
if (!empty($_GET['invite'])) {
    $_SESSION['redirect_after_login'] = 'join.php?invite=' . urlencode($_GET['invite']);
}

$csrf  = csrf_token();
// نصب تازه: تا وقتی هیچ حسابی نیست، به صفحه‌ی راه‌اندازی برو
try {
    if ((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        header('Location: install.php');
        exit;
    }
} catch (\Throwable $e) {}

$error = '';
if (!empty($_GET['banned'])) {
    $error = 'حساب شما مسدود شده است. برای اطلاعات بیشتر با مدیر تماس بگیرید.';
}
$clientIp = client_ip();
$rlKey    = 'auth_' . $clientIp;
rate_limit_gc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token  = $_POST['csrf'] ?? '';

    if (!csrf_is_valid($token)) {
        $error = 'درخواست نامعتبر است.';
    } elseif (!rate_limit_check($rlKey)) {
        $secs  = rate_limit_remaining_seconds($rlKey);
        $mins  = (int)ceil($secs / 60);
        $error = "تعداد تلاش‌های مجاز تجاوز شد. لطفاً {$mins} دقیقه دیگر امتحان کنید.";
    } else {
        $pdo = db();
        if ($action === 'register') {
            $hasInviteInSession = !empty($_SESSION['redirect_after_login']) && strpos($_SESSION['redirect_after_login'], 'join.php') !== false;
            $hasInviteInPost   = !empty($_POST['invite_token']);
            if (!FEATURE_REGISTRATION && !$hasInviteInSession && !$hasInviteInPost) {
                $error = 'ثبت‌نام جدید در حال حاضر غیرفعال است.';
            } elseif (site_setting('allow_registration', '1') !== '1' && !$hasInviteInSession && !$hasInviteInPost) {
                $error = 'ثبت‌نام جدید در حال حاضر غیرفعال است.';
            } elseif (site_setting('invite_only', '0') === '1' && !$hasInviteInSession && !$hasInviteInPost) {
                $error = 'ثبت‌نام فقط با لینک دعوت امکانپذیر است.';
            } elseif (register_quota_exceeded($clientIp)) {
                // سقف روزانه‌ی ساخت حساب از یک نشانی — جلوی ساخت انبوه حساب را می‌گیرد
                $error = 'از این اتصال به تعداد کافی حساب ساخته شده است. فردا دوباره تلاش کنید.';
                rate_limit_fail($rlKey);
            } else {
            $username = trim($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
                $error = 'نام کاربری باید ۳ تا ۳۰ کاراکتر و فقط شامل حروف انگلیسی، اعداد و زیرخط باشد.';
            } elseif (strlen($password) < REGISTER_MIN_PASSWORD) {
                $error = 'رمز عبور باید حداقل ' . strtr((string)REGISTER_MIN_PASSWORD, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']) . ' کاراکتر باشد.';
            } else {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
                $stmt->execute([$username]);
                if ($stmt->fetchColumn()) {
                    $error = 'این نام کاربری قبلاً ثبت شده است.';
                    rate_limit_fail($rlKey);
                } else {
                    try {
                        $pdo->beginTransaction();

                        // اولین حساب سایت، سازنده می‌شود. بدون این، تازه‌نصب‌کننده
                        // مجبور بود دستی در پایگاه داده دستور بزند تا مدیر شود.
                        $isFirstAccount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;

                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, is_admin, admin_level)
                                               VALUES (?, ?, ?, ?)');
                        $stmt->execute([
                            $username, $hash,
                            $isFirstAccount ? 1 : 0,
                            $isFirstAccount ? ADMIN_LEVEL_OWNER : ADMIN_LEVEL_NONE,
                        ]);
                        $userId = (int)$pdo->lastInsertId();
                        // اگه گروه عمومی وجود داشت، عضوش کن (بدون auto-create)
                        try {
                            $defRoom = $pdo->prepare('SELECT id FROM rooms WHERE name = ? LIMIT 1');
                            $defRoom->execute([DEFAULT_ROOM_NAME]);
                            $roomId = (int)($defRoom->fetchColumn() ?: 0);
                            if ($roomId > 0) {
                                $pdo->prepare('INSERT IGNORE INTO room_members (room_id, user_id) VALUES (?, ?)')->execute([$roomId, $userId]);
                            }
                        } catch (\Throwable $e) {}
                        $pdo->commit();
                        // شمارنده را صفر نمی‌کنیم — نسخه‌ی قبلی این کار را می‌کرد
                        // و ساخت حساب عملاً بی‌سقف می‌شد.
                        log_signup($clientIp, $userId, $username);
                        session_regenerate_id(true);
                        $_SESSION['user_id']  = $userId;
                        $_SESSION['username'] = $username;
                        header('Location: chat.php');
                        exit;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $error = 'ثبت نام ناموفق بود. لطفاً دوباره تلاش کنید.';
                    }
                }
            }
            } // end invite_only/allow_registration check
        } elseif ($action === 'login') {
            $username = trim($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $stmt = $pdo->prepare('SELECT id, password_hash, banned_until FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($password, $row['password_hash'])) {
                $error = 'نام کاربری یا رمز عبور اشتباه است.';
                rate_limit_fail($rlKey);
            } elseif (!empty($row['banned_until']) && strtotime($row['banned_until']) > time()) {
                $until = date('H:i', strtotime($row['banned_until']));
                $error = "حساب شما تا ساعت {$until} مسدود است.";
            } else {
                rate_limit_reset($rlKey);
                session_regenerate_id(true);
                $_SESSION['user_id']  = (int)$row['id'];
                $_SESSION['username'] = $username;
                header('Location: chat.php');
                exit;
            }
        }
    }
}
$v = fn(string $p) => h(asset_version($p));
?>
<?php
$fa = static fn(int $n): string => strtr((string)$n, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <title><?= h(SITE_NAME) ?> — ورود</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#080e17">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="پیام‌رسان">
  <link rel="manifest" href="manifest.php">
  <link rel="icon" type="image/png" href="favicon.png">
  <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
  <link rel="stylesheet" href="assets/fonts/fonts.css?v=<?= $v('assets/fonts/fonts.css') ?>">
  <link rel="stylesheet" href="assets/auth.css?v=<?= $v('assets/auth.css') ?>">
</head>
<body>
  <div class="auth-bg">
    <div class="auth-blobs">
      <div class="blob blob-1"></div>
      <div class="blob blob-2"></div>
      <div class="blob blob-3"></div>
    </div>
    <div class="auth-wrap">
      <div class="auth-brand">
        <div class="auth-logo">
          <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="20" cy="20" r="20" fill="url(#lg1)"/>
            <path d="M10 14h20M10 20h14M10 26h17" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>
            <defs><linearGradient id="lg1" x1="0" y1="0" x2="40" y2="40" gradientUnits="userSpaceOnUse">
              <stop stop-color="#3aa4ff"/><stop offset="1" stop-color="#7c5cfc"/>
            </linearGradient></defs>
          </svg>
        </div>
        <h1 class="auth-title"><?= h(SITE_NAME) ?></h1>
        <p class="auth-subtitle"><?= h(cfg('site.tagline', 'پیام‌رسان امن و خصوصی')) ?></p>
      </div>

      <?php if ($error): ?>
        <div class="auth-error">
          <svg viewBox="0 0 20 20" fill="none" width="16" height="16"><circle cx="10" cy="10" r="9" stroke="#ff6b6b" stroke-width="1.5"/><path d="M10 6v5M10 13.5v.5" stroke="#ff6b6b" stroke-width="1.8" stroke-linecap="round"/></svg>
          <?= h($error) ?>
        </div>
      <?php endif; ?>

      <div class="auth-tabs">
        <button class="auth-tab active" data-tab="login">ورود</button>
        <button class="auth-tab" data-tab="register">ثبت نام</button>
      </div>

      <!-- فرم ورود -->
      <form method="post" class="auth-form" id="form-login" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="login">
        <div class="auth-field">
          <label for="login-username">نام کاربری</label>
          <div class="field-wrap">
            <svg class="field-icon" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="7" r="3.5" stroke="currentColor" stroke-width="1.5"/><path d="M3 17c0-3.314 3.134-6 7-6s7 2.686 7 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            <input type="text" id="login-username" name="username" placeholder="نام کاربری انگلیسی" required autocomplete="username">
          </div>
        </div>
        <div class="auth-field">
          <label for="login-password">رمز عبور</label>
          <div class="field-wrap">
            <svg class="field-icon" viewBox="0 0 20 20" fill="none"><rect x="4" y="9" width="12" height="9" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M7 9V6a3 3 0 0 1 6 0v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="13.5" r="1" fill="currentColor"/></svg>
            <input type="password" id="login-password" name="password" placeholder="رمز عبور" required autocomplete="current-password">
            <button type="button" class="toggle-pass" tabindex="-1" aria-label="نمایش رمز">
              <svg class="eye-icon" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z" stroke="currentColor" stroke-width="1.4"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.4"/></svg>
            </button>
          </div>
        </div>
        <button type="submit" class="auth-btn">
          <span>ورود به حساب</span>
          <svg viewBox="0 0 20 20" fill="none" width="18"><path d="M4 10h12M11 5l5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
      </form>

      <!-- فرم ثبت نام -->
      <form method="post" class="auth-form hidden" id="form-register" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="register">
        <div class="auth-field">
          <label for="reg-username">نام کاربری <span class="hint-small">(۳-۳۰ کاراکتر انگلیسی)</span></label>
          <div class="field-wrap">
            <svg class="field-icon" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="7" r="3.5" stroke="currentColor" stroke-width="1.5"/><path d="M3 17c0-3.314 3.134-6 7-6s7 2.686 7 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            <input type="text" id="reg-username" name="username" placeholder="مثال: ali_2030" required pattern="[A-Za-z0-9_]{3,30}" autocomplete="username">
          </div>
        </div>
        <div class="auth-field">
          <label for="reg-password">رمز عبور <span class="hint-small">(حداقل <?= $fa(REGISTER_MIN_PASSWORD) ?> کاراکتر)</span></label>
          <div class="field-wrap">
            <svg class="field-icon" viewBox="0 0 20 20" fill="none"><rect x="4" y="9" width="12" height="9" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M7 9V6a3 3 0 0 1 6 0v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="13.5" r="1" fill="currentColor"/></svg>
            <input type="password" id="reg-password" name="password" placeholder="رمز قوی انتخاب کنید" required autocomplete="new-password">
            <button type="button" class="toggle-pass" tabindex="-1" aria-label="نمایش رمز">
              <svg class="eye-icon" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z" stroke="currentColor" stroke-width="1.4"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.4"/></svg>
            </button>
          </div>
          <div class="strength-bar"><div id="strength-fill" class="strength-fill"></div></div>
          <span id="strength-label" class="strength-label"></span>
        </div>
        <button type="submit" class="auth-btn">
          <span>ایجاد حساب</span>
          <svg viewBox="0 0 20 20" fill="none" width="18"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        </button>
      </form>

      <p class="auth-footer">نام کاربری و رمز فقط انگلیسی — رمز حداقل <?= $fa(REGISTER_MIN_PASSWORD) ?> کاراکتر</p>
    </div>
  </div>

<script>
(function(){
  // سوئیچ تب
  const tabs = document.querySelectorAll('.auth-tab');
  const forms = { login: document.getElementById('form-login'), register: document.getElementById('form-register') };
  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      tabs.forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      Object.values(forms).forEach(f => f.classList.add('hidden'));
      const target = tab.dataset.tab;
      if (forms[target]) forms[target].classList.remove('hidden');
    });
  });

  // نمایش/مخفی رمز
  document.querySelectorAll('.toggle-pass').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = btn.closest('.field-wrap').querySelector('input[type="password"],input[type="text"]');
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
      btn.querySelector('svg').style.opacity = input.type === 'text' ? '1' : '0.5';
    });
  });

  // قدرت رمز
  const passInput = document.getElementById('reg-password');
  const strengthFill = document.getElementById('strength-fill');
  const strengthLabel = document.getElementById('strength-label');
  if (passInput) {
    passInput.addEventListener('input', () => {
      const v = passInput.value;
      let score = 0;
      if (v.length >= 6) score++;
      if (v.length >= 10) score++;
      if (/[A-Z]/.test(v)) score++;
      if (/[0-9]/.test(v)) score++;
      if (/[^A-Za-z0-9]/.test(v)) score++;
      const pct = (score / 5) * 100;
      strengthFill.style.width = pct + '%';
      const colors = ['#ff4444','#ff8800','#ffcc00','#88cc00','#22cc66'];
      const labels = ['خیلی ضعیف','ضعیف','متوسط','قوی','بسیار قوی'];
      strengthFill.style.background = colors[score - 1] || '#333';
      strengthLabel.textContent = v.length ? (labels[score - 1] || '') : '';
      strengthLabel.style.color = colors[score - 1] || 'transparent';
    });
  }

  // اگه خطا بود، تب مناسب رو باز کن
  const error = document.querySelector('.auth-error');
  const action = document.querySelector('input[name="action"]');
  if (error && action) {
    const act = action.value;
    tabs.forEach(t => t.classList.remove('active'));
    Object.values(forms).forEach(f => f.classList.add('hidden'));
    const matchTab = document.querySelector(`.auth-tab[data-tab="${act}"]`);
    if (matchTab) matchTab.classList.add('active');
    if (forms[act]) forms[act].classList.remove('hidden');
  }
})();
</script>
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').catch(() => {});
}
</script>
</body>
</html>
