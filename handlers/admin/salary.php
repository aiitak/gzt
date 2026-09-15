<?php
/**
 * 工资相关接口：上传/历史导入/下发/统计/列表/详情/删除/模板/回收站
 */

/**
 * 执行下发（供手动与定时共用）。scope: draft=仅未下发；all=未确认全部
 */
function do_push($year, $month, $scope = 'draft', $userids = []) {
    $db = get_db();
    if ($scope === 'all') {
        $sql = "SELECT id, userid FROM salary WHERE year=? AND month=? AND status IN ('draft','sent') AND confirmed=0 AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL";
    } else {
        $sql = "SELECT id, userid FROM salary WHERE year=? AND month=? AND status='draft' AND confirmed=0 AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL";
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([$year, $month]);
    $sent = 0;
    $total = 0;
    foreach ($stmt->fetchAll() as $r) {
        if ($userids && !in_array($r['userid'], $userids, true)) {
            continue;
        }
        $total++;
        // 原子抢占防止并发重复推送：先把 draft→sent，rowCount=0 说明已被其他进程处理
        $claim = $db->prepare("UPDATE salary SET status=?, pushed_at=? WHERE id=? AND status=?");
        $claim->execute([SALARY_SENT, date('Y-m-d H:i:s'), $r['id'], SALARY_DRAFT]);
        if ($claim->rowCount() > 0) {
            // 抢占成功（原 draft），发送消息；失败则回退
            if (salary_push_send($r['userid'], $year, $month)) {
                $sent++;
            } else {
                $db->prepare("UPDATE salary SET status=?, pushed_at=NULL WHERE id=?")
                   ->execute([SALARY_DRAFT, $r['id']]);
            }
        } elseif ($scope === 'all') {
            // scope=all 时已 sent 的记录重新推送（允许重复，用于催办式重推）
            if (salary_push_send($r['userid'], $year, $month)) {
                $sent++;
            }
        }
    }
    return ['total' => $total, 'sent' => $sent];
}

function handle_admin_upload() {
    $u = require_admin_session([ROLE_FINANCE, ROLE_ADMIN]);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_out(['success' => false, 'error' => '未收到文件或上传失败']);
    }
    $f = $_FILES['file'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        json_out(['success' => false, 'error' => '仅支持 .xlsx 格式']);
    }
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }
    $save = UPLOAD_DIR . uniqid('sal_', true) . '.xlsx';
    if (!move_uploaded_file($f['tmp_name'], $save)) {
        json_out(['success' => false, 'error' => '文件保存失败，请检查目录权限']);
    }

    $parsed = parse_salary_excel($save);
    if (!$parsed['success']) {
        @unlink($save);
        json_out(['success' => false, 'error' => implode('；', $parsed['errors'])]);
    }

    $year = $parsed['year'];
    $month = $parsed['month'];
    $rows = $parsed['rows'];
    $columns = $parsed['columns'];
    // columns 元素为 ['name'=>列名,'category'=>分组名]，第一列为员工标识
    $firstCol = $columns[0]['name'];
    // 构建列名 -> 分组名 映射，供导入明细时使用
    $categoryMap = [];
    foreach ($columns as $c) {
        $categoryMap[$c['name']] = $c['category'] ?? '';
    }

    $db = get_db();
    $imported = 0;
    $skip = 0;
    $autoCreated = 0;
    $skippedNames = [];
    $autoCreatedNames = [];
    $rowIdx = 0;
    // 整体事务：任何一行出错则全部回滚，避免部分导入残留
    $db->beginTransaction();
    try {
        foreach ($rows as $row) {
            $rowIdx++;
            $rawVal = trim((string)($row[$firstCol] ?? ''));
            if ($rawVal === '') {
                continue;
            }
            $matched = match_employee($db, $rawVal, 'normal');
            $userid = $matched['userid'];
            $empName = $matched['name'];
            $deptName = $matched['dept_name'];
            $isHist = $matched['is_hist'];
            if ($userid === null) {
                $skip++;
                if (!in_array($rawVal, $skippedNames, true)) {
                    $skippedNames[] = $rawVal;
                }
                continue;
            }
            if ($isHist) {
                // HIST_U_ 账号：工资条会导入存档但不会推送，也算未匹配
                $skip++;
                $autoCreated++;
                if (!in_array($empName, $skippedNames, true)) {
                    $skippedNames[] = $empName;
                }
                if (!in_array($empName, $autoCreatedNames, true)) {
                    $autoCreatedNames[] = $empName;
                }
            }
            $realPay = 0;
            foreach ($row as $k => $v) {
                if (strpos((string)$k, '实发工资') !== false) {
                    $realPay = (float)str_replace(',', '', (string)$v);
                    break;
                }
            }
            // 查重时同时考虑软删记录：若存在（含已软删），复用该 id 并清除软删标记，避免 UNIQUE 约束冲突
            $g = $db->prepare("SELECT id FROM salary WHERE year = ? AND month = ? AND userid = ?");
            $g->execute([$year, $month, $userid]);
            $ex = $g->fetch();
            if ($ex) {
                $sid = $ex['id'];
                // 重新上传已确认工资条时，统一回退到 draft 状态（清除确认标记），避免 status=confirmed AND confirmed=0 的矛盾状态
                // 同时清除软删标记（deleted_at/deleted_by），使回收站中的记录被重新上传覆盖后恢复可见
                $db->prepare(
                    "UPDATE salary SET real_pay = ?, name = ?, dept_name = ?, status = 'draft',
                     confirmed = 0, confirmed_at = NULL, pushed_at = NULL, last_remind_at = NULL,
                     deleted_at = NULL, deleted_by = NULL WHERE id = ?"
                )->execute([$realPay, $empName, $deptName, $sid]);
                $db->prepare("DELETE FROM salary_items WHERE salary_id = ?")->execute([$sid]);
            } else {
                $db->prepare(
                    "INSERT INTO salary (userid,name,dept_name,year,month,real_pay,status) VALUES (?,?,?,?,?,?,'draft')"
                )->execute([$userid, $empName, $deptName, $year, $month, $realPay]);
                $sid = $db->lastInsertId();
            }
            $ins = $db->prepare(
                "INSERT INTO salary_items (salary_id,item_name,item_value,sort_order,is_deduction,category)
                 VALUES (?,?,?,?,?,?)"
            );
            $i = 0;
            foreach ($row as $k => $v) {
                if ((string)$k === $firstCol) {
                    continue;
                }
                $isDed = is_deduction_field($k) ? 1 : 0;
                $cat = $categoryMap[$k] ?? '';
                $ins->execute([$sid, (string)$k, (string)$v, $i, $isDed, $cat]);
                $i++;
            }
            $imported++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        @unlink($save);
        json_out(['success' => false, 'error' => '导入第 ' . $rowIdx . ' 行出错：' . $e->getMessage()]);
    }
    @unlink($save);
    // 重新上传后清除定时推送的防重复标记，使定时任务能自动推送重传的 draft 工资条
    set_setting('last_auto_push', '');
    // 构建列名+分组预览（排除第0列员工标识），供前端展示解析结果
    $colPreview = [];
    foreach ($columns as $c) {
        if ($c['name'] === $firstCol) {
            continue;
        }
        $colPreview[] = ['name' => $c['name'], 'category' => $c['category'] ?? ''];
    }
    audit_log('upload_salary', 'salary:' . sprintf('%04d/%02d', $year, $month), 'ok', [
        'imported' => $imported, 'skip' => $skip, 'columns' => count($columns) - 1,
        'skipped_names' => $skippedNames, 'auto_created' => $autoCreated,
        'auto_created_names' => $autoCreatedNames,
    ]);
    json_out([
        'success'   => true,
        'year_month'=> sprintf('%04d%02d', $year, $month),
        'valid'     => $imported,
        'columns'   => count($columns) - 1,
        'skip'      => $skip,
        'skipped_names' => $skippedNames,
        'auto_created' => $autoCreated,
        'auto_created_names' => $autoCreatedNames,
        'col_preview' => $colPreview,
    ]);
}

