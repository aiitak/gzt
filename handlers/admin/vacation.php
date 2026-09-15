<?php
/**
 * 年假管理接口：全员总览、手动调整、规则配置、审批同步
 *
 *   GET  /api/admin/vacation/overview?year=&dept=&q=&page=&pageSize=
 *   GET  /api/admin/vacation/adjust?userid=&year=           读取某员工年假余额 + 流水
 *   POST /api/admin/vacation/adjust                          提交手动调整(source=2)
 *   GET  /api/admin/vacation/rules?year=                     读取规则
 *   POST /api/admin/vacation/rules                            保存规则
 *   GET  /api/admin/vacation/sync                             读取同步状态（上次同步时间 + 最近回调日志摘要）
 *   POST /api/admin/vacation/sync                             手动触发批量同步（回看30天）
 *
 *   审批调试工具（仅 ADMIN）：
 *   POST /api/admin/vacation/debug_scan_start                  初始化「按时间范围扫描审批单→反推模板库」任务
 *   POST /api/admin/vacation/debug_scan_next                   拉取下一批 sp_no 的详情（合并聚合为模板）
 *   GET  /api/admin/vacation/debug_set_template                一键把某模板设为 settings.vacation_template_id
 *   GET  /api/admin/vacation/debug_sp                          单审批单详情+控件扁平化表+原始 JSON
 */

// ===== 全员年假总览 =====
function handle_admin_vacation_overview() {
    require_admin_session([ROLE_ADMIN, ROLE_FINANCE, ROLE_HR]);
    $db = get_db();

    $year     = (int)param('year', (int)date('Y'));
    $deptId   = (int)param('dept', 0);
    $q        = trim((string)param('q', ''));
    $filter   = trim((string)param('filter', 'all'));   // all / normal / exempt
    $page     = max(1, (int)param('page', 1));
    $pageSize = max(1, min(200, (int)param('pageSize', 20)));

    // 基础查询：企微真实在职员工（与通讯录/数据概览口径一致）
    //   wecom_userid 非空、非 HIST_ 存档、部门 ∉ (已离职,未匹配)
    $where = " WHERE u.userid NOT LIKE 'HIST_%' AND u.wecom_userid IS NOT NULL AND COALESCE(d.name, '') NOT IN ('已离职','未匹配')";
    $params = [];
    if ($deptId > 0) {
        $where .= " AND u.dept_id = ?";
        $params[] = $deptId;
    }
    if ($q !== '') {
        $where .= " AND (u.name LIKE ? OR u.userid LIKE ? OR u.wecom_userid LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    // 计算方式筛选：normal=常规（exempt=0且rule_type=0）、special=两年+1天（rule_type=1）、exempt=已豁免（exempt=1）
    if ($filter === 'normal') {
        $where .= " AND (u.vacation_exempt = 0 OR u.vacation_exempt IS NULL) AND (u.vacation_rule_type = 0 OR u.vacation_rule_type IS NULL)";
    } elseif ($filter === 'special') {
        $where .= " AND u.vacation_rule_type = 1";
    } elseif ($filter === 'exempt') {
        $where .= " AND u.vacation_exempt = 1";
    }

    // 总数（$where 引用了 d.name，所以 cntSql 也必须 LEFT JOIN departments）
    $cntSql = "SELECT COUNT(*) AS c FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id" . $where;
    $stmt = $db->prepare($cntSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['c'];

    // 分页数据：先查用户基本信息，再逐个算余额（规则计算在 PHP 层，避免复杂 SQL）
    $offset = ($page - 1) * $pageSize;
    $listSql = "SELECT u.userid, u.name, u.wecom_userid, u.dept_id, u.hire_date, u.vacation_exempt, u.vacation_rule_type, u.vacation_special_start, u.vacation_special_days, u.vacation_special_cap, d.name AS dept_name"
             . " FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id"
             . $where
             . " ORDER BY u.dept_id ASC, u.name ASC"
             . " LIMIT ? OFFSET ?";
    $stmt = $db->prepare($listSql);
    $bindParams = array_merge($params, [$pageSize, $offset]);
    foreach ($bindParams as $i => $v) {
        $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $users = $stmt->fetchAll();

    $rows = [];
    foreach ($users as $u) {
        $b = vacation_calc_balance($db, $u['userid'], $year);
        $rows[] = [
            'userid'       => $u['userid'],
            'name'         => $u['name'],
            'dept_name'    => $u['dept_name'] ?? '',
            'hire_date'    => $u['hire_date'] ?? '',
            'vacation_exempt' => (int)($u['vacation_exempt'] ?? 0),
            'vacation_rule_type' => (int)($u['vacation_rule_type'] ?? 0),
            'vacation_special_start' => $u['vacation_special_start'] ?? '',
            'vacation_special_days' => (int)($u['vacation_special_days'] ?? 3),
            'vacation_special_cap' => (int)($u['vacation_special_cap'] ?? 15),
            'eligible'     => $b['eligible'],
            'entitlement'  => $b['entitlement'],
            'used_days'    => $b['used_days'],
            'manual_delta' => $b['manual_delta'],
            'balance'      => $b['balance'],
            'approval_count' => $b['approval_count'],
            'detail'       => $b['eligible_detail'],
        ];
    }

    // 部门列表（前端筛选下拉）
    $depts = $db->query("SELECT dept_id, name FROM departments WHERE name NOT IN ('已离职','未匹配') ORDER BY sort_order ASC, name ASC")->fetchAll();

    json_out([
        'success'  => true,
        'year'      => $year,
        'total'     => $total,
        'page'      => $page,
        'pageSize'  => $pageSize,
        'list'      => $rows,
        'depts'     => $depts,
    ]);
}

// ===== 年假手动调整 =====
function handle_admin_vacation_adjust() {
    $me = require_admin_session([ROLE_ADMIN, ROLE_HR]);
    $db = get_db();

    // POST：提交手动调整 / 修改入职日期 / 设置特殊规则（三者可任意组合）
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $userid   = trim((string)param('userid', ''));
        $year     = (int)param('year', (int)date('Y'));
        $delta    = (float)param('delta', 0);
        $note     = trim((string)param('note', ''));
        $hireDate = trim((string)param('hire_date', ''));

        // 特殊年假规则参数
        $ruleType       = (int)param('vacation_rule_type', 0);
        $specialStart   = trim((string)param('vacation_special_start', ''));
        $specialDays    = (int)param('vacation_special_days', 0);
        $specialCap     = (int)param('vacation_special_cap', 15);

        if ($userid === '') {
            json_out(['success' => false, 'error' => '请选择员工']);
        }
        // 三项都为空则拒绝
        $hasDelta   = abs($delta) >= 0.001;
        $hasHire    = $hireDate !== '';
        $hasSpecial = $ruleType === 1;  // 启用特殊规则
        if (!$hasDelta && !$hasHire && !$hasSpecial) {
            json_out(['success' => false, 'error' => '请填写变动值、入职日期或启用特殊规则']);
        }

        // 特殊规则校验（启用时必须有起点日期和基础天数）
        if ($hasSpecial) {
            if ($specialStart === '') {
                json_out(['success' => false, 'error' => '特殊规则必须设置起点日期']);
            }
            if ($specialDays <= 0) {
                json_out(['success' => false, 'error' => '特殊规则起点天数必须大于 0']);
            }
        }

        // 校验员工存在（必须是企微员工，避免给本地账户调整年假）
        $stmt = $db->prepare("SELECT userid, name FROM users WHERE userid = ? AND wecom_userid IS NOT NULL LIMIT 1");
        $stmt->execute([$userid]);
        $emp = $stmt->fetch();
        if (!$emp) {
            json_out(['success' => false, 'error' => '员工不存在（仅允许调整企微真实员工的年假）']);
        }

        // 入职日期校验（可选，传了才校验+写入）
        if ($hasHire) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hireDate)) {
                json_out(['success' => false, 'error' => '入职日期格式应为 YYYY-MM-DD']);
            }
            // 解析合法性（如 2026-02-30 会被 DateTime 视为溢出）
            try {
                $dt = new DateTime($hireDate);
                if ($dt->format('Y-m-d') !== $hireDate) {
                    throw new Exception('日期溢出');
                }
                if ($dt > new DateTime('+1 day')) {
                    json_out(['success' => false, 'error' => '入职日期不能晚于明天']);
                }
            } catch (Throwable $e) {
                json_out(['success' => false, 'error' => '入职日期不合法']);
            }
        }

        try {
            $db->beginTransaction();

            // 1) 写入入职日期（users.hire_date，覆盖旧值——HR 手动维护优先级最高）
            if ($hasHire) {
                $stmt = $db->prepare("UPDATE users SET hire_date = ? WHERE userid = ?");
                $stmt->execute([$hireDate, $userid]);
                audit_log('vacation_hire_date', 'user:' . $userid, 'ok', ['hire_date' => $hireDate]);
            }

            // 2) 写入特殊年假规则
            if ($hasSpecial) {
                $stmt = $db->prepare("UPDATE users SET vacation_rule_type = ?, vacation_special_start = ?, vacation_special_days = ?, vacation_special_cap = ? WHERE userid = ?");
                $stmt->execute([$ruleType, $specialStart, $specialDays, $specialCap, $userid]);
                audit_log('vacation_special_rule', 'user:' . $userid, 'ok', [
                    'rule_type'      => $ruleType,
                    'special_start'  => $specialStart,
                    'special_days'   => $specialDays,
                    'special_cap'    => $specialCap,
                ]);
            }

            // 3) 写入流水账（delta 非零才写）
            if ($hasDelta) {
                $nowExpr = (defined('DB_TYPE') && DB_TYPE === 'mysql') ? 'NOW()' : "datetime('now')";
                $roundedDelta = round($delta, 2);
                $stmt = $db->prepare(
                    "INSERT INTO vacation_ledger (userid, name, year, source, delta, ref_no, remark, op_by, created_at)
                     VALUES (?, '', ?, 2, ?, NULL, ?, ?, " . $nowExpr . ")"
                );
                // source=2=手动调整，ref_no=NULL（非审批来源）
                // 同时支持后台密码登录和企业微信登录
                $operator = $me['userid'] ?? '';
                $stmt->execute([$userid, $year, $roundedDelta, $note, $operator]);
                audit_log('vacation_adjust', 'user:' . $userid, 'ok', [
                    'year'  => $year,
                    'delta' => $roundedDelta,
                    'note'  => safe_substr($note, 0, 100),
                ]);
            }

            $db->commit();
            json_out(['success' => true]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) try { $db->rollBack(); } catch (Throwable $e2) {}
            json_out(['success' => false, 'error' => '保存失败：' . $e->getMessage()]);
        }
    }

    // GET：读取某员工余额 + 流水
    $userid = trim((string)param('userid', ''));
    $year   = (int)param('year', (int)date('Y'));
    if ($userid === '') {
        // 未指定 userid：返回可选员工列表（企微在职员工，口径与通讯录/年假总览一致）
        $users = $db->query(
            "SELECT u.userid, u.name, u.dept_id
             FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id
             WHERE u.userid NOT LIKE 'HIST_%'
               AND u.wecom_userid IS NOT NULL
               AND COALESCE(d.name, '') NOT IN ('已离职','未匹配')
             ORDER BY u.name ASC"
        )->fetchAll();
        json_out(['success' => true, 'users' => $users, 'year' => $year]);
    }

    $balance = vacation_calc_balance($db, $userid, $year);

    // 流水明细（source=1/4 审批通过/撤销 LEFT JOIN 审批表拿 sp_no+假期起止，后台表格直接展示）
    // LEFT JOIN users 表取操作人姓名，存入 op_by_name，优先给前端显示
    $ledger = [];
    $ledger_error = null;
    try {
        // 历史坑：MySQL 多表 JOIN 两侧列排序规则（utf8mb4_general_ci vs unicode_ci）不一致
        // 会抛 Illegal mix of collations 错误，这里在 JOIN 条件上强制统一 collation；
        // SQLite 环境不支持该子句，保持原样。
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            $sql = "SELECT l.id, l.source, l.delta, l.ref_no, l.op_by, l.remark, l.created_at,
                           a.sp_no AS sp_no, a.leave_start AS start_date, a.leave_end AS end_date,
                           a.leave_days AS leave_days, a.apply_time AS apply_time,
                           u.name AS op_by_name
                    FROM vacation_ledger l
                    LEFT JOIN vacation_approvals a
                           ON a.sp_no COLLATE utf8mb4_unicode_ci = l.ref_no COLLATE utf8mb4_unicode_ci
                    LEFT JOIN users u
                           ON u.userid COLLATE utf8mb4_unicode_ci = l.op_by COLLATE utf8mb4_unicode_ci
                    WHERE l.userid = ? AND l.year = ?
                    ORDER BY l.created_at DESC, l.id DESC";
        } else {
            $sql = "SELECT l.id, l.source, l.delta, l.ref_no, l.op_by, l.remark, l.created_at,
                           a.sp_no AS sp_no, a.leave_start AS start_date, a.leave_end AS end_date,
                           a.leave_days AS leave_days, a.apply_time AS apply_time,
                           u.name AS op_by_name
                    FROM vacation_ledger l
                    LEFT JOIN vacation_approvals a ON a.sp_no = l.ref_no
                    LEFT JOIN users u ON u.userid = l.op_by
                    WHERE l.userid = ? AND l.year = ?
                    ORDER BY l.created_at DESC, l.id DESC";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([$userid, $year]);
        $ledger = $stmt->fetchAll();
        // 老数据兼容：leave_start/leave_end 仅 Y-m-d（10 位，全 ASCII 字节数=10）时补默认时分
        foreach ($ledger as &$row) {
            if (!empty($row['start_date']) && strlen($row['start_date']) === 10) {
                $row['start_date'] = $row['start_date'] . ' 08:00';
            }
            if (!empty($row['end_date']) && strlen($row['end_date']) === 10) {
                $row['end_date'] = $row['end_date'] . ' 17:00';
            }
            // 审批通过 / 审批撤销两行的时间列（流水表第一列、详情卡时间）都显示审批提交时间，
            // 避免入账/同步时间（created_at）与用户真正提交时间不一致导致误解。
            // 未同步的老数据 apply_time 为空时回退 created_at，保证不为空。
            $src = (int)($row['source'] ?? 0);
            if (($src === 1 || $src === 4) && !empty($row['apply_time'])) {
                $row['show_time'] = $row['apply_time'];
            } else {
                $row['show_time'] = $row['created_at'];
            }
        }
        unset($row);
    } catch (Throwable $e) {
        // 不再静默吞错：写入本地日志 + 返回给前端，便于直接定位问题
        error_log('[vacation_ledger] userid=' . $userid . ',year=' . $year . ', error=' . $e->getMessage());
        $ledger_error = $e->getMessage();
        $ledger = [];
    }

    // 员工基本信息（主查询：仅企微员工）
    $stmt = $db->prepare("SELECT userid, name, dept_id, hire_date, vacation_exempt, vacation_rule_type, vacation_special_start, vacation_special_days, vacation_special_cap FROM users WHERE userid = ? AND wecom_userid IS NOT NULL LIMIT 1");
    $stmt->execute([$userid]);
    $emp = $stmt->fetch();

    json_out([
        'success'      => true,
        'year'         => $year,
        'emp'          => $emp ?: null,
        'balance'      => $balance,
        'ledger'       => $ledger,
        'ledger_error' => $ledger_error,
    ]);
}

