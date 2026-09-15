<?php
/**
 * 通讯录相关接口：同步部门/成员/标签、通讯录数据查询
 */

function handle_admin_sync_department() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['success' => false, 'error' => 'Method Not Allowed'], 405); return; }
    try {
        $res = wecom_get_departments();
        if (!$res['ok']) {
            json_out(['success' => false, 'error' => $res['errmsg'], 'errcode' => $res['errcode'] ?? null]);
            return;
        }
        $depts = $res['data'];
        if (!is_array($depts)) {
            json_out(['success' => false, 'error' => '通讯录接口返回数据格式异常（缺少 department 字段）']);
            return;
        }
        $db = get_db();
        $ins = $db->prepare(db_upsert_sql(
            'departments',
            ['dept_id', 'name', 'parent_id'],
            'dept_id'
        ));
        $n = 0;
        $db->beginTransaction();
        try {
            foreach ($depts as $d) {
                $deptId = (int)($d['id'] ?? 0);
                if ($deptId <= 0) { continue; } // 跳过缺 id 的异常部门，避免约束报错中断整次同步
                $name   = isset($d['name']) ? (string)$d['name'] : '';
                $parent = (int)($d['parentid'] ?? 0);
                $ins->execute([$deptId, $name, $parent]);
                $n++;
            }
            $db->commit();
        } catch (Throwable $te) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $te;
        }
        audit_log('sync_contacts', '', 'ok', ['type' => 'department', 'count' => $n]);
        json_out(['success' => true, 'message' => '同步部门 ' . $n . ' 个']);
    } catch (\Throwable $e) {
        json_out(['success' => false, 'error' => '同步部门失败：' . $e->getMessage()]);
    }
}

function handle_admin_sync_user() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['success' => false, 'error' => 'Method Not Allowed'], 405); return; }
    try {
        // 1) 先拉部门（保证 dept 名称存在）
        $dres = wecom_get_departments();
        if (!$dres['ok']) {
            json_out(['success' => false, 'error' => $dres['errmsg'], 'errcode' => $dres['errcode'] ?? null]);
            return;
        }
        $db = get_db();
        $db->beginTransaction();
        $insDept = $db->prepare(db_upsert_sql(
            'departments',
            ['dept_id', 'name', 'parent_id'],
            'dept_id'
        ));
        foreach (($dres['data'] ?? []) as $d) {
            $deptId = (int)($d['id'] ?? 0);
            if ($deptId <= 0) { continue; }
            $insDept->execute([$deptId, isset($d['name']) ? (string)$d['name'] : '', (int)($d['parentid'] ?? 0)]);
        }
        // 2) 用根部门 + fetch_child=1 一次性拉全量成员，避免嵌套部门遗漏、也避免重复计数
        $ures = wecom_get_department_users(1);
        if (!$ures['ok']) {
            if ($db->inTransaction()) { $db->rollBack(); }
            json_out(['success' => false, 'error' => $ures['errmsg'], 'errcode' => $ures['errcode'] ?? null]);
            return;
        }
        $insUser = $db->prepare(db_upsert_sql(
            'users',
            ['userid', 'name', 'dept_id', 'role', 'wecom_userid'],
            'userid',
            ['name', 'dept_id', 'wecom_userid']   // 绝不覆盖 role（避免同步后管理员被重置为 employee）
        ));
        $synced = 0;
        $syncedUserids = [];
        foreach (($ures['data'] ?? []) as $m) {
            $userid = trim((string)($m['userid'] ?? ''));
            if ($userid === '') { continue; } // 跳过缺 userid 的异常成员
            $dept_id = is_array($m['department'] ?? null) ? (int)($m['department'][0] ?? 1) : 1;
            // 先检查是否已有 userid 不同的记录占用了相同 wecom_userid
            $ckStmt = $db->prepare("SELECT userid FROM users WHERE wecom_userid = ? AND userid <> ? LIMIT 1");
            $ckStmt->execute([$userid, $userid]);
            $ckRow = $ckStmt->fetch();
            if ($ckRow) {
                // 管理员已绑定企微，只更新不插入
                $db->prepare("UPDATE users SET name=?, dept_id=?, wecom_userid=? WHERE userid=?")
                   ->execute([isset($m['name']) ? (string)$m['name'] : '', $dept_id, $userid, $ckRow['userid']]);
            } else {
                $insUser->execute([
                    $userid,
                    isset($m['name']) ? (string)$m['name'] : '',
                    $dept_id,
                    'employee',
                    $userid   // 企微 userid 同时写入 wecom_userid，后续推送消息/登录匹配都以它为准
                ]);
            }
            $syncedUserids[$userid] = true;
            $synced++;
        }
        // 3) 将企微已不存在（离职/删除）的员工标记为「已离职」
        //    排除后台管理员（admin/finance/hr），防止因不在企微根部门可见范围而被误标离职
        $allWecomUsers = $db->query("SELECT userid, wecom_userid, role FROM users WHERE wecom_userid IS NOT NULL AND userid NOT LIKE 'HIST_%'")->fetchAll();
        $resignedDeptId = find_or_create_dept_by_name($db, '已离职');
        $resigned = 0;
        foreach ($allWecomUsers as $ru) {
            // 跳过拥有后台角色的用户，避免管理员被误标离职
            if (user_has_any_role($ru['role'], [ROLE_ADMIN, ROLE_FINANCE, ROLE_HR])) {
                continue;
            }
            if (!isset($syncedUserids[$ru['wecom_userid']])) {
                $db->prepare("UPDATE users SET wecom_userid = NULL, dept_id = ? WHERE userid = ?")
                   ->execute([$resignedDeptId, $ru['userid']]);
                $resigned++;
            }
        }
        $db->commit();
        audit_log('sync_contacts', '', 'ok', ['type' => 'user', 'synced' => $synced, 'resigned' => $resigned]);
        json_out(['success' => true, 'message' => "同步成员 {$synced} 人" . ($resigned > 0 ? "，标记离职 {$resigned} 人" : '')]);
    } catch (\Throwable $e) {
        $db = get_db();
        if ($db->inTransaction()) { $db->rollBack(); }
        json_out(['success' => false, 'error' => '同步成员失败：' . $e->getMessage()]);
    }
}

