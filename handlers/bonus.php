<?php
/**
 * 奖金管理接口（admin/finance 上传推送，employee 查看确认）
 *   bonus/upload           上传奖金 Excel
 *   bonus/template         下载 Excel 模板
 *   bonus/list             管理端：奖金列表
 *   bonus/detail/{id}      管理端/员工端：奖金详情
 *   bonus/push             推送奖金通知
 *   bonus/confirm          员工确认签收
 *   bonus/delete           删除奖金（仅 admin/finance）
 *   bonus/types            奖金类型管理（GET 列表 / POST 增删）
 *   bonus/employee         员工端：我的奖金列表
 *   bonus/chart            图表数据：年度奖金合计
 */

/**
 * 上传奖金 Excel
 * 严格校验标题格式，错误时返回具体原因
 */
function handle_bonus_upload() {
    $u = require_admin_session([ROLE_ADMIN, ROLE_FINANCE]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_out(['success' => false, 'error' => '请选择 .xlsx 文件']);
    }
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        json_out(['success' => false, 'error' => '仅支持 .xlsx 格式（不支持 .xls）']);
    }
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }
    $save = UPLOAD_DIR . uniqid('bonus_', true) . '.xlsx';
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $save)) {
        json_out(['success' => false, 'error' => '文件保存失败']);
    }

    $parsed = parse_bonus_excel($save);
    if (!$parsed['success']) {
        @unlink($save);
        $errMsg = implode('；', $parsed['errors']);
        audit_log('upload_bonus', '', 'fail', ['error' => $errMsg]);
        json_out(['success' => false, 'error' => $errMsg]);
    }

    $year = $parsed['year'];
    $bonusTypeName = $parsed['bonus_type'];
    $columns = $parsed['columns'];
    $rows = $parsed['rows'];

    // 严格校验奖金类型：必须已存在于 bonus_types 表，不存在则拒绝上传
    // 避免错别字产生脏类型，财务需联系管理员先在「奖金类型」页面添加
    $db = get_db();
    $typeStmt = $db->prepare("SELECT type_key FROM bonus_types WHERE type_name = ? LIMIT 1");
    $typeStmt->execute([$bonusTypeName]);
    $typeRow = $typeStmt->fetch();
    if (!$typeRow) {
        @unlink($save);
        audit_log('upload_bonus', '', 'fail', ['error' => '类型不存在:' . $bonusTypeName]);
        json_out([
            'success' => false,
            'error' => '奖金类型「' . $bonusTypeName . '」不存在，请联系管理员在「奖金类型」页面添加后再上传',
        ]);
    }
    $bonusTypeKey = $typeRow['type_key'];

    // 构建 categoryMap：列名 -> 分组路径
    $categoryMap = [];
    foreach ($columns as $col) {
        if ($col['name'] !== '' && $col['name'] !== '姓名') {
            $categoryMap[$col['name']] = $col['category'] ?? '';
        }
    }

    $imported = 0;
    $skip = 0;
    $errors = [];

    $db->beginTransaction();
    try {
        foreach ($rows as $row) {
            $firstCol = trim((string)($row['姓名'] ?? $row[array_key_first($row)] ?? ''));
            if ($firstCol === '') { $skip++; continue; }

            $matched = match_employee($db, $firstCol, 'normal');
            if (!$matched['userid']) {
                $skip++;
                $errors[] = "未匹配员工：{$firstCol}";
                continue;
            }

            $userid = $matched['userid'];
            $name = $matched['name'] ?: $userid;
            $deptName = $matched['dept_name'];

            // 提取实发奖金
            $realBonus = 0;
            foreach ($row as $k => $v) {
                if ($k === '实发奖金') {
                    $realBonus = (float)preg_replace('/[^\d.\-]/', '', (string)$v);
                    break;
                }
            }

            // 提取备注（列名固定为"备注"，可能含多行文本，不作为金额明细项）
            $remark = '';
            foreach ($row as $k => $v) {
                if ($k === '备注') {
                    $remark = trim((string)$v);
                    break;
                }
            }

            // 已存在则覆盖（含软删记录，复用 id 并清除软删标记，避免 UNIQUE 约束冲突）
            $chk = $db->prepare("SELECT id FROM bonus WHERE year = ? AND bonus_type = ? AND userid = ?");
            $chk->execute([$year, $bonusTypeName, $userid]);
            $exist = $chk->fetch();

            if ($exist) {
                $db->prepare("UPDATE bonus SET amount = ?, name = ?, dept_name = ?, remark = ?, status = 'draft', confirmed = 0, confirmed_at = NULL, pushed_at = NULL, deleted_at = NULL, deleted_by = NULL WHERE id = ?")
                   ->execute([$realBonus, $name, $deptName, $remark, $exist['id']]);
                $bonusId = $exist['id'];
                $db->prepare("DELETE FROM bonus_items WHERE bonus_id = ?")->execute([$bonusId]);
            } else {
                $ins = $db->prepare(
                    "INSERT INTO bonus (userid, name, dept_name, year, bonus_type, amount, remark, status)
                     VALUES (?,?,?,?,?,?,?,'draft')"
                );
                $ins->execute([$userid, $name, $deptName, $year, $bonusTypeName, $realBonus, $remark]);
                $bonusId = $db->lastInsertId();
            }

            // 写入明细项（扣除项存正数，标记 is_deduction；"备注"列跳过）
            $sortOrder = 0;
            $insItem = $db->prepare(
                "INSERT INTO bonus_items (bonus_id, item_name, item_value, sort_order, is_deduction, category)
                 VALUES (?,?,?,?,?,?)"
            );
            foreach ($columns as $col) {
                $colName = $col['name'];
                if ($colName === '' || $colName === '姓名' || $colName === '备注') continue;
                $val = trim((string)($row[$colName] ?? ''));
                if ($val === '') continue;
                $isDeduction = is_deduction_field($colName) ? 1 : 0;
                $cat = $categoryMap[$colName] ?? '';
                $insItem->execute([$bonusId, $colName, $val, $sortOrder++, $isDeduction, $cat]);
            }

            $imported++;
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        @unlink($save);
        audit_log('upload_bonus', '', 'fail', ['error' => $e->getMessage()]);
        json_out(['success' => false, 'error' => '导入失败：' . $e->getMessage()]);
    }

    @unlink($save);
    audit_log('upload_bonus', "bonus:{$year}/{$bonusTypeName}", 'ok', [
        'imported' => $imported, 'skip' => $skip, 'columns' => count($columns) - 1,
    ]);
    json_out([
        'success' => true,
        'year' => $year,
        'bonus_type' => $bonusTypeName,
        'imported' => $imported,
        'skip' => $skip,
        'errors' => $errors,
    ]);
}

