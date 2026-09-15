<?php
/**
 * 员工端页面路由：/employee/salary、/employee/chart、/employee/history、
 *                  /employee/feedback、/employee/password
 * 由 OpenResty 重写 /employee/(.*) -> /employee/router.php?page=$1 触发。
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../settings.php';

// 禁止缓存页面（避免部署新版后仍显示旧 HTML）
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// 安全响应头：防 MIME 嗅探 / 点击劫持 / referrer 泄露
security_headers();

$user = get_logged_user();
if (!$user) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/employee/salary';
    header('Location: /api/auth/login?redirect=' . urlencode($redirect));
    exit;
}

$page = $_GET['page'] ?? 'salary';
$allowed = ['salary', 'chart', 'history', 'feedback', 'password', 'detail', 'bonus', 'vacation'];
if (!in_array($page, $allowed, true)) {
    $page = 'salary';
}
$file = __DIR__ . '/../templates/employee/' . $page . '.html';
if (!file_exists($file)) {
    $file = __DIR__ . '/../templates/employee/salary.html';
}

// 注入站点配置到模板（一次 SQL 批量读取，避免 N+1）
$siteCfg = get_site_settings_with_defaults();
$GLOBALS['_site_company']   = $siteCfg['company_name'];
$GLOBALS['_site_website']   = $siteCfg['company_website'];
$GLOBALS['_site_year']      = date('Y');
$GLOBALS['_site_title']     = $siteCfg['site_title'];
$GLOBALS['_site_subtitle']  = $siteCfg['site_subtitle'];
$GLOBALS['_site_theme']     = $siteCfg['theme_color'];
$GLOBALS['_site_logo']      = $siteCfg['logo_url'];
$GLOBALS['_site_announcement'] = $siteCfg['announcement'];

ob_start();
include $file;
$html = ob_get_clean();
echo salary_inject_cache_buster($html);
