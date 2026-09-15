<?php
/**
 * 反馈接口
 *   feedback/my        我的反馈列表（数组，含 replies）
 *   feedback/pending   待处理/全部反馈列表（HR 用，数组）
 *   feedback/submit    员工提交反馈（POST {year_month,content}）
 *   feedback/reply     HR 回复（POST {feedback_id,content}）
 *   feedback/delete    管理员删除反馈（POST {feedback_id}，仅 ADMIN）
 *
 * 反馈负责人分配（assignments 表：assign_type=user/dept/all, target_id, hr_userid）
 */

function get_feedback_assignee($userid) {
    $db = get_db();
    $dept = $db->prepare("SELECT dept_id FROM users WHERE userid = ?");
    $dept->execute([$userid]);
    $row = $dept->fetch();
    $deptId = $row ? (int)$row['dept_id'] : 0;

    // 用户级负责人优先
    $a2 = $db->prepare("SELECT hr_userid FROM assignments WHERE assign_type='user' AND target_id = ? LIMIT 1");
    $a2->execute([$userid]);
    $r2 = $a2->fetch();
    if ($r2 && $r2['hr_userid']) {
        return $r2['hr_userid'];
    }
    if ($deptId) {
        $a = $db->prepare("SELECT hr_userid FROM assignments WHERE assign_type='dept' AND target_id = ? LIMIT 1");
        $a->execute([$deptId]);
        $r = $a->fetch();
        if ($r && $r['hr_userid']) {
            return $r['hr_userid'];
        }
    }
    $a3 = $db->prepare("SELECT hr_userid FROM assignments WHERE assign_type='all' LIMIT 1");
    $a3->execute();
    $r3 = $a3->fetch();
    if ($r3 && $r3['hr_userid']) {
        return $r3['hr_userid'];
    }
    // 多角色兼容：role 字段可能是逗号分隔的多值（如 "finance,hr"）
    // 用 SQL 一次性查出含 HR 角色的账号，避免全表扫描后内存循环
    $hrStmt = $db->query(
        "SELECT userid, role FROM users
         WHERE role = 'hr' OR role LIKE 'hr,%' OR role LIKE '%,hr' OR role LIKE '%,hr,%'
         ORDER BY CASE
             WHEN role = 'hr' OR role LIKE 'hr,%' OR role LIKE '%,hr' OR role LIKE '%,hr,%' THEN 0
             ELSE 1
         END LIMIT 1"
    );
    foreach ($hrStmt->fetchAll() as $x) {
        if (user_has_any_role($x['role'], [ROLE_HR])) {
            return $x['userid'];
        }
    }
    return null;
}

function handle_feedback_my() {
    $u = require_login();
    require_password($u);
    $db = get_db();
    $stmt = $db->prepare(
        "SELECT f.id, f.content, f.status, f.reply, f.reply_at, f.reply_by, f.created_at,
                COALESCE(s.year, f.salary_year) AS year,
                COALESCE(s.month, f.salary_month) AS month,
                b.id AS bonus_id, b.year AS bonus_year, b.bonus_type,
                ru.name as reply_name
         FROM feedback f
         LEFT JOIN salary s ON f.salary_id = s.id
         LEFT JOIN bonus b ON f.bonus_id = b.id
         LEFT JOIN users ru ON f.reply_by = ru.userid
         WHERE f.userid = ?
         ORDER BY COALESCE(s.year, f.salary_year, 0) DESC,
                  COALESCE(s.month, f.salary_month, 0) DESC,
                  f.created_at DESC"
    );
    $stmt->execute([$u['userid']]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $replies = [];
        if (!empty($r['reply'])) {
            $replies[] = [
                'content' => $r['reply'],
                'created_at' => $r['reply_at'],
                'reply_name' => $r['reply_name'] ?? '',
            ];
        }
        $ym = '';
        if (!empty($r['year']) && !empty($r['month'])) {
            $ym = sprintf('%04d%02d', $r['year'], $r['month']);
        }
        $bonusInfo = null;
        if (!empty($r['bonus_id'])) {
            $bonusInfo = [
                'id' => (int)$r['bonus_id'],
                'year' => (int)$r['bonus_year'],
                'bonus_type' => $r['bonus_type'],
            ];
        }
        $out[] = [
            'id'         => $r['id'],
            'year_month' => $ym,
            'status'     => $r['status'],
            'content'    => $r['content'],
            'created_at' => $r['created_at'],
            'replies'    => $replies,
            'bonus'      => $bonusInfo,
        ];
    }
    json_out($out);
}

