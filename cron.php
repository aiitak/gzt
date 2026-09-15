<?php
/**
 * 定时任务入口（由 1Panel「计划任务」调用）
 *   催办：  cron.php?task=remind   （建议每小时执行）
 *   定时下发：cron.php?task=push    （建议每分钟/每小时执行）
 * 也支持命令行：php cron.php remind
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/wecom.php';
require_once __DIR__ . '/excel.php';
require_once __DIR__ . '/handlers/admin.php';

// 显式设置业务时区，避免服务器 date.timezone 配置为 UTC 导致工作时间/计划推送时间错位
date_default_timezone_set('Asia/Shanghai');

// 入口派发逻辑仅在被直接访问/CLI 执行时运行；
// 被 require_once 引入（如 handlers/admin.php 调试接口）时跳过，避免重复鉴权与 exit
$_cron_is_included = (count(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)) > 0);

if (!$_cron_is_included) {
    if (!is_installed()) {
        echo "system not installed\n";
        exit;
    }

    // 定时任务鉴权：CRON_KEY 必须配置，否则拒绝执行（防止未授权调用）
    if (CRON_KEY === '') {
        http_response_code(403);
        echo "forbidden: CRON_KEY not configured. Set CRON_KEY in config.local.php\n";
        exit;
    } else {
        $provided = $_GET['key'] ?? '';
        if ($provided === '' && isset($argv[2])) {
            $provided = $argv[2];
        }
        if ($provided === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $provided = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
        }
        if (!is_string($provided) || !hash_equals(CRON_KEY, $provided)) {
            http_response_code(403);
            echo "forbidden: invalid cron key\n";
            exit;
        }
    }

    $task = $_GET['task'] ?? ($argv[1] ?? '');
    if ($task === 'remind') {
        run_remind_with_legacy();
    } elseif ($task === 'push') {
        run_scheduled_push_with_legacy();
    } elseif ($task === 'all') {
        run_timer_all();
    } else {
        echo "usage: cron.php?task=remind|push|all\n";
    }
}

/**
 * 判断当前是否处于催办工作时间
 * 后台 settings 表 remind_worktime 字段可配置，JSON 格式：
 *   { "enabled": true, "days": [1,2,3,4,5], "start_hour": 8, "end_hour": 17 }
 * - enabled=false 时任意时间都触发（方便测试）
 * - days: 0=周日,1=周一...6=周六
 * 无配置时回退到默认：周一至周五 8-17 点
 */
function is_workday_hours() {
    $cfg = json_decode(get_setting('remind_worktime', ''), true) ?: [];
    // 无配置时回退到默认：周一至周五 8-17 点
    if (empty($cfg) || !isset($cfg['enabled'])) {
        $h = (int)date('H');
        $w = (int)date('w');
        if ($w === 0 || $w === 6) {
            return false;
        }
        return $h >= 8 && $h < 17;
    }
    // 关闭限制：任何时间都触发（方便测试）
    if (empty($cfg['enabled'])) {
        return true;
    }
    $days = isset($cfg['days']) && is_array($cfg['days']) ? $cfg['days'] : [1,2,3,4,5];
    $startHour = isset($cfg['start_hour']) ? (int)$cfg['start_hour'] : 8;
    $endHour = isset($cfg['end_hour']) ? (int)$cfg['end_hour'] : 17;
    $w = (int)date('w');
    $h = (int)date('H');
    if (!in_array($w, $days, true)) {
        return false;
    }
    return $h >= $startHour && $h < $endHour;
}

function run_remind() {
    if (!is_workday_hours()) {
        echo "非工作时间，跳过催办\n";
        return;
    }
    $salaryRes = run_remind_salary(false, false);
    echo "remind sent: {$salaryRes['sent']}\n";
    $bonusRes = run_remind_bonus(false, false);
    echo "bonus remind sent: {$bonusRes['sent']}\n";
}

