<?php
/**
 * 企业微信回调入口，处理两类请求：
 *   1) OAuth 网页授权回调：?code=xxx&state=employee|admin  -> 换 userid -> 种登录 Cookie -> 跳转
 *   2) 接收消息回调：GET 验证签名 / POST 接收消息（员工发"重置密码"）
 *
 * 企业微信后台配置：
 *   - 网页授权 redirect_uri 指向 callback.php
 *   - 接收消息 URL 指向 callback.php，Token / EncodingAESKey 与后台设置一致
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/wecom.php';
// 引入管理端 handler，复用 do_push / notify_push_result 实现「消息下发工资」
require_once __DIR__ . '/handlers/admin.php';

// 安全闸门：JWT 密钥未正确配置则拒绝签发会话 Cookie（避免伪造任意用户登录态）
ensure_jwt_secret();

// ---------- 1) OAuth 网页授权回调 ----------
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $state = $_GET['state'] ?? 'employee';
    $res = wecom_get_userid_by_code($code);
    if (!$res['ok']) {
        header('Content-Type:text/html;charset=utf-8');
        $ec = htmlspecialchars((string)($res['errcode'] ?? ''), ENT_QUOTES);
        $em = htmlspecialchars((string)($res['errmsg'] ?? '未知错误'), ENT_QUOTES);
        echo "获取用户信息失败（errcode: {$ec}）：{$em}。"
            . "请在企业微信中打开本链接重试；若反复失败，请联系管理员检查：① 该应用在企微后台的「可见范围」是否包含该员工；② 企业ID / 应用 Secret 是否正确；③ 网页授权可信域名是否配置为当前站点域名。";
        exit;
    }
    $userid = $res['userid'];

    // CSRF 防护：验证 state 中的 nonce 与 oauth_nonce cookie 一致
    $originalState = $state;
    $cookieNonce = $_COOKIE['oauth_nonce'] ?? '';
    $stateNonce = '';
    $redirectPath = '';
    $isBind = strpos($state, 'bind:') === 0;

    if ($isBind) {
        // bind:<bindTok>:<nonce>:<redirect> (新格式) 或 bind:<bindTok>:<redirect> (旧格式)
        $parts = explode(':', $state, 4);
        if (count($parts) >= 4) {
            $stateNonce = $parts[2];
            $redirectPath = $parts[3];
        } else {
            // 旧格式兼容：bind:<bindTok>:<redirect>
            $redirectPath = $parts[2] ?? '';
        }
    } elseif (strpos($state, 'n:') === 0) {
        // 新格式：n:<nonce>:<redirect>
        $rest = substr($state, 2);
        $colonPos = strpos($rest, ':');
        if ($colonPos !== false) {
            $stateNonce = substr($rest, 0, $colonPos);
            $redirectPath = substr($rest, $colonPos + 1);
        }
    } else {
        // 旧格式兼容：直接是路径（如 /employee/salary）或 'admin'
        $redirectPath = $state;
    }

    // 验证 nonce（新格式必须有，旧格式跳过兼容）
    if ($stateNonce !== '') {
        if ($cookieNonce === '' || !hash_equals($cookieNonce, $stateNonce)) {
            header('Content-Type:text/html;charset=utf-8');
            echo '登录状态验证失败（CSRF 防护），请重新打开应用登录。';
            exit;
        }
    }
    // 清除 oauth_nonce cookie（一次性使用）
    setcookie('oauth_nonce', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'secure' => is_https(), 'samesite' => 'Lax']);
    // 恢复 $state 为纯路径，供后续逻辑使用
    $state = $redirectPath;

    // ---------- 绑定企业微信模式 ----------
    // 已用「后台账号密码」登录的管理员，点击「绑定企业微信」后走此分支：
    // 把当前企微身份（userid）写入该后台账号的 wecom_userid，之后即可用企微直接登录。
    if ($isBind) {
        $admin = get_admin_session();
        if (!$admin) {
            // 兜底：用 state 中携带的短期签名令牌恢复后台身份
            // 解决 OAuth 回跳时 admin_token Cookie 因跨站 / HTTP↔HTTPS 不一致而丢失的问题
            $bindParts = explode(':', $originalState, 4);
            $bindTok = $bindParts[1] ?? '';
            if ($bindTok !== '') {
                $payload = jwt_decode($bindTok);
                if ($payload && !empty($payload['bind_admin'])) {
                    try {
                        $db = get_db();
                        $st = $db->prepare("SELECT * FROM users WHERE userid = ?");
                        $st->execute([$payload['bind_admin']]);
                        $admin = $st->fetch() ?: null;
                    } catch (Throwable $e) {
                        $admin = null;
                    }
                }
            }
        }
        if (!$admin) {
            header('Content-Type:text/html;charset=utf-8');
            echo '绑定失败：请先使用「后台账号密码」登录，再操作绑定企业微信。';
            exit;
        }
        try {
            $db = get_db();
            // 冲突检查：该企微 ID 是否已被其它账号绑定
            $cf = $db->prepare("SELECT userid, role, admin_login FROM users WHERE wecom_userid = ? AND userid != ?");
            $cf->execute([$userid, $admin['userid']]);
            $conflict = $cf->fetch();
            // 合并账号涉及多表写入，用事务保证一致性
            $db->beginTransaction();
            try {
                if ($conflict) {
                    // 判断冲突账号是否是独立的后台账号（有 admin_login 说明是用账号密码登录的管理账号）
                    if (!empty($conflict['admin_login'])) {
                        $db->rollBack();
                        header('Content-Type:text/html;charset=utf-8');
                        echo '绑定失败：该企业微信账号（' . htmlspecialchars($userid, ENT_QUOTES)
                           . '）已被其他管理账号（' . htmlspecialchars($conflict['admin_login'], ENT_QUOTES)
                           . '）绑定，请先用该账号登录后解绑，或联系管理员。';
                        exit;
                    }
                    // 非独立后台账号（企微自动创建的员工账号等）：合并到当前管理员账号
                    // 1) 取旧账号姓名、部门  2) 转移 salary 记录并更新 name/dept_name  3) 转移 feedback  4) 同步姓名到管理员  5) 删除旧账号
                    $oldUid = $conflict['userid'];
                    $newUid = $admin['userid'];
                    $oldName = '';
                    $oldDept = '';
                    $on = $db->prepare("SELECT u.name, d.name AS dept_name FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id WHERE u.userid = ?");
                    $on->execute([$oldUid]);
                    $oldRow = $on->fetch();
                    if ($oldRow) {
                        $oldName = $oldRow['name'];
                        $oldDept = normalize_dept_name($oldRow['dept_name'] ?: '');
                    }
                    // 转移 salary，同时用旧账号姓名/部门补全（如果为空）
                    // 先删除管理员账号已存在的同年月工资条（避免 UNIQUE(year,month,userid) 冲突导致整个合并事务回滚）
                    // 注意：子查询包装为派生表，绕过 MySQL Error 1093（不能在 DELETE 子查询中引用目标表）
                    // 同时清理对应的 salary_items，避免孤儿数据（无外键约束不会自动级联删除）
                    $db->prepare("DELETE FROM salary_items WHERE salary_id IN (SELECT id FROM (SELECT id FROM salary WHERE userid = ? AND (year, month) IN (SELECT year, month FROM (SELECT year, month FROM salary WHERE userid = ?) AS tmp2)) AS tmp3)")
                       ->execute([$newUid, $oldUid]);
                    $db->prepare("DELETE FROM salary WHERE userid = ? AND (year, month) IN (SELECT year, month FROM (SELECT year, month FROM salary WHERE userid = ?) AS tmp)")
                       ->execute([$newUid, $oldUid]);
                    $db->prepare("UPDATE salary SET userid = ?, name = CASE WHEN name = '' THEN ? ELSE name END, dept_name = CASE WHEN dept_name = '' THEN ? ELSE dept_name END WHERE userid = ?")
                       ->execute([$newUid, $oldName, $oldDept, $oldUid]);
                    $db->prepare("UPDATE feedback SET userid = ? WHERE userid = ?")
                       ->execute([$newUid, $oldUid]);
                    // 如果管理员姓名是默认值（admin等），同步旧账号的真实姓名
                    if ($oldName !== '' && $admin['name'] !== $oldName && in_array($admin['name'], ['admin', '管理员', 'Administrator'], true)) {
                        $db->prepare("UPDATE users SET name = ? WHERE userid = ?")
                           ->execute([$oldName, $newUid]);
                    }
                    $db->prepare("DELETE FROM users WHERE userid = ?")
                       ->execute([$oldUid]);
                }
                $db->prepare("UPDATE users SET wecom_userid = ? WHERE userid = ?")
                   ->execute([$userid, $admin['userid']]);
                $db->commit();
            } catch (Throwable $te) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $te;
            }
            // 绑定后同步企微真实姓名和部门
            $detail = wecom_get_user_detail($userid);
            if ($detail['ok']) {
                if (!empty($detail['name'])) {
                    $currentName = $admin['name'] ?? '';
                    if ($currentName === '' || $currentName === $admin['userid'] || in_array($currentName, ['admin','管理员','Administrator'], true)) {
                        $db->prepare("UPDATE users SET name = ? WHERE userid = ?")
                           ->execute([$detail['name'], $admin['userid']]);
                        $db->prepare("UPDATE salary SET name = ? WHERE userid = ?")
                           ->execute([$detail['name'], $admin['userid']]);
                    }
                }
                if (!empty($detail['dept_id'])) {
                    $deptStmt = $db->prepare("SELECT name FROM departments WHERE dept_id = ?");
                    $deptStmt->execute([(int)$detail['dept_id']]);
                    $deptRow = $deptStmt->fetch();
                    if ($deptRow && !empty($deptRow['name'])) {
                        $deptName = normalize_dept_name($deptRow['name']);
                        $db->prepare("UPDATE users SET dept_id = ? WHERE userid = ?")
                           ->execute([(int)$detail['dept_id'], $admin['userid']]);
                        $db->prepare("UPDATE salary SET dept_name = ? WHERE userid = ?")
                           ->execute([$deptName, $admin['userid']]);
                    }
                }
            }
            // 跳转地址：$state 已是纯路径（$redirectPath，第 79 行赋值），无则回设置页
            $ret = !empty($state) ? ('/' . ltrim($state, '/')) : '/admin/settings';
            header('Location: ' . $ret);
        } catch (Throwable $e) {
            slog('callback bind error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            header('Content-Type:text/html;charset=utf-8');
            echo '绑定失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
        exit;
    }

    try {
        $db = get_db();
        // 企微登录：优先匹配 wecom_userid（已绑定的账号）
        $ck = $db->prepare("SELECT id, role, userid, wecom_userid FROM users WHERE wecom_userid = ? LIMIT 1");
        $ck->execute([$userid]);
        $row = $ck->fetch();
        if (!$row) {
            // 未绑定 wecom_userid：兜底匹配 userid（通讯录同步或工资导入的员工）
            $ck2 = $db->prepare("SELECT id, role, userid, wecom_userid FROM users WHERE userid = ? LIMIT 1");
            $ck2->execute([$userid]);
            $row = $ck2->fetch();
        }
        if ($row) {
            // 已有用户：补充绑定 wecom_userid（若该字段为空）
            if (empty($row['wecom_userid'])) {
                $db->prepare("UPDATE users SET wecom_userid = ? WHERE userid = ?")
                   ->execute([$userid, $row['userid']]);
            }
            // 同步真实姓名（如果当前 name 是默认值或与企微不一致）
            $detail = wecom_get_user_detail($userid);
            if ($detail['ok'] && !empty($detail['name'])) {
                $currentName = $row['name'] ?? '';
                if ($currentName === '' || $currentName === $userid || $currentName !== $detail['name']) {
                    $db->prepare("UPDATE users SET name = ? WHERE userid = ?")
                       ->execute([$detail['name'], $row['userid']]);
                    // 同步更新 salary 表中的姓名
                    $db->prepare("UPDATE salary SET name = ? WHERE userid = ? AND (name = '' OR name = ? OR name IN ('admin','Administrator','管理员'))")
                       ->execute([$detail['name'], $row['userid'], $row['userid']]);
                }
                // 同步更新部门（如果 salary 表中为空或默认值）
                if (!empty($detail['dept_id'])) {
                    $deptStmt = $db->prepare("SELECT name FROM departments WHERE dept_id = ?");
                    $deptStmt->execute([(int)$detail['dept_id']]);
                    $deptRow = $deptStmt->fetch();
                    if ($deptRow && !empty($deptRow['name'])) {
                        $deptName = normalize_dept_name($deptRow['name']);
                        $db->prepare("UPDATE salary SET dept_name = ? WHERE userid = ? AND (dept_name = '' OR dept_name = '未分配')")
                           ->execute([$deptName, $row['userid']]);
                        $db->prepare("UPDATE users SET dept_id = ? WHERE userid = ? AND (dept_id = 0 OR dept_id IS NULL)")
                           ->execute([(int)$detail['dept_id'], $row['userid']]);
                    }
                }
            }
            $role = $row['role'];
            set_session_cookie($row['userid'], $role);
        } else {
            // 全新用户：员工端自动创建账号；管理端（state 为 admin 或 /admin/*）拒绝登录
            $isAdminEntry = ($state === 'admin' || strpos($state, '/admin') === 0);
            if ($isAdminEntry) {
                header('Content-Type:text/html;charset=utf-8');
                echo '该企业微信账号尚未绑定管理后台账号，请先用「后台账号密码」登录后再绑定企业微信。';
                exit;
            }
            // 获取企微真实姓名
            $detail = wecom_get_user_detail($userid);
            $realName = $userid;
            if ($detail['ok'] && !empty($detail['name'])) {
                $realName = $detail['name'];
            }
            $db->prepare("INSERT INTO users (userid, name, role, wecom_userid) VALUES (?, ?, 'employee', ?)")
               ->execute([$userid, $realName, $userid]);
            $role = 'employee';
            set_session_cookie($userid, $role);
        }
    } catch (Throwable $e) {
        // 记录真实异常便于排查（不要笼统报"系统尚未初始化"）
        $err = $e->getMessage() . "\n" . $e->getTraceAsString();
        slog('OAuth callback error: ' . $err);
        header('Content-Type:text/html;charset=utf-8');
        echo '登录处理异常：' . htmlspecialchars($e->getMessage(), ENT_QUOTES)
           . '<br><br>如反复出现，请联系管理员查看服务器错误日志。';
        exit;
    }
    audit_log('login', 'user:' . $userid, 'ok', ['wecom_userid' => $userid]);
    if (strpos($state, '/') === 0) {
        // 跳回原始页面（如 /employee/salary?ym=202603）
        // 防止 Open Redirect：拒绝协议相对 URL（//evil.com）及反斜杠变体（/\evil.com，浏览器会规范化为 //）
        if (strpos($state, '//') === 0 || strpos($state, '/\\') === 0) {
            $state = '/';
        }
        header('Location: ' . $state);
    } else {
        header('Location: ' . ($state === 'admin' ? 'admin.php' : 'index.php'));
    }
    exit;
}

// ---------- 2) 消息回调 GET 签名验证 ----------
// 支持 ?from=contact 区分来源：通讯录事件服务器 用独立的 Token/EncodingAESKey
if (isset($_GET['echostr']) && isset($_GET['msg_signature'])) {
    $isContact = ($_GET['from'] ?? '') === 'contact';
    $cfg = wxcfg();
    if ($isContact) {
        $token   = $cfg['contact_token'];
        $aesKey  = $cfg['contact_aes_key'];
        $srcName = '通讯录事件服务器';
        $cbUser  = '通讯录回调';
    } else {
        $token   = $cfg['token'];
        $aesKey  = $cfg['encoding_aes_key'];
        $srcName = '自建应用';
        $cbUser  = '应用消息回调';
    }
    // 提前校验配置完整性，避免配置缺失时盲目进入签名/解密流程
    if (empty($token) || empty($aesKey)) {
        slog("Callback verify skipped ($srcName): token or encoding_aes_key not configured");
        try {
            require_once __DIR__ . '/functions.php';
            audit_log_for_callback($cbUser, $isContact ? 'wecom_cb_contact_fail' : 'wecom_cb_app_fail',
                $isContact ? 'from=contact' : 'from=app', 'fail', ['err' => 'token or encoding_aes_key not configured']);
        } catch (Throwable $e) {}
        http_response_code(403);
        echo 'callback not configured';
        exit;
    }
    $signature = $_GET['msg_signature'];
    $timestamp = $_GET['timestamp'];
    $nonce = $_GET['nonce'];
    $echostr = $_GET['echostr'];

    // 诊断日志：记录回调验证各环节状态，便于排查企微后台配置问题
    $diag = [];
    $diag[] = 'source=' . ($isContact ? 'contact' : 'app');
    $diag[] = 'token_empty=' . (empty($token) ? '1' : '0');
    $diag[] = 'aes_key_empty=' . (empty($aesKey) ? '1' : '0');
    $diag[] = 'aes_key_len=' . strlen($aesKey);
    $calcSig = wecom_signature($token, $timestamp, $nonce, $echostr);
    $diag[] = 'sig_match=' . ($calcSig === $signature ? '1' : '0');
    $diag[] = 'calc_sig=' . substr($calcSig, 0, 10);
    $diag[] = 'recv_sig=' . substr($signature, 0, 10);
    slog('Callback verify: ' . implode(', ', $diag));

    if (hash_equals($signature, $calcSig)) {
        $plain = wecom_decrypt($echostr, $aesKey);
        if ($plain !== false) {
            try {
                require_once __DIR__ . '/functions.php';
                audit_log_for_callback($cbUser,
                    $isContact ? 'wecom_cb_contact_url' : 'wecom_cb_app_url',
                    $isContact ? 'from=contact' : 'from=app', 'ok',
                    ['timestamp' => $timestamp, 'nonce' => $nonce]);
            } catch (Throwable $e) {}
            header('Content-Type:text/plain');
            echo $plain;
            exit;
        }
        slog('Callback decrypt failed: aes_key_len_decoded=' . strlen(wecom_aes_key($aesKey)));
    }
    try {
        require_once __DIR__ . '/functions.php';
        audit_log_for_callback($cbUser, $isContact ? 'wecom_cb_contact_fail' : 'wecom_cb_app_fail',
            $isContact ? 'from=contact' : 'from=app', 'fail',
            ['sig_match' => hash_equals($signature, $calcSig) ? 1 : 0, 'decrypt_ok' => 0,
                'err' => (hash_equals($signature, $calcSig) ? 'AES解密失败' : '签名不匹配')]);
    } catch (Throwable $e) {}
    http_response_code(403);
    exit;
}

// ---------- 3) 消息回调 POST 接收 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['msg_signature'])) {
    $isContact = ($_GET['from'] ?? '') === 'contact';
    $cfg = wxcfg();
    if ($isContact) {
        $token   = $cfg['contact_token'];
        $aesKey  = $cfg['contact_aes_key'];
        $srcName = '通讯录事件服务器';
        $cbUser  = '通讯录回调';
    } else {
        $token   = $cfg['token'];
        $aesKey  = $cfg['encoding_aes_key'];
        $srcName = '自建应用';
        $cbUser  = '应用消息回调';
    }
    if (empty($token) || empty($aesKey)) {
        slog("Callback POST skipped ($srcName): token or encoding_aes_key not configured");
        try {
            require_once __DIR__ . '/functions.php';
            audit_log_for_callback($cbUser, $isContact ? 'wecom_cb_contact_fail' : 'wecom_cb_app_fail',
                $isContact ? 'from=contact' : 'from=app', 'fail', ['err' => 'token or encoding_aes_key not configured']);
        } catch (Throwable $e) {}
        http_response_code(403);
        echo 'callback not configured';
        exit;
    }
    $signature = $_GET['msg_signature'];
    $timestamp = $_GET['timestamp'];
    $nonce = $_GET['nonce'];
    $body = file_get_contents('php://input');
    $xml = @simplexml_load_string($body);
    $encrypt = (string)($xml->Encrypt ?? '');
    $sigMatch = hash_equals($signature, wecom_signature($token, $timestamp, $nonce, $encrypt));
    if (!$sigMatch) {
        try {
            require_once __DIR__ . '/functions.php';
            audit_log_for_callback($cbUser, $isContact ? 'wecom_cb_contact_fail' : 'wecom_cb_app_fail',
                $isContact ? 'from=contact' : 'from=app', 'fail',
                ['sig' => 0, 'decrypt' => null, 'err' => '签名不匹配']);
        } catch (Throwable $e) {}
        slog("Callback POST ($srcName): signature mismatch, returned 403");
        http_response_code(403);
        echo 'signature mismatch';
        exit;
    }
    $plain = wecom_decrypt($encrypt, $aesKey);
    if ($plain === false) {
        try {
            require_once __DIR__ . '/functions.php';
            audit_log_for_callback($cbUser, $isContact ? 'wecom_cb_contact_fail' : 'wecom_cb_app_fail',
                $isContact ? 'from=contact' : 'from=app', 'fail',
                ['sig' => 1, 'decrypt' => 0, 'err' => 'AES解密失败']);
        } catch (Throwable $e) {}
        slog("Callback POST ($srcName): AES decrypt failed, returned 403");
        http_response_code(403);
        echo 'decrypt failed';
        exit;
    }
    $msg = @simplexml_load_string($plain);
    $msgType    = (string)($msg->MsgType ?? '');
    $event      = (string)($msg->Event ?? '');
    $changeType = (string)($msg->ChangeType ?? '');
    require_once __DIR__ . '/functions.php'; // 保证 audit_log_for_callback 可用（后面多分支都要写审计）

    // 通讯录变更事件：实时同步到本地 users / departments 表
    if ($isContact && $msgType === 'event' && $event === 'change_contact') {
        handle_contact_change($msg, $changeType);
        header('Content-Type:text/plain');
        echo 'success';
        exit;
    }

    // 自建应用消息：关键词触发（重置密码 / 下发工资）
    $fromUser = (string)($msg->FromUserName ?? '');
    $content = trim((string)($msg->Content ?? ''));
    // 读取后台配置的消息口令（多关键词用 | 分隔）
    $resetKw = parse_keyword_list(
        get_setting('reset_keywords', '重置密码|密码重置|改密|修改密码'),
        '重置密码|密码重置|改密|修改密码'
    );
    $pushKw = parse_keyword_list(
        get_setting('push_keywords', '下发工资|工资下发|推送工资|发工资'),
        '下发工资|工资下发|推送工资|发工资'
    );
    $bonusPushKw = parse_keyword_list(
        get_setting('bonus_push_keywords', '下发奖金|奖金下发|推送奖金|发奖金'),
        '下发奖金|奖金下发|推送奖金|发奖金'
    );

    if ($fromUser !== '' && content_has_keyword($content, $resetKw)) {
        // 重置密码：任意员工发送口令之一即可重置自己的查看密码
        // 同时匹配 userid 和 wecom_userid，兼容「后台账号绑定企微」场景
        $matchedKw = content_has_keyword($content, $resetKw, true);
        $result = 'ok';
        $err    = null;
        try {
            $db = get_db();
            $updated = $db->prepare("UPDATE users SET password = NULL, has_set_password = 0 WHERE userid = ? OR wecom_userid = ?")
               ->execute([$fromUser, $fromUser]);
            $name = _find_display_name_by_wecom($fromUser) ?: $fromUser;
            wecom_send_text($fromUser, "您的工资查看密码已重置，请打开工资条应用重新设置密码。");
        } catch (Throwable $e) {
            $result = 'fail';
            $err    = $e->getMessage();
            slog('callback reset_password error: ' . $err);
        }
        audit_log_for_callback('应用消息回调', 'wecom_msg_reset_pwd',
            'wecom_userid:' . $fromUser . ($name ?? '' ? (' [' . $name . ']') : ''),
            $result, ['matched_kw' => $matchedKw, 'updated_rows' => $updated ?? null, 'err' => $err]);
    } elseif ($fromUser !== '' && content_has_keyword($content, $pushKw)) {
        // 下发工资：仅管理员 / 财务发送口令之一可下发最近待发月份（效果同后台「下发工资」）
        $matchedKw = content_has_keyword($content, $pushKw, true);
        $targetLabel = '';
        $detail      = ['matched_kw' => $matchedKw];
        $result      = 'ok';
        try {
            $db = get_db();
            $ustmt = $db->prepare("SELECT role, userid, name FROM users WHERE userid = ? OR wecom_userid = ? LIMIT 1");
            $ustmt->execute([$fromUser, $fromUser]);
            $urow = $ustmt->fetch();
            $role = $urow ? $urow['role'] : '';
            $detail['sender_userid'] = $urow ? ($urow['userid'] ?? '') : '';
            $detail['sender_name']   = $urow ? ($urow['name'] ?? '') : '';
            $detail['sender_role']   = $role;
            if (!user_has_any_role($role, [ROLE_ADMIN, ROLE_FINANCE])) {
                $detail['reason'] = 'no_permission';
                $result = 'fail';
                wecom_send_text($fromUser, "您无权限通过消息下发工资，请联系管理员或财务。");
            } else {
                $s = $db->prepare("SELECT year, month FROM salary WHERE status='draft' AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL ORDER BY year DESC, month DESC LIMIT 1");
                $s->execute();
                $row = $s->fetch();
                if (!$row) {
                    $detail['reason'] = 'no_draft_salary';
                    $result = 'ok'; // 业务上没数据不算系统级 fail
                    wecom_send_text($fromUser, "当前没有待下发的工资。");
                } else {
                    $ret = do_push($row['year'], $row['month'], 'draft');
                    $sent = $ret['sent'];
                    $targetLabel = "salary:{$row['year']}/{$row['month']}";
                    $detail['sent'] = $sent;
                    $detail['year'] = (int)$row['year'];
                    $detail['month'] = (int)$row['month'];
                    notify_push_result("【消息下发结果】{$row['year']}年{$row['month']}月工资已下发 {$sent} 人。");
                    wecom_send_text($fromUser, "已下发 {$row['year']}年{$row['month']}月工资，共 {$sent} 人。");
                }
            }
        } catch (Throwable $e) {
            $result = 'fail';
            $detail['err'] = $e->getMessage();
            slog('callback push_salary error: ' . $e->getMessage());
        }
        $target = $targetLabel !== ''
            ? ("wecom_userid:{$fromUser}" . ($detail['sender_name'] ?? '' ? (' [' . $detail['sender_name'] . '] ') : ' ') . "→ {$targetLabel}")
            : ('wecom_userid:' . $fromUser . ($detail['sender_name'] ?? '' ? (' [' . $detail['sender_name'] . ']') : ''));
        audit_log_for_callback('应用消息回调', 'wecom_msg_push_salary', $target, $result, $detail);
    } elseif ($fromUser !== '' && content_has_keyword($content, $bonusPushKw)) {
        // 下发奖金：仅管理员 / 财务发送口令之一可推送所有 draft 状态的奖金（按年+类型分批）
        $matchedKw = content_has_keyword($content, $bonusPushKw, true);
        $detail    = ['matched_kw' => $matchedKw];
        $result    = 'ok';
        try {
            $db = get_db();
            $ustmt = $db->prepare("SELECT role, userid, name FROM users WHERE userid = ? OR wecom_userid = ? LIMIT 1");
            $ustmt->execute([$fromUser, $fromUser]);
            $urow = $ustmt->fetch();
            $role = $urow ? $urow['role'] : '';
            $detail['sender_userid'] = $urow ? ($urow['userid'] ?? '') : '';
            $detail['sender_name']   = $urow ? ($urow['name'] ?? '') : '';
            $detail['sender_role']   = $role;
            if (!user_has_any_role($role, [ROLE_ADMIN, ROLE_FINANCE])) {
                $detail['reason'] = 'no_permission';
                $result = 'fail';
                wecom_send_text($fromUser, "您无权限通过消息下发奖金，请联系管理员或财务。");
            } else {
                // 找到所有有 draft 奖金的 (year, bonus_type) 组合，逐一推送
                $batchStmt = $db->prepare(
                    "SELECT year, bonus_type FROM bonus
                     WHERE status = 'draft' AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL
                     GROUP BY year, bonus_type ORDER BY year DESC, bonus_type ASC"
                );
                $batchStmt->execute();
                $batches = $batchStmt->fetchAll();
                if (empty($batches)) {
                    $detail['reason'] = 'no_draft_bonus';
                    wecom_send_text($fromUser, "当前没有待下发的奖金。");
                } else {
                    $totalSent = 0;
                    $totalAll = 0;
                    $resultLines = [];
                    $perBatch = [];
                    foreach ($batches as $batch) {
                        $year = (int)$batch['year'];
                        $bonusType = $batch['bonus_type'];
                        $rowsStmt = $db->prepare(
                            "SELECT id, userid FROM bonus
                             WHERE year = ? AND bonus_type = ? AND status = 'draft' AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL"
                        );
                        $rowsStmt->execute([$year, $bonusType]);
                        $rows = $rowsStmt->fetchAll();
                        $sent = 0;
                        $total = 0;
                        $pushedAt = date('Y-m-d H:i:s');
                        foreach ($rows as $r) {
                            $total++;
                            $claim = $db->prepare("UPDATE bonus SET status = 'sent', pushed_at = ? WHERE id = ? AND status = 'draft'");
                            $claim->execute([$pushedAt, $r['id']]);
                            if ($claim->rowCount() > 0) {
                                if (bonus_push_send($r['userid'], $year, $bonusType, (int)$r['id'])) {
                                    $sent++;
                                }
                            }
                        }
                        $totalSent += $sent;
                        $totalAll += $total;
                        $resultLines[] = "{$year}年「{$bonusType}」：推送 {$sent}/{$total}";
                        $perBatch[] = ['year' => $year, 'bonus_type' => $bonusType, 'sent' => $sent, 'total' => $total];
                        audit_log('push_bonus', "bonus:{$year}/{$bonusType}", 'ok', [
                            'sent' => $sent, 'fail' => $total - $sent, 'total' => $total,
                        ]);
                    }
                    $detail['batches'] = $perBatch;
                    $detail['total_sent'] = $totalSent;
                    $detail['total'] = $totalAll;
                    // 推送结果通知接收人
                    $notifyMsg = "【消息下发奖金结果】\n" . implode("\n", $resultLines) . "\n合计：推送 {$totalSent}/{$totalAll}";
                    notify_push_result($notifyMsg);
                    wecom_send_text($fromUser, "奖金下发完成，共 {$totalSent}/{$totalAll} 条。\n" . implode("\n", $resultLines));
                }
            }
        } catch (Throwable $e) {
            $result = 'fail';
            $detail['err'] = $e->getMessage();
            slog('callback push_bonus error: ' . $e->getMessage());
        }
        $target = 'wecom_userid:' . $fromUser . ($detail['sender_name'] ?? '' ? (' [' . $detail['sender_name'] . ']') : '');
        audit_log_for_callback('应用消息回调', 'wecom_msg_push_bonus', $target, $result, $detail);
    }
    header('Content-Type:text/plain');
    echo 'success';
    exit;
}

http_response_code(404);
try {
    require_once __DIR__ . '/functions.php';
    audit_log_for_callback('应用消息回调', 'wecom_cb_app_fail', 'from=app', 'fail',
        ['err' => '404 no handler matched for POST/GET']);
} catch (Throwable $e) {}
echo 'not found';

// ==================== 通讯录变更事件处理 ====================
/**
 * 处理企业微信「通讯录接收事件服务器」推送的 change_contact 事件
 *   $msg         : 解密后的 SimpleXMLElement
 *   $changeType  : create_user / update_user / delete_user / create_party / update_party / delete_party
 *
 * 设计原则：
 *   - 用户删除：不直接 DELETE users 记录（保留历史工资条可查），仅清空 wecom_userid 防止离职员工继续登录
 *   - 用户新增/更新：拉取 user/get 详情后 UPSERT，保证 name/dept_id/wecom_userid 始终最新
 *   - 部门新增/更新：拉取 department/get 详情后 UPSERT
 *   - 部门删除：先把该部门下员工的 dept_id 置 0（避免外键孤儿），再删除部门
 *   - 所有异常仅记录日志不抛出，确保回调始终返回 success（否则企微会反复重试）
 */