/**
 * 同步企业微信标签到 tags 表
 */
function handle_admin_sync_tag() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['success' => false, 'error' => 'Method Not Allowed'], 405); return; }
    try {
        $res = wecom_get_tags();
        if (!$res['ok']) {
            json_out(['success' => false, 'error' => $res['errmsg'], 'errcode' => $res['errcode'] ?? null]);
            return;
        }
        $tags = $res['data'];
        if (!is_array($tags)) {
            json_out(['success' => false, 'error' => '通讯录接口返回数据格式异常（缺少 taglist 字段）']);
            return;
        }
        $db = get_db();
        // 整体事务：先拉所有标签数据到内存，全部成功后再原子替换 tag_users 表，避免中途失败导致数据丢失
        $db->beginTransaction();
        try {
            $db->exec('DELETE FROM tag_users');
            $memberTotal = 0;
            $insTag  = $db->prepare(db_upsert_sql('tags', ['tag_id', 'tagname'], 'tag_id'));
            $insMem  = $db->prepare(db_upsert_sql('tag_users', ['tag_id', 'userid', 'name'], 'tag_id,userid'));
            foreach ($tags as $t) {
                $tagid = (int)($t['tagid'] ?? 0);
                if ($tagid <= 0) { continue; }
                $tagname = isset($t['tagname']) ? (string)$t['tagname'] : '';
                $insTag->execute([$tagid, $tagname]);
                // 同步该标签的成员明细
                $d = wecom_get_tag_detail($tagid);
                if ($d['ok']) {
                    foreach (($d['userlist'] ?? []) as $u) {
                        $muid = trim((string)($u['userid'] ?? ''));
                        if ($muid === '') { continue; }
                        $insMem->execute([$tagid, $muid, isset($u['name']) ? (string)$u['name'] : '']);
                        $memberTotal++;
                    }
                }
            }
            $db->commit();
        } catch (Throwable $te) {
            $db->rollBack();
            throw $te;
        }
        $dbCount = $db->query("SELECT COUNT(*) FROM tags")->fetchColumn();
        $msg = '同步标签 ' . count($tags) . ' 个（接口返回），已入库 ' . $dbCount . ' 条，成员 ' . $memberTotal . ' 条';
        if (count($tags) > 0 && $dbCount == 0) {
            json_out(['success' => false, 'error' => '接口返回了 ' . count($tags) . ' 个标签，但写入数据库失败，请检查数据库文件权限或磁盘空间。']);
            return;
        }
        // 通讯录同步会全量覆盖 tag_users 表，需审计以追踪谁触发了大范围数据替换
        audit_log('sync_contacts', '', 'ok', ['type' => 'tag', 'tags' => count($tags), 'members' => $memberTotal]);
        json_out(['success' => true, 'message' => $msg]);
    } catch (\Throwable $e) {
        json_out(['success' => false, 'error' => '同步标签失败：' . $e->getMessage()]);
    }
}

/**
 * 通讯录数据：部门 / 标签 / 成员（供通讯录页与发送选人使用）
 */
function handle_admin_contacts() {
    require_admin_session([ROLE_ADMIN, ROLE_FINANCE, ROLE_HR]);
    try {
        $db = get_db();
        $departments = $db->query("SELECT dept_id, name, parent_id FROM departments WHERE name NOT IN ('已离职','未匹配') ORDER BY dept_id")->fetchAll();
        foreach ($departments as &$d) {
            $d['name'] = normalize_dept_name($d['name']);
        }
        unset($d);
        $tags = $db->query("SELECT tag_id, tagname FROM tags ORDER BY tag_id")->fetchAll();
        $users = $db->query(
            "SELECT u.userid, u.name, u.dept_id, d.name AS dept_name, u.wecom_userid, u.role
             FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id
             WHERE u.userid NOT LIKE 'HIST_%'
               AND u.wecom_userid IS NOT NULL
               AND COALESCE(d.name, '') NOT IN ('已离职','未匹配')
             ORDER BY u.dept_id, u.name"
        )->fetchAll();
        foreach ($users as &$u) {
            $u['dept_name'] = normalize_dept_name($u['dept_name'] ?? '');
        }
        unset($u);
        // 每个标签的成员（用于标签面板展示）；tag_users 缺失时不阻断整体加载
        $tagMembers = [];
        try {
            $rows = $db->query("SELECT tag_id, userid, name FROM tag_users ORDER BY tag_id, name")->fetchAll();
            foreach ($rows as $r) {
                $tagMembers[$r['tag_id']][] = ['userid' => $r['userid'], 'name' => $r['name']];
            }
        } catch (\Throwable $e) {
            // tag_users 表不存在时成员留空，不影响标签/部门/成员展示
        }
        json_out([
            'departments' => $departments,
            'tags'        => $tags,
            'users'       => $users,
            'tag_members' => $tagMembers,
        ]);
    } catch (\Throwable $e) {
        json_out(['success' => false, 'error' => '通讯录加载失败：' . $e->getMessage()]);
    }
}