/**
 * 催办通用逻辑（salary / bonus）
 * @param PDO      $db            DB 连接
 * @param string   $table         表名：'salary' 或 'bonus'
 * @param string   $tplKey        模板 key：'salary_remind' 或 'bonus_remind'
 * @param int      $statusVal     状态常量 SALARY_SENT 或 BONUS_SENT
 * @param callable $urlBuilder    回调：接受一条记录返回详情 URL
 * @param callable $varsBuilder   回调：接受 ($row, $name, $dept, $link) 返回模板变量数组
 * @param callable $titleFallback 回调：接受一条记录返回默认 fallback 标题
 * @param callable $okLogBuilder  回调：接受 ($row, $name) 返回成功日志描述
 * @param bool     $force         是否绕过 1 小时去重与工作时间限制
 * @param bool     $logDetail     是否记录详细处理结果
 * @return array {sent:int, total:int, skip:int, error:?string, details:array}
 */
function _run_remind_generic($db, $table, $tplKey, $statusVal, $urlBuilder, $varsBuilder, $titleFallback, $okLogBuilder, $force = false, $logDetail = false) {
    $now = time();
    $result = ['sent' => 0, 'total' => 0, 'skip' => 0, 'error' => null, 'details' => []];
    try {
        if (!$force && !is_workday_hours()) {
            if ($logDetail) {
                $result['details'][] = ['status' => 'skip_worktime', 'msg' => '非工作时间跳过'];
            }
            return $result;
        }

        if ($table === 'salary') {
            $selectFields = "s.id, s.userid, s.year, s.month, s.last_remind_at,
                    u.wecom_userid, u.name AS uname, d.name AS dept_name";
            $from    = "salary s";
            $orderBy = "s.year ASC, s.month ASC, s.id ASC";
            $prefix  = "s";
        } else {
            $selectFields = "b.id, b.userid, b.year, b.bonus_type, b.last_remind_at,
                    u.wecom_userid, u.name AS uname, d.name AS dept_name";
            $from    = "bonus b";
            $orderBy = "b.year ASC, b.id ASC";
            $prefix  = "b";
        }
        // MySQL 下处理不同表 userid 字段可能存在的 collation 冲突
        $collateJoin = (defined('DB_TYPE') && DB_TYPE === 'mysql') ? ' COLLATE utf8mb4_general_ci' : '';
        // status 口径：sent + confirmed 两种状态只要 confirmed=0 都视为"待确认"
        // （与后台数据概览、stats 报表的 total_unconfirmed 一致，
        //   避免存在 status='confirmed' 但 confirmed=0 的脏数据时催办漏催）
        // 并排除 HIST_ 历史存档用户，保持跟概览同一批员工口径
        $stmt = $db->prepare(
            "SELECT {$selectFields}
             FROM {$from} LEFT JOIN users u ON {$prefix}.userid{$collateJoin} = u.userid{$collateJoin}
                            LEFT JOIN departments d ON u.dept_id = d.dept_id
             WHERE {$prefix}.status IN (?, ?) AND {$prefix}.confirmed = 0 AND {$prefix}.deleted_at IS NULL
               AND {$prefix}.userid NOT LIKE 'HIST_%'
             ORDER BY {$orderBy}"
        );
        $stmt->execute([$statusVal, 'confirmed']);
        $rows = $stmt->fetchAll();

        $sentKeys = [];
        foreach ($rows as $r) {
            $result['total']++;

            if (!$force && $r['last_remind_at'] && strtotime($r['last_remind_at']) > $now - 3600) {
                $result['skip']++;
                if ($logDetail) {
                    $result['details'][] = ['userid' => $r['userid'], 'name' => $r['uname'], 'status' => 'skip_last_remind', 'msg' => '1小时内已催过'];
                }
                continue;
            }

            $notifyUid = !empty($r['wecom_userid']) ? $r['wecom_userid'] : $r['userid'];
            if ($table === 'salary') {
                $groupKey = sprintf('%04d%02d', (int)$r['year'], (int)$r['month']);
            } else {
                $groupKey = (string)$r['year'] . ':' . $r['bonus_type'];
            }
            $dedupKey = $notifyUid . '|' . $groupKey;
            if (isset($sentKeys[$dedupKey])) {
                $result['skip']++;
                if ($logDetail) {
                    $result['details'][] = ['userid' => $r['userid'], 'name' => $r['uname'], 'status' => 'skip_dedup', 'msg' => '同一用户同组已催过，跳过'];
                }
                continue;
            }

            $name = !empty($r['uname']) ? $r['uname'] : $r['userid'];
            $dept = normalize_dept_name(!empty($r['dept_name']) ? $r['dept_name'] : '');
            $link = call_user_func($urlBuilder, $r);
            $vars = call_user_func($varsBuilder, $r, $name, $dept, $link);

            $tpl     = get_builtin_template($tplKey);
            $type    = $tpl['msgtype'];
            $content = salary_render_template($tpl['content'], $vars);
            $title   = salary_render_template($tpl['title'], $vars);
            $defaultTitle = call_user_func($titleFallback, $r);

            $ok     = false;
            $errMsg = '';
            if ($type === 'template_card') {
                $r2 = wecom_send_template_card($notifyUid, $title ?: $defaultTitle, $content, $link);
                if (!$r2['ok']) {
                    $ok = wecom_send_markdown($notifyUid, $content);
                    if (!$ok) $errMsg = 'template_card 失败且 markdown 降级也失败';
                } else {
                    $ok = true;
                }
            } elseif ($type === 'markdown') {
                $ok = wecom_send_markdown($notifyUid, $content);
                if (!$ok) $errMsg = 'markdown 发送失败';
            } else {
                $ok = wecom_send_text($notifyUid, $content);
                if (!$ok) $errMsg = 'text 发送失败';
            }

            if ($ok) {
                $db->prepare("UPDATE {$table} SET last_remind_at = ? WHERE id = ?")
                   ->execute([date('Y-m-d H:i:s'), $r['id']]);
                $sentKeys[$dedupKey] = true;
                $result['sent']++;
                if ($logDetail) {
                    $result['details'][] = ['userid' => $r['userid'], 'name' => $name, 'status' => 'ok', 'msg' => call_user_func($okLogBuilder, $r, $name)];
                }
            } else {
                if ($logDetail) {
                    $result['details'][] = ['userid' => $r['userid'], 'name' => $name, 'status' => 'fail', 'msg' => $errMsg ?: '发送失败'];
                }
            }
        }
    } catch (Throwable $e) {
        error_log("[{$table}] remind error: " . $e->getMessage());
        $result['error'] = $e->getMessage();
    }
    return $result;
}

