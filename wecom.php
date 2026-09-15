<?php
/**
 * 企业微信自建应用 API 封装（零依赖，使用 file_get_contents 发起请求）
 * 包含：access_token 缓存、发送消息、通讯录、网页授权 OAuth、回调验签解密
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';

/**
 * 读取企业微信配置
 */
function wxcfg() {
    return [
        'corpid'          => get_setting('corpid'),
        'agentid'         => get_setting('agentid'),
        'secret'          => get_setting('secret'),
        'contact_secret'  => get_setting('contact_secret'),
        'token'           => get_setting('token'),
        'encoding_aes_key'=> get_setting('encoding_aes_key'),
        'server_domain'   => get_setting('server_domain'),
        'card_source_desc'=> get_setting('card_source_desc'),
        // 通讯录事件服务器（独立 Token / EncodingAESKey）
        'contact_token'    => get_setting('contact_token'),
        'contact_aes_key'  => get_setting('contact_aes_key'),
    ];
}

/**
 * 通用 HTTP 请求（GET/POST），返回解析后的数组
 * 优先使用 cURL；若环境无 cURL 才回退到 file_get_contents。
 * 网络层失败时返回 ['errcode'=>-1,'errmsg'=>'具体错误描述']，便于上层定位问题。
 */
function wx_http($url, $post = null) {
    // 优先 cURL：对 HTTPS / 证书 / 超时控制更好，且能拿到具体错误
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // 用 Mozilla CA 包；若服务器未配置 CA 可降级为不校验证书（仍加密，但不防 MITM）
            CURLOPT_CAINFO         => __DIR__ . '/assets/cacert.pem',
        ];
        // CA 文件不存在时自动降级（开发环境常见），避免直接握手失败
        if (!file_exists(__DIR__ . '/assets/cacert.pem')) {
            $opt[CURLOPT_SSL_VERIFYPEER] = false;
            $opt[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        if ($post !== null) {
            $opt[CURLOPT_POST]       = true;
            $opt[CURLOPT_POSTFIELDS] = is_string($post) ? $post : json_encode($post, JSON_UNESCAPED_UNICODE);
            $opt[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }
        curl_setopt_array($ch, $opt);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch) ?: '未知 cURL 错误';
            $eno = curl_errno($ch);
            curl_close($ch);
            return [
                'errcode' => -1,
                'errmsg'  => "cURL 请求失败($eno)：$err。请检查服务器能否访问 qyapi.weixin.qq.com、PHP 是否启用 curl + openssl 扩展、CA 证书是否就绪。",
            ];
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($resp, true);
        if (is_array($data)) {
            return $data;
        }
        return ['errcode' => -1, 'errmsg' => "HTTP $code 返回非 JSON：" . substr((string)$resp, 0, 200)];
    }

    // 回退：file_get_contents（要求 allow_url_fopen=On 且启用 openssl）
    if (!ini_get('allow_url_fopen')) {
        return [
            'errcode' => -1,
            'errmsg'  => 'PHP 未启用 cURL，且 allow_url_fopen=Off，无法发起 HTTPS 请求。请在 php.ini 开启 extension=curl 与 extension=openssl，或设置 allow_url_fopen=On。',
        ];
    }
    $opts = ['http' => ['timeout' => 10, 'ignore_errors' => true]];
    if ($post !== null) {
        $opts['http']['method'] = 'POST';
        $opts['http']['header'] = "Content-Type: application/json\r\n";
        $opts['http']['content'] = is_string($post) ? $post : json_encode($post, JSON_UNESCAPED_UNICODE);
    }
    $ctx  = stream_context_create($opts);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        $e = error_get_last();
        $msg = $e['message'] ?? '未知错误';
        return [
            'errcode' => -1,
            'errmsg'  => "file_get_contents 请求失败：$msg。请检查 allow_url_fopen / openssl 扩展 / 服务器出网。",
        ];
    }
    $data = json_decode($resp, true);
    return is_array($data) ? $data : ['errcode' => -1, 'errmsg' => '返回非 JSON：' . substr((string)$resp, 0, 200)];
}

/**
 * 获取 access_token（结构化返回，含企微错误码，便于排查）
 *   $type: 'app'       自建应用token（默认）
 *          'contact'   通讯录管理token（需独立 contact_secret）
 *          'approval'  审批API token（与自建应用共用 secret，只需白名单包含 agentid）
 *   返回 ['ok'=>true,'token'=>...] 或 ['ok'=>false,'errcode'=>...,'errmsg'=>...]
 */
function get_access_token_ex($type = 'app') {
    $cfg = wxcfg();
    // approval 类型和 app 复用同一套缓存（secret 相同），避免重复换取
    $cacheKey = ($type === 'contact') ? 'contact' : 'app';
    $cacheFile = sys_get_temp_dir() . '/wx_token_' . md5(__DIR__) . '_' . $cacheKey . '.json';
    if (file_exists($cacheFile)) {
        $c = json_decode(@file_get_contents($cacheFile), true);
        if ($c && !empty($c['token']) && $c['exp'] > time() + 60) {
            return ['ok' => true, 'token' => $c['token']];
        }
    }
    $secret = ($type === 'contact') ? $cfg['contact_secret'] : $cfg['secret'];
    if (empty($cfg['corpid']) || empty($secret)) {
        if ($type === 'contact') {
            return [
                'ok'      => false,
                'errcode' => -2,
                'errmsg'  => '未配置「通讯录管理密钥（通讯录Secret）」。通讯录同步已默认优先使用「自建应用 Secret」，只要该自建应用已开通通讯录读权限并配置可信 IP 即可正常工作；若仍想用通讯录同步助手，请在企业微信后台「管理工具 → 通讯录同步」创建「通讯录同步助手」并获取 Secret，再在本系统「系统设置 → 企业微信配置 → 通讯录Secret」中填写。',
            ];
        }
        $name = ($type === 'approval') ? '企业ID / 应用Secret（审批API使用自建应用凭证）' : '企业ID / 应用Secret';
        return ['ok' => false, 'errcode' => -2, 'errmsg' => "未配置{$name}"];
    }
    $url = "https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid=" . urlencode($cfg['corpid'])
         . "&corpsecret=" . urlencode($secret);
    $r = wx_http($url);
    if (!$r || empty($r['access_token'])) {
        return [
            'ok'      => false,
            'errcode' => isset($r['errcode']) ? (int)$r['errcode'] : -1,
            'errmsg'  => $r['errmsg'] ?? '网络请求失败，无法连接企业微信服务器',
        ];
    }
    @file_put_contents($cacheFile, json_encode(['token' => $r['access_token'], 'exp' => time() + 7200]));
    return ['ok' => true, 'token' => $r['access_token']];
}

/**
 * 获取 access_token（兼容性包装，仅返回 token 字符串或 false）
 */
function get_access_token($type = 'app') {
    $r = get_access_token_ex($type);
    return $r['ok'] ? $r['token'] : false;
}

/**
 * 删除 access_token 缓存文件，强制下次重新获取
 *   在企微返回 42001(token expired) / 40014(invalid token) 时调用
 */