// ===== 年假豁免开关切换 =====
function handle_admin_vacation_toggle_exempt() {
    require_admin_session([ROLE_ADMIN, ROLE_HR]);
    $db = get_db();

    $userid  = trim((string)param('userid', ''));
    $exempt  = (int)param('exempt', 0);   // 0=正常 / 1=不享受年假

    if ($userid === '') {
        json_out(['success' => false, 'error' => '缺少员工参数']);
    }
    if (!in_array($exempt, [0, 1], true)) {
        json_out(['success' => false, 'error' => '参数不合法']);
    }

    // 校验员工存在
    $stmt = $db->prepare("SELECT userid, name FROM users WHERE userid = ? LIMIT 1");
    $stmt->execute([$userid]);
    $emp = $stmt->fetch();
    if (!$emp) {
        json_out(['success' => false, 'error' => '员工不存在']);
    }

    try {
        $stmt = $db->prepare("UPDATE users SET vacation_exempt = ? WHERE userid = ?");
        $stmt->execute([$exempt, $userid]);
        audit_log('vacation_toggle_exempt', 'user:' . $userid, 'ok', [
            'exempt' => $exempt,
            'name'   => $emp['name'] ?? '',
        ]);
        json_out(['success' => true, 'exempt' => $exempt]);
    } catch (Throwable $e) {
        json_out(['success' => false, 'error' => '保存失败：' . $e->getMessage()]);
    }
}

// ===== 年假总览-切换计算方式 =====
/**
 * POST /api/admin/vacation/overview_set_rule
 * 批量/单个 切换员工的年假计算方式：
 *   - normal  : 常规规则（清零 exemption + 特殊规则字段）
 *   - special : 两年+1天规则（需提供 special_start / special_days / special_cap）
 *   - exempt  : 豁免（vacation_exempt=1）
 */
function handle_admin_vacation_overview_set_rule() {
    $me = require_admin_session([ROLE_ADMIN, ROLE_HR]);
    $db = get_db();

    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $userid  = trim((string)($payload['userid'] ?? ''));
    $rules   = $payload['rules'] ?? null;  // 批量模式：[{userid,rule_type,...}, ...]
    $dryRun  = !empty($payload['dry_run']);

    // 兼容单条模式（前端 overview 下拉直接调用）
    if ($rules === null && $userid !== '') {
        $rules = [$payload];
    }
    if (!is_array($rules) || empty($rules)) {
        json_out(['success' => false, 'error' => '缺少规则变更数据']);
    }

    $opUser = $me['userid'] ?? '';

    $errors  = [];
    $updated = 0;
    $details = [];

    foreach ($rules as $idx => $r) {
        $uid = trim((string)($r['userid'] ?? ''));
        if ($uid === '') {
            $errors[] = '第 ' . ($idx + 1) . ' 条缺少 userid';
            continue;
        }
        $type = trim((string)($r['rule_type'] ?? 'normal'));
        if (!in_array($type, ['normal', 'special', 'exempt'], true)) {
            $errors[] = '第 ' . ($idx + 1) . ' 条未知 rule_type: ' . $type;
            continue;
        }

        // 特殊规则参数
        $spStart = trim((string)($r['special_start'] ?? ''));
        $spDays  = (int)($r['special_days'] ?? 0);
        $spCap   = (int)($r['special_cap'] ?? 15);

        // 校验员工存在
        $stmt = $db->prepare("SELECT userid, name FROM users WHERE userid = ? AND wecom_userid IS NOT NULL LIMIT 1");
        $stmt->execute([$uid]);
        $emp = $stmt->fetch();
        if (!$emp) {
            $errors[] = $uid . ': 员工不存在或非企微员工';
            continue;
        }

        // 特殊规则校验
        if ($type === 'special') {
            if ($spStart === '') {
                $errors[] = $uid . ': 特殊规则必须设置起点日期';
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $spStart)) {
                $errors[] = $uid . ': 起点日期格式应为 YYYY-MM-DD';
                continue;
            }
            if ($spDays <= 0) {
                $errors[] = $uid . ': 起点天数必须大于 0';
                continue;
            }
            if ($spCap < $spDays) {
                $errors[] = $uid . ': 封顶天数不能小于起点天数';
                continue;
            }
        }

        if ($dryRun) {
            $updated++;
            $details[] = ['userid' => $uid, 'name' => $emp['name'] ?? '', 'rule_type' => $type, 'dry_run' => true];
            continue;
        }

        try {
            if ($type === 'exempt') {
                $stmt = $db->prepare("UPDATE users SET vacation_exempt = 1, vacation_rule_type = 0 WHERE userid = ?");
                $stmt->execute([$uid]);
            } elseif ($type === 'special') {
                $stmt = $db->prepare("UPDATE users SET vacation_exempt = 0, vacation_rule_type = 1, vacation_special_start = ?, vacation_special_days = ?, vacation_special_cap = ? WHERE userid = ?");
                $stmt->execute([$spStart, $spDays, $spCap, $uid]);
            } else { // normal
                $stmt = $db->prepare("UPDATE users SET vacation_exempt = 0, vacation_rule_type = 0, vacation_special_start = NULL, vacation_special_days = 3, vacation_special_cap = 15 WHERE userid = ?");
                $stmt->execute([$uid]);
            }

            // 重新计算年假余额，返回给前端刷新
            $newBalance = vacation_calc_balance($db, $uid, (int)date('Y'));
            $updated++;
            $details[] = [
                'userid' => $uid,
                'name'   => $emp['name'] ?? '',
                'rule_type' => $type,
                'special_start' => $spStart,
                'special_days'  => $spDays,
                'special_cap'   => $spCap,
                'balance_data'  => $newBalance,  // 用于前端刷新
            ];
        } catch (Throwable $e) {
            $errors[] = $uid . ': 保存失败 - ' . $e->getMessage();
        }
    }

    // 审计日志（汇总记录一次即可，避免批量时刷爆 audit_log）
    $hasError = !empty($errors);
    audit_log('vacation_set_rule', 'bulk:' . count($rules), $hasError ? 'warn' : 'ok', [
        'op'       => $opUser,
        'updated'  => $updated,
        'errors'   => $errors,
        'details'  => $details,
        'dry_run'  => $dryRun ? 1 : 0,
    ]);

    json_out([
        'success' => !$hasError,
        'updated' => $updated,
        'errors'  => $errors,
        'dry_run' => $dryRun,
        'details' => $details,  // 返回更新后的数据供前端刷新
    ]);
}

