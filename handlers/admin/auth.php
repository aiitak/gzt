<?php
/**
 * 登录/密码接口：后台登录、登出、修改账号密码、修改个人资料
 */

/**
 * 后台登录（独立账号密码，不依赖企业微信）
 */
function handle_admin_login() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['success' => false, 'error' => 'Method Not Allowed'], 405); return; }
    $login = (string)param('admin_login', '');
    $pass  = (string)param('password', '');
    if ($login === '' || $pass === '') {
        json_out(['success' => false, 'error' => '请输入账号和密码']);
    }
    if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $login)) {
        json_out(['success' => false, 'error' => '账号格式不正确']);
    }
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM users WHERE admin_login = ?");
    $stmt->execute([$login]);
    $u = $stmt->fetch();
    if (!$u || empty($u['admin_pass']) || !verify_password($pass, $u['admin_pass'])) {
        json_out(['success' => false, 'error' => '账号或密码错误']);
    }
    if (!user_has_any_role($u['role'], [ROLE_ADMIN, ROLE_FINANCE, ROLE_HR])) {
        json_out(['success' => false, 'error' => '该账号无权登录后台']);
    }
    set_admin_cookie($u['userid'], $u['role']);
    audit_log('admin_login', 'user:' . $u['userid'], 'ok', ['admin_login' => $login]);
    json_out(['success' => true, 'role' => $u['role']]);
}

/**
 * 后台登出（清账号密码态 + 企微态）
 */
function handle_admin_logout() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['success' => false, 'error' => 'Method Not Allowed'], 405); return; }
    audit_log('admin_logout', '-');
    clear_admin_cookie();
    clear_session_cookie();
    // 同步清除员工端免密 cookie，避免共享设备残留免密窗口
    setcookie('pw_ok', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'secure' => is_https(), 'samesite' => 'Lax']);
    json_out(['success' => true]);
}

/**
 * 修改后台登录账号/密码（需已登录：账号密码 或 企业微信）
 */
function handle_admin_password() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $u = require_admin_session([ROLE_ADMIN, ROLE_FINANCE, ROLE_HR]);
    $new = (string)param('new_password', '');
    if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
        json_out(['success' => false, 'error' => '密码至少 8 位且必须包含字母和数字']);
    }
    $new_login = trim((string)param('admin_login', $u['admin_login'] ?? ''));
    if ($new_login === '') {
        $new_login = $u['admin_login'];
    }
    // 通过 get_admin_session() 验证 Cookie 有效性（避免伪造 admin_token Cookie 绕过旧密码校验）
    $adminSess = get_admin_session();
    $is_admin_login = $adminSess && $adminSess['userid'] === $u['userid'];
    // 若已设置过密码，无论何种登录方式都必须校验旧密码
    if (!empty($u['admin_pass'])) {
        if (!$is_admin_login) {
            json_out(['success' => false, 'error' => '修改密码请先用账号密码登录']);
        }
        if (!verify_password((string)param('old_password', ''), $u['admin_pass'])) {
            json_out(['success' => false, 'error' => '旧密码错误']);
        }
    }
    get_db()->prepare("UPDATE users SET admin_login = ?, admin_pass = ? WHERE userid = ?")
        ->execute([$new_login, hash_password($new), $u['userid']]);
    if ($is_admin_login) {
        set_admin_cookie($u['userid'], $u['role']);
    }
    audit_log('admin_password', 'user:' . $u['userid'], 'ok', [
        'login_changed' => $new_login !== ($u['admin_login'] ?? '') ? 1 : 0,
    ]);
    json_out(['success' => true]);
}

/**
 * 修改管理员个人资料（姓名 + 部门），同步更新工资条
 */
function handle_admin_profile() {
    $u = require_admin_session([ROLE_ADMIN, ROLE_FINANCE, ROLE_HR]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['success' => false, 'error' => 'Method Not Allowed'], 405); return; }
    $name = trim((string)param('name', ''));
    $deptId = (int)param('dept_id', 0);
    if ($name === '') {
        json_out(['success' => false, 'error' => '姓名不能为空']);
    }
    $db = get_db();
    $deptName = '';
    if ($deptId > 0) {
        $dn = $db->prepare("SELECT name FROM departments WHERE dept_id = ?");
        $dn->execute([$deptId]);
        $dr = $dn->fetch();
        if ($dr) $deptName = normalize_dept_name($dr['name']);
    }
    $db->prepare("UPDATE users SET name = ?, dept_id = ? WHERE userid = ?")
        ->execute([$name, $deptId > 0 ? $deptId : 0, $u['userid']]);
    $db->prepare("UPDATE salary SET name = ?, dept_name = ? WHERE userid = ?")
        ->execute([$name, $deptName, $u['userid']]);
    audit_log('profile_change', 'user:' . $u['userid']);
    json_out(['success' => true]);
}