/**
 * 批量历史工资条导入（标记为已确认）
 *   支持多文件上传，每个 Excel 文件对应一个月份
 *   导入后状态设为 confirmed，confirmed_at 设为当前时间
 */
function handle_admin_history_import() {
    $u = require_admin_session([ROLE_FINANCE, ROLE_ADMIN]);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }

    if (empty($_FILES['files']) || !is_array($_FILES['files']['name'])) {
        json_out(['success' => false, 'error' => '未收到文件或上传失败']);
    }

    $files = $_FILES['files'];
    $fileCount = count($files['name']);

    if ($fileCount === 0) {
        json_out(['success' => false, 'error' => '请至少选择一个文件']);
    }

    if ($fileCount > 50) {
        json_out(['success' => false, 'error' => '最多支持导入 50 个文件，当前选择了 ' . $fileCount . ' 个']);
    }

    $db = get_db();
    $totalImported = 0;
    $totalSkip = 0;
    $results = [];
    $errors = [];

    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }

    $db->beginTransaction();
    try {
        for ($i = 0; $i < $fileCount; $i++) {
            $fileName = $files['name'][$i];
            $fileError = $files['error'][$i];

            if ($fileError !== UPLOAD_ERR_OK) {
                $errors[] = "文件 $fileName 上传失败";
                continue;
            }

            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if ($ext !== 'xlsx') {
                $errors[] = "文件 $fileName 格式不支持（仅支持 .xlsx）";
                continue;
            }

            $savePath = UPLOAD_DIR . uniqid('hist_', true) . '_' . $i . '.xlsx';
            if (!move_uploaded_file($files['tmp_name'][$i], $savePath)) {
                $errors[] = "文件 $fileName 保存失败";
                continue;
            }

            $parsed = parse_salary_excel($savePath);
            @unlink($savePath);

            if (!$parsed['success']) {
                $errors[] = "文件 $fileName 解析失败：" . implode('；', $parsed['errors']);
                continue;
            }

            $year = $parsed['year'];
            $month = $parsed['month'];
            $rows = $parsed['rows'];
            $columns = $parsed['columns'];
            $firstCol = $columns[0]['name'];

            $categoryMap = [];
            foreach ($columns as $c) {
                $categoryMap[$c['name']] = $c['category'] ?? '';
            }

            $monthImported = 0;
            $monthSkip = 0;
            $monthSkippedNames = [];
            $monthAutoCreated = 0;     // 方案B：本月自动创建的历史员工数
            $monthAutoCreatedNames = [];
            $confirmedAt = date('Y-m-d H:i:s');

            foreach ($rows as $row) {
                $rawVal = trim((string)($row[$firstCol] ?? ''));
                if ($rawVal === '') {
                    continue;
                }

                $matched = match_employee($db, $rawVal, 'history');
                $userid = $matched['userid'];
                $empName = $matched['name'];
                $deptName = $matched['dept_name'];
                $isHistoryOnly = $matched['is_hist'];

                if ($userid === null) {
                    $monthSkip++;
                    if (!in_array($rawVal, $monthSkippedNames, true)) {
                        $monthSkippedNames[] = $rawVal;
                    }
                    continue;
                }
                if ($isHistoryOnly) {
                    $monthAutoCreated++;
                    if (!in_array($empName, $monthAutoCreatedNames, true)) {
                        $monthAutoCreatedNames[] = $empName;
                    }
                }

                $realPay = 0;
                foreach ($row as $k => $v) {
                    if (strpos((string)$k, '实发工资') !== false) {
                        $realPay = (float)str_replace(',', '', (string)$v);
                        break;
                    }
                }

                // 历史导入查重：同时考虑软删记录，若存在（含已软删），复用 id 并清除软删标记
                $g = $db->prepare("SELECT id, status, confirmed FROM salary WHERE year = ? AND month = ? AND userid = ?");
                $g->execute([$year, $month, $userid]);
                $ex = $g->fetch();

                if ($ex) {
                    $sid = $ex['id'];
                    // 已下发且未确认的工资条不跳过签收环节，保留原状态
                    if ($ex['status'] === 'sent' && (int)$ex['confirmed'] === 0) {
                        $monthSkip++;
                        if (!in_array($rawVal, $monthSkippedNames, true)) {
                            $monthSkippedNames[] = $rawVal . '（已下发未确认，跳过历史覆盖）';
                        }
                        continue;
                    }
                    $db->prepare(
                        "UPDATE salary SET real_pay = ?, name = ?, dept_name = ?, status = 'confirmed',
                         confirmed = 1, confirmed_at = ?, deleted_at = NULL, deleted_by = NULL WHERE id = ?"
                    )->execute([$realPay, $empName, $deptName, $confirmedAt, $sid]);
                    $db->prepare("DELETE FROM salary_items WHERE salary_id = ?")->execute([$sid]);
                } else {
                    $db->prepare(
                        "INSERT INTO salary (userid,name,dept_name,year,month,real_pay,status,confirmed,confirmed_at) VALUES (?,?,?,?,?,?,?,?,?)"
                    )->execute([$userid, $empName, $deptName, $year, $month, $realPay, 'confirmed', 1, $confirmedAt]);
                    $sid = $db->lastInsertId();
                }

                $ins = $db->prepare(
                    "INSERT INTO salary_items (salary_id,item_name,item_value,sort_order,is_deduction,category)
                     VALUES (?,?,?,?,?,?)"
                );
                $sortOrder = 0;
                foreach ($row as $k => $v) {
                    if ((string)$k === $firstCol) {
                        continue;
                    }
                    $isDed = is_deduction_field($k) ? 1 : 0;
                    $cat = $categoryMap[$k] ?? '';
                    $ins->execute([$sid, (string)$k, (string)$v, $sortOrder, $isDed, $cat]);
                    $sortOrder++;
                }
                $monthImported++;
            }

            $totalImported += $monthImported;
            $totalSkip += $monthSkip;
            $results[] = [
                'file' => $fileName,
                'year' => $year,
                'month' => $month,
                'imported' => $monthImported,
                'skip' => $monthSkip,
                'skipped_names' => $monthSkippedNames,
                'auto_created' => $monthAutoCreated,
                'auto_created_names' => $monthAutoCreatedNames,
            ];
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        json_out(['success' => false, 'error' => '导入出错：' . $e->getMessage()]);
    }

    // 汇总所有月份未匹配 / 自动创建的姓名（去重）
    $allSkipped = [];
    $allAutoCreated = [];
    foreach ($results as $r) {
        foreach (($r['skipped_names'] ?? []) as $n) {
            if (!in_array($n, $allSkipped, true)) $allSkipped[] = $n;
        }
        foreach (($r['auto_created_names'] ?? []) as $n) {
            if (!in_array($n, $allAutoCreated, true)) $allAutoCreated[] = $n;
        }
    }

    // 合并未匹配名单：自动创建 + 跳过 都算未匹配
    $allUnmatched = [];
    $unmatchedMeta = [];
    foreach ($allAutoCreated as $n) {
        $allUnmatched[] = $n;
        $unmatchedMeta[$n] = 'auto';
    }
    foreach ($allSkipped as $n) {
        if (!in_array($n, $allUnmatched, true)) {
            $allUnmatched[] = $n;
        }
        $unmatchedMeta[$n] = 'skip';
    }

    // 无论成功或部分失败都记录审计日志，部分失败场景此前无审计可追溯
    audit_log('history_import', '', count($errors) === 0 ? 'ok' : 'partial', [
        'total_imported' => $totalImported,
        'total_skip' => $totalSkip,
        'files' => count($results),
        'errors' => count($errors),
    ]);
    json_out([
        'success' => count($errors) === 0,
        'total_imported' => $totalImported,
        'total_skip' => $totalSkip,
        'total_unmatched' => count($allUnmatched),
        'all_skipped_names' => $allSkipped,
        'all_auto_created_names' => $allAutoCreated,
        'all_unmatched_names' => $allUnmatched,
        'unmatched_meta' => $unmatchedMeta,
        'total_auto_created' => count($allAutoCreated),
        'results' => $results,
        'errors' => $errors,
    ]);
}