// ===== 年假规则配置 =====
function handle_admin_vacation_rules() {
    require_admin_session([ROLE_ADMIN]);
    $db = get_db();
    $year = (int)param('year', (int)date('Y'));

    // POST：保存规则
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $minYears       = (int)param('min_years', 3);
        $baseDays       = (float)param('base_days', 3);
        $stepDays       = (float)param('step_days', 1);
        $maxDays        = (float)param('max_days', 10);
        $cap1MaxDays    = (float)param('cap1_max_days', 10);
        $cap2Min        = (float)param('cap2_min', 20);
        $cap2MaxDays    = (float)param('cap2_max_days', 15);
        $precision      = (int)param('precision', 2);
        $allowCrossYear = (int)param('allow_cross_year', 0);

        if ($minYears < 1 || $minYears > 30) {
            json_out(['success' => false, 'error' => '满工龄门槛应在 1~30 年之间']);
        }
        if ($baseDays < 0 || $stepDays < 0 || $maxDays < 0 || $cap1MaxDays < 0 || $cap2Min < 0 || $cap2MaxDays < 0) {
            json_out(['success' => false, 'error' => '天数参数不能为负数']);
        }
        if ($cap1MaxDays < $baseDays) {
            json_out(['success' => false, 'error' => '档1封顶天数不应小于首年基数']);
        }
        if ($cap2Min <= $cap1MaxDays) {
            json_out(['success' => false, 'error' => '档2阈值必须大于档1封顶天数（默认档1=10、档2阈值=20）']);
        }
        if ($cap2MaxDays <= $cap1MaxDays) {
            json_out(['success' => false, 'error' => '档2封顶天数必须大于档1封顶天数']);
        }
        if (!in_array($precision, [0, 1, 2], true)) {
            json_out(['success' => false, 'error' => '精度仅支持 0/1/2 位小数']);
        }

        try {
            $isMy = (defined('DB_TYPE') && DB_TYPE === 'mysql');
            $colYear    = $isMy ? '`year`'           : 'year';
            $colPrec    = $isMy ? '`round_precision`': 'round_precision';
            $colCross   = $isMy ? '`allow_cross_year`':'allow_cross_year';
            $upsert = $isMy
                ? "INSERT INTO vacation_rules
                   ({$colYear}, min_years, base_days, year1_prorate, increment_per_year,
                    max_days, cap1_max_days, cap2_min, cap2_max_days,
                    {$colPrec}, {$colCross}, updated_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE
                     min_years=VALUES(min_years), base_days=VALUES(base_days),
                     year1_prorate=VALUES(year1_prorate), increment_per_year=VALUES(increment_per_year),
                     max_days=VALUES(max_days), cap1_max_days=VALUES(cap1_max_days),
                     cap2_min=VALUES(cap2_min), cap2_max_days=VALUES(cap2_max_days),
                     {$colPrec}=VALUES({$colPrec}), {$colCross}=VALUES({$colCross}),
                     updated_at=NOW()"
                : "INSERT OR REPLACE INTO vacation_rules
                   (year, min_years, base_days, year1_prorate, increment_per_year,
                    max_days, cap1_max_days, cap2_min, cap2_max_days,
                    round_precision, allow_cross_year, updated_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime('now'))";
            // 旧列 max_days 与 cap1_max_days 同步写一份，兼容未升级的读路径
            $legacyMax = $cap1MaxDays;
            $db->prepare($upsert)->execute([
                $year, $minYears, $baseDays, 1, $stepDays,
                $legacyMax, $cap1MaxDays, $cap2Min, $cap2MaxDays,
                $precision, $allowCrossYear
            ]);
            audit_log('vacation_rules', 'year:' . $year, 'ok', [
                'min_years' => $minYears, 'base_days' => $baseDays,
                'step_days' => $stepDays, 'max_days' => $legacyMax,
                'cap1_max_days' => $cap1MaxDays, 'cap2_min' => $cap2Min, 'cap2_max_days' => $cap2MaxDays,
                'precision' => $precision, 'allow_cross_year' => $allowCrossYear,
            ]);
            json_out(['success' => true]);
        } catch (Throwable $e) {
            json_out(['success' => false, 'error' => '保存规则失败：' . $e->getMessage()]);
        }
    }

    // GET：读取规则
    $rule = vacation_get_rules($year);
    json_out([
        'success' => true,
        'year'    => $year,
        'rule'    => $rule,
    ]);
}

// ===== 审批同步管理 =====
function handle_admin_vacation_sync() {
    require_admin_session([ROLE_ADMIN, ROLE_HR]);

    // POST：手动触发批量同步
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $lookback = (int)param('lookback_days', 30);
        if ($lookback < 1 || $lookback > 90) {
            $lookback = 30;
        }
        $templateId = (string)get_setting('vacation_template_id', '');
        if ($templateId === '') {
            json_out(['success' => false, 'error' => '请先在「系统设置→企业微信→年假同步参数」中配置年假审批模板ID']);
        }
        $result = vacation_sync_approvals($lookback);
        audit_log('vacation_sync', 'manual', $result['errors'] ? 'warn' : 'ok', [
            'lookback_days'  => $lookback,
            'processed'      => $result['processed'],
            'inserted'       => $result['inserted_approvals'],
            'ledger_changes' => $result['ledger_changes'],
            'errors'         => count($result['errors']),
        ]);
        json_out(array_merge(['success' => true], $result));
    }

    // GET：读取同步状态
    $lastSyncAt = (string)get_setting('vacation_last_sync_at', '');
    $templateId = (string)get_setting('vacation_template_id', '');
    $db = get_db();

    // 从 approval_callback_logs 表查最近 10 条回调记录
    $recentLogs = [];
    try {
        $rows = $db->query(
            "SELECT received_at, method, sp_no, template_id, sp_status, apply_name,
                    signature_ok, decrypt_ok, sync_status, sync_detail
             FROM approval_callback_logs
             ORDER BY id DESC
             LIMIT 10"
        )->fetchAll();
        foreach ($rows as $r) {
            $recentLogs[] = [
                'time'         => $r['received_at'],
                'method'       => $r['method'],
                'sp_no'        => $r['sp_no'],
                'apply_name'   => $r['apply_name'],
                'sp_status'    => (int)$r['sp_status'],
                'sig_ok'       => (int)$r['signature_ok'] === 1,
                'decrypt_ok'   => (int)$r['decrypt_ok'] === 1,
                'sync_status'  => $r['sync_status'],
                'sync_detail'  => $r['sync_detail'],
            ];
        }
    } catch (Throwable $e) {}

    // 统计 vacation_approvals 表记录数
    $approvalCount = 0;
    try {
        $approvalCount = (int)$db->query("SELECT COUNT(*) AS c FROM vacation_approvals")->fetch()['c'];
    } catch (Throwable $e) {}

    json_out([
        'success'             => true,
        'last_sync_at'        => $lastSyncAt,
        'template_id'         => $templateId !== '' ? substr($templateId, 0, 8) . '***' : '',
        'template_configured'=> $templateId !== '',
        'approval_count'      => $approvalCount,
        'recent_logs'         => $recentLogs,
    ]);
}

// ===== 按「全年」批量同步审批单（支持跨月自动拆分 + 干跑模式）=====
/**
 * POST /api/admin/vacation/sync_full
 *   year     int  要同步的年份（默认当年；限制 2020 ~ 今年+1）
 *   dry_run  int  1=干跑模式（外层事务最后 ROLLBACK，DB 零污染）；0=正式写入
 *
 * 返回：
 *   success, year, range[start,end], total, processed, success, skipped, failed,
 *   ledger_changes, skip_logs[], fail_logs[], dry_run, last_sync_at
 *
 * 为什么不直接调用 vacation_sync_approvals：
 *   后者是「回看 N 天」的短周期增量模型，这里是「全年一次性回填历史」模型，
 *   两者幂等策略（全年 ROLLBACK 干跑）和返回格式（前端需失败明细展开）差异较大，
 *   分两个 handler 保持单一职责。
 */
function handle_admin_vacation_sync_full() {
    $me = require_admin_session([ROLE_ADMIN, ROLE_HR]);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅允许 POST']);
    }

    $db = get_db();

    $year   = (int)param('year', (int)date('Y'));
    $dryRun = (int)param('dry_run', 1) === 1;

    $minYear = 2020;
    $maxYear = (int)date('Y') + 1;
    if ($year < $minYear || $year > $maxYear) {
        json_out(['success' => false, 'error' => "年份范围仅允许 {$minYear} ~ {$maxYear}"]);
    }

    $templateId = (string)get_setting('vacation_template_id', '');
    if ($templateId === '') {
        json_out(['success' => false, 'error' => '请先在「系统设置→企业微信→年假同步参数」中配置年假审批模板ID']);
    }

    // 时间范围：该年 1/1 00:00:00 ~ 今年末 23:59:59（若为当年则只到今天 23:59:59，避免抓未来区间）
    $startTs = mktime(0, 0, 0, 1, 1, $year);
    if ($year === (int)date('Y')) {
        $endTs = mktime(23, 59, 59, (int)date('m'), (int)date('d'), $year);
    } else {
        $endTs = mktime(23, 59, 59, 12, 31, $year);
    }
    // 防止极端：当年 1/1 执行时 endTs 可能 == startTs（补齐一天 23:59:59）
    if ($endTs <= $startTs) {
        $endTs = $startTs + 86399;
    }

    // Step 1: 拉全年 sp_nos（wecom_get_approval_list 内部自动按 ≤30天 chunk 拆分企微硬限制）
    $list = wecom_get_approval_list($templateId, $startTs, $endTs, 100);
    if (empty($list['ok'])) {
        json_out([
            'success' => false,
            'error'   => '拉取审批单号列表失败: ' . ($list['errmsg'] ?? 'unknown'),
            'errcode' => $list['errcode'] ?? null,
        ]);
    }
    $spNos = array_values($list['sp_nos'] ?? []);
    $total = count($spNos);

    // Step 2: 干跑模式 → 外层统一事务（最后 ROLLBACK；所有单条跳过自管事务，只让外层决定）
    $inOuterTx = false;
    if ($dryRun) {
        try {
            $db->beginTransaction();
            $inOuterTx = true;
        } catch (Throwable $e) {
            json_out(['success' => false, 'error' => '干跑事务开启失败: ' . $e->getMessage()]);
        }
    }

    $cntSuccess = 0;
    $cntSkipped = 0;
    $cntFailed  = 0;
    $ledgerChanges = 0;
    $skipLogs = [];
    $failLogs = [];

    foreach ($spNos as $i => $spNo) {
        // handleOwnTx = false 当且仅当外层已经有事务（干跑模式），否则每条自管事务（避免一条失败 rollback 全年）
        $r = vacation_process_sp_detail($spNo, !$inOuterTx);

        if (!empty($r['error'])) {
            $cntFailed++;
            $failLogs[] = [
                'sp_no'  => $spNo,
                'name'   => $r['name'] ?? '',
                'status' => (int)($r['sp_status'] ?? 0),
                'error'  => $r['error'],
            ];
            continue;
        }
        if (!empty($r['skipped'])) {
            $cntSkipped++;
            $s = (int)($r['sp_status'] ?? 0);
            switch (true) {
                case $s === 1: $reason = '审批中（未到扣减时机，仅保存快照）'; break;
                case $s === 3: $reason = '已驳回（不写流水）'; break;
                case $s === 4: $reason = '提交人主动撤销（不冲回）'; break;
                case $s >= 5:  $reason = '状态代码=' . $s . '（非 2=通过/6=通过后撤销，不写流水）'; break;
                default:       $reason = '模板不匹配或非扣减状态（仅保存快照）'; break;
            }
            $skipLogs[] = [
                'sp_no'  => $spNo,
                'name'   => $r['name'] ?? '',
                'status' => $s,
                'reason' => $reason,
            ];
            continue;
        }
        $cntSuccess++;
        if (!empty($r['ledger_changed'])) $ledgerChanges++;
    }

    // Step 3: 结束事务 + 审计日志 + 写入 last_sync_at
    $lastSyncAt = '';
    try {
        if ($inOuterTx) {
            // 干跑：无论结果如何一律 ROLLBACK → DB 零写入
            try { $db->rollBack(); } catch (Throwable $e) {}
        } else {
            // 正式：更新 last_sync_at 设置
            $lastSyncAt = date('Y-m-d H:i:s');
            set_setting('vacation_last_sync_at', $lastSyncAt);
        }

        $op = $me['userid'] ?? '';
        audit_log('vacation_sync_full', 'year:' . $year, $cntFailed > 0 ? 'warn' : 'ok', [
            'year'           => $year,
            'dry_run'        => $dryRun,
            'range_start'    => date('Y-m-d H:i:s', $startTs),
            'range_end'      => date('Y-m-d H:i:s', $endTs),
            'total'          => $total,
            'success'        => $cntSuccess,
            'skipped'        => $cntSkipped,
            'failed'         => $cntFailed,
            'ledger_changes' => $ledgerChanges,
            'op'             => $op,
        ]);
    } catch (Throwable $e) {
        // 审计/设置写入失败不影响主结果（可能是 SQLite 锁）
        if ($inOuterTx && $db->inTransaction()) { try { $db->rollBack(); } catch (Throwable $e2) {} }
    }

    json_out([
        'success'        => true,  // API 层调用成功（≠ 所有审批单处理成功，具体看 failed/processed_ok）
        'year'           => $year,
        'range'          => [date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs)],
        'total'          => $total,
        'processed'      => $cntSuccess + $cntSkipped + $cntFailed,
        'processed_ok'   => $cntSuccess,
        'skipped'        => $cntSkipped,
        'failed'         => $cntFailed,
        'ledger_changes' => $ledgerChanges,
        'skip_logs'      => $skipLogs,
        'fail_logs'      => $failLogs,
        'dry_run'        => $dryRun,
        'last_sync_at'   => $lastSyncAt,
    ]);
}

