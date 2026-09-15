<?php
/**
 * 消息相关接口：通知接收人、消息发送、图片上传、发送日志、模板库
 */

function handle_admin_notify_users() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $cur = [];
        $raw = trim(get_setting('push_notify', ''));
        if ($raw !== '') {
            $cur = array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
        $action = trim((string)param('action', 'set'));
        $in = param('userids', []);
        if (!is_array($in)) {
            $in = [];
        }
        $in = array_values(array_filter(array_map('trim', $in)));
        if ($action === 'add') {
            $cur = array_values(array_unique(array_merge($cur, $in)));
        } elseif ($action === 'remove') {
            $cur = array_values(array_diff($cur, $in));
        } else {
            $cur = $in;
        }
        set_setting('push_notify', implode(',', $cur));
        // 推送接收人列表变更需审计：用于追踪谁调整了敏感消息的接收范围
        audit_log('notify_users', 'push_notify', 'ok', ['action' => $action, 'count' => count($cur)]);
        json_out(['success' => true]);
    }
    $notify = trim(get_setting('push_notify', ''));
    $list = [];
    if ($notify !== '') {
        $uids = array_values(array_filter(array_map('trim', explode(',', $notify))));
        if (!empty($uids)) {
            $db = get_db();
            // 一次性查询所有接收人姓名，避免 N+1 循环查询
            $placeholders = implode(',', array_fill(0, count($uids), '?'));
            $stmt = $db->prepare("SELECT userid, name FROM users WHERE userid IN ($placeholders)");
            $stmt->execute($uids);
            $nameMap = [];
            foreach ($stmt->fetchAll() as $r) {
                $nameMap[$r['userid']] = $r['name'];
            }
            foreach ($uids as $uid) {
                $list[] = ['userid' => $uid, 'name' => $nameMap[$uid] ?? $uid];
            }
        }
    }
    json_out($list);
}

/**
 * 发送消息（个人/多人/部门/标签 任意组合；文本/Markdown/模板卡片）
 */