/**
 * 工资催办核心逻辑
 * @param bool $force       true=绕过 1 小时去重限制与工作时间限制
 * @param bool $logDetail   true=返回每条记录的详细处理结果（调试用）
 * @return array {sent:int, total:int, skip:int, error:?string, details:array}
 */
function run_remind_salary($force = false, $logDetail = false) {
    $db = get_db();
    return _run_remind_generic(
        $db,
        'salary',
        'salary_remind',
        SALARY_SENT,
        function ($r) {
            $year  = (int)$r['year'];
            $month = (int)$r['month'];
            $ym    = sprintf('%04d%02d', $year, $month);
            return salary_detail_url($ym);
        },
        function ($r, $name, $dept, $link) {
            $year  = (int)$r['year'];
            $month = (int)$r['month'];
            $ym    = sprintf('%04d%02d', $year, $month);
            return [
                '姓名' => $name,
                '部门' => $dept,
                '年'   => (string)$year,
                '月'   => sprintf('%02d', $month),
                '年月' => $ym,
                '链接' => $link,
            ];
        },
        function ($r) {
            $year  = (int)$r['year'];
            $month = (int)$r['month'];
            $ym    = sprintf('%04d%02d', $year, $month);
            return "{$ym}工资条待确认";
        },
        function ($r, $name) {
            $year  = (int)$r['year'];
            $month = (int)$r['month'];
            $ym    = sprintf('%04d%02d', $year, $month);
            return "{$ym} 已发送";
        },
        $force,
        $logDetail
    );
}

/**
 * 奖金催办核心逻辑
 * @param bool $force       true=绕过 1 小时去重限制与工作时间限制
 * @param bool $logDetail   true=返回每条记录的详细处理结果（调试用）
 * @return array {sent:int, total:int, skip:int, error:?string, details:array}
 */