// ===== 年假总览 Excel 导出 =====
/**
 * GET /api/admin/vacation/overview_export?year=&dept=&q=
 *
 * 与 overview 使用完全相同的 WHERE 条件（year/dept/q 三维过滤），
 * 但不分页，一次性导出所有符合条件的员工。
 *
 * 列顺序（与页面表格一致，操作列排除、资格用中文、追加计算说明便于追溯）：
 *   姓名 | 部门 | 入职日期 | 资格 | 应享天数 | 已休天数 | 手动调整 | 剩余余额 | 审批单数 | 计算说明
 */
function handle_admin_vacation_overview_export() {
    require_admin_session([ROLE_ADMIN, ROLE_FINANCE, ROLE_HR]);
    $db = get_db();

    $year   = (int)param('year', (int)date('Y'));
    $deptId = (int)param('dept', 0);
    $q      = trim((string)param('q', ''));
    $filter = trim((string)param('filter', 'all'));

    // 与 overview 完全相同的过滤条件，保证"导出"="页面所见（除分页外）"
    //   企微真实在职员工：wecom_userid 非空、非 HIST_ 存档、部门 ∉ (已离职,未匹配)
    $where = " WHERE u.userid NOT LIKE 'HIST_%' AND u.wecom_userid IS NOT NULL AND COALESCE(d.name, '') NOT IN ('已离职','未匹配')";
    $params = [];
    if ($deptId > 0) {
        $where .= " AND u.dept_id = ?";
        $params[] = $deptId;
    }
    if ($q !== '') {
        $where .= " AND (u.name LIKE ? OR u.userid LIKE ? OR u.wecom_userid LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    // 年假状态筛选：与 overview handler 保持一致
    if ($filter === 'normal') {
        $where .= " AND (u.vacation_exempt = 0 OR u.vacation_exempt IS NULL)";
    } elseif ($filter === 'exempt') {
        $where .= " AND u.vacation_exempt = 1";
    }

    $listSql = "SELECT u.userid, u.name, u.wecom_userid, u.dept_id, u.hire_date, u.vacation_exempt, d.name AS dept_name"
             . " FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id"
             . $where
             . " ORDER BY u.dept_id ASC, u.name ASC";
    $stmt = $db->prepare($listSql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    $headers = ['姓名', '部门', '入职日期', '年假状态', '资格', '应享天数', '已休天数', '手动调整', '剩余余额', '审批单数', '计算说明'];
    $rows = [];
    foreach ($users as $u) {
        $b = vacation_calc_balance($db, $u['userid'], $year);
        $isExempt = (int)($u['vacation_exempt'] ?? 0) === 1;
        $rows[] = [
            $u['name'],
            $u['dept_name'] ?? '',
            $u['hire_date'] ?? '',
            $isExempt ? '不享受年假' : '正常',
            !empty($b['eligible']) ? '符合' : '不符合',
            (float)($b['entitlement'] ?? 0),
            (float)($b['used_days'] ?? 0),
            (float)($b['manual_delta'] ?? 0),
            (float)($b['balance'] ?? 0),
            (int)($b['approval_count'] ?? 0),
            $b['eligible_detail'] ?? '',
        ];
    }

    $filename = '年假总览_' . $year . '_' . date('Ymd_His') . '.xlsx';

    try {
        $xlsx = excel_write_xlsx($headers, $rows, '年假总览' . $year);
    } catch (Throwable $e) {
        // 生成失败：走 HTTP 500 + JSON 错误，但因为 fetch (非 blob 模式下) 会被前端按文本解析，
        // 这里直接用 Content-Type: text/plain 输出可读错误，便于前端提示用户。
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(500);
        }
        exit('Excel 生成失败：' . $e->getMessage());
    }

    if (!headers_sent()) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Length: ' . strlen($xlsx));
        // 纯 ASCII fallback 防止 Safari/旧 IE 中文文件名乱码；RFC 5987 编码覆盖主流现代浏览器
        $asciiFallback = 'vacation_overview_' . $year . '.xlsx';
        $encoded = rawurlencode($filename);
        header('Content-Disposition: attachment; filename="' . $asciiFallback . '"; filename*=UTF-8\'\'' . $encoded);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }
    echo $xlsx;
    exit;
}

// ========================================================================
// 审批调试工具（「审批调试」Tab 用，仅 ADMIN）
// ========================================================================

/**
 * 扫描任务单条 sp_no 详情的"批大小"。
 * - 每次 scan_next 只处理 BATCH_SIZE 条：避免单请求超时（企微串行 HTTP 耗时长）；
 * - 也避免触发 rate limit（企微 getapprovalinfo/detail ~100 次/分钟）。
 */
define('VAC_DEBUG_BATCH_SIZE', 40);
/** 时间范围上限（含）：180 天 */
define('VAC_DEBUG_MAX_DAYS', 180);
/** 结果缓存 TTL（秒）：1 小时 */
define('VAC_DEBUG_CACHE_TTL', 3600);
/** settings 中 scan cache 的 key（同一时间范围复用一份聚合结果，1h 内命中即跳过真实扫描） */
define('VAC_DEBUG_CACHE_KEY', 'vacation_debug_scan_cache');
/** settings 中「最近一次扫描结果」的 key（永不过期，页面打开时优先恢复显示表格） */
define('VAC_DEBUG_LATEST_KEY', 'vacation_debug_scan_latest');

/**
 * 把完整扫描结果同时写入两份 settings：
 *   1. VAC_DEBUG_CACHE_KEY  — 带 sig（范围签名）+ TTL，用于"1 小时内相同范围命中即不真扫"；
 *   2. VAC_DEBUG_LATEST_KEY — 同内容但不带 TTL 限制语义，页面打开时无条件恢复显示；
 * payload 结构保持一致：{ cached_at, sig, range, templates, meta }
 * @param array $payload
 */
function _vac_debug_write_result($payload) {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    set_setting(VAC_DEBUG_CACHE_KEY,  $json);
    set_setting(VAC_DEBUG_LATEST_KEY, $json);
}

/**
 * 给模板数组做字段兼容（老结构缺字段时补默认值，避免前端渲染崩）。
 * @param array $templates
 * @return array
 */
function _vac_debug_normalize_templates($templates) {
    $curTemplateId = _vac_debug_current_template_id();
    $out = [];
    foreach ((array)$templates as $t) {
        if (!is_array($t)) continue;
        $tplId = (string)($t['template_id'] ?? '');
        if ($tplId === '') continue;
        $out[] = [
            'template_id'          => $tplId,
            'template_name'        => (string)($t['template_name'] ?? '(未命名模板)'),
            'sp_count'             => (int)($t['sp_count'] ?? 0),
            'sample_sp_no'         => (string)($t['sample_sp_no'] ?? ''),
            'sample_apply_time'    => (string)($t['sample_apply_time'] ?? ''),
            'applyer_names'        => isset($t['applyer_names']) && is_array($t['applyer_names']) ? $t['applyer_names'] : [],
            'controls_count'       => (int)($t['controls_count'] ?? 0),
            'control_names'        => isset($t['control_names']) && is_array($t['control_names']) ? $t['control_names'] : [],
            'is_vacation_template' => $curTemplateId !== '' && $curTemplateId === $tplId,
        ];
    }
    return $out;
}

/**
 * 解析 YYYY-MM-DD 字符串为 timestamp（00:00:00 / 23:59:59），失败返回 false。
 * @param string $s
 * @param bool $endOfDay true=补 23:59:59
 * @return int|false
 */
function _vac_debug_parse_date($s, $endOfDay = false) {
    $s = trim((string)$s);
    if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) return false;
    $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
    if (!checkdate($mo, $d, $y)) return false;
    return $endOfDay ? mktime(23, 59, 59, $mo, $d, $y) : mktime(0, 0, 0, $mo, $d, $y);
}

/**
 * 获取「当前已配置年假模板 ID」，用于模板库表格的 ⭐ 星标高亮。
 * @return string
 */
function _vac_debug_current_template_id() {
    $k = get_settings_by_keys(['vacation_template_id'], '');
    return (string)($k['vacation_template_id'] ?? '');
}

/**
 * 根据企微 apply_userid（一般是 wecom_userid）反查内部 users.name / dept_name；查不到回退 $fallbackName。
 * @param PDO $db
 * @param string $wecomUserId
 * @param string $fallbackName
 * @return array{name:string,dept_name:string}
 */
function _vac_debug_resolve_user($db, $wecomUserId, $fallbackName = '') {
    $out = ['name' => trim((string)$fallbackName), 'dept_name' => ''];
    $wu = trim((string)$wecomUserId);
    if ($wu === '') return $out;
    // 优先按 wecom_userid，其次按 name 字段精确匹配（企业微信审批详情里 applyer 通常没 name，要靠内部 users 表补）
    $stmt = $db->prepare("SELECT u.name, d.name AS dept_name FROM users u LEFT JOIN departments d ON u.dept_id=d.dept_id WHERE u.wecom_userid=? LIMIT 1");
    $stmt->execute([$wu]);
    $row = $stmt->fetch();
    if ($row && !empty($row['name'])) {
        $out['name'] = (string)$row['name'];
        $out['dept_name'] = (string)($row['dept_name'] ?? '');
        return $out;
    }
    return $out;
}

