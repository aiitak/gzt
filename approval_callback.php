<?php
/**
 * 审批状态回调接收脚本（签名校验 + AES 解密 + 增量同步 + 结构化入库）
 *
 * 用途：接收企业微信「审批系统应用」推送的审批状态回调。
 *   GET  — 企微 URL 验证：签名校验 + echostr 解密后返回明文
 *   POST — 审批状态变化通知：签名校验 + AES 解密 → 提取 sp_no →
 *          调用 vacation_process_sp_detail 增量同步 → 结果写入 approval_callback_logs 表
 *
 * 配置（企微后台 → 应用管理 → 审批系统应用 → 接收事件服务器配置）：
 *   URL             : https://gzx.jxf.ink/approval_callback.php
 *   Token           : OmcFVzyp9r3
 *   EncodingAESKey  : eyiJnRVirYSrjEKHTsXo7xjEs3LmWj5TJFlOJlTxpce
 *
 * 日志：approval_callback_logs 表（DB），不再写文件
 */

// 审批系统应用专属的回调凭证（与企微后台"接收事件"配置保持一致）
define('APPROVAL_CALLBACK_TOKEN',    'OmcFVzyp9r3');
define('APPROVAL_CALLBACK_AES_KEY', 'eyiJnRVirYSrjEKHTsXo7xjEs3LmWj5TJFlOJlTxpce');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/wecom.php';

$method      = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
$requestTime = date('Y-m-d H:i:s');
$remoteIp    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

/**
 * 写入回调日志到 DB
 */
$cb_log = function (array $fields) use ($requestTime, $method, $remoteIp) {
    static $inserted = false;
    if ($inserted) return; // 每次请求只记一条
    try {
        $db = get_db();
        $nowExpr = (defined('DB_TYPE') && DB_TYPE === 'mysql') ? 'NOW()' : "datetime('now')";
        // 列：received_at(1) method(2) remote_ip(3) sp_no(4) template_id(5) sp_status(6)
        //    apply_name(7) signature_ok(8) decrypt_ok(9) sync_status(10) sync_detail(11)
        //    created_at 用表达式，不用占位符 → 共 11 个 ?
        $stmt = $db->prepare(
            "INSERT INTO approval_callback_logs
                (received_at, method, remote_ip, sp_no, template_id, sp_status,
                 apply_name, signature_ok, decrypt_ok, sync_status, sync_detail, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?," . $nowExpr . ")"
        );
        $stmt->execute([
            $requestTime, $method, $remoteIp,
            $fields['sp_no']        ?? '',
            $fields['template_id']  ?? '',
            $fields['sp_status']    ?? 0,
            $fields['apply_name']   ?? '',
            $fields['signature_ok'] ?? 0,
            $fields['decrypt_ok']   ?? 0,
            $fields['sync_status']  ?? '',
            safe_substr($fields['sync_detail'] ?? '', 0, 480),
        ]);
        $inserted = true;
    } catch (Throwable $e) {
        // DB 写入失败不影响回调响应
    }
};

// ---- GET：企微 URL 验证请求 ------------------------------------------------
if ($method === 'GET') {
    $echostr      = $_GET['echostr']       ?? '';
    $msgSignature = $_GET['msg_signature'] ?? '';
    $timestamp    = $_GET['timestamp']     ?? '';
    $nonce        = $_GET['nonce']         ?? '';

    $signatureOK  = false;
    $plainEchostr = '';
    $decryptOK    = false;
    $responseBody = '';

    if ($msgSignature !== '' && $timestamp !== '' && $nonce !== '' && $echostr !== '') {
        $calcSig = wecom_signature(APPROVAL_CALLBACK_TOKEN, $timestamp, $nonce, $echostr);
        $signatureOK = hash_equals($msgSignature, $calcSig);

        if ($signatureOK) {
            $plainEchostr = wecom_decrypt($echostr, APPROVAL_CALLBACK_AES_KEY);
            $decryptOK = ($plainEchostr !== false && $plainEchostr !== '');

            if (!$decryptOK) {
                // 兜底解密：审批应用推送的 receiveid 可能非 corpid
                $plainEchostr = approval_decrypt_no_verify($echostr, APPROVAL_CALLBACK_AES_KEY);
                $decryptOK = ($plainEchostr !== false && $plainEchostr !== '');
            }

            if ($decryptOK) {
                $responseBody = $plainEchostr;
            }
        }
    }

    $cbResult = ($signatureOK && $decryptOK) ? 'ok' : 'fail';
    // 写入 DB 日志
    $cb_log([
        'signature_ok' => $signatureOK ? 1 : 0,
        'decrypt_ok'   => $decryptOK ? 1 : 0,
        'sync_status'  => $cbResult === 'ok' ? 'url_verified' : 'url_verify_failed',
        'sync_detail'  => $responseBody !== '' ? 'URL验证成功' : 'URL验证失败',
    ]);
    try {
        require_once __DIR__ . '/functions.php';
        // 回调无人登录：op_user 强制写"审批回调"
        audit_log_for_callback(
            '审批回调',
            $cbResult === 'ok' ? 'wecom_cb_approval_url' : 'wecom_cb_approval_fail',
            'from=approval',
            $cbResult,
            [
                'sig'       => $signatureOK ? 1 : 0,
                'decrypt'   => $decryptOK   ? 1 : 0,
                'timestamp' => $timestamp,
                'nonce'     => $nonce,
                'err'       => $cbResult === 'ok' ? null : ($signatureOK ? 'AES解密失败' : '签名不匹配'),
            ]
        );
    } catch (Throwable $e) { /* 审计失败不影响主流程 */ }

    header('Content-Type: text/plain; charset=utf-8');
    echo $responseBody !== '' ? $responseBody : 'invalid request';
    exit;
}

