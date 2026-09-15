<?php
// 公开版本探针：用于前端判断当前部署版本，强制击穿企业微信/浏览器对页面的 URL 缓存。
// 不依赖登录态、不走 API 网关，返回 no-store 确保每次都被实时拉取。
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}
require_once __DIR__ . '/config.php';

echo json_encode([
    'version' => defined('APP_VERSION') ? APP_VERSION : '0',
], JSON_UNESCAPED_UNICODE);
