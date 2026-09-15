<?php
/**
 * 系统设置读写（settings 表）
 * 企业微信参数、应用域名、站点信息等均存于此，后台「系统设置」网页维护。
 *
 * 性能优化：
 *   - get_setting() 使用请求级静态缓存，避免同一请求多次查询相同 key（N+1）
 *   - get_all_settings() / get_settings_by_keys() 一次性批量查询，杜绝循环 SQL
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * 读取单个设置项（带请求级缓存，避免 N+1 查询）
 */
function get_setting($key, $default = '') {
    // 请求级缓存：避免同一 key 被多次查询
    if (isset($GLOBALS['_settings_cache'][$key])) {
        return $GLOBALS['_settings_cache'][$key];
    }
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $value = $row ? $row['value'] : $default;
    } catch (Throwable $e) {
        $value = $default;
    }
    $GLOBALS['_settings_cache'][$key] = $value;
    return $value;
}

/**
 * 批量读取多个 key（一次 SQL，避免循环 N+1）
 *
 * @param array $keys 要读取的 key 列表
 * @param mixed $default 默认值
 * @return array ['key' => value, ...]
 */
function get_settings_by_keys(array $keys, $default = ''): array {
    $out = [];
    $missing = [];
    // 先从缓存命中
    foreach ($keys as $k) {
        if (isset($GLOBALS['_settings_cache'][$k])) {
            $out[$k] = $GLOBALS['_settings_cache'][$k];
        } else {
            $missing[] = $k;
        }
    }
    if (empty($missing)) {
        return $out;
    }
    // 一次性查询未命中的 key
    try {
        $db = get_db();
        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $stmt = $db->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ($placeholders)");
        $stmt->execute($missing);
        $rows = $stmt->fetchAll();
        $found = [];
        foreach ($rows as $r) {
            $found[$r['key']] = $r['value'];
        }
        foreach ($missing as $k) {
            $val = array_key_exists($k, $found) ? $found[$k] : $default;
            $out[$k] = $val;
            $GLOBALS['_settings_cache'][$k] = $val;
        }
    } catch (Throwable $e) {
        foreach ($missing as $k) {
            $out[$k] = $default;
            $GLOBALS['_settings_cache'][$k] = $default;
        }
    }
    return $out;
}

function set_setting($key, $value) {
    $db = get_db();
    if (DB_TYPE === 'mysql') {
        $sql = "INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
    } else {
        $sql = "INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`";
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([$key, (string)$value]);
    // 写入后同步更新缓存，避免同请求读到旧值
    $GLOBALS['_settings_cache'][$key] = (string)$value;
}

/**
 * 清空请求级缓存（用于设置被批量更新后强制重读，或在测试中重置）
 */
function clear_settings_cache(): void {
    $GLOBALS['_settings_cache'] = [];
}

/**
 * 读取所有企业微信相关配置（一次 SQL，避免循环 N+1）
 */
function get_all_settings() {
    $keys = [
        'corpid', 'agentid', 'secret', 'contact_secret',
        'token', 'encoding_aes_key', 'server_domain', 'card_source_desc',
        // 通讯录事件服务器（与自建应用接收消息是两套独立的 Token / EncodingAESKey）
        'contact_token', 'contact_aes_key',
    ];
    return get_settings_by_keys($keys);
}

/**
 * 读取所有站点信息配置（一次 SQL，避免循环 N+1）
 */
function get_all_site_settings(): array {
    return get_settings_by_keys([
        'company_name', 'company_website',
        'site_title', 'site_subtitle',
        'theme_color', 'logo_url', 'announcement',
    ], '');
}

/**
 * 读取站点信息配置（带默认值，一次 SQL）
 */
function get_site_settings_with_defaults(): array {
    $defaults = [
        'company_name'   => '劲旋风航空',
        'company_website'=> 'jxfpropeller.com',
        'site_title'     => '工资查询',
        'site_subtitle'  => '团结奋进 知难而进 不断改进',
        'theme_color'    => '#07c160',
        'logo_url'       => '',
        'announcement'   => '',
    ];
    $values = get_settings_by_keys(array_keys($defaults), '');
    $out = [];
    foreach ($defaults as $k => $v) {
        $out[$k] = ($values[$k] !== '') ? $values[$k] : $v;
    }
    return $out;
}
