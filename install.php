<?php
/**
 * 初始化向导（三步安装）
 *  Step 1: PHP 环境检测（版本、扩展、目录权限）
 *  Step 2: 选择数据库类型 + 填写连接信息（SQLite / MySQL）
 *  Step 3: 管理员账号 + 执行安装
 * 安装完成后访问本页会提示「已安装」。
 */
require_once __DIR__ . '/config.php';

// 自带兜底函数（用独特命名 _install_ 前缀，避免引入 settings.php → functions.php 时与真实函数重名报错）
if (!function_exists('_install_hash_password')) {
    function _install_hash_password($plain) { return password_hash($plain, PASSWORD_DEFAULT); }
}
if (!function_exists('_install_e')) {
    function _install_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('_install_exec_sql_file')) {
    /** 读取 SQL 文件并逐条执行（移除 -- 注释行后按分号拆分，兼容 SQLite/MySQL） */
    function _install_exec_sql_file($pdo, $path) {
        $sql = @file_get_contents($path);
        if ($sql === false) throw new Exception("无法读取 SQL 文件：{$path}");
        $lines = explode("\n", $sql);
        $clean = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || strpos($t, '--') === 0) continue;
            $clean[] = $line;
        }
        foreach (explode(';', implode("\n", $clean)) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;
            $pdo->exec($stmt);
        }
    }
}

/** 生成 PHP 环境检测报告 */
function install_env_check() {
    $checks = [];
    $overallPass = true;
    $overallWarn = false;

    // 1. PHP 版本（≥7.4 必需；≥8.0 推荐）
    $verOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $verRec = version_compare(PHP_VERSION, '8.0.0', '>=');
    $checks['version'] = [
        'label' => 'PHP 版本（需 ≥ 7.4，推荐 ≥ 8.0）',
        'value' => PHP_VERSION,
        'pass'  => $verOk,
        'warn'  => !$verRec,
        'tip'   => $verOk ? ($verRec ? '' : '当前版本可运行，推荐升级到 PHP 8.0+ 获得更好性能与安全性')
                        : 'PHP 版本过低，无法运行本系统，请升级到 7.4 或更高版本',
    ];
    if (!$verOk) $overallPass = false;
    if (!$verRec) $overallWarn = true;

    // 2. 核心必需扩展（必须全部满足）
    $requiredExts = [
        'PDO'       => 'PDO 数据库抽象层',
        'curl'      => 'cURL（企业微信 API 通信）',
        'json'      => 'JSON 编解码',
        'openssl'   => 'OpenSSL（HTTPS 与加密）',
        'fileinfo'  => 'FileInfo（文件类型识别）',
        'zip'       => 'Zip（解析 .xlsx Excel 文件）',
        'simplexml' => 'SimpleXML（解析 Excel XML）',
        'xml'       => 'XML（XML 基础解析）',
        'session'   => 'Session（用户会话）',
    ];
    foreach ($requiredExts as $ext => $desc) {
        $ok = extension_loaded($ext);
        $checks["ext_$ext"] = [
            'label' => "扩展 {$desc}",
            'value' => $ok ? '已安装' : '未安装',
            'pass'  => $ok,
            'tip'   => $ok ? '' : "缺少必需扩展：{$ext}，请在 php.ini 中启用或联系主机服务商安装",
        ];
        if (!$ok) $overallPass = false;
    }

    // 3. 数据库扩展（至少满足其中一种）
    $sqliteOk = extension_loaded('pdo_sqlite');
    $mysqlOk  = extension_loaded('pdo_mysql');
    $dbAnyOk  = $sqliteOk || $mysqlOk;
    $checks['ext_pdo_drivers'] = [
        'label' => '数据库驱动（pdo_sqlite 或 pdo_mysql 至少一种）',
        'value' => 'pdo_sqlite: ' . ($sqliteOk ? '✅' : '❌') . '  pdo_mysql: ' . ($mysqlOk ? '✅' : '❌'),
        'pass'  => $dbAnyOk,
        'tip'   => $dbAnyOk ? ($sqliteOk && $mysqlOk ? '两种都可用，安装时可自由选择'
                                                       : ($sqliteOk ? '仅可用 SQLite（若需要 MySQL，请启用 pdo_mysql）'
                                                                   : '仅可用 MySQL/MariaDB（若需要 SQLite，请启用 pdo_sqlite）'))
                            : '两种数据库驱动均未安装，至少启用其中一种',
    ];
    if (!$dbAnyOk) $overallPass = false;

    // 4. 推荐扩展（不满足可运行，但体验有差异）
    $recommended = [
        'mbstring' => 'Mbstring（中文多字节处理）',
        'gd'       => 'GD（图形处理，后续生成缩略图/验证码会用到）',
    ];
    foreach ($recommended as $ext => $desc) {
        $ok = extension_loaded($ext);
        $checks["rec_$ext"] = [
            'label' => "推荐扩展 {$desc}",
            'value' => $ok ? '已安装' : '未安装',
            'pass'  => true,
            'warn'  => !$ok,
            'tip'   => $ok ? '' : "建议安装 $ext，未安装会有回退方案但体验有差异",
        ];
        if (!$ok) $overallWarn = true;
    }

    // 5. 关键函数禁用检查（常见被禁用的函数）
    $requiredFns = ['curl_exec', 'file_get_contents', 'move_uploaded_file', 'password_hash', 'password_verify'];
    $disabledStr = (string)ini_get('disable_functions');
    $disabledArr = array_map('trim', explode(',', $disabledStr));
    foreach ($requiredFns as $fn) {
        $ok = !in_array($fn, $disabledArr, true) && function_exists($fn);
        $checks["fn_$fn"] = [
            'label' => "函数 {$fn}() 可用",
            'value' => $ok ? '可用' : '已被禁用',
            'pass'  => $ok,
            'tip'   => $ok ? '' : "必需函数 {$fn}() 被 php.ini disable_functions 禁用，请移除或联系服务商",
        ];
        if (!$ok) $overallPass = false;
    }

    // 6. 目录权限
    $webRoot = __DIR__;
    $paths = [
        'root'    => [$webRoot,                           '项目根目录（写入 config.local.php）', true],
        'uploads' => [$webRoot . '/uploads',               'uploads 目录（上传 Logo / Excel）', false],
        'logs'    => [$webRoot . '/logs',                  'logs 目录（日志记录）', false],
    ];
    foreach ($paths as $k => list($path, $label, $mustWrite)) {
        if (!is_dir($path)) {
            $old = error_reporting(0);
            $made = @mkdir($path, 0755, true);
            error_reporting($old);
            if (!$made && !is_dir($path)) {
                $ok = false;
                $msg = "无法创建 {$path}，请手动创建或开放上级目录权限";
            } else {
                $ok = is_writable($path);
                $msg = $ok ? '' : "目录已创建但不可写：{$path}";
            }
        } else {
            $ok = is_writable($path);
            $msg = $ok ? '' : "目录不可写：{$path}（建议 chmod 755 或调整属主）";
        }
        $isFail = $mustWrite && !$ok;
        $isWarn = !$mustWrite && !$ok;
        $checks["dir_$k"] = [
            'label' => "目录 {$label}",
            'value' => $ok ? '可写' : '不可写',
            'pass'  => !$isFail,
            'warn'  => $isWarn,
            'tip'   => $msg,
        ];
        if ($isFail) $overallPass = false;
        if ($isWarn) $overallWarn = true;
    }

    return [
        'checks'      => $checks,
        'overallPass' => $overallPass,
        'overallWarn' => $overallWarn,
        'sqliteOk'    => $sqliteOk,
        'mysqlOk'     => $mysqlOk,
    ];
}

