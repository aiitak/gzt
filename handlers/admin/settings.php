<?php
/**
 * 系统设置接口：企业微信配置/测试、定时推送、关键词、免确认窗口、站点设置、页面访问权限
 */

function handle_admin_settings_wecom() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 敏感字段（secret / contact_secret / token / encoding_aes_key / contact_token / contact_aes_key）
        // 留空表示保留原值，避免误覆盖
        $maskable = ['secret', 'contact_secret', 'token', 'encoding_aes_key', 'contact_token', 'contact_aes_key'];
        foreach ([
            'corpid','agentid','secret','contact_secret','token','encoding_aes_key','server_domain','card_source_desc',
            'contact_token', 'contact_aes_key',
        ] as $k) {
            $v = param($k, null);
            if ($v === null) {
                continue;
            }
            if (in_array($k, $maskable, true) && $v === '') {
                continue;
            }
            set_setting($k, (string)$v);
        }
        // 写操作审计：记录企业微信配置变更，便于追溯敏感凭证的修改人
        audit_log('settings_wecom', 'wecom_config', 'ok');
        json_out(['success' => true]);
    }
    $all = get_all_settings();
    // 脱敏：敏感字段不回显明文，仅告知是否已配置（has_* 标志位供前端展示占位提示）
    foreach (['secret', 'contact_secret', 'token', 'encoding_aes_key', 'contact_token', 'contact_aes_key'] as $k) {
        if (!empty($all[$k])) {
            $all[$k] = '';
            $all['has_' . $k] = true;
        } else {
            $all['has_' . $k] = false;
        }
    }
    json_out($all);
}

/**
 * 测试企业微信连接：用当前配置换取 access_token，返回明确成功/失败信息
 */
function handle_admin_test_connect() {
    require_admin_session([ROLE_ADMIN]);
    $cfg = wxcfg();
    if (empty($cfg['corpid']) || empty($cfg['secret'])) {
        json_out(['success' => false, 'error' => '请先填写企业ID（CorpID）和应用Secret后再测试']);
        return;
    }
    $url = 'https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid=' . urlencode($cfg['corpid'])
         . '&corpsecret=' . urlencode($cfg['secret']);
    $r = wx_http($url);
    if (!$r || empty($r['access_token'])) {
        $errcode = isset($r['errcode']) ? (int)$r['errcode'] : -1;
        $errmsg  = $r['errmsg'] ?? '网络请求失败，无法连接企业微信服务器';
        json_out(['success' => false, 'error' => '连接失败（errcode: ' . $errcode . '）：' . $errmsg]);
        return;
    }
    json_out([
        'success' => true,
        'message' => '✅ 连接成功，应用凭证有效（CorpID：' . $cfg['corpid'] . '）',
    ]);
}