function handle_feedback_pending() {
    $u = require_admin_session([ROLE_HR, ROLE_ADMIN]);
    $db = get_db();

    $hasPaging = isset($_GET['page']) || isset($_GET['pageSize']);
    $page     = max(1, (int)param('page', 1));
    $pageSize = max(1, min(500, (int)param('pageSize', 50)));
    $offset   = ($page - 1) * $pageSize;

    if ($hasPaging) {
        $countStmt = $db->prepare(
            "SELECT COUNT(*) AS c
             FROM feedback f
             LEFT JOIN users u ON f.userid = u.userid
             LEFT JOIN salary s ON f.salary_id = s.id
             LEFT JOIN bonus b ON f.bonus_id = b.id"
        );
        $countStmt->execute();
        $total = (int)($countStmt->fetch()['c'] ?? 0);
        $totalPages = $total > 0 ? (int)ceil($total / $pageSize) : 0;
    }

    $sql = "SELECT f.id, f.userid, f.content, f.status, f.reply, f.reply_at, f.created_at,
                u.name as user_name,
                COALESCE(s.year, f.salary_year) AS year,
                COALESCE(s.month, f.salary_month) AS month,
                b.id AS bonus_id, b.year AS bonus_year, b.bonus_type
         FROM feedback f
         LEFT JOIN users u ON f.userid = u.userid
         LEFT JOIN salary s ON f.salary_id = s.id
         LEFT JOIN bonus b ON f.bonus_id = b.id
         ORDER BY f.created_at DESC";
    if ($hasPaging) {
        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$pageSize, $offset]);
    } else {
        $stmt = $db->query($sql);
    }
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $ym = '';
        if (!empty($r['year']) && !empty($r['month'])) {
            $ym = sprintf('%04d%02d', $r['year'], $r['month']);
        }
        $bonusInfo = null;
        if (!empty($r['bonus_id'])) {
            $bonusInfo = [
                'id'         => (int)$r['bonus_id'],
                'year'       => (int)$r['bonus_year'],
                'bonus_type' => $r['bonus_type'],
            ];
        }
        $out[] = [
            'id'         => $r['id'],
            'userid'     => $r['userid'],
            'user_name'  => $r['user_name'],
            'year_month' => $ym,
            'status'     => $r['status'],
            'content'    => $r['content'],
            'reply'      => $r['reply'],
            'reply_at'   => $r['reply_at'],
            'created_at' => $r['created_at'],
            'bonus'      => $bonusInfo,
        ];
    }
    if ($hasPaging) {
        json_out([
            'total'      => $total,
            'page'       => $page,
            'pageSize'   => $pageSize,
            'totalPages' => $totalPages,
            'list'       => $out,
        ]);
    } else {
        json_out($out);
    }
}