function handle_admin_message_send() {
    $u = require_admin_session([ROLE_ADMIN, ROLE_FINANCE, ROLE_HR]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $msgtype = trim((string)param('msgtype', 'text'));
    if (!in_array($msgtype, ['text', 'markdown', 'template_card', 'image'], true)) {
        json_out(['success' => false, 'error' => '消息类型不支持']);
    }
    $content = (string)param('content', '');
    $title = trim((string)param('title', ''));
    if ($msgtype !== 'template_card' && $msgtype !== 'image' && $content === '') {
        json_out(['success' => false, 'error' => '内容不能为空']);
    }
    if ($msgtype === 'image' && $content === '') {
        json_out(['success' => false, 'error' => '请先上传图片']);
    }

    // 群发场景的通配符替换（仅全局变量，个人变量如{姓名}不替换）
    $now = time();
    $gvars = [
        '年'   => date('Y', $now),
        '月'   => date('m', $now),
        '日'   => date('d', $now),
        '年月' => date('Ym', $now),
        '今天' => date('Y-m-d', $now),
        '日期' => date('Y-m-d', $now),
        '时间' => date('H:i:s', $now),
        '日期时间' => date('Y-m-d H:i:s', $now),
    ];
    if ($msgtype !== 'image') {
        $content = salary_render_template($content, $gvars);
        $title   = salary_render_template($title, $gvars);
    }

    $tu = param('touser', '');
    $tp = param('toparty', '');
    $tt = param('totag', '');
    if (is_array($tu)) { $tu = implode('|', array_filter(array_map('trim', $tu))); }
    if (is_array($tp)) { $tp = implode('|', array_filter(array_map('trim', $tp))); }
    if (is_array($tt)) { $tt = implode('|', array_filter(array_map('trim', $tt))); }
    $to = [
        'touser' => is_string($tu) ? trim($tu) : '',
        'toparty' => is_string($tp) ? trim($tp) : '',
        'totag'  => is_string($tt) ? trim($tt) : '',
    ];
    if ($to['touser'] === '' && $to['toparty'] === '' && $to['totag'] === '') {
        json_out(['success' => false, 'error' => '请至少选择一个接收人']);
    }
    // 将系统 userid 转换为企微 wecom_userid
    if ($to['touser'] !== '') {
        $uids = array_values(array_filter(explode('|', $to['touser'])));
        if (!empty($uids)) {
            $db = get_db();
            // 一次性查询所有 uid 的 wecom_userid，避免 N+1 循环查询
            $ph = implode(',', array_fill(0, count($uids), '?'));
            $ws = $db->prepare("SELECT userid, wecom_userid FROM users WHERE userid IN ($ph)");
            $ws->execute($uids);
            $wecomMap = [];
            foreach ($ws->fetchAll() as $r) {
                $wecomMap[$r['userid']] = $r['wecom_userid'];
            }
            $wecomIds = [];
            foreach ($uids as $uid) {
                $wecomIds[] = !empty($wecomMap[$uid]) ? $wecomMap[$uid] : $uid;
            }
            $to['touser'] = implode('|', $wecomIds);
        }
    }
    $res = wecom_send_message($msgtype, $content, $to, $title);
    if (empty($res['ok'])) {
        json_out(['success' => false, 'error' => $res['err'] ?? '发送失败']);
    }
    $summary = [];
    if ($to['touser']) { $summary[] = '成员:' . $to['touser']; }
    if ($to['toparty']) { $summary[] = '部门:' . $to['toparty']; }
    if ($to['totag'])  { $summary[] = '标签:' . $to['totag']; }
    $db = get_db();
    $db->prepare(
        "INSERT INTO message_log (type, touser, sender, msgtype, recipients, title, content, status)
         VALUES ('send', ?, ?, ?, ?, ?, ?, 1)"
    )->execute([
        $to['touser'], $u['userid'], $msgtype,
        implode('；', $summary), $title, $content,
    ]);
    // 消息发送属敏感外发动作，需审计记录接收范围与发送人，便于事后追溯
    audit_log('message_send', 'msg:' . $msgtype, 'ok', ['recipients' => implode('；', $summary), 'sender' => $u['userid']]);
    json_out([
        'success' => true,
        'invaliduser'  => $res['invaliduser'] ?? '',
        'invalidparty' => $res['invalidparty'] ?? '',
        'invalidtag'   => $res['invalidtag'] ?? '',
    ]);
}

/**
 * 上传图片素材（用于发送图片消息）
 * 通过 multipart/form-data 上传，返回 media_id
 */
function handle_admin_message_upload_image() {
    $u = require_admin_session([ROLE_ADMIN, ROLE_FINANCE, ROLE_HR]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $err = '图片上传失败';
        if (isset($_FILES['image']['error'])) {
            switch ($_FILES['image']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $err = '图片大小超过限制'; break;
                case UPLOAD_ERR_NO_FILE:
                    $err = '请选择图片文件'; break;
                case UPLOAD_ERR_PARTIAL:
                    $err = '图片仅部分上传，请重试'; break;
                case UPLOAD_ERR_CANT_WRITE:
                    $err = '服务器写入失败，请检查目录权限'; break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $err = '服务器缺少临时目录'; break;
                case UPLOAD_ERR_EXTENSION:
                    $err = 'PHP 扩展阻止了上传'; break;
            }
        }
        json_out(['success' => false, 'error' => $err]);
    }
    $file = $_FILES['image']['tmp_name'];
    $size = $_FILES['image']['size'];
    if ($size > 50 * 1024 * 1024) {
        json_out(['success' => false, 'error' => '图片大小不能超过 50MB']);
    }
    // 文件扩展名白名单校验，避免上传非图片文件
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        json_out(['success' => false, 'error' => '仅支持 jpg/jpeg/png/gif/webp 格式']);
    }
    // 扩展名可伪造，需用 getimagesize 校验真实图片类型
    $imgInfo = @getimagesize($file);
    if ($imgInfo === false) {
        json_out(['success' => false, 'error' => '上传的文件不是有效的图片']);
    }
    $uploadRes = wecom_upload_media($file, 'image');
    if (empty($uploadRes['ok'])) {
        json_out(['success' => false, 'error' => $uploadRes['err'] ?? '图片上传失败']);
    }
    json_out(['success' => true, 'media_id' => $uploadRes['media_id']]);
}

/**
 * 消息发送记录（分页）
 */
