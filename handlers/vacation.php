<?php
/**
 * 员工端年假查询接口
 *
 *   GET /api/vacation/employee?year=    获取当前登录员工的年假余额 + 流水明细 + 审批快照查看
 *
 * 关于鉴权 & 查看密码过期：
 *   require_login() 处理 JWT 过期 / 会话失效 / 401 / 403；
 *   require_password() 处理"查看密码有效期（pw_ok cookie）"：
 *     - 没设密码 → 返回 success:true + need_setup:true（前端跳 /employee/password）
 *     - 密码过期 → 返回 success:true + need_password:true（前端就地弹密码框验证，成功 reloadData）
 *   两种都不是"真失败"，前端不能把 success:true 直接走 renderData 显示"无数据"。
 */
function handle_vacation_employee() {
    $u = require_login();
    require_password($u);
    $db = get_db();
    $year = (int)param('year', (int)date('Y'));

    $balance = vacation_calc_balance($db, $u['userid'], $year);

    // ----- 1) 流水明细：source=1/4 审批通过/撤销 LEFT JOIN 审批表用于展示详情 -----
    $ledger = [];
    try {
        $stmt = $db->prepare(
            "SELECT l.id, l.source, l.delta, l.ref_no, l.op_by, l.remark, l.created_at,
                    a.sp_no AS sp_no, a.leave_start AS start_date, a.leave_end AS end_date,
                    a.leave_days AS leave_days, a.apply_time AS apply_time
             FROM vacation_ledger l
             LEFT JOIN vacation_approvals a ON a.sp_no = l.ref_no
             WHERE l.userid = ? AND l.year = ?
             ORDER BY l.created_at DESC, l.id DESC"
        );
        $stmt->execute([$u['userid'], $year]);
        $ledger = $stmt->fetchAll();
        // 老数据兼容：leave_start/leave_end 仅 Y-m-d（10 位 ASCII）时补默认上班/下班时分
        foreach ($ledger as &$row) {
            if (!empty($row['start_date']) && strlen($row['start_date']) === 10) {
                $row['start_date'] = $row['start_date'] . ' 08:00';
            }
            if (!empty($row['end_date']) && strlen($row['end_date']) === 10) {
                $row['end_date'] = $row['end_date'] . ' 17:00';
            }
            if (empty($row['sp_no']) && !empty($row['ref_no'])) {
                $row['sp_no'] = $row['ref_no'];
            }
            // 年假流水里"审批通过 / 审批撤销"下面显示的时间，展示为审批提交时间
            //（流水本身 created_at 是入账时间，和员工发起申请的时间不是同一个概念，
            // 员工理解的"什么时候申请通过/撤销"应以 apply_time 为准，入账/同步时间无意义）
            // 老数据如果 vacation_approvals 行还没同步 / apply_time 为空，
            // 回退用 created_at 显示，避免出现空。
            $source = (int)($row['source'] ?? 0);
            if (($source === 1 || $source === 4) && !empty($row['apply_time'])) {
                $row['show_time'] = $row['apply_time'];
            } else {
                $row['show_time'] = $row['created_at'];
            }
        }
        unset($row);
    } catch (Throwable $e) {
        error_log("handle_vacation_employee ledger failed: " . $e->getMessage());
    }

    // ----- 2) 审批快照查看（只展示，不计入余额）-----
    // 不做状态白名单：审批中(1) / 已通过(2) / 已驳回(3) / 已撤回(4) / 通过后撤销(6) 都可见。
    // 按 apply_userid + year 过滤；排序优先 finish_time（有最终态），否则用 apply_time（审批中）。
    $approvals = [];
    try {
        $apStmt = $db->prepare(
            "SELECT sp_no, sp_name, sp_status, apply_time, finish_time,
                    leave_start AS start_date, leave_end AS end_date, leave_days
             FROM   vacation_approvals
             WHERE  apply_userid = ?
               AND  year = ?
             ORDER  BY COALESCE(finish_time, apply_time) DESC, id DESC"
        );
        $apStmt->execute([$u['userid'], $year]);
        $approvals = $apStmt->fetchAll() ?: [];
        foreach ($approvals as &$ap) {
            if (!empty($ap['start_date']) && strlen($ap['start_date']) === 10) $ap['start_date'] .= ' 08:00';
            if (!empty($ap['end_date'])   && strlen($ap['end_date'])   === 10) $ap['end_date']   .= ' 17:00';
        }
        unset($ap);
    } catch (Throwable $e) {
        error_log("handle_vacation_employee approvals failed: " . $e->getMessage());
    }

    json_out([
        'success'   => true,
        'year'      => $year,
        'emp'       => [
            'userid'    => $u['userid'],
            'name'      => $u['name'],
            'hire_date' => $u['hire_date'] ?? '',
        ],
        'balance'   => $balance,
        'ledger'    => $ledger,
        'approvals' => $approvals,
    ]);
}

/**
 * 员工端：查询单个年假审批单的完整详情（含审批流程节点表）。
 * 路由：GET /api.php?path=vacation/sp_detail&sp_no=xxx
 *
 * 安全关键：只允许查询当前登录员工自己名下的审批单（apply_userid = 登录 userid），
 *           禁止通过改 sp_no 偷窥同事的审批意见/流程。
 *
 * 返回结构（扁平化，前端不用解析嵌套数组）：
 *   submitter:   提交人中文名（优先 vacation_approvals.apply_name，兜底 DB 反查）
 *   submit_time: 提交时间 Y-m-d H:i:s
 *   sp_no / start_date / end_date / leave_days: 复用 vacation_approvals 用于弹窗渲染
 *   nodes_available: bool - 是否能解析出审批流程节点（老单 raw_json 为空 / 超期无法解析时 false）
 *   flow_nodes:    [{idx,user,status,status_color,time,speech}]，按 抄送→第1级→第2级…顺序
 *
 * @return void
 */
