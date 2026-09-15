<?php
/**
 * 管理端页面路由：/admin/dashboard、/admin/settings、/admin/upload、
 *                  /admin/stats、/admin/feedback_list、/admin/roles
 * 由 OpenResty 重写 /admin/(.*) -> /admin/router.php?page=$1 触发。
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../settings.php';

// 禁止缓存后台页面（避免部署新版后仍显示旧 HTML / 反向代理缓存）
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// 安全响应头：防 MIME 嗅探 / 点击劫持 / referrer 泄露
security_headers();

// 双登录：后台账号密码 或 企业微信，任一有效即视为已登录
$user = get_admin_session();
if (!$user) {
    $user = get_logged_user();
}
if (!$user) {
    // 未登录：显示后台登录页（账号密码 + 企业微信登录入口）
    include __DIR__ . '/../templates/admin/login.html';
    exit;
}
if (!user_has_any_role($user['role'], [ROLE_ADMIN, ROLE_FINANCE, ROLE_HR])) {
    // 非后台角色（含纯员工、空 role）访问后台：清除登录态，跳转至管理员登录页
    clear_session_cookie();
    clear_admin_cookie();
    $login_error = '当前账号无权限访问管理后台，请使用管理员账号登录';
    include __DIR__ . '/../templates/admin/login.html';
    exit;
}

$page = $_GET['page'] ?? 'dashboard';
$allowed = ['dashboard', 'settings', 'upload', 'stats', 'feedback_list', 'roles', 'contacts', 'messages', 'notify', 'salary_list', 'salary_trash', 'history_import', 'access', 'audit_log', 'bonus_list', 'bonus_trash', 'bonus_upload', 'bonus_types', 'info', 'bb', 'vacation_overview', 'vacation_adjust', 'vacation_rules'];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}

// 页面级权限控制（支持后台自定义角色×页面访问矩阵）
$role = $user['role'] ?? '';
if (!role_can_access_page($role, $page)) {
    header('Content-Type:text/html;charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>无权限访问</title><style>*{margin:0;padding:0;box-sizing:border-box}body{display:flex;justify-content:center;align-items:center;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Segoe UI",Roboto,sans-serif;background:linear-gradient(135deg,#f5f7fa 0%,#e4e8ec 100%)}.box{text-align:center;padding:80px 60px;background:#fff;border-radius:20px;box-shadow:0 8px 40px rgba(0,0,0,.08);max-width:400px}.icon{font-size:88px;margin-bottom:28px;display:inline-block;animation:float 3s ease-in-out infinite}@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}.msg{font-size:32px;font-weight:700;color:#1a1a1a;letter-spacing:2px}.tip{font-size:15px;color:#888;margin-top:16px}a.back{display:inline-block;margin-top:28px;padding:12px 32px;background:linear-gradient(135deg,#07c160,#1aa86a);color:#fff;text-decoration:none;border-radius:24px;font-size:15px;font-weight:600;box-shadow:0 4px 14px rgba(7,193,96,.3);transition:transform .15s}a.back:hover{transform:translateY(-2px)}</style></head><body><div class="box"><div class="icon">🔒</div><div class="msg">无权限访问</div><div class="tip">您当前角色无权访问该页面，请联系管理员</div><a class="back" href="/admin/dashboard">返回首页</a></div></body></html>';
    exit;
}
$tpl_map = ['salary_list' => 'salary-list', 'salary_trash' => 'salary-trash', 'history_import' => 'history-import', 'bonus_list' => 'bonus-list', 'bonus_trash' => 'bonus-trash', 'bonus_upload' => 'bonus-upload', 'bonus_types' => 'bonus-types', 'vacation_overview' => 'vacation-overview', 'vacation_adjust' => 'vacation-adjust', 'vacation_rules' => 'vacation-rules'];
$tpl = $tpl_map[$page] ?? $page;
$file = __DIR__ . '/../templates/admin/' . $tpl . '.html';
if (!file_exists($file)) {
    $file = __DIR__ . '/../templates/admin/dashboard.html';
}
ob_start();
include $file;
$html = ob_get_clean();
echo salary_inject_cache_buster($html);