function wecom_refresh_token_cache($type = 'app') {
    $cacheKey = $type === 'contact' ? 'contact' : 'app';
    $cacheFile = sys_get_temp_dir() . '/wx_token_' . md5(__DIR__) . '_' . $cacheKey . '.json';
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }
}

/**
 * 发送文本消息（应用内）
 */
function wecom_send_text($touser, $content) {
    if (empty($touser)) {
        return false;
    }
    $cfg = wxcfg();
    $token = get_access_token('app');
    if (!$token || empty($cfg['agentid'])) {
        return false;
    }
    $body = [
        'touser'  => $touser,
        'msgtype' => 'text',
        'agentid' => (int)$cfg['agentid'],
        'text'    => ['content' => $content],
    ];
    $url = "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=" . urlencode($token);
    $r = wx_http($url, $body);
    $ec = (int)($r['errcode'] ?? -1);
    if (in_array($ec, [42001, 40014], true)) {
        wecom_refresh_token_cache('app');
        $token = get_access_token('app');
        if (!$token) {
            return false;
        }
        $url = "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=" . urlencode($token);
        $r = wx_http($url, $body);
    }
    return $r && (($r['errcode'] ?? -1) == 0);
}

/**
 * 发送 markdown 消息（应用内）
 */
function wecom_send_markdown($touser, $content) {
    if (empty($touser)) {
        return false;
    }
    $cfg = wxcfg();
    $token = get_access_token('app');
    if (!$token || empty($cfg['agentid'])) {
        return false;
    }
    $body = [
        'touser'  => $touser,
        'msgtype' => 'markdown',
        'agentid' => (int)$cfg['agentid'],
        'markdown'=> ['content' => $content],
    ];
    $url = "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=" . urlencode($token);
    $r = wx_http($url, $body);
    $ec = (int)($r['errcode'] ?? -1);
    if (in_array($ec, [42001, 40014], true)) {
        wecom_refresh_token_cache('app');
        $token = get_access_token('app');
        if (!$token) {
            return false;
        }
        $url = "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=" . urlencode($token);
        $r = wx_http($url, $body);
    }
    return $r && (($r['errcode'] ?? -1) == 0);
}

/**
 * 发送 template_card（text_notice）消息：点击卡片整体即可打开 $url（工资条详情）。
 * 用于工资条下发，让员工「点击消息直接看工资」。
 */
function wecom_send_template_card($touser, $title, $content, $url) {
    $cfg = wxcfg();
    $token = get_access_token('app');
    if (!$token) {
        $te = get_access_token_ex('app');
        return ['ok' => false, 'err' => 'access_token 获取失败：' . ($te['errmsg'] ?? '未知原因')];
    }
    if (empty($cfg['agentid'])) {
        return ['ok' => false, 'err' => '未配置 agentid（应用ID）'];
    }
    if (empty($touser)) {
        return ['ok' => false, 'err' => '接收人 userid 为空'];
    }
    $body = [
        'touser'  => $touser,
        'msgtype' => 'template_card',
        'agentid' => (int)$cfg['agentid'],
        'template_card' => [
            'card_type' => 'text_notice',
            'source'    => ['desc' => $cfg['card_source_desc'] ?: ($cfg['server_domain'] ?: '工资条系统')],
            'main_title'=> ['title' => $title ?: '工资条已发布', 'desc' => ''],
            'sub_title_text' => $content,
            'card_action' => ['type' => 1, 'url' => $url],
        ],
    ];
    $r = wx_http('https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=' . urlencode($token), $body);
    $ec = (int)($r['errcode'] ?? -1);
    if (in_array($ec, [42001, 40014], true)) {
        wecom_refresh_token_cache('app');
        $token = get_access_token('app');
        if ($token) {
            $r = wx_http('https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=' . urlencode($token), $body);
            $ec = (int)($r['errcode'] ?? -1);
        }
    }
    if (!$r) {
        return ['ok' => false, 'err' => 'HTTP 请求无响应'];
    }
    if ($ec !== 0) {
        $em = $r['errmsg'] ?? '未知错误';
        $friendly = wecom_friendly_err($ec, $em);
        return ['ok' => false, 'err' => "errcode={$ec} errmsg={$friendly}", 'invaliduser' => $r['invaliduser'] ?? ''];
    }
    return ['ok' => true, 'invaliduser' => $r['invaliduser'] ?? ''];
}

/**
 * 上传临时素材（图片等）
 *   $filepath : 本地文件路径
 *   $type    : image | voice | video | file
 * 返回 ['ok'=>true, 'media_id'=>'xxx'] 或 ['ok'=>false, 'err'=>'xxx']
 */
function wecom_upload_media($filepath, $type = 'image') {
    $token = get_access_token('app');
    if (!$token) {
        return ['ok' => false, 'err' => 'access_token 获取失败'];
    }
    if (!file_exists($filepath)) {
        return ['ok' => false, 'err' => '文件不存在'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'err' => '服务器未启用 cURL，无法上传图片'];
    }
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['media' => new CURLFile($filepath)],
    ];
    if (!file_exists(__DIR__ . '/assets/cacert.pem')) {
        $opt[CURLOPT_SSL_VERIFYPEER] = false;
        $opt[CURLOPT_SSL_VERIFYHOST] = 0;
    }
    // token 过期/无效时刷新并重试一次
    for ($attempt = 0; $attempt <= 1; $attempt++) {
        $url = "https://qyapi.weixin.qq.com/cgi-bin/media/upload?access_token=" . urlencode($token) . "&type=" . $type;
        $ch = curl_init($url);
        curl_setopt_array($ch, $opt);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'err' => '上传失败：' . $err];
        }
        curl_close($ch);
        $data = json_decode($resp, true);
        if ($data && ($data['errcode'] ?? -1) == 0 && !empty($data['media_id'])) {
            return ['ok' => true, 'media_id' => $data['media_id']];
        }
        if (in_array((int)($data['errcode'] ?? -1), [42001, 40014], true) && $attempt === 0) {
            wecom_refresh_token_cache('app');
            $token = get_access_token('app');
            if (!$token) break;
            continue;
        }
        return ['ok' => false, 'err' => $data['errmsg'] ?? '上传失败'];
    }
    return ['ok' => false, 'err' => '上传失败：access_token 获取失败'];
}

/**
 * 统一发送消息（支持 个人/部门/标签 任意组合 + 文本/Markdown/模板卡片/图片）
 *   $msgtype : text | markdown | template_card | image
 *   $content : 文本内容（template_card 时作为 sub_title_text；image 时为 media_id）
 *   $to      : ['touser'=>'a|b|c', 'toparty'=>'1|2', 'totag'=>'3|4']
 *   $title   : template_card 主标题
 * 返回 ['ok'=>true/false, ...]
 */
