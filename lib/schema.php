<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  ساختار جدول‌ها — هر بار خودکار بررسی و تکمیل می‌شود
 * ═══════════════════════════════════════════════════════════════
 */
declare(strict_types=1);

/**
 * شماره‌ی ساختار. هر بار که جدول یا ستونی به این فایل اضافه شد،
 * این عدد را یکی زیاد کن تا مهاجرت دوباره اجرا شود.
 */
const SCHEMA_VERSION = '5';

/**
 * ساختار جدول‌ها را کامل می‌کند.
 *
 * مهم: تا پیش از این، این تابع در *هر درخواست* ده‌ها دستور ساخت جدول و
 * چند دستور نوشتن روی جدول کاربران اجرا می‌کرد. روی میزبانی اشتراکی این
 * یعنی قفل گرفتن روی جدول کاربران در هر بار باز شدن هر صفحه. حالا فقط
 * وقتی اجرا می‌شود که شماره‌ی ساختار عوض شده باشد.
 */
function auto_migrate(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // اگر ساختار به‌روز است، هیچ کاری نکن — مسیر همیشگی همین است.
    try {
        $stmt = $pdo->query("SELECT `value` FROM site_settings WHERE `key` = 'schema_version'");
        if ($stmt && (string)$stmt->fetchColumn() === SCHEMA_VERSION) return;
    } catch (\Throwable $e) {
        // جدول تنظیمات هنوز نیست — یعنی نصب تازه است، ادامه بده
    }

    try {
        // ── جدول users ──
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(30) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // اضافه کردن ستون‌ها با چک وجود (برای MySQL قدیمی)
        $addCol = function(string $table, string $col, string $def) use ($pdo): void {
            try {
                $r = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
                if ($r && $r->rowCount() > 0) return;
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
            } catch (\Throwable $e) {}
        };

        $addCol('users','display_name',         'VARCHAR(100) NULL DEFAULT NULL');
        $addCol('users','bio',                  'TEXT NULL DEFAULT NULL');
        $addCol('users','avatar_token',          'VARCHAR(64) NULL DEFAULT NULL');
        $addCol('users','last_active',           'DATETIME NULL DEFAULT NULL');
        $addCol('users','is_admin',              'TINYINT(1) NOT NULL DEFAULT 0');
        $addCol('users','is_vip',                'TINYINT(1) NOT NULL DEFAULT 0');
        $addCol('users','vip_label',             'VARCHAR(30) NULL DEFAULT NULL');
        $addCol('users','is_invisible',          'TINYINT(1) NOT NULL DEFAULT 0');
        $addCol('users','notify_mode',           "ENUM('all','mentions','none') NOT NULL DEFAULT 'all'");
        $addCol('users','banned_until',          'DATETIME NULL DEFAULT NULL');
        $addCol('users','created_at',            'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

        // ── جدول rooms ──
        $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(60) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $addCol('rooms','password_hash',        'VARCHAR(255) NULL DEFAULT NULL');
        $addCol('rooms','invite_token',         'VARCHAR(48) NULL DEFAULT NULL');
        $addCol('rooms','vip_only',              'TINYINT(1) NOT NULL DEFAULT 0');
        $addCol('rooms','slow_mode_seconds',     'INT NOT NULL DEFAULT 0');
        $addCol('rooms','created_by',            'INT UNSIGNED NULL DEFAULT NULL');
        $addCol('rooms','created_at',            'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

        // ── جدول room_members ──
        $pdo->exec("CREATE TABLE IF NOT EXISTS room_members (
            room_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (room_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $addCol('room_members','last_read_id',   'INT UNSIGNED NULL DEFAULT NULL');
        $addCol('room_members','joined_at',      'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        $addCol('room_members','muted_until',    'DATETIME NULL DEFAULT NULL');

        // ── جدول messages ──
        $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sender_id INT UNSIGNED NOT NULL,
            room_id INT UNSIGNED NULL DEFAULT NULL,
            body TEXT NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $addCol('messages','recipient_id',       'INT UNSIGNED NULL DEFAULT NULL');
        $addCol('messages','reply_to_id',        'INT UNSIGNED NULL DEFAULT NULL');
        $addCol('messages','forwarded_from_id',  'INT UNSIGNED NULL DEFAULT NULL');
        $addCol('messages','file_token',         'VARCHAR(32) NULL DEFAULT NULL');
        $addCol('messages','file_name',          'VARCHAR(255) NULL DEFAULT NULL');
        $addCol('messages','file_type',          'VARCHAR(100) NULL DEFAULT NULL');
        $addCol('messages','file_size',          'INT UNSIGNED NULL DEFAULT NULL');
        $addCol('messages','audio_duration',     'DECIMAL(10,2) NULL DEFAULT NULL');
        $addCol('messages','pinned',             'TINYINT(1) NOT NULL DEFAULT 0');
        $addCol('messages','pinned_at',          'DATETIME NULL DEFAULT NULL');
        $addCol('messages','pinned_by',          'INT UNSIGNED NULL DEFAULT NULL');
        $addCol('messages','edited_at',          'DATETIME NULL DEFAULT NULL');
        $addCol('messages','deleted_at',         'DATETIME NULL DEFAULT NULL');
        $addCol('messages','deleted_by',         'INT UNSIGNED NULL DEFAULT NULL');
        $addCol('messages','read_at',            'DATETIME NULL DEFAULT NULL');

        // ── جداول کمکی ──
        $pdo->exec("CREATE TABLE IF NOT EXISTS message_reactions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            emoji VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_reaction (message_id, user_id, emoji),
            KEY idx_message_id (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS room_typing (
            room_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (room_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS private_typing (
            sender_id INT UNSIGNED NOT NULL,
            recipient_id INT UNSIGNED NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (sender_id, recipient_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS room_bans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            room_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            banned_by INT UNSIGNED NOT NULL,
            banned_until DATETIME NULL,
            reason VARCHAR(255) NULL,
            banned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_room_ban (room_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS room_roles (
            room_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            role ENUM('owner','moderator','member') NOT NULL DEFAULT 'member',
            assigned_by INT UNSIGNED NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (room_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS user_blocks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            blocker_id INT UNSIGNED NOT NULL,
            blocked_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_block (blocker_id, blocked_id),
            KEY idx_blocker (blocker_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(512),
            auth VARCHAR(255),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS call_signals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            caller_id INT UNSIGNED NOT NULL,
            receiver_id INT UNSIGNED NOT NULL,
            type VARCHAR(20) NOT NULL,
            data TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_receiver (receiver_id, created_at),
            KEY idx_caller (caller_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
            `key` VARCHAR(64) NOT NULL PRIMARY KEY,
            `value` TEXT NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ── ایندکس‌های مفید ──
        // indexes -- silently ignore if already exist
        try { $pdo->exec('CREATE INDEX idx_messages_room ON messages (room_id, deleted_at, id)'); } catch (\Throwable $e) {}
        try { $pdo->exec('CREATE INDEX idx_messages_dm ON messages (sender_id, recipient_id, deleted_at)'); } catch (\Throwable $e) {}
        try { $pdo->exec('CREATE INDEX idx_users_active ON users (last_active)'); } catch (\Throwable $e) {}

        // ── ستون‌های جدید v23 ──
        $addCol('users','is_banned_permanently', 'TINYINT(1) NOT NULL DEFAULT 0');
        $addCol('users','ban_reason',            'VARCHAR(255) NULL DEFAULT NULL');
        $addCol('call_signals','duration_seconds','INT UNSIGNED NULL DEFAULT NULL');
        $addCol('call_signals','answered_at',    'DATETIME NULL DEFAULT NULL');
        $addCol('call_signals','ended_at',       'DATETIME NULL DEFAULT NULL');

        // ── default site_settings ──
        $defaults = [
            'allow_room_creation'  => '1',
            'allow_registration'   => '1',
            'invite_only'          => '0',
            'maintenance_mode'     => '0',
            'maintenance_message'  => 'سیستم در حال بروزرسانی است. به زودی برمی‌گردیم.',
            'max_file_size_mb'     => '10',
            'max_file_size_vip_mb' => '25',
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO site_settings (`key`,`value`) VALUES (?,?)");
        foreach ($defaults as $k => $v) {
            try { $ins->execute([$k, $v]); } catch (\Throwable $e) {}
        }


        // ══════════════════════════════════════════════════════
        //  جدول‌های افزوده‌شده در بازبینی امنیتی
        // ══════════════════════════════════════════════════════

        // سطح دسترسی: 0 کاربر، 1 مدیر، 2 سازنده
        $addCol('users', 'admin_level', 'TINYINT(1) NOT NULL DEFAULT 0');

        // محدودسازی تلاش‌ها — قبلاً در نشست مرورگر بود و با پاک کردن
        // کوکی دور زده می‌شد. حالا ماندگار است.
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            bucket VARCHAR(48) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            first_at INT UNSIGNED NOT NULL DEFAULT 0,
            locked_until INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (bucket),
            KEY idx_locked (locked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // دفتر ثبت‌نام‌ها — برای سقف ساخت حساب از هر نشانی
        $pdo->exec("CREATE TABLE IF NOT EXISTS signup_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            user_id INT UNSIGNED NULL,
            username VARCHAR(30) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ip_time (ip, created_at),
            KEY idx_time (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // دفتر کارهای مدیران — قبلاً هیچ ردی از کار مدیر نمی‌ماند
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id INT UNSIGNED NULL,
            admin_name VARCHAR(100) NULL,
            action VARCHAR(60) NOT NULL,
            target_user_id INT UNSIGNED NULL,
            detail VARCHAR(500) NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_time (created_at),
            KEY idx_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");


        // تاریخچه‌ی تماس‌ها.
        // جدول call_signals عمداً گذرا است — call.php هر سیگنال قدیمی‌تر از
        // ۳۰ ثانیه را پاک می‌کند. برای همین لاگ تماس پنل همیشه خالی بود.
        // تاریخچه این‌جا می‌ماند و پاک نمی‌شود.
        $pdo->exec("CREATE TABLE IF NOT EXISTS call_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            caller_id INT UNSIGNED NOT NULL,
            receiver_id INT UNSIGNED NOT NULL,
            is_video TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('ringing','answered','rejected','missed','busy','ended') NOT NULL DEFAULT 'ringing',
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            answered_at DATETIME NULL,
            ended_at DATETIME NULL,
            duration_seconds INT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY idx_started (started_at),
            KEY idx_caller (caller_id, started_at),
            KEY idx_receiver (receiver_id, started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");


        // ── گروه پیش‌فرض ──
        // در نصب تازه هیچ گروهی وجود ندارد، پس اولین کاربر وارد صفحه‌ی
        // خالی می‌شد. اگر هیچ گروهی نیست، یکی بساز.
        try {
            $roomCount = (int)$pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn();
            if ($roomCount === 0 && function_exists('get_default_room_id')) {
                get_default_room_id($pdo);
            }
        } catch (\Throwable $e) {}

        // ── جا انداختن سطح دسترسی روی داده‌ی موجود ──
        try {
            // هر کس تا حالا مدیر بوده، دست‌کم سطح ۱ می‌گیرد
            $pdo->exec('UPDATE users SET admin_level = 1 WHERE is_admin = 1 AND admin_level < 1');
            // اگر هیچ سازنده‌ای نیست، قدیمی‌ترین مدیر سازنده می‌شود
            $hasOwner = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE admin_level >= 2')->fetchColumn();
            if ($hasOwner === 0) {
                $pdo->exec('UPDATE users SET admin_level = 2
                            WHERE is_admin = 1 ORDER BY id ASC LIMIT 1');
            }
        } catch (\Throwable $e) {}

        // ساختار کامل شد — نشانه‌گذاری کن تا درخواست‌های بعدی رد شوند
        $pdo->prepare("INSERT INTO site_settings (`key`,`value`) VALUES ('schema_version', ?)
                       ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()")
            ->execute([SCHEMA_VERSION]);

    } catch (\Throwable $e) {
        // اگر مهاجرت نیمه‌کاره ماند، شماره ثبت نمی‌شود و دفعه‌ی بعد دوباره تلاش می‌کند
        error_log('auto_migrate error: ' . $e->getMessage());
    }
}