function run_remind_bonus($force = false, $logDetail = false) {
    $db = get_db();
    return _run_remind_generic(
        $db,
        'bonus',
        'bonus_remind',
        BONUS_SENT,
        function ($r) {
            return bonus_detail_url((int)$r['id']);
        },
        function ($r, $name, $dept, $link) {
            $year      = (int)$r['year'];
            $bonusType = $r['bonus_type'];
            return [
                '姓名'     => $name,
                '部门'     => $dept,
                '年'       => (string)$year,
                '奖金类型' => $bonusType,
                '类型'     => $bonusType,
                '链接'     => $link,
            ];
        },
        function ($r) {
            $bonusType = $r['bonus_type'];
            return "{$bonusType}待确认";
        },
        function ($r, $name) {
            $year      = (int)$r['year'];
            $bonusType = $r['bonus_type'];
            return "{$year}年{$bonusType} 已发送";
        },
        $force,
        $logDetail
    );
}

function run_scheduled_push() {
    $res = run_scheduled_push_inner(false, false);
    echo "scheduled push done\n";
}

/**
 * 定时下发核心逻辑
 * @param bool $force       true=绕过日期限制与 last_auto_push 去重，直接推送 draft 状态的最新一期
 * @param bool $logDetail   true=返回详细处理结果（调试用）
 * @return array {sent:int, total:int, skipped:bool, ym:?string, error:?string, notify_ok:bool, details:array}
 */
function run_scheduled_push_inner($force = false, $logDetail = false) {
    $db = get_db();
    $result = ['sent' => 0, 'total' => 0, 'skipped' => false, 'ym' => null, 'error' => null, 'notify_ok' => false, 'details' => []];

    // 每月自动推送规则（系统设置 → 定时推送）
    $sch = json_decode(get_setting('schedule', ''), true) ?: [];
    // force 模式下即使没启用定时推送，也执行一次（调试用）
    if (empty($sch['enabled']) && !$force) {
        $result['skipped'] = true;
        if ($logDetail) $result['details'][] = ['status' => 'skip', 'msg' => '定时推送未启用，跳过'];
        return $result;
    }

    $shouldRun = false;
    $nowTs = time();
    if ($force) {
        // force 模式：忽略日期，直接运行（但仍受 draft 数据存在与否约束）
        $shouldRun = true;
        if ($logDetail) $result['details'][] = ['status' => 'info', 'msg' => 'force 模式：已跳过日期限制和 last_auto_push 去重'];
    } else {
        // 钳制到当月实际天数，避免 day=31 在 2/4/6/9/11 月永不触发
        $maxDay = (int)date('t');
        $planDay = min((int)$sch['day'], $maxDay);
        $scheduledTs = mktime((int)$sch['hour'], (int)$sch['minute'], 0, (int)date('n'), $planDay);
        if ((int)date('j') >= $planDay && $nowTs >= $scheduledTs) {
            $shouldRun = true;
        } else {
            if ($logDetail) {
                $result['details'][] = ['status' => 'skip', 'msg' => '未到计划日期（每月第 ' . $planDay . ' 天 ' . sprintf('%02d:%02d', (int)$sch['hour'], (int)$sch['minute']) . ' 之后）'];
            }
        }
    }
    if (!$shouldRun) {
        $result['skipped'] = true;
        return $result;
    }

    $last = get_setting('last_auto_push', '');
    $s = $db->prepare("SELECT year, month FROM salary WHERE status='draft' AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL ORDER BY year DESC, month DESC LIMIT 1");
    $s->execute();
    $row = $s->fetch();
    if (!$row) {
        if ($logDetail) $result['details'][] = ['status' => 'skip', 'msg' => '没有可推送的 draft 工资条（不存在 draft 数据）'];
        $result['skipped'] = true;
        return $result;
    }

    $year = (int)$row['year'];
    $month = (int)$row['month'];
    $ym = sprintf('%04d%02d', $year, $month);
    $result['ym'] = $ym;
    if (!$force && $last === $ym) {
        if ($logDetail) $result['details'][] = ['status' => 'skip_dedup', 'msg' => $ym . ' 当月已推送过（last_auto_push=' . $ym . '），跳过'];
        $result['skipped'] = true;
        return $result;
    }

    try {
        $ret = do_push($year, $month, 'all');
        $sent = $ret['sent'];
        $total = $ret['total'];
        $result['sent'] = (int)$sent;
        $result['total'] = (int)$total;
        if ($logDetail) $result['details'][] = ['status' => 'ok', 'msg' => '已执行 ' . $year . '年' . sprintf('%02d', $month) . '月 工资推送：成功 ' . $sent . ' / ' . $total . ' 人'];

        $rTpl = render_builtin_template('push_result', [
            '年'     => (string)$year,
            '月'     => sprintf('%02d', $month),
            '成功数' => (string)$sent,
            '总数'   => (string)$total,
        ]);
        // 即使 force 模式也发送推送结果通知（方便测试通知链路）
        try {
            notify_push_result($rTpl['content'], $rTpl);
            $result['notify_ok'] = true;
        } catch (Throwable $e) {
            if ($logDetail) $result['details'][] = ['status' => 'warn', 'msg' => '下发结果通知失败：' . $e->getMessage()];
        }
        // force 模式也更新 last_auto_push（保持行为一致，避免重复推送）
        set_setting('last_auto_push', $ym);
    } catch (Throwable $e) {
        error_log('[salary] scheduled push error: ' . $e->getMessage());
        $result['error'] = $e->getMessage();
        if ($logDetail) $result['details'][] = ['status' => 'fail', 'msg' => '推送异常：' . $e->getMessage()];
    }
    return $result;
}

