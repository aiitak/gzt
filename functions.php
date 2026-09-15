<?php
/**
 * 公共工具函数
 */
require_once __DIR__ . '/config.php';

// 请求体缓存（避免重复读取）
$GLOBALS['_req_body'] = null;

/**
 * UTF-8 安全的大小写不敏感查找（mbstring 不可用时降级为 stripos）。
 * 对于 UTF-8 多字节字符（如中文）字节序列匹配等价于字符匹配，
 * 仅 ASCII 字母的大小写差异由 stripos 处理，结果与 mb_stripos 一致。
 */
function safe_stripos($haystack, $needle) {
    if (function_exists('mb_stripos')) {
        return mb_stripos((string)$haystack, (string)$needle);
    }
    return stripos((string)$haystack, (string)$needle);
}

/**
 * UTF-8 安全的子串截取（mbstring 不可用时降级为 substr）。
 * 参数语义与 mb_substr 一致：start 为起始字符位置（非字节），length 为字符数。
 * 未启用 mbstring 时，按字节截取可能截断中文中间字节，但避免调用未定义函数导致致命错误。
 */
function safe_substr($string, $start, $length = null) {
    $string = (string)$string;
    if (function_exists('mb_substr')) {
        return $length === null
            ? mb_substr($string, $start)
            : mb_substr($string, $start, $length);
    }
    return $length === null
        ? substr($string, $start)
        : substr($string, $start, $length);
}

/**
 * UTF-8 安全的字符串长度（mbstring 不可用时降级为 strlen）。
 * 未启用 mbstring 时返回字节数（中文字符通常占 3 字节），避免调用未定义函数导致致命错误。
 */
function safe_strlen($string) {
    if (function_exists('mb_strlen')) {
        return mb_strlen((string)$string);
    }
    return strlen((string)$string);
}

function normalize_dept_name($name) {
    $name = (string)$name;
    if ($name === '已辞职') {
        return '已离职';
    }
    return $name;
}

/**
 * 查找或创建指定名称的部门，返回 dept_id。
 * 用于"已离职"/"未匹配"等虚拟部门的管理。
 */
function find_or_create_dept_by_name($db, $deptName) {
    // 1. 精确查找同名部门
    $stmt = $db->prepare("SELECT dept_id FROM departments WHERE name = ? LIMIT 1");
    $stmt->execute([$deptName]);
    $row = $stmt->fetch();
    if ($row) {
        return (int)$row['dept_id'];
    }
    // 2. 直接创建新部门（不复用已有负 dept_id 的部门，避免互相覆盖）
    // 注意：条件用 <= -9999 而非 < -9999，否则第二个虚拟部门仍取 -9999 触发 UNIQUE 冲突静默失败
    $minDept = $db->query("SELECT MIN(dept_id) FROM departments")->fetchColumn();
    $deptId = ($minDept !== false && (int)$minDept <= -9999) ? (int)$minDept - 1 : -9999;
    $insertSql = (defined('DB_TYPE') && DB_TYPE === 'mysql')
        ? "INSERT IGNORE INTO departments (dept_id, name, parent_id) VALUES (?, ?, 0)"
        : "INSERT OR IGNORE INTO departments (dept_id, name, parent_id) VALUES (?, ?, 0)";
    $db->prepare($insertSql)->execute([$deptId, $deptName]);
    // 确认创建成功
    $ck = $db->prepare("SELECT dept_id FROM departments WHERE name = ? LIMIT 1");
    $ck->execute([$deptName]);
    $ckr = $ck->fetch();
    return $ckr ? (int)$ckr['dept_id'] : -9999;
}

/**
 * 前端缓存击穿：在页面 <head> 注入一段脚本。
 * 页面加载后向 /version.php 请求当前部署版本，若与页面内嵌版本不一致
 * （说明企业微信/浏览器缓存了旧页面），则带时间戳强制重新加载，彻底解决
 * 「服务器已更新、客户端仍显示旧版」的问题。
 */
function salary_inject_cache_buster($html) {
    $v = defined('APP_VERSION') ? APP_VERSION : '0';
    $script = "<script>(function(){"
        . "window.APP_VERSION=" . json_encode($v, JSON_HEX_TAG) . ";"
        . "var PV=" . json_encode($v, JSON_HEX_TAG) . ";"
        . "try{"
        . "var x=new XMLHttpRequest();"
        . "x.open('GET','/version.php?_='+Date.now(),true);"
        . "x.onload=function(){try{var d=JSON.parse(x.responseText);"
        . "if(d&&d.version&&d.version!==PV){var u=new URL(location.href);u.searchParams.set('__v',Date.now());location.replace(u.toString());}}catch(e){}};"
        . "x.send();"
        . "}catch(e){}"
        . "})();</script>";
    if (preg_match('/<head[^>]*>/i', $html)) {
        $html = preg_replace('/<head[^>]*>/i', '$0' . $script, $html, 1);
    } else {
        $html = $script . $html;
    }
    return $html;
}

/**
 * 输出 JSON 并终止
 */
function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $opts = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $opts |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($data, $opts);
    if ($json === false) {
        // 数据含非法 UTF-8 等导致编码失败时，降级返回错误信息而非空 body
        $json = json_encode([
            'success' => false,
            'error'   => '响应数据 JSON 编码失败：' . json_last_error_msg(),
        ], $opts);
    }
    echo $json;
    exit;
}

/**
 * 读取请求体（自动识别 JSON / 表单）
 */
function get_request_body() {
    if ($GLOBALS['_req_body'] !== null) {
        return $GLOBALS['_req_body'];
    }
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $dec = json_decode($raw, true);
        $GLOBALS['_req_body'] = is_array($dec) ? $dec : [];
    } else {
        $GLOBALS['_req_body'] = $_POST;
    }
    return $GLOBALS['_req_body'];
}

/**
 * 取参数：GET > JSON/POST body > $_POST
 */
function param($key, $default = null) {
    if (isset($_GET[$key]) && $_GET[$key] !== '') {
        return $_GET[$key];
    }
    $body = get_request_body();
    if (is_array($body) && isset($body[$key]) && $body[$key] !== '') {
        return $body[$key];
    }
    if (isset($_POST[$key]) && $_POST[$key] !== '') {
        return $_POST[$key];
    }
    return $default;
}

/**
 * 安全输出 HTML（防 XSS）
 */
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * 记录运行日志。
 * 关键错误（含 error/failed/exception/fatal 关键词）即使关闭 DEBUG 也强制写入，
 * 便于生产环境追溯 OAuth/回调/通讯录同步等链路问题；
 * 普通诊断信息（info/debug 级别）仅 DEBUG=true 时记录，避免刷爆日志。
 */
function slog($msg) {
    $force = false;
    if (is_string($msg)) {
        $lower = strtolower($msg);
        foreach (['error', 'failed', 'exception', 'fatal', 'skipped'] as $kw) {
            if (strpos($lower, $kw) !== false) { $force = true; break; }
        }
    }
    if (DEBUG || $force) {
        error_log('[salary] ' . $msg);
    }
}

/**
 * 判断字段是否为扣除类（用于前端区分颜色展示，员工端仍可见）
 * 注意：含"补助/补贴"的项通常是补贴收入（社保补助、餐补、房补等），不是扣款
 */
function is_deduction_field($name) {
    $name = (string)$name;
    // 白名单优先：含这些词的属于补贴收入，即使命中扣款关键词也不是扣款
    $incomeKw = ['补助', '补贴', '房补', '餐补', '话费补贴', '交通补助', '高温补助', '奖', '奖励', '慰问金', '福利'];
    foreach ($incomeKw as $k) {
        if (strpos($name, $k) !== false) {
            return false;
        }
    }
    $kw = ['代扣', '扣款', '扣除', '扣减', '社保', '公积金', '个税', '所得税',
           '保险', '养老', '医疗', '失业', '工伤', '生育', '住房',
           '迟到', '早退', '请假', '缺勤', '罚款', '质量罚款', '工会经费'];
    foreach ($kw as $k) {
        if (strpos($name, $k) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * 解析「|」分隔的多关键词，过滤空项。
 * 配置示例：重置密码|密码重置|改密|修改密码
 */
function parse_keyword_list($str, $default = '') {
    $s = trim((string)$str);
    if ($s === '') {
        $s = $default;
    }
    $arr = array_filter(array_map('trim', explode('|', $s)), function ($x) {
        return $x !== '';
    });
    return array_values($arr);
}

/**
 * 判断消息文本是否命中关键词列表（不区分大小写/中英文）。
 *
 * @param string          $content        用户发的消息原文
 * @param array|string    $kwList         关键词数组或单关键词字符串
 * @param bool            $returnMatched  true=返回具体命中的那一个关键词字符串；false=只返回 bool（默认）
 * @return bool|string  bool 默认；$returnMatched=true 时返回第一个命中的关键词字符串，未命中返回 ''
 */
function content_has_keyword($content, $kwList, $returnMatched = false) {
    $content = (string)$content;
    if (!is_array($kwList)) $kwList = $kwList === '' ? [] : [(string)$kwList];
    foreach ($kwList as $kw) {
        if ($kw !== '' && safe_stripos($content, $kw) !== false) {
            return $returnMatched ? (string)$kw : true;
        }
    }
    return $returnMatched ? '' : false;
}

/**
 * 根据企微 userid/姓名 反查用户显示名，用于回调审计日志的 op_target 可读化。
 *
 * @param string $wecomId 企微 userid / userid
 * @return string|null 姓名，找不到返回 null
 * @internal 仅用于本地日志展示，失败静默返回 null 不抛
 */
function _find_display_name_by_wecom($wecomId) {
    static $cache = [];
    $key = (string)$wecomId;
    if ($key === '') return null;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT name FROM users WHERE wecom_userid = ? OR userid = ? LIMIT 1");
        $stmt->execute([$key, $key]);
        $row = $stmt->fetch();
        $cache[$key] = ($row && !empty($row['name'])) ? (string)$row['name'] : null;
    } catch (Throwable $e) {
        $cache[$key] = null;
    }
    return $cache[$key];
}

/**
 * 免密窗口时长（分钟），后台可设，默认 30。
 * 员工验证密码后在该时长内查看工资条免重复输入。
 */
function free_window_minutes() {
    $m = (int)get_setting('free_window_minutes', 30);
    return $m < 1 ? 30 : $m;
}

/**
 * 是否通过 HTTPS 访问（兼容反向代理透传的 X-Forwarded-Proto）
 */
function is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || (defined('TRUST_PROXY') && TRUST_PROXY && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/**
 * 输出基础安全响应头（防 MIME 嗅探 / 点击劫持 / referrer 泄露 / XSS 过滤）
 */
function security_headers() {
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('X-XSS-Protection: 1; mode=block');
}

/**
 * JWT 密钥仍为默认值时拒绝签发/校验令牌，避免被伪造管理员/员工会话
 */
function ensure_jwt_secret() {
    if (jwt_secret_is_default()) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "ERROR: JWT_SECRET 仍为默认值，请在 config.local.php 设置随机密钥后重试。\n");
            exit(1);
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => '系统安全配置未完成：JWT_SECRET 仍为默认值，请设置随机密钥后重试。']);
        exit;
    }
}