function handle_feedback_submit() {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['success' => false, 'error' => 'Method Not Allowed'], 405); return; }
    $db = get_db();
    $ym = (string)param('year_month', '');
    $bonusId = (int)param('bonus_id', 0);
    $content = trim((string)param('content', ''));
    if ($content === '') {
        json_out(['success' => false, 'error' => '反馈内容不能为空']);
        return;
    }
    // 反馈内容长度限制 2000 字
    $contentLen = safe_strlen($content);
    if ($contentLen > 2000) {
        json_out(['success' => false, 'error' => '反馈内容不能超过2000字']);
        return;
    }

    // 奖金反馈：验证 bonus_id 归属当前用户
    $bonusInfo = null;
    if ($bonusId > 0) {
        $bStmt = $db->prepare("SELECT id, year, bonus_type FROM bonus WHERE id = ? AND userid = ? AND deleted_at IS NULL");
        $bStmt->execute([$bonusId, $u['userid']]);
        $bonusInfo = $bStmt->fetch();
        if (!$bonusInfo) {
            json_out(['success' => false, 'error' => '奖金记录不存在或无权操作']);
        }
    }

    // 前端未传月份时，兜底取该用户最新一条工资记录的年份月份，避免误报「月份参数错误」
    if ($bonusId === 0 && ($ym === '' || strlen($ym) !== 6 || !ctype_digit($ym))) {
        $s0 = $db->prepare("SELECT year, month FROM salary WHERE userid = ? AND deleted_at IS NULL ORDER BY year DESC, month DESC LIMIT 1");
        $s0->execute([$u['userid']]);
        $row0 = $s0->fetch();
        if ($row0) {
            $ym = sprintf('%04d%02d', (int)$row0['year'], (int)$row0['month']);
        }
    }
    if ($bonusId === 0 && (strlen($ym) !== 6 || !ctype_digit($ym))) {
        // 允许不选具体月份：直接作为一般性反馈提交，不关联某条工资
        $year = 0;
        $month = 0;
        $sal = false;
    } elseif ($bonusId > 0) {
        // 奖金反馈不关联工资月份
        $year = (int)$bonusInfo['year'];
        $month = 0;
        $sal = false;
    } else {
        $year = (int)substr($ym, 0, 4);
        $month = (int)substr($ym, 4, 2);
        $stmt = $db->prepare("SELECT id FROM salary WHERE userid = ? AND year = ? AND month = ? AND status IN ('sent','confirmed') AND deleted_at IS NULL");
        $stmt->execute([$u['userid'], $year, $month]);
        $sal = $stmt->fetch();
    }
    $assignee = get_feedback_assignee($u['userid']);

    $db->prepare(
        "INSERT INTO feedback (salary_id, bonus_id, userid, content, status, assignee)
         VALUES (?, ?, ?, ?, 'pending', ?)"
    )->execute([$sal ? $sal['id'] : null, $bonusId > 0 ? $bonusId : null, $u['userid'], $content, $assignee]);

    if ($assignee) {
        if ($bonusId > 0) {
            $link = app_base_url() . '/admin/feedback_list';
            $tpl = render_builtin_template('feedback_to_hr_bonus', [
                '姓名'     => $u['name'],
                '账号'     => $u['userid'],
                '年'       => (string)$year,
                '奖金类型' => $bonusInfo['bonus_type'],
                '链接'     => $link,
            ]);
        } else {
            $ymText = ($year > 0 && $month > 0) ? sprintf('%d年%02d月', $year, $month) : '';
            $adminUrl = app_base_url() . '/admin/feedback_list';
            $tpl = render_builtin_template('feedback_to_hr_salary', [
                '姓名'     => $u['name'],
                '账号'     => $u['userid'],
                '年'       => (string)$year,
                '月'       => sprintf('%02d', $month),
                '年月'     => $ymText,
                '链接'     => $adminUrl,
            ]);
            $link = $adminUrl;
        }
        // 查找 assignee 的 wecom_userid
        $aStmt = $db->prepare("SELECT wecom_userid FROM users WHERE userid = ?");
        $aStmt->execute([$assignee]);
        $aRow = $aStmt->fetch();
        $aWecomId = ($aRow && !empty($aRow['wecom_userid'])) ? $aRow['wecom_userid'] : $assignee;
        // 无 wecom_userid 时跳过通知，避免用错误 userid 调用企微 API
        if (empty($aRow['wecom_userid'])) {
            write_log('feedback_notify.log', date('Y-m-d H:i:s') . " feedback_submit assignee={$assignee} result=SKIP reason=no_wecom_userid");
        } else {
            $tplType = $tpl['msgtype'] ?? 'template_card';
            $notifyOk = false;
            if ($tplType === 'template_card') {
                $r = wecom_send_template_card($aWecomId, $tpl['title'] ?: '新的反馈待处理', $tpl['content'], $link);
                if (!$r['ok']) {
                    $notifyOk = (bool)wecom_send_markdown($aWecomId, $tpl['content']);
                } else {
                    $notifyOk = true;
                }
            } elseif ($tplType === 'markdown') {
                $notifyOk = (bool)wecom_send_markdown($aWecomId, $tpl['content']);
            } else {
                $notifyOk = (bool)wecom_send_text($aWecomId, $tpl['content']);
            }
            if (!$notifyOk) {
                write_log('feedback_notify.log', date('Y-m-d H:i:s') . " feedback_submit assignee={$assignee} wecom_userid={$aWecomId} result=FAIL");
            }
        }
    }
    // 忽略通知发送结果，不影响提交成功
    audit_log('submit_feedback', 'user:' . $u['userid'], 'ok', [
        'year' => $year, 'month' => $month, 'salary_id' => $sal ? $sal['id'] : null,
        'content_len' => strlen($content),
    ]);
    json_out(['success' => true]);
}

