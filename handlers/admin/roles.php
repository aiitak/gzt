<?php
/**
 * 角色管理接口：角色列表/分配/移除、反馈负责人（HR）分配
 */

function handle_admin_roles_list() {
    require_admin_session([ROLE_ADMIN]);
    $db = get_db();
    $users = $db->query("SELECT userid, name, dept_id, role, has_set_password FROM users")->fetchAll();
    // 仅返回拥有后台角色（admin/finance/hr）的人员，并展开其全部角色
    $out = [];
    foreach ($users as $x) {
        $rs = user_roles_array($x['role']);
        if (empty($rs)) {
            continue;
        }
        $out[] = [
            'userid'           => $x['userid'],
            'name'             => $x['name'],
            'dept_id'          => $x['dept_id'],
            'roles'            => $rs,
            'has_set_password' => (bool)$x['has_set_password'],
        ];
    }
    json_out($out);
}

function handle_admin_roles_assign() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['success' => false, 'error' => 'Method Not Allowed'], 405); return; }
    $userid = trim((string)param('userid', ''));
    $role = trim((string)param('role_type', ''));
    if ($userid === '' || $role === '') {
        json_out(['success' => false, 'error' => '参数缺失']);
    }
    if (!in_array($role, [ROLE_ADMIN, ROLE_FINANCE, ROLE_HR], true)) {
        json_out(['success' => false, 'error' => '角色类型不支持']);
    }
    $db = get_db();
    $stmt = $db->prepare("SELECT role FROM users WHERE userid = ?");
    $stmt->execute([$userid]);
    $row = $stmt->fetch();
    if (!$row) {
        json_out(['success' => false, 'error' => '该成员不存在']);
    }
    $cur = user_roles_array($row['role']);
    if (!in_array($role, $cur, true)) {
        $cur[] = $role;
    }
    $newRole = implode(',', $cur);
    $db->prepare("UPDATE users SET role = ? WHERE userid = ?")->execute([$newRole, $userid]);
    audit_log('role_change', 'user:' . $userid, 'ok', ['assign' => $role, 'after' => $newRole]);
    json_out(['success' => true]);
}

function handle_admin_roles_remove() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['success' => false, 'error' => 'Method Not Allowed'], 405); return; }
    $userid = trim((string)param('userid', ''));
    $role = trim((string)param('role_type', ''));
    if ($userid === '' || $role === '') {
        json_out(['success' => false, 'error' => '参数缺失']);
    }
    $db = get_db();
    $stmt = $db->prepare("SELECT role FROM users WHERE userid = ?");
    $stmt->execute([$userid]);
    $row = $stmt->fetch();
    if (!$row) {
        json_out(['success' => false, 'error' => '该成员不存在']);
    }
    // 保护：移除管理员角色时，至少保留一个管理员
    // 用原子 SQL 避免 TOCTOU 竞态（先 SELECT 计数再 UPDATE，并发互移可能都通过检查导致 0 管理员）
    if ($role === ROLE_ADMIN) {
        // 子查询统计其他管理员数量（排除当前待移除的用户），WHERE 条件保证仅当仍有其他管理员时才更新
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE userid != ? AND (role = ? OR role LIKE ? OR role LIKE ? OR role LIKE ?)");
        $cntStmt->execute([$userid, ROLE_ADMIN, ROLE_ADMIN . ',%', '%,' . ROLE_ADMIN, '%,' . ROLE_ADMIN . ',%']);
        $otherAdmins = (int)$cntStmt->fetchColumn();
        if ($otherAdmins < 1) {
            json_out(['success' => false, 'error' => '系统至少需保留一个管理员，无法移除最后一个管理员']);
        }
    }
    $cur = array_values(array_diff(user_roles_array($row['role']), [$role]));
    $newRole = $cur ? implode(',', $cur) : 'employee';
    // 加 AND 原子条件：仅在当前 role 仍含该角色时更新（并发场景下若已被其他请求改过则 rowCount=0）
    $upd = $db->prepare("UPDATE users SET role = ? WHERE userid = ? AND (role = ? OR role LIKE ? OR role LIKE ? OR role LIKE ?)");
    $upd->execute([$newRole, $userid, $role, $role . ',%', '%,' . $role, '%,' . $role . ',%']);
    audit_log('role_change', 'user:' . $userid, 'ok', ['remove' => $role, 'after' => $newRole]);
    json_out(['success' => true]);
}