/**
 * 下载奖金 Excel 模板
 */
function handle_bonus_template() {
    require_admin_session([ROLE_ADMIN, ROLE_FINANCE]);
    if (!class_exists('ZipArchive')) {
        json_out(['success' => false, 'error' => '服务器未启用 ZipArchive 扩展']);
    }

    $year = date('Y');
    $tmpFile = tempnam(sys_get_temp_dir(), 'bonus_tpl_');
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== TRUE) {
        @unlink($tmpFile);
        json_out(['success' => false, 'error' => '无法创建模板文件']);
    }

    // 共享字符串：0=标题, 1=姓名, 2=基本奖金, 3=绩效奖金, 4=代扣个税, 5=其他扣除, 6=实发奖金
    $titleText = "【{$year}年年终奖】";
    $headers = ['姓名', '基本奖金', '绩效奖金', '代扣个税', '其他扣除', '实发奖金'];
    $allStrings = array_merge([$titleText], $headers);
    $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $sharedXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($allStrings) . '" uniqueCount="' . count($allStrings) . '">';
    foreach ($allStrings as $s) {
        $sharedXml .= '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
    }
    $sharedXml .= '</sst>';

    // 工作表：行1=合并标题, 行2=表头, 行3=示例
    $lastCol = count($headers); // 6 列 (A-F)
    $lastColLetter = chr(64 + $lastCol); // 'F'
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
    $sampleData = ['张三', '5000', '3000', '500', '200', '7300'];
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
    header('Content-Disposition: attachment; filename="bonus_template.xlsx"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    @unlink($tmpFile);
    exit;
}