function handle_admin_schedule() {
    require_admin_session([ROLE_FINANCE, ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 注意 PHP 的 (bool)"false" === true（非空字符串都是 truthy）
        // 这里显式将字符串 "false"/"0"/"" 视为 false，避免前端误传导致定时任务被误启用
        $enabledRaw = param('enabled', false);
        $enabled = is_bool($enabledRaw) ? $enabledRaw
                  : in_array(strtolower((string)$enabledRaw), ['1', 'true', 'on', 'yes'], true);
        $data = [
            'enabled' => $enabled,
            'day'     => (int)param('day', 15),
            'hour'    => (int)param('hour', 10),
            'minute'  => (int)param('minute', 0),
        ];
        set_setting('schedule', json_encode($data, JSON_UNESCAPED_UNICODE));

        // ===== 5 个定时任务参数（天数）：独立 K-V 存储，方便 cron 直接读取 =====
        $timerCfgs = [
            'timer_vacation_lookback_days'  => ['min' => 1, 'max' => 180, 'def' => 2],
            'timer_keep_audit_log_days'     => ['min' => 1, 'max' => 3650, 'def' => 180],
            'timer_keep_cb_log_days'        => ['min' => 1, 'max' => 3650, 'def' => 90],
            'timer_keep_slog_days'          => ['min' => 1, 'max' => 3650, 'def' => 30],
            'timer_keep_feedback_days'      => ['min' => 1, 'max' => 3650, 'def' => 180],
        ];
        $timerSaved = [];
        foreach ($timerCfgs as $k => $rule) {
            $raw = param($k, null);
            if ($raw === null) continue;
            $v = (int)$raw;
            if ($v < $rule['min']) $v = $rule['min'];
            if ($v > $rule['max']) $v = $rule['max'];
            set_setting($k, (string)$v);
            $timerSaved[$k] = $v;
        }

        // 定时推送计划变更需审计：误开/误关定时任务会影响工资推送时效
        $auditDetail = $timerSaved + ['schedule' => $data];
        audit_log('schedule', 'push_schedule', 'ok', $auditDetail);
        json_out(['success' => true]);
    }
    $sch = json_decode(get_setting('schedule', ''), true) ?: [];
    // 返回 CRON_KEY 状态（脱敏：仅返回是否已配置 + 前4位预览，完整密钥不回传前端）
    $cronKeyConfigured = defined('CRON_KEY') && CRON_KEY !== '';
    $cronKeyPreview = '';
    $cronKeyFull = '';
    if ($cronKeyConfigured) {
        $len = strlen(CRON_KEY);
        // 短密钥（<8 字符）不返回预览，避免泄露完整密钥
        if ($len >= 8) {
            $cronKeyPreview = substr(CRON_KEY, 0, 4) . str_repeat('*', max(0, $len - 4));
        } else {
            $cronKeyPreview = str_repeat('*', $len);
        }
        // 允许有权限的管理员获取完整密钥（用于点击眼睛预览）
        if (param('full', '') === '1') {
            $cronKeyFull = CRON_KEY;
        }
    }
    json_out([
        'enabled' => $sch['enabled'] ?? false,
        'day'     => $sch['day'] ?? 15,
        'hour'    => $sch['hour'] ?? 10,
        'minute'  => $sch['minute'] ?? 0,
        'cron_key_configured' => $cronKeyConfigured,
        'cron_key_preview'    => $cronKeyPreview,
        'cron_key_full'       => $cronKeyFull,
        'app_base_url'        => app_base_url(),
        // 5 个定时任务参数，带默认值
        'timer_vacation_lookback_days' => (int)get_setting('timer_vacation_lookback_days', '2'),
        'timer_keep_audit_log_days'    => (int)get_setting('timer_keep_audit_log_days',    '180'),
        'timer_keep_cb_log_days'       => (int)get_setting('timer_keep_cb_log_days',       '90'),
        'timer_keep_slog_days'         => (int)get_setting('timer_keep_slog_days',         '30'),
        'timer_keep_feedback_days'     => (int)get_setting('timer_keep_feedback_days',     '180'),
    ]);
}

/**
 * 消息口令关键词设置（GET 读取 / POST 保存）
 *   reset_keywords      ：重置密码口令，多关键词用 | 分隔
 *   push_keywords       ：下发工资口令，多关键词用 | 分隔
 *   bonus_push_keywords ：下发奖金口令，多关键词用 | 分隔
 */
function handle_admin_keywords() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $reset = trim((string)param('reset_keywords', ''));
        $push  = trim((string)param('push_keywords', ''));
        $bonusPush = trim((string)param('bonus_push_keywords', ''));
        if ($reset !== '') {
            set_setting('reset_keywords', $reset);
        }
        if ($push !== '') {
            set_setting('push_keywords', $push);
        }
        if ($bonusPush !== '') {
            set_setting('bonus_push_keywords', $bonusPush);
        }
        // 口令关键词变更需审计：关键词直接控制工资下发/改密的触发条件
        audit_log('keywords', 'message_keywords', 'ok', [
            'reset' => $reset !== '', 'push' => $push !== '', 'bonus_push' => $bonusPush !== '',
        ]);
        json_out(['success' => true]);
    }
    json_out([
        'reset_keywords'       => get_setting('reset_keywords', '重置密码|密码重置|改密|修改密码'),
        'push_keywords'        => get_setting('push_keywords', '下发工资|工资下发|推送工资|发工资'),
        'bonus_push_keywords'  => get_setting('bonus_push_keywords', '下发奖金|奖金下发|推送奖金|发奖金'),
    ]);
}