/**
 * 旧入口（task=remind）包装：先检查 legacy 互斥，再执行，最后写 legacy 时间戳。
 * 保留旧独立接口用于手动调试；与 task=all 之间通过 legacy 时间戳做 ≥55 分钟互斥兜底。
 */
function run_remind_with_legacy() {
    if (!timer_check_legacy_dedup('remind')) {
        echo "remind: 距上次（task=all 或独立 task=remind）执行不足 55 分钟，为避免重复催办已跳过\n";
        return;
    }
    run_remind();
    timer_update_legacy_dedup('remind');
}

/**
 * 旧入口（task=push）包装：与 remind 同理加 legacy 兜底。
 */
function run_scheduled_push_with_legacy() {
    if (!timer_check_legacy_dedup('push', 23 * 3600)) {
        echo "push: 距上次（task=all 或独立 task=push）执行不足 23 小时，为避免重复推送已跳过\n";
        return;
    }
    run_scheduled_push();
    timer_update_legacy_dedup('push');
}

/**
 * 定时任务统一入口（task=all）。
 * 分发策略：
 *   ① push        每分钟触发（执行时仍受 schedule 日期 + last_auto_push 去重控制）
 *   ② remind      每小时触发（≥55 分钟间隔栅栏 + 工作时间判断 + 1小时单条去重）
 *   ③ 年假增量同步 每天 02:00-05:00 窗口触发（≥23 小时间隔），回看过去 N 天
 *   ④ 日志清理     每天 03:00-06:00 窗口触发（≥23 小时间隔）
 * 并发控制：timer_global_lock 全局锁（10 分钟 TTL），防止多实例并发。
 * 审计：每次执行完写入 op_type=timer_run 的聚合审计日志。
 */