/**
 * 管理端：奖金列表
 * GET /api/bonus/list?year=2026&type=年终奖
 */
function handle_bonus_list() {
    require_admin_session([ROLE_ADMIN, ROLE_FINANCE, ROLE_HR]);
    $db = get_db();
    $year = (int)param('year', 0);
    $bonusType = trim((string)param('type', ''));

    $sql = "SELECT b.id, b.userid, b.name, b.dept_name, b.year, b.bonus_type, b.amount,
                   b.remark, b.status, b.confirmed, b.confirmed_at, b.pushed_at, b.created_at
            FROM bonus b WHERE b.deleted_at IS NULL";
    $params = [];
    if ($year > 0) {
        $sql .= " AND b.year = ?";
        $params[] = $year;
    }
    if ($bonusType !== '') {
        $sql .= " AND b.bonus_type = ?";
        $params[] = $bonusType;
    }
    $sql .= " ORDER BY b.year DESC, b.bonus_type ASC, b.confirmed ASC, b.name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll();
    foreach ($list as &$row) {
        $row['dept_name'] = normalize_dept_name($row['dept_name'] ?? '');
    }
    unset($row);

    // 返回可用年份和类型列表供筛选（仅未删除记录）
    $years = $db->query("SELECT DISTINCT year FROM bonus WHERE deleted_at IS NULL ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
    $types = $db->query("SELECT DISTINCT bonus_type FROM bonus WHERE deleted_at IS NULL ORDER BY bonus_type")->fetchAll(PDO::FETCH_COLUMN);

    json_out(['success' => true, 'list' => $list, 'years' => $years, 'types' => $types]);
}

/**
 * 奖金详情
 * GET /api/bonus/detail/{id} — 管理端查看任意记录
 */
function handle_bonus_detail() {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_out(['success' => false, 'error' => '参数错误']);
    }

    // 管理端或员工端均可访问，但员工端只能看自己的
    $admin = get_admin_session();
    if ($admin && user_has_any_role($admin['role'], [ROLE_ADMIN, ROLE_FINANCE, ROLE_HR])) {
        $userid = null;
    } else {
        $u = require_login();
        require_password($u);
        $userid = $u['userid'];
    }

    $db = get_db();
    $sql = "SELECT * FROM bonus WHERE id = ? AND deleted_at IS NULL";
    $params = [$id];
    if ($userid !== null) {
        $sql .= " AND userid = ?";
        $params[] = $userid;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $bonus = $stmt->fetch();
    if (!$bonus) {
        json_out(['success' => false, 'error' => '记录不存在']);
    }

    $itemsStmt = $db->prepare("SELECT * FROM bonus_items WHERE bonus_id = ? ORDER BY sort_order");
    $itemsStmt->execute([$id]);
    $items = $itemsStmt->fetchAll();

    json_out(['success' => true, 'bonus' => $bonus, 'items' => $items]);
}

/**
 * 推送奖金通知
 * POST /api/bonus/push — body: { year, bonus_type, scope: 'draft'|'all' }
 */
function handle_bonus_push() {
    $u = require_admin_session([ROLE_ADMIN, ROLE_FINANCE]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $year = (int)param('year', 0);
    $bonusType = trim((string)param('bonus_type', ''));
    $scope = trim((string)param('scope', 'draft'));
    if ($year <= 0 || $bonusType === '') {
        json_out(['success' => false, 'error' => '请指定年份和奖金类型']);
    }

    $db = get_db();
    $sql = "SELECT id, userid FROM bonus WHERE year = ? AND bonus_type = ? AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL";
    if ($scope === 'all') {
        $sql .= " AND confirmed = 0";
    } else {
        $sql .= " AND status = 'draft'";
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([$year, $bonusType]);
    $rows = $stmt->fetchAll();

    $sent = 0;
    $total = 0;
    foreach ($rows as $r) {
        $total++;
        $claim = $db->prepare("UPDATE bonus SET status = ?, pushed_at = ? WHERE id = ? AND status = 'draft'");
        $claim->execute([BONUS_SENT, date('Y-m-d H:i:s'), $r['id']]);
        if ($claim->rowCount() > 0 || $scope === 'all') {
            if (bonus_push_send($r['userid'], $year, $bonusType, (int)$r['id'])) {
                $sent++;
            }
        }
    }

    audit_log('push_bonus', "bonus:{$year}/{$bonusType}", 'ok', [
        'sent' => $sent, 'fail' => $total - $sent, 'total' => $total,
    ]);

    // 推送结果通知接收人：使用 bonus_push_result 模板
    $rTpl = render_builtin_template('bonus_push_result', [
        '年'       => (string)$year,
        '奖金类型' => $bonusType,
        '成功数'   => (string)$sent,
        '总数'     => (string)$total,
    ]);
    $notifyMsg = $rTpl['content'];
    if ($total - $sent > 0) {
        $notifyMsg .= "\n失败：" . ($total - $sent);
    }
    $notifyMsg .= "\n操作人：" . ($u['name'] ?? $u['userid']);
    notify_push_result($notifyMsg, $rTpl);

    json_out(['success' => true, 'sent' => $sent, 'total' => $total]);
}

/**
 * 员工确认签收奖金
 * POST /api/bonus/confirm — body: { id }
 */
function handle_bonus_confirm() {
    $u = require_login();
    require_password($u);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $id = (int)param('id', 0);
    if ($id <= 0) {
        json_out(['success' => false, 'error' => '参数错误']);
    }

    $db = get_db();
    $stmt = $db->prepare("SELECT id, userid, confirmed FROM bonus WHERE id = ? AND userid = ? AND deleted_at IS NULL");
    $stmt->execute([$id, $u['userid']]);
    $bonus = $stmt->fetch();
    if (!$bonus) {
        json_out(['success' => false, 'error' => '记录不存在']);
    }
    if ($bonus['confirmed']) {
        json_out(['success' => true, 'already_confirmed' => true]);
    }

    // 同步更新 status='confirmed'，与 salary 员工端确认口径一致，
    // 避免 status 永远停留在 sent 与 confirmed 标志位语义脱钩，
    // 导致数据概览（IN (sent,confirmed)）与业务口径出现差异。
    $db->prepare("UPDATE bonus SET confirmed = 1, confirmed_at = ?, status = ? WHERE id = ?")
       ->execute([date('Y-m-d H:i:s'), BONUS_CONFIRMED, $id]);

    audit_log('confirm_bonus', "bonus:{$id}", 'ok', ['by' => $u['userid']]);
    json_out(['success' => true]);
}

/**
 * 删除奖金（仅 admin/finance，HR 不能删）
 * POST /api/bonus/delete — body: { id } 或 { year, bonus_type, scope: 'all' }
 */
function handle_bonus_delete() {
    require_admin_session([ROLE_ADMIN, ROLE_FINANCE]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $db = get_db();
    $id = (int)param('id', 0);
    $scope = trim((string)param('scope', ''));
    // 软删除操作人：记录到 deleted_by 字段，便于回收站展示
    $opUser = '-';
    $admin = function_exists('get_admin_session') ? get_admin_session() : null;
    if ($admin && !empty($admin['userid'])) {
        $opUser = $admin['userid'];
    }

    $db->beginTransaction();
    try {
        if ($scope === 'all') {
            // 按年份+类型批量软删除（仅删除未软删的记录）
            $year = (int)param('year', 0);
            $bonusType = trim((string)param('bonus_type', ''));
            if ($year <= 0 || $bonusType === '') {
                if ($db->inTransaction()) $db->rollBack();
                json_out(['success' => false, 'error' => '请指定年份和奖金类型']);
            }
            $stmt = $db->prepare("SELECT id FROM bonus WHERE year = ? AND bonus_type = ? AND deleted_at IS NULL");
            $stmt->execute([$year, $bonusType]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (empty($ids)) {
                if ($db->inTransaction()) $db->rollBack();
                json_out(['success' => false, 'error' => '未找到匹配的记录']);
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            // 删除前固化反馈的年/类型归属：bonus_id 失效后用 bonus_year/bonus_type 保留归属（对齐工资条逻辑）
            $db->prepare(
                "UPDATE feedback SET bonus_year = ?, bonus_type = ? WHERE bonus_id IN ($placeholders) AND bonus_year IS NULL"
            )->execute(array_merge([$year, $bonusType], $ids));
            // 软删除：子表 bonus_items 不动，跟随主表可见性
            $db->prepare("UPDATE bonus SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ? WHERE year = ? AND bonus_type = ? AND deleted_at IS NULL")->execute([$opUser, $year, $bonusType]);
            $db->commit();
            audit_log('delete_bonus', "bonus:{$year}/{$bonusType}", 'ok', ['scope' => 'all', 'count' => count($ids)]);
            json_out(['success' => true, 'deleted' => count($ids), 'scope' => 'all']);
        } else {
            // 单条软删除
            if ($id <= 0) {
                if ($db->inTransaction()) $db->rollBack();
                json_out(['success' => false, 'error' => '参数错误']);
            }
            // 取记录的 year/bonus_type 用于回填 feedback
            $info = $db->prepare("SELECT id, year, bonus_type FROM bonus WHERE id = ? AND deleted_at IS NULL");
            $info->execute([$id]);
            $row = $info->fetch();
            if (!$row) {
                if ($db->inTransaction()) $db->rollBack();
                json_out(['success' => false, 'error' => '未找到该奖金记录']);
            }
            // 删除前固化反馈的年/类型归属
            $db->prepare(
                "UPDATE feedback SET bonus_year = ?, bonus_type = ? WHERE bonus_id = ? AND bonus_year IS NULL"
            )->execute([$row['year'], $row['bonus_type'], $id]);
            // 软删除：子表 bonus_items 不动，跟随主表可见性
            $db->prepare("UPDATE bonus SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ? WHERE id = ?")->execute([$opUser, $id]);
            $db->commit();
            audit_log('delete_bonus', "bonus:{$id}", 'ok', ['scope' => 'single']);
            json_out(['success' => true, 'scope' => 'single']);
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        json_out(['success' => false, 'error' => '删除失败：' . $e->getMessage()]);
    }
}

/**
 * 奖金类型管理
 * GET  /api/bonus/types — 列表
 * POST /api/bonus/types — { op: 'add', type_name } / { op: 'delete', id }
 */
function handle_bonus_types() {
    require_admin_session([ROLE_ADMIN, ROLE_FINANCE, ROLE_HR]);
    $db = get_db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 类型管理仅 admin 可操作
        $admin = require_admin_session([ROLE_ADMIN]);
        $op = trim((string)param('op', ''));
        if ($op === 'add') {
            $typeName = trim((string)param('type_name', ''));
            if ($typeName === '') {
                json_out(['success' => false, 'error' => '请填写类型名称']);
            }
            // 检查重名
            $chk = $db->prepare("SELECT id FROM bonus_types WHERE type_name = ?");
            $chk->execute([$typeName]);
            if ($chk->fetch()) {
                json_out(['success' => false, 'error' => '类型名称已存在']);
            }
            $typeKey = 'custom_' . substr(md5($typeName . uniqid('', true)), 0, 12);
            $maxOrder = (int)$db->query("SELECT MAX(sort_order) FROM bonus_types")->fetchColumn();
            $db->prepare("INSERT INTO bonus_types (type_key, type_name, sort_order) VALUES (?,?,?)")
               ->execute([$typeKey, $typeName, $maxOrder + 1]);
            audit_log('bonus_type_add', 'bonus_type:' . $typeName);
            json_out(['success' => true, 'id' => $db->lastInsertId()]);
        }
        if ($op === 'delete') {
            $id = (int)param('id', 0);
            $chk = $db->prepare("SELECT type_key, type_name FROM bonus_types WHERE id = ?");
            $chk->execute([$id]);
            $row = $chk->fetch();
            if (!$row) {
                json_out(['success' => false, 'error' => '类型不存在']);
            }
            // 内置类型禁止删除
            if (in_array($row['type_key'], ['year_end'], true)) {
                json_out(['success' => false, 'error' => '内置类型禁止删除']);
            }
            // 检查是否有关联奖金记录
            $cnt = $db->prepare("SELECT COUNT(*) FROM bonus WHERE bonus_type = ? AND deleted_at IS NULL");
            $cnt->execute([$row['type_name']]);
            if ((int)$cnt->fetchColumn() > 0) {
                json_out(['success' => false, 'error' => '该类型下有奖金记录，无法删除']);
            }
            $db->prepare("DELETE FROM bonus_types WHERE id = ?")->execute([$id]);
            audit_log('bonus_type_delete', 'bonus_type:' . $row['type_name']);
            json_out(['success' => true]);
        }
        json_out(['success' => false, 'error' => '未知操作']);
    }
    // GET：返回类型列表
    $list = $db->query("SELECT id, type_key, type_name, sort_order FROM bonus_types ORDER BY sort_order, id")->fetchAll();
    json_out(['success' => true, 'list' => $list]);
}

/**
 * 员工端：我的奖金列表
 * GET /api/bonus/employee
 */
function handle_bonus_employee() {
    $u = require_login();
    require_password($u);
    $db = get_db();
    $stmt = $db->prepare(
        "SELECT b.id, b.year, b.bonus_type, b.amount, b.status, b.confirmed, b.confirmed_at, b.pushed_at
         FROM bonus b
         WHERE b.userid = ? AND b.deleted_at IS NULL
         ORDER BY b.year DESC, b.created_at DESC"
    );
    $stmt->execute([$u['userid']]);
    $list = $stmt->fetchAll();

    // 按年份分组
    $grouped = [];
    foreach ($list as $r) {
        $year = $r['year'];
        if (!isset($grouped[$year])) {
            $grouped[$year] = ['year' => $year, 'total' => 0, 'items' => []];
        }
        $grouped[$year]['items'][] = $r;
        $grouped[$year]['total'] += (float)$r['amount'];
    }
    $result = array_values($grouped);

    json_out(['success' => true, 'list' => $result]);
}

/**
 * 图表数据：年度奖金合计
 * GET /api/bonus/chart — 返回各年份的奖金合计（不区分类型，多类型合并）
 */
function handle_bonus_chart() {
    $u = require_login();
    require_password($u);
    $db = get_db();
    $stmt = $db->prepare(
        "SELECT year, SUM(amount) AS total
         FROM bonus
         WHERE userid = ? AND userid NOT LIKE 'HIST_%' AND deleted_at IS NULL
         GROUP BY year
         ORDER BY year"
    );
    $stmt->execute([$u['userid']]);
    $rows = $stmt->fetchAll();

    $chartData = [];
    foreach ($rows as $r) {
        $chartData[] = ['year' => (int)$r['year'], 'total' => (float)$r['total']];
    }

    json_out(['success' => true, 'data' => $chartData]);
}

/**
 * 奖金回收站：列表 / 恢复 / 彻底删除
 *   GET  /api/bonus/trash_list               回收站列表
 *   POST /api/bonus/restore   {id} 或 {year,bonus_type,scope=all}  恢复
 *   POST /api/bonus/purge     {id} 或 {year,bonus_type,scope=all}  彻底删除
 * 权限：仅 admin/finance（与删除权限一致）
 */
function handle_bonus_trash_list() {
    require_admin_session([ROLE_ADMIN, ROLE_FINANCE]);
    $db = get_db();
    $stmt = $db->query(
        "SELECT b.id, b.userid, b.name, b.dept_name, b.year, b.bonus_type, b.amount,
                b.status, b.confirmed, b.remark, b.deleted_at, b.deleted_by
         FROM bonus b
         WHERE b.deleted_at IS NOT NULL
         ORDER BY b.deleted_at DESC, b.year DESC, b.bonus_type ASC"
    );
    $list = $stmt->fetchAll();
    foreach ($list as &$row) {
        $row['dept_name'] = normalize_dept_name($row['dept_name'] ?? '');
    }
    unset($row);
    json_out(['success' => true, 'list' => $list]);
}

function handle_bonus_restore() {
    require_admin_session([ROLE_ADMIN, ROLE_FINANCE]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $db = get_db();
    $id = (int)param('id', 0);
    $scope = trim((string)param('scope', ''));

    try {
        $db->beginTransaction();
        if ($scope === 'all') {
            // 按年份+类型批量恢复
            $year = (int)param('year', 0);
            $bonusType = trim((string)param('bonus_type', ''));
            if ($year <= 0 || $bonusType === '') {
                if ($db->inTransaction()) $db->rollBack();
                json_out(['success' => false, 'error' => '请指定年份和奖金类型']);
            }
            $stmt = $db->prepare("UPDATE bonus SET deleted_at = NULL, deleted_by = NULL WHERE year = ? AND bonus_type = ? AND deleted_at IS NOT NULL");
            $stmt->execute([$year, $bonusType]);
            $count = $stmt->rowCount();
            $target = "bonus:{$year}/{$bonusType}";
        } else {
            // 单条恢复
            if ($id <= 0) {
                if ($db->inTransaction()) $db->rollBack();
                json_out(['success' => false, 'error' => '参数错误']);
            }
            $db->prepare("UPDATE bonus SET deleted_at = NULL, deleted_by = NULL WHERE id = ? AND deleted_at IS NOT NULL")->execute([$id]);
            $count = 1;
            $target = "bonus:{$id}";
        }
        $db->commit();
        audit_log('restore_bonus', $target, 'ok', ['count' => $count]);
        json_out(['success' => true, 'restored' => $count]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        json_out(['success' => false, 'error' => '恢复失败：' . $e->getMessage()]);
    }
}

function handle_bonus_purge() {
    require_admin_session([ROLE_ADMIN, ROLE_FINANCE]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['success' => false, 'error' => '仅支持 POST 请求'], 405);
    }
    $db = get_db();
    $id = (int)param('id', 0);
    $scope = trim((string)param('scope', ''));

    try {
        $db->beginTransaction();
        if ($scope === 'all') {
            // 按年份+类型批量彻底删除
            $year = (int)param('year', 0);
            $bonusType = trim((string)param('bonus_type', ''));
            if ($year <= 0 || $bonusType === '') {
                if ($db->inTransaction()) $db->rollBack();
                json_out(['success' => false, 'error' => '请指定年份和奖金类型']);
            }
            // 先查出所有待删除 id，用于删子表
            $q = $db->prepare("SELECT id FROM bonus WHERE year = ? AND bonus_type = ? AND deleted_at IS NOT NULL");
            $q->execute([$year, $bonusType]);
            $ids = $q->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!empty($ids)) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("DELETE FROM bonus_items WHERE bonus_id IN ($in)")->execute($ids);
                $db->prepare("DELETE FROM bonus WHERE year = ? AND bonus_type = ? AND deleted_at IS NOT NULL")->execute([$year, $bonusType]);
            }
            $count = count($ids);
            $target = "bonus:{$year}/{$bonusType}";
        } else {
            // 单条彻底删除
            if ($id <= 0) {
                if ($db->inTransaction()) $db->rollBack();
                json_out(['success' => false, 'error' => '参数错误']);
            }
            $db->prepare("DELETE FROM bonus_items WHERE bonus_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM bonus WHERE id = ? AND deleted_at IS NOT NULL")->execute([$id]);
            $count = 1;
            $target = "bonus:{$id}";
        }
        $db->commit();
        audit_log('purge_bonus', $target, 'ok', ['count' => $count]);
        json_out(['success' => true, 'purged' => $count]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        json_out(['success' => false, 'error' => '彻底删除失败：' . $e->getMessage()]);
    }
}