/**
 * 从审批单 apply_data 中抽取模板控件名称列表（Table 子项不展开，标记为"xxx表"）。
 * 模板库表格用"控件名称"列，帮助一眼区分不同模板。
 * @param array $applyData
 * @return string[]
 */
function _vac_debug_extract_control_names($applyData) {
    $contents = (array)(($applyData['contents'] ?? []));
    $names = [];
    foreach ($contents as $c) {
        $title = '';
        foreach ((array)($c['title'] ?? []) as $t) {
            if (!empty($t['text'])) { $title = (string)$t['text']; break; }
        }
        $control = (string)($c['control'] ?? '');
        if ($control === 'Table') {
            $title = $title !== '' ? ($title . '表') : '明细表';
        }
        $name = $title !== '' ? $title : ($control !== '' ? ('[' . $control . ']') : '未命名');
        $names[] = $name;
    }
    return $names;
}

/**
 * POST /api/admin/vacation/debug_scan_start
 * 初始化扫描任务。
 *   body: { start_date: 'YYYY-MM-DD', end_date: 'YYYY-MM-DD', force: 0|1 }
 *   force=1：忽略 1 小时缓存，强制重扫
 *
 * 流程：
 *   1) 校验时间范围 ≤ VAC_DEBUG_MAX_DAYS；
 *   2) 查 settings.vacation_debug_scan_cache —— 若命中（范围一致、未过期、!force），直接返回 cached=true + 完整 templates 列表，scan_next 无需再跑；
 *   3) 未命中：调用 wecom_get_approval_list(template_id=null, start, end) 拉全量 sp_no 列表（内部自动按 31 天拆块）；
 *   4) 生成 task_id（纯标识；实际批次游标就是 sp_list 的数字 offset），写入 $_SESSION + settings 的"待处理"占位；
 *   5) 返回 { task_id, total_sp, progress, has_more }，前端再循环调 scan_next。
 */
function handle_admin_vacation_debug_scan_start() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅允许 POST']);
    }
    $in = json_decode(file_get_contents('php://input'), true);
    $startStr = trim((string)($in['start_date'] ?? ''));
    $endStr   = trim((string)($in['end_date'] ?? ''));
    $force    = !empty($in['force']);

    $startTs = _vac_debug_parse_date($startStr, false);
    $endTs   = _vac_debug_parse_date($endStr, true);
    if ($startTs === false || $endTs === false) {
        json_out(['success' => false, 'error' => '日期格式错误，应为 YYYY-MM-DD']);
    }
    if ($endTs < $startTs) {
        json_out(['success' => false, 'error' => '结束日期必须大于等于开始日期']);
    }
    $days = (int)ceil(($endTs - $startTs) / 86400);
    if ($days > VAC_DEBUG_MAX_DAYS) {
        json_out(['success' => false, 'error' => '时间范围过大（上限 ' . VAC_DEBUG_MAX_DAYS . ' 天），请分段扫描']);
    }

    $cacheKey = VAC_DEBUG_CACHE_KEY;
    $cacheSig = $startStr . '|' . $endStr;

    // ===== 缓存命中检查（1 小时内，相同范围） =====
    if (!$force) {
        $row = get_settings_by_keys([$cacheKey], '');
        $raw = $row[$cacheKey] ?? '';
        if (is_string($raw) && $raw !== '') {
            $cached = @json_decode($raw, true);
            if (
                is_array($cached)
                && !empty($cached['cached_at'])
                && (time() - (int)$cached['cached_at'] <= VAC_DEBUG_CACHE_TTL)
                && ($cached['sig'] ?? '') === $cacheSig
                && isset($cached['templates'])
                && is_array($cached['templates'])
            ) {
                // 兼容旧缓存结构（没写 control_names 时给空数组，避免前端渲染崩）
                $templatesOut = _vac_debug_normalize_templates($cached['templates']);
                $scannedCount = (int)($cached['meta']['scanned_sp'] ?? 0);
                // 命中缓存也把 latest 再刷一次，保证页面打开看到的就是"最近一次有效结果"（cached_at/sig 保持原样）
                $range = isset($cached['range']) ? $cached['range'] : ['start' => $startStr, 'end' => $endStr];
                $payload = [
                    'cached_at' => (int)$cached['cached_at'],
                    'sig'       => (string)($cached['sig'] ?? $cacheSig),
                    'range'     => $range,
                    'templates' => $templatesOut,
                    'meta'      => is_array($cached['meta'] ?? null) ? $cached['meta'] : ['scanned_sp' => $scannedCount, 'failed_sp' => 0, 'failed_list' => []],
                ];
                _vac_debug_write_result($payload);
                json_out([
                    'success'     => true,
                    'cached'      => true,
                    'sig'         => $cacheSig,
                    'cached_at'   => date('Y-m-d H:i:s', (int)$cached['cached_at']),
                    'range'       => isset($cached['range']) ? $cached['range'] : ['start' => $startStr, 'end' => $endStr],
                    'range_days'  => $days,
                    'scanned_sp'  => $scannedCount,
                    'has_more'    => false,
                    'total_sp'    => $scannedCount,
                    'progress'    => 100,
                    'task_id'     => null,
                    'templates'   => $templatesOut,
                ]);
            }
        }
    }

    // ===== 拉 sp_no 全量列表（template_id=null，跨模板；内部 31 天自动拆） =====
    $listR = wecom_get_approval_list(null, $startTs, $endTs, 100);
    if (empty($listR['ok'])) {
        json_out([
            'success' => false,
            'error'   => '拉审批单号列表失败：' . ($listR['errmsg'] ?? '未知错误'),
            'errcode' => $listR['errcode'] ?? null,
        ]);
    }
    $spNos = array_values($listR['sp_nos'] ?? []);
    $total = count($spNos);

    // ===== 生成任务（SESSION 存待处理 sp_no 队列 + 聚合中间态） =====
    if (session_status() === PHP_SESSION_NONE) session_start();
    $taskId = 'vacscan_' . bin2hex(random_bytes(8));
    $_SESSION['vac_debug_task'] = [
        'task_id'    => $taskId,
        'sig'        => $cacheSig,
        'start_date' => $startStr,
        'end_date'   => $endStr,
        'created_at' => time(),
        'sp_queue'   => $spNos,         // 未处理的 sp_no（每次 scan_next 从头部 pop 一批）
        'processed'  => [],             // 已处理 sp_no 去重（保险，理论队列出一次就够）
        // 聚合中间态：按 template_id 为 key 做归并
        'agg'        => [
            // $tplId => [
            //   'template_id','template_name','sp_count','sample_sp_no','sample_apply_time',
            //   'applyer_names' => [['name','dept_name','time'],...],  // 按时间倒序最近 3 条
            //   'controls_count','sample_apply_data' => []
            // ]
        ],
        'failed'     => [],             // [ [sp_no, error], ... ]
    ];

    // 如果 sp_no 为 0，直接返回"空"，前端不需要 scan_next
    if ($total === 0) {
        json_out([
            'success'    => true,
            'cached'     => false,
            'sig'        => $cacheSig,
            'range_days' => $days,
            'scanned_sp' => 0,
            'total_sp'   => 0,
            'progress'   => 100,
            'has_more'   => false,
            'task_id'    => null,
            'templates'  => [],
            'info'       => '该时间范围内暂无审批单。',
        ]);
    }

    audit_log('vacation_debug_scan', "range:{$startStr}~{$endStr}(days:{$days})", 'ok', [
        'total_sp' => $total,
        'force'    => $force ? 1 : 0,
    ]);

    json_out([
        'success'    => true,
        'cached'     => false,
        'sig'        => $cacheSig,
        'range_days' => $days,
        'scanned_sp' => 0,
        'total_sp'   => $total,
        'progress'   => 0,
        'has_more'   => true,
        'task_id'    => $taskId,
        'batch_size' => VAC_DEBUG_BATCH_SIZE,
        'templates'  => [],
    ]);
}

/**
 * POST /api/admin/vacation/debug_scan_next
 *   body: { task_id }
 * 处理 SESSION 里队列的下一批（VAC_DEBUG_BATCH_SIZE 条）sp_no：
 *   调 wecom_get_approval_detail → 按 template_id 聚合 → 最后 has_more=false 时写 settings 缓存。
 */