// ---- POST：审批状态变化回调通知 ---------------------------------------------
if ($method === 'POST') {
    $rawBody = file_get_contents('php://input');

    // 1) 签名三参数 + encrypt 密文（query 优先，body 补齐）
    $qSig = $_GET['msg_signature'] ?? '';
    $qTs  = $_GET['timestamp']     ?? '';
    $qNo  = $_GET['nonce']         ?? '';

    // body 按 JSON 解析；失败再按 XML
    $bodyStruct = null;
    $bodyJson = json_decode($rawBody, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($bodyJson)) {
        $bodyStruct = $bodyJson;
    } else {
        $xml = simplexml_load_string($rawBody, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml !== false) {
            $arr = json_decode(json_encode($xml), true);
            if (is_array($arr)) $bodyStruct = $arr;
        }
    }

    // 递归提取字段（兼容大小写不固定）
    $walk = static function ($arr, array $keys) use (&$walk) {
        foreach ($keys as $kn) {
            foreach ([$kn, strtolower($kn), ucfirst(strtolower($kn)), strtoupper($kn)] as $variant) {
                if (isset($arr[$variant]) && $arr[$variant] !== '') {
                    return (string)$arr[$variant];
                }
            }
        }
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $res = $walk($v, $keys);
                if ($res !== null) return $res;
            }
        }
        return null;
    };

    // 企微审批状态码 → 中文（audit_log 详情专用，DB 日志仍存原始数字便于查询）
    if (!function_exists('_spStatusText')) {
        function _spStatusText(int $code): string {
            $map = [1=>'审批中', 2=>'已通过', 3=>'已驳回', 4=>'已撤销', 6=>'通过后撤销', 7=>'已加签'];
            return $map[$code] ?? ('未知(' . $code . ')');
        }
    }

    $bSig = ''; $bTs = ''; $bNo = ''; $encrypt = '';
    if (is_array($bodyStruct)) {
        $bSig = (string)($walk($bodyStruct, ['MsgSignature']) ?? '');
        $bTs  = (string)($walk($bodyStruct, ['TimeStamp', 'Timestamp']) ?? '');
        $bNo  = (string)($walk($bodyStruct, ['Nonce']) ?? '');
        $encrypt = (string)($walk($bodyStruct, ['Encrypt']) ?? '');
    }

    $signature = $qSig !== '' ? $qSig : $bSig;
    $timestamp = $qTs  !== '' ? $qTs  : $bTs;
    $nonce     = $qNo  !== '' ? $qNo  : $bNo;

    // 2) 签名校验
    $signatureOK = false;
    if ($encrypt !== '' && $signature !== '' && $timestamp !== '' && $nonce !== '') {
        $calcSig = wecom_signature(APPROVAL_CALLBACK_TOKEN, $timestamp, $nonce, $encrypt);
        $signatureOK = hash_equals($signature, $calcSig);
    }

    // 3) AES 解密
    $plainText = false;
    if ($encrypt !== '') {
        $plainText = wecom_decrypt($encrypt, APPROVAL_CALLBACK_AES_KEY);
        if ($plainText === false) {
            $plainText = approval_decrypt_no_verify($encrypt, APPROVAL_CALLBACK_AES_KEY);
        }
    }
    $decryptOK = ($plainText !== false && $plainText !== '');

    // 4) 解析明文 + 提取 sp_no + 增量同步
    $spNo = '';
    $parsed = null;
    $syncStatus = 'not_applicable';
    $syncDetail = '';
    $syncFields = ['sp_no' => '', 'template_id' => '', 'sp_status' => 0, 'apply_name' => ''];

    if ($plainText !== false && $plainText !== '') {
        $parsed = json_decode($plainText, true);
        if (!is_array($parsed)) {
            $xml = simplexml_load_string($plainText, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml !== false) {
                $parsed = json_decode(json_encode($xml), true);
            }
        }
    }

    if (is_array($parsed)) {
        $spNo = approval_extract_sp_no($parsed);
        $syncFields['sp_no'] = $spNo;

        // 提取模板ID、审批状态、申请人（复用上方 $walk 递归函数，避免重复实现）
        $syncFields['template_id'] = (string)($walk($parsed, ['TemplateId', 'template_id']) ?? '');
        $syncFields['sp_status']   = (int)($walk($parsed, ['SpStatus', 'sp_status']) ?? 0);
        $syncFields['apply_name']   = (string)($walk($parsed, ['ApplyName', 'apply_name', 'ApplyUserName', 'apply_user_name']) ?? '');
    }

    if ($spNo !== '') {
        try {
            $r = vacation_process_sp_detail($spNo);
            if (!empty($r['error'])) {
                $syncStatus = 'error';
                $syncDetail = $r['error'];
                if (!empty($r['skip_reason'])) {
                    // wrong_account 等需要人看的"跳过类"不归入 error 语义
                    switch ($r['skip_reason']) {
                        case 'wrong_account':
                            $syncStatus = 'skipped_account';
                            break;
                    }
                }
            } elseif (!empty($r['skipped'])) {
                $reason = (string)($r['skip_reason'] ?? 'unknown');
                switch ($reason) {
                    case 'wrong_template':
                        $syncStatus = 'skipped_tpl';
                        $spName = trim((string)($r['sp_name'] ?? ''));
                        if ($spName !== '') {
                            $syncDetail = '非年假模板：' . $spName;
                        } else {
                            $got = (string)($r['got_template_id'] ?? '');
                            if ($got === '') $got = '(空)';
                            else             $got = safe_substr($got, 0, 8) . '***…';
                            $syncDetail = '非年假模板（实际=' . $got . '）';
                        }
                        break;
                    case 'pending_status':
                        $syncStatus = 'skipped_status';
                        $stText = _spStatusText((int)($r['sp_status'] ?? 0));
                        $syncDetail = '状态=' . $stText . '，仅处理已通过/通过后撤销；待流程推进后会自动同步';
                        break;
                    case 'zero_days':
                        $syncStatus = 'skipped';
                        $syncDetail = 'DateRange 解析为 0 天（请检查 vacation_daterange_idx 指向）';
                        break;
                    default:
                        $syncStatus = 'skipped';
                        $syncDetail = '已跳过（原因未分类）';
                }
            } else {
                $syncStatus = 'success';
                $parts = [];
                $parts[] = $r['approval_inserted'] ? '审批快照已写入' : '审批快照已更新';
                $parts[] = $r['ledger_changed'] ? 'ledger已变动' : 'ledger无变动';
                $syncDetail = implode('，', $parts);
            }
        } catch (Throwable $e) {
            $syncStatus = 'error';
            $syncDetail = $e->getMessage();
        }
    } else {
        $syncStatus = 'no_sp_no';
        $syncDetail = '未提取到审批单号';
    }

    // 5) 写入 DB 日志 + audit_log 审计
    // 用 vacation_process_sp_detail 返回的姓名补充空值（非年假模板也能拿到申请人）
    if (empty($syncFields['apply_name']) && !empty($r['name'])) {
        $syncFields['apply_name'] = $r['name'];
    }
    $cb_log(array_merge($syncFields, [
        'signature_ok' => $signatureOK ? 1 : 0,
        'decrypt_ok'   => $decryptOK ? 1 : 0,
        'sync_status'  => $syncStatus,
        'sync_detail'  => $syncDetail,
    ]));
    try {
        require_once __DIR__ . '/functions.php';
        $tplRaw   = (string)($syncFields['template_id'] ?? '');
        $tplShort = '';
        if ($tplRaw !== '') {
            $tplShort = (strlen($tplRaw) > 12)
                ? (substr($tplRaw, 0, 8) . '***…' . substr($tplRaw, -4))
                : $tplRaw;
        }
        if (!$signatureOK || !$decryptOK) {
            audit_log_for_callback(
                '审批回调',
                'wecom_cb_approval_fail',
                $spNo !== '' ? ('sp:' . $spNo) : 'from=approval',
                'fail',
                [
                    'sig'         => $signatureOK ? 1 : 0,
                    'decrypt'     => $decryptOK   ? 1 : 0,
                    'err'         => !$signatureOK ? '签名不匹配' : (!$decryptOK ? 'AES解密失败' : null),
                    'tpl_id'      => $tplRaw !== '' ? $tplRaw : '',
                    'sp_status'   => _spStatusText((int)($syncFields['sp_status'] ?? 0)),
                    'apply_name'  => $syncFields['apply_name'] ?? '',
                    'sync_status' => $syncStatus,
                    'sync_detail' => $syncDetail,
                    'preview'     => $plainText !== '' ? safe_substr($plainText, 0, 800) : null,
                ]
            );
        } else {
            $opResult = ($syncStatus === 'error') ? 'fail' : 'ok';
            audit_log_for_callback(
                '审批回调',
                'wecom_cb_approval_event',
                $spNo !== '' ? ('sp:' . $spNo . ($syncFields['apply_name'] ? (' [' . $syncFields['apply_name'] . ']') : '')) : 'from=approval',
                $opResult,
                [
                    'tpl_id'      => $tplRaw !== '' ? $tplRaw : '',
                    'sp_status'   => _spStatusText((int)($syncFields['sp_status'] ?? 0)),
                    'apply_name'  => $syncFields['apply_name'] ?? '',
                    'sync_status' => $syncStatus,
                    'sync_detail' => $syncDetail,
                ]
            );
        }
    } catch (Throwable $e) { /* 审计失败不影响主流程 */ }

    header('Content-Type: text/plain; charset=utf-8');
    echo 'success';
    exit;
}