function _push_common($scope) {
    $ym = param('id', param('ym', ''));
    if (strlen($ym) !== 6 || !ctype_digit($ym)) {
        json_out(['success' => false, 'error' => '参数错误']);
    }
    $year = (int)substr($ym, 0, 4);
    $month = (int)substr($ym, 4, 2);
    $db = get_db();
    $ret = do_push($year, $month, $scope);
    $sent = $ret['sent'];
    $total = $ret['total'];
    $rTpl = render_builtin_template('push_result', [
        '年'     => (string)$year,
        '月'     => sprintf('%02d', $month),
        '成功数' => (string)$sent,
        '总数'   => (string)$total,
    ]);
    notify_push_result($rTpl['content'], $rTpl);
    audit_log('push_salary', 'salary:' . sprintf('%04d/%02d', $year, $month), 'ok', [
        'sent' => $sent, 'fail' => $total - $sent, 'total' => $total,
    ]);
    json_out(['success' => true, 'success_count' => $sent, 'fail_count' => $total - $sent]);
}

function handle_admin_push() {
    require_admin_session([ROLE_FINANCE, ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['success' => false, 'error' => 'Method Not Allowed'], 405); return; }
    _push_common('draft');
}

function handle_admin_push_all() {
    require_admin_session([ROLE_FINANCE, ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['success' => false, 'error' => 'Method Not Allowed'], 405); return; }
    _push_common('all');
}