function handle_admin_vacation_debug_scan_next() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅允许 POST']);
    }
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['vac_debug_task'])) {
        json_out(['success' => false, 'error' => '扫描任务已过期或不存在，请重新点"扫描审批单"']);
    }
    $task = &$_SESSION['vac_debug_task'];
    $in = json_decode(file_get_contents('php://input'), true);
    $taskId = trim((string)($in['task_id'] ?? ''));
    if ($taskId !== '' && ($task['task_id'] ?? '') !== $taskId) {
        json_out(['success' => false, 'error' => '任务ID不匹配，请重新开始扫描']);
    }

    $queue   = &$task['sp_queue'];
    $batch   = array_splice($queue, 0, VAC_DEBUG_BATCH_SIZE);
    $doneCnt = count($task['processed'] ?? []);
    $total   = $doneCnt + count($queue) + count($batch);

    $db  = get_db();
    $curTemplateId = _vac_debug_current_template_id();
    $agg = &$task['agg'];

    foreach ($batch as $spNo) {
        if (in_array($spNo, $task['processed'], true)) continue;
        $task['processed'][] = $spNo;

        $d = wecom_get_approval_detail($spNo);
        if (empty($d['ok'])) {
            $task['failed'][] = [
                'sp_no'  => $spNo,
                'error'  => ($d['errmsg'] ?? '未知错误'),
                'errcode'=> $d['errcode'] ?? null,
            ];
            continue;
        }
        $tplId  = (string)($d['template_id'] ?? '');
        $tplName = trim((string)($d['sp_name'] ?? ''));
        if ($tplId === '') {
            // 极个别老数据可能缺 template_id，给一个兜底占位避免丢失统计
            $tplId = '__UNKNOWN__';
            $tplName = $tplName ?: '(未识别模板)';
        }
        if (!isset($agg[$tplId])) {
            $agg[$tplId] = [
                'template_id'         => $tplId,
                'template_name'       => $tplName,
                'sp_count'            => 0,
                'sample_sp_no'        => '',
                'sample_apply_time'   => '',
                'applyer_snapshots'   => [],   // 最多 3 条，按 apply_time 倒序
                'controls_count'      => 0,
                'control_names'       => [],   // 模板控件名（按 sample 那条详情取前 8 个，Table 标"xxx表"）
            ];
        }
        // 若首次没拿到名字，后面有名字就补齐
        if ($agg[$tplId]['template_name'] === '' && $tplName !== '') {
            $agg[$tplId]['template_name'] = $tplName;
        }
        $agg[$tplId]['sp_count']++;

        $applyTime = (string)($d['apply_time'] ?? '');
        // 最近一条作为 sample（按时间字符串大小判断即可，YYYY-MM-DD HH:MM:SS 可按字典序）
        $isNewSample = ($agg[$tplId]['sample_sp_no'] === '' || $applyTime > $agg[$tplId]['sample_apply_time']);
        if ($isNewSample) {
            $agg[$tplId]['sample_sp_no']      = (string)($d['sp_no'] ?? $spNo);
            $agg[$tplId]['sample_apply_time'] = $applyTime;
            $applyData = $d['apply_data'] ?? [];
            $agg[$tplId]['controls_count']    = count((array)($applyData['contents'] ?? []));
            $agg[$tplId]['control_names']     = _vac_debug_extract_control_names($applyData);
        }

        // applyer 快照：最近 3 条（姓名 + 部门 + 申请时间）
        $applyerUserId = (string)($d['apply_userid'] ?? '');
        $applyerName   = trim((string)($d['apply_name'] ?? ''));
        $who = _vac_debug_resolve_user($db, $applyerUserId, $applyerName);
        if ($who['name'] !== '') {
            array_unshift($agg[$tplId]['applyer_snapshots'], [
                'name'      => $who['name'],
                'dept_name' => $who['dept_name'],
                'time'      => $applyTime,
            ]);
            if (count($agg[$tplId]['applyer_snapshots']) > 3) {
                array_pop($agg[$tplId]['applyer_snapshots']);
            }
        }
    }

    // ===== 进度 / 状态 =====
    $processedNow = count($task['processed']);
    $hasMore = !empty($queue);

    // 聚合完成 → 模板数组化 + ⭐ 标记 + 写 settings 缓存
    if (!$hasMore) {
        $templates = [];
        foreach ($agg as $tplId => $a) {
            $snapNames = [];
            foreach ($a['applyer_snapshots'] as $s) {
                $snapNames[] = trim($s['name'] . ($s['dept_name'] !== '' ? ('（' . $s['dept_name'] . '）') : ''));
            }
            $templates[] = [
                'template_id'          => $a['template_id'],
                'template_name'        => $a['template_name'] ?: '(未命名模板)',
                'sp_count'             => (int)$a['sp_count'],
                'sample_sp_no'         => $a['sample_sp_no'],
                'sample_apply_time'    => $a['sample_apply_time'],
                'applyer_names'        => $snapNames,
                'controls_count'       => (int)$a['controls_count'],
                'control_names'        => is_array($a['control_names'] ?? null) ? $a['control_names'] : [],
                'is_vacation_template' => $curTemplateId !== '' && $curTemplateId === $tplId,
            ];
        }
        // 按"扫描命中数"倒序，常用模板排前面
        usort($templates, function ($x, $y) {
            if ($y['sp_count'] !== $x['sp_count']) return $y['sp_count'] - $x['sp_count'];
            return strcmp($y['sample_apply_time'], $x['sample_apply_time']);
        });
        // 扫描完成后，与 approval_orders 全表聚合结果合并，保证模板库覆盖完整
        $orderTemplates = _vac_debug_fetch_order_templates();
        if (!empty($orderTemplates)) {
            $templates = _vac_debug_merge_templates($templates, $orderTemplates);
        }
        $payload = [
            'cached_at' => time(),
            'sig'       => $task['sig'],
            'range'     => ['start' => $task['start_date'], 'end' => $task['end_date']],
            'templates' => $templates,
            'meta'      => [
                'scanned_sp'  => $processedNow,
                'failed_sp'   => count($task['failed'] ?? []),
                'failed_list' => $task['failed'] ?? [],
            ],
        ];
        _vac_debug_write_result($payload);
        $failedCount = count($task['failed'] ?? []);
        $info = $failedCount > 0
            ? sprintf('扫描完成：成功 %d 条，失败 %d 条（见 failed_list）', $processedNow - $failedCount, $failedCount)
            : sprintf('扫描完成，共 %d 条审批单，识别出 %d 个模板。', $processedNow, count($templates));
        // 任务完成，清 session 中间态（缓存已落到 DB，session 没必要留）
        unset($_SESSION['vac_debug_task']);

        json_out([
            'success'    => true,
            'has_more'   => false,
            'progress'   => $total > 0 ? (int)round($processedNow * 100 / $total) : 100,
            'scanned_sp' => $processedNow,
            'total_sp'   => $total,
            'batch_done' => count($batch),
            'templates'  => $templates,
            'failed'     => $task['failed'] ?? [],
            'info'       => $info,
        ]);
    }

    // 还有剩余 → 返回增量
    $flat = [];
    foreach ($agg as $tplId => $a) {
        $snapNames = [];
        foreach ($a['applyer_snapshots'] as $s) $snapNames[] = trim($s['name']);
        $flat[] = [
            'template_id'          => $tplId,
            'template_name'        => $a['template_name'] ?: '(扫描中…)',
            'sp_count'             => (int)$a['sp_count'],
            'sample_sp_no'         => $a['sample_sp_no'],
            'sample_apply_time'    => $a['sample_apply_time'],
            'applyer_names'        => $snapNames,
            'controls_count'       => (int)$a['controls_count'],
            'is_vacation_template' => $curTemplateId !== '' && $curTemplateId === $tplId,
        ];
    }
    usort($flat, function ($x, $y) {
        if ($y['sp_count'] !== $x['sp_count']) return $y['sp_count'] - $x['sp_count'];
        return strcmp($y['sample_apply_time'], $x['sample_apply_time']);
    });

    json_out([
        'success'    => true,
        'has_more'   => true,
        'progress'   => $total > 0 ? (int)round($processedNow * 100 / $total) : 0,
        'scanned_sp' => $processedNow,
        'total_sp'   => $total,
        'batch_done' => count($batch),
        'templates'  => $flat,
        'failed'     => $task['failed'] ?? [],
    ]);
}

/**
 * GET /api/admin/vacation/debug_scan_latest
 * 页面打开时优先恢复"最近一次扫描结果"（永不过期，保证表格永久显示）。
 * 返回字段：
 *   - exists        ：是否存在上次结果；不存在 = 让前端显示"尚未扫描"空态
 *   - sig / range   ：上次扫描的范围签名 & {start, end}
 *   - cached_at / cached_at_ts：生成时间（格式化 & 原始时间戳，前端用于算"X 天前"）
 *   - is_stale      ：超过 1 小时了吗？超过就给显眼黄色提示建议刷新
 *   - range_matches ：范围签名是否与 ?cur_sig= 一致，不一致就提示"显示的是 XX ~ YY 的旧结果，当前界面输入范围是 AA ~ BB"
 *   - templates     ：已 normalize 的完整模板数组（自动与 approval_orders 全表聚合结果合并）
 *   - scanned_sp    ：审批单数
 *
 * 当缓存缺失或 template_id 不完整时，自动从 approval_orders 聚合补全（但不写回）。
 */
function handle_admin_vacation_debug_scan_latest() {
    require_admin_session([ROLE_ADMIN]);
    $curSig = trim((string)param('cur_sig', ''));
    $row = get_settings_by_keys([VAC_DEBUG_LATEST_KEY], '');
    $raw = $row[VAC_DEBUG_LATEST_KEY] ?? '';
    $templates = [];
    $cachedAt = 0;
    $sig = '';
    $range = ['start' => '', 'end' => ''];
    $scannedCount = 0;
    $hasCache = false;

    if (is_string($raw) && $raw !== '') {
        $data = @json_decode($raw, true);
        if (is_array($data) && is_array($data['templates'] ?? null)) {
            $templates = _vac_debug_normalize_templates($data['templates']);
            $cachedAt = (int)($data['cached_at'] ?? 0);
            $sig = (string)($data['sig'] ?? '');
            $range = is_array($data['range'] ?? null) ? $data['range'] : ['start' => '', 'end' => ''];
            $scannedCount = (int)($data['meta']['scanned_sp'] ?? count($templates));
            $hasCache = true;
        }
    }

    // 始终从 approval_orders 聚合模板信息，与缓存合并（保证覆盖最完整的模板集）
    $orderTemplates = _vac_debug_fetch_order_templates();

    // 如果有缓存则合并；否则直接用 approval_orders 结果
    if ($hasCache && !empty($templates)) {
        $merged = _vac_debug_merge_templates($templates, $orderTemplates);
    } else {
        $merged = $orderTemplates;
    }

    $ageSec = $cachedAt > 0 ? (time() - $cachedAt) : 0;
    $isStale = $cachedAt <= 0 || $ageSec > VAC_DEBUG_CACHE_TTL;

    json_out([
        'success'       => true,
        'exists'        => $hasCache || !empty($orderTemplates),
        'sig'           => $sig,
        'range'         => $range,
        'cached_at'     => $cachedAt > 0 ? date('Y-m-d H:i:s', $cachedAt) : '',
        'cached_at_ts'  => $cachedAt,
        'age_sec'       => max(0, $ageSec),
        'is_stale'      => $isStale,
        'range_matches' => ($curSig !== '') ? ($sig === $curSig) : true,
        'templates'     => $merged,
        'scanned_sp'    => max($scannedCount, count($orderTemplates)),
        'from_orders'   => count($orderTemplates),
        'merged'        => $hasCache && !empty($templates) && !empty($orderTemplates),
    ]);
}

/**
 * GET /api/admin/vacation/debug_set_template?template_id=XXX
 * 一键把该模板写进 settings.vacation_template_id（不去动控件索引，保留当前值）。
 */
function handle_admin_vacation_debug_set_template() {
    require_admin_session([ROLE_ADMIN]);
    $tplId = trim((string)param('template_id', ''));
    if ($tplId === '' || $tplId === '__UNKNOWN__') {
        json_out(['success' => false, 'error' => '模板ID不能为空']);
    }
    // 简单读一下当前控件索引，保证"一键设模板"不会把原配置的索引清掉
    $cur = get_settings_by_keys(['vacation_hire_date_idx', 'vacation_daterange_idx'], '');
    $hireIdx = (string)($cur['vacation_hire_date_idx']   !== '' ? $cur['vacation_hire_date_idx']   : '2');
    $drIdx   = (string)($cur['vacation_daterange_idx']   !== '' ? $cur['vacation_daterange_idx']   : '5');
    set_setting('vacation_template_id',    $tplId);
    set_setting('vacation_hire_date_idx',  $hireIdx);
    set_setting('vacation_daterange_idx',  $drIdx);
    audit_log('vacation_debug_set_template', 'template:' . substr($tplId, 0, 8) . '***', 'ok', []);
    json_out(['success' => true]);
}

/**
 * GET /api/admin/vacation/debug_sp?sp_no=202608210001
 * 返回单审批单详情：
 *   - summary：元信息卡（状态、申请人、部门、耗时…）
 *   - flat_controls：控件扁平化表（idx / control / title / type / preview / is_hire_idx / is_daterange_idx）
 *   - raw_json：原始全量 HTTP 返回（供调试折叠框展示）
 */
