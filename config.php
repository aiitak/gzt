<?php
/**
 * 工资条系统 - 配置文件
 *
 * 数据库配置：推荐通过浏览器访问 install.php 初始化向导生成 config.local.php 自动覆盖；
 * 也可直接修改本文件中的 DB_* 常量。
 * 企业微信参数（corpid/agentid/secret 等）在后台「系统设置」网页填写，存于 settings 表。
 */

// 统一设置时区（覆盖所有入口：api.php / admin / employee / cron.php 等）
// 必须在任何 date() 调用之前设置，避免 php.ini 未配 date.timezone 时返回 UTC
date_default_timezone_set('Asia/Shanghai');

// 本地覆盖配置（由 install.php 生成）须在任何默认定义之前引入，以确保其优先
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// 调试模式（生产环境改为 false）
defined('DEBUG') or define('DEBUG', false);

// 前端资源/页面缓存击穿版本号。
// 每次前端有改动部署后，请同步修改本值（以及 templates 下资源引用的 ?v= 由打包脚本自动同步）。
// 企业微信/手机浏览器会按 URL 缓存页面，本版本号用于强制其重新拉取。
defined('APP_VERSION') or define('APP_VERSION', '2026082401');

// 数据库配置
// 数据库类型：sqlite（默认，零依赖、单文件）或 mysql（遗留，需自建 MariaDB）
defined('DB_TYPE') or define('DB_TYPE', 'sqlite');

// SQLite 数据库文件路径。默认放在「站点根目录之外一层」的 salary_data/ 下，
// 避免 .db 文件被 Web 直接下载；请确保该目录 PHP 进程可写。
if (!defined('DB_FILE')) {
    define('DB_FILE', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'salary_data' . DIRECTORY_SEPARATOR . 'app.sqlite3');
}

// 以下为遗留 MySQL 配置（仅当 DB_TYPE === 'mysql' 时使用）
defined('DB_HOST') or define('DB_HOST', '127.0.0.1');
defined('DB_PORT') or define('DB_PORT', 3306);
defined('DB_NAME') or define('DB_NAME', 'salary');
defined('DB_USER') or define('DB_USER', 'salary');
defined('DB_PASS') or define('DB_PASS', 'change_me');

// JWT 密钥（安装向导会自动生成随机串写入 config.local.php；此处占位串仅用于检测「未正确配置」）
defined('JWT_SECRET') or define('JWT_SECRET', 'please_change_this_to_a_random_secret_string_at_least_32_chars');
defined('JWT_EXPIRE') or define('JWT_EXPIRE', 60 * 60 * 24 * 7); // 7 天

// 判断 JWT 密钥是否仍为不安全的默认值（空 / 占位串 / 过短）。
// 安装向导会生成随机值写入 config.local.php；若仍为默认值，系统将拒绝签发/校验令牌，避免被伪造会话。
function jwt_secret_is_default() {
    return JWT_SECRET === ''
        || JWT_SECRET === 'please_change_this_to_a_random_secret_string_at_least_32_chars'
        || strlen(JWT_SECRET) < 16;
}

// 定时任务访问密钥（安装时随机生成写入 config.local.php）。
// 一旦配置，cron.php 必须携带正确密钥（?key= 或 Authorization: Bearer）才会执行，否则拒绝。
defined('CRON_KEY') or define('CRON_KEY', '');

// 会话 Cookie 名称
defined('TOKEN_COOKIE') or define('TOKEN_COOKIE', 'salary_token');

// 后台账号密码登录 Cookie（独立于企业微信登录态）
defined('ADMIN_TOKEN_COOKIE') or define('ADMIN_TOKEN_COOKIE', 'admin_token');
defined('ADMIN_JWT_EXPIRE') or define('ADMIN_JWT_EXPIRE', 60 * 60 * 12); // 12 小时

// 上传目录与大小限制（MB）
defined('UPLOAD_DIR') or define('UPLOAD_DIR', __DIR__ . '/uploads/');
defined('MAX_UPLOAD_MB') or define('MAX_UPLOAD_MB', 10);

// 应用基础 URL（兜底，后台设置可覆盖；用于构造 OAuth 回调地址）
defined('APP_BASE_URL') or define('APP_BASE_URL', '');

// 是否信任反向代理（X-Forwarded-Proto / X-Forwarded-For 头）。
// 站点经过 Nginx / 1Panel 等反代且 HTTPS 在外层终结时设为 true，避免 is_https() 判断为 HTTP 导致 Cookie secure 标志不生效、OAuth 回调地址 http 被企微拒绝。
defined('TRUST_PROXY') or define('TRUST_PROXY', false);

// 角色常量（使用 defined or define 防止 config.local.php 已定义时触发 Notice）
defined('ROLE_ADMIN') or define('ROLE_ADMIN', 'admin');      // 管理员：分配角色、全局配置
defined('ROLE_FINANCE') or define('ROLE_FINANCE', 'finance');  // 财务：上传工资条、下发
defined('ROLE_HR') or define('ROLE_HR', 'hr');            // 人事：回复反馈
defined('ROLE_EMPLOYEE') or define('ROLE_EMPLOYEE', 'employee');// 员工：查看/确认/反馈

// 工资条状态
defined('SALARY_DRAFT') or define('SALARY_DRAFT', 'draft');    // 已上传未下发
defined('SALARY_SENT') or define('SALARY_SENT', 'sent');      // 已下发待确认
defined('SALARY_CONFIRMED') or define('SALARY_CONFIRMED', 'confirmed'); // 已确认
// 奖金状态值与 salary 一致，但用独立常量以区分语义
if (!defined('BONUS_SENT')) define('BONUS_SENT', 'sent');
if (!defined('BONUS_CONFIRMED')) define('BONUS_CONFIRMED', 'confirmed');