function handle_admin_freewindow() {
    require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $m = (int)param('minutes', 30);
        if ($m < 1) { $m = 1; }
        if ($m > 1440) { $m = 1440; }
        set_setting('free_window_minutes', (string)$m);
        // 免确认窗口时长变更需审计：窗口过长会放大工资被未授权确认的风险
        audit_log('freewindow', 'free_window', 'ok', ['minutes' => $m]);
        json_out(['success' => true]);
    }
    json_out([
        'minutes' => (int)get_setting('free_window_minutes', 30),
    ]);
}

/**
 * 站点设置（公司名称、网址、标题、主题色、Logo、公告）GET/POST
 */
function handle_admin_site_settings() {
    $u = require_admin_session([ROLE_ADMIN]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $fields = [
            'company_name', 'company_website',
            'site_title', 'site_subtitle',
            'theme_color', 'logo_url',
            'announcement',
        ];
        foreach ($fields as $f) {
            $val = trim((string)param($f, ''));
            if ($f === 'logo_url' && $val !== '' && !preg_match('#^https?://#i', $val)) {
                json_out(['success' => false, 'error' => 'Logo URL 必须以 http:// 或 https:// 开头']);
            }
            set_setting($f, $val);
        }
        // Logo 上传处理
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allow = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($ext, $allow, true)) {
                // 扩展名可伪造，需用 getimagesize 校验真实图片类型
                $imgInfo = @getimagesize($_FILES['logo']['tmp_name']);
                if ($imgInfo === false) {
                    json_out(['success' => false, 'error' => '上传的文件不是有效的图片']);
                }
                $dir = __DIR__ . '/../../uploads';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $fname = 'logo_' . time() . '.' . $ext;
                $target = $dir . '/' . $fname;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $target)) {
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? '';
                    set_setting('logo_url', $protocol . '://' . $host . '/uploads/' . $fname);
                }
            }
        }
        // 站点配置含品牌/公告等对外展示内容，变更需审计以追溯展示内容修改人
        audit_log('site_settings', 'site_config', 'ok');
        json_out(['success' => true]);
    }
    $defaults = [
        'company_name'   => '劲旋风航空',
        'company_website'=> 'jxfpropeller.com',
        'site_title'     => '工资查询',
        'site_subtitle'  => '团结奋进 知难而进 不断改进',
        'theme_color'    => '#07c160',
        'logo_url'       => '',
        'announcement'   => '',
    ];
    // 一次性批量读取所有站点配置，避免循环 N+1 查询
    $values = get_settings_by_keys(array_keys($defaults), '');
    $data = ['success' => true];
    foreach ($defaults as $k => $v) {
        $data[$k] = ($values[$k] !== '') ? $values[$k] : $v;
    }
    json_out($data);
}

/**
 * 页面访问权限管理
 * GET  /api/admin/page_access — 读取当前权限矩阵
 * POST /api/admin/page_access — 保存权限矩阵
 */