function handle_admin_vacation_debug_sp() {
    require_admin_session([ROLE_ADMIN]);
    $spNo = trim((string)param('sp_no', ''));
    if ($spNo === '') {
        json_out(['success' => false, 'error' => '审批编号不能为空']);
    }
    $d = wecom_get_approval_detail($spNo);
    if (empty($d['ok'])) {
        json_out([
            'success' => false,
            'error'   => ($d['errmsg'] ?? '拉取审批详情失败'),
            'errcode' => $d['errcode'] ?? null,
            'raw'     => $d['raw_http_response'] ?? null,
        ]);
    }
    $db = get_db();

    // ===== 申请人 + 部门 解析 =====
    $applyerUserId = (string)($d['apply_userid'] ?? '');
    $applyerName   = trim((string)($d['apply_name'] ?? ''));
    $who = _vac_debug_resolve_user($db, $applyerUserId, $applyerName);

    // ===== 流程耗时 =====
    $applyT  = strtotime((string)($d['apply_time'] ?? ''));
    $finishT = !empty($d['finish_time']) ? strtotime((string)$d['finish_time']) : null;
    $spStatusTextMap = [
        1 => '审批中', 2 => '已通过', 3 => '已驳回', 4 => '已撤销', 6 => '通过后撤销',
    ];
    $status = (int)($d['sp_status'] ?? 0);
    $statusText = $spStatusTextMap[$status] ?? ('状态' . $status);

    $durationText = '';
    if ($applyT && $finishT) {
        $diff = (int)($finishT - $applyT);
        if ($diff < 3600) $durationText = (int)floor($diff / 60) . ' 分钟';
        elseif ($diff < 86400) $durationText = (int)floor($diff / 3600) . ' 小时 ' . (int)floor(($diff % 3600) / 60) . ' 分';
        else $durationText = (int)floor($diff / 86400) . ' 天 ' . (int)floor(($diff % 86400) / 3600) . ' 小时';
    }

    $currentTemplateId = _vac_debug_current_template_id();
    $idxConf = get_settings_by_keys(['vacation_hire_date_idx', 'vacation_daterange_idx'], '');
    $hireIdx = (string)($idxConf['vacation_hire_date_idx']   !== '' ? $idxConf['vacation_hire_date_idx']   : '2');
    $drIdx   = (string)($idxConf['vacation_daterange_idx']   !== '' ? $idxConf['vacation_daterange_idx']   : '5');

    // ===== 控件扁平化 =====
    $flatAll = wecom_approval_flatten_controls($d['apply_data'] ?? []);
    $flatControls = [];
    foreach ($flatAll as $c) {
        $idxStr = (string)($c['idx'] ?? '');
        $flatControls[] = [
            'idx'             => $idxStr,
            'control'         => (string)($c['control'] ?? ''),
            'title'           => (string)($c['title'] ?? ''),
            'type'            => (string)($c['type'] ?? ''),
            'preview'         => isset($c['preview']) ? $c['preview'] : (isset($c['value_raw']) ? $c['value_raw'] : ''),
            'value_raw'       => isset($c['value_raw']) ? $c['value_raw'] : null,
            'is_hire_idx'     => $idxStr === $hireIdx,
            'is_daterange_idx'=> $idxStr === $drIdx,
        ];
    }

    // ===== 审批流程节点摘要 =====
    // 真实审批单结构（与官方文档一致）：
    //   info.sp_record        —— 已处理过的节点快照数组；每个节点下真正的"审批人/处理时间/意见"在 details[] 里；
    //                            一个节点可能含多个并行审批人，所以按 details 扁平化。
    //   info.process_list.node_list —— 全流程节点（含未处理节点 / 抄送 node_type=2 / 审批 node_type=1）；
    //                                   作为"节点名"来源，拼出"第 N 级审批 / 抄送"。
    $nodes = [];
    $raw = (array)($d['raw'] ?? []);
    $statusTextMap = [1 => '审批中', 2 => '已同意', 3 => '已驳回', 4 => '已转办', 6 => '已撤销', 7 => '已加签'];

    // 先算一份"节点名"：用 process_list.node_list 的顺序 + 类型凑出"第1级审批 / 第2级审批 / 抄送"
    $plNodes = (array)(($raw['process_list']['node_list'] ?? []));
    $nodeLabels = [];
    $apvSeq = 1;
    foreach ($plNodes as $plNd) {
        $nt = (int)($plNd['node_type'] ?? 0);
        if ($nt === 1) { // 审批
            $nodeLabels[] = '第' . $apvSeq++ . '级审批';
        } elseif ($nt === 2) { // 抄送
            $nodeLabels[] = '抄送';
        } else {
            $nodeLabels[] = '节点' . count($nodeLabels);
        }
    }

    $spRecords = (array)($raw['sp_record'] ?? []);
    $i = 0;
    foreach ($spRecords as $nd) {
        $nodeLabel = isset($nodeLabels[$i]) ? $nodeLabels[$i] : ('节点' . ($i + 1));
        $details = (array)($nd['details'] ?? []);
        // details 为空：极少数老结构，退回旧 approver/speech/sptime 取法
        if (empty($details)) {
            $approverName = '';
            $apv = (array)($nd['approver'] ?? []);
            if (!empty($apv)) {
                $first = is_array($apv[0] ?? null) ? $apv[0] : [];
                $apvUserId = (string)($first['userid'] ?? '');
                $apvName   = trim((string)($first['name'] ?? ''));
                $w = _vac_debug_resolve_user($db, $apvUserId, $apvName);
                $approverName = $w['name'] !== '' ? $w['name'] : ($apvName !== '' ? $apvName : $apvUserId);
            }
            $st = (int)($nd['sp_status'] ?? 0);
            $nodes[] = [
                'node_name' => (string)($nd['node_name'] ?? (string)($nd['name'] ?? '')) !== ''
                    ? trim((string)($nd['node_name'] ?? $nd['name']))
                    : $nodeLabel,
                'approver'  => $approverName,
                'status'    => $statusTextMap[$st] ?? ('状态' . $st),
                'sptime'    => !empty($nd['sptime']) ? date('Y-m-d H:i:s', (int)$nd['sptime']) : '',
                'speech'    => (string)($nd['speech'] ?? ''),
            ];
        } else {
            foreach ($details as $detail) {
                $apvUserId = (string)($detail['approver']['userid'] ?? '');
                $apvName   = trim((string)($detail['approver']['name'] ?? ''));
                $w = _vac_debug_resolve_user($db, $apvUserId, $apvName);
                $approverName = $w['name'] !== '' ? $w['name'] : ($apvName !== '' ? $apvName : ($apvUserId !== '' ? $apvUserId : '(未填写)'));
                $st = (int)($detail['sp_status'] ?? 0);
                $sptime = !empty($detail['sptime']) ? date('Y-m-d H:i:s', (int)$detail['sptime']) : '';
                $speech = trim((string)($detail['speech'] ?? ''));
                $nodes[] = [
                    'node_name' => (string)($nd['node_name'] ?? (string)($nd['name'] ?? '')) !== ''
                        ? trim((string)($nd['node_name'] ?? $nd['name']))
                        : $nodeLabel,
                    'approver'  => $approverName,
                    'status'    => $statusTextMap[$st] ?? ('状态' . $st),
                    'sptime'    => $sptime,
                    'speech'    => $speech,
                ];
            }
        }
        $i++;
    }
    // 状态兜底：避免前端显示"<em>(未填写)</em>"（当后端 name/userid 都解不出来时，上面已写"(未填写)"纯文本，前端 <em> 是另一个问题——等下查前端）

    // raw_http_response 是数组形式，直接 json 化到响应里（前端会再 JSON.stringify 后塞进 textarea）
    $rawResp = $d['raw_http_response'] ?? null;
    audit_log('vacation_debug_sp', 'sp:' . $spNo, 'ok', [
        'template_id' => !empty($d['template_id']) ? substr($d['template_id'], 0, 8) . '***' : '',
    ]);

    json_out([
        'success' => true,
        'summary' => [
            'sp_no'               => (string)($d['sp_no'] ?? $spNo),
            'template_id'         => (string)($d['template_id'] ?? ''),
            'template_name'       => trim((string)($d['sp_name'] ?? '')),
            'is_vacation_template'=> $currentTemplateId !== '' && $currentTemplateId === (string)($d['template_id'] ?? ''),
            'hire_idx'            => $hireIdx,
            'daterange_idx'       => $drIdx,
            'applyer_name'        => $who['name'] !== '' ? $who['name'] : ($applyerName !== '' ? $applyerName : $applyerUserId),
            'applyer_dept'        => $who['dept_name'],
            'applyer_wecom'       => $applyerUserId,
            'apply_time'          => (string)($d['apply_time'] ?? ''),
            'finish_time'         => (string)($d['finish_time'] ?? ''),
            'sp_status'           => $status,
            'sp_status_text'      => $statusText,
            'duration_text'       => $durationText,
            'nodes_count'         => count($nodes),
        ],
        'nodes'        => $nodes,
        'flat_controls'=> $flatControls,
        'raw_json'     => $rawResp,
    ]);
}

// ===== 审批单基础表（approval_orders）列表 + 详情 =====

/**
 * 审批单列表查询（分页 + LIKE 搜索）
 *
 * GET /api/admin/vacation/approval_orders?page=1&pageSize=20&q=xxx&status=all
 *
 * 纯字母搜索由前端 matchPinyinQuery 本地过滤，此接口只处理中文/数字 LIKE；
 * 纯字母搜索时传 all=1 拉全量（前端分页），非字母搜索传 all=0 后端分页。
 */