/**
 * 列出"有未下发（draft）工资"的月份，供上传页手动推送选择
 */
function handle_admin_push_months() {
    require_admin_session([ROLE_FINANCE, ROLE_ADMIN]);
    $db = get_db();
    $rows = $db->query("SELECT year, month, COUNT(*) c FROM salary WHERE status='draft' AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL GROUP BY year, month ORDER BY year DESC, month DESC")->fetchAll();
    $months = [];
    foreach ($rows as $r) {
        $months[] = ['ym' => sprintf('%04d%02d', $r['year'], $r['month']), 'draft' => (int)$r['c']];
    }
    json_out(['success' => true, 'months' => $months]);
}

/**
 * 列出"所有可重新推送"的月份（含已推送），供重新推送按钮选择
 */
function handle_admin_push_all_months() {
    require_admin_session([ROLE_FINANCE, ROLE_ADMIN]);
    $db = get_db();
    $rows = $db->query("SELECT year, month, COUNT(*) c FROM salary WHERE status IN ('draft','sent') AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL GROUP BY year, month ORDER BY year DESC, month DESC")->fetchAll();
    $months = [];
    foreach ($rows as $r) {
        $months[] = ['ym' => sprintf('%04d%02d', $r['year'], $r['month']), 'total' => (int)$r['c']];
    }
    json_out(['success' => true, 'months' => $months]);
}

function handle_admin_stats_overview() {
    require_admin_session([ROLE_FINANCE, ROLE_ADMIN, ROLE_HR]);
    $db = get_db();
    $l = $db->prepare("SELECT year, month FROM salary WHERE status IN ('sent','confirmed') AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL ORDER BY year DESC, month DESC LIMIT 1");
    $l->execute();
    $row = $l->fetch();
    $latest_month = $row ? sprintf('%04d%02d', $row['year'], $row['month']) : '无';
    $sent_months = $db->query("SELECT COUNT(DISTINCT year * 100 + month) c FROM salary WHERE status IN ('sent','confirmed') AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL")->fetch()['c'];
    // 已确认/待确认：只统计最后一个月的工资条
    if ($row) {
        $confirmedStmt = $db->prepare("SELECT COUNT(*) c FROM salary WHERE year = ? AND month = ? AND confirmed = 1 AND status IN ('sent','confirmed') AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL");
        $confirmedStmt->execute([$row['year'], $row['month']]);
        $confirmed = $confirmedStmt->fetch()['c'];
        $unconfirmedStmt = $db->prepare("SELECT COUNT(*) c FROM salary WHERE year = ? AND month = ? AND status IN ('sent','confirmed') AND confirmed = 0 AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL");
        $unconfirmedStmt->execute([$row['year'], $row['month']]);
        $unconfirmed = $unconfirmedStmt->fetch()['c'];
    } else {
        $confirmed = 0;
        $unconfirmed = 0;
    }
    // 员工总数：企微真实在职员工，与「通讯录主列表」「年假总览」口径保持一致：
    //   wecom_userid 非空 → 企微在册
    //   userid 非 HIST_  → 排除历史存档（手工重命名）
    //   部门 ∉ (已离职,未匹配) → 排除已挂入离职/未匹配部门的老员工
    $employees = $db->query(
        "SELECT COUNT(*) c FROM users u
         LEFT JOIN departments d ON u.dept_id = d.dept_id
         WHERE u.wecom_userid IS NOT NULL
           AND u.userid NOT LIKE 'HIST_%'
           AND COALESCE(d.name, '') NOT IN ('已离职','未匹配')"
    )->fetch()['c'];
    $deptCount = $db->query("SELECT COUNT(*) c FROM departments")->fetch()['c'];
    $pending = $db->query("SELECT COUNT(*) c FROM feedback WHERE status='pending'")->fetch()['c'];
    $totalSentEmployees = $confirmed + $unconfirmed;
    json_out([
        'latest_month'         => $latest_month,
        'total_sent_months'    => (int)$sent_months,
        'total_sent_employees' => (int)$totalSentEmployees,
        'total_confirmed'      => (int)$confirmed,
        'total_unconfirmed'    => (int)$unconfirmed,
        'total_employees'      => (int)$employees,
        'total_departments'    => (int)$deptCount,
        'pending_feedback'     => (int)$pending,
    ]);
}