function handle_admin_page_access() {
    require_admin_session([ROLE_ADMIN]);
    // 重置为默认：删除自定义权限配置，让 get_role_page_access 返回 null
    $action = (string)param('action', '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reset') {
        set_setting('role_page_access', '');
        // 重置权限矩阵会放开所有限制，属高危操作，必须审计
        audit_log('page_access_reset', 'role_page_access', 'ok');
        json_out(['success' => true, 'message' => '已恢复默认权限']);
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            json_out(['success' => false, 'error' => '数据格式错误']);
            return;
        }
        // 白名单校验：只允许 finance 和 hr 角色的页面权限被配置
        $validRoles = [ROLE_FINANCE, ROLE_HR];
        $allPages = array_column(get_page_definitions(), 'page');
        $clean = [];
        foreach ($validRoles as $r) {
            if (isset($data[$r]) && is_array($data[$r])) {
                $clean[$r] = array_values(array_intersect($allPages, $data[$r]));
            } else {
                $clean[$r] = [];
            }
        }
        set_setting('role_page_access', json_encode($clean, JSON_UNESCAPED_UNICODE));
        // 权限矩阵变更直接影响各角色可见页面，需审计以追踪授权范围调整
        audit_log('page_access_save', 'role_page_access', 'ok', ['finance' => count($clean['finance'] ?? []), 'hr' => count($clean['hr'] ?? [])]);
        json_out(['success' => true]);
        return;
    }
    // GET：返回页面定义 + 当前权限矩阵
    $pages = get_page_definitions();
    $custom = get_role_page_access();
    // 构建默认权限矩阵（admin 始终全选）
    $defaultAccess = [];
    foreach ($pages as $p) {
        foreach ([ROLE_ADMIN, ROLE_FINANCE, ROLE_HR] as $r) {
            if (in_array($r, $p['roles'], true)) {
                $defaultAccess[$r][] = $p['page'];
            }
        }
    }
    $access = $custom !== null ? $custom : $defaultAccess;
    // 确保 admin 始终拥有所有页面
    $access[ROLE_ADMIN] = array_column($pages, 'page');
    json_out([
        'success'  => true,
        'pages'    => $pages,
        'access'   => $access,
        'is_custom' => $custom !== null,
    ]);
}

/**
 * 年假同步参数（系统设置→企业微信→年假同步参数卡片）
 *   GET  /api/admin/settings/vacation           读取配置 + 默认控件索引值
 *   POST /api/admin/settings/vacation           保存（template_id / hire_date_idx / daterange_idx）+ 审计日志
 *   POST /api/admin/settings/vacation?action=check_template
 *        校验模板控件索引：传入 sample_sp_no（示例审批单号），拉取详情并返回扁平控件列表，
 *        前端据此显示"某索引对应 Date/DateRange 是否正确"。
 */