function handle_admin_assignments_hr() {
    require_admin_session([ROLE_ADMIN]);
    $db = get_db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $type = param('assign_type', 'user');
        if (!in_array($type, ['user', 'dept', 'all'], true)) {
            json_out(['success' => false, 'error' => '分配类型参数错误']);
        }
        $target = trim((string)param('target_id', ''));
        $hrUserids = param('hr_userids', '');
        // 兼容旧格式：单个人事
        if (is_string($hrUserids)) {
            $hrUserids = $hrUserids !== '' ? [$hrUserids] : [];
        }
        $hrUserids = array_values(array_filter(array_map('trim', (array)$hrUserids)));
        if ($target === '' || empty($hrUserids)) {
            json_out(['success' => false, 'error' => '请填写完整']);
        }
        // 校验：所有人事负责人必须拥有「人事」角色
        $hrList = [];
        $chk = $db->prepare("SELECT userid, role FROM users WHERE userid = ?");
        foreach ($hrUserids as $hr) {
            $chk->execute([$hr]);
            $urow = $chk->fetch();
            if (!$urow || !in_array(ROLE_HR, user_roles_array($urow['role']), true)) {
                json_out(['success' => false, 'error' => '「' . $hr . '」没有人事角色，请先在「角色分配」中为其分配人事角色']);
            }
            $hrList[] = $hr;
        }
        // 插入新分配（不删除已有记录，支持同一目标分配多个人事）
        // 先检查是否已存在，避免重复插入
        $count = 0;
        $db->beginTransaction();
        try {
            $chkExists = $db->prepare("SELECT id FROM assignments WHERE assign_type = ? AND target_id = ? AND hr_userid = ? LIMIT 1");
            $ins = $db->prepare("INSERT INTO assignments (assign_type, target_id, hr_userid) VALUES (?,?,?)");
            foreach ($hrList as $hr) {
                $chkExists->execute([$type, $target, $hr]);
                if ($chkExists->fetch()) continue;
                $ins->execute([$type, $target, $hr]);
                $count++;
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            json_out(['success' => false, 'error' => '保存失败：' . $e->getMessage()]);
        }
        // 审计日志
        audit_log('assignment_hr', 'assignment:' . $type . ':' . $target, 'ok', ['hr_count' => $count, 'hr_list' => $hrList]);
        json_out(['success' => true, 'count' => $count]);
    }
    $stmt = $db->query("SELECT * FROM assignments ORDER BY id");
    $rows = $stmt->fetchAll();
    $out = [];
    if (empty($rows)) {
        json_out($out);
    }
    // 批量收集所有需要查询的 userid / dept_id，避免 N+1 循环查询
    $userIds = [];
    $deptIds = [];
    foreach ($rows as $a) {
        if ($a['assign_type'] === 'user') {
            $userIds[] = $a['target_id'];
        } elseif ($a['assign_type'] === 'dept') {
            $deptIds[] = (int)$a['target_id'];
        }
        $userIds[] = $a['hr_userid'];
    }
    $userNameMap = [];
    $deptNameMap = [];
    if (!empty($userIds)) {
        $userIds = array_values(array_unique($userIds));
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $us = $db->prepare("SELECT userid, name FROM users WHERE userid IN ($ph)");
        $us->execute($userIds);
        foreach ($us->fetchAll() as $r) {
            $userNameMap[$r['userid']] = $r['name'];
        }
    }
    if (!empty($deptIds)) {
        $deptIds = array_values(array_unique($deptIds));
        $ph = implode(',', array_fill(0, count($deptIds), '?'));
        $ds = $db->prepare("SELECT dept_id, name FROM departments WHERE dept_id IN ($ph)");
        $ds->execute($deptIds);
        foreach ($ds->fetchAll() as $r) {
            $deptNameMap[(int)$r['dept_id']] = $r['name'];
        }
    }
    foreach ($rows as $a) {
        $targetName = $a['target_id'];
        if ($a['assign_type'] === 'user') {
            $targetName = $userNameMap[$a['target_id']] ?? $targetName;
        } elseif ($a['assign_type'] === 'dept') {
            $targetName = $deptNameMap[(int)$a['target_id']] ?? $targetName;
        }
        $hrName = $userNameMap[$a['hr_userid']] ?? $a['hr_userid'];
        $out[] = [
            'id'          => $a['id'],
            'assign_type' => $a['assign_type'],
            'target_id'   => $a['target_id'],
            'target_name' => $targetName,
            'hr_userid'   => $a['hr_userid'],
            'hr_name'     => $hrName,
        ];
    }
    json_out($out);
}

/**
 * 获取所有拥有「人事」角色的成员列表（供反馈负责人分配时选择）
 *   GET /api/admin/hr_users
 */
function handle_admin_hr_users() {
    require_admin_session([ROLE_ADMIN, ROLE_FINANCE, ROLE_HR]);
    $db = get_db();
    $users = $db->query("SELECT userid, name, role FROM users")->fetchAll();
    $out = [];
    foreach ($users as $u) {
        if (in_array(ROLE_HR, user_roles_array($u['role']), true)) {
            $out[] = ['userid' => $u['userid'], 'name' => $u['name']];
        }
    }
    json_out(['success' => true, 'data' => $out]);
}

/**
 * 删除反馈负责人分配
 *   POST /api/admin/assignments/delete  body: {id}
 */
function handle_admin_assignments_delete() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $db = get_db();
    $id = (int)param('id', 0);
    if ($id <= 0) {
        json_out(['success' => false, 'error' => '参数错误']);
    }
    $db->prepare("DELETE FROM assignments WHERE id = ?")->execute([$id]);
    audit_log('assignment_delete', 'assignment:' . $id);
    json_out(['success' => true]);
}