/**
 * 同源 CSRF 校验：对会改变状态的请求（POST/PUT/DELETE/PATCH）要求 Origin/Referer 与本站同源。
 * 企业微信回调走 callback.php，不经过本校验；后台 UI 与前端的 fetch 均为同源，不受影响。
 *
 * 安全策略：
 *   - 写操作必须携带 Origin 或 Referer 之一，且与本站同源；两者均缺失视为可疑请求直接拒绝
 *     （现代浏览器对跨站 fetch 会强制附带 Origin，本地直接 fetch 也会带 Referer）
 *   - 仅依靠 HttpOnly + SameSite Cookie 不足以防御所有 CSRF 场景（如子域 XSS、SameSite=None 配置）
 */
function require_same_origin() {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        return;
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        // CLI 请求无 Host 头，放行；HTTP 请求缺少 Host 属于异常，拒绝写操作
        if (PHP_SAPI === 'cli') {
            return;
        }
        http_response_code(403);
        json_out(['success' => false, 'error' => '缺少 Host 头（CSRF 防护）']);
    }
    $host = preg_replace('/:\d+$/', '', $host);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    // 写操作必须携带 Origin 或 Referer 之一（缺失则视为可疑请求）
    if ($origin === '' && $referer === '') {
        http_response_code(403);
        json_out(['success' => false, 'error' => '缺少请求来源信息（CSRF 防护）']);
    }
    $check = $origin !== '' ? $origin : $referer;
    $pu = parse_url($check);
    if ($pu === false || empty($pu['host'])) {
        http_response_code(403);
        json_out(['success' => false, 'error' => '请求来源不合法']);
    }
    $originHost = preg_replace('/:\d+$/', '', $pu['host']);
    if ($originHost !== $host) {
        http_response_code(403);
        json_out(['success' => false, 'error' => '跨站请求被拒绝（CSRF 防护）']);
    }
}

// 表结构初始化与旧库列兜底已统一由 db.php 的 db_ensure_schema() / db_ensure_column() 处理，
// 在 get_db() 连接成功后自动调用，无需在此重复定义。

/**
 * 解析 users.role：支持「单值」或「逗号分隔多值」（如 "finance,hr"）。
 * 返回去重后的角色数组；employee / 空 视为无后台角色。
 */
function user_roles_array($roleStr) {
    $roleStr = (string)($roleStr ?? '');
    if ($roleStr === '' || $roleStr === 'employee') {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('trim', explode(',', $roleStr)))));
}

/**
 * 判断 users.role 是否包含 $allowed 中的任一角色（多角色安全判定）。
 */
function user_has_any_role($roleStr, array $allowed) {
    foreach (user_roles_array($roleStr) as $r) {
        if (in_array($r, $allowed, true)) {
            return true;
        }
    }
    return false;
}

// ===== 页面访问权限控制 =====
/**
 * 获取后台页面列表及其默认角色权限。
 * 返回格式：[ ['page'=>'dashboard','label'=>'数据概览','icon'=>'📈','roles'=>['admin','finance','hr']], ... ]
 * admin 角色始终拥有所有页面访问权限（硬编码，不可取消）。
 */
function get_page_definitions() {
    return [
        ['page' => 'dashboard',      'label' => '数据概览',    'icon' => '📈', 'roles' => ['admin', 'finance', 'hr']],
        ['page' => 'upload',         'label' => '上传工资条',  'icon' => '📤', 'roles' => ['admin', 'finance']],
        ['page' => 'history_import', 'label' => '历史数据导入', 'icon' => '📚', 'roles' => ['admin', 'finance']],
        ['page' => 'stats',          'label' => '签收统计',    'icon' => '📊', 'roles' => ['admin', 'finance', 'hr']],
        ['page' => 'salary_list',    'label' => '工资条管理',  'icon' => '💰', 'roles' => ['admin', 'finance']],
        ['page' => 'salary_trash',   'label' => '工资条回收站', 'icon' => '🗑️', 'roles' => ['admin', 'finance']],
        ['page' => 'feedback_list',  'label' => '反馈处理',    'icon' => '💬', 'roles' => ['admin', 'hr']],
        ['page' => 'contacts',       'label' => '通讯录',      'icon' => '📇', 'roles' => ['admin', 'finance', 'hr']],
        ['page' => 'messages',       'label' => '消息记录',    'icon' => '📜', 'roles' => ['admin', 'finance', 'hr']],
        ['page' => 'notify',         'label' => '发送消息',    'icon' => '📨', 'roles' => ['admin', 'finance', 'hr']],
        ['page' => 'roles',          'label' => '角色管理',    'icon' => '👑', 'roles' => ['admin']],
        ['page' => 'access',         'label' => '权限控制',    'icon' => '🔐', 'roles' => ['admin']],
        ['page' => 'audit_log',      'label' => '操作日志',    'icon' => '📋', 'roles' => ['admin']],
        ['page' => 'bonus_list',     'label' => '奖金管理',    'icon' => '🎁', 'roles' => ['admin', 'finance']],
        ['page' => 'bonus_trash',    'label' => '奖金回收站',  'icon' => '🗑️', 'roles' => ['admin', 'finance']],
        ['page' => 'bonus_upload',   'label' => '上传奖金',    'icon' => '📤', 'roles' => ['admin', 'finance']],
        ['page' => 'bonus_types',    'label' => '奖金类型',    'icon' => '🏷️', 'roles' => ['admin']],
        ['page' => 'vacation_overview', 'label' => '年假总览',  'icon' => '🏖️', 'roles' => ['admin', 'finance', 'hr']],
        ['page' => 'vacation_adjust',   'label' => '年假调整',  'icon' => '✏️', 'roles' => ['admin', 'hr']],
        ['page' => 'vacation_rules',    'label' => '年假规则',  'icon' => '📏', 'roles' => ['admin']],
        ['page' => 'settings',       'label' => '系统设置',    'icon' => '⚙️', 'roles' => ['admin']],
        ['page' => 'info',           'label' => '部署说明',    'icon' => '📖', 'roles' => ['admin']],
        ['page' => 'bb',             'label' => '版本记录',    'icon' => '📒', 'roles' => ['admin']],
    ];
}

/**
 * 获取自定义角色页面权限矩阵（从 settings 表读取）。
 * 返回格式：[ 'finance' => ['dashboard','upload',...], 'hr' => ['dashboard','stats',...] ]
 * admin 始终拥有所有页面权限，不在此矩阵中配置。
 */