function wecom_send_message($msgtype, $content, $to, $title = '') {
    $cfg = wxcfg();
    $token = get_access_token('app');
    if (!$token || empty($cfg['agentid'])) {
        return ['ok' => false, 'err' => 'access_token 或 agentid 缺失，请检查企业微信配置'];
    }
    $body = ['agentid' => (int)$cfg['agentid']];
    if (!empty($to['touser'])) {
        $body['touser'] = $to['touser'];
    }
    if (!empty($to['toparty'])) {
        $body['toparty'] = $to['toparty'];
    }
    if (!empty($to['totag'])) {
        $body['totag'] = $to['totag'];
    }
    if (empty($body['touser']) && empty($body['toparty']) && empty($body['totag'])) {
        return ['ok' => false, 'err' => '未选择接收人'];
    }
    $body['msgtype'] = $msgtype;
    if ($msgtype === 'text') {
        $body['text'] = ['content' => $content];
    } elseif ($msgtype === 'markdown') {
        $body['markdown'] = ['content' => $content];
    } elseif ($msgtype === 'template_card') {
        $body['template_card'] = [
            'card_type'   => 'text_notice',
            'source'      => ['desc' => $cfg['card_source_desc'] ?: ($cfg['server_domain'] ?: '工资条系统')],
            'main_title'  => ['title' => $title ?: '通知', 'desc' => ''],
            'sub_title_text' => $content,
        ];
    } elseif ($msgtype === 'image') {
        $body['image'] = ['media_id' => $content];
    } else {
        return ['ok' => false, 'err' => '不支持的消息类型：' . $msgtype];
    }
    $url = "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=" . urlencode($token);
    $r = wx_http($url, $body);
    // token 过期/无效时刷新并重试一次（与 wecom_send_text 等保持一致）
    $ec = (int)($r['errcode'] ?? -1);
    if (in_array($ec, [42001, 40014], true)) {
        wecom_refresh_token_cache('app');
        $token = get_access_token('app');
        if ($token) {
            $url = "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=" . urlencode($token);
            $r = wx_http($url, $body);
        }
    }
    if ($r && (($r['errcode'] ?? -1) == 0)) {
        return [
            'ok' => true,
            'invaliduser' => $r['invaliduser'] ?? '',
            'invalidparty' => $r['invalidparty'] ?? '',
            'invalidtag'  => $r['invalidtag'] ?? '',
        ];
    }
    return ['ok' => false, 'err' => ($r['errmsg'] ?? '发送失败'), 'errcode' => ($r['errcode'] ?? -1)];
}

/**
 * 把企业微信常见 errcode 翻译成运维友好的中文提示
 *   $errcode : 企业微信返回的错误码（int）
 *   $errmsg  : 原始 errmsg
 */
function wecom_friendly_err($errcode, $errmsg) {
    $map = [
        48009 => '通讯录接口被拒绝（errcode 48009：api forbidden for contact assistant）。企业微信自 2022-06 起要求「通讯录同步助手」必须配置可信 IP。请到【企业微信管理后台 → 管理工具 → 通讯录同步】，把本系统服务器的公网 IP 加入「可信 IP」白名单，并确认已开启「API 接口同步」。'
               . '（若白名单已加仍报此错，多为 2022-08-15 后新增的 IP 被政策禁用，需改用「自建应用 Secret」并把该自建应用的可见范围设为根部门来调用通讯录接口。）',
        40001 => 'CorpID 或 Secret 无效（errcode 40001）。请核对系统设置中的企业 ID 与「通讯录Secret」是否与企微后台一致，必要时在企微后台重置 Secret 后重新填写。',
        40013 => 'CorpID 无效（errcode 40013）。请检查系统设置中的企业 ID 是否正确。',
        60020 => '接口访问受限（errcode 60020：IP 不在应用可信 IP 白名单）。请把本系统服务器公网 IP 加入对应应用/通讯录同步的「可信 IP」白名单。',
        60011 => '不允许跨部门/无权限访问通讯录（errcode 60011）。请确认自建应用或通讯录同步的可见范围已覆盖对应部门。',
    ];
    if (isset($map[(int)$errcode])) {
        return $map[(int)$errcode] . '（原始信息：' . $errmsg . '）';
    }
    return $errmsg;
}

/**
 * 调用企业微信通讯录接口（自动处理令牌，并对 48009/60020 做「自建应用 Secret」兜底）
 *   $api   : 接口路径，如 'department/list' / 'user/simplelist' / 'tag/list'
 *   $query : 额外查询串（不含 access_token），如 'department_id=1&fetch_child=1'
 *   返回 ['ok'=>true,'data'=>企微返回的整个数组] 或 ['ok'=>false,'errcode'=>,'errmsg'=>]
 *
 * 说明：errcode 48009(api forbidden for contact assistant) / 60020(IP 不在白名单) 多为
 * 「通讯录同步助手」被企微安全策略拦截。此时自动改用「自建应用 Secret」重试验录接口，
 * 只要该自建应用已开启「通讯录」读权限且其「可信 IP」已包含服务器公网 IP，即可绕过同步助手的限制。
 */
function wecom_contact_get($api, $query = '') {
    // 默认优先使用「自建应用 Secret」读取通讯录；若自建应用无通讯录权限或未配置可信 IP，
    // 再回退到「通讯录同步助手 Secret」。如需调整优先级，交换下面数组顺序即可。
    $types = ['app', 'contact'];
    $lastErr = null;
    foreach ($types as $type) {
        $t = get_access_token_ex($type);
        if (!$t['ok']) {
            $lastErr = $t;
            continue; // 该凭证不可用，尝试下一个
        }
        $sep  = strpos($api, '?') === false ? '?' : '&';
        // token 过期/无效时刷新重试一次（与其他 wecom_send_* 保持一致）
        for ($attempt = 0; $attempt <= 1; $attempt++) {
            $url  = "https://qyapi.weixin.qq.com/cgi-bin/$api$sep" . "access_token=" . urlencode($t['token']);
            if ($query !== '') {
                $url .= '&' . $query;
            }
            $r = wx_http($url);
            if ($r && (($r['errcode'] ?? 0) == 0)) {
                return ['ok' => true, 'data' => $r];
            }
            $ec = (int)($r['errcode'] ?? -1);
            // token 过期/无效 → 刷新后重试一次
            if (in_array($ec, [42001, 40014], true) && $attempt === 0) {
                wecom_refresh_token_cache($type);
                $nt = get_access_token_ex($type);
                if (!$nt['ok']) { break; }
                $t = $nt;
                continue;
            }
            break;
        }
        $lastErr = [
            'errcode' => $ec,
            'errmsg'  => wecom_friendly_err($ec, $r['errmsg'] ?? '请求失败'),
        ];
        // 通讯录接口被拒（48009/60020）且还有其它凭证可试 → 继续尝试
        if (in_array($ec, [48009, 60020], true)) {
            continue;
        }
        return ['ok' => false, 'errcode' => $ec, 'errmsg' => $lastErr['errmsg']];
    }
    if ($lastErr && isset($lastErr['errcode'])) {
        return ['ok' => false, 'errcode' => $lastErr['errcode'], 'errmsg' => $lastErr['errmsg']];
    }
    return [
        'ok'      => false,
        'errcode' => -2,
        'errmsg'  => '获取通讯录访问凭证失败（自建应用与通讯录同步助手均不可用）。请检查「应用Secret / 通讯录Secret」是否已填写，并在企业微信后台把本系统服务器公网 IP 加入对应「可信 IP」白名单。',
    ];
}