function handle_admin_vacation_approval_orders() {
    $me = require_admin_session([ROLE_ADMIN, ROLE_HR]);
    $db = get_db();
    $isMysql = (defined('DB_TYPE') && DB_TYPE === 'mysql');

    $q       = trim((string)param('q', ''));
    $status  = trim((string)param('status', 'all'));
    $page    = max(1, (int)param('page', 1));
    $pageSize = max(1, min(100, (int)param('pageSize', 20)));
    $all     = (string)param('all', '') === '1'; // 纯字母搜索时前端拉全量

    // 状态映射
    $statusMap = [
        'pending'   => 1, 'approved'  => 2, 'rejected'  => 3,
        'revoked'   => 4, 'revoked2'  => 6, 'deleted'   => 7, 'paid' => 10,
    ];

    $where = [];
    $params = [];

    // 状态过滤
    if ($status !== 'all' && isset($statusMap[$status])) {
        $where[] = 'sp_status = ?';
        $params[] = $statusMap[$status];
    }

    // 非字母搜索：后端 LIKE（中文/数字）
    if ($q !== '' && !$all) {
        $like = '%' . $q . '%';
        $where[] = '(sp_no LIKE ? OR sp_name LIKE ? OR name LIKE ? OR userid LIKE ? OR template_id LIKE ?)';
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $whereSql = '';
    if (!empty($where)) {
        $whereSql = ' WHERE ' . implode(' AND ', $where);
    }

    // 纯字母搜索（all=1）：拉全量（最多5000条防止内存溢出）
    if ($all) {
        $sql = "SELECT sp_no, sp_name, sp_status, template_id, apply_time, userid, name FROM approval_orders{$whereSql} ORDER BY (apply_time IS NULL) ASC, apply_time DESC, id DESC LIMIT 5000";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_out(['success' => true, 'data' => $rows, 'total' => count($rows)]);
    }

    // 后端分页
    $countSql = "SELECT COUNT(*) FROM approval_orders{$whereSql}";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $pageSize;
    $listSql = "SELECT sp_no, sp_name, sp_status, template_id, apply_time, userid, name FROM approval_orders{$whereSql} ORDER BY (apply_time IS NULL) ASC, apply_time DESC, id DESC LIMIT ? OFFSET ?";
    $stmt = $db->prepare($listSql);
    foreach ($params as $i => $v) {
        $stmt->bindValue($i + 1, $v);
    }
    $stmt->bindValue(count($params) + 1, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusTexts = [1 => '审批中', 2 => '已通过', 3 => '已驳回', 4 => '已撤销', 6 => '通过后撤销', 7 => '已删除', 10 => '已支付'];
    foreach ($rows as &$r) {
        $r['sp_status_text'] = $statusTexts[(int)$r['sp_status']] ?? ('状态' . $r['sp_status']);
    }

    json_out(['success' => true, 'data' => $rows, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize]);
}

/**
 * 审批单详情（解压 info 字段返回完整 JSON）
 *
 * GET /api/admin/vacation/approval_order_detail?sp_no=xxx
 */
function handle_admin_vacation_approval_order_detail() {
    require_admin_session([ROLE_ADMIN, ROLE_HR]);
    $spNo = trim((string)param('sp_no', ''));
    if ($spNo === '') {
        json_out(['success' => false, 'error' => 'sp_no 不能为空'], 400);
    }

    $row = get_approval_order($spNo);
    if (!$row) {
        json_out(['success' => false, 'error' => '审批单不存在'], 404);
    }

    $statusTexts = [1 => '审批中', 2 => '已通过', 3 => '已驳回', 4 => '已撤销', 6 => '通过后撤销', 7 => '已删除', 10 => '已支付'];
    $row['sp_status_text'] = $statusTexts[(int)$row['sp_status']] ?? ('状态' . $row['sp_status']);

    json_out(['success' => true, 'data' => $row]);
}

/**
 * 从 approval_orders 表聚合模板元信息（轻量版，只取 template_id + name + count，不解析 info）。
 * 用于与扫描结果合并，保证模板库覆盖完整。
 * @return array
 */
function _vac_debug_fetch_order_templates() {
    $db = get_db();
    $curTemplateId = _vac_debug_current_template_id();
    $stmt = $db->query("
        SELECT template_id, sp_name, COUNT(*) AS cnt
        FROM approval_orders
        WHERE template_id IS NOT NULL AND template_id != ''
        GROUP BY template_id, sp_name
        ORDER BY cnt DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $tplId = (string)($r['template_id'] ?? '');
        if ($tplId === '') continue;
        $out[] = [
            'template_id'          => $tplId,
            'template_name'        => trim((string)($r['sp_name'] ?? '')) ?: '(未命名模板)',
            'sp_count'             => (int)$r['cnt'],
            'controls_count'       => 0,
            'control_names'        => [],
            'is_vacation_template' => $curTemplateId !== '' && $curTemplateId === $tplId,
            'source'               => 'approval_orders',
        ];
    }
    return $out;
}

/**
 * GET /api/admin/vacation/debug_tpl_from_orders
 * 从 approval_orders 表聚合模板信息，支持合并到现有模板库。
 * 与 scan_next 流程互补：scan_next 按指定时间范围扫描并调企微 API 反推控件信息；
 * 本接口直接聚合 approval_orders 全量表，覆盖范围更广，并从 info 字段解析出模板控件。
 *
 * 可选参数：
 *   - merge: 1=与现有缓存合并并写回，0=直接返回不落盘
 * 返回：templates 数组 + 现有缓存中的模板数 + 聚合到的原始行数
 */
function handle_admin_vacation_debug_tpl_from_orders() {
    require_admin_session([ROLE_ADMIN]);
    $db = get_db();
    $curTemplateId = _vac_debug_current_template_id();

    // 1) 先聚合每个 template_id 的统计数据
    $stmt = $db->query("
        SELECT template_id, sp_name, COUNT(*) AS cnt
        FROM approval_orders
        WHERE template_id IS NOT NULL AND template_id != ''
        GROUP BY template_id, sp_name
        ORDER BY cnt DESC
    ");
    $summaryRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2) 对每个 template_id，取 apply_time 最新的一条记录的 info 字段解析控件
    $isMysql = (defined('DB_TYPE') && DB_TYPE === 'mysql');
    $templates = [];
    foreach ($summaryRows as $sumRow) {
        $tplId = (string)($sumRow['template_id'] ?? '');
        if ($tplId === '') continue;

        $tplName = trim((string)($sumRow['sp_name'] ?? ''));
        $cnt = (int)($sumRow['cnt'] ?? 0);

        // 取最新一条记录的 info 字段
        $infoRaw = '';
        $applyTime = '';
        if ($isMysql) {
            $detailStmt = $db->prepare("
                SELECT info, apply_time FROM approval_orders
                WHERE template_id = ?
                ORDER BY COALESCE(apply_time, '0000-00-00 00:00:00') DESC, id DESC
                LIMIT 1
            ");
        } else {
            $detailStmt = $db->prepare("
                SELECT info, apply_time FROM approval_orders
                WHERE template_id = ?
                ORDER BY COALESCE(apply_time, '1970-01-01 00:00:00') DESC, id DESC
                LIMIT 1
            ");
        }
        $detailStmt->execute([$tplId]);
        $detailRow = $detailStmt->fetch(PDO::FETCH_ASSOC);
        if ($detailRow) {
            $infoRaw = (string)($detailRow['info'] ?? '');
            $applyTime = (string)($detailRow['apply_time'] ?? '');
        }

        // 从 info 字段解析控件信息
        $controlsCount = 0;
        $controlNames = [];
        if ($infoRaw !== '') {
            $decoded = base64_decode($infoRaw, true);
            if ($decoded !== false) {
                $decompressed = @gzuncompress($decoded);
                if ($decompressed !== false) {
                    $infoArr = @json_decode($decompressed, true);
                    if (is_array($infoArr)) {
                        $applyData = is_array($infoArr['apply_data'] ?? null) ? $infoArr['apply_data'] : [];
                        $controlNames = _vac_debug_extract_control_names($applyData);
                        $controlsCount = count($controlNames);
                    }
                }
            }
        }

        if ($tplName === '') {
            $tplName = '(未命名模板)';
        }

        $templates[] = [
            'template_id'          => $tplId,
            'template_name'        => $tplName,
            'sp_count'             => $cnt,
            'sample_sp_no'         => '',
            'sample_apply_time'    => $applyTime,
            'controls_count'       => $controlsCount,
            'control_names'        => $controlNames,
            'is_vacation_template' => $curTemplateId !== '' && $curTemplateId === $tplId,
            'source'               => 'approval_orders',
        ];
    }

    // 3) 可选：与现有缓存合并并写回
    $existingCount = 0;
    $merge = !empty($_GET['merge']);
    if ($merge) {
        $row = get_settings_by_keys([VAC_DEBUG_LATEST_KEY], '');
        $raw = $row[VAC_DEBUG_LATEST_KEY] ?? '';
        if (is_string($raw) && $raw !== '') {
            $data = @json_decode($raw, true);
            if (is_array($data) && is_array($data['templates'] ?? null)) {
                $existingTemplates = $data['templates'];
                $existingCount = count($existingTemplates);
                $templates = _vac_debug_merge_templates($existingTemplates, $templates);
            }
        }

        $payload = [
            'cached_at' => time(),
            'sig'       => 'merged_from_orders',
            'range'     => ['start' => '', 'end' => ''],
            'templates' => $templates,
            'meta'      => [
                'scanned_sp'  => 0,
                'failed_sp'   => 0,
                'failed_list' => [],
                'merged_from_orders' => true,
            ],
        ];
        _vac_debug_write_result($payload);
    }

    json_out([
        'success'        => true,
        'templates'      => $templates,
        'from_orders'    => count($summaryRows),
        'existing_count' => $existingCount,
        'merged'         => $merge,
    ]);
}

/**
 * 合并两个模板数组（按 template_id 去重，取更完整的信息）
 * 合并策略：对同一 template_id，取 control_names/controls_count/template_name/sp_count 中更完整的一方。
 * 若一方有控件信息而另一方没有，优先保留有控件的；其余字段取非空值。
 */
function _vac_debug_merge_templates($existing, $new) {
    $byId = [];

    // 先把现有数据按 template_id 索引
    foreach ($existing as $tpl) {
        $id = (string)($tpl['template_id'] ?? '');
        if ($id !== '' && $id !== '__UNKNOWN__') {
            $byId[$id] = $tpl;
        } else {
            // 无 template_id 的直接进结果（无法去重）
            $byId['__anon_' . count($byId)] = $tpl;
        }
    }

    // 用新数据合并覆盖（新数据更可信，但缺控件信息时保留旧数据）
    foreach ($new as $tpl) {
        $id = (string)($tpl['template_id'] ?? '');
        if ($id === '' || $id === '__UNKNOWN__') {
            $byId['__anon_' . count($byId)] = $tpl;
            continue;
        }

        if (isset($byId[$id])) {
            $old = $byId[$id];
            // 模板名：优先非空、非"未命名"
            if (empty($tpl['template_name']) || $tpl['template_name'] === '(未命名模板)') {
                if (!empty($old['template_name']) && $old['template_name'] !== '(未命名模板)') {
                    $tpl['template_name'] = $old['template_name'];
                }
            }
            // 控件信息：有则用，没有则回退到旧数据
            if (empty($tpl['control_names']) && !empty($old['control_names'])) {
                $tpl['control_names'] = $old['control_names'];
                $tpl['controls_count'] = (int)($old['controls_count'] ?? count($old['control_names']));
            }
            // sp_count：累加（两个来源的计数都有意义）
            $oldCnt = (int)($old['sp_count'] ?? 0);
            $newCnt = (int)($tpl['sp_count'] ?? 0);
            if ($oldCnt > $newCnt) {
                $tpl['sp_count'] = $oldCnt;
            }
            // is_vacation_template：保留 true
            if (!empty($old['is_vacation_template'])) {
                $tpl['is_vacation_template'] = true;
            }
            // 来源：合并后标记为"merged"
            $tpl['source'] = 'merged';
        }
        $byId[$id] = $tpl;
    }

    // 添加只存在于旧数据中、未被新数据覆盖的模板
    foreach ($existing as $old) {
        $id = (string)($old['template_id'] ?? '');
        if ($id === '' || $id === '__UNKNOWN__') continue;
        if (!isset($byId[$id])) {
            $old['source'] = $old['source'] ?? 'cache_only';
            $byId[$id] = $old;
        }
    }

    // 结果去重 + 按 sp_count 倒序
    $result = [];
    $seen = [];
    foreach ($byId as $tpl) {
        $id = (string)($tpl['template_id'] ?? '');
        if ($id === '' || isset($seen[$id])) continue;
        $seen[$id] = true;
        $result[] = $tpl;
    }

    usort($result, function ($x, $y) {
        return (int)($y['sp_count'] ?? 0) - (int)($x['sp_count'] ?? 0);
    });

    return $result;
}
