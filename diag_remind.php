<?php
/**
 * 临时诊断脚本：查看被催办跳过的记录详情
 * 用法：浏览器访问 https://你的域名/diag_remind.php?key=CRON_KEY
 *       或 CLI: php diag_remind.php CRON_KEY
 * 查完删除本文件。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// 鉴权：用 CRON_KEY 防止未授权访问
$key = $_GET['key'] ?? ($argv[1] ?? '');
if (!CRON_KEY || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

$db = get_db();
$now = time();

// 查询所有待确认工资条（与催办 SQL 口径一致）
$stmt = $db->prepare(
    "SELECT s.id, s.userid, u.name, d.name AS dept_name,
            s.year, s.month, s.status, s.confirmed,
            s.pushed_at, s.last_remind_at
     FROM salary s
     LEFT JOIN users u ON s.userid = u.userid
     LEFT JOIN departments d ON u.dept_id = d.dept_id
     WHERE s.status IN ('sent','confirmed') AND s.confirmed = 0
       AND s.deleted_at IS NULL
       AND s.userid NOT LIKE 'HIST_%'
     ORDER BY s.year ASC, s.month ASC, s.id ASC"
);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$skipped = [];
$willSend = [];
foreach ($rows as $r) {
    $skipReason = '';
    if ($r['last_remind_at'] && strtotime($r['last_remind_at']) > $now - 3600) {
        $skipReason = '1小时内已催过';
        $r['skip_reason'] = $skipReason;
        $r['seconds_ago'] = $now - strtotime($r['last_remind_at']);
        $skipped[] = $r;
    } else {
        $r['skip_reason'] = '';
        $willSend[] = $r;
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo "=== 催办诊断报告 ===\n";
echo "当前时间: " . date('Y-m-d H:i:s') . "\n";
echo "待确认总数: " . count($rows) . "\n";
echo "本轮会催: " . count($willSend) . "\n";
echo "被跳过: " . count($skipped) . "\n\n";

if (count($skipped) > 0) {
    echo "--- 被跳过的 " . count($skipped) . " 条明细 ---\n";
    foreach ($skipped as $r) {
        echo sprintf("  %s | %s | %s | %d年%d月 | last_remind_at=%s | 距今%d秒(%.1f分钟)\n",
            $r['userid'],
            $r['name'] ?: '(无名)',
            $r['dept_name'] ?: '(无部门)',
            $r['year'], $r['month'],
            $r['last_remind_at'],
            $r['seconds_ago'],
            $r['seconds_ago'] / 60
        );
    }
}

echo "\n--- 本轮会催的 " . count($willSend) . " 条明细 ---\n";
foreach ($willSend as $r) {
    echo sprintf("  %s | %s | %s | %d年%d月 | last_remind_at=%s\n",
        $r['userid'],
        $r['name'] ?: '(无名)',
        $r['dept_name'] ?: '(无部门)',
        $r['year'], $r['month'],
        $r['last_remind_at'] ?: '(NULL)'
    );
}
