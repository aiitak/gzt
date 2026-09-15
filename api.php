<?php
// 全局错误捕获：将 PHP 致命错误 / 未捕获异常转为 JSON，避免空白 500 难以排查
function _api_fatal_handler() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        // 生产环境不泄露服务器路径与行号，仅 DEBUG 模式返回详细信息
        if (defined('DEBUG') && DEBUG) {
            echo json_encode([
                'success' => false,
                'error'   => '服务器内部错误：' . $e['message'],
                'file'    => isset($e['file']) ? $e['file'] : '',
                'line'    => isset($e['line']) ? $e['line'] : 0,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            error_log('[salary fatal] ' . $e['message'] . ' @ ' . ($e['file'] ?? '') . ':' . ($e['line'] ?? 0));
            echo json_encode(['success' => false, 'error' => '服务器内部错误，请稍后重试或联系管理员'], JSON_UNESCAPED_UNICODE);
        }
    }
}
register_shutdown_function('_api_fatal_handler');
set_exception_handler(function ($ex) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    if (defined('DEBUG') && DEBUG) {
        echo json_encode([
            'success' => false,
            'error'   => '服务器异常：' . $ex->getMessage(),
            'file'    => $ex->getFile(),
            'line'    => $ex->getLine(),
        ], JSON_UNESCAPED_UNICODE);
    } else {
        error_log('[salary exception] ' . $ex->getMessage() . "\n" . $ex->getTraceAsString());
        echo json_encode(['success' => false, 'error' => '服务器内部错误，请稍后重试或联系管理员'], JSON_UNESCAPED_UNICODE);
    }
});

/**
 * 统一 API 入口
 * 调用方式：/api.php?path=模块/操作[/子操作[/资源ID]]
 * 例如：/api.php?path=auth/password/set
 *       /api.php?path=salary/detail/202603
 *       /api.php?path=admin/push/all/202603
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/wecom.php';
require_once __DIR__ . '/excel.php';

security_headers();

// 定时清理上传目录中超过 1 小时的临时 xlsx 文件（低概率执行，不影响请求性能）
register_shutdown_function(function () {
    $dir = UPLOAD_DIR;
    if (!is_dir($dir)) return;
    $cutoff = time() - 3600;
    foreach (glob($dir . '*.xlsx') as $f) {
        if (filemtime($f) < $cutoff) @unlink($f);
    }
});

function sanitize_seg($s) {
    return preg_replace('/[^a-z0-9_]/', '', str_replace('-', '_', strtolower((string)$s)));
}

if (!is_installed()) {
    json_out(['success' => false, 'error' => '系统未初始化，请先访问 install.php'], 503);
}
// 安全闸门：JWT 密钥未正确配置 / 跨站请求 → 拒绝
ensure_jwt_secret();
require_same_origin();

$path = trim($_GET['path'] ?? '', '/');
if ($path === '') {
    json_out(['success' => false, 'error' => '缺少 path'], 400);
}
$segs = explode('/', $path);
$module = sanitize_seg($segs[0] ?? '');
$action = sanitize_seg($segs[1] ?? '');
$sub    = isset($segs[2]) ? sanitize_seg($segs[2]) : '';

if ($module === '' || $action === '') {
    json_out(['success' => false, 'error' => 'path 格式错误'], 400);
}

$handler_file = __DIR__ . '/handlers/' . $module . '.php';
if (!file_exists($handler_file)) {
    json_out(['success' => false, 'error' => '接口模块不存在: ' . $module], 404);
}
require_once $handler_file;

$func = 'handle_' . $module . '_' . $action . ($sub !== '' ? '_' . $sub : '');

if (!function_exists($func)) {
    // 第三段为数字（如年份/年月）时，作为资源 ID 注入后调用 模块_操作
    if ($sub !== '' && ctype_digit($sub) && function_exists('handle_' . $module . '_' . $action)) {
        $_GET['id'] = $sub;
        $func = 'handle_' . $module . '_' . $action;
    } else {
        json_out(['success' => false, 'error' => '操作不存在: ' . $action . ($sub ? '/' . $sub : '')], 404);
    }
}

// 第四段作为 ym / id（如 admin/push/all/202603, admin/stats/salary/202603）
if (isset($segs[3])) {
    $_GET['ym'] = sanitize_seg($segs[3]);
    $_GET['id'] = sanitize_seg($segs[3]);
}

call_user_func($func);
