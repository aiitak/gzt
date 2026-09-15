<?php
/**
 * 鉴权：JWT 签发/校验、密码哈希、当前用户与角色检查
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * 签发 JWT
 */
function jwt_encode(array $payload, $ttl = null) {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    // 尊重外部传入的 exp，未传入时使用默认 JWT_EXPIRE 或 $ttl 参数
    if (!isset($payload['exp'])) {
        $payload['exp'] = time() + ($ttl !== null ? (int)$ttl : JWT_EXPIRE);
    }
    $pl = base64url_encode(json_encode($payload));
    $sig = base64url_encode(hash_hmac('sha256', "$header.$pl", JWT_SECRET, true));
    return "$header.$pl.$sig";
}

/**
 * 校验 JWT，返回 payload 或 null
 */
function jwt_decode($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    list($h, $p, $s) = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    if (!hash_equals($expected, $s)) {
        return null;
    }
    $payload = json_decode(base64url_decode($p), true);
    if (!$payload) {
        return null;
    }
    if (!empty($payload['exp']) && $payload['exp'] < time()) {
        return null;
    }
    return $payload;
}

/**
 * 从 Cookie / 参数 取当前 userid
 */
function get_current_userid() {
    // 仅从 HttpOnly Cookie 读取，避免令牌经 URL（?token=）泄露到 Referer / 代理日志
    $token = $_COOKIE[TOKEN_COOKIE] ?? '';
    if (is_array($token)) {
        $token = '';
    }
    if (!$token) {
        return null;
    }
    $payload = jwt_decode($token);
    return $payload['userid'] ?? null;
}

/**
 * 取当前登录用户记录（users 表）
 */
function get_logged_user() {
    $userid = get_current_userid();
    if (!$userid) {
        return null;
    }
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM users WHERE userid = ?");
    $stmt->execute([$userid]);
    return $stmt->fetch() ?: null;
}

/**
 * 要求已登录，否则返回 401
 */
function require_login() {
    $u = get_logged_user();
    if (!$u) {
        json_out(['success' => false, 'error' => '未登录'], 401);
    }
    // 阻断「仅历史存档」员工（userid 以 HIST_ 开头）：
    //  这些账号是为了历史导入时外键约束自动创建的，没有真实企微身份，
    //  不允许登录查看工资条 / 提交反馈 / 修改密码等任何操作。
    if (strpos((string)$u['userid'], 'HIST_') === 0) {
        json_out(['success' => false, 'error' => '该账号为历史存档账号，仅限后台查看，无法登录使用'], 403);
    }
    return $u;
}

/**
 * 验证「查看密码」cookie（pw_ok）：用户已设置密码时，必须有该 cookie
 * 且 cookie 中签名的 userid 与当前登录用户一致。
 * 返回 true 表示密码已验证通过，false 表示需要验证密码或尚未设置密码。
 */
function password_verified(&$u = null) {
    if ($u === null) {
        $u = get_logged_user();
        if (!$u) return false;
    }
    // 还没设置密码 -> 视为未验证，由上层引导去设置
    if (empty($u['has_set_password'])) return false;

    if (empty($_COOKIE['pw_ok'])) return false;
    $parts = explode('.', (string)$_COOKIE['pw_ok'], 2);
    if (count($parts) !== 2) return false;
    $expected = hash_hmac('sha256', $parts[0], JWT_SECRET);
    if (!hash_equals($expected, $parts[1])) return false;
    return $parts[0] === (string)$u['userid'];
}

/**
 * 要求已验证密码（员工查看工资数据类接口使用）。
 * 规则：
 *   - 如果用户尚未设置密码（has_set_password=0），返回 need_setup=true 让前端引导设置。
 *   - 如果已设置密码但 pw_ok cookie 未通过，返回 need_password=true 要求验证。
 */
function require_password(&$u = null) {
    if ($u === null) {
        $u = get_logged_user();
        if (!$u) {
            json_out(['success' => false, 'error' => '未登录'], 401);
        }
    }
    if (empty($u['has_set_password'])) {
        json_out(['success' => true, 'need_password' => true, 'need_setup' => true]);
    }
    if (!password_verified($u)) {
        json_out(['success' => true, 'need_password' => true, 'wrong' => false]);
    }
}

/**
 * 验证密码（bcrypt）
 */
function verify_password($plain, $hash) {
    return password_verify($plain, $hash);
}

/**
 * 生成密码哈希
 */
function hash_password($plain) {
    return password_hash($plain, PASSWORD_BCRYPT);
}

/**
 * 设置会话 Cookie
 */
function set_session_cookie($userid, $role) {
    $token = jwt_encode(['userid' => $userid, 'role' => $role]);
    setcookie(TOKEN_COOKIE, $token, [
        'expires'  => time() + JWT_EXPIRE,
        'path'     => '/',
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => 'Lax',
    ]);
    return $token;
}

function clear_session_cookie() {
    setcookie(TOKEN_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => 'Lax',
    ]);
}

/**
 * 取后台账号密码登录会话（admin_token cookie）
 */
function get_admin_session() {
    $token = $_COOKIE[ADMIN_TOKEN_COOKIE] ?? '';
    if (!$token) {
        return null;
    }
    $payload = jwt_decode($token);
    if (!$payload || empty($payload['userid'])) {
        return null;
    }
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM users WHERE userid = ?");
    $stmt->execute([$payload['userid']]);
    return $stmt->fetch() ?: null;
}

function set_admin_cookie($userid, $role) {
    // JWT 过期时间与 Cookie 保持一致（ADMIN_JWT_EXPIRE），避免令牌寿命远超 Cookie 导致冒用窗口扩大
    $token = jwt_encode(['userid' => $userid, 'role' => $role], ADMIN_JWT_EXPIRE);
    setcookie(ADMIN_TOKEN_COOKIE, $token, [
        'expires'  => time() + ADMIN_JWT_EXPIRE,
        'path'     => '/',
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => 'Lax',
    ]);
    return $token;
}

function clear_admin_cookie() {
    setcookie(ADMIN_TOKEN_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => 'Lax',
    ]);
}

/**
 * 后台鉴权：后台账号密码登录 或 企业微信登录，任一有效且角色匹配即通过
 */
function require_admin_session(array $roles) {
    // 方式A：后台账号密码
    $a = get_admin_session();
    if ($a && user_has_any_role($a['role'], $roles)) {
        return $a;
    }
    // 方式B：企业微信登录
    $u = get_logged_user();
    if ($u && user_has_any_role($u['role'], $roles)) {
        return $u;
    }
    json_out(['success' => false, 'error' => '未登录或无权访问'], 401);
}