function handle_admin_stats_salary() {
    require_admin_session([ROLE_FINANCE, ROLE_ADMIN, ROLE_HR]);
    $ym = param('id', param('ym', ''));
    if (strlen($ym) !== 6 || !ctype_digit($ym)) { json_out(['error'=>'invalid ym']); return; }
    $year = (int)substr($ym, 0, 4);
    $month = (int)substr($ym, 4, 2);
    $db = get_db();
    $total = $db->prepare("SELECT COUNT(*) c FROM salary WHERE year=? AND month=? AND status IN ('sent','confirmed') AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL");
    $total->execute([$year, $month]);
    $total = (int)$total->fetch()['c'];
    $confirmed = $db->prepare("SELECT COUNT(*) c FROM salary WHERE year=? AND month=? AND confirmed=1 AND status IN ('sent','confirmed') AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL");
    $confirmed->execute([$year, $month]);
    $confirmed = (int)$confirmed->fetch()['c'];
    $unconfirmed = $total - $confirmed;
    $rate = $total ? round($confirmed / $total * 100, 1) : 0;

    $stmt = $db->prepare(
        "SELECT u.dept_id, d.name AS deptname, COUNT(*) AS tot, SUM(CASE WHEN s.confirmed=1 THEN 1 ELSE 0 END) AS conf
         FROM salary s LEFT JOIN users u ON s.userid = u.userid
         LEFT JOIN departments d ON u.dept_id = d.dept_id
         WHERE s.year=? AND s.month=? AND s.status IN ('sent','confirmed') AND s.userid NOT LIKE 'HIST_%' AND s.deleted_at IS NULL GROUP BY u.dept_id, d.name"
    );
    $stmt->execute([$year, $month]);
    $dept_stats = [];
    foreach ($stmt->fetchAll() as $r) {
        $name = normalize_dept_name($r['deptname'] ?: '');
        $dept_stats[$name] = ['total' => (int)$r['tot'], 'confirmed' => (int)$r['conf']];
    }
    $ul = $db->prepare(
        "SELECT COALESCE(NULLIF(s.name,''), u.name) AS name, COALESCE(NULLIF(s.dept_name,''), d.name) AS deptname FROM salary s
         LEFT JOIN users u ON s.userid = u.userid
         LEFT JOIN departments d ON u.dept_id = d.dept_id
         WHERE s.year=? AND s.month=? AND s.confirmed=0 AND s.status IN ('sent','confirmed') AND s.userid NOT LIKE 'HIST_%' AND s.deleted_at IS NULL"
    );
    $ul->execute([$year, $month]);
    $unconfirmed_list = [];
    foreach ($ul->fetchAll() as $r) {
        $unconfirmed_list[] = ['name' => $r['name'], 'department' => normalize_dept_name($r['deptname'] ?: '')];
    }
    json_out([
        'total' => $total, 'confirmed' => $confirmed, 'unconfirmed' => $unconfirmed,
        'rate' => $rate, 'dept_stats' => $dept_stats, 'unconfirmed_list' => $unconfirmed_list,
    ]);
}

/**
 * 工资条列表（后台查看）
 *   GET /api/admin/salary/list?ym=202604          指定月份的员工工资条列表
 *   GET /api/admin/salary/list                     最近所有月份列表
 */
function handle_admin_salary_list() {
    require_admin_session([ROLE_FINANCE, ROLE_ADMIN]);
    $db = get_db();
    $ym = (string)param('ym', '');
    if ($ym !== '') {
        if (strlen($ym) !== 6 || !ctype_digit($ym)) {
            json_out(['success' => false, 'error' => '月份参数格式错误']);
        }
        // 指定月份：返回该月所有员工工资条
        $year = (int)substr($ym, 0, 4);
        $month = (int)substr($ym, 4, 2);
        $stmt = $db->prepare(
            "SELECT s.id, s.userid,
                    CASE WHEN s.name = '' OR s.name = s.userid OR s.name IN ('admin','Administrator','管理员') THEN COALESCE(NULLIF(u.name,''), s.name) ELSE s.name END AS name,
                    s.year, s.month, s.real_pay,
                    s.status, s.confirmed, s.confirmed_at, s.pushed_at,
                    CASE WHEN s.dept_name = '' OR s.dept_name = '未分配' OR s.dept_name = '已辞职' THEN COALESCE(NULLIF(d.name,''), s.dept_name) ELSE s.dept_name END AS dept_name
             FROM salary s
             LEFT JOIN users u ON s.userid = u.userid
             LEFT JOIN departments d ON u.dept_id = d.dept_id
             WHERE s.year = ? AND s.month = ? AND s.deleted_at IS NULL
             ORDER BY s.confirmed ASC, name ASC"
        );
        $stmt->execute([$year, $month]);
        $list = $stmt->fetchAll();
        foreach ($list as &$row) {
            $row['dept_name'] = normalize_dept_name($row['dept_name'] ?? '');
        }
        unset($row);
        json_out(['success' => true, 'ym' => $ym, 'list' => $list]);
    }
    // 无 ym：返回所有月份汇总
    $stmt = $db->query(
        "SELECT s.year, s.month, COUNT(*) AS cnt,
                SUM(CASE WHEN s.confirmed=1 THEN 1 ELSE 0 END) AS confirmed_cnt,
                SUM(s.real_pay) AS total_pay
         FROM salary s
         WHERE s.status IN ('sent','confirmed') AND s.deleted_at IS NULL
         GROUP BY s.year, s.month
         ORDER BY s.year DESC, s.month DESC"
    );
    $months = $stmt->fetchAll();
    // 格式化 ym 为 6 位字符串，避免前端拼接错误
    $out = [];
    foreach ($months as $m) {
        $out[] = [
            'year'          => (int)$m['year'],
            'month'         => (int)$m['month'],
            'ym'            => sprintf('%04d%02d', $m['year'], $m['month']),
            'cnt'           => (int)$m['cnt'],
            'confirmed_cnt' => (int)$m['confirmed_cnt'],
            'total_pay'     => (float)$m['total_pay'],
        ];
    }
    json_out(['success' => true, 'months' => $out]);
}