$env = install_env_check();

// 当前步骤
$step = 1; // 1=环境检测 2=数据库选择 3=管理员+执行
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $s = (int)($_POST['_step'] ?? 1);
    if (in_array($s, [1,2,3], true)) $step = $s;
}

$error = '';
$success = '';

/** 是否已安装？ */
function install_is_done() {
    if (!file_exists(__DIR__ . '/config.local.php')) return false;
    try {
        require_once __DIR__ . '/db.php';
        require_once __DIR__ . '/settings.php';
        return get_setting('installed') === '1';
    } catch (Throwable $e) {
        return false;
    }
}

$alreadyDone = install_is_done();
if ($alreadyDone) {
    try {
        require_once __DIR__ . '/db.php';
        get_db(); // 触发自动升级（幂等）
    } catch (Throwable $e) {}
}

// ========== Step 2 提交：校验数据库连接 ==========
$dbType = 'sqlite';
$dbConfig = [];
$dbTested  = false;
$dbTestOk  = false;
$dbTestMsg = '';

// 只有 POST 中明确携带 db_type 时才解析配置并测试连接
// 避免 Step 1→Step 2 或 Step 3→Step 2 跳转时（无 db_type）自动以 sqlite 默认值触发测试
if ($step >= 2 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_type'])) {
    $dbType = $_POST['db_type'] === 'mysql' ? 'mysql' : 'sqlite';

    if ($dbType === 'sqlite') {
        $dataDir = trim($_POST['data_dir'] ?? '');
        if ($dataDir === '') {
            $dataDir = dirname(__DIR__) . '/salary_data';
        }
        $dataDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dataDir), DIRECTORY_SEPARATOR);
        $dbConfig = ['data_dir' => $dataDir, 'db_file' => $dataDir . DIRECTORY_SEPARATOR . 'app.sqlite3'];
    } else {
        $dbConfig = [
            'host' => trim($_POST['db_host'] ?? '127.0.0.1'),
            'port' => (int)($_POST['db_port'] ?? 3306),
            'name' => trim($_POST['db_name'] ?? ''),
            'user' => trim($_POST['db_user'] ?? ''),
            'pass' => (string)($_POST['db_pass'] ?? ''),
        ];
    }

    // 测试数据库连接
    $dbTested = true;
    try {
        if ($dbType === 'sqlite') {
            $dir = $dbConfig['data_dir'];
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                throw new Exception('数据目录不存在或不可写：' . $dir . '。请手动创建并开放 PHP 写权限（chmod 755）。');
            }
            $pdo = new PDO('sqlite:' . $dbConfig['db_file'], null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('SELECT 1');
            $dbTestOk  = true;
            $dbTestMsg = '✅ SQLite 连接测试通过，数据库文件：' . $dbConfig['db_file'];
        } else {
            foreach (['host','name','user'] as $k) {
                if ($dbConfig[$k] === '') {
                    throw new Exception('MySQL/MariaDB 参数不完整（主机、库名、用户名必填）。');
                }
            }
            if ($dbConfig['port'] <= 0) {
                throw new Exception('MySQL/MariaDB 端口必须大于 0。');
            }
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $dbConfig['host'], $dbConfig['port'], $dbConfig['name']);
            $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET NAMES utf8mb4");
            $pdo->exec('SELECT 1');
            $dbTestOk  = true;
            $dbTestMsg = '✅ MySQL/MariaDB 连接测试通过，库：' . $dbConfig['name'] . '@' . $dbConfig['host'] . ':' . $dbConfig['port'];
        }
    } catch (Throwable $e) {
        $dbTestOk  = false;
        $dbTestMsg = '❌ 连接失败：' . $e->getMessage();
    }

    // 仅有「下一步」按钮并且测试通过时，才允许进入 step 3
    $action = $_POST['_action'] ?? '';
    if ($action === 'install') {
        $step = 3;
    }
}