function handle_admin_message_log() {
    require_admin_session([ROLE_ADMIN, ROLE_FINANCE, ROLE_HR]);
    $page = max(1, (int)param('page', 1));
    $size = (int)param('size', 20);
    if ($size < 10) $size = 10;
    if ($size > 500) $size = 500;
    $db = get_db();
    $total = (int)$db->query("SELECT COUNT(*) c FROM message_log")->fetch()['c'];
    $stmt = $db->prepare("SELECT id, sender, msgtype, recipients, title, content, status, created_at FROM message_log ORDER BY id DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $size, PDO::PARAM_INT);
    $stmt->bindValue(2, ($page - 1) * $size, PDO::PARAM_INT);
    $stmt->execute();
    json_out(['total' => $total, 'page' => $page, 'size' => $size, 'list' => $stmt->fetchAll()]);
}

/**
 * 消息模板库：GET 列表 / POST 新增·更新·删除（op=add|update|delete）
 * 内置业务模板（tpl_key 非空）禁止删除，避免消息为空。
 */
function handle_admin_message_templates() {
    require_admin_session([ROLE_ADMIN, ROLE_FINANCE, ROLE_HR]);
    $db = get_db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $op = param('op', 'add');
        if ($op === 'delete') {
            $id = (int)param('id', 0);
            $chk = $db->prepare("SELECT tpl_key FROM msg_templates WHERE id = ? LIMIT 1");
            $chk->execute([$id]);
            $row = $chk->fetch();
            // 内置业务模板禁止删除，自定义模板（custom_ 前缀）允许删除
            $builtinKeys = ['salary_send','push_result','salary_remind','feedback_to_hr_salary','feedback_to_hr_bonus','feedback_reply_salary','feedback_reply_bonus','bonus_push_result','bonus_send','bonus_remind'];
            if ($row && in_array($row['tpl_key'], $builtinKeys, true)) {
                json_out(['success' => false, 'error' => '内置业务模板禁止删除，可编辑修改内容']);
            }
            $db->prepare("DELETE FROM msg_templates WHERE id = ?")->execute([$id]);
            // 删除模板会直接影响后续消息发送内容，需审计以追溯删除人
            audit_log('msg_template_delete', 'template:' . $id);
            json_out(['success' => true]);
        }
        $name = trim((string)param('name', ''));
        $msgtype = trim((string)param('msgtype', 'text'));
        $title = trim((string)param('title', ''));
        $content = (string)param('content', '');
        if ($name === '') {
            json_out(['success' => false, 'error' => '请填写模板名称']);
        }
        if (!in_array($msgtype, ['text', 'markdown', 'template_card'], true)) {
            json_out(['success' => false, 'error' => '消息类型不支持']);
        }
        if ($op === 'update') {
            $upId = (int)param('id', 0);
            // 只更新 name/msgtype/title/content，tpl_key 始终保持原值（保护内置模板标识）
            $stmt = $db->prepare("UPDATE msg_templates SET name=?, msgtype=?, title=?, content=? WHERE id = ?");
            $stmt->execute([$name, $msgtype, $title, $content, $upId]);
            if ($stmt->rowCount() === 0) {
                json_out(['success' => false, 'error' => '模板不存在或内容未变化']);
            }
            // 模板内容变更会影响后续消息发送的实际文案，需审计
            audit_log('msg_template_update', 'template:' . $upId, 'ok', ['name' => $name]);
            json_out(['success' => true]);
        } else {
            // 新增自定义模板：tpl_key 使用唯一值避免 UNIQUE 冲突（空字符串只能存在一条）
            $tplKey = 'custom_' . substr(md5(uniqid('', true)), 0, 12);
            $ins = $db->prepare(
                "INSERT INTO msg_templates (name, tpl_key, msgtype, title, content) VALUES (?,?,?,?,?)"
            );
            $ins->execute([$name, $tplKey, $msgtype, $title, $content]);
            // 新增模板会扩充消息模板库，需审计以追踪自定义模板的来源
            audit_log('msg_template_add', 'template:' . $tplKey, 'ok', ['name' => $name]);
            json_out(['success' => true, 'id' => $db->lastInsertId()]);
        }
    }
    // 模板迁移与兜底写入已由 db.php 的 db_ensure_schema 统一处理，此处不再重复
    // 发送消息页只展示自定义模板（source=custom），系统设置页展示全部（默认）
    $source = param('source', 'all');
    if ($source === 'custom') {
        $stmt = $db->prepare("SELECT id, name, tpl_key, msgtype, title, content, created_at FROM msg_templates WHERE tpl_key LIKE 'custom_%' ORDER BY id ASC");
        $stmt->execute();
        $list = $stmt->fetchAll();
    } else {
        $list = $db->query("SELECT id, name, tpl_key, msgtype, title, content, created_at FROM msg_templates ORDER BY (tpl_key='') DESC, id ASC")->fetchAll();
    }
    json_out($list);
}
