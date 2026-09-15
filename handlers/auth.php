<?php
/**
 * 认证与密码接口
 *   auth/me             当前用户信息 {userid,name,roles,password_set}
 *   auth/login          跳转企业微信 OAuth（?redirect=）
 *   auth/password/set   设置/重置后设置密码
 *   auth/password/reset 清空密码（需登录）
 *   auth/password/verify 校验密码（成功种短期 cookie，供 detail 使用）
 */

function handle_auth_me() {
    // 兼容两种登录：后台账号密码 或 企业微信
    $u = get_admin_session();
    $login_type = 'admin';
    if (!$u) {
        $u = get_logged_user();
        $login_type = 'wecom';
    }
    if (!$u) {
        json_out(['success' => false, 'logged_in' => false]);
        return;
    }
    $deptId = isset($u['dept_id']) ? (int)$u['dept_id'] : 0;
    $deptName = '';
    if ($deptId > 0) {
        $db = get_db();
        $dn = $db->prepare("SELECT name FROM departments WHERE dept_id = ?");
        $dn->execute([$deptId]);
        $dr = $dn->fetch();
        if ($dr) $deptName = normalize_dept_name($dr['name']);
    }
    // 计算当前用户可访问的后台页面列表（供前端菜单动态显示）
    $accessiblePages = [];
    foreach (get_page_definitions() as $p) {
        if (role_can_access_page($u['role'], $p['page'])) {
            $accessiblePages[] = $p['page'];
        }
    }
    json_out([
        'logged_in'        => true,
        'login_type'       => $login_type,
        'userid'           => $u['userid'],
        'name'             => $u['name'],
        'roles'            => user_roles_array($u['role']),
        'password_set'     => (bool)$u['has_set_password'],
        'admin_login'      => $u['admin_login'],
        'wecom_userid'     => $u['wecom_userid'] ?? '',
        'dept_id'          => $deptId,
        'dept_name'        => $deptName,
        'accessible_pages' => $accessiblePages,
    ]);
}

function handle_auth_login() {
    $redirect = $_GET['redirect'] ?? '/employee/salary';
    // 防开放重定向：仅允许本站相对路径（以 / 开头，不含协议/双斜杠）
    if (strpos($redirect, '/') !== 0 || strpos($redirect, '//') !== false || preg_match('#^[a-z]+:#i', $redirect)) {
        $redirect = '/employee/salary';
    }
    $proto = is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $callback = $proto . '://' . $host . '/callback.php';
    // CSRF 防护：生成随机 nonce 存入 HttpOnly 短期 cookie，callback.php 验证后清除
    $nonce = bin2hex(random_bytes(16));
    setcookie('oauth_nonce', $nonce, [
        'expires'   => time() + 600,
        'path'      => '/',
        'httponly'  => true,
        'secure'    => is_https(),
        'samesite'  => 'Lax',
    ]);
    // 绑定模式：需先以「后台账号密码」登录。把后台身份编码进短期签名令牌放入 state，
    // 避免 OAuth 回跳（跨站/HTTPS 不一致）导致 admin_token Cookie 丢失时无法识别登录态。
    $bind = ($_GET['bind'] ?? '') === '1';
    if ($bind) {
        $admin = get_admin_session();
        if (!$admin) {
            // 未用后台账号密码登录，无法直接绑定，带错误码跳回原页面
            header('Location: ' . $redirect . (strpos($redirect, '?') === false ? '?bind_err=1' : '&bind_err=1'));
            exit;
        }
        $bindTok = jwt_encode(['bind_admin' => $admin['userid'], 'exp' => time() + 600]);
        $state = 'bind:' . $bindTok . ':' . $nonce . ':' . ltrim($redirect, '/');
    } else {
        $state = 'n:' . $nonce . ':' . $redirect;
    }
    header('Location: ' . wecom_oauth_url($callback, $state));
    exit;
}

/**
 * 解绑企业微信账号（需已登录后台）
 */
function handle_auth_unbind_wecom() {
    $u = require_admin_session([ROLE_ADMIN, ROLE_FINANCE, ROLE_HR]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['success' => false, 'error' => 'Method Not Allowed'], 405); return; }
    // 解绑前校验是否已配置后台账号密码，避免解绑后无法登录
    if (empty($u['admin_login']) || empty($u['admin_pass'])) {
        json_out(['success' => false, 'error' => '请先在设置页配置后台账号密码后再解绑企微，否则解绑后无法登录后台']);
    }
    get_db()->prepare("UPDATE users SET wecom_userid = NULL WHERE userid = ?")
       ->execute([$u['userid']]);
    audit_log('unbind_wecom', 'user:' . $u['userid']);
    json_out(['success' => true]);
}

function handle_auth_password_set() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $u = require_login();
    $pw = (string)param('password', '');
    if (strlen($pw) < 4 || strlen($pw) > 20) {
        json_out(['success' => false, 'error' => '密码长度需4-20位']);
    }

    // 如果已设置过密码，必须验证旧密码
    if ($u['has_set_password']) {
        $oldPw = (string)param('old_password', '');
        if (!$oldPw) {
            json_out(['success' => false, 'error' => '请输入原密码']);
        }
        if (!verify_password($oldPw, $u['password'])) {
            json_out(['success' => false, 'error' => '原密码错误']);
        }
    }

    get_db()->prepare("UPDATE users SET password = ?, has_set_password = 1 WHERE userid = ?")
       ->execute([hash_password($pw), $u['userid']]);
    $mac = hash_hmac('sha256', (string)$u['userid'], JWT_SECRET);
    setcookie('pw_ok', $u['userid'] . '.' . $mac, ['expires' => time() + free_window_minutes() * 60, 'path' => '/', 'httponly' => true, 'secure' => is_https(), 'samesite' => 'Lax']);
    audit_log('password_set', 'user:' . $u['userid'], 'ok', ['first_setup' => empty($u['has_set_password']) ? 1 : 0]);
    json_out(['success' => true]);
}

function handle_auth_password_reset() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $u = require_login();
    get_db()->prepare("UPDATE users SET password = NULL, has_set_password = 0 WHERE userid = ?")
       ->execute([$u['userid']]);
    setcookie('pw_ok', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'secure' => is_https(), 'samesite' => 'Lax']);
    audit_log('password_reset', 'user:' . $u['userid']);
    json_out(['success' => true]);
}

function handle_auth_password_verify() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $u = require_login();
    if (!$u['has_set_password']) {
        json_out(['success' => false, 'need_setup' => true]);
    }
    $pw = (string)param('password', '');
    if (!verify_password($pw, $u['password'])) {
        json_out(['success' => false, 'error' => '密码错误']);
    }
    $mac = hash_hmac('sha256', (string)$u['userid'], JWT_SECRET);
    setcookie('pw_ok', $u['userid'] . '.' . $mac, ['expires' => time() + free_window_minutes() * 60, 'path' => '/', 'httponly' => true, 'secure' => is_https(), 'samesite' => 'Lax']);
    audit_log('password_verify', 'user:' . $u['userid']);
    json_out(['success' => true]);
}