/**
 * 获取部门列表（结构化返回，便于错误处理）
 *   返回 ['ok'=>true,'data'=>[...]] 或 ['ok'=>false,'errcode'=>...,'errmsg'=>...]
 */
function wecom_get_departments() {
    $r = wecom_contact_get('department/list');
    if (!$r['ok']) {
        return $r;
    }
    return ['ok' => true, 'data' => $r['data']['department'] ?? []];
}

/**
 * 获取单个部门详情（用于通讯录事件服务器回调后拉取最新部门信息）
 *   返回 ['ok'=>true,'name'=>...,'parentid'=>...] 或 ['ok'=>false,...]
 */
function wecom_get_department_detail($dept_id) {
    $r = wecom_contact_get('department/get', 'id=' . urlencode((string)$dept_id));
    if (!$r['ok']) {
        return $r;
    }
    $dept = $r['data']['department'] ?? [];
    return [
        'ok'       => true,
        'id'       => (int)($dept['id'] ?? 0),
        'name'     => (string)($dept['name'] ?? ''),
        'parentid' => (int)($dept['parentid'] ?? 0),
    ];
}

/**
 * 获取部门成员（结构化返回）
 *   $dept_id：部门ID，传 1 并配合 fetch_child=1 可一次性拉取全量成员
 *   返回 ['ok'=>true,'data'=>[...]] 或 ['ok'=>false,'errcode'=>...,'errmsg'=>...]
 */
function wecom_get_department_users($dept_id) {
    $r = wecom_contact_get('user/simplelist', 'department_id=' . urlencode((string)$dept_id) . '&fetch_child=1');
    if (!$r['ok']) {
        return $r;
    }
    return ['ok' => true, 'data' => $r['data']['userlist'] ?? []];
}

/**
 * 获取标签列表（结构化返回）
 */
function wecom_get_tags() {
    $r = wecom_contact_get('tag/list');
    if (!$r['ok']) {
        return $r;
    }
    return ['ok' => true, 'data' => $r['data']['taglist'] ?? []];
}

/**
 * 获取单个标签的成员明细（tag/get）
 * @return array ['ok'=>true,'data'=>['tagname'=>...,'userlist'=>[['userid','name'],...]]]
 */
function wecom_get_tag_detail($tagid) {
    $r = wecom_contact_get('tag/get?tagid=' . urlencode($tagid));
    if (!$r['ok']) {
        return $r;
    }
    return [
        'ok'      => true,
        'tagname' => $r['data']['tagname'] ?? '',
        'userlist' => $r['data']['userlist'] ?? [],
    ];
}

function wecom_get_user_detail($userid) {
    $r = wecom_contact_get('user/get?userid=' . urlencode($userid));
    if (!$r['ok']) {
        return $r;
    }
    return [
        'ok'   => true,
        'name' => $r['data']['name'] ?? '',
        'userid' => $r['data']['userid'] ?? '',
        'dept_id' => $r['data']['department'][0] ?? 0,
    ];
}

/**
 * 构造网页授权 URL（snsapi_base 静默获取 userid）
 */
function wecom_oauth_url($redirect_uri, $state = '') {
    $cfg = wxcfg();
    $redirect = urlencode($redirect_uri);
    return "https://open.weixin.qq.com/connect/oauth2/authorize?appid=" . urlencode($cfg['corpid'])
         . "&redirect_uri=$redirect&response_type=code&scope=snsapi_base&state="
         . urlencode($state) . "#wechat_redirect";
}

/**
 * 用 code 换取 userid
 */
function wecom_get_userid_by_code($code) {
    $tok = get_access_token_ex('app');
    if (!$tok['ok']) {
        return ['ok' => false, 'errcode' => $tok['errcode'] ?? 'token', 'errmsg' => $tok['errmsg'] ?? '无法获取应用 access_token'];
    }
    $token = $tok['token'];
    // token 过期/无效时刷新重试一次（与 wecom_send_text 等保持一致）
    for ($attempt = 0; $attempt <= 1; $attempt++) {
        $url = "https://qyapi.weixin.qq.com/cgi-bin/auth/getuserinfo?access_token=" . urlencode($token)
             . "&code=" . urlencode($code);
        $r = wx_http($url);
        if (!$r || !is_array($r)) {
            return ['ok' => false, 'errcode' => 'net', 'errmsg' => '企业微信未返回有效响应'];
        }
        // 企业微信该接口返回字段大小写历史上不一致，兼容 userid / UserId / user_id
        $userid = $r['userid'] ?? $r['UserId'] ?? $r['user_id'] ?? '';
        if (($r['errcode'] ?? 0) == 0 && $userid !== '') {
            return ['ok' => true, 'userid' => $userid];
        }
        $ec = (int)($r['errcode'] ?? -1);
        if (in_array($ec, [42001, 40014], true) && $attempt === 0) {
            wecom_refresh_token_cache('app');
            $nt = get_access_token_ex('app');
            if (!$nt['ok']) { break; }
            $token = $nt['token'];
            continue;
        }
        break;
    }
    // errcode 为 0 却无 userid 属异常，附上原始响应便于排查
    $raw = json_encode($r, JSON_UNESCAPED_UNICODE);
    return [
        'ok'      => false,
        'errcode' => $r['errcode'] ?? 'unknown',
        'errmsg'  => ($r['errmsg'] ?? '企业微信未返回用户信息') . ' [原始响应: ' . $raw . ']',
    ];
}

/* ============ 回调消息验签与解密 ============ */

function wecom_signature($token, $timestamp, $nonce, $encrypt) {
    $arr = [$token, (string)$timestamp, (string)$nonce, (string)$encrypt];
    sort($arr, SORT_STRING);
    return sha1(implode('', $arr));
}

function wecom_aes_key($aesKeyStr = null) {
    if ($aesKeyStr === null) {
        $cfg = wxcfg();
        $aesKeyStr = $cfg['encoding_aes_key'];
    }
    return base64_decode($aesKeyStr . '=');
}

/**
 * 解密企业微信回调密文，返回明文消息体
 *   $aesKeyStr：可选，传入通讯录事件服务器的 EncodingAESKey 即可复用同一解密逻辑
 */
function wecom_decrypt($encrypt, $aesKeyStr = null) {
    $key = wecom_aes_key($aesKeyStr);
    if (strlen($key) !== 32) {
        return false;
    }
    $iv = substr($key, 0, 16);
    $cipher = base64_decode($encrypt);
    if ($cipher === false) {
        return false;
    }
    $dec = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    if ($dec === false) {
        return false;
    }
    // 去除 PKCS7 补位
    $pad = ord($dec[strlen($dec) - 1]);
    if ($pad < 1 || $pad > 32) {
        return false;
    }
    $dec = substr($dec, 0, strlen($dec) - $pad);
    // 结构：random(16B) + msg_len(4B 大端) + msg + receiveid
    if (strlen($dec) < 20) {
        return false;
    }
    $msgLen = unpack('N', substr($dec, 16, 4))[1];
    $msg = substr($dec, 20, $msgLen);
    // 提取 receiveid（msg 之后的部分）并与配置的 corpid 校验，防止密文被篡改/串用
    $receiveid = substr($dec, 20 + $msgLen);
    $cfg = wxcfg();
    if (!empty($cfg['corpid']) && $receiveid !== $cfg['corpid']) {
        return false;
    }
    return $msg;
}

