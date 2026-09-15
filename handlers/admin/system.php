<?php
/**
 * 调试/日志接口：催办工作时间配置、催办/推送调试、操作日志查询
 */

/**
 * 催办工作时间配置（GET 读取 / POST 保存）
 * 存储到 settings 表 remind_worktime 字段，JSON 格式：
 *   { "enabled": true, "days": [1,2,3,4,5], "start_hour": 8, "end_hour": 17 }
 * enabled=false 时任意时间都触发催办（方便测试），cron.php 的 is_workday_hours() 读取此配置
 */
function handle_admin_remind_worktime() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $enabledRaw = param('enabled', false);
        $enabled = is_bool($enabledRaw) ? $enabledRaw
                  : in_array(strtolower((string)$enabledRaw), ['1', 'true', 'on', 'yes'], true);
        $days = param('days', [1,2,3,4,5]);
        if (!is_array($days)) {
            $days = array_values(array_filter(array_map('intval', explode(',', (string)$days))));
        }
        $days = array_values(array_unique(array_map('intval', $days)));
        // 校验 0-6（0=周日,6=周六）
        $days = array_values(array_filter($days, function ($d) { return $d >= 0 && $d <= 6; }));
        if (empty($days)) { $days = [1,2,3,4,5]; }
        $startHour = (int)param('start_hour', 8);
        $endHour = (int)param('end_hour', 17);
        if ($startHour < 0 || $startHour > 23) { $startHour = 8; }
        if ($endHour < 0 || $endHour > 23) { $endHour = 17; }
        if ($endHour <= $startHour) { $endHour = $startHour + 1; }
        $data = [
            'enabled'    => $enabled,
            'days'       => $days,
            'start_hour' => $startHour,
            'end_hour'   => $endHour,
        ];
        set_setting('remind_worktime', json_encode($data, JSON_UNESCAPED_UNICODE));
        audit_log('remind_worktime', 'remind_worktime', 'ok', $data);
        json_out(['success' => true]);
    }
    $cfg = json_decode(get_setting('remind_worktime', ''), true);
    if (!is_array($cfg)) {
        $cfg = [
            'enabled'    => true,
            'days'       => [1,2,3,4,5],
            'start_hour' => 8,
            'end_hour'   => 17,
        ];
    }
    json_out(['success' => true, 'config' => $cfg]);
}

/**
 * 催办调试接口（绕过工作时间限制 + 1 小时去重限制，直接触发）
 *   POST /api/admin/remind_debug  body: { type: 'salary'|'bonus'|'all' }
 * type=all 同时执行工资催办 + 奖金催办（与 cron.php?task=remind 一致）
 * 仅 admin 可调用；返回详细处理结果，便于排查「为什么没有催办」
 */
function handle_admin_remind_debug() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $type = trim((string)param('type', ''));
    if (!in_array($type, ['salary', 'bonus', 'all'], true)) {
        json_out(['success' => false, 'error' => 'type 参数无效，应为 salary、bonus 或 all']);
    }
    // 引入 cron.php 中的催办函数
    $cronFile = __DIR__ . '/../../cron.php';
    if (!file_exists($cronFile)) {
        json_out(['success' => false, 'error' => 'cron.php 文件不存在']);
    }
    require_once $cronFile;
    if (!function_exists('run_remind_salary') || !function_exists('run_remind_bonus')) {
        json_out(['success' => false, 'error' => '催办函数未定义']);
    }
    // force=true 绕过 1 小时去重；logDetail=true 返回每条记录的处理结果
    if ($type === 'salary' || $type === 'all') {
        $salaryRes = run_remind_salary(true, true);
        audit_log('remind_debug', 'salary_remind', 'ok', ['sent' => $salaryRes['sent'], 'total' => $salaryRes['total'], 'skip' => $salaryRes['skip']]);
    }
    if ($type === 'bonus' || $type === 'all') {
        $bonusRes = run_remind_bonus(true, true);
        audit_log('remind_debug', 'bonus_remind', 'ok', ['sent' => $bonusRes['sent'], 'total' => $bonusRes['total'], 'skip' => $bonusRes['skip']]);
    }
    if ($type === 'all') {
        json_out([
            'success'   => true,
            'type'      => 'all',
            'summary'   => '工资催办 ' . $salaryRes['sent'] . '/' . $salaryRes['total'] . '，奖金催办 ' . $bonusRes['sent'] . '/' . $bonusRes['total'],
            'salary'    => $salaryRes,
            'bonus'     => $bonusRes,
        ]);
    } else {
        $res = $type === 'salary' ? $salaryRes : $bonusRes;
        json_out([
            'success' => true,
            'type'    => $type,
            'sent'    => $res['sent'],
            'total'   => $res['total'],
            'skip'    => $res['skip'],
            'error'   => $res['error'],
            'details' => $res['details'],
        ]);
    }
}