// ---- 兜底 -------------------------------------------------------------------
$cb_log([
    'sync_status'  => 'unsupported_method',
    'sync_detail'  => '不支持的 method: ' . $method,
]);
try {
    require_once __DIR__ . '/functions.php';
    audit_log_for_callback(
        '审批回调',
        'wecom_cb_approval_fail',
        'from=approval',
        'fail',
        ['err' => 'unsupported_method: ' . $method]
    );
} catch (Throwable $e) { /* 审计失败不影响主流程 */ }
header('Content-Type: text/plain; charset=utf-8');
echo 'unsupported method: ' . $method;

// ============ 辅助函数 ============

/**
 * 从回调解密后的结构中递归提取审批单号 sp_no / SpNo / spNo。
 *
 * @param array $data 解密后的 JSON/XML 数组
 * @return string 审批单号，未找到返回空字符串
 */
function approval_extract_sp_no(array $data): string {
    $walker = null;
    $walker = static function ($node) use (&$walker) {
        if (!is_array($node)) return '';
        foreach ($node as $k => $v) {
            $lk = strtolower((string)$k);
            if (in_array($lk, ['spno', 'sp_no'], true) && is_scalar($v) && $v !== '') {
                return (string)$v;
            }
            if (is_array($v)) {
                $res = $walker($v);
                if ($res !== '') return $res;
            }
        }
        return '';
    };
    return $walker($data);
}

/**
 * 企微 AES 解密的兜底版本：不校验 receiveid 与 corpid 是否匹配，
 * 仅用于 URL 验证阶段确保 echostr 能被解开。
 *
 * @param string $encrypt   base64 编码的密文
 * @param string $aesKeyStr 43 字符的 EncodingAESKey
 * @return string|false     解密后的明文 msg；失败返回 false
 */
function approval_decrypt_no_verify($encrypt, $aesKeyStr) {
    $key = base64_decode($aesKeyStr . '=');
    if (strlen($key) !== 32) return false;
    $iv = substr($key, 0, 16);
    $cipher = base64_decode($encrypt);
    if ($cipher === false) return false;
    $dec = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    if ($dec === false) return false;
    $pad = ord($dec[strlen($dec) - 1]);
    if ($pad < 1 || $pad > 32) return false;
    $dec = substr($dec, 0, strlen($dec) - $pad);
    if (strlen($dec) < 20) return false;
    $msgLen = unpack('N', substr($dec, 16, 4))[1];
    if ($msgLen < 0 || $msgLen > strlen($dec) - 20) return false;
    return substr($dec, 20, $msgLen);
}
