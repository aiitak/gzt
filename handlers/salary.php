<?php
/**
 * 员工端工资条接口
 *   salary/list      可查看的年月列表
 *   salary/detail    查看某月工资条（需密码）
 *   salary/confirm   确认签收
 *   salary/chart     本年每月实发柱形图 + 月均
 *   salary/history   最近 5 年工资历史
 */

function handle_salary_list() {
    $u = require_login();
    require_password($u);
    $db = get_db();
    $stmt = $db->prepare(
        "SELECT year, month, status, confirmed, real_pay FROM salary
         WHERE userid = ? AND status IN ('sent','confirmed') AND deleted_at IS NULL
         ORDER BY year DESC, month DESC"
    );
    $stmt->execute([$u['userid']]);
    $rows = $stmt->fetchAll();
    // 补充 year_month 字段（6位字符串，如 "202603"），前端月份选择器依赖该字段
    // 强制 real_pay 为 float，避免前端 toFixed() 报错（MySQL PDO 默认返回字符串）
    $list = [];
    $years = [];
    foreach ($rows as $r) {
        $r['year_month'] = sprintf('%04d%02d', (int)$r['year'], (int)$r['month']);
        $r['real_pay'] = (float)$r['real_pay'];
        $list[] = $r;
        $y = (int)$r['year'];
        if (!in_array($y, $years, true)) $years[] = $y;
    }
    json_out(['success' => true, 'list' => $list, 'years' => $years]);
}

function handle_salary_detail() {
    $u = require_login();
    // 年月来源：路径 /salary/detail/{ym}（api.php 注入 $_GET['id']），兼容 ?year=&month=
    $ym = (string)param('id', '');
    if ($ym === '') {
        $ym = sprintf('%04d%02d', (int)param('year'), (int)param('month'));
    }
    $year  = (int)substr($ym, 0, 4);
    $month = (int)substr($ym, 4, 2);
    if ($year < 2000 || $month < 1 || $month > 12) {
        json_out(['success' => false, 'error' => '参数错误']);
    }

    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM salary WHERE userid = ? AND year = ? AND month = ? AND deleted_at IS NULL");
    $stmt->execute([$u['userid'], $year, $month]);
    $sal = $stmt->fetch();
    if (!$sal) {
        json_out(['success' => false, 'error' => '未找到该工资条']);
    }
    if ($sal['status'] === SALARY_DRAFT) {
        json_out(['success' => false, 'error' => '工资条尚未下发']);
    }
    // 未设置密码 -> 引导设置
    if (!$u['has_set_password']) {
        json_out(['success' => true, 'need_password' => true, 'need_setup' => true]);
    }
    // 免密窗口：pw_ok cookie 为 HMAC 签名的 userid（避免伪造 cookie 绕过密码验证）
    $freeOk = false;
    if (isset($_COOKIE['pw_ok'])) {
        $parts = explode('.', (string)$_COOKIE['pw_ok'], 2);
        if (count($parts) === 2) {
            $expected = hash_hmac('sha256', $parts[0], JWT_SECRET);
            $freeOk = hash_equals($expected, $parts[1]) && $parts[0] === (string)$u['userid'];
        }
    }
    $password = (string)param('password', '');
    if (!$freeOk) {
        if ($password === '') {
            json_out(['success' => true, 'need_password' => true, 'wrong' => false]);
        }
        if (!verify_password($password, $u['password'])) {
            json_out(['success' => true, 'need_password' => true, 'wrong' => true]);
        }
        // 验证成功 -> 种 HMAC 签名的免密 cookie（时长后台可设）
        $minutes = free_window_minutes();
        $mac = hash_hmac('sha256', (string)$u['userid'], JWT_SECRET);
        setcookie('pw_ok', $u['userid'] . '.' . $mac, [
            'expires'  => time() + $minutes * 60,
            'path'     => '/',
            'httponly' => true,
            'secure'   => is_https(),
            'samesite' => 'Lax',
        ]);
    }
    // 取全部明细（含扣除项，前端按 is_deduction 区分颜色展示；category 为表头分组名）
    // 规则：金额为 0 的项目不显示
    $it = $db->prepare(
        "SELECT item_name, item_value, is_deduction, category FROM salary_items
         WHERE salary_id = ?
         ORDER BY sort_order"
    );
    $it->execute([$sal['id']]);
    $rawItems = $it->fetchAll();
    $filteredItems = [];
    foreach ($rawItems as $it) {
        if ($it['item_name'] === '实发工资') {
            $filteredItems[] = $it; // 实发工资保留（前端用于合计展示判断）
            continue;
        }
        $v = (float)$it['item_value'];
        if (abs($v) >= 0.005) {
            $filteredItems[] = $it;
        }
    }
    json_out([
        'success'       => true,
        'need_password' => false,
        'confirmed'     => (bool)$sal['confirmed'],
        'total_salary'  => $sal['real_pay'],
        'name'          => $u['name'],
        'year'          => $year,
        'month'         => $month,
        'items'         => $filteredItems,
    ]);
}

