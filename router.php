<?php
/**
 * 开发服务器路由脚本（配合 `php -S 127.0.0.1:8080 router.php` 使用）
 * 在 PHP 内置开发服务器下模拟 Nginx 伪静态，让 /employee/salary /admin/dashboard 等 URL 生效。
 * 生产环境（1Panel）应使用 nginx.conf 中的 rewrite 规则，不需要本文件。
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = __DIR__;

// 伪静态路由优先处理（避免 /admin、/employee 这种目录被真实文件检查拦截）
// /admin/* -> /admin/router.php
if (preg_match('#^/admin(?:$|/)(.*)$#', $uri, $m)) {
    $page = trim($m[1], '/');
    if ($page !== '') {
        $_GET['page'] = $page;
    }
    require $root . '/admin/router.php';
    return true;
}

// /employee/* -> /employee/router.php
if (preg_match('#^/employee(?:$|/)(.*)$#', $uri, $m)) {
    $page = trim($m[1], '/');
    if ($page !== '') {
        $_GET['page'] = $page;
    }
    require $root . '/employee/router.php';
    return true;
}

// /api/* -> /api.php?path=...
if (preg_match('#^/api/(.*)$#', $uri, $m)) {
    $_GET['path'] = $m[1];
    require $root . '/api.php';
    return true;
}

// 真实文件存在 -> 直接返回（静态资源、install.php、admin.php、callback.php、cron.php 等）
$filepath = $root . $uri;
if ($uri !== '/' && file_exists($filepath) && !is_dir($filepath)) {
    return false;
}

// 根路径 -> 员工端入口
if ($uri === '/' || $uri === '') {
    header('Location: /employee/salary');
    return true;
}

// 兜底：交给默认处理（通常返回 404）
return false;