function get_role_page_access() {
    $raw = get_setting('role_page_access', '');
    if ($raw === '') {
        // 无自定义配置时返回 null，表示使用默认权限
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    return $data;
}

/**
 * 判断指定角色是否能访问指定页面。
 * admin 角色始终允许；其他角色根据自定义权限矩阵或默认权限判断。
 */
function role_can_access_page($roleStr, $page) {
    // admin 始终拥有所有权限
    if (user_has_any_role($roleStr, [ROLE_ADMIN])) {
        return true;
    }
    $pages = get_page_definitions();
    $pageDef = null;
    foreach ($pages as $p) {
        if ($p['page'] === $page) {
            $pageDef = $p;
            break;
        }
    }
    if (!$pageDef) {
        return false;
    }
    // 读取自定义权限
    $custom = get_role_page_access();
    if ($custom !== null) {
        // 有自定义配置：检查每个角色的自定义权限
        foreach (user_roles_array($roleStr) as $r) {
            if (isset($custom[$r]) && is_array($custom[$r]) && in_array($page, $custom[$r], true)) {
                return true;
            }
        }
        return false;
    }
    // 无自定义配置：使用默认权限
    foreach ($pageDef['roles'] as $r) {
        if (user_has_any_role($roleStr, [$r])) {
            return true;
        }
    }
    return false;
}

// ===== 工资条下发消息模板（后台可编辑 + 通配符）=====
// 内置业务模板默认值（msg_templates 无记录时兜底，避免消息为空）
define('TPL_SALARY_SEND', [
    'msgtype' => 'template_card',
    'title'   => '{年月}工资条已发布',
    'content' => "亲爱的 {姓名}，{年月} 月薪资已生成。\n请点击卡片查看明细并确认签收：{链接}",
]);
define('TPL_PUSH_RESULT', [
    'msgtype' => 'text',
    'title'   => '',
    'content' => '【下发结果】{年}年{月}月 工资条已下发 {成功数} 人（共 {总数} 人）。',
]);
define('TPL_SALARY_REMIND', [
    'msgtype' => 'template_card',
    'title'   => '{年月}工资条待确认',
    'content' => "亲爱的 {姓名}，您还有 {年}年{月}月 工资条尚未确认。\n请点击卡片查看明细并完成签收：{链接}",
]);
define('TPL_FEEDBACK_TO_HR_SALARY', [
    'msgtype' => 'template_card',
    'title'   => '新的工资反馈待处理',
    'content' => '员工 {姓名}({账号}) 对 {年月} 工资条提出反馈，请点击查看：{链接}',
]);
define('TPL_FEEDBACK_TO_HR_BONUS', [
    'msgtype' => 'template_card',
    'title'   => '新的奖金反馈待处理',
    'content' => '员工 {姓名}({账号}) 对 {年}年{奖金类型} 奖金提出反馈，请点击查看：{链接}',
]);
define('TPL_FEEDBACK_REPLY_SALARY', [
    'msgtype' => 'template_card',
    'title'   => '【反馈已回复】{年月}工资反馈',
    'content' => '您提交的工资条反馈已由人事回复，点击查看详情：{链接}',
]);
define('TPL_FEEDBACK_REPLY_BONUS', [
    'msgtype' => 'template_card',
    'title'   => '【反馈已回复】{年}年{奖金类型}反馈',
    'content' => '您提交的奖金反馈已由人事回复，点击查看详情：{链接}',
]);
define('TPL_BONUS_PUSH_RESULT', [
    'msgtype' => 'text',
    'title'   => '',
    'content' => '【下发结果】{年}年「{奖金类型}」已下发 {成功数} 人（共 {总数} 人）。',
]);
define('TPL_BONUS_SEND', [
    'msgtype' => 'template_card',
    'title'   => '{年}年{奖金类型}已发放',
    'content' => "亲爱的【{姓名}】同志您好！感谢您的辛勤付出，您{年}年的{奖金类型}已经发放，请点击查看详情。如有问题请及时反馈！",
]);
define('TPL_BONUS_REMIND', [
    'msgtype' => 'template_card',
    'title'   => '{奖金类型}待确认',
    'content' => "亲爱的 {姓名}，您还有 {年} 年的 {奖金类型} 尚未确认。\n请点击卡片查看明细并完成签收：{链接}",
]);

/**
 * 读取内置业务模板（按 tpl_key 查 msg_templates 表，查不到则用代码内常量兜底）。
 * 返回 ['msgtype','title','content','name']。
 */
function get_builtin_template($tplKey) {
    $constMap = [
        'salary_send'            => TPL_SALARY_SEND,
        'push_result'            => TPL_PUSH_RESULT,
        'salary_remind'          => TPL_SALARY_REMIND,
        'feedback_to_hr_salary'  => TPL_FEEDBACK_TO_HR_SALARY,
        'feedback_to_hr_bonus'   => TPL_FEEDBACK_TO_HR_BONUS,
        'feedback_reply_salary'  => TPL_FEEDBACK_REPLY_SALARY,
        'feedback_reply_bonus'   => TPL_FEEDBACK_REPLY_BONUS,
        'bonus_push_result'      => TPL_BONUS_PUSH_RESULT,
        'bonus_send'             => TPL_BONUS_SEND,
        'bonus_remind'           => TPL_BONUS_REMIND,
    ];
    $fallback = $constMap[$tplKey] ?? ['msgtype' => 'text', 'title' => '', 'content' => ''];
    $nameMap = [
        'salary_send'            => '工资下发通知（员工）',
        'push_result'            => '工资下发结果通知（管理员/财务）',
        'salary_remind'          => '工资催办确认通知（员工）',
        'feedback_to_hr_salary'  => '工资反馈通知（人事收）',
        'feedback_to_hr_bonus'   => '奖金反馈通知（人事收）',
        'feedback_reply_salary'  => '工资反馈回复通知（员工收）',
        'feedback_reply_bonus'   => '奖金反馈回复通知（员工收）',
        'bonus_push_result'      => '奖金下发结果通知（管理员/财务）',
        'bonus_send'             => '奖金下发通知（员工）',
        'bonus_remind'           => '奖金催办确认通知（员工）',
    ];
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT msgtype, title, content, name FROM msg_templates WHERE tpl_key = ? LIMIT 1");
        $stmt->execute([$tplKey]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'msgtype' => $row['msgtype'] ?: $fallback['msgtype'],
                'title'   => $row['title'] !== null && $row['title'] !== '' ? $row['title'] : $fallback['title'],
                'content' => $row['content'] !== null && $row['content'] !== '' ? $row['content'] : $fallback['content'],
                'name'    => $row['name'] ?: ($nameMap[$tplKey] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        // 表不存在或 DB 故障时兜底到常量模板，记录日志便于排查
        slog('get_builtin_template 异常: ' . $e->getMessage());
    }
    return ['msgtype' => $fallback['msgtype'], 'title' => $fallback['title'], 'content' => $fallback['content'], 'name' => $nameMap[$tplKey] ?? ''];
}

/**
 * 便捷组合：读取模板 + 变量渲染，一次返回渲染后的 ['msgtype','title','content']。
 */
function render_builtin_template($tplKey, $vars) {
    $tpl = get_builtin_template($tplKey);
    $tpl['title']   = salary_render_template($tpl['title'],   $vars);
    $tpl['content'] = salary_render_template($tpl['content'], $vars);
    return $tpl;
}

/**
 * 返回应用基础地址（不含具体路径），用于拼接任意内部页面链接。
 * 优先 salary_link_base → server_domain → 当前请求域名。
 *
 * 内部缓存：同一请求内多次调用不重复查 settings，避免 N+1
 */
function app_base_url() {
    // 请求级缓存：base URL 一次请求内不变
    if (isset($GLOBALS['_app_base_url_cache'])) {
        return $GLOBALS['_app_base_url_cache'];
    }
    // 一次批量读取两个相关配置，避免两次 get_setting 查询
    $cfg = get_settings_by_keys(['salary_link_base', 'server_domain'], '');
    $base = trim($cfg['salary_link_base']);
    if ($base === '') {
        $d = trim($cfg['server_domain']);
        $base = $d === '' ? '' : (preg_match('#^[a-z]+://#i', $d) ? $d : 'https://' . $d);
    }
    if ($base === '') {
        // 最终 fallback：用当前请求域名自动生成，避免消息无链接
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            $proto = is_https() ? 'https' : 'http';
            $base = $proto . '://' . $host;
        }
    }
    $base = $base === '' ? '' : rtrim($base, '/');
    $GLOBALS['_app_base_url_cache'] = $base;
    return $base;
}

/**
 * 构造工资条详情链接（员工点击消息打开）。
 * 复用 app_base_url()，避免重复 base 解析逻辑。
 */
function salary_detail_url($ym) {
    $base = app_base_url();
    if ($base === '') {
        return '';
    }
    return $base . '/employee/salary?ym=' . $ym;
}

/**
 * 渲染下发模板：将 {姓名}{部门}{年}{月}{年月}{链接} 等通配符替换为实际值。
 * 未匹配的通配符保留原样，避免误删内容。
 */
function salary_render_template($tpl, $vars) {
    if ($tpl === '') {
        return '';
    }
    return preg_replace_callback('/\{([^}]+)\}/u', function ($m) use ($vars) {
        $k = $m[1];
        return array_key_exists($k, $vars) ? (string)$vars[$k] : $m[0];
    }, $tpl);
}

/**
 * 给单个员工推送工资条下发消息（按后台模板渲染，带可点击链接）。
 * 返回 true/false 表示是否真实发送成功（供推送统计使用）。
 *
 * 性能优化：模板相关 settings 一次批量读取，避免循环 N+1 查询。
 */
function salary_push_send($userid, $year, $month) {
    $ym = sprintf('%04d%02d', $year, $month);
    $db = get_db();
    $stmt = $db->prepare(
        "SELECT u.name AS name, d.name AS dept_name, u.wecom_userid
         FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id
         WHERE u.userid = ?"
    );
    $stmt->execute([$userid]);
    $row = $stmt->fetch();
    $name = ($row && !empty($row['name'])) ? $row['name'] : $userid;
    $dept = normalize_dept_name(($row && !empty($row['dept_name'])) ? $row['dept_name'] : '');
    // 优先使用 wecom_userid（企微真实userid），回退到 userid
    $notifyUserid = ($row && !empty($row['wecom_userid'])) ? $row['wecom_userid'] : $userid;
    $link = salary_detail_url($ym);
    $vars = [
        '姓名' => $name,
        '部门' => $dept,
        '年'   => (string)$year,
        '月'   => sprintf('%02d', $month),
        '年月' => $ym,
        '链接' => $link,
    ];
    // 优先 msg_templates 表（tpl_key=salary_send），最后代码内常量兜底
    $tpl = get_builtin_template('salary_send');
    $type = $tpl['msgtype'];
    $content = $tpl['content'];
    $title   = $tpl['title'];
    $content = salary_render_template($content, $vars);
    $title   = salary_render_template($title, $vars);

    if ($type === 'template_card') {
        $r = wecom_send_template_card($notifyUserid, $title ?: '工资条已发布', $content, $link);
        if (!$r['ok']) {
            // 卡片超限/失败时降级为 markdown，链接仍可点击
            return wecom_send_markdown($notifyUserid, $content);
        }
        return true;
    }
    if ($type === 'markdown') {
        return wecom_send_markdown($notifyUserid, $content);
    }
    return wecom_send_text($notifyUserid, $content);
}

/**
 * 生成奖金详情页链接（员工端 /employee/bonus?id=xxx）。
 *
 * @param int $bonusId 奖金记录 ID，传入则生成带参数的直链
 * @return string 完整 URL 或空字符串
 */
function bonus_detail_url($bonusId = 0) {
    $base = app_base_url();
    if ($base === '') {
        return '';
    }
    $url = $base . '/employee/bonus';
    if ($bonusId > 0) {
        $url .= '?id=' . (int)$bonusId;
    }
    return $url;
}

/**
 * 给单个员工推送奖金下发消息（按后台模板渲染，带可点击链接）。
 *
 * @param string $userid     员工 userid
 * @param int    $year       奖金年份
 * @param string $bonusType  奖金类型名称
 * @param int    $bonusId    奖金记录 ID，用于生成详情直链
 * @return bool 是否发送成功
 */
function bonus_push_send($userid, $year, $bonusType, $bonusId = 0) {
    $db = get_db();
    $stmt = $db->prepare(
        "SELECT u.name AS name, d.name AS dept_name, u.wecom_userid
         FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id
         WHERE u.userid = ?"
    );
    $stmt->execute([$userid]);
    $row = $stmt->fetch();
    $name = ($row && !empty($row['name'])) ? $row['name'] : $userid;
    $dept = normalize_dept_name(($row && !empty($row['dept_name'])) ? $row['dept_name'] : '');
    $notifyUserid = ($row && !empty($row['wecom_userid'])) ? $row['wecom_userid'] : $userid;
    $link = bonus_detail_url($bonusId);
    $vars = [
        '姓名'     => $name,
        '部门'     => $dept,
        '年'       => (string)$year,
        '奖金类型' => $bonusType,
        '链接'     => $link,
    ];
    $tpl = get_builtin_template('bonus_send');
    $type = $tpl['msgtype'];
    $content = salary_render_template($tpl['content'], $vars);
    $title   = salary_render_template($tpl['title'], $vars);

    if ($type === 'template_card') {
        $r = wecom_send_template_card($notifyUserid, $title ?: '奖金已发布', $content, $link);
        if (!$r['ok']) {
            return wecom_send_markdown($notifyUserid, $content);
        }
        return true;
    }
    if ($type === 'markdown') {
        return wecom_send_markdown($notifyUserid, $content);
    }
    return wecom_send_text($notifyUserid, $content);
}

/**
 * 写入操作审计日志。
 * 调用方只需关心业务参数，操作人/IP/UA 自动收集；失败不抛出异常，避免审计逻辑影响主流程。
 *
 * @param string $type     操作类型枚举（如 upload_salary / delete_salary）
 * @param string $target   操作对象（如 "salary:2025/07"、"feedback:123"）
 * @param string $result   ok / fail
 * @param array  $detail   变更前后快照或关键参数，自动 JSON 化
 */
function audit_log($type, $target = '', $result = 'ok', $detail = []) {
    try {
        require_once __DIR__ . '/db.php';
        require_once __DIR__ . '/auth.php';
        $db = get_db();

        // 操作人：优先管理端，其次员工端，最后 '-'
        $opUser = '-';
        if (function_exists('get_admin_session') && ($a = get_admin_session())) {
            $opUser = 'A:' . ($a['userid'] ?? '-');
        } elseif (function_exists('get_logged_user') && ($u = get_logged_user())) {
            $opUser = 'E:' . ($u['userid'] ?? '-');
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (strlen($ua) > 512) { $ua = substr($ua, 0, 512); }

        $detailStr = $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : '';

        $stmt = $db->prepare(
            "INSERT INTO audit_log (op_user, op_type, op_target, op_result, ip, ua, detail)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$opUser, $type, $target, $result, $ip, $ua, $detailStr]);
    } catch (Throwable $e) {
        // 审计失败不影响业务主流程
    }
}

/**
 * 企微回调 / 消息口令场景写入 audit_log。
 *
 * 与 audit_log() 的核心区别：不依赖 PHP 会话（回调请求没有登录态），
 * $opUser 直接写"审批回调/通讯录回调/应用消息回调"等可读字符串，
 * 便于在后台审计日志页一眼分辨"谁触发的"。
 *
 * @param string $opUser 操作人可读标签（如 "审批回调"、"通讯录回调"、"应用消息回调"、"审批回调-fail"）
 * @param string $type   OP_TYPE_MAP 里的 op_type（如 wecom_cb_approval_event）
 * @param string $target 操作对象（sp:xxx / wecom_userid:xxx / salary:YYYY/MM）
 * @param string $result ok/fail
 * @param array  $detail JSON 化的结构化明细（字段数、失败原因等）
 * @return void
 */
function audit_log_for_callback($opUser, $type, $target = '', $result = 'ok', $detail = []) {
    try {
        require_once __DIR__ . '/db.php';
        $db = get_db();

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (strlen($ua) > 512) { $ua = substr($ua, 0, 512); }
        if (strlen((string)$target) > 512) { $target = substr((string)$target, 0, 512); }
        if (strlen((string)$opUser) > 128) { $opUser = substr((string)$opUser, 0, 128); }

        $detailStr = $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : '';

        $stmt = $db->prepare(
            "INSERT INTO audit_log (op_user, op_type, op_target, op_result, ip, ua, detail)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$opUser, $type, $target, $result, $ip, $ua, $detailStr]);
    } catch (Throwable $e) {
        // 审计失败不影响业务主流程
    }
}

/**
 * 统一日志写入：自动创建 logs 目录，加文件锁防止并发写入错乱。
 *
 * @param string $file 日志文件名（如 'feedback_notify.log'）
 * @param string $msg  日志内容（不含换行，函数自动追加）
 */
function write_log($file, $msg) {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($dir . '/' . $file, $msg . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * 推送下发结果通知给接收人列表。
 * 接收人来源：settings.push_notify（逗号分隔 userid），为空则取所有 admin/finance 角色用户。
 *
 * @param string $msg 通知正文
 * @param array|null $tpl 消息模板 ['msgtype'=>'text|markdown|template_card','title'=>'','content'=>'']
 */
function notify_push_result($msg, $tpl = null) {
    $notify = get_setting('push_notify', '');
    if ($notify !== '') {
        $list = array_filter(array_map('trim', explode(',', $notify)));
    } else {
        $db = get_db();
        $stmt = $db->query("SELECT userid, role FROM users");
        $list = [];
        foreach ($stmt->fetchAll() as $r) {
            if (user_has_any_role($r['role'], [ROLE_ADMIN, ROLE_FINANCE])) {
                $list[] = $r['userid'];
            }
        }
    }
    if (empty($list)) {
        return;
    }
    $db = get_db();
    // 一次性查询所有接收人的 wecom_userid，避免 N+1 循环查询
    $placeholders = implode(',', array_fill(0, count($list), '?'));
    $ws = $db->prepare("SELECT userid, wecom_userid FROM users WHERE userid IN ($placeholders)");
    $ws->execute(array_values($list));
    $map = [];
    foreach ($ws->fetchAll() as $r) {
        $map[$r['userid']] = $r['wecom_userid'];
    }
    foreach ($list as $uid) {
        $notifyUid = !empty($map[$uid]) ? $map[$uid] : $uid;
        $msgtype = $tpl['msgtype'] ?? 'text';
        $title   = $tpl['title'] ?? '';
        $sendOk = false;
        if ($msgtype === 'template_card' && $title !== '') {
            $r = wecom_send_template_card($notifyUid, $title, $msg, '');
            $sendOk = $r['ok'];
        } elseif ($msgtype === 'markdown') {
            $sendOk = wecom_send_markdown($notifyUid, $msg);
        } else {
            $sendOk = wecom_send_text($notifyUid, $msg);
        }
        if (!$sendOk) {
            write_log('push_notify.log', date('Y-m-d H:i:s') . " notify_push_result uid={$uid} wecom_userid={$notifyUid} result=FAIL");
        }
    }
}

/**
 * 员工匹配：纯字母→企微userid，纯汉字→姓名，未匹配则创建 HIST_ 存档账号。
 * 供工资条上传、历史导入、奖金上传共用，消除三处重复逻辑。
 *
 * @param PDO $db
 * @param string $rawVal 第一列原始值
 * @param string $mode   'normal'=未匹配创建到"未匹配"部门；'history'=未匹配创建到"已离职"部门
 * @return array ['userid'=>string|null, 'name'=>string, 'dept_name'=>string, 'is_hist'=>bool]
 */
function match_employee($db, $rawVal, $mode = 'normal') {
    $rawVal = trim((string)$rawVal);
    if ($rawVal === '') {
        return ['userid' => null, 'name' => '', 'dept_name' => '', 'is_hist' => false];
    }

    $userid = null;
    $empName = '';
    $deptName = '';
    $isHist = false;

    // normal 模式排除 HIST_ 账号：避免误命中历史导入创建的存档账号
    $histFilter = ($mode === 'normal') ? " AND u.userid NOT LIKE 'HIST_%'" : '';

    if (preg_match('/^[A-Za-z]+$/', $rawVal)) {
        $ck = $db->prepare("SELECT u.userid, u.name, d.name AS dept_name FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id WHERE u.userid = ?" . $histFilter);
        $ck->execute([$rawVal]);
        $hit = $ck->fetch();
        if ($hit) {
            $userid = $hit['userid'];
            $empName = $hit['name'];
            $isHist = (strpos($hit['userid'], 'HIST_') === 0);
            $deptName = $isHist ? '已离职' : normalize_dept_name($hit['dept_name'] ?: '');
        }
    } elseif (preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $rawVal)) {
        $ck = $db->prepare("SELECT u.userid, u.name, d.name AS dept_name FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id WHERE u.name = ?" . $histFilter);
        $ck->execute([$rawVal]);
        $hit = $ck->fetch();
        if ($hit) {
            $userid = $hit['userid'];
            $empName = $hit['name'];
            $isHist = (strpos($hit['userid'], 'HIST_') === 0);
            $deptName = $isHist ? '已离职' : normalize_dept_name($hit['dept_name'] ?: '');
        }
    }

    if ($userid === null) {
        $empName = $rawVal;
        // normal 模式用 HIST_U_ 前缀，history 模式保持 HIST_ 前缀（兼容旧数据）
        $fakeUid = ($mode === 'history')
            ? 'HIST_' . strtolower(substr(md5($empName), 0, 10))
            : 'HIST_U_' . strtolower(substr(md5($empName), 0, 9));
        $deptId = $mode === 'history'
            ? find_or_create_dept_by_name($db, '已离职')
            : find_or_create_dept_by_name($db, '未匹配');
        $ignoreSql = (defined('DB_TYPE') && DB_TYPE === 'mysql')
            ? "INSERT IGNORE INTO users (userid,name,role,wecom_userid,dept_id) VALUES (?,?,?,?,?)"
            : "INSERT OR IGNORE INTO users (userid,name,role,wecom_userid,dept_id) VALUES (?,?,?,?,?)";
        try {
            $db->prepare($ignoreSql)->execute([$fakeUid, $empName, 'employee', null, $deptId]);
        } catch (Throwable $t) {
            error_log('match_employee INSERT failed: ' . $t->getMessage());
        }
        $ck2 = $db->prepare("SELECT userid, name FROM users WHERE userid = ?");
        $ck2->execute([$fakeUid]);
        $hit2 = $ck2->fetch();
        if ($hit2) {
            $userid = $hit2['userid'];
            $empName = $hit2['name'] ?: $empName;
            $deptName = $mode === 'history' ? '已离职' : '未匹配';
            $isHist = true;
        }
    }

    return ['userid' => $userid, 'name' => $empName, 'dept_name' => $dept_name, 'is_hist' => $isHist];
}

// ========================================================================
// 年假：核心计算、余额查询、审批同步骨架
// ========================================================================

/**
 * 读取并缓存年假规则（vacation_rules 表，主键 year）。
 * 规则由 DB 层默认初始化（schema_mysql.sql），admin 可在「年假规则」页调整。
 *
 * @param int|null $year 年份，默认当年
 * @return array { min_years, base_days, step_days, max_days, precisions, allow_cross_year }
 */
function vacation_get_rules($year = null) {
    if ($year === null) $year = (int)date('Y');
    static $cache = [];
    if (isset($cache[$year])) return $cache[$year];
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM vacation_rules WHERE year = ? LIMIT 1");
        $stmt->execute([$year]);
        $row = $stmt->fetch();
        if (!$row) {
            // 未定义时回退到默认规则（和 schema 默认保持一致），防止页面崩
            $row = [
                'year'               => $year,
                'min_years'          => 3,
                'base_days'          => 3,
                'year1_prorate'      => 1,
                'increment_per_year' => 1,
                'max_days'           => 10,
                'cap1_max_days'      => 10,
                'cap2_min'           => 20,
                'cap2_max_days'      => 15,
                'round_precision'    => 2,
                'allow_cross_year'   => 0,
            ];
        }
    } catch (Throwable $e) {
        $row = null;
    }
    if ($row === null) {
        $row = [
            'year'               => $year,
            'min_years'          => 3,
            'base_days'          => 3,
            'year1_prorate'      => 1,
            'increment_per_year' => 1,
            'max_days'           => 10,
            'cap1_max_days'      => 10,
            'cap2_min'           => 20,
            'cap2_max_days'      => 15,
            'round_precision'    => 2,
            'allow_cross_year'   => 0,
        ];
    }
    // 列名别名统一：上游代码与前端规则页使用 step_days/precision，避免改整个调用链
    if (!isset($row['step_days']))         $row['step_days']         = $row['increment_per_year'] ?? 1;
    if (!isset($row['precision']))         $row['precision']         = $row['round_precision']   ?? 2;
    if (!isset($row['year1_prorate']))     $row['year1_prorate']     = 1;
    if (!isset($row['increment_per_year']))$row['increment_per_year']= $row['step_days']         ?? 1;
    if (!isset($row['round_precision']))   $row['round_precision']   = $row['precision']         ?? 2;
    if (!isset($row['allow_cross_year']))  $row['allow_cross_year']  = 0;
    // 档1封顶：优先用新列 cap1_max_days，缺失则回退旧列 max_days（兼容历史数据）
    if (!isset($row['cap1_max_days']) || $row['cap1_max_days'] === null || $row['cap1_max_days'] === '') {
        $row['cap1_max_days'] = isset($row['max_days']) && $row['max_days'] !== '' ? (float)$row['max_days'] : 10;
    }
    if (!isset($row['cap2_min'])      || $row['cap2_min']      === null || $row['cap2_min']      === '') $row['cap2_min']      = 20;
    if (!isset($row['cap2_max_days']) || $row['cap2_max_days'] === null || $row['cap2_max_days'] === '') $row['cap2_max_days'] = 15;
    if (!isset($row['max_days']))     $row['max_days'] = $row['cap1_max_days']; // 旧列兜底
    if (!isset($row['year']))         $row['year']     = $year;
    $cache[$year] = $row;
    return $row;
}

/**
 * 年假应享天数专用：按规则**条件向下取整**。
 * 业务规则：entitlement >= 3 天 → floor 取整数（9.8→9，9.2→9）；
 *          entitlement < 3 天（例如首年折算 2.x 天）→ 保留原小数，不截断。
 * 为啥这样：首年不足一整年可能只享 2.4 天这种"碎天数"，直接 floor 变 2 差别不大，
 * 但按业务方最新口径，<3 保留原值、>=3 才统一取整，以兼顾公平和观感。
 *
 * @param float|int $value
 * @param int       $precision  仅当需保留小数时生效（<3 时用于规整精度；>=3 时忽略，强制按 0 取整）
 * @return float
 */
function _vacation_floor_precision($value, $precision = 0) {
    $precision = (int)$precision;
    $value = (float)$value;
    // >=3 天：强制取整，忽略精度参数
    if ($value >= 3.0) {
        return (float)floor($value);
    }
    // <3 天：保留原值，按精度规整（不 floor，只做 round 对齐避免 IEEE 754 尾巴）
    if ($precision <= 0) {
        return $value;
    }
    $factor = 10 ** $precision;
    return round($value * $factor) / $factor;
}

/**
 * 计算员工某自然年的年假应享天数（Entitlement）。
 *
 * 核心规则（业务方 2026-08 确认版）：
 *   1. 工龄未满 min_years 年 → 0 天。
 *   2. 满 min_years 年的"当年"（首年）：entitlement = remainMonths / 12 × base，
 *      remainMonths = 12 - annivMonth（满年当月不计，下月起至12月）。首年无 floor，
 *      保留原小数（因为 remainMonths 最多 11，×base=3 时最大 2.75 < 3，无需 floor）。
 *   3. 首年之后的"下一年及以后"：与首年折算值无关，从 base 起算重新递增：
 *        k = forYear - startYear（k=1 表示"首年后的下一年"）
 *        raw = base + step × (k - 1)
 *      → 封顶方案 A（按未封顶公式结果判断，非按工龄年限）：
 *            若 cap1 < raw < cap2_min  → 固定 cap1_max_days
 *            若 raw >= cap2_min        → 固定 cap2_max_days
 *      → 后续 raw 最小 = base(默认3) → _vacation_floor_precision 会 floor 整数。
 *
 * 业务方三组验例（min=3, base=3, step=1, cap1=10, cap2_min=20, cap2_max=15）：
 *   hire=2016-04-07  → 2019=2, 2020=3, 2021=4, … 2026=9, 2027=10, 2036=10, 2037+=15
 *   hire=2016-01-15  → 2019=2.75, 2020=3, 2021=4, … 2026=9, 2027=10, 2036=10, 2037+=15
 *   hire=2016-11-15  → 2019=0.25, 2020=3, 2021=4, … 2026=9, 2027=10, 2036=10, 2037+=15
 *
 * @param string|DateTime $hireDate  入职日期，任何能被 strtotime/DateTime 解析的格式
 * @param int|null       $forYear   要计算的年份（默认当年）
 * @param array|null     $rule      可选，自定义规则（默认读 vacation_rules 表）
 * @return array { entitlement, hire_date, anniversary_of_year, remaining_months, detail: string, eligible: bool }
 */
function vacation_calc_entitlement($hireDate, $forYear = null, array $rule = null) {
    if ($forYear === null) $forYear = (int)date('Y');
    $rule = $rule ?? vacation_get_rules($forYear);
    $out = [
        'entitlement'          => 0.0,
        'hire_date'            => null,
        'anniversary_of_year'  => null,
        'remaining_months'     => 0,
        'detail'               => '',
        'eligible'             => false,
    ];

    if ($hireDate === null || $hireDate === '') {
        $out['detail'] = '未录入入职日期';
        return $out;
    }
    try {
        $hd = $hireDate instanceof DateTime ? $hireDate : new DateTime((string)$hireDate);
    } catch (Throwable $e) {
        $out['detail'] = '入职日期格式无效';
        return $out;
    }
    $out['hire_date'] = $hd->format('Y-m-d');

    $minYears = (int)($rule['min_years'] ?? 3);
    $anniv    = (clone $hd)->modify("+{$minYears} years");
    $out['anniversary_of_year'] = $anniv->format('Y-m-d');

    $startYear = (int)$anniv->format('Y');
    if ($forYear < $startYear) {
        $out['detail'] = "{$forYear} 年尚未满工龄（满 {$minYears} 年日期：{$anniv->format('Y-m-d')}）";
        return $out;
    }
    $out['eligible'] = true;

    $base        = (float)($rule['base_days']          ?? 3);
    $step        = (float)($rule['step_days']          ?? 1);
    $cap1        = (float)($rule['cap1_max_days']      ?? 10);
    $cap2Min     = (float)($rule['cap2_min']           ?? 20);
    $cap2Max     = (float)($rule['cap2_max_days']      ?? 15);
    $precision   = (int)  ($rule['precision']          ?? 2);

    if ($forYear === $startYear) {
        // 首年：满年当月不计，从下月起到 12 月的月份数
        $remainMonths = 12 - (int)$anniv->format('n');
        $out['remaining_months'] = $remainMonths;
        $entitlement = ($remainMonths / 12.0) * $base;
        // 首年按业务规则不做 floor（最大 11/12×3=2.75 永远<3），只按精度规整浮点尾巴
        if ($precision > 0) {
            $factor = 10 ** $precision;
            $entitlement = round($entitlement * $factor) / $factor;
        }
        $out['entitlement'] = $entitlement;
        $out['detail']      = "首年（满 {$minYears} 年在 {$anniv->format('Y-m-d')}），剩余月数 {$remainMonths}/12 × {$base} = {$entitlement} 天";
        return $out;
    }

    // 第二年及以后：从 base 重新开始递增（与首年折算值无关）
    $k   = $forYear - $startYear;              // k=1 → "下一年"
    $raw = $base + $step * ($k - 1);           // k=1 → base

    // 方案 A：按未封顶公式结果做两档封顶
    if ($raw > $cap1 && $raw < $cap2Min) {
        $rawBeforeCap = $raw;
        $raw = $cap1;
        $capTag = "（{$rawBeforeCap} 处于 档1：>{$cap1} 且 <{$cap2Min} → 封顶 {$cap1}）";
    } elseif ($raw >= $cap2Min) {
        $rawBeforeCap = $raw;
        $raw = $cap2Max;
        $capTag = "（{$rawBeforeCap} ≥ {$cap2Min} → 档2 封顶 {$cap2Max}）";
    } else {
        $capTag = '';
    }

    $entitlement = _vacation_floor_precision($raw, $precision);
    $out['remaining_months'] = 12 - (int)$anniv->format('n'); // 仅用于展示口径
    $out['entitlement']      = $entitlement;
    $out['detail']           = "满 {$minYears} 年日期 {$anniv->format('Y-m-d')}（startYear={$startYear}），"
                             . "第 {$k} 年（k=1 为下一年）：base {$base} + step {$step}×($k-1) = "
                             . ($base + $step * ($k - 1))
                             . ($capTag ? " 天 {$capTag}，最终 floor={$entitlement} 天" : " 天，floor={$entitlement} 天");
    return $out;
}

/**
 * 计算指定员工在指定年份的年假余额。
 *
 * 余额 = 当年应享 entitlement
 *      + 当年 vacation_ledger 中 manual(2)/system(3) 合计（正负通用）
 *      + 当年审批通过 source=1 扣减（delta < 0，会在合计中自然减去）
 *      + 当年已通过后撤销 source=4 冲回（delta > 0，会在合计中自然加回）
 * 即：所有 source 合并求和后直接加到 entitlement 上。
 *
 * 注意：本项目业务规则明确"不允许跨年休假"，因此 ledger 只统计当年 year（approve_year）。
 *
 * @param PDO    $db
 * @param string $userid    内部 users.userid
 * @param int|null $forYear 年份，默认当年
 * @return array { entitlement, used_days, manual_delta, ledger_sum, balance, approval_count }
 */
function vacation_calc_balance($db, $userid, $forYear = null) {
    if ($forYear === null) $forYear = (int)date('Y');
    $rule = vacation_get_rules($forYear);

    $hireDate = null;
    $vacationExempt = 0;
    $vacationRuleType = 0;
    $vacationSpecialStart = null;
    $vacationSpecialDays = 0;
    $vacationSpecialCap = 15;
    try {
        $stmt = $db->prepare("SELECT hire_date, vacation_exempt, vacation_rule_type, vacation_special_start, vacation_special_days, vacation_special_cap FROM users WHERE userid = ? LIMIT 1");
        $stmt->execute([$userid]);
        $row = $stmt->fetch();
        if ($row) {
            if (!empty($row['hire_date'])) $hireDate = $row['hire_date'];
            $vacationExempt = (int)($row['vacation_exempt'] ?? 0);
            $vacationRuleType = (int)($row['vacation_rule_type'] ?? 0);
            $vacationSpecialStart = $row['vacation_special_start'] ?? null;
            $vacationSpecialDays = (int)($row['vacation_special_days'] ?? 0);
            $vacationSpecialCap = (int)($row['vacation_special_cap'] ?? 15);
        }
    } catch (Throwable $e) {}

    // 前置判断：年假豁免人员，无论工龄直接返回 eligible=false，entitlement=0
    // 历史流水（ledger）保留不动，余额 = 0 + 历史 ledger sum（可能为负）
    if ($vacationExempt === 1) {
        $precision = (int)($rule['precision'] ?? 2);
        $ledgerSum = 0.0;
        $manualDelta = 0.0;
        $usedDays = 0.0;
        $approvalCount = 0;
        try {
            $stmt = $db->prepare(
                "SELECT source, SUM(delta) AS delta_sum, COUNT(*) AS cnt FROM vacation_ledger
                 WHERE userid = ? AND year = ? GROUP BY source"
            );
            $stmt->execute([$userid, $forYear]);
            $rows = $stmt->fetchAll();
            $bySource = [];
            foreach ($rows as $r) { $bySource[(int)$r['source']] = (float)$r['delta_sum']; }
            $ledgerSum = array_sum($bySource);
            $manualDelta = (float)($bySource[2] ?? 0) + (float)($bySource[3] ?? 0);
            $approvalDelta = (float)($bySource[1] ?? 0) + (float)($bySource[4] ?? 0);
            $usedDays = -$approvalDelta;
            if ($usedDays < 0) $usedDays = 0.0;
            $cntStmt = $db->prepare(
                "SELECT COUNT(DISTINCT ref_no) AS c FROM vacation_ledger
                 WHERE userid = ? AND year = ? AND source IN (1,4) AND ref_no IS NOT NULL AND ref_no <> ''"
            );
            $cntStmt->execute([$userid, $forYear]);
            $c = $cntStmt->fetch();
            $approvalCount = $c ? (int)$c['c'] : 0;
        } catch (Throwable $e) {}
        $balance = round(0 + $ledgerSum, $precision);
        return [
            'year'            => $forYear,
            'hire_date'       => $hireDate,
            'entitlement'     => 0,
            'used_days'       => round($usedDays, $precision),
            'manual_delta'    => round($manualDelta, $precision),
            'ledger_sum'      => round($ledgerSum, $precision),
            'balance'         => $balance,
            'approval_count'  => $approvalCount,
            'eligible'        => false,
            'eligible_detail' => '根据人员设置，不享受年假',
            'vacation_exempt' => 1,
        ];
    }

    // 特殊规则类型 1：每两年增加一天（不依赖入职日期）
    if ($vacationRuleType === 1 && !empty($vacationSpecialStart) && $vacationSpecialDays > 0) {
        $startYear = (int)date('Y', strtotime($vacationSpecialStart));
        $yearDiff = $forYear - $startYear;
        $rawDays = $vacationSpecialDays + (int)floor($yearDiff / 2);
        $cap = $vacationSpecialCap > 0 ? $vacationSpecialCap : 15;
        $entitlement = min($rawDays, $cap);
        $entRes = [
            'entitlement'          => (float)$entitlement,
            'hire_date'            => null,
            'anniversary_of_year'  => null,
            'remaining_months'     => 0,
            'eligible'             => true,
            'detail'               => "特殊规则：起点 {$startYear} 年 {$vacationSpecialDays} 天，每两年 +1 天，封顶 {$cap} 天。yearDiff={$yearDiff}，应享 {$entitlement} 天",
        ];
    } else {
        $entRes = vacation_calc_entitlement($hireDate, $forYear, $rule);
        $entitlement = (float)$entRes['entitlement'];
    }

    $ledgerSum     = 0.0;
    $manualDelta   = 0.0;
    $usedDays      = 0.0;
    $approvalCount = 0;
    try {
        $stmt = $db->prepare(
            "SELECT source, SUM(delta) AS delta_sum, COUNT(*) AS cnt FROM vacation_ledger
             WHERE userid = ? AND year = ? GROUP BY source"
        );
        $stmt->execute([$userid, $forYear]);
        $rows = $stmt->fetchAll();
        $bySource = [];
        foreach ($rows as $r) { $bySource[(int)$r['source']] = (float)$r['delta_sum']; }
        $ledgerSum = array_sum($bySource);
        $manualDelta = (float)($bySource[2] ?? 0) + (float)($bySource[3] ?? 0); // manual + system
        // used_days 仅统计"实际审批扣减净额"= 撤销(source=4) + 审批通过(source=1)；
        // 业务上审批通过 = 扣减，source=1 delta<0，通过后撤销冲回 source=4 delta>0，两者之和即为"已休假净天数（已发生）"
        $approvalDelta = (float)($bySource[1] ?? 0) + (float)($bySource[4] ?? 0);
        $usedDays = -$approvalDelta; // 正数 = 已经休假多少天
        if ($usedDays < 0) $usedDays = 0.0; // 若撤销多于通过（极端场景），置 0 避免误导
        // 统计涉及的审批单数量（只统计 source=1 和 4，避免 manual 污染）
        $cntStmt = $db->prepare(
            "SELECT COUNT(DISTINCT ref_no) AS c FROM vacation_ledger
             WHERE userid = ? AND year = ? AND source IN (1,4) AND ref_no IS NOT NULL AND ref_no <> ''"
        );
        $cntStmt->execute([$userid, $forYear]);
        $c = $cntStmt->fetch();
        $approvalCount = $c ? (int)$c['c'] : 0;
    } catch (Throwable $e) {}

    // balance / manual_delta / used_days 允许保留 precision 位小数（手动调整会有 0.88 这种碎天数）；
    // 但 entitlement 单独遵循业务口径：>=3 取整数，<3 保留原小数（不再被 round(precision=2) 格式化）。
    $precision = (int)($rule['precision'] ?? 2);
    $balance = round($entitlement + $ledgerSum, $precision);
    return [
        'year'            => $forYear,
        'hire_date'       => $entRes['hire_date'],
        'entitlement'     => _vacation_floor_precision($entitlement, $precision),
        'used_days'       => round($usedDays, $precision),
        'manual_delta'    => round($manualDelta, $precision),
        'ledger_sum'      => round($ledgerSum, $precision),
        'balance'         => $balance,
        'approval_count'  => $approvalCount,
        'eligible'        => $entRes['eligible'],
        'eligible_detail' => $entRes['detail'],
        'vacation_exempt' => 0,
    ];
}

/**
 * 审批同步：按设置中的 template_id 批量拉取最近审批单号（过去 N 天），
 * 逐条调用 vacation_process_sp_detail 入库并写入/冲回 ledger（幂等）。
 * 同步完成后会更新 settings.vacation_last_sync_at。
 *
 * 此函数由 approval_callback.php（回调增量触发）和 cron_worker（定时兜底）共用。
 *
 * @param int|null $lookbackDays 回看天数，默认 30 天（避免跨度过大的超时）
 * @return array { processed, inserted_approvals, ledger_changes, errors[], skipped }
 */
function vacation_sync_approvals($lookbackDays = 30) {
    $result = [
        'processed'          => 0,
        'inserted_approvals' => 0,
        'ledger_changes'     => 0,
        'errors'             => [],
        'skipped'            => 0,
    ];
    $templateId = (string)get_setting('vacation_template_id', '');
    if ($templateId === '') {
        $result['errors'][] = 'vacation_template_id 未配置';
        return $result;
    }
    $endTime   = time();
    $startTime = $endTime - max(1, (int)$lookbackDays) * 86400;
    // 只拉取 settings.vacation_approval_statuses 配置的状态（默认 [1,2] = 审批中+已通过），避免拉到太多历史已撤销/驳回
    $statusCfg = (string)get_setting('vacation_approval_statuses', '[1,2]');
    $statusArr = [];
    $decoded = json_decode($statusCfg, true);
    if (is_array($decoded)) $statusArr = array_map('intval', $decoded);
    $list = wecom_get_approval_list($templateId, $startTime, $endTime, 100, null,
        empty($statusArr) ? null : $statusArr);
    if (!$list['ok']) {
        $result['errors'][] = 'wecom_get_approval_list 失败: ' . ($list['errmsg'] ?? 'unknown')
            . '（errcode=' . ($list['errcode'] ?? -1) . '）';
        return $result;
    }
    $spNos = $list['sp_nos'] ?? [];
    foreach ($spNos as $spNo) {
        $result['processed']++;
        try {
            $r = vacation_process_sp_detail($spNo);
            if (!empty($r['error'])) {
                $result['errors'][] = "sp_no={$spNo}: " . $r['error'];
            } else {
                if (!empty($r['approval_inserted'])) $result['inserted_approvals']++;
                if (!empty($r['ledger_changed']))     $result['ledger_changes']++;
                if (!empty($r['skipped']))            $result['skipped']++;
            }
        } catch (Throwable $e) {
            $result['errors'][] = "sp_no={$spNo}: " . $e->getMessage();
        }
    }
    // 更新最后同步时间（无论部分失败都写，避免 cron 每次都在同一批上重跑造成限流）
    set_setting('vacation_last_sync_at', date('Y-m-d H:i:s'));
    return $result;
}

/**
 * 处理单条审批单详情：
 *   1. 拉取审批详情；
 *   2. upsert 到 vacation_approvals（保存原始 apply_data 快照便于事后追溯）；
 *   3. 只有当 sp_status ∈ {2 已通过, 6 已通过后撤销} 才会写入/冲回 vacation_ledger（幂等）：
 *        - source=1 审批通过：delta = -days（扣减）
 *        - source=4 审批撤销：delta = +days（冲回）
 *   4. 若 users.hire_date 为空且审批单中包含"入职日期"控件值，则回填（不覆盖已填值）。
 *
 * 幂等性保证：
 *   vacation_ledger 中 (userid, year, source, sp_no) 唯一；若已存在对应记录则先删除再写入，
 *   以便重复回调不产生重复 delta。
 *
 * @param string $spNo       审批单号
 * @param bool   $handleOwnTx 是否自己开启/提交/回滚内部事务（默认 true，独立调用用）。
 *                            传 false 时跳过事务控制，由外层统一管理（用于 dry_run 外层大事务最后统一 ROLLBACK）
 * @return array { error|null, approval_inserted, ledger_changed, skipped, days, sp_status, userid, name }
 */
function vacation_process_sp_detail($spNo, $handleOwnTx = true) {
    $out = ['error'=>null, 'approval_inserted'=>false, 'ledger_changed'=>false, 'skipped'=>false,
            'days'=>0.0, 'sp_status'=>0, 'userid'=>null, 'name'=>''];

    $templateId = (string)get_setting('vacation_template_id', '');
    $hireIdx    = (string)get_setting('vacation_hire_date_idx', '2');
    $drIdx      = (string)get_setting('vacation_daterange_idx', '5');
    if ($templateId === '' || $drIdx === '') { $out['error'] = '年假同步参数未配置'; return $out; }

    $detail = wecom_get_approval_detail($spNo);
    if (empty($detail['ok'])) {
        $out['error'] = '拉取审批详情失败: ' . ($detail['errmsg'] ?? 'unknown');
        return $out;
    }
    // 统一填充模板标题（供 audit_log / callback 层生成可读说明，非年假模板时尤其有用）
    $out['sp_name'] = (string)($detail['sp_name'] ?? '');
    // 收紧：template_id 缺失时一律视为"非目标模板"，避免空值绕过过滤后被当成年假单写入
    $detailTplId = (string)($detail['template_id'] ?? '');
    if ($detailTplId !== $templateId) {
        $out['skipped'] = true;
        $out['skip_reason'] = 'wrong_template';
        $out['got_template_id'] = $detailTplId;
        $out['want_template_id'] = $templateId;
        // 非年假模板也解析申请人姓名，供回调日志/审计日志展示
        $applyerWecom = (string)($detail['apply_userid'] ?? '');
        $applyerName  = (string)($detail['apply_name'] ?? '');
        if ($applyerWecom !== '' || $applyerName !== '') {
            try {
                $dbTmp = get_db();
                if ($applyerWecom !== '') {
                    $stmtTmp = $dbTmp->prepare("SELECT name FROM users WHERE wecom_userid = ? LIMIT 1");
                    $stmtTmp->execute([$applyerWecom]);
                    $rowTmp = $stmtTmp->fetch();
                    if ($rowTmp) { $applyerName = (string)$rowTmp['name']; }
                }
                if ($applyerName === '' && $applyerWecom !== '') {
                    $stmtTmp = $dbTmp->prepare("SELECT name FROM users WHERE userid = ? LIMIT 1");
                    $stmtTmp->execute([$applyerWecom]);
                    $rowTmp = $stmtTmp->fetch();
                    if ($rowTmp) { $applyerName = (string)$rowTmp['name']; }
                }
            } catch (Throwable $e) {}
        }
        $out['name'] = $applyerName;
        $out['apply_userid'] = $applyerWecom;
        return $out;
    }
    $spStatus = (int)($detail['sp_status'] ?? 0);
    $out['sp_status'] = $spStatus;

    // 定位内部账号
    $db = get_db();
    $applyerWecom = (string)($detail['apply_userid'] ?? ''); // 企微 userid
    $applyerName   = (string)($detail['apply_name'] ?? '');
    $out['name']   = $applyerName;
    $userid = null;
    try {
        if ($applyerWecom !== '') {
            $stmt = $db->prepare("SELECT userid FROM users WHERE wecom_userid = ? LIMIT 1");
            $stmt->execute([$applyerWecom]);
            $row = $stmt->fetch();
            if ($row) $userid = (string)$row['userid'];
        }
        if ($userid === null && $applyerName !== '') {
            // 兜底按姓名查（避免企微 userid 未在通讯录映射的情况）
            $stmt = $db->prepare("SELECT userid FROM users WHERE name = ? LIMIT 1");
            $stmt->execute([$applyerName]);
            $row = $stmt->fetch();
            if ($row) $userid = (string)$row['userid'];
        }
    } catch (Throwable $e) {}
    $out['userid'] = $userid;

    // 解析 DateRange 休假天数（按 perday_duration=28800 秒=8h/天 计算，若无则按 8h/天 折算）
    $days = 0.0;
    $year = (int)date('Y');
    try {
        $dr = wecom_approval_extract_value($detail['apply_data'] ?? [], $drIdx);
        if (is_array($dr) && isset($dr['days_8h'])) {
            $days = (float)$dr['days_8h'];
        }
        if (is_array($dr) && !empty($dr['start'])) {
            $year = (int)date('Y', strtotime($dr['start']));
        }
    } catch (Throwable $e) {}
    $out['days'] = $days;

    // 1. upsert vacation_approvals（保存快照）
    try {
        // raw_json 存储企微 info 完整 JSON（含 sp_record 审批节点 / notifyer 抄送人 / applyer 提交人 / apply_time 等全量字段）。
        // 为什么不用原来的 apply_data（仅表单控件值）：员工端弹窗要展示"审批流程节点表+抄送人+提交时间"，这些字段在 info 顶层，不在 apply_data 内；
        //   若只存 apply_data，历史审批单无法通过 API 找回（企微保留期 180 天），会永久丢失流程审计信息。
        $rawInfoJson = is_array($detail['raw'] ?? null)
            ? json_encode($detail['raw'], JSON_UNESCAPED_UNICODE)
            : (is_string($detail['raw'] ?? null) ? $detail['raw'] : '');
        $dup = (defined('DB_TYPE') && DB_TYPE === 'mysql') ? 'ON DUPLICATE KEY UPDATE' : '';
        if ($dup !== '') {
            $sql = "INSERT INTO vacation_approvals
                (sp_no, template_id, sp_name, apply_userid, apply_name, sp_status, apply_time, finish_time,
                 leave_days, leave_start, leave_end, year, raw_json, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())
                ON DUPLICATE KEY UPDATE
                    sp_status=VALUES(sp_status), finish_time=VALUES(finish_time),
                    leave_days=VALUES(leave_days), leave_start=VALUES(leave_start),
                    leave_end=VALUES(leave_end), year=VALUES(year),
                    raw_json=VALUES(raw_json), updated_at=NOW()";
        } else {
            // SQLite 兼容：先删后插
            $db->prepare("DELETE FROM vacation_approvals WHERE sp_no = ?")->execute([$spNo]);
            $sql = "INSERT INTO vacation_approvals
                (sp_no, template_id, sp_name, apply_userid, apply_name, sp_status, apply_time, finish_time,
                 leave_days, leave_start, leave_end, year, raw_json, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, datetime('now'))";
        }
        $leaveStart = is_array($dr ?? null) ? ($dr['start_dt'] ?? $dr['start'] ?? null) : null;
        $leaveEnd   = is_array($dr ?? null) ? ($dr['end_dt']   ?? $dr['end']   ?? null) : null;
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $spNo, $detail['template_id'] ?? $templateId, $detail['sp_name'] ?? '',
            $applyerWecom, $applyerName, $spStatus,
            $detail['apply_time'] ?? null, $detail['finish_time'] ?? null,
            $days, $leaveStart, $leaveEnd, $year, $rawInfoJson,
        ]);
        $out['approval_inserted'] = true;
    } catch (Throwable $e) {
        $out['error'] = '写入 vacation_approvals 失败: ' . $e->getMessage();
        return $out;
    }

    // 2. 尝试回填 users.hire_date（仅当 users.hire_date 为空且审批单含入职日期）
    if ($userid !== null && $hireIdx !== '') {
        try {
            $hd = wecom_approval_extract_value($detail['apply_data'] ?? [], $hireIdx);
            if (!empty($hd)) {
                if (is_array($hd) && isset($hd['date']))      $hd = $hd['date'];
                elseif (is_array($hd) && isset($hd['s_value'])) $hd = $hd['s_value'];
                if (is_string($hd) && $hd !== '') {
                    // 空值才写入，不覆盖 HR 已维护的值
                    $stmt = $db->prepare("UPDATE users SET hire_date = ? WHERE userid = ? AND (hire_date IS NULL OR hire_date = '')");
                    $stmt->execute([$hd, $userid]);
                }
            }
        } catch (Throwable $e) {}
    }

    // 3. 写入/冲回 vacation_ledger：只处理 2（通过）、6（已通过后撤销）
    //    如果 userid 未找到，只在 vacation_approvals 存快照，不写 ledger（日志记录）
    if ($userid === null) {
        write_log('vacation_sync.log', date('Y-m-d H:i:s') . " sp_no={$spNo} applyer={$applyerName}/{$applyerWecom} SKIP: 未匹配内部账号");
        $out['error'] = '未匹配到内部账号（users.wecom_userid / name 均未命中），已保存审批快照待 HR 手动处理';
        $out['skip_reason'] = 'wrong_account';
        return $out;
    }
    if (!in_array($spStatus, [2, 6], true)) {
        $out['skipped'] = true;
        $out['skip_reason'] = 'pending_status';
        return $out;
    }
    if ($days <= 0) {
        $out['skipped'] = true;
        $out['skip_reason'] = 'zero_days';
        return $out;
    }

    $source  = ($spStatus === 2) ? 1 : 4;          // 1=审批通过(扣)，4=审批撤销(加)
    $sign    = ($spStatus === 2) ? -1.0 : +1.0;    // 扣减用负，冲回用正
    $delta   = round($sign * $days, 2);
    $note    = ($spStatus === 2 ? '审批通过扣减' : '审批撤销冲回') . " sp_no={$spNo}";
    try {
        if ($handleOwnTx) $db->beginTransaction();
        // 幂等：同 (userid, year, source, sp_no) 先删再插
        $db->prepare(
            "DELETE FROM vacation_ledger WHERE userid = ? AND year = ? AND source = ? AND ref_no = ?"
        )->execute([$userid, $year, $source, $spNo]);
        if ($delta != 0) {
            $inserted = $db->prepare(
                "INSERT INTO vacation_ledger
                    (userid, name, year, source, delta, ref_no, remark, op_by)
                 VALUES (?,?,?,?,?,?,?,?)"
            )->execute([$userid, '', $year, $source, $delta, $spNo, $note, 'SYSTEM']);
            if ($inserted) $out['ledger_changed'] = true;
        }
        if ($handleOwnTx) $db->commit();
    } catch (Throwable $e) {
        if ($handleOwnTx && $db->inTransaction()) try { $db->rollBack(); } catch (Throwable $e2) {}
        $out['error'] = '写入 vacation_ledger 失败: ' . $e->getMessage();
        return $out;
    }
    return $out;
}

// ======================== 定时任务统一入口辅助函数 ========================

/**
 * 获取全局锁（防止 task=all 并发执行导致重复推送/重复扣减）。
 * 锁存放在 settings.timer_global_lock，值为 JSON：{owner, expire_ts}
 * 锁超时 10 分钟：若上一次持有者异常崩溃未释放，下一次请求可强制获取。
 *
 * @param string $owner  锁持有者标识（建议用 PID+time，便于排查）
 * @param int    $ttlSec 锁超时秒数，默认 600（10 分钟）
 * @return bool  true=获取成功，false=锁被其他有效持有者占用
 */
function timer_acquire_global_lock($owner, $ttlSec = 600) {
    $db = get_db();
    $now = time();
    try {
        // 先读现有锁
        $cur = (string)get_setting('timer_global_lock', '');
        if ($cur !== '') {
            $arr = json_decode($cur, true);
            if (is_array($arr) && !empty($arr['expire_ts'])) {
                $expTs = (int)$arr['expire_ts'];
                $curOwner = (string)($arr['owner'] ?? '');
                // 同一 owner 重入（如代码内部重复调用）允许；或锁已过期则可抢占
                if ($curOwner !== $owner && $now < $expTs) {
                    return false;
                }
            }
        }
        $val = json_encode([
            'owner'     => $owner,
            'acquire_ts'=> $now,
            'expire_ts' => $now + max(60, (int)$ttlSec),
        ], JSON_UNESCAPED_UNICODE);
        set_setting('timer_global_lock', $val);
        return true;
    } catch (Throwable $e) {
        error_log('[timer] acquire lock error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 释放全局锁。仅当当前 owner 匹配时才删除，避免误删其他并发任务的锁。
 */
function timer_release_global_lock($owner) {
    try {
        $cur = (string)get_setting('timer_global_lock', '');
        if ($cur === '') return true;
        $arr = json_decode($cur, true);
        if (!is_array($arr) || empty($arr['owner']) || (string)$arr['owner'] !== (string)$owner) {
            return false;
        }
        set_setting('timer_global_lock', '');
        return true;
    } catch (Throwable $e) {
        error_log('[timer] release lock error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 检查某子任务是否达到最小运行间隔。
 * 用 settings.last_run_{$taskKey} 记录上次完成的 UTC 时间戳（秒）。
 * 注意：记录的是"完成时间"而非启动时间，避免长任务启动后 crash 导致下次长时间无法重跑。
 *
 * @param string $taskKey       子任务键（如 remind / push / vacation_sync / log_cleanup）
 * @param int    $minIntervalSec 最小间隔秒数；传 0 表示不检查间隔直接运行
 * @return bool  true=可以运行，false=距上次完成时间不足 minIntervalSec
 */
function timer_check_last_run($taskKey, $minIntervalSec) {
    $minIntervalSec = max(0, (int)$minIntervalSec);
    if ($minIntervalSec === 0) return true;
    $last = (int)get_setting('last_run_' . $taskKey, '0');
    if ($last <= 0) return true;
    return (time() - $last) >= $minIntervalSec;
}

/**
 * 记录某子任务已完成（写入 settings.last_run_{$taskKey}=当前时间戳）。
 * 由 cron 各子任务完成后分别调用。
 */
function timer_update_last_run($taskKey) {
    set_setting('last_run_' . $taskKey, (string)time());
}

/**
 * 检查旧 crontab（task=remind/push 独立触发）的去重兜底。
 * 设计：当部署人员保留了旧的单任务 cron 配置时，新增 timer_last_run_legacy_{$taskKey}
 * 记录各任务独立触发的最后时间，避免 task=all 与 task=remind/push 在同一时间窗口重复执行。
 * 注意：此兜底只在同一进程调用 run_remind()/run_scheduled_push() 路径之前检查，
 *      不替代各自内部的去重（last_remind_at / last_auto_push）。
 *
 * @param string $taskKey       'remind' | 'push'
 * @param int    $minIntervalSec 独立入口与统一入口之间的最小间隔
 * @return bool  true=允许跑，false=刚由另一条入口跑完
 */
function timer_check_legacy_dedup($taskKey, $minIntervalSec = 55 * 60) {
    $last = (int)get_setting('timer_last_run_legacy_' . $taskKey, '0');
    if ($last <= 0) return true;
    return (time() - $last) >= max(0, (int)$minIntervalSec);
}

/**
 * 旧独立入口（task=remind/push）跑完后写 legacy 时间戳，与 task=all 互斥。
 */
function timer_update_legacy_dedup($taskKey) {
    set_setting('timer_last_run_legacy_' . $taskKey, (string)time());
}

/**
 * 年假审批增量同步（定时任务调用的包装函数）。
 * - 回看天数从 settings.timer_vacation_lookback_days 读，默认 2 天
 * - 同步完成后更新 vacation_last_sync_at
 *
 * @return array { processed, inserted_approvals, ledger_changes, errors[], skipped }
 */
function timer_vacation_sync_incremental() {
    $lookback = (int)get_setting('timer_vacation_lookback_days', '2');
    if ($lookback < 1) $lookback = 2;
    $res = vacation_sync_approvals($lookback);
    if (empty($res['errors'])) {
        set_setting('vacation_last_sync_at', date('Y-m-d H:i:s'));
    }
    return $res;
}

/**
 * 清理过期日志/数据（由定时任务每天 03:00-06:00 窗口触发）。
 * 保留天数从 settings 读取，未配置时使用默认值。
 * 清理对象：
 *   1. audit_log                 —— 默认 180 天（timer_keep_audit_log_days）
 *   2. approval_callback_logs    —— 默认  90 天（timer_keep_cb_log_days）
 *   3. slog 目录下 .log 文件     —— 默认  30 天（timer_keep_slog_days）
 *   4. feedback                  —— 默认 180 天（timer_keep_feedback_days）
 *
 * @return array { deleted_audit, deleted_cb, deleted_slog, deleted_feedback, errors[] }
 */
function timer_cleanup_logs() {
    $out = [
        'deleted_audit'    => 0,
        'deleted_cb'       => 0,
        'deleted_slog'     => 0,
        'deleted_feedback' => 0,
        'errors'           => [],
    ];
    $db = get_db();
    $now = time();

    $keepAudit    = max(1, (int)get_setting('timer_keep_audit_log_days',    '180'));
    $keepCb       = max(1, (int)get_setting('timer_keep_cb_log_days',       '90'));
    $keepSlog     = max(1, (int)get_setting('timer_keep_slog_days',         '30'));
    $keepFeedback = max(1, (int)get_setting('timer_keep_feedback_days',    '180'));

    // 1. audit_log
    try {
        $cutoff = date('Y-m-d H:i:s', $now - $keepAudit * 86400);
        $stmt = $db->prepare("DELETE FROM audit_log WHERE op_time < ?");
        $stmt->execute([$cutoff]);
        $out['deleted_audit'] = $stmt->rowCount();
    } catch (Throwable $e) {
        $out['errors'][] = '清理 audit_log 失败: ' . $e->getMessage();
    }

    // 2. approval_callback_logs（若表不存在则跳过，避免安装初期报错）
    //    必须按数据库类型选择系统视图，MySQL 查 information_schema，SQLite 才查 sqlite_master；
    //    判断顺序先选对 SQL 再执行，防止选错方言导致查询直接抛异常（例如 MySQL 不认识 sqlite_master）。
    try {
        $cutoff = date('Y-m-d H:i:s', $now - $keepCb * 86400);
        $tblExists = false;
        if (defined('DB_TYPE') && DB_TYPE === 'sqlite') {
            $chk = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='approval_callback_logs'");
            $tblExists = $chk && $chk->fetch() ? true : false;
        } else {
            // MySQL / MariaDB
            $chk = $db->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'approval_callback_logs' LIMIT 1");
            $chk->execute();
            $tblExists = $chk->fetch() ? true : false;
        }
        if ($tblExists) {
            $stmt = $db->prepare("DELETE FROM approval_callback_logs WHERE created_at < ?");
            $stmt->execute([$cutoff]);
            $out['deleted_cb'] = $stmt->rowCount();
        }
    } catch (Throwable $e) {
        $out['errors'][] = '清理 approval_callback_logs 失败: ' . $e->getMessage();
    }

    // 3. slog 文件（项目根目录下 slog/ 子目录，匹配 *.log）
    try {
        $slogDir = __DIR__ . '/slog';
        if (is_dir($slogDir)) {
            $cutoffTs = $now - $keepSlog * 86400;
            $files = glob($slogDir . '/*.log');
            if (is_array($files)) {
                foreach ($files as $f) {
                    $mt = @filemtime($f);
                    if ($mt !== false && $mt < $cutoffTs) {
                        if (@unlink($f)) $out['deleted_slog']++;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $out['errors'][] = '清理 slog 文件失败: ' . $e->getMessage();
    }

    // 4. feedback 表
    try {
        $cutoff = date('Y-m-d H:i:s', $now - $keepFeedback * 86400);
        $stmt = $db->prepare("DELETE FROM feedback WHERE created_at < ?");
        $stmt->execute([$cutoff]);
        $out['deleted_feedback'] = $stmt->rowCount();
    } catch (Throwable $e) {
        $out['errors'][] = '清理 feedback 失败: ' . $e->getMessage();
    }

    return $out;
}