/**
 * 工资条详情（后台查看某员工某月工资条，含表头分组）
 *   GET /api/admin/salary/detail/{ym}?userid=xxx
 */
function handle_admin_salary_detail() {
    require_admin_session([ROLE_FINANCE, ROLE_ADMIN]);
    $ym = (string)param('id', '');
    $userid = (string)param('userid', '');
    if (strlen($ym) !== 6 || !ctype_digit($ym) || $userid === '') {
        json_out(['success' => false, 'error' => '参数错误']);
    }
    $year = (int)substr($ym, 0, 4);
    $month = (int)substr($ym, 4, 2);
    $db = get_db();
    // 工资条主信息
    $stmt = $db->prepare(
        "SELECT s.*,
                CASE WHEN s.name = '' OR s.name = s.userid OR s.name IN ('admin','Administrator','管理员') THEN COALESCE(NULLIF(u.name,''), s.name) ELSE s.name END AS name,
                CASE WHEN s.dept_name = '' OR s.dept_name = '未分配' OR s.dept_name = '已辞职' THEN COALESCE(NULLIF(d.name,''), s.dept_name) ELSE s.dept_name END AS dept_name
         FROM salary s
         LEFT JOIN users u ON s.userid = u.userid
         LEFT JOIN departments d ON u.dept_id = d.dept_id
         WHERE s.userid = ? AND s.year = ? AND s.month = ? AND s.deleted_at IS NULL"
    );
    $stmt->execute([$userid, $year, $month]);
    $sal = $stmt->fetch();
    if (!$sal) {
        json_out(['success' => false, 'error' => '未找到该工资条']);
    }
    // 明细项（含 category 分组）
    // 规则：金额为 0 的项目不显示；某分组全为 0 则整个分组不显示
    $it = $db->prepare(
        "SELECT item_name, item_value, is_deduction, category, sort_order
         FROM salary_items
         WHERE salary_id = ?
         ORDER BY sort_order"
    );
    $it->execute([$sal['id']]);
    $items = $it->fetchAll();
    // 按 category 聚合（保留顺序），计算每组合计，跳过 0 项
    $groups = [];
    $groupOrder = [];
    foreach ($items as $it) {
        $val = (float)$it['item_value'];
        // 跳过金额为 0 的项（实发工资是合计项，本身也不显示在明细中）
        if ($val === 0.0 || $it['item_name'] === '实发工资') {
            continue;
        }
        $cat = $it['category'] ?: '';
        if (!isset($groups[$cat])) {
            $groups[$cat] = ['name' => $cat, 'items' => [], 'sum' => 0, 'is_deduction' => false];
            $groupOrder[] = $cat;
        }
        $dispVal = $it['is_deduction'] == 1 ? -abs($val) : $val;
        $groups[$cat]['items'][] = [
            'name' => $it['item_name'],
            'value' => $it['item_value'],
            'display' => $dispVal,
            'is_deduction' => (int)$it['is_deduction'],
        ];
        $groups[$cat]['sum'] += $dispVal;
        if ($it['is_deduction'] == 1) {
            $groups[$cat]['is_deduction'] = true;
        }
    }
    // 过滤掉空分组（全为0被跳过的）
    $groupedList = [];
    foreach ($groupOrder as $cat) {
        if (!empty($groups[$cat]['items'])) {
            $groupedList[] = $groups[$cat];
        }
    }
    json_out([
        'success' => true,
        'salary' => [
            'id' => $sal['id'],
            'userid' => $sal['userid'],
            'name' => $sal['name'],
            'dept_name' => normalize_dept_name($sal['dept_name'] ?: ''),
            'year' => $sal['year'],
            'month' => $sal['month'],
            'real_pay' => (float)$sal['real_pay'],
            'status' => $sal['status'],
            'confirmed' => (bool)$sal['confirmed'],
            'confirmed_at' => $sal['confirmed_at'],
            'pushed_at' => $sal['pushed_at'],
        ],
        'groups' => $groupedList,
        'items' => $items,
    ]);
}

/**
 * 删除工资条
 *   POST /api/admin/salary/delete
 *   参数：
 *     ym     - 必填，6 位年月（如 202607）
 *     userid - 可选，指定员工；不传则删除该月所有工资条
 *   权限：仅财务/管理员（HR 无删除权限）
 */