// ========== Step 3 提交：执行安装 ==========
// 重要：必须 _step==='3' 才进入 Step 3 提交处理。
//   如果是 Step 2 的 POST（_step==='2'），先由 Step 2 代码块解析 db_type+dbConfig 并把 $step 升到 3，
//   但此时不直接执行安装，仅渲染 Step 3 页面。防止同一个请求里「Step 2 设好 mysql」后又被 Step 3 代码块
//   因缺少 _db_type 字段被默认 sqlite 覆盖，产生「选 mysql 最终 sqlite」幽灵 BUG。
$cronKeyOut = '';
$_step_str = (string)($_POST['_step'] ?? '');
if ($step === 3 && $_step_str === '3' && $_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyDone) {
    // 从隐藏字段恢复数据库配置（Step 3 表单才会带 _db_type / _db_* 前缀字段）
    $dbType = ($_POST['_db_type'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
    if ($dbType === 'sqlite') {
        $dbConfig = [
            'data_dir' => trim($_POST['_data_dir'] ?? ''),
            'db_file'  => trim($_POST['_db_file'] ?? ''),
        ];
    } else {
        $dbConfig = [
            'host' => trim($_POST['_db_host'] ?? '127.0.0.1'),
            'port' => (int)($_POST['_db_port'] ?? 3306),
            'name' => trim($_POST['_db_name'] ?? ''),
            'user' => trim($_POST['_db_user'] ?? ''),
            'pass' => (string)($_POST['_db_pass'] ?? ''),
        ];
    }

    $admin_login = trim($_POST['admin_login'] ?? '');
    $admin_pass  = $_POST['admin_pass'] ?? '';
    $jwt_secret  = trim($_POST['jwt_secret'] ?? '');
    if ($jwt_secret === '') $jwt_secret = bin2hex(random_bytes(32));
    $cron_key   = bin2hex(random_bytes(24));
    $cronKeyOut = $cron_key;

    if (!$env['overallPass']) {
        $error = '第 1 步环境检测未通过，请先解决失败项。';
    } elseif (!$admin_login || !$admin_pass) {
        $error = '请填写管理员后台登录账号与密码';
    } elseif (strlen($admin_pass) < 8 || !preg_match('/[A-Za-z]/', $admin_pass) || !preg_match('/[0-9]/', $admin_pass)) {
        $error = '管理员密码至少 8 位，需包含字母和数字';
    } elseif (strlen($jwt_secret) < 16) {
        $error = 'JWT 密钥长度至少 16 位';
    } else {
        try {
            // 1) 连接数据库
            if ($dbType === 'sqlite') {
                $dataDir = $dbConfig['data_dir'];
                if (!is_dir($dataDir)) {
                    @mkdir($dataDir, 0755, true);
                }
                if (!is_dir($dataDir) || !is_writable($dataDir)) {
                    throw new Exception('SQLite 数据目录不可写：' . $dataDir);
                }
                $pdo = new PDO('sqlite:' . $dbConfig['db_file'], null, null, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                @$pdo->exec('PRAGMA journal_mode=WAL');
                @$pdo->exec('PRAGMA busy_timeout=5000');
                @$pdo->exec('PRAGMA foreign_keys=OFF');

                // 如果数据目录位于网站根目录内，写入禁止访问规则
                $docRoot  = realpath(__DIR__);
                $realData = realpath($dataDir);
                if ($docRoot !== false && $realData !== false
                    && strpos($realData . DIRECTORY_SEPARATOR, $docRoot . DIRECTORY_SEPARATOR) === 0) {
                    $ht = $dataDir . '/.htaccess';
                    if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
                    $wc = $dataDir . '/web.config';
                    if (!file_exists($wc)) @file_put_contents($wc, "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
                        . "<configuration><system.webServer><security><requestFiltering>"
                        . "<fileExtensions><add fileExtension=\".db\" allowed=\"false\"/><add fileExtension=\".sqlite\" allowed=\"false\"/><add fileExtension=\".sqlite3\" allowed=\"false\"/></fileExtensions>"
                        . "</requestFiltering></security></system.webServer></configuration>");
                }
                @file_put_contents($dataDir . '/index.html', '');

                // 执行建表（完整 17 张表 + 全部字段）
                _install_exec_sql_file($pdo, __DIR__ . '/sql/schema_sqlite.sql');

                $upsertSetting = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON CONFLICT(`key`) DO UPDATE SET `value`=excluded.`value`");
                $upsertUser    = $pdo->prepare("INSERT INTO users (userid, name, role, has_set_password, admin_login, admin_pass) VALUES (?,?,?,?,?,?) ON CONFLICT(userid) DO UPDATE SET role=excluded.role, admin_login=excluded.admin_login, admin_pass=excluded.admin_pass");
            } else {
                // MySQL / MariaDB
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $dbConfig['host'], $dbConfig['port'], $dbConfig['name']);
                $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                $pdo->exec("SET NAMES utf8mb4");
                $pdo->exec("SET time_zone = '+08:00'");
                $pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                // 执行建表（完整 17 张表 + 全部字段）
                _install_exec_sql_file($pdo, __DIR__ . '/sql/schema_mysql.sql');
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                $upsertSetting = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
                $upsertUser    = $pdo->prepare("INSERT INTO users (userid, name, role, has_set_password, admin_login, admin_pass) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role), admin_login=VALUES(admin_login), admin_pass=VALUES(admin_pass)");
            }

            // 2) 写 roles 初始数据
            $roles = [
                ['admin',    '管理员', '分配角色、全局配置'],
                ['finance',  '财务',   '上传工资条、下发'],
                ['hr',       '人事',   '回复员工反馈'],
                ['employee', '员工',   '查看/确认/反馈'],
            ];
            if ($dbType === 'sqlite') {
                $roleIns = $pdo->prepare("INSERT OR IGNORE INTO roles (role_key, role_name, description) VALUES (?,?,?)");
            } else {
                $roleIns = $pdo->prepare("INSERT IGNORE INTO roles (role_key, role_name, description) VALUES (?,?,?)");
            }
            foreach ($roles as $r) $roleIns->execute($r);

            // 3) 写入内置消息模板 + 奖金类型（安装即就绪，无需等待首次访问触发 db_ensure_schema）
            $builtinTemplates = [
                ['salary_send', '工资下发通知（员工）', 'template_card', '{年月}工资条已发布', "亲爱的 {姓名}，{年月} 月薪资已生成。\n请点击卡片查看明细并确认签收：{链接}"],
                ['push_result', '工资下发结果通知（管理员/财务）', 'text', '', '【下发结果】{年}年{月}月 工资条已下发 {成功数} 人（共 {总数} 人）。'],
                ['salary_remind', '工资催办确认通知（员工）', 'template_card', '{年月}工资条待确认', "亲爱的 {姓名}，您还有 {年}年{月}月 工资条尚未确认。\n请点击卡片查看明细并完成签收。"],
                ['feedback_to_hr_salary', '工资反馈通知（人事收）', 'template_card', '新的工资反馈待处理', '员工 {姓名}({账号}) 对 {年月} 工资条提出反馈，请点击查看：{链接}'],
                ['feedback_to_hr_bonus', '奖金反馈通知（人事收）', 'template_card', '新的奖金反馈待处理', '员工 {姓名}({账号}) 对 {年}年{奖金类型} 奖金提出反馈，请点击查看：{链接}'],
                ['feedback_reply_salary', '工资反馈回复通知（员工收）', 'template_card', '【反馈已回复】{年月}工资反馈', '您提交的工资条反馈已由人事回复，点击查看详情：{链接}'],
                ['feedback_reply_bonus', '奖金反馈回复通知（员工收）', 'template_card', '【反馈已回复】{年}年{奖金类型}反馈', '您提交的奖金反馈已由人事回复，点击查看详情：{链接}'],
                ['bonus_push_result', '奖金下发结果通知（管理员/财务）', 'text', '', '【下发结果】{年}年「{奖金类型}」已下发 {成功数} 人（共 {总数} 人）。'],
                ['bonus_send', '奖金下发通知（员工）', 'template_card', '{奖金类型}已发放', "亲爱的 {姓名}，您 {年} 年的 {奖金类型} 已生成。\n请点击卡片查看明细并确认签收：{链接}"],
                ['bonus_remind', '奖金催办确认通知（员工）', 'template_card', '{奖金类型}待确认', "亲爱的 {姓名}，您还有 {年} 年的 {奖金类型} 尚未确认。\n请点击卡片查看明细并完成签收。"],
            ];
            $tplIns = $pdo->prepare(
                $dbType === 'sqlite'
                    ? "INSERT OR IGNORE INTO msg_templates (tpl_key, name, msgtype, title, content) VALUES (?,?,?,?,?)"
                    : "INSERT IGNORE INTO msg_templates (tpl_key, name, msgtype, title, content) VALUES (?,?,?,?,?)"
            );
            foreach ($builtinTemplates as $t) {
                try { $tplIns->execute($t); } catch (Throwable $e) {}
            }
            $btIns = $pdo->prepare(
                $dbType === 'sqlite'
                    ? "INSERT OR IGNORE INTO bonus_types (type_key, type_name, sort_order) VALUES (?,?,0)"
                    : "INSERT IGNORE INTO bonus_types (type_key, type_name, sort_order) VALUES (?,?,0)"
            );
            try { $btIns->execute(['year_end', '年终奖']); } catch (Throwable $e) {}

            // 4) 写 installed 标记 + 管理员
            $upsertSetting->execute(['installed', '1']);
            $upsertUser->execute([$admin_login, $admin_login, 'admin', 0, $admin_login, _install_hash_password($admin_pass)]);

            // 5) 写 config.local.php
            $local = "<?php\n";
            $local .= "// 本文件由 install.php 自动生成；如需重装请删除本文件后访问 install.php\n";
            $local .= "define('DB_TYPE', " . var_export($dbType, true) . ");\n";
            if ($dbType === 'sqlite') {
                $local .= "define('DB_FILE', " . var_export($dbConfig['db_file'], true) . ");\n";
            } else {
                $local .= "define('DB_HOST', " . var_export($dbConfig['host'], true) . ");\n";
                $local .= "define('DB_PORT', " . $dbConfig['port'] . ");\n";
                $local .= "define('DB_NAME', " . var_export($dbConfig['name'], true) . ");\n";
                $local .= "define('DB_USER', " . var_export($dbConfig['user'], true) . ");\n";
                $local .= "define('DB_PASS', " . var_export($dbConfig['pass'], true) . ");\n";
            }
            $local .= "define('JWT_SECRET', " . var_export($jwt_secret, true) . ");\n";
            $local .= "define('CRON_KEY',   " . var_export($cron_key,   true) . ");\n";
            // APP_VERSION 不写入 config.local.php，由 config.php 统一管理，确保版本更新时缓存击穿机制生效

            if (!@file_put_contents(__DIR__ . '/config.local.php', $local)) {
                throw new Exception('无法写入 config.local.php，请确认项目根目录 PHP 有写权限（chmod 755）。');
            }
            @chmod(__DIR__ . '/config.local.php', 0640);

            $success = '🎉 安装成功！请用管理员账号【' . $admin_login . '】登录后台。'
                . "\n数据库类型：" . ($dbType === 'mysql' ? 'MySQL / MariaDB' : 'SQLite（单文件）')
                . "\n\n下一步："
                . "\n  1. 用 admin_login 直接登录后台 → 系统设置 → 填写企业微信参数 + 绑定管理员企微 ID"
                . "\n  2. 通讯录同步 → 分配角色（admin/finance/hr）"
                . "\n  3. 上传 Excel → 推送工资条"
                . "\n\n定时任务(cron.php)访问密钥：{$cron_key}"
                . "\n调用方式：php " . __DIR__ . "/cron.php remind"
                . "\n  或 URL：/cron.php?key={$cron_key}（或请求头 Authorization: Bearer {$cron_key}）";

            $alreadyDone = true;
            $step = 4; // 完成

        } catch (Throwable $e) {
            $error = '安装过程出错：' . $e->getMessage();
            $step = 3; // 回到 Step 3 重新提交
        }
    }
}

// 默认预填的数据库配置
$defaultDataDir = dirname(__DIR__) . '/salary_data';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>工资条系统 - 初始化向导</title>
<style>
 body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f5f5f5;margin:0;padding:16px;color:#333;}
 .wrap{max-width:720px;margin:16px auto;}
 .card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.06);}
 .head{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:8px;}
 h1{font-size:20px;margin:0;}
 .sub{color:#999;font-size:13px;margin:4px 0 0;}
 /* Stepper */
 .stepper{display:flex;align-items:center;justify-content:center;gap:4px;margin-bottom:24px;flex-wrap:wrap;}
 .step{display:flex;align-items:center;gap:8px;}
 .step-circle{width:30px;height:30px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:bold;background:#e5e7eb;color:#6b7280;flex:0 0 30px;}
 .step.active .step-circle{background:#07c160;color:#fff;box-shadow:0 2px 6px rgba(7,193,96,.35);}
 .step.done .step-circle{background:#07c160;color:#fff;}
 .step-label{font-size:13px;color:#6b7280;}
 .step.active .step-label,.step.done .step-label{color:#111;font-weight:600;}
 .step-line{width:40px;height:2px;background:#e5e7eb;}
 .step.done + .step-line, .step.active + .step-line{background:#07c160;}
 label{display:block;font-size:14px;margin:14px 0 6px;}
 input,select{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box;font-size:14px;background:#fff;transition:border-color .15s, box-shadow .15s;outline:none;}
 input:focus,select:focus{border-color:#07c160;box-shadow:0 0 0 3px rgba(7,193,96,.15);}
 .req{color:#e64340;}
 .sec{margin-top:24px;padding-top:16px;border-top:1px solid #eef0f2;font-weight:bold;font-size:15px;}
 .btn-row{display:flex;gap:10px;margin-top:24px;flex-wrap:wrap;}
 .btn{padding:12px 20px;border:none;border-radius:8px;font-size:15px;cursor:pointer;transition:transform .1s,box-shadow .15s;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;}
 .btn-primary{background:#07c160;color:#fff;flex:1;min-width:140px;}
 .btn-primary:hover{box-shadow:0 4px 14px rgba(7,193,96,.35);}
 .btn-primary:active{transform:translateY(1px);}
 .btn-primary:disabled{background:#b2dfc5;cursor:not-allowed;box-shadow:none;}
 .btn-secondary{background:#f3f4f6;color:#374151;}
 .btn-secondary:hover{background:#e5e7eb;}
 .btn-test{background:#fff;border:1px solid #07c160;color:#07c160;flex:0 0 auto;}
 .btn-test:hover{background:#e8f8e8;}
 .btn:disabled{cursor:not-allowed;opacity:.6;}
 .err{background:#fff3f3;color:#e64340;padding:12px;border-radius:8px;font-size:14px;margin-bottom:12px;line-height:1.6;}
 .ok{background:#eafaf0;color:#07c160;padding:12px;border-radius:8px;font-size:14px;margin-bottom:12px;line-height:1.6;}
 .warn{background:#fff8e1;color:#d68a00;padding:12px;border-radius:8px;font-size:14px;margin-bottom:12px;line-height:1.6;}
 .tip{color:#6b7280;font-size:12px;margin-top:4px;line-height:1.55;}
 .badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:13px;font-weight:normal;margin-bottom:10px;}
 .badge.ok{background:#eafaf0;color:#07c160;}
 .badge.todo{background:#fff3f3;color:#e64340;}
 code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:12px;word-break:break-all;}
 /* Env check table */
 .env-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;}
 .env-summary{display:flex;gap:8px;flex-wrap:wrap;}
 .pill{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;}
 .pill-pass{background:#eafaf0;color:#07c160;}
 .pill-warn{background:#fff8e1;color:#d68a00;}
 .pill-fail{background:#fff3f3;color:#e64340;}
 .env-grid{border:1px solid #eef0f2;border-radius:10px;overflow:hidden;}
 .env-row{display:grid;grid-template-columns:1.4fr 1fr 2.2fr auto;padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;gap:10px;align-items:center;}
 .env-row:last-child{border-bottom:none;}
 .env-row:hover{background:#fafbfc;}
 .env-row .lab{color:#374151;font-weight:500;}
 .env-row .val{color:#6b7280;}
 .env-row .tip{margin:0;}
 .env-row .ic{width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;color:#fff;flex:0 0 24px;}
 .ic-pass{background:#07c160;}
 .ic-warn{background:#f59e0b;}
 .ic-fail{background:#ef4444;}
 /* DB type selector */
 .db-tabs{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;}
 .db-tab{border:2px solid #e5e7eb;border-radius:10px;padding:14px;cursor:pointer;transition:all .15s;background:#fff;}
 .db-tab:hover{border-color:#a7e9c0;}
 .db-tab.active{border-color:#07c160;background:#f0fcf4;box-shadow:0 0 0 3px rgba(7,193,96,.12);}
 .db-tab .t{font-weight:bold;font-size:15px;display:flex;align-items:center;gap:6px;}
 .db-tab .d{color:#6b7280;font-size:12px;margin-top:4px;line-height:1.5;}
 .db-tab .lock{display:inline-block;margin-left:4px;padding:1px 8px;border-radius:10px;font-size:11px;background:#eef0f2;color:#6b7280;font-weight:normal;}
 .db-tab.disabled{opacity:.5;cursor:not-allowed;}
 .db-tab.disabled:hover{border-color:#e5e7eb;}
 .mysql-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
 .mysql-grid .full{grid-column:1 / -1;}
 .db-test-msg{margin-top:10px;padding:10px 12px;border-radius:8px;font-size:13px;}
 .db-test-msg.ok{background:#eafaf0;color:#07c160;}
 .db-test-msg.err{background:#fff3f3;color:#e64340;}
 .pwd-eye-wrap{position:relative;display:block;}
 .pwd-eye-wrap input{padding-right:34px;box-sizing:border-box;}
 .pwd-eye{position:absolute;right:10px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:17px;user-select:none;z-index:2;}
 .success-box{background:linear-gradient(135deg,#eafaf0,#dcfce7);border-radius:12px;padding:20px;}
 pre{background:#1e1e1e;color:#d4d4d4;padding:14px;border-radius:8px;overflow-x:auto;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-all;}
</style>
</head>
<body>
<div class="wrap">
<div class="card">

<?php if ($alreadyDone && $step !== 3): ?>
  <div class="head">
    <div>
      <span class="badge ok">✅ 已安装</span>
      <h1>工资条系统</h1>
      <p class="sub">系统早已安装完成，无需重复安装。</p>
    </div>
  </div>
  <?php if ($success): ?>
    <div class="ok" style="white-space:pre-line;"><?php echo _install_e($success); ?></div>
    <div class="success-box" style="margin-top:14px;">
      <div style="font-weight:bold;margin-bottom:10px;">📋 部署完成后请按以下顺序继续</div>
      <ol style="font-size:14px;line-height:1.9;margin:0;padding-left:20px;">
        <li>企业微信管理后台：把自建应用的<b>可信域名、JS-SDK 域名、网页授权域名</b>全部填成本系统</li>
        <li>配置接收消息回调地址：<code>/callback.php</code></li>
        <li><a href="admin.php" style="color:#07c160;">登录后台（admin.php）</a> → 系统设置 → 填写企业微信参数，并绑定管理员企微 ID</li>
        <li>同步通讯录 → 给各成员分配角色（admin / finance / hr）</li>
        <li>上传 Excel → 推送工资条</li>
      </ol>
    </div>
  <?php else: ?>
    <div class="ok">如需重新安装，请先删除 <code>config.local.php</code>（以及数据库文件/清空数据库）后重新访问本页面。</div>
    <div class="btn-row">
      <a href="admin.php" class="btn btn-primary">进入管理后台</a>
      <a href="employee/login" class="btn btn-secondary">进入员工端</a>
    </div>
  <?php endif; ?>

<?php else: ?>

  <div class="head">
    <div>
      <span class="badge todo">● 未初始化</span>
      <h1>工资条系统初始化向导</h1>
      <p class="sub">按以下 3 步完成部署，预计 2 分钟。</p>
    </div>
  </div>

  <!-- Stepper -->
  <div class="stepper">
    <div class="step <?php echo $step===1?'active':($step>1?'done':''); ?>">
      <span class="step-circle"><?php echo $step>1?'✓':'1'; ?></span>
      <span class="step-label">环境检测</span>
    </div>
    <div class="step-line"></div>
    <div class="step <?php echo $step===2?'active':($step>2?'done':''); ?>">
      <span class="step-circle"><?php echo $step>2?'✓':'2'; ?></span>
      <span class="step-label">数据库配置</span>
    </div>
    <div class="step-line"></div>
    <div class="step <?php echo $step===3?'active':($step>3?'done':''); ?>">
      <span class="step-circle"><?php echo $step>3?'✓':'3'; ?></span>
      <span class="step-label">安装完成</span>
    </div>
  </div>

  <?php if ($error): ?><div class="err"><?php echo _install_e($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="ok" style="white-space:pre-line;"><?php echo _install_e($success); ?></div><?php endif; ?>

  <!-- ========== Step 1: 环境检测 ========== -->
  <?php if ($step === 1): ?>
    <div class="env-title">
      <div style="font-weight:bold;font-size:15px;">🔍 PHP 运行环境检测</div>
      <div class="env-summary">
        <span class="pill <?php echo $env['overallPass']?'pill-pass':'pill-fail'; ?>">
          <?php echo $env['overallPass'] ? '✅ 通过：'.count(array_filter($env['checks'],fn($c)=>!empty($c['pass']))) : '❌ 存在必失败项'; ?>
        </span>
        <?php if ($env['overallWarn']): ?>
          <span class="pill pill-warn">⚠️ 有 <?php echo count(array_filter($env['checks'],fn($c)=>!empty($c['warn']))); ?> 条建议</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="env-grid">
      <?php foreach ($env['checks'] as $id => $c):
        $cls = empty($c['pass']) ? 'fail' : (!empty($c['warn']) ? 'warn' : 'pass');
        $sym = $cls === 'pass' ? '✓' : ($cls === 'warn' ? '!' : '✗');
      ?>
        <div class="env-row">
          <div class="lab"><?php echo _install_e($c['label']); ?></div>
          <div class="val"><?php echo _install_e($c['value']); ?></div>
          <div class="tip"><?php echo _install_e($c['tip'] ?? ''); ?></div>
          <div class="ic ic-<?php echo $cls; ?>"><?php echo $sym; ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <form method="post" id="formStep1" class="btn-row" onsubmit="return confirmStep1(event)">
      <input type="hidden" name="_step" value="2">
      <button class="btn btn-primary" type="submit" id="step1Next"
        <?php echo $env['overallPass'] ? '' : 'disabled'; ?>>
        下一步：选择数据库
      </button>
      <?php if (!$env['overallPass']): ?>
        <div style="width:100%;margin-top:6px;color:#e64340;font-size:13px;">
          ❌ 存在必需项未满足，请先解决上方红色失败项后再继续。黄色警告项为推荐条件，可继续安装。
        </div>
      <?php endif; ?>
    </form>
  <?php endif; ?>

  <!-- ========== Step 2: 数据库选择 ========== -->
  <?php if ($step === 2): ?>
    <form method="post" id="formStep2" onsubmit="return beforeStep2Submit(this)">
      <input type="hidden" name="_step" value="2">
      <input type="hidden" name="_action" id="step2Action" value="test">

      <div style="font-weight:bold;font-size:15px;margin-bottom:4px;">💾 选择数据库类型</div>
      <div style="font-size:13px;color:#6b7280;margin-bottom:4px;">根据你的实际环境选择以下其中一种。推荐小团队用 SQLite（零运维），已有 MariaDB 服务器直接用 MySQL。</div>

      <div class="db-tabs">
        <div class="db-tab <?php echo $dbType==='sqlite' ? 'active':''; ?><?php echo !$env['sqliteOk'] ? ' disabled':''; ?>" id="tabSqlite" onclick="selectDb('sqlite')">
          <div class="t">🪶 SQLite<?php echo !$env['sqliteOk']?'<span class="lock">不可用</span>':''; ?></div>
          <div class="d">单文件数据库，无需独立服务。适合几十人小团队，备份即复制文件。<br>推荐：员工 ≤ 200 人。</div>
        </div>
        <div class="db-tab <?php echo $dbType==='mysql' ? 'active':''; ?><?php echo !$env['mysqlOk'] ? ' disabled':''; ?>" id="tabMysql" onclick="selectDb('mysql')">
          <div class="t">🐬 MySQL / MariaDB<?php echo !$env['mysqlOk']?'<span class="lock">不可用</span>':''; ?></div>
          <div class="d">独立数据库服务，高并发稳定。适合已有机房/云数据库，或未来规模增长。<br>推荐：员工 ≥ 200 人。</div>
        </div>
      </div>
      <!-- 隐藏字段：真正被表单提交的 db_type 值；彻底摆脱 label 嵌套 radio 的浏览器原生 toggle 行为冲突 -->
      <input type="hidden" name="db_type" id="dbTypeHidden" value="<?php echo _install_e($dbType); ?>">

      <!-- SQLite 配置 -->
      <div id="sqliteCfg" class="<?php echo $dbType==='sqlite' ? '' : 'hidden'; ?>" style="margin-top:16px;">
        <div class="sec" style="border:none;padding:0;margin-bottom:4px;">SQLite 数据目录</div>
        <label>数据库文件存放目录（留空使用推荐位置）</label>
        <input name="data_dir" id="dataDir" value="<?php echo _install_e($dbConfig['data_dir'] ?? $defaultDataDir); ?>" placeholder="留空 = 站点根目录之外一层 /salary_data">
        <div class="tip">
          推荐位置：<code><?php echo _install_e($defaultDataDir . DIRECTORY_SEPARATOR . 'app.sqlite3'); ?></code><br>
          如果上方推荐位置在你的主机上因 open_basedir 限制无法写入，可改成任意 PHP 可写的绝对路径（宝塔常见 <code>/www/wwwroot_private/salary_data</code>）。
          系统会自动判断目录是否在 Web 根目录内，并在目录内写入 <code>.htaccess / web.config</code> 禁止 Web 直接下载。
        </div>
      </div>

      <!-- MySQL 配置 -->
      <div id="mysqlCfg" class="<?php echo $dbType==='mysql' ? '' : 'hidden'; ?>" style="margin-top:16px;">
        <div class="sec" style="border:none;padding:0;margin-bottom:4px;">MySQL / MariaDB 连接参数</div>
        <div class="tip" style="margin-bottom:8px;">
          请先在 MySQL 中执行：<code>CREATE DATABASE IF NOT EXISTS salary CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;</code>
          并为该库创建拥有全部权限的账号（GRANT ALL）。
        </div>
        <div class="mysql-grid">
          <div>
            <label>主机 Host <span class="req">*</span></label>
            <input name="db_host" id="dbHost" value="<?php echo _install_e($dbConfig['host'] ?? '127.0.0.1'); ?>" placeholder="127.0.0.1">
          </div>
          <div>
            <label>端口 Port <span class="req">*</span></label>
            <input name="db_port" id="dbPort" type="number" min="1" max="65535" value="<?php echo _install_e($dbConfig['port'] ?? 3306); ?>">
          </div>
          <div>
            <label>库名 Database <span class="req">*</span></label>
            <input name="db_name" id="dbName" value="<?php echo _install_e($dbConfig['name'] ?? 'salary'); ?>" placeholder="salary">
          </div>
          <div>
            <label>用户名 Username <span class="req">*</span></label>
            <input name="db_user" id="dbUser" value="<?php echo _install_e($dbConfig['user'] ?? ''); ?>" placeholder="salary">
          </div>
          <div class="full">
            <label>密码 Password</label>
            <div class="pwd-eye-wrap">
              <input type="password" name="db_pass" id="dbPass" value="<?php echo _install_e($dbConfig['pass'] ?? ''); ?>" autocomplete="new-password">
              <span class="pwd-eye" onclick="togglePwd('dbPass', this)">👁</span>
            </div>
          </div>
        </div>
      </div>

      <?php if ($dbTested): ?>
        <div class="db-test-msg <?php echo $dbTestOk ? 'ok' : 'err'; ?>"><?php echo _install_e($dbTestMsg); ?></div>
      <?php endif; ?>

      <div class="btn-row">
        <button class="btn btn-secondary" type="button" onclick="goStep(1)">← 上一步</button>
        <button class="btn btn-test" type="button" onclick="testDbConn()">🔎 测试连接</button>
        <button class="btn btn-primary" type="submit" id="step2Next"
          <?php echo ($dbTested && $dbTestOk) ? '' : 'disabled'; ?>>
          下一步：设置管理员
        </button>
      </div>
      <?php if (!$dbTested || !$dbTestOk): ?>
        <div style="margin-top:8px;color:#d68a00;font-size:13px;">💡 请先点击「🔎 测试连接」，通过后才能进入下一步。</div>
      <?php endif; ?>
    </form>
  <?php endif; ?>

  <!-- ========== Step 3: 管理员 + 执行 ========== -->
  <?php if ($step === 3): ?>
    <form method="post" id="formStep3" onsubmit="return onInstallSubmit(this)">
      <input type="hidden" name="_step" value="3">
      <input type="hidden" name="_db_type" value="<?php echo _install_e($dbType); ?>">
      <?php if ($dbType === 'sqlite'): ?>
        <input type="hidden" name="_data_dir" value="<?php echo _install_e($dbConfig['data_dir'] ?? ''); ?>">
        <input type="hidden" name="_db_file"  value="<?php echo _install_e($dbConfig['db_file'] ?? ''); ?>">
      <?php else: ?>
        <input type="hidden" name="_db_host" value="<?php echo _install_e($dbConfig['host'] ?? ''); ?>">
        <input type="hidden" name="_db_port" value="<?php echo _install_e($dbConfig['port'] ?? ''); ?>">
        <input type="hidden" name="_db_name" value="<?php echo _install_e($dbConfig['name'] ?? ''); ?>">
        <input type="hidden" name="_db_user" value="<?php echo _install_e($dbConfig['user'] ?? ''); ?>">
        <input type="hidden" name="_db_pass" value="<?php echo _install_e($dbConfig['pass'] ?? ''); ?>">
      <?php endif; ?>

      <div class="success-box" style="margin-bottom:18px;">
        <div style="font-weight:600;margin-bottom:6px;">✅ 数据库准备完成</div>
        <div style="font-size:13px;line-height:1.6;color:#374151;">
          类型：<b><?php echo $dbType === 'mysql' ? 'MySQL / MariaDB' : 'SQLite（单文件）'; ?></b><br>
          连接：<code><?php echo _install_e($dbType === 'mysql' ? ($dbConfig['host']??'').':'.($dbConfig['port']??'').' / '.($dbConfig['name']??'') : ($dbConfig['db_file'] ?? '')); ?></code>
        </div>
      </div>
      <div class="sec">管理员账号与密钥</div>
      <p class="tip">管理员企业微信 ID 请在安装后，登录后台「系统设置」进行绑定。此处设置的账号密码用于直接登录后台，与企业微信登录相互独立。</p>

      <label>管理员后台登录账号 <span class="req">*</span></label>
      <input name="admin_login" id="adminLogin" required placeholder="如 admin 或工号，建议用易记的名字">
      <p class="tip">这是后台「账号密码登录」的用户名，不依赖企业微信，任何时候都能直接登录。</p>

      <label>管理员后台登录密码 <span class="req">*</span></label>
      <div class="pwd-eye-wrap">
        <input type="password" name="admin_pass" id="adminPass" required minlength="8" placeholder="至少 8 位，需含字母和数字">
        <span class="pwd-eye" onclick="togglePwd('adminPass', this)">👁</span>
      </div>
      <p class="tip">后台账号密码登录密码，<b>和员工端工资条密码是两回事</b>。</p>

      <label>JWT 密钥（建议留空自动生成）</label>
      <input name="jwt_secret" id="jwtSecret" placeholder="留空自动生成 64 位随机串；或填写 ≥16 位随机字符">
      <p class="tip">用于签名会话 Token，生产环境务必保持随机。留空即可。</p>

      <div class="btn-row">
        <button class="btn btn-secondary" type="button" onclick="goBackToStep2()">← 上一步</button>
        <button class="btn btn-primary" type="submit" id="installBtn">🚀 开始安装</button>
      </div>
    </form>
  <?php endif; ?>

<?php endif; ?>
</div>
<div style="text-align:center;color:#9ca3af;font-size:12px;margin-top:16px;">
  工资条系统 · 初始化向导 &nbsp;|&nbsp; 有问题请先检查上方环境检测结果
</div>
</div>

<script>
  // ---- 密码显隐 ----
  function togglePwd(id, eye){
    var inp=document.getElementById(id);
    if(inp.type==='password'){ inp.type='text'; eye.textContent='🙈'; }
    else { inp.type='password'; eye.textContent='👁'; }
  }

  // ---- 步骤跳转 ----
  // extraFields: 可选，携带额外隐藏字段（用于 Step 3→Step 2 时保留数据库配置）
  function goStep(n, extraFields){
    var f=document.createElement('form'); f.method='post';
    var i=document.createElement('input'); i.type='hidden'; i.name='_step'; i.value=n; f.appendChild(i);
    if(extraFields){
      for(var name in extraFields){
        if(extraFields.hasOwnProperty(name)){
          var inp=document.createElement('input'); inp.type='hidden'; inp.name=name; inp.value=extraFields[name]; f.appendChild(inp);
        }
      }
    }
    document.body.appendChild(f); f.submit();
  }
  function confirmStep1(e){
    <?php if (!$env['overallPass']): ?>
      e.preventDefault();
      alert('存在必失败项未解决，无法继续。请先修复上方红色项目。');
      return false;
    <?php endif; ?>
    return true;
  }

  // ---- Step 3 → Step 2：携带数据库配置回退，避免丢失已选类型和参数 ----
  function goBackToStep2(){
    var extraFields = {};
    // 从 Step 3 表单的隐藏字段读取数据库配置，把 _db_xxx 还原为 db_xxx
    document.querySelectorAll('#formStep3 input[type=hidden]').forEach(function(inp){
      var name = inp.name;
      if(name === '_step') return; // 跳过 _step
      if(name.indexOf('_db_') === 0){
        name = name.substring(1); // _db_type → db_type, _db_host → db_host ...
      }
      extraFields[name] = inp.value;
    });
    goStep(2, extraFields);
  }

  // ---- Step 2: 数据库选择 ----
  // 现在完全不用 label + 嵌套 radio 的方式，db_type 通过隐藏字段 <input name=db_type id=dbTypeHidden> 提交
  // 彻底消除浏览器 label 原生 toggle 行为与 JS 反复打架的问题
  function selectDb(type){
    var tabS = document.getElementById('tabSqlite');
    var tabM = document.getElementById('tabMysql');
    var cfgS = document.getElementById('sqliteCfg');
    var cfgM = document.getElementById('mysqlCfg');
    var hidden = document.getElementById('dbTypeHidden');
    if (type==='sqlite' && tabS.classList.contains('disabled')) return;
    if (type==='mysql'  && tabM.classList.contains('disabled')) return;
    tabS.classList.toggle('active', type==='sqlite');
    tabM.classList.toggle('active', type==='mysql');
    cfgS.classList.toggle('hidden', type!=='sqlite');
    cfgM.classList.toggle('hidden', type!=='mysql');
    if (hidden) hidden.value = type;
  }
  // 初始化：无需额外操作，兜底 .hidden{display:none !important;} 样式已注入在末尾
  // 注意：不要再用 JS 给元素写内联 style.display，否则后续 classList.toggle 切换 hidden class 时
  // 内联样式优先级更高，会导致元素仍然不显示

  // ---- Step 2: 测试数据库连接 ----
  function testDbConn(){
    var form=document.getElementById('formStep2');
    document.getElementById('step2Action').value='test';
    // 不禁用下一步按钮；让 PHP 返回 step2 页面 + 测试结果
    form.submit();
  }
  function beforeStep2Submit(form){
    // 进入下一步，必须是「测试通过」状态；按钮 disabled 的前提下本函数不会走到，但加一道保险
    var btn=document.getElementById('step2Next');
    if (btn && btn.disabled){
      alert('请先点击「🔎 测试连接」，通过后再继续');
      return false;
    }
    document.getElementById('step2Action').value='install';
    return true;
  }

  // ---- Step 3: 安装 ----
  function onInstallSubmit(form){
    var btn=document.getElementById('installBtn');
    if(!btn || btn.disabled) return false;
    btn.disabled=true;
    btn.innerHTML='⏳ 安装中…请稍候，不要关闭页面';
    return true;
  }

  // 给 hidden 类兜底（若浏览器未应用 style）
  var styleAdd = document.createElement('style');
  styleAdd.textContent = '.hidden{display:none !important;}';
  document.head.appendChild(styleAdd);
</script>
</body>
</html>