// ========================= 审批 API 封装 =========================

/**
 * 获取审批单详情（企微 getapprovaldetail）
 * 文档：https://developer.work.weixin.qq.com/document/path/91983
 *
 * @param string $spNo 审批单号
 * @return array ['ok'=>bool, 'sp_no'=>..., 'apply_data'=>..., 'sp_status'=>..., ...] 或 ['ok'=>false, 'errcode'=>..., 'errmsg'=>...]
 */
function wecom_get_approval_detail($spNo) {
    $tr = get_access_token_ex('approval');
    if (!$tr['ok']) return ['ok' => false, 'errcode' => $tr['errcode'], 'errmsg' => $tr['errmsg']];
    $url = "https://qyapi.weixin.qq.com/cgi-bin/oa/getapprovaldetail?access_token=" . urlencode($tr['token']);
    $r = wx_http($url, ['sp_no' => (string)$spNo]);
    $ec = isset($r['errcode']) ? (int)$r['errcode'] : -1;
    if ($ec !== 0) {
        if (in_array($ec, [42001, 40014], true)) {
            // token 过期，强制刷新后重试一次
            wecom_refresh_token_cache('approval');
            $tr = get_access_token_ex('approval');
            if ($tr['ok']) {
                $url = "https://qyapi.weixin.qq.com/cgi-bin/oa/getapprovaldetail?access_token=" . urlencode($tr['token']);
                $r = wx_http($url, ['sp_no' => (string)$spNo]);
                $ec = isset($r['errcode']) ? (int)$r['errcode'] : -1;
            }
        }
    }
    if ($ec !== 0) {
        return [
            'ok'                => false,
            'errcode'           => $ec,
            'errmsg'            => wecom_friendly_err($ec, $r['errmsg'] ?? '请求审批详情失败'),
            'http_errcode'      => $ec,
            'http_errmsg'       => $r['errmsg'] ?? '',
            'raw_http_response' => $r,
        ];
    }
    // 注意：企微 getapprovaldetail 返回体的审批单容器字段名是 info（不是 approval_info）
    $info = $r['info'] ?? ($r['approval_info'] ?? []);

    // —— 审批单基础表 upsert：任何调用方拿到审批详情后自动入库，无需各入口单独集成 ——
    //  try-catch 静默隔离，写入失败不影响 API 返回值
    try {
        $aoSpNo   = (string)($info['sp_no'] ?? $spNo);
        // 申请人提取：优先 applyer，其次 applicant，最后 batch_applyer（批量审批取第一个）
        $aoUserid = (string)(($info['applyer'] ?? [])['userid'] ?? ($info['applicant'] ?? [])['userid'] ?? '');
        $aoName   = (string)(($info['applyer'] ?? [])['name'] ?? ($info['applicant'] ?? [])['name'] ?? '');
        if ($aoUserid === '' && !empty($info['batch_applyer']) && is_array($info['batch_applyer'])) {
            $firstBatch = $info['batch_applyer'][0] ?? [];
            $aoUserid = (string)($firstBatch['userid'] ?? '');
        }
        // 企微 applyer 不含 name 时从本地 users 表补取（避免额外 API 调用）
        if ($aoName === '' && $aoUserid !== '') {
            $dbTmp = get_db();
            $stmtTmp = $dbTmp->prepare("SELECT name FROM users WHERE wecom_userid = ? OR userid = ? LIMIT 1");
            $stmtTmp->execute([$aoUserid, $aoUserid]);
            $rowTmp = $stmtTmp->fetch();
            if ($rowTmp) $aoName = (string)$rowTmp['name'];
        }
        $aoJsonStr   = json_encode($info, JSON_UNESCAPED_UNICODE);
        $aoCompressed = base64_encode(gzcompress($aoJsonStr, 6));
        $aoDb = get_db();
        $aoIsMysql = (defined('DB_TYPE') && DB_TYPE === 'mysql');
        if ($aoIsMysql) {
            $aoSql = "INSERT INTO approval_orders
                    (sp_no, sp_name, sp_status, template_id, apply_time, userid, name, info, created_at, updated_at)
                    VALUES (?,?,?,?,?,?,?,?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        sp_name=VALUES(sp_name), sp_status=VALUES(sp_status),
                        template_id=VALUES(template_id), apply_time=VALUES(apply_time),
                        userid=VALUES(userid), name=VALUES(name), info=VALUES(info), updated_at=NOW()";
        } else {
            $aoDb->prepare("DELETE FROM approval_orders WHERE sp_no = ?")->execute([$aoSpNo]);
            $aoSql = "INSERT INTO approval_orders
                    (sp_no, sp_name, sp_status, template_id, apply_time, userid, name, info, created_at, updated_at)
                    VALUES (?,?,?,?,?,?,?,?, datetime('now'), datetime('now'))";
        }
        $aoStmt = $aoDb->prepare($aoSql);
        $applyTime = (int)($info['apply_time'] ?? 0);
            $aoStmt->execute([
                $aoSpNo,
                (string)($info['sp_name'] ?? ''),
                (int)($info['sp_status'] ?? 0),
                (string)($info['template_id'] ?? ''),
                $applyTime > 0 ? date('Y-m-d H:i:s', $applyTime) : null,
                $aoUserid,
                $aoName,
                $aoCompressed,
            ]);
    } catch (Throwable $aoErr) {
        error_log("[approval_orders] upsert failed sp_no={$spNo}: " . $aoErr->getMessage());
    }

    // 返回值中的申请人提取也支持 batch_applyer
    $retUserId = (string)(($info['applyer'] ?? [])['userid'] ?? ($info['applicant'] ?? [])['userid'] ?? '');
    $retName   = (string)(($info['applyer'] ?? [])['name'] ?? ($info['applicant'] ?? [])['name'] ?? '');
    if ($retUserId === '' && !empty($info['batch_applyer']) && is_array($info['batch_applyer'])) {
        $firstBatch = $info['batch_applyer'][0] ?? [];
        $retUserId = (string)($firstBatch['userid'] ?? '');
    }

    return [
        'ok'                 => true,
        'sp_no'              => (string)($info['sp_no'] ?? $spNo),
        'sp_name'            => (string)($info['sp_name'] ?? ''),
        'template_id'        => (string)($info['template_id'] ?? ''),
        'sp_status'          => (int)($info['sp_status'] ?? 0),
        // applyer = {userid, partyid}（老版本叫 applicant），这里只取 userid；支持 batch_applyer 批量审批
        'apply_userid'       => $retUserId,
        // 企微审批详情不直接给 applyer.name（只有 userid/partyid），name 由上层调用者再解析
        'apply_name'         => $retName,
        // apply_time 为 0 时返回 null，避免格式化为 1970 年
        'apply_time'         => (int)($info['apply_time'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int)$info['apply_time']) : null,
        'apply_time_ts'      => (int)($info['apply_time'] ?? 0),  // 原始时间戳，供上层使用
        // finish_time 顶层缺失，后续从 sp_record 最后一个节点 sptime 推导
        'finish_time'        => isset($info['finish_time']) ? date('Y-m-d H:i:s', (int)$info['finish_time']) : null,
        'apply_data'         => $info['apply_data'] ?? [],  // 完整的审批表单数据（含 contents 数组）
        'applyer'            => $info['applyer'] ?? ($info['applicant'] ?? ($info['batch_applyer'][0] ?? [])),
        'batch_applyer'      => $info['batch_applyer'] ?? [],
        'raw'                => $info,
        'http_errcode'       => $ec,
        'http_errmsg'        => $r['errmsg'] ?? '',
        'raw_http_response'  => $r,   // 企微 HTTP 全量原始返回（含 errcode/errmsg 等），debug 专用
    ];
}

/**
 * 【底层单次 HTTP 请求】单 chunk 单页拉取审批单号列表（企微 getapprovalinfo）
 * 不做时间合法性校验、不做 chunk 拆分、不做 has_more 翻页 —— 所有智能逻辑在上层 wecom_get_approval_list。
 * 文档：https://developer.work.weixin.qq.com/document/path/91983#批量获取审批单号
 *
 * @param string   $templateId   模板ID（必填）
 * @param int      $startTime    开始时间戳
 * @param int      $endTime      结束时间戳（必须 - start ≤ 31 天，企微硬限制，这里直接透传报错）
 * @param int      $pageSize     分页大小（最大100）
 * @param int|null $nextCursor   游标（传 0 或 null 从第一页开始）
 * @param array|null $spStatuses 【已废弃，仅兼容签名】服务端状态过滤会触发 301025，统一拉回后本地过滤
 * @return array ['ok'=>bool, 'sp_nos'=>[], 'next_cursor'=>?, 'has_more'=>bool, ?errcode, ?errmsg]
 */
function _wecom_get_approval_list_raw($templateId, $startTime, $endTime, $pageSize = 100, $nextCursor = null, $spStatuses = null) {
    $tr = get_access_token_ex('approval');
    if (!$tr['ok']) return ['ok' => false, 'errcode' => $tr['errcode'], 'errmsg' => $tr['errmsg']];
    $body = [
        'starttime'   => (int)$startTime,
        'endtime'     => (int)$endTime,
        'size'        => max(1, min(100, (int)$pageSize)),
        'new_cursor'  => $nextCursor !== null && $nextCursor !== 0 && $nextCursor !== '' ? (string)$nextCursor : '',
        'filters'     => [],
    ];
    // template_id 作为 filters 的一项（企微文档格式：对象数组，每项 {key,value}）
    if ($templateId !== null && $templateId !== '') {
        $body['filters'][] = ['key' => 'template_id', 'value' => (string)$templateId];
    }
    // 注意：企微 getapprovalinfo 文档【注意 1】明确 —— 仅 department 支持多个同 key filter。
    //  sp_status 若传多个 {key:"sp_status"} 会触发 301025 invalid filter；即使只传 1 个状态，
    //  也存在新旧版本兼容性问题（部分租户要求数字/字符串不一致）。
    //  因此：统一不在这里做服务端状态过滤，所有调用方本来就有本地 in_array 兜底，
    //  年假审批单单月几十条，多拉几条后本地过滤零成本且 100% 可靠。
    unset($spStatuses);
    $url = "https://qyapi.weixin.qq.com/cgi-bin/oa/getapprovalinfo?access_token=" . urlencode($tr['token']);
    $r = wx_http($url, $body);
    $ec = isset($r['errcode']) ? (int)$r['errcode'] : -1;
    if (in_array($ec, [42001, 40014], true)) {
        wecom_refresh_token_cache('approval');
        $tr = get_access_token_ex('approval');
        if ($tr['ok']) {
            $url = "https://qyapi.weixin.qq.com/cgi-bin/oa/getapprovalinfo?access_token=" . urlencode($tr['token']);
            $r = wx_http($url, $body);
            $ec = isset($r['errcode']) ? (int)$r['errcode'] : -1;
        }
    }
    if ($ec !== 0) {
        return [
            'ok'         => false,
            'errcode'    => $ec,
            'errmsg'     => wecom_friendly_err($ec, $r['errmsg'] ?? '获取审批单号列表失败'),
            'sp_nos'     => [],
            'next_cursor'=> null,
            'has_more'   => false,
        ];
    }
    $spNos = [];
    foreach ((array)($r['sp_no_list'] ?? []) as $sn) {
        $spNos[] = (string)$sn;
    }
    // 优先使用新游标字段 new_next_cursor，回退旧字段 next_cursor
    $next = null;
    if (isset($r['new_next_cursor']) && $r['new_next_cursor'] !== '') {
        $next = (string)$r['new_next_cursor'];
    } elseif (isset($r['next_cursor']) && $r['next_cursor'] !== '') {
        $next = (string)$r['next_cursor'];
    }
    $hasMore = (int)($r['has_more'] ?? 0) === 1;
    return [
        'ok'          => true,
        'sp_nos'      => $spNos,
        'next_cursor' => $next,
        'has_more'    => $hasMore,
    ];
}

/**
 * 批量拉取审批单号列表（对调用方 100% 透明的安全封装）
 *
 * 解决企微 2 个硬限制：
 *   ① endtime - starttime 不得超过 31 天 —— 内部自动按每 30 天切一个 chunk（留 1 天安全余量），
 *     多轮 HTTP 调用后合并去重 sp_nos。
 *   ② 同一 chunk 内 has_more=true 时分页 —— 内部按 next_cursor 自动翻完所有页，
 *     不再把分页的 next_cursor / has_more 抛给调用方。
 *
 * 因此：调用方传入任意合法时间范围（哪怕全年/跨年），返回值永远是该范围内的**完整** sp_nos 列表，
 * 外层无需关心分页、无需按月切。
 *
 * 文档：https://developer.work.weixin.qq.com/document/path/91983#批量获取审批单号
 *
 * @param string   $templateId   模板ID（必填）
 * @param int      $startTime    开始时间戳
 * @param int      $endTime      结束时间戳（允许 > start+31 天，内部自动切）
 * @param int      $pageSize     分页大小（每 chunk 每页，最大100）
 * @param int|null $nextCursor   【已废弃，仅兼容签名】统一在内部翻完所有页
 * @param array|null $spStatuses 【已废弃，仅兼容签名】服务端过滤会触发 301025，统一本地过滤
 * @return array ['ok'=>bool, 'sp_nos'=>[], 'next_cursor'=>null, 'has_more'=>false, ?errcode, ?errmsg]
 */
function wecom_get_approval_list($templateId, $startTime, $endTime, $pageSize = 100, $nextCursor = null, $spStatuses = null) {
    $s = (int)$startTime;
    $e = (int)$endTime;
    // 1) 基础时间合法性 & 边界修正
    if ($s <= 0 || $e <= 0) {
        return ['ok' => false, 'errcode' => -1, 'errmsg' => 'startTime/endTime 非法', 'sp_nos' => [], 'next_cursor' => null, 'has_more' => false];
    }
    if ($e < $s) {
        // 时间反了：直接交换并沿用（不报错 —— 调用方传反是偶尔会犯的错误，交换更友好）
        list($s, $e) = [$e, $s];
    }
    if ($e === $s) {
        // endtime 必须严格大于 starttime：补齐当天剩余秒数
        $e = $s + 86399;
    }

    // 2) 按 30 天一块拆分（企微最大允许 31 天，留 1 天安全余量，避免临界时刻被拒）
    $CHUNK_SEC = 30 * 86400;
    $chunks = [];
    $cur = $s;
    while ($cur < $e) {
        $nxt = min($cur + $CHUNK_SEC, $e);
        // 再次保证每个 chunk 内 end > start（至少差 1 秒）
        if ($nxt <= $cur) $nxt = $cur + 1;
        $chunks[] = [$cur, $nxt];
        $cur = $nxt;
    }
    // 极端安全：如果 chunks 为空（理论不会到），塞一个原区间
    if (!$chunks) $chunks[] = [$s, $e];

    // 3) 逐 chunk + 逐页（has_more 自动翻）抓 sp_nos
    $all = [];
    foreach ($chunks as $c) {
        list($cs, $ce) = $c;
        $cursor = null;
        do {
            $raw = _wecom_get_approval_list_raw($templateId, $cs, $ce, $pageSize, $cursor, $spStatuses);
            if (!$raw['ok']) {
                // 任一 chunk 任一页失败 → 整体中止，透传企微错误（含时间片段信息，方便定位哪块失败）
                return [
                    'ok'          => false,
                    'errcode'     => $raw['errcode'],
                    'errmsg'      => $raw['errmsg'] . ' (chunk ' . date('Y-m-d', $cs) . '~' . date('Y-m-d', $ce) . ')',
                    'sp_nos'      => [],
                    'next_cursor' => null,
                    'has_more'    => false,
                ];
            }
            foreach ($raw['sp_nos'] as $sn) $all[] = $sn;
            $cursor = $raw['next_cursor'];
        } while (!empty($raw['has_more']) && $cursor !== null && $cursor !== '');
    }

    // 4) 跨 chunk / 跨页 去重（同 1 单不会因边界被记 2 次扣减）
    $all = array_values(array_unique($all));

    return [
        'ok'          => true,
        'sp_nos'      => $all,
        'next_cursor' => null,
        'has_more'    => false,
    ];
}

/**
 * 从审批单详情的 apply_data.contents 解析控件扁平列表（按索引顺序）
 * 返回结构：[ [idx, control, title, type, value_raw], ... ]
 *   type 取值：Text / Number / Money / Date / DateRange / Selector / Contact / Attendance / ...
 * 方便配置页"校验控件索引"时做预览 & 类型核对。
 */
function wecom_approval_flatten_controls($applyData) {
    $contents = (array)($applyData['contents'] ?? []);
    $out = [];
    foreach ($contents as $idx => $c) {
        $control = (string)($c['control'] ?? '');
        $id      = (string)($c['id'] ?? '');
        $title   = '';
        foreach ((array)($c['title'] ?? []) as $t) {
            if (!empty($t['text'])) { $title = (string)$t['text']; break; }
        }
        $value = $c['value'] ?? [];
        $type = '';
        if ($control === 'Table') {
            // Table 控件是多行列子表，递归扁平化，子项索引以 "N.rowIdx.colIdx" 形式返回
            $children = (array)($value['children'] ?? []);
            foreach ($children as $rowIdx => $row) {
                foreach ((array)($row['list'] ?? []) as $colIdx => $col) {
                    $subCtrl = (string)($col['control'] ?? '');
                    $subType = '';
                    if (!empty($col['value'])) {
                        $keys = array_keys((array)$col['value']);
                        $subType = (string)($keys[0] ?? '');
                    }
                    $subTitle = '';
                    foreach ((array)($col['title'] ?? []) as $t) {
                        if (!empty($t['text'])) { $subTitle = (string)$t['text']; break; }
                    }
                    $out[] = [
                        'idx'        => "{$idx}.{$rowIdx}.{$colIdx}",
                        'control'    => $subCtrl,
                        'title'      => $subTitle,
                        'type'       => $subType,
                        'value_raw'  => $col['value'] ?? [],
                    ];
                }
            }
            continue;
        }
        // 企微新版 value 里有大量元信息 key（tips/members/departments/files/children/... 共 14 个）
        // 第一个 key 永远是 "tips"，所以不能按 value 的首 key 推 type。直接用 control 字段更可靠。
        $type = strtolower(trim((string)$control));
        $preview = wecom_approval_preview_for_control($control, $type, $value);
        $out[] = [
            'idx'        => (string)$idx,
            'control'    => $control,
            'title'      => $title,
            'type'       => $type,
            'value_raw'  => $value,
            'preview'    => $preview,
        ];
    }
    return $out;
}

/**
 * 从 apply_data 扁平化结果里按"数字索引或点路径索引"取出单条控件信息。
 * 为调用方提供更短的语法糖：wecom_approval_flatten_control_at($applyData, 2)
 * 等价于：
 *   $all = wecom_approval_flatten_controls($applyData);
 *   foreach ($all as $c) if ($c['idx'] === (string)$idx) return $c;
 *
 * 支持的索引形式（与 settings.vacation_hire_date_idx 格式一致）：
 *   - 纯整数字符串 "2"              → 匹配 contents[2]
 *   - 点路径 "0.0.1"                  → 匹配 Table 0 的第 0 行第 1 列子控件
 *   - 整数 5                          → 同上 (string)5
 *
 * @param array  $applyData  apply_data 数组（企微审批单详情 apply_data 字段）
 * @param int|string $idx    目标控件索引（整数或点路径字符串）
 * @return array|null 找到时返回 flat 项（含 idx/control/title/type/value_raw/preview）；找不到返回 null
 */
function wecom_approval_flatten_control_at($applyData, $idx) {
    $all = wecom_approval_flatten_controls($applyData);
    $target = (string)$idx;
    foreach ($all as $c) {
        if ($c['idx'] === $target) return $c;
    }
    return null;
}

/**
 * 按控件类型把 raw value 转成单行文本预览（给前端"示例值"列展示用）。
 * 保持轻量，不做业务逻辑（业务逻辑统一走 wecom_approval_extract_value）。
 */
function wecom_approval_preview_for_control($control, $type, $value) {
    $ctl = strtolower((string)$control);
    $tp  = strtolower((string)$type);
    if ($ctl === 'contact') {
        $parts = [];
        foreach ((array)(($value['members'] ?? [])) as $m) $parts[] = $m['name'] ?? $m['userid'] ?? '';
        foreach ((array)(($value['departments'] ?? [])) as $d) $parts[] = $d['name'] ?? '';
        return implode(', ', array_filter($parts));
    }
    if ($tp === 'date' || $ctl === 'date') {
        $ts = (int)(($value['date'] ?? [])['timestamp'] ?? ($value['date'] ?? [])['s_timestamp'] ?? 0);
        if ($ts <= 0) return '';
        return date('Y-m-d', $ts);
    }
    if ($tp === 'daterange' || $ctl === 'daterange') {
        $dr = $value['date_range'] ?? [];
        $startTs = (int)($dr['start_timestamp'] ?? $dr['new_begin'] ?? 0);
        $endTs   = (int)($dr['end_timestamp']   ?? $dr['new_end']   ?? 0);
        if ($startTs <= 0 || $endTs <= 0) return '';
        $perDay  = (int)($dr['perday_duration'] ?? 28800);
        $dur     = (int)($dr['new_duration'] ?? 0);
        $days    = $perDay > 0 ? round($dur / $perDay, 2) : 0;
        return date('Y-m-d', $startTs) . ' ~ ' . date('Y-m-d', $endTs) . '（' . $days . '天）';
    }
    if ($tp === 'text' || $tp === 'textarea' || $ctl === 'text' || $ctl === 'textarea') {
        $v = $value['text'] ?? [];
        $s = is_array($v) ? ($v['text'] ?? '') : (string)$v;
        if (safe_strlen($s) > 60) $s = safe_substr($s, 0, 60) . '…';
        return $s;
    }
    if ($tp === 'number' || $ctl === 'number') {
        return (string)($value['new_number'] ?? '');
    }
    if ($tp === 'money' || $ctl === 'money') {
        return (string)(($value['money'] ?? [])['new_amount'] ?? '');
    }
    if ($tp === 'selector' || $ctl === 'selector') {
        $opts = (array)(($value['selector'] ?? [])['options'] ?? []);
        $tags = [];
        foreach ($opts as $o) {
            foreach ((array)($o['value'] ?? []) as $t) {
                if (!empty($t['text'])) { $tags[] = (string)$t['text']; break; }
            }
        }
        return implode(' / ', $tags);
    }
    if ($tp === 'tips' || $ctl === 'tips') {
        // Tips 控件不承载业务值，展示"（说明类控件）"即可
        return '（说明类控件）';
    }
    return '';
}

/**
 * 从审批单详情中按控件索引提取具体业务值
 * 支持类型：
 *   Date        → 返回 'YYYY-MM-DD' 字符串
 *   DateRange   → 返回 ['start'=>'Y-m-d', 'end'=>'Y-m-d', 'new_duration'=>秒, 'days_8h'=>按8小时/天的天数, 'days_24h'=>按自然日天数]
 *   Text/Number/Money → 返回原始字符串/数值
 *
 * @param array  $applyData    审批单 apply_data
 * @param string $idx          控件索引（整数或 "N.row.col" 格式的Table子项）
 * @return mixed 解析后的值；找不到或解析失败返回 null
 */
function wecom_approval_extract_value($applyData, $idx) {
    $flat = wecom_approval_flatten_controls($applyData);
    $hit = null;
    foreach ($flat as $c) {
        if ((string)$c['idx'] === (string)$idx) { $hit = $c; break; }
    }
    if (!$hit) return null;
    $raw = $hit['value_raw'];
    $type = strtolower(trim((string)$hit['type']));
    // textarea 与 text 同样取值自 value.text.text，合并处理
    if ($type === 'textarea') $type = 'text';
    switch ($type) {
        case 'date': {
            // 企微日期控件新老格式兼容：老 date.timestamp / 新 date.s_timestamp
            $ts = (int)($raw['date']['timestamp'] ?? $raw['date']['s_timestamp'] ?? 0);
            if ($ts <= 0) return null;
            return date('Y-m-d', $ts);
        }
        case 'daterange': {
            $dr = $raw['date_range'] ?? [];
            $newDur = (int)($dr['new_duration'] ?? 0);
            $perDay = (int)($dr['perday_duration'] ?? 28800);  // 企微默认 8 小时/天
            // 企微日期范围新老格式兼容：老 start_timestamp/end_timestamp / 新 new_begin/new_end
            $startTs = (int)($dr['start_timestamp'] ?? $dr['new_begin'] ?? 0);
            $endTs   = (int)($dr['end_timestamp']   ?? $dr['new_end']   ?? 0);
            $days8h  = $perDay > 0 ? round($newDur / $perDay, 2) : 0;
            $days24h = ($startTs > 0 && $endTs > 0) ? round(($endTs - $startTs) / 86400 + 1, 2) : 0;
            return [
                'start'           => $startTs > 0 ? date('Y-m-d', $startTs) : null,
                'end'             => $endTs   > 0 ? date('Y-m-d', $endTs)   : null,
                'start_ts'        => $startTs > 0 ? $startTs : null,
                'end_ts'          => $endTs   > 0 ? $endTs   : null,
                'start_dt'        => $startTs > 0 ? date('Y-m-d H:i', $startTs) : null,
                'end_dt'          => $endTs   > 0 ? date('Y-m-d H:i', $endTs)   : null,
                'new_duration'    => $newDur,
                'perday_seconds'  => $perDay,
                'days_8h'         => $days8h,
                'days_24h'        => $days24h,
            ];
        }
        case 'text':
            return (string)($raw['text']['text'] ?? '');
        case 'number':
            return (string)($raw['new_number'] ?? '');
        case 'money':
            return (string)(($raw['money'] ?? [])['new_amount'] ?? '');
        case 'selector': {
            // 单选/多选：返回选项名称数组
            $opts = (array)(($raw['selector'] ?? [])['options'] ?? []);
            $names = [];
            foreach ($opts as $o) {
                foreach ((array)($o['value'] ?? []) as $t) {
                    if (!empty($t['text'])) { $names[] = (string)$t['text']; break; }
                }
            }
            return $names;
        }
        default:
            return $raw;
    }
}

/**
 * 从审批单详情提取休假起始年度（按 leave_start 归属年份），用于 vacation_ledger.year 字段
 * 业务规则：不允许跨年休假，所有扣减归属于休假开始日期所在年度
 */
function wecom_approval_leave_year($applyData, $daterangeIdx) {
    $dr = wecom_approval_extract_value($applyData, (string)$daterangeIdx);
    if (is_array($dr) && !empty($dr['start'])) {
        return (int)date('Y', strtotime($dr['start']));
    }
    return (int)date('Y');
}

/**
 * 从 approval_orders 表读取并解压 info 字段。
 *
 * @param string $spNo 审批单号
 * @return array|null 审批单行（info 字段已解压为 array），找不到返回 null
 */
function get_approval_order($spNo) {
    $spNo = (string)$spNo;
    if ($spNo === '') return null;

    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM approval_orders WHERE sp_no = ? LIMIT 1");
    $stmt->execute([$spNo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    // 解压 info
    if (!empty($row['info'])) {
        $decoded = base64_decode($row['info']);
        if ($decoded !== false) {
            $decompressed = gzuncompress($decoded);
            if ($decompressed !== false) {
                $row['info'] = json_decode($decompressed, true);
            }
        }
    }
    return $row;
}