function handle_salary_confirm() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $u = require_login();
    require_password($u);
    // 路由注入的 id 为年月字符串（如 202605），兼容旧版 year/month 分开传参
    $ym = (string)param('id', '');
    if (strlen($ym) === 6 && ctype_digit($ym)) {
        $year  = (int)substr($ym, 0, 4);
        $month = (int)substr($ym, 4, 2);
    } else {
        $year  = (int)param('year');
        $month = (int)param('month');
    }
    $db = get_db();
    $stmt = $db->prepare(
        "UPDATE salary SET confirmed = 1, status = ?, confirmed_at = ?
         WHERE userid = ? AND year = ? AND month = ? AND status = ?"
    );
    $stmt->execute([SALARY_CONFIRMED, date('Y-m-d H:i:s'), $u['userid'], $year, $month, SALARY_SENT]);
    if ($stmt->rowCount() === 0) {
        // 区分「未找到」「已确认」「尚未下发」三种情况
        $chk = $db->prepare("SELECT status, confirmed FROM salary WHERE userid = ? AND year = ? AND month = ? AND deleted_at IS NULL");
        $chk->execute([$u['userid'], $year, $month]);
        $r = $chk->fetch();
        if (!$r) {
            json_out(['success' => false, 'error' => '未找到该工资条']);
        }
        if ((int)$r['confirmed'] === 1) {
            json_out(['success' => false, 'error' => '该工资条已确认，无需重复签收']);
        }
        json_out(['success' => false, 'error' => '工资条尚未下发，暂不可确认']);
    }
    json_out(['success' => true]);
}

function handle_salary_chart() {
    $u = require_login();
    require_password($u);
    // 支持两种传参：路径 salary/chart/{year}（注入 $_GET['id']）或 ?year=YYYY
    $idVal = (int)param('id', 0);
    $year = $idVal ?: (int)param('year', date('Y'));
    $db = get_db();
    $stmt = $db->prepare(
        "SELECT month, real_pay FROM salary
         WHERE userid = ? AND year = ? AND status IN ('sent','confirmed') AND deleted_at IS NULL"
    );
    $stmt->execute([$u['userid'], $year]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[(int)$r['month']] = (float)$r['real_pay'];
    }
    $data = [];
    $months = [];
    $salaries = [];
    $sum = 0;
    $cnt = 0;
    for ($m = 1; $m <= 12; $m++) {
        $v = $map[$m] ?? 0;
        $data[] = ['month' => $m, 'real_pay' => $v];
        $months[] = $m . '月';
        $salaries[] = $v > 0 ? round($v, 2) : 0;
        if ($v > 0) {
            $sum += $v;
            $cnt++;
        }
    }
    $average = $cnt ? round($sum / $cnt, 2) : 0;

    // 查询该年度奖金合计（不区分类型，多类型合并）
    $bonusStmt = $db->prepare(
        "SELECT SUM(amount) AS total FROM bonus
         WHERE userid = ? AND year = ? AND deleted_at IS NULL"
    );
    $bonusStmt->execute([$u['userid'], $year]);
    $bonusRow = $bonusStmt->fetch();
    $bonusTotal = $bonusRow ? (float)$bonusRow['total'] : 0;

    json_out([
        'success' => true,
        'year' => $year,
        'data' => $data,
        'months' => $months,
        'salaries' => $salaries,
        'total' => round($sum, 2),
        'average' => $average,
        'bonus_total' => round($bonusTotal, 2),
    ]);
}

function handle_salary_history() {
    $u = require_login();
    require_password($u);
    $curYear = (int)date('Y');
    $startYear = $curYear - 9; // 最近10年
    $db = get_db();
    $stmt = $db->prepare(
        "SELECT year, month, real_pay FROM salary
         WHERE userid = ? AND year >= ? AND status IN ('sent','confirmed') AND deleted_at IS NULL
         ORDER BY year DESC, month DESC"
    );
    $stmt->execute([$u['userid'], $startYear]);
    $list = [];
    foreach ($stmt->fetchAll() as $r) {
        $r['year_month'] = sprintf('%04d%02d', (int)$r['year'], (int)$r['month']);
        $list[] = $r;
    }
    json_out(['success' => true, 'list' => $list]);
}