function handle_vacation_sp_detail() {
    $u = require_login();
    require_password($u);
    $db = get_db();

    $spNo = trim((string)param('sp_no', ''));
    if ($spNo === '') { json_out(['success'=>false,'error'=>'缺少 sp_no'], 400); }

    // ---------- 归属强校验：apply_userid 必须是当前登录人，防越权 ----------
    $stmt = $db->prepare(
        "SELECT sp_no, apply_userid, apply_name, sp_status, apply_time, finish_time,
                leave_start AS start_date, leave_end AS end_date, leave_days, raw_json
         FROM   vacation_approvals
         WHERE  sp_no = ? AND apply_userid = ?
         LIMIT  1"
    );
    $stmt->execute([$spNo, $u['userid']]);
    $row = $stmt->fetch();
    if (!$row) {
        // 有两种情况：① sp_no 真不存在 / ② sp_no 存在但不是该员工的 → 对外统一"无权限或不存在"，避免枚举撞库
        json_out(['success'=>false,'error'=>'审批单不存在或无权查看'], 403);
    }

    // ---------- 老数据兼容：start_date / end_date 只有 10 位日期时补默认时分 ----------
    if (!empty($row['start_date']) && strlen($row['start_date']) === 10) $row['start_date'] .= ' 08:00';
    if (!empty($row['end_date'])   && strlen($row['end_date'])   === 10) $row['end_date']   .= ' 17:00';

    // ---------- 提交人 / 提交时间：优先 vacation_approvals 已有字段（没有 raw_json 也能显示）----------
    $submitter = !empty($row['apply_name']) ? $row['apply_name'] : ($u['name'] ?? '');
    $submitTime = !empty($row['apply_time']) ? $row['apply_time'] : '';

    // ---------- 状态 / 节点映射 helper ----------
    $spStatusText = function (int $s): string {
        static $map = [
            1 => '审批中',
            2 => '已同意',   // 企微枚举 2=已通过，对应用户示例截图显示"已同意"
            3 => '已驳回',
            4 => '已撤销',
            6 => '已撤销',   // 6=通过后撤销（用户界面统一展示"已撤销"即可，保持简洁）
        ];
        return $map[$s] ?? '未知';
    };
    $spStatusColor = function (int $s): string {
        static $map = [
            1 => '#6b7280',  // 灰：审批中
            2 => '#07c160',  // 绿：已同意
            3 => '#e53935',  // 红：已驳回
            4 => '#9ca3af',  // 浅灰：已撤销
            6 => '#9ca3af',
        ];
        return $map[$s] ?? '#6b7280';
    };

    // ---------- 解析 raw_json 生成流程节点 ----------
    $nodes = [];
    $nodesAvailable = false;
    if (!empty($row['raw_json'])) {
        $raw = json_decode($row['raw_json'], true);
        if (is_array($raw)) {
            $nodesAvailable = true;

            // ---- 1) 抄送：来自 raw.notifyer[]（放在最前面，对应用户示例截图顺序）----
            $notifyers = (array)($raw['notifyer'] ?? []);
            foreach ($notifyers as $n) {
                $uid = is_array($n) ? (string)($n['userid'] ?? '') : (string)$n;
                if ($uid === '') continue;
                $name = _find_display_name_by_wecom($uid) ?? $uid;
                // 用户示例截图抄送也显示"已同意"状态色（绿色）；抄送无时间/意见，填 -
                $nodes[] = [
                    'idx'          => '抄送',
                    'user'         => $name,
                    'status'       => '已同意',
                    'status_color' => '#07c160',
                    'time'         => '-',
                    'speech'       => '-',
                ];
            }

            // ---- 2) 审批节点：来自 raw.sp_record[]，按数组顺序 1..N 编号为"第 N 级审批"----
            $spRecords = (array)($raw['sp_record'] ?? []);
            $levelIdx = 0;
            foreach ($spRecords as $rec) {
                $levelIdx++;
                $details = (array)($rec['details'] ?? []);
                // sp_record 是"每一级节点一个对象"；每个节点下 details[] 存放该级的 1~N 个审批人（会签场景）
                foreach ($details as $det) {
                    $uid = (string)(($det['approver'] ?? [])['userid'] ?? '');
                    $name = $uid !== '' ? (_find_display_name_by_wecom($uid) ?? $uid) : '-';
                    $st = (int)($det['sp_status'] ?? 0);
                    $spTime = !empty($det['sptime']) ? date('Y-m-d H:i:s', (int)$det['sptime']) : '-';
                    $speech = trim((string)($det['speech'] ?? ''));
                    $nodes[] = [
                        'idx'          => '第'.$levelIdx.'级审批',
                        'user'         => $name,
                        'status'       => $spStatusText($st),
                        'status_color' => $spStatusColor($st),
                        'time'         => $spTime,
                        'speech'       => $speech === '' ? '-' : $speech,
                    ];
                }
            }
        }
    }

    json_out([
        'success'         => true,
        'sp_no'           => $row['sp_no'],
        'sp_status'       => (int)$row['sp_status'],
        'start_date'      => $row['start_date'] ?? '',
        'end_date'        => $row['end_date'] ?? '',
        'leave_days'      => (float)$row['leave_days'],
        'submitter'       => $submitter,
        'submit_time'     => $submitTime,
        'nodes_available' => $nodesAvailable,
        'flow_nodes'      => $nodes,
    ]);
}