function run_timer_all() {
    $owner = (function_exists('getmypid') ? getmypid() : 'cron') . ':' . time();
    if (!timer_acquire_global_lock($owner, 600)) {
        echo "timer_all: 全局锁被其他任务持有，本次跳过\n";
        return;
    }

    $runStartTs = time();
    $dateStr    = date('Y-m-d H:i:s', $runStartTs);
    $summary = [
        'started_at' => $dateStr,
        'owner'      => $owner,
        'push'       => ['status' => 'skip', 'reason' => '未到执行条件', 'sent' => 0, 'total' => 0, 'skipped' => false, 'ym' => null, 'error' => null],
        'remind'     => ['status' => 'skip', 'reason' => '未到执行条件',
                          'salary_sent' => 0, 'salary_total' => 0, 'salary_skip' => 0,
                          'bonus_sent'  => 0, 'bonus_total'  => 0, 'bonus_skip'  => 0,
                          'error' => null],
        'vacation'   => ['status' => 'skip', 'reason' => '未到时间窗口', 'processed' => 0, 'inserted_approvals' => 0, 'ledger_changes' => 0, 'errors' => [], 'skipped' => 0],
        'cleanup'    => ['status' => 'skip', 'reason' => '未到时间窗口', 'deleted_audit' => 0, 'deleted_cb' => 0, 'deleted_slog' => 0, 'deleted_feedback' => 0, 'errors' => []],
    ];

    try {
        $hour = (int)date('H');

        // ========== ① push：每分钟都尝试（内部按每月日期 + last_auto_push 去重） ==========
        try {
            // push 独立入口与 task=all 的互斥兜底（保留旧 cron 场景）
            if (!timer_check_legacy_dedup('push', 23 * 3600)) {
                $summary['push']['status'] = 'skip';
                $summary['push']['reason'] = '旧独立入口(task=push)刚在23小时内执行过，为避免重复推送已跳过';
            } else {
                $pushRes = run_scheduled_push_inner(false, true);
                if (!empty($pushRes['skipped'])) {
                    $summary['push']['status'] = 'skip';
                    $summary['push']['reason'] = empty($pushRes['details'][0]['msg']) ? '未到计划日期/当月已推送' : $pushRes['details'][0]['msg'];
                    $summary['push']['ym']     = $pushRes['ym'];
                } else {
                    $summary['push']['status']  = isset($pushRes['error']) ? 'fail' : 'ok';
                    $summary['push']['sent']    = (int)($pushRes['sent'] ?? 0);
                    $summary['push']['total']   = (int)($pushRes['total'] ?? 0);
                    $summary['push']['ym']      = $pushRes['ym'] ?? null;
                    $summary['push']['error']   = $pushRes['error'] ?? null;
                }
                timer_update_last_run('push');
                timer_update_legacy_dedup('push');
            }
        } catch (Throwable $e) {
            $summary['push']['status'] = 'fail';
            $summary['push']['error']  = $e->getMessage();
            error_log('[timer_all] push error: ' . $e->getMessage());
        }

        // ========== ② remind：每小时 ≥55 分钟间隔 + 工作时间判断 ==========
        try {
            if (!timer_check_last_run('remind', 55 * 60)) {
                $summary['remind']['reason'] = '距上次催办完成不足 55 分钟，跳过';
            } elseif (!timer_check_legacy_dedup('remind')) {
                $summary['remind']['reason'] = '旧独立入口(task=remind)刚在 55 分钟内执行过，跳过';
            } else {
                // 定时任务催办遵守工作时间限制（force=false）
                $salaryRes = run_remind_salary(false, true);
                $bonusRes  = run_remind_bonus(false, true);
                $hasError = !empty($salaryRes['error']) || !empty($bonusRes['error']);
                $salarySent = (int)($salaryRes['sent'] ?? 0);
                $salaryTot  = (int)($salaryRes['total'] ?? 0);
                $salarySkip = (int)($salaryRes['skip'] ?? 0);
                $bonusSent  = (int)($bonusRes['sent'] ?? 0);
                $bonusTot   = (int)($bonusRes['total'] ?? 0);
                $bonusSkip  = (int)($bonusRes['skip'] ?? 0);
                $totalSent = $salarySent + $bonusSent;
                // 判断是否因工作时间跳过
                $isWorktimeSkip = false;
                if (!empty($salaryRes['details'])) {
                    foreach ($salaryRes['details'] as $d) {
                        if (!empty($d['status']) && $d['status'] === 'skip_worktime') {
                            $isWorktimeSkip = true;
                            break;
                        }
                    }
                }
                if (!$isWorktimeSkip && !empty($bonusRes['details'])) {
                    foreach ($bonusRes['details'] as $d) {
                        if (!empty($d['status']) && $d['status'] === 'skip_worktime') {
                            $isWorktimeSkip = true;
                            break;
                        }
                    }
                }
                if ($isWorktimeSkip) {
                    $summary['remind']['status']  = 'skip';
                    $summary['remind']['reason']  = '非工作时间跳过';
                } else {
                    $summary['remind']['status']  = $hasError ? 'partial_fail' : 'ok';
                    $summary['remind']['reason']  = $totalSent > 0
                        ? '催办完成'
                        : '暂无待催办记录（1小时内已催办或全部已确认）';
                }
                $summary['remind']['salary_sent']  = $salarySent;
                $summary['remind']['salary_total'] = $salaryTot;
                $summary['remind']['salary_skip']  = $salarySkip;
                $summary['remind']['bonus_sent']   = $bonusSent;
                $summary['remind']['bonus_total']  = $bonusTot;
                $summary['remind']['bonus_skip']   = $bonusSkip;
                if (!empty($salaryRes['error'])) $summary['remind']['error'] = 'salary:' . $salaryRes['error'];
                if (!empty($bonusRes['error']))  $summary['remind']['error'] = ($summary['remind']['error'] ? $summary['remind']['error'] . '; ' : '') . 'bonus:' . $bonusRes['error'];
                timer_update_last_run('remind');
                timer_update_legacy_dedup('remind');
            }
        } catch (Throwable $e) {
            $summary['remind']['status'] = 'fail';
            $summary['remind']['error']  = $e->getMessage();
            error_log('[timer_all] remind error: ' . $e->getMessage());
        }

        // ========== ③ 年假增量同步：每天 02:00-05:00 窗口，≥23 小时间隔 ==========
        try {
            if ($hour < 2 || $hour > 5) {
                $summary['vacation']['reason'] = '不在 02:00-05:00 时间窗口（当前 ' . sprintf('%02d', $hour) . ' 点），跳过';
            } elseif (!timer_check_last_run('vacation_sync', 23 * 3600)) {
                $summary['vacation']['reason'] = '距上次年假同步完成不足 23 小时，跳过';
            } else {
                $vRes = timer_vacation_sync_incremental();
                $summary['vacation']['status']             = empty($vRes['errors']) ? 'ok' : 'fail';
                $summary['vacation']['processed']          = (int)($vRes['processed'] ?? 0);
                $summary['vacation']['inserted_approvals'] = (int)($vRes['inserted_approvals'] ?? 0);
                $summary['vacation']['ledger_changes']     = (int)($vRes['ledger_changes'] ?? 0);
                $summary['vacation']['skipped']            = (int)($vRes['skipped'] ?? 0);
                $summary['vacation']['errors']             = $vRes['errors'] ?? [];
                timer_update_last_run('vacation_sync');
            }
        } catch (Throwable $e) {
            $summary['vacation']['status']   = 'fail';
            $summary['vacation']['errors'][] = $e->getMessage();
            error_log('[timer_all] vacation error: ' . $e->getMessage());
        }

        // ========== ④ 日志清理：每天 03:00-06:00 窗口，≥23 小时间隔 ==========
        try {
            if ($hour < 3 || $hour > 6) {
                $summary['cleanup']['reason'] = '不在 03:00-06:00 时间窗口（当前 ' . sprintf('%02d', $hour) . ' 点），跳过';
            } elseif (!timer_check_last_run('log_cleanup', 23 * 3600)) {
                $summary['cleanup']['reason'] = '距上次日志清理完成不足 23 小时，跳过';
            } else {
                $cRes = timer_cleanup_logs();
                $summary['cleanup']['status']           = empty($cRes['errors']) ? 'ok' : 'fail';
                $summary['cleanup']['deleted_audit']    = (int)($cRes['deleted_audit'] ?? 0);
                $summary['cleanup']['deleted_cb']       = (int)($cRes['deleted_cb'] ?? 0);
                $summary['cleanup']['deleted_slog']     = (int)($cRes['deleted_slog'] ?? 0);
                $summary['cleanup']['deleted_feedback'] = (int)($cRes['deleted_feedback'] ?? 0);
                $summary['cleanup']['errors']           = $cRes['errors'] ?? [];
                timer_update_last_run('log_cleanup');
            }
        } catch (Throwable $e) {
            $summary['cleanup']['status']   = 'fail';
            $summary['cleanup']['errors'][] = $e->getMessage();
            error_log('[timer_all] cleanup error: ' . $e->getMessage());
        }
    } finally {
        timer_release_global_lock($owner);
    }

    // 聚合审计日志：仅在至少有一个子任务实际执行（非 skip）或出现失败时写入
    // 4 个子任务全 skip → 说明所有子任务在前置栅栏（时间窗/last_run）就被拦截，未消耗任何 API/DB 资源，
    // 此时不写 audit_log 避免每分钟产生大量垃圾心跳记录；仅写一条轻量 slog 便于 cron 侧排查。
    $summary['duration_sec'] = time() - $runStartTs;
    $hasFailure = false;
    $hasAnyNonSkip = false;
    foreach (['push', 'remind', 'vacation', 'cleanup'] as $k) {
        $st = $summary[$k]['status'] ?? 'skip';
        if ($st !== 'skip') { $hasAnyNonSkip = true; }
        if ($st === 'fail')   { $hasFailure = true; }
    }
    $overallResult = $hasFailure ? 'fail' : 'ok';
    if ($hasAnyNonSkip || $hasFailure) {
        try {
            audit_log_for_callback('定时任务', 'timer_run', 'all_tasks', $overallResult, $summary);
        } catch (Throwable $e) {
            error_log('[timer_all] audit_log fail: ' . $e->getMessage());
        }
    } else {
        // 全 skip 兜底：仅写 slog（本地日志文件），不进 audit_log 表
        try {
            slog('[timer_all] all_skipped owner=' . $owner . ' dur=' . $summary['duration_sec'] . 's push_reason=' . $summary['push']['reason'] . ' remind_reason=' . $summary['remind']['reason'] . ' vacation_reason=' . $summary['vacation']['reason'] . ' cleanup_reason=' . $summary['cleanup']['reason']);
        } catch (Throwable $e) {
            error_log('[timer_all] slog fail: ' . $e->getMessage());
        }
    }

    // 终端友好输出（写进 cron 日志便于排查）
    echo "=== timer_all @ {$dateStr} (owner={$owner}) ===\n";
    echo "push:     status={$summary['push']['status']} sent={$summary['push']['sent']}/{$summary['push']['total']} ym=" . ($summary['push']['ym'] ?? '-') . " reason={$summary['push']['reason']}" . (!empty($summary['push']['error']) ? " error={$summary['push']['error']}" : '') . "\n";
    echo "remind:   status={$summary['remind']['status']} salary={$summary['remind']['salary_sent']}/{$summary['remind']['salary_total']}(skip={$summary['remind']['salary_skip']}) bonus={$summary['remind']['bonus_sent']}/{$summary['remind']['bonus_total']}(skip={$summary['remind']['bonus_skip']}) reason={$summary['remind']['reason']}" . (!empty($summary['remind']['error']) ? " error={$summary['remind']['error']}" : '') . "\n";
    echo "vacation: status={$summary['vacation']['status']} processed={$summary['vacation']['processed']} inserted={$summary['vacation']['inserted_approvals']} ledger={$summary['vacation']['ledger_changes']} reason={$summary['vacation']['reason']}" . (!empty($summary['vacation']['errors']) ? " errors=" . implode(';', $summary['vacation']['errors']) : '') . "\n";
    echo "cleanup:  status={$summary['cleanup']['status']} audit=-{$summary['cleanup']['deleted_audit']} cb=-{$summary['cleanup']['deleted_cb']} slog=-{$summary['cleanup']['deleted_slog']} fb=-{$summary['cleanup']['deleted_feedback']} reason={$summary['cleanup']['reason']}" . (!empty($summary['cleanup']['errors']) ? " errors=" . implode(';', $summary['cleanup']['errors']) : '') . "\n";
    echo "duration: {$summary['duration_sec']}s, overall={$overallResult}\n";
}