/**
 * 定时下发调试接口（绕过日期限制与 last_auto_push 去重，直接执行一次）
 *   POST /api/admin/push_debug
 * 仅 admin 可调用；查找最新一期 draft 状态工资条并执行推送
 */
function handle_admin_push_debug() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    // 引入 cron.php 中的定时下发函数
    $cronFile = __DIR__ . '/../../cron.php';
    if (!file_exists($cronFile)) {
        json_out(['success' => false, 'error' => 'cron.php 文件不存在']);
    }
    require_once $cronFile;
    if (!function_exists('run_scheduled_push_inner')) {
        json_out(['success' => false, 'error' => '定时下发函数未定义']);
    }
    $res = run_scheduled_push_inner(true, true);
    audit_log('push_debug', 'scheduled_push', $res['error'] ? 'fail' : 'ok', [
        'sent' => $res['sent'], 'total' => $res['total'], 'ym' => $res['ym'], 'skipped' => $res['skipped'],
        'error' => $res['error'],
    ]);
    json_out([
        'success'   => true,
        'sent'      => $res['sent'],
        'total'     => $res['total'],
        'ym'        => $res['ym'],
        'skipped'   => $res['skipped'],
        'notify_ok' => $res['notify_ok'],
        'error'     => $res['error'],
        'details'   => $res['details'],
    ]);
}

/**
 * 操作日志查询（仅 admin）
 * GET /api/admin/audit_log?page=1&size=50&op_type=&op_user=&date_from=&date_to=
 */
function handle_admin_audit_log() {
    require_admin_session([ROLE_ADMIN]);
    $db = get_db();
    $page = max(1, (int)param('page', 1));
    $size = min(200, max(10, (int)param('size', 50)));

    $where = '1=1';
    $params = [];
    $opType = trim((string)param('op_type', ''));
    $opUser = trim((string)param('op_user', ''));
    $dateFrom = trim((string)param('date_from', ''));
    $dateTo = trim((string)param('date_to', ''));

    if ($opType !== '') {
        $where .= ' AND op_type = ?';
        $params[] = $opType;
    }
    if ($opUser !== '') {
        $where .= ' AND op_user LIKE ?';
        $params[] = '%' . $opUser . '%';
    }
    if ($dateFrom !== '') {
        $where .= ' AND op_time >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND op_time <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }

    $countSql = "SELECT COUNT(*) FROM audit_log WHERE $where";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "SELECT id, op_time, op_user, op_type, op_target, op_result, ip, ua, detail
            FROM audit_log WHERE $where
            ORDER BY id DESC LIMIT ? OFFSET ?";
    $stmt = $db->prepare($sql);
    $bindParams = $params;
    $bindParams[] = $size;
    $bindParams[] = ($page - 1) * $size;
    foreach ($bindParams as $i => $v) {
        $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $list = $stmt->fetchAll();

    // 批量解析操作人姓名：op_user 格式为 "A:userid" 或 "E:userid"
    // 收集所有 userid，一次性查询 users 表获取姓名
    $userids = [];
    foreach ($list as $row) {
        $raw = $row['op_user'] ?? '';
        if ($raw !== '' && $raw !== '-' && strpos($raw, ':') !== false) {
            $parts = explode(':', $raw, 2);
            $userids[$parts[1]] = true;
        }
    }
    $nameMap = [];
    if ($userids) {
        $placeholders = implode(',', array_fill(0, count($userids), '?'));
        $nameStmt = $db->prepare("SELECT userid, name FROM users WHERE userid IN ($placeholders)");
        $nameStmt->execute(array_keys($userids));
        foreach ($nameStmt->fetchAll() as $nr) {
            $nameMap[$nr['userid']] = $nr['name'];
        }
    }
    // 为每条记录附加 op_user_label：姓名（角色）
    foreach ($list as &$row) {
        $raw = $row['op_user'] ?? '';
        if ($raw === '' || $raw === '-' || strpos($raw, ':') === false) {
            $row['op_user_label'] = $raw ?: '-';
            continue;
        }
        $parts = explode(':', $raw, 2);
        $roleTag = $parts[0] === 'A' ? '管理员' : '员工';
        $uid = $parts[1];
        $name = $nameMap[$uid] ?? $uid;
        $row['op_user_label'] = $name . '（' . $roleTag . '）';
    }
    unset($row);

    // 返回去重的操作类型列表供筛选
    $types = $db->query("SELECT DISTINCT op_type FROM audit_log ORDER BY op_type")->fetchAll(PDO::FETCH_COLUMN);

    json_out([
        'success' => true,
        'total' => $total,
        'page' => $page,
        'size' => $size,
        'list' => $list,
        'op_types' => $types,
    ]);
}