function handle_admin_salary_delete() {
    $u = require_admin_session([ROLE_FINANCE, ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $ym = (string)param('ym', '');
    if (strlen($ym) !== 6 || !ctype_digit($ym)) {
        json_out(['success' => false, 'error' => '参数错误：ym 需为 6 位数字']);
    }
    $year = (int)substr($ym, 0, 4);
    $month = (int)substr($ym, 4, 2);
    $userid = trim((string)param('userid', ''));

    $db = get_db();
    // 软删除操作人：记录到 deleted_by 字段，便于回收站展示
    // require_admin_session 同时覆盖后台账号与企业微信登录两种场景，统一用其返回值取 userid
    $opUser = $u['userid'] ?? '-';
    try {
        $db->beginTransaction();
        if ($userid !== '') {
            // 软删除单个员工该月工资条（仅删除未软删的记录）
            $stmt = $db->prepare("SELECT id FROM salary WHERE year=? AND month=? AND userid=? AND deleted_at IS NULL");
            $stmt->execute([$year, $month, $userid]);
            $sal = $stmt->fetch();
            if (!$sal) {
                if ($db->inTransaction()) $db->rollBack();
                json_out(['success' => false, 'error' => '未找到该员工的工资条']);
            }
            $sid = $sal['id'];
            // 删除前固化反馈的年月归属：工资条删除后 salary_id 失效，回填 salary_year/month 避免反馈被误归入"通用反馈"
            $db->prepare(
                "UPDATE feedback SET salary_year = ?, salary_month = ? WHERE salary_id = ? AND salary_year IS NULL"
            )->execute([$year, $month, $sid]);
            // 软删除：子表 salary_items 不动，跟随主表可见性
            $db->prepare("UPDATE salary SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ? WHERE id = ?")->execute([$opUser, $sid]);
            $db->commit();
            audit_log('delete_salary', 'salary:' . sprintf('%04d/%02d#%d', $year, $month, $sid), 'ok', ['scope' => 'single']);
            json_out(['success' => true, 'deleted' => 1, 'scope' => 'single']);
        }
        // 软删除整月所有工资条（仅删除未软删的记录）
        $q = $db->prepare("SELECT id FROM salary WHERE year=? AND month=? AND deleted_at IS NULL");
        $q->execute([$year, $month]);
        $ids = $q->fetchAll(PDO::FETCH_COLUMN, 0);
        if (empty($ids)) {
            if ($db->inTransaction()) $db->rollBack();
            json_out(['success' => false, 'error' => '该月份无工资条数据']);
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        // 删除前固化反馈的年月归属：工资条删除后 salary_id 失效，回填 salary_year/month 避免反馈被误归入"通用反馈"
        $db->prepare(
            "UPDATE feedback SET salary_year = ?, salary_month = ? WHERE salary_id IN ($in) AND salary_year IS NULL"
        )->execute(array_merge([$year, $month], $ids));
        // 软删除：子表 salary_items 不动，跟随主表可见性
        $db->prepare("UPDATE salary SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ? WHERE year=? AND month=? AND deleted_at IS NULL")->execute([$opUser, $year, $month]);
        $db->commit();
        audit_log('delete_salary', 'salary:' . sprintf('%04d/%02d', $year, $month), 'ok', [
            'scope' => 'month', 'count' => count($ids),
        ]);
        json_out(['success' => true, 'deleted' => count($ids), 'scope' => 'month']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        json_out(['success' => false, 'error' => '删除失败：' . $e->getMessage()]);
    }
}

/**
 * 下载工资条 Excel 模板
 * GET /api/admin/salary_template
 */
function handle_admin_salary_template() {
    require_admin_session([ROLE_ADMIN, ROLE_FINANCE]);
    if (!class_exists('ZipArchive')) {
        json_out(['success' => false, 'error' => '服务器未启用 ZipArchive 扩展']);
    }

    $year = date('Y');
    $month = (int)date('m');
    $ymText = sprintf("【%04d年%02d月薪资】", $year, $month);
    $tmpFile = tempnam(sys_get_temp_dir(), 'salary_tpl_');
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== TRUE) {
        @unlink($tmpFile);
        json_out(['success' => false, 'error' => '无法创建模板文件']);
    }

    // 共享字符串：0=标题, 1=姓名, 2=基本工资, 3=绩效工资, 4=加班工资, 5=社保个人, 6=公积金个人, 7=个税, 8=实发工资
    $headers = ['姓名', '基本工资', '绩效工资', '加班工资', '社保个人', '公积金个人', '代扣个税', '实发工资'];
    $allStrings = array_merge([$ymText], $headers);
    $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $sharedXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($allStrings) . '" uniqueCount="' . count($allStrings) . '">';
    foreach ($allStrings as $s) {
        $sharedXml .= '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
    }
    $sharedXml .= '</sst>';

    $lastCol = count($headers); // 8 列 (A-H)
    $lastColLetter = chr(64 + $lastCol); // 'H'
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $sheetXml .= '<mergeCells><mergeCell ref="A1:' . $lastColLetter . '1"/></mergeCells>';
    $sheetXml .= '<sheetData>';
    // 行1：合并标题
    $sheetXml .= '<row r="1"><c r="A1" t="s"><v>0</v></c>';
    for ($i = 1; $i < $lastCol; $i++) {
        $colLetter = chr(65 + $i);
        $sheetXml .= '<c r="' . $colLetter . '1"/>';
    }
    $sheetXml .= '</row>';
    // 行2：表头
    $sheetXml .= '<row r="2">';
    for ($i = 0; $i < $lastCol; $i++) {
        $colLetter = chr(65 + $i);
        $sheetXml .= '<c r="' . $colLetter . '2" t="s"><v>' . ($i + 1) . '</v></c>';
    }
    $sheetXml .= '</row>';
    // 行3：示例数据
    $sampleData = ['张三', '8000', '2000', '500', '600', '800', '120', '8980'];
    $sheetXml .= '<row r="3">';
    for ($i = 0; $i < $lastCol; $i++) {
        $colLetter = chr(65 + $i);
        $sheetXml .= '<c r="' . $colLetter . '3"><v>' . htmlspecialchars($sampleData[$i], ENT_XML1) . '</v></c>';
    }
    $sheetXml .= '</row>';
    $sheetXml .= '</sheetData></worksheet>';

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="salary_template.xlsx"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    @unlink($tmpFile);
    exit;
}

/**
 * 工资条回收站：列表 / 恢复 / 彻底删除
 *   GET  /api/admin/salary/trash_list              回收站列表
 *   POST /api/admin/salary/restore  {id} 或 {ym}   恢复（单条或整月）
 *   POST /api/admin/salary/purge    {id} 或 {ym}   彻底删除（单条或整月）
 * 权限：仅 admin/finance（与删除权限一致）
 */
function handle_admin_salary_trash_list() {
    require_admin_session([ROLE_FINANCE, ROLE_ADMIN]);
    $db = get_db();
    // 仅返回已软删记录，按删除时间倒序
    $stmt = $db->query(
        "SELECT s.id, s.userid,
                CASE WHEN s.name = '' OR s.name = s.userid THEN COALESCE(NULLIF(u.name,''), s.name) ELSE s.name END AS name,
                s.year, s.month, s.real_pay, s.status, s.confirmed,
                CASE WHEN s.dept_name = '' OR s.dept_name = '未分配' THEN COALESCE(NULLIF(d.name,''), s.dept_name) ELSE s.dept_name END AS dept_name,
                s.deleted_at, s.deleted_by
         FROM salary s
         LEFT JOIN users u ON s.userid = u.userid
         LEFT JOIN departments d ON u.dept_id = d.dept_id
         WHERE s.deleted_at IS NOT NULL
         ORDER BY s.deleted_at DESC, s.year DESC, s.month DESC"
    );
    $list = $stmt->fetchAll();
    foreach ($list as &$row) {
        $row['dept_name'] = normalize_dept_name($row['dept_name'] ?? '');
        $row['ym'] = sprintf('%04d%02d', (int)$row['year'], (int)$row['month']);
    }
    unset($row);
    json_out(['success' => true, 'list' => $list]);
}

function handle_admin_salary_restore() {
    require_admin_session([ROLE_FINANCE, ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $db = get_db();
    $id = (int)param('id', 0);
    $ym = (string)param('ym', '');

    try {
        $db->beginTransaction();
        if ($id > 0) {
            // 单条恢复
            $db->prepare("UPDATE salary SET deleted_at = NULL, deleted_by = NULL WHERE id = ? AND deleted_at IS NOT NULL")->execute([$id]);
            $count = 1;
            $target = 'salary:' . $id;
        } elseif (strlen($ym) === 6 && ctype_digit($ym)) {
            // 整月恢复
            $year = (int)substr($ym, 0, 4);
            $month = (int)substr($ym, 4, 2);
            $stmt = $db->prepare("UPDATE salary SET deleted_at = NULL, deleted_by = NULL WHERE year = ? AND month = ? AND deleted_at IS NOT NULL");
            $stmt->execute([$year, $month]);
            $count = $stmt->rowCount();
            $target = 'salary:' . sprintf('%04d%02d', $year, $month);
        } else {
            if ($db->inTransaction()) $db->rollBack();
            json_out(['success' => false, 'error' => '参数错误：需提供 id 或 ym']);
        }
        $db->commit();
        audit_log('restore_salary', $target, 'ok', ['count' => $count]);
        json_out(['success' => true, 'restored' => $count]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        json_out(['success' => false, 'error' => '恢复失败：' . $e->getMessage()]);
    }
}

function handle_admin_salary_purge() {
    require_admin_session([ROLE_FINANCE, ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $db = get_db();
    $id = (int)param('id', 0);
    $ym = (string)param('ym', '');

    try {
        $db->beginTransaction();
        if ($id > 0) {
            // 单条彻底删除：先删子表，再删主表
            $db->prepare("DELETE FROM salary_items WHERE salary_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM salary WHERE id = ? AND deleted_at IS NOT NULL")->execute([$id]);
            $count = 1;
            $target = 'salary:' . $id;
        } elseif (strlen($ym) === 6 && ctype_digit($ym)) {
            // 整月彻底删除
            $year = (int)substr($ym, 0, 4);
            $month = (int)substr($ym, 4, 2);
            // 先查出所有待删除 id，用于删子表
            $q = $db->prepare("SELECT id FROM salary WHERE year = ? AND month = ? AND deleted_at IS NOT NULL");
            $q->execute([$year, $month]);
            $ids = $q->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!empty($ids)) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("DELETE FROM salary_items WHERE salary_id IN ($in)")->execute($ids);
                $db->prepare("DELETE FROM salary WHERE year = ? AND month = ? AND deleted_at IS NOT NULL")->execute([$year, $month]);
            }
            $count = count($ids);
            $target = 'salary:' . sprintf('%04d%02d', $year, $month);
        } else {
            if ($db->inTransaction()) $db->rollBack();
            json_out(['success' => false, 'error' => '参数错误：需提供 id 或 ym']);
        }
        $db->commit();
        audit_log('purge_salary', $target, 'ok', ['count' => $count]);
        json_out(['success' => true, 'purged' => $count]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        json_out(['success' => false, 'error' => '彻底删除失败：' . $e->getMessage()]);
    }
}