function handle_contact_change($msg, $changeType) {
    $db = get_db();
    $opUser = '通讯录回调';
    try {
        switch ($changeType) {
            case 'create_user':
            case 'update_user':
                $userid = (string)($msg->UserID ?? '');
                if ($userid === '') {
                    slog("contact_change $changeType: empty UserID, skipped");
                    audit_log_for_callback($opUser, 'wecom_cb_contact_fail', "change_contact:$changeType", 'fail',
                        ['err' => 'empty UserID']);
                    return;
                }
                // 拉取最新详情（含姓名、部门）
                $detail = wecom_get_user_detail($userid);
                if (!$detail['ok']) {
                    $err = 'fetch user detail failed: ' . ($detail['errmsg'] ?? 'unknown');
                    slog("contact_change $changeType: $err for $userid");
                    audit_log_for_callback($opUser, 'wecom_cb_contact_fail',
                        'wecom_userid:' . $userid, 'fail', ['err' => $err]);
                    return;
                }
                $name   = $detail['name'] ?: $userid;
                $deptId = (int)($detail['dept_id'] ?? 0);
                $oldRow = null;
                try {
                    $stmt = $db->prepare("SELECT userid, name, dept_id, wecom_userid FROM users WHERE userid = ? OR wecom_userid = ? LIMIT 1");
                    $stmt->execute([$userid, $userid]);
                    $oldRow = $stmt->fetch() ?: null;
                } catch (Throwable $e) {}
                $oldName    = $oldRow ? ($oldRow['name'] ?? '') : '';
                $oldDeptId  = $oldRow ? (int)($oldRow['dept_id'] ?? 0) : 0;
                $oldWecom   = $oldRow ? ($oldRow['wecom_userid'] ?? '') : '';
                $isCreate   = $changeType === 'create_user' || !$oldRow;
                // UPSERT 到 users 表（不覆盖 role / password / admin_login 等本地字段）
                $sql = db_upsert_sql(
                    'users',
                    ['userid', 'name', 'dept_id', 'wecom_userid'],
                    'userid',
                    ['name', 'dept_id', 'wecom_userid']
                );
                $db->prepare($sql)->execute([$userid, $name, $deptId, $userid]);
                slog("contact_change $changeType: synced user $userid (name=$name, dept=$deptId)");

                $changes = [];
                if ($isCreate) {
                    $changes['action'] = 'create';
                    $changes['new_name'] = $name;
                    $changes['new_dept_id'] = $deptId;
                } else {
                    if ($oldName !== $name) $changes['name'] = ['before' => $oldName, 'after' => $name];
                    if ($oldDeptId !== $deptId) $changes['dept_id'] = ['before' => $oldDeptId, 'after' => $deptId];
                    if ($oldWecom !== $userid) $changes['wecom_userid'] = ['before' => $oldWecom, 'after' => $userid];
                }
                $opType = $isCreate ? 'wecom_cb_contact_user_create' : 'wecom_cb_contact_user_update';
                $target = 'wecom_userid:' . $userid . ($name ? (' [' . $name . ']') : '');
                $result = empty($changes) ? 'ok' : 'ok'; // 没变化也算同步成功
                audit_log_for_callback($opUser, $opType, $target, $result, $changes + ['dept_id' => $deptId, 'name' => $name]);
                break;

            case 'delete_user':
                $userid = (string)($msg->UserID ?? '');
                if ($userid === '') {
                    slog("contact_change delete_user: empty UserID, skipped");
                    audit_log_for_callback($opUser, 'wecom_cb_contact_fail', 'change_contact:delete_user', 'fail',
                        ['err' => 'empty UserID']);
                    return;
                }
                // 清空 wecom_userid 并将部门改为「已离职」（保留 users 记录以便历史工资条查询）
                $resignedDeptId = find_or_create_dept_by_name($db, '已离职');
                $oldStmt = $db->prepare("SELECT userid, name, wecom_userid, dept_id FROM users WHERE userid = ? OR wecom_userid = ? LIMIT 1");
                $oldStmt->execute([$userid, $userid]);
                $old = $oldStmt->fetch() ?: null;
                $db->prepare("UPDATE users SET wecom_userid = NULL, dept_id = ? WHERE userid = ? OR wecom_userid = ?")
                   ->execute([$resignedDeptId, $userid, $userid]);
                slog("contact_change delete_user: cleared wecom_userid and set dept to 已离职 for $userid (record kept for salary history)");
                audit_log_for_callback($opUser, 'wecom_cb_contact_user_delete',
                    'wecom_userid:' . $userid . ($old ? (' [' . ($old['name'] ?? '') . ']') : '') . ' → 已离职',
                    'ok', [
                        'old_userid'   => $old ? ($old['userid'] ?? '') : $userid,
                        'old_name'     => $old ? ($old['name'] ?? '') : '',
                        'old_dept_id'  => $old ? (int)($old['dept_id'] ?? 0) : null,
                        'cleared_wecom_userid' => true,
                        'new_dept_id'  => $resignedDeptId,
                    ]);
                break;

            case 'create_party':
            case 'update_party':
                $deptId = (int)($msg->Id ?? 0);
                if ($deptId === 0) {
                    slog("contact_change $changeType: empty Id, skipped");
                    audit_log_for_callback($opUser, 'wecom_cb_contact_fail', "change_contact:$changeType", 'fail',
                        ['err' => 'empty Id']);
                    return;
                }
                // 拉取部门详情
                $dept = wecom_get_department_detail($deptId);
                if (!$dept['ok']) {
                    $err = 'fetch dept detail failed: ' . ($dept['errmsg'] ?? 'unknown');
                    slog("contact_change $changeType: $err for $deptId");
                    audit_log_for_callback($opUser, 'wecom_cb_contact_fail', "dept_id:$deptId", 'fail', ['err' => $err]);
                    return;
                }
                $name     = $dept['name'] ?: '';
                $parentId = (int)$dept['parentid'];
                $oldStmt = $db->prepare("SELECT dept_id, name, parent_id FROM departments WHERE dept_id = ? LIMIT 1");
                $oldStmt->execute([$deptId]);
                $old = $oldStmt->fetch() ?: null;
                $isCreate = $changeType === 'create_party' || !$old;
                $sql = db_upsert_sql(
                    'departments',
                    ['dept_id', 'name', 'parent_id'],
                    'dept_id',
                    ['name', 'parent_id']
                );
                $db->prepare($sql)->execute([$deptId, $name, $parentId]);
                slog("contact_change $changeType: synced dept $deptId (name=$name, parent=$parentId)");
                $changes = [];
                if ($isCreate) {
                    $changes['action'] = 'create';
                    $changes['new_name'] = $name;
                    $changes['new_parent_id'] = $parentId;
                } else {
                    if (($old['name'] ?? '') !== $name) $changes['name'] = ['before' => $old['name'], 'after' => $name];
                    if ((int)($old['parent_id'] ?? 0) !== $parentId) $changes['parent_id'] = ['before' => (int)($old['parent_id'] ?? 0), 'after' => $parentId];
                }
                $opType = $isCreate ? 'wecom_cb_contact_dept_create' : 'wecom_cb_contact_dept_update';
                audit_log_for_callback($opUser, $opType, "dept_id:$deptId" . ($name ? (" [$name]") : ''),
                    'ok', $changes + ['parent_id' => $parentId]);
                break;

            case 'delete_party':
                $deptId = (int)($msg->Id ?? 0);
                if ($deptId === 0) {
                    slog("contact_change delete_party: empty Id, skipped");
                    audit_log_for_callback($opUser, 'wecom_cb_contact_fail', 'change_contact:delete_party', 'fail',
                        ['err' => 'empty Id']);
                    return;
                }
                $oldStmt = $db->prepare("SELECT dept_id, name, parent_id FROM departments WHERE dept_id = ? LIMIT 1");
                $oldStmt->execute([$deptId]);
                $old = $oldStmt->fetch() ?: null;
                // 先把该部门下员工的 dept_id 置 0（避免外键孤儿），再删除部门
                $db->prepare("UPDATE users SET dept_id = 0 WHERE dept_id = ?")
                   ->execute([$deptId]);
                $movedRows = $db->prepare("SELECT COUNT(*) AS c FROM users WHERE dept_id = 0");
                $movedCount = 0;
                try { $movedRows->execute(); $m = $movedRows->fetch(); $movedCount = (int)($m['c'] ?? 0); } catch (Throwable $e) {}
                $db->prepare("DELETE FROM departments WHERE dept_id = ?")
                   ->execute([$deptId]);
                slog("contact_change delete_party: deleted dept $deptId (users under it reset to dept_id=0)");
                audit_log_for_callback($opUser, 'wecom_cb_contact_dept_delete',
                    "dept_id:$deptId" . ($old ? (' [' . ($old['name'] ?? '') . ']') : ''),
                    'ok', [
                        'old_name'      => $old ? ($old['name'] ?? '') : '',
                        'old_parent_id' => $old ? (int)($old['parent_id'] ?? 0) : null,
                        'employees_reset_to_dept_0' => $movedCount,
                    ]);
                break;

            default:
                slog("contact_change: unknown ChangeType=$changeType, ignored");
                audit_log_for_callback($opUser, 'wecom_cb_contact_fail', "change_contact:$changeType", 'fail',
                    ['err' => 'unknown ChangeType', 'ChangeType' => $changeType]);
                break;
        }
    } catch (Throwable $e) {
        // 仅记录日志，不抛出（避免企微反复重试回调）
        slog("contact_change $changeType error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        try {
            audit_log_for_callback($opUser, 'wecom_cb_contact_fail', "change_contact:$changeType", 'fail',
                ['err' => $e->getMessage()]);
        } catch (Throwable $e2) {}
    }
}