function handle_admin_settings_vacation() {
    require_admin_session([ROLE_ADMIN]);

    $action = (string)param('action', '');

    // ==== 校验模板控件索引 ====
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'check_template') {
        $templateId    = trim((string)param('template_id', ''));
        $hireIdx       = (string)param('hire_date_idx', '2');
        $drIdx         = (string)param('daterange_idx', '5');
        $sampleSpNo    = trim((string)param('sample_sp_no', ''));
        if ($templateId === '') {
            json_out(['success' => false, 'error' => '请先填写年假审批模板ID']);
        }
        if ($sampleSpNo === '') {
            // 若未填示例单号，查本地 vacation_approvals 是否有该模板的已保存审批单
            try {
                $db = get_db();
                $stmt = $db->prepare("SELECT sp_no FROM vacation_approvals WHERE template_id = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$templateId]);
                $row = $stmt->fetch();
                if ($row && !empty($row['sp_no'])) {
                    $sampleSpNo = (string)$row['sp_no'];
                }
            } catch (Throwable $e) {}
        }
        if ($sampleSpNo === '') {
            json_out([
                'success' => false,
                'error'   => '请先填写「示例审批单号」。若系统尚未同步过该模板的审批单，请先在企微中发起一条年假审批（任意状态均可），再把审批单号填到此处，系统将拉取详情并为你展示控件索引。',
            ]);
        }
        $detail = wecom_get_approval_detail($sampleSpNo);
        if (!$detail['ok']) {
            json_out([
                'success' => false,
                'error'   => '拉取审批单详情失败：' . ($detail['errmsg'] ?? '未知错误') . '（errcode: ' . ($detail['errcode'] ?? -1) . '）',
            ]);
        }
        if (!empty($detail['template_id']) && $detail['template_id'] !== $templateId) {
            json_out([
                'success' => false,
                'error'   => '该示例审批单的模板ID（' . htmlspecialchars($detail['template_id']) . '）与上方填写的年假模板ID不一致，请检查。',
            ]);
        }
        $flat = wecom_approval_flatten_controls($detail['apply_data']);
        // 回显当前已填索引对应的控件，并做类型提示
        // hire 控件允许缺失：有的年假模板不包含入职日期字段（入职日期主存储为 users.hire_date），此时 WARN 不 ERROR
        $hireCheck = ['idx' => $hireIdx, 'found' => false, 'title' => '', 'type' => '', 'ok' => true, 'warn_only' => false, 'hint' => ''];
        $drCheck   = ['idx' => $drIdx,   'found' => false, 'title' => '', 'type' => '', 'ok' => false, 'warn_only' => false, 'hint' => ''];
        foreach ($flat as $c) {
            if ((string)$c['idx'] === (string)$hireIdx) {
                $hireCheck['found'] = true;
                $hireCheck['title'] = $c['title'];
                $hireCheck['type']  = $c['type'];
                if (strcasecmp($c['type'], 'date') === 0) {
                    $hireCheck['hint'] = '✅ 类型正确（Date 日期控件）。注意：如果模板实际没有入职日期控件，此处 Date 可能是"休假开始"等其他日期控件，值不会回写到 users.hire_date。';
                } else {
                    $hireCheck['hint'] = '⚠️ 类型不匹配：当前控件类型是 ' . htmlspecialchars($c['type'] ?: $c['control']) . '，若这是入职日期控件请调整；若模板本身无入职日期控件可忽略。';
                }
            }
            if ((string)$c['idx'] === (string)$drIdx) {
                $drCheck['found'] = true;
                $drCheck['title'] = $c['title'];
                $drCheck['type']  = $c['type'];
                if (strcasecmp($c['type'], 'daterange') === 0) {
                    $drCheck['ok']  = true;
                    // 取示例值中的 days_8h 展示，直观验证天数换算是否正确
                    $dr = wecom_approval_extract_value($detail['apply_data'], (string)$drIdx);
                    if (is_array($dr)) {
                        $drCheck['hint'] = '✅ 类型正确（DateRange 日期范围）。示例：'
                            . ($dr['start'] ?? '?') . ' ~ ' . ($dr['end'] ?? '?')
                            . '，按 8h/天 = ' . ($dr['days_8h'] ?? 0) . ' 天'
                            . '（perday=' . ($dr['perday_seconds'] ?? 0) . '秒）';
                    } else {
                        $drCheck['hint'] = '✅ 类型正确（DateRange 日期范围）';
                    }
                } else {
                    $drCheck['hint'] = '❌ 类型不匹配：当前控件类型是 ' . htmlspecialchars($c['type'] ?: $c['control']) . '，应为 DateRange（日期范围）。请核对索引。';
                }
            }
        }
        if (!$hireCheck['found']) {
            $hireCheck['warn_only'] = true;
            $hireCheck['hint'] = '💡 未找到索引为 ' . htmlspecialchars($hireIdx) . ' 的控件（可能模板本身无入职日期字段：入职日期主存储为 users.hire_date，同步时只回填空值）。可忽略继续保存，或改填正确索引。';
        }
        if (!$drCheck['found']) {
            $drCheck['hint'] = '❌ 未找到索引为 ' . htmlspecialchars($drIdx) . ' 的控件，请检查下方列表。';
        }
        $resp = [
            'success'       => true,
            'sample_sp_no'  => $sampleSpNo,
            'sp_name'       => $detail['sp_name'] ?? '',
            'applyer'       => $detail['apply_name'] ?? '',
            'sp_status'     => $detail['sp_status'] ?? 0,
            'controls'      => $flat,
            'hire_check'    => $hireCheck,
            'daterange_check' => $drCheck,
        ];
        // 控件列表为空或 apply_data 为空时，附带原始 HTTP 返回与完整数据结构，便于排查
        if (empty($flat) || empty($detail['apply_data'])) {
            $resp['debug_raw']              = $detail['raw'] ?? [];
            $resp['debug_apply_data']       = $detail['apply_data'] ?? [];
            $resp['debug_apply_data_keys']  = array_keys((array)($detail['apply_data'] ?? []));
            $resp['debug_info_keys']        = array_keys((array)($detail['raw'] ?? []));
            $resp['debug_http_response']    = $detail['raw_http_response'] ?? null;   // 企微 HTTP 全量返回（含 errcode/errmsg）
            $resp['debug_http_keys']        = array_keys((array)($detail['raw_http_response'] ?? []));
            $resp['debug_http_errcode']     = $detail['http_errcode'] ?? null;
            $resp['debug_http_errmsg']      = $detail['http_errmsg'] ?? null;
            $resp['debug_sp_no_used']       = $sampleSpNo;
            $resp['debug_template_id_sent'] = $templateId;
        }
        json_out($resp);
    }

    // ==== 保存配置 ====
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $templateId  = trim((string)param('vacation_template_id', ''));
        $hireIdxRaw  = trim((string)param('vacation_hire_date_idx', '2'));
        $drIdxRaw    = trim((string)param('vacation_daterange_idx', '5'));
        // 控件索引允许 "5" 或 "2.0.1"（Table 子项），但不允许空
        if ($hireIdxRaw === '' || $drIdxRaw === '') {
            json_out(['success' => false, 'error' => '入职日期控件索引 / 休假DateRange控件索引 不能为空']);
        }
        if (!preg_match('/^[0-9]+(\.[0-9]+)*$/', $hireIdxRaw) || !preg_match('/^[0-9]+(\.[0-9]+)*$/', $drIdxRaw)) {
            json_out(['success' => false, 'error' => '控件索引格式错误，应为纯数字或 "行.列" 形式（如 5 或 2.0.1）']);
        }
        set_setting('vacation_template_id',    $templateId);
        set_setting('vacation_hire_date_idx',  $hireIdxRaw);
        set_setting('vacation_daterange_idx',  $drIdxRaw);
        // vacation_last_sync_at 首次保存时不强制置值，留待同步器首次跑时写
        audit_log('vacation_settings', 'vacation_config', 'ok', [
            'template_id'  => $templateId !== '' ? substr($templateId, 0, 8) . '***' : '',
            'hire_idx'     => $hireIdxRaw,
            'daterange_idx'=> $drIdxRaw,
        ]);
        json_out(['success' => true]);
    }

    // ==== 读取配置 ====
    $keys = ['vacation_template_id', 'vacation_hire_date_idx', 'vacation_daterange_idx', 'vacation_last_sync_at'];
    $vals = get_settings_by_keys($keys, '');
    // 默认值：未配置时按我们之前实测的年假模板（C4em...Sg6CNSaT）给出控件索引建议值
    $out = [
        'vacation_template_id'    => (string)($vals['vacation_template_id'] ?? ''),
        'vacation_hire_date_idx'  => (string)($vals['vacation_hire_date_idx']   !== '' ? $vals['vacation_hire_date_idx']   : '2'),
        'vacation_daterange_idx'  => (string)($vals['vacation_daterange_idx']   !== '' ? $vals['vacation_daterange_idx']   : '5'),
        'vacation_last_sync_at'   => (string)($vals['vacation_last_sync_at'] ?? ''),
    ];
    json_out($out);
}