function handle_feedback_reply() {
    $u = require_admin_session([ROLE_HR, ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['success' => false, 'error' => 'Method Not Allowed'], 405); return; }
    $id = (int)param('feedback_id');
    $reply = trim((string)param('content', ''));
    if ($id <= 0 || $reply === '') {
        json_out(['success' => false, 'error' => '参数错误']);
    }
    $db = get_db();
    $stmt = $db->prepare(
        "SELECT f.userid, f.assignee, f.bonus_id,
                COALESCE(s.year, f.salary_year) AS year,
                COALESCE(s.month, f.salary_month) AS month,
                COALESCE(b.year, f.bonus_year) AS bonus_year,
                COALESCE(b.bonus_type, f.bonus_type) AS bonus_type,
                u.admin_login, u.name, u.wecom_userid
         FROM feedback f
         LEFT JOIN salary s ON f.salary_id = s.id
         LEFT JOIN bonus b ON f.bonus_id = b.id
         LEFT JOIN users u ON f.userid = u.userid
         WHERE f.id = ?"
    );
    $stmt->execute([$id]);
    $fb = $stmt->fetch();
    if (!$fb) {
        json_out(['success' => false, 'error' => '反馈不存在']);
    }
    // 分配范围校验：ADMIN 可回复任何反馈，HR 只能回复 assignee 为自己的反馈
    if ($fb['assignee'] !== $u['userid'] && !user_has_any_role($u['role'] ?? '', [ROLE_ADMIN])) {
        json_out(['success' => false, 'error' => '无权限回复该反馈']);
    }
    $db->prepare(
        "UPDATE feedback SET reply = ?, status = 'replied', reply_at = ?, reply_by = ?, assignee = ? WHERE id = ?"
    )->execute([$reply, date('Y-m-d H:i:s'), $u['userid'], $u['userid'], $id]);

    // 发送企微通知给员工：优先使用 wecom_userid（企微真实userid），回退到 userid
    $notifyUserid = $fb['wecom_userid'] ?: $fb['userid'];
    $fbUrl = app_base_url() . "/employee/feedback#feedback-{$id}";

    // 根据反馈关联类型选择对应模板：bonus_id 或 bonus_year 有值则为奖金反馈
    $isBonusFeedback = (!empty($fb['bonus_id']) || !empty($fb['bonus_year']));

    if (empty($notifyUserid)) {
        write_log('feedback_notify.log', date('Y-m-d H:i:s') . " feedback_reply id={$id} userid={$fb['userid']} result=SKIP reason=no_wecom_userid");
        json_out(['success' => true, 'notify' => 'skip', 'notify_err' => '该用户未绑定企业微信，无法发送通知']);
    }

    if ($isBonusFeedback) {
        $replyTpl = render_builtin_template('feedback_reply_bonus', [
            '年'       => (string)($fb['bonus_year'] ?? ''),
            '奖金类型' => $fb['bonus_type'] ?? '',
            '姓名'     => $fb['name'] ?? '',
            '链接'     => $fbUrl,
        ]);
        $fallbackTitle = '反馈已回复 ' . ($fb['bonus_year'] ?? '') . '年' . ($fb['bonus_type'] ?? '');
    } else {
        $ymText = (!empty($fb['year']) && !empty($fb['month'])) ? sprintf(' %d年%02d月', $fb['year'], (int)$fb['month']) : '';
        $replyTpl = render_builtin_template('feedback_reply_salary', [
            '年'   => (string)($fb['year'] ?? ''),
            '月'   => !empty($fb['month']) ? sprintf('%02d', $fb['month']) : '',
            '年月' => ltrim($ymText),
            '姓名' => $fb['name'] ?? '',
            '链接' => $fbUrl,
        ]);
        $fallbackTitle = '反馈已回复' . $ymText;
    }
    $replyType = $replyTpl['msgtype'] ?? 'template_card';
    if ($replyType === 'template_card') {
        $notifyResult = wecom_send_template_card(
            $notifyUserid,
            $replyTpl['title'] ?: $fallbackTitle,
            $replyTpl['content'],
            $fbUrl
        );
        if (!$notifyResult['ok']) {
            // 卡片发送失败时降级为 markdown
            $mdOk = wecom_send_markdown($notifyUserid, $replyTpl['content']);
            $notifyResult = ['ok' => $mdOk, 'err' => $mdOk ? '' : 'markdown 降级发送失败'];
        }
    } elseif ($replyType === 'markdown') {
        $mdOk = wecom_send_markdown($notifyUserid, $replyTpl['content']);
        $notifyResult = ['ok' => $mdOk, 'err' => $mdOk ? '' : 'markdown 发送失败'];
    } else { // text
        $txtOk = wecom_send_text($notifyUserid, $replyTpl['content']);
        $notifyResult = ['ok' => $txtOk, 'err' => $txtOk ? '' : 'text 发送失败'];
    }

    // 记录通知发送日志
    $notifyStatus = $notifyResult['ok'] ? 'OK' : 'FAIL';
    $notifyErr = $notifyResult['ok'] ? '' : ($notifyResult['err'] ?? '');
    write_log('feedback_notify.log', date('Y-m-d H:i:s') . " feedback_reply id={$id} userid={$fb['userid']} wecom_userid={$notifyUserid} result={$notifyStatus} err={$notifyErr}");

    audit_log('reply_feedback', 'feedback:' . $id, 'ok', [
        'by' => $u['userid'], 'to_user' => $fb['userid'], 'reply_len' => strlen($reply),
    ]);
    json_out(['success' => true, 'notify' => $notifyResult['ok'] ? 'ok' : 'fail', 'notify_err' => $notifyErr]);
}

function handle_feedback_delete() {
    // 仅管理员可删除反馈：HR 只能回复不能删除，避免误删留痕数据
    $u = require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['success' => false, 'error' => 'Method Not Allowed'], 405); return; }
    $id = (int)param('feedback_id');
    if ($id <= 0) {
        json_out(['success' => false, 'error' => '参数错误']);
    }
    $db = get_db();
    $stmt = $db->prepare("SELECT id FROM feedback WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        json_out(['success' => false, 'error' => '反馈不存在']);
    }
    $db->prepare("DELETE FROM feedback WHERE id = ?")->execute([$id]);
    audit_log('delete_feedback', 'feedback:' . $id, 'ok', ['by' => $u['userid']]);
    json_out(['success' => true, 'deleted' => $id]);
}
