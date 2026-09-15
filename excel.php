<?php
/**
 * Excel 解析引擎（零依赖：ZipArchive + SimpleXML）
 *
 * 兼容用户格式规范：
 *   1) 第一行合并单元格格式【xxxx年xx月薪资】
 *   2) 第一列为企业微信员工ID
 *   3) 必须包含【实发工资】列
 *   4) 支持合并单元格（含首行跨列标题、字段跨列/跨行合并）
 *
 * 返回结构：
 *   ['success'=>bool, 'year'=>int, 'month'=>int,
 *    'columns'=>[ ['name'=>列名,'category'=>分组名], ... ],
 *    'rows'=>[ [列名=>值,...], ... ], 'errors'=>[...]]
 */

/**
 * 列字母转 0 基索引：A->0, B->1, AA->26
 */
function col_letter_to_index($letters) {
    $idx = 0;
    $letters = strtoupper($letters);
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $idx = $idx * 26 + (ord($letters[$i]) - 64);
    }
    return $idx - 1;
}

/**
 * 取单元格原始值（区分共享字符串 / 内联字符串 / 数字）
 */
function excel_cell_value($c, $strings) {
    $t = (string)($c['t'] ?? '');
    if ($t === 's') {
        $idx = (int)(string)$c->v;
        return $strings[$idx] ?? '';
    }
    if ($t === 'inlineStr') {
        return trim((string)($c->is->t ?? ''));
    }
    return (string)($c->v ?? '');
}

/**
 * 读取共享字符串表
 */
function excel_read_shared_strings($zip) {
    $strings = [];
    $content = $zip->getFromName('xl/sharedStrings.xml');
    if ($content === false) {
        return $strings;
    }
    $content = preg_replace('#\s+xmlns="[^"]*"#', '', $content);
    $sst = @simplexml_load_string($content);
    if ($sst === false) {
        return $strings;
    }
    foreach ($sst->si as $si) {
        $text = '';
        foreach ($si->xpath('.//t') as $t) {
            $text .= (string)$t;
        }
        $strings[] = trim($text);
    }
    return $strings;
}

/**
 * 找到第一个工作表文件路径
 */
function excel_first_sheet_name($zip) {
    // 优先 sheet1.xml
    if ($zip->getFromName('xl/worksheets/sheet1.xml') !== false) {
        return 'xl/worksheets/sheet1.xml';
    }
    // 否则从 workbook 关系里找第一个
    $wb = $zip->getFromName('xl/workbook.xml');
    if ($wb !== false) {
        $xml = @simplexml_load_string($wb);
        if ($xml && isset($xml->sheets->sheet)) {
            foreach ($xml->sheets->sheet as $sh) {
                $rId = (string)($sh['r:id'] ?? $sh['id'] ?? '');
                if ($rId) {
                    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
                    if ($rels) {
                        $rx = @simplexml_load_string($rels);
                        foreach ($rx->Relationship as $rel) {
                            if ((string)$rel['Id'] === $rId) {
                                return 'xl/' . ltrim((string)$rel['Target'], '/');
                            }
                        }
                    }
                }
            }
        }
    }
    return false;
}

/**
 * 解析工资表
 */
function parse_salary_excel($filepath) {
    $result = ['success' => false, 'year' => 0, 'month' => 0, 'columns' => [], 'rows' => [], 'errors' => []];

    if (!class_exists('ZipArchive')) {
        $result['errors'][] = '服务器未启用 ZipArchive 扩展';
        return $result;
    }
    if (!file_exists($filepath)) {
        $result['errors'][] = '文件不存在';
        return $result;
    }

    $zip = new ZipArchive();
    if ($zip->open($filepath) !== TRUE) {
        $result['errors'][] = '无法打开 Excel 文件（请确认是 .xlsx 格式）';
        return $result;
    }

    $strings = excel_read_shared_strings($zip);
    $sheetName = excel_first_sheet_name($zip);
    if (!$sheetName) {
        $result['errors'][] = '未找到工作表';
        $zip->close();
        return $result;
    }
    $sheetContent = $zip->getFromName($sheetName);
    $zip->close();
    if ($sheetContent === false) {
        $result['errors'][] = '工作表内容读取失败';
        return $result;
    }

    $sheetContent = preg_replace('#\s+xmlns="[^"]*"#', '', $sheetContent);
    $sheet = @simplexml_load_string($sheetContent);
    if ($sheet === false || !isset($sheet->sheetData->row)) {
        $result['errors'][] = '工作表结构无法解析';
        return $result;
    }

    // 1) 解析所有单元格 + 合并区域
    $cells = [];   // $cells[row][col] = value
    $merges = [];  // [tlc, tlr, brc, brr]
    if (isset($sheet->mergeCells->mergeCell)) {
        foreach ($sheet->mergeCells->mergeCell as $mc) {
            $ref = (string)$mc['ref'];
            if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $ref, $m)) {
                $merges[] = [
                    col_letter_to_index($m[1]), (int)$m[2] - 1,
                    col_letter_to_index($m[3]), (int)$m[4] - 1,
                ];
            }
        }
    }
    foreach ($sheet->sheetData->row as $row) {
        $rowi = (int)$row['r'] - 1;
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                continue;
            }
            $col = col_letter_to_index($m[1]);
            $cells[$rowi][$col] = excel_cell_value($c, $strings);
        }
    }
    // 合并区域填充（左上角值覆盖整个区域，保证任意格可取）
    foreach ($merges as $mg) {
        list($tlc, $tlr, $brc, $brr) = $mg;
        $v = $cells[$tlr][$tlc] ?? '';
        for ($r = $tlr; $r <= $brr; $r++) {
            for ($c = $tlc; $c <= $brc; $c++) {
                if (!isset($cells[$r][$c])) {
                    $cells[$r][$c] = $v;
                }
            }
        }
    }

    if (empty($cells)) {
        $result['errors'][] = '未读取到任何数据';
        return $result;
    }

    // 2) 解析年份月份：扫描首行（及前几行）合并标题文本
    $year = 0;
    $month = 0;
    $maxRow = max(array_keys($cells));
    for ($r = 0; $r <= min(2, $maxRow); $r++) {
        if (!isset($cells[$r])) continue;
        foreach ($cells[$r] as $txt) {
            if (preg_match('/(\d{4})\s*年\s*(\d{1,2})\s*月/', $txt, $ym)) {
                $year = (int)$ym[1];
                $month = (int)$ym[2];
                break 2;
            }
        }
    }
    if (!$year || !$month) {
        $result['errors'][] = '未能从首行标题识别「年份/月份」，请确认第一行格式如【2026年03月薪资】';
        return $result;
    }

    // 3) 定位数据行起始（第一列是员工ID/姓名的行）
    // 不再依赖「实发工资」定位列名行，因为多层表头中「实发工资」可能只在分组行
    // 而列名行该列为空（合计项无子列名），会导致错误定位到分组行
    $dataStartRow = -1;
    for ($r = 0; $r <= $maxRow; $r++) {
        if (!isset($cells[$r])) {
            continue;
        }
        $firstVal = trim((string)($cells[$r][0] ?? ''));
        if ($firstVal === '') {
            continue;
        }
        // 排除标题行（含年月）
        if (strpos($firstVal, '年') !== false || strpos($firstVal, '月') !== false) {
            continue;
        }
        // 排除"姓名"等表头标识
        if (in_array($firstVal, ['姓名', '员工姓名', '工号', '姓名/ID'], true)) {
            continue;
        }
        // 排除常见英文/中文表头标识（不区分大小写比较）
        $headerExcludes = ['Name', 'ID', 'User', 'Employee', '员工ID', '成员ID'];
        $isHeaderExclude = false;
        foreach ($headerExcludes as $hdr) {
            if (strcasecmp($firstVal, $hdr) === 0) {
                $isHeaderExclude = true;
                break;
            }
        }
        if ($isHeaderExclude) {
            continue;
        }
        // 第一列是字母（企微ID）或纯汉字（姓名）视为数据行
        if (preg_match('/^[A-Za-z]+$/', $firstVal) || preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $firstVal)) {
            $dataStartRow = $r;
            break;
        }
    }
    if ($dataStartRow < 1) {
        $result['errors'][] = '未找到员工数据行，请确认第一列为员工姓名或企微ID';
        return $result;
    }
    // 列名行 = 数据行的上一行
    $titleRow = $dataStartRow - 1;

    // 4) 构建列名 -> 数据（支持多层表头分组）
    // 从列名行向上扫描所有非空内容行（排除年月标题行）作为分组层级
    // 越靠近列名行的层级越深；每列的 category 为各层分组名用 "/" 拼接的路径
    // boundaryNames: 前向填充边界（重置 lastCat），这些值是独立分段标志
    // excludeCatNames: 排除作为分组名的值（员工标识类不参与分组）
    $boundaryNames = ['应发工资','实发工资','姓名','员工姓名','工号'];
    $excludeCatNames = ['姓名','员工姓名','工号'];
    // 收集分组层（从上往下：第0层最顶层，最后一层最靠近列名行）
    $groupLayers = []; // 每层为 [colIdx => 填充后的分组名]
    $groupLayersRaw = []; // 每层原始值（合并单元格填充后），用于列名继承
    for ($r = 0; $r < $titleRow; $r++) {
        if (!isset($cells[$r])) {
            continue;
        }
        $hasContent = false;
        foreach ($cells[$r] as $v) {
            $v = trim((string)$v);
            if ($v !== '' && strpos($v, '年') === false && strpos($v, '月') === false) {
                $hasContent = true;
                break;
            }
        }
        if (!$hasContent) {
            continue;
        }
        // 该行作为一层分组：按列索引排序后做向前填充
        $raw = $cells[$r];
        ksort($raw, SORT_NUMERIC);
        $filled = [];
        $lastCat = '';
        foreach ($raw as $c => $v) {
            $v = trim((string)$v);
            if ($v !== '' && strpos($v, '年') === false) {
                if (in_array($v, $boundaryNames, true)) {
                    // 边界值（应发工资/实发工资/姓名等）重置 lastCat，
                    // 但它们自身可以作为独立分组名（用于当前列的 category）
                    $lastCat = '';
                    $filled[$c] = $v;
                    continue;
                } else {
                    $lastCat = $v;
                }
            }
            $filled[$c] = $lastCat;
        }
        $groupLayers[] = $filled;
        $groupLayersRaw[] = $raw;
    }
    $columns = [];
    // 按列索引数字排序，避免合并单元格填充导致顺序错乱
    $titleCols = $cells[$titleRow];
    ksort($titleCols, SORT_NUMERIC);
    foreach ($titleCols as $col => $name) {
        $colName = trim((string)$name);
        $category = '';
        // 第0列是员工标识列，不参与分组
        if ($col === 0) {
            $colName = $colName !== '' ? $colName : '姓名';
            $columns[$col] = ['name' => $colName, 'category' => ''];
            continue;
        }
        // 列名行该列为空时，从最靠近的分组层原始值继承列名
        // （如「实发工资」合计项在分组层有但列名行为空）
        if ($colName === '') {
            for ($i = count($groupLayersRaw) - 1; $i >= 0; $i--) {
                if (isset($groupLayersRaw[$i][$col])) {
                    $inherited = trim((string)$groupLayersRaw[$i][$col]);
                    if ($inherited !== '' && strpos($inherited, '年') === false
                        && strpos($inherited, '月') === false) {
                        $colName = $inherited;
                        break;
                    }
                }
            }
        }
        // 拼接各层分组名作为路径（排除员工标识类值）
        $parts = [];
        foreach ($groupLayers as $layer) {
            if (isset($layer[$col])) {
                $g = $layer[$col];
                if ($g !== '' && strpos($g, '年') === false
                    && !in_array($g, $excludeCatNames, true)) {
                    $parts[] = $g;
                }
            }
        }
        // 去重相邻相同的层级（防止合并单元格跨多层填充同名）
        $deduped = [];
        $prev = '';
        foreach ($parts as $p) {
            if ($p !== $prev) {
                $deduped[] = $p;
                $prev = $p;
            }
        }
        $category = implode('/', $deduped);
        $columns[$col] = ['name' => $colName, 'category' => $category];
    }

    // 6) 后处理：如果列名本身带 "-" 分组符但 category 为空，
    //    说明是旧系统条目导出的「计时工资-基本工资」这类二级拼接名，
    //    按 "-" 拆分为 category（最后一段前的部分）+ name（最后一段）。
    //    例："计时工资-基本工资" → category="计时工资", name="基本工资"
    //    例："应发工资-加班工资-夜班" → category="应发工资/加班工资", name="夜班"
    $excludeForDash = ['姓名', '微信号', '实际出勤', '带薪假', '夜班', '加班小时', '出勤天数', '工号'];
    foreach ($columns as $colIdx => $cc) {
        $n = (string)$cc['name'];
        $cat = (string)$cc['category'];
        if ($cat !== '' || $n === '' || strpos($n, '-') === false) {
            continue; // 已有分组，或不含 "-"，跳过
        }
        if (in_array($n, $excludeForDash, true)) {
            continue; // 标识类 / 出勤统计类不拆分
        }
        $parts = explode('-', $n);
        if (count($parts) < 2) continue;
        $lastPart = array_pop($parts);
        // 过滤空段（防止 "计时工资-" 这种异常格式）
        $parts = array_values(array_filter($parts, function ($s) { return trim($s) !== ''; }));
        if (count($parts) === 0 || trim($lastPart) === '') continue;
        // 把 parts 每段中常见的 "本月..." 汇总前缀保留（不要把 "本月计时工资" 拆成 "本月/计时工资"，
        // 而是作为分组名整体保留）—— 直接以 "/" 连接即可，和合并单元格层级的 category 格式完全一致
        $newCat = implode('/', $parts);
        $columns[$colIdx] = ['name' => $lastPart, 'category' => $newCat];
    }

    // 优先精确匹配「实发工资」，找不到再回退 strpos 模糊匹配（含子串）
    $hasRealPay = false;
    foreach ($columns as $c) {
        if ($c['name'] === '实发工资') {
            $hasRealPay = true;
            break;
        }
    }
    if (!$hasRealPay) {
        foreach ($columns as $c) {
            if (strpos($c['name'], '实发工资') !== false) {
                $hasRealPay = true;
                break;
            }
        }
    }
    if (!$hasRealPay) {
        $result['errors'][] = '缺少「实发工资」列';
        return $result;
    }

    // 5) 解析数据行（标题行之后）
    // 确保 $columns 按列索引排序
    ksort($columns, SORT_NUMERIC);
    $rows = [];
    $dupReported = []; // 已记录的重复列名（避免每行重复报错）
    for ($r = $titleRow + 1; $r <= $maxRow; $r++) {
        if (!isset($cells[$r])) {
            continue;
        }
        $userid = trim((string)($cells[$r][0] ?? ''));
        if ($userid === '') {
            continue; // 跳过空行
        }
        $row = [];
        foreach ($columns as $col => $cinfo) {
            $name = $cinfo['name'];
            // 列名重复检测：记录到 errors，但仍按原逻辑处理（覆盖）
            if (isset($row[$name]) && !isset($dupReported[$name])) {
                $result['errors'][] = "列名重复：$name";
                $dupReported[$name] = true;
            }
            if ($col === 0) {
                $row[$name] = $userid; // 第一列即企微ID
            } else {
                $row[$name] = trim((string)($cells[$r][$col] ?? ''));
            }
        }
        $rows[] = $row;
    }

    if (empty($rows)) {
        $result['errors'][] = '未解析到任何员工数据行';
        return $result;
    }

    $result['success'] = true;
    $result['year'] = $year;
    $result['month'] = $month;
    $result['columns'] = array_values($columns);
    $result['rows'] = $rows;
    return $result;
}

/**
 * 解析奖金 Excel（零依赖：ZipArchive + SimpleXML）
 *
 * 格式规范：
 *   1) 第一行合并单元格标题【xxxx年{奖金类型}】（如【2026年年终奖】）
 *   2) 第一列为企微成员ID（纯字母）或员工姓名（纯汉字）
 *   3) 必须包含「实发奖金」列
 *   4) 支持多层表头与合并单元格
 *   5) 扣除项在 Excel 中填正数，由前端根据 is_deduction 标记加负号显示
 *
 * 严格校验：标题格式不符（缺年份/缺类型名/含月份）直接报错，不默默跳过
 *
 * @param string $filepath Excel 文件路径
 * @return array ['success'=>bool, 'year'=>int, 'bonus_type'=>string, 'columns'=>[], 'rows'=>[], 'errors'=>[]]
 */
function parse_bonus_excel($filepath) {
    $result = ['success' => false, 'year' => 0, 'bonus_type' => '', 'columns' => [], 'rows' => [], 'errors' => []];

    if (!class_exists('ZipArchive')) {
        $result['errors'][] = '服务器未启用 ZipArchive 扩展';
        return $result;
    }
    if (!file_exists($filepath)) {
        $result['errors'][] = '文件不存在';
        return $result;
    }

    $zip = new ZipArchive();
    if ($zip->open($filepath) !== TRUE) {
        $result['errors'][] = '无法打开 Excel 文件（请确认是 .xlsx 格式）';
        return $result;
    }

    $strings = excel_read_shared_strings($zip);
    $sheetName = excel_first_sheet_name($zip);
    if (!$sheetName) {
        $result['errors'][] = '未找到工作表';
        $zip->close();
        return $result;
    }
    $sheetContent = $zip->getFromName($sheetName);
    $zip->close();
    if ($sheetContent === false) {
        $result['errors'][] = '工作表内容读取失败';
        return $result;
    }

    $sheetContent = preg_replace('#\s+xmlns="[^"]*"#', '', $sheetContent);
    $sheet = @simplexml_load_string($sheetContent);
    if ($sheet === false || !isset($sheet->sheetData->row)) {
        $result['errors'][] = '工作表结构无法解析';
        return $result;
    }

    // 1) 解析所有单元格 + 合并区域（与工资表解析逻辑一致）
    $cells = [];
    $merges = [];
    if (isset($sheet->mergeCells->mergeCell)) {
        foreach ($sheet->mergeCells->mergeCell as $mc) {
            $ref = (string)$mc['ref'];
            if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $ref, $m)) {
                $merges[] = [
                    col_letter_to_index($m[1]), (int)$m[2] - 1,
                    col_letter_to_index($m[3]), (int)$m[4] - 1,
                ];
            }
        }
    }
    foreach ($sheet->sheetData->row as $row) {
        $rowi = (int)$row['r'] - 1;
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                continue;
            }
            $col = col_letter_to_index($m[1]);
            $cells[$rowi][$col] = excel_cell_value($c, $strings);
        }
    }
    foreach ($merges as $mg) {
        list($tlc, $tlr, $brc, $brr) = $mg;
        $v = $cells[$tlr][$tlc] ?? '';
        for ($r = $tlr; $r <= $brr; $r++) {
            for ($c = $tlc; $c <= $brc; $c++) {
                if (!isset($cells[$r][$c])) {
                    $cells[$r][$c] = $v;
                }
            }
        }
    }

    if (empty($cells)) {
        $result['errors'][] = '未读取到任何数据';
        return $result;
    }

    // 2) 严格解析标题：扫描前 3 行，提取年份 + 奖金类型名称
    $year = 0;
    $bonusType = '';
    $maxRow = max(array_keys($cells));
    $titleFound = false;
    for ($r = 0; $r <= min(2, $maxRow); $r++) {
        if (!isset($cells[$r])) continue;
        foreach ($cells[$r] as $txt) {
            $txt = trim((string)$txt);
            if ($txt === '') continue;
            // 匹配 4 位年份 + "年"
            if (preg_match('/(\d{4})\s*年/u', $txt, $ym)) {
                $year = (int)$ym[1];
                $titleFound = true;
                // 含"月"说明可能是工资条文件，不是奖金文件
                if (preg_match('/\d{1,2}\s*月/u', $txt)) {
                    $result['errors'][] = '标题包含月份信息，可能是工资条文件而非奖金文件。奖金标题格式应为【' . $year . '年年终奖】';
                    return $result;
                }
                // 提取奖金类型名称：年份之后的文本，去除括号和空白
                $afterYear = trim(substr($txt, strpos($txt, $ym[0]) + strlen($ym[0])));
                $afterYear = trim($afterYear, "【】[]()（） \t\n\r");
                $bonusType = $afterYear;
                if ($bonusType === '') {
                    $result['errors'][] = '标题识别到年份（' . $year . '年）但未识别到奖金类型名称，请确认格式如【' . $year . '年年终奖】';
                    return $result;
                }
                break 2;
            }
        }
    }
    if (!$titleFound) {
        $result['errors'][] = '未能从首行标题识别年份，请确认第一行格式如【2026年年终奖】';
        return $result;
    }

    // 3) 定位数据行起始（第一列是员工ID/姓名的行）
    $dataStartRow = -1;
    for ($r = 0; $r <= $maxRow; $r++) {
        if (!isset($cells[$r])) continue;
        $firstVal = trim((string)($cells[$r][0] ?? ''));
        if ($firstVal === '') continue;
        // 排除标题行（含年）
        if (strpos($firstVal, '年') !== false) continue;
        // 排除"姓名"等表头标识
        if (in_array($firstVal, ['姓名', '员工姓名', '工号', '姓名/ID'], true)) continue;
        $headerExcludes = ['Name', 'ID', 'User', 'Employee', '员工ID', '成员ID'];
        $isHeaderExclude = false;
        foreach ($headerExcludes as $hdr) {
            if (strcasecmp($firstVal, $hdr) === 0) {
                $isHeaderExclude = true;
                break;
            }
        }
        if ($isHeaderExclude) continue;
        // 纯字母（企微ID）或纯汉字（姓名）视为数据行
        if (preg_match('/^[A-Za-z]+$/', $firstVal) || preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $firstVal)) {
            $dataStartRow = $r;
            break;
        }
    }
    if ($dataStartRow < 1) {
        $result['errors'][] = '未找到员工数据行，请确认第一列为员工姓名或企微ID';
        return $result;
    }
    $titleRow = $dataStartRow - 1;

    // 4) 构建列名 -> 数据（支持多层表头分组，与工资表逻辑一致）
    $boundaryNames = ['应发奖金','实发奖金','姓名','员工姓名','工号'];
    $excludeCatNames = ['姓名','员工姓名','工号'];
    $groupLayers = [];
    $groupLayersRaw = [];
    for ($r = 0; $r < $titleRow; $r++) {
        if (!isset($cells[$r])) continue;
        $hasContent = false;
        foreach ($cells[$r] as $v) {
            $v = trim((string)$v);
            if ($v !== '' && strpos($v, '年') === false) {
                $hasContent = true;
                break;
            }
        }
        if (!$hasContent) continue;
        $raw = $cells[$r];
        ksort($raw, SORT_NUMERIC);
        $filled = [];
        $lastCat = '';
        foreach ($raw as $c => $v) {
            $v = trim((string)$v);
            if ($v !== '' && strpos($v, '年') === false) {
                if (in_array($v, $boundaryNames, true)) {
                    $lastCat = '';
                    $filled[$c] = $v;
                    continue;
                } else {
                    $lastCat = $v;
                }
            }
            $filled[$c] = $lastCat;
        }
        $groupLayers[] = $filled;
        $groupLayersRaw[] = $raw;
    }
    $columns = [];
    $titleCols = $cells[$titleRow];
    ksort($titleCols, SORT_NUMERIC);
    foreach ($titleCols as $col => $name) {
        $colName = trim((string)$name);
        $category = '';
        if ($col === 0) {
            $colName = $colName !== '' ? $colName : '姓名';
            $columns[$col] = ['name' => $colName, 'category' => ''];
            continue;
        }
        if ($colName === '') {
            for ($i = count($groupLayersRaw) - 1; $i >= 0; $i--) {
                if (isset($groupLayersRaw[$i][$col])) {
                    $inherited = trim((string)$groupLayersRaw[$i][$col]);
                    if ($inherited !== '' && strpos($inherited, '年') === false) {
                        $colName = $inherited;
                        break;
                    }
                }
            }
        }
        $parts = [];
        foreach ($groupLayers as $layer) {
            if (isset($layer[$col])) {
                $g = $layer[$col];
                if ($g !== '' && strpos($g, '年') === false
                    && !in_array($g, $excludeCatNames, true)) {
                    $parts[] = $g;
                }
            }
        }
        $deduped = [];
        $prev = '';
        foreach ($parts as $p) {
            if ($p !== $prev) {
                $deduped[] = $p;
                $prev = $p;
            }
        }
        $category = implode('/', $deduped);
        $columns[$col] = ['name' => $colName, 'category' => $category];
    }

    // 5) "-" 分隔符拆分（兼容旧格式）
    $excludeForDash = ['姓名', '微信号', '工号'];
    foreach ($columns as $colIdx => $cc) {
        $n = (string)$cc['name'];
        $cat = (string)$cc['category'];
        if ($cat !== '' || $n === '' || strpos($n, '-') === false) continue;
        if (in_array($n, $excludeForDash, true)) continue;
        $parts = explode('-', $n);
        if (count($parts) < 2) continue;
        $lastPart = array_pop($parts);
        $parts = array_values(array_filter($parts, function ($s) { return trim($s) !== ''; }));
        if (count($parts) === 0 || trim($lastPart) === '') continue;
        $columns[$colIdx] = ['name' => $lastPart, 'category' => implode('/', $parts)];
    }

    // 6) 严格校验「实发奖金」列
    $hasRealBonus = false;
    foreach ($columns as $c) {
        if ($c['name'] === '实发奖金') {
            $hasRealBonus = true;
            break;
        }
    }
    if (!$hasRealBonus) {
        foreach ($columns as $c) {
            if (strpos($c['name'], '实发奖金') !== false) {
                $hasRealBonus = true;
                break;
            }
        }
    }
    if (!$hasRealBonus) {
        $result['errors'][] = '缺少「实发奖金」列，请确认 Excel 中包含该列';
        return $result;
    }

    // 7) 解析数据行
    ksort($columns, SORT_NUMERIC);
    $rows = [];
    $dupReported = [];
    for ($r = $titleRow + 1; $r <= $maxRow; $r++) {
        if (!isset($cells[$r])) continue;
        $userid = trim((string)($cells[$r][0] ?? ''));
        if ($userid === '') continue;
        $row = [];
        foreach ($columns as $col => $cinfo) {
            $name = $cinfo['name'];
            if (isset($row[$name]) && !isset($dupReported[$name])) {
                $result['errors'][] = "列名重复：$name";
                $dupReported[$name] = true;
            }
            if ($col === 0) {
                $row[$name] = $userid;
            } else {
                $row[$name] = trim((string)($cells[$r][$col] ?? ''));
            }
        }
        $rows[] = $row;
    }

    if (empty($rows)) {
        $result['errors'][] = '未解析到任何员工数据行';
        return $result;
    }

    $result['success'] = true;
    $result['year'] = $year;
    $result['bonus_type'] = $bonusType;
    $result['columns'] = array_values($columns);
    $result['rows'] = $rows;
    return $result;
}

/**
 * 零依赖 xlsx 生成（ZipArchive + 手动拼 XML，无需 PhpSpreadsheet）
 *
 * 适用场景：后台列表导出（100~10000 行，纯文本/数字），不涉及公式/图表/复杂样式。
 *
 * 单元格类型判定：
 *   - null / 空字符串  → 空单元格（不写入 sheetData，避免 XML 膨胀）
 *   - true/false       → 字符串 "是"/"否"
 *   - is_numeric 且非空字符串 → 数字类型 n
 *   - 其他             → 共享字符串 s
 *
 * @param array $headers  列标题（一维数组，按顺序；string[]）
 * @param array $rows     数据行（二维数组，每行与 headers 顺序对齐）
 * @param string $sheetName 工作表名称（不超过 31 字符，非法字符自动截断）
 * @return string .xlsx 二进制内容（可直接 echo + Content-Disposition 下载）
 * @throws Exception ZipArchive 不可用时抛出
 */
function excel_write_xlsx(array $headers, array $rows, $sheetName = 'Sheet1') {
    if (!class_exists('ZipArchive')) {
        throw new Exception('服务器未启用 ZipArchive 扩展，无法生成 Excel 文件');
    }

    // ---- 列索引 -> 字母（A, B, ... Z, AA, AB ...）----
    $colLetter = function ($idx) {
        $letters = '';
        $idx = (int)$idx;
        while ($idx >= 0) {
            $letters = chr(65 + ($idx % 26)) . $letters;
            $idx = (int)($idx / 26) - 1;
        }
        return $letters;
    };

    // ---- 表头粗体样式：Style 1 = 粗体（fontId=1）----
    // 其他单元格用 Style 0（默认）
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/></font>
  </fonts>
  <fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>
  <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="2">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>';

    // ---- 收集共享字符串（按出现顺序记录索引，相同串复用以减小文件）----
    $strings = [];   // string => index
    $stringList = [];

    $ensureString = function ($val) use (&$strings, &$stringList) {
        $s = (string)$val;
        if (isset($strings[$s])) return $strings[$s];
        $idx = count($stringList);
        $strings[$s] = $idx;
        $stringList[] = $s;
        return $idx;
    };

    // ---- 格式化单元格值为 XML <c> 片段 ----
    $cellToXml = function ($colLetter, $rowNum, $val, $styleIdx, $isHeader) use ($ensureString) {
        if ($val === null || $val === '') return '';
        if ($val === true) $val = '是';
        elseif ($val === false) $val = '否';
        $ref = $colLetter . $rowNum;
        if (!$isHeader && is_numeric($val) && $val !== '') {
            // 数字类型：不进共享字符串
            $v = htmlspecialchars((string)$val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            return '<c r="' . $ref . '" s="' . $styleIdx . '"><v>' . $v . '</v></c>';
        }
        $idx = $ensureString($val);
        return '<c r="' . $ref . '" s="' . $styleIdx . '" t="s"><v>' . $idx . '</v></c>';
    };

    // ---- 拼 sheet1.xml 的行数据 ----
    $sheetRowsXml = '';

    // 第 1 行：粗体表头
    $headerCells = '';
    foreach (array_values($headers) as $c => $h) {
        $headerCells .= $cellToXml($colLetter($c), 1, $h, 1, true);
    }
    $sheetRowsXml .= '<row r="1">' . $headerCells . '</row>';

    // 数据行：从第 2 行起
    $ri = 2;
    foreach ($rows as $row) {
        $cells = '';
        $row = array_values($row);
        foreach ($row as $c => $v) {
            $cells .= $cellToXml($colLetter($c), $ri, $v, 0, false);
        }
        $sheetRowsXml .= '<row r="' . $ri . '">' . $cells . '</row>';
        $ri++;
    }

    // 共享字符串表 XML
    $sstXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
     . count($stringList) . '" uniqueCount="' . count($stringList) . '">';
    foreach ($stringList as $s) {
        $sstXml .= '<si><t>' . htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
    }
    $sstXml .= '</sst>';

    // sheet1.xml
    $safeSheetName = safe_substr(preg_replace('/[\/\\\?\*\[\]:]/u', '_', (string)$sheetName), 0, 31);
    if ($safeSheetName === '') $safeSheetName = 'Sheet1';
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheetData>' . $sheetRowsXml . '</sheetData>
</worksheet>';

    // workbook.xml
    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="' . htmlspecialchars($safeSheetName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';

    // workbook rels
    $wbRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>';

    // [Content_Types].xml
    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>';

    // .rels
    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

    // ---- Zip 打包，写入内存流（避免磁盘临时文件 + 权限问题）----
    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
    if ($tmpFile === false) {
        throw new Exception('无法创建临时文件目录，请检查服务器 sys_get_temp_dir() 权限');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpFile);
        throw new Exception('ZipArchive 无法创建临时 xlsx 文件');
    }
    try {
        $zip->addFromString('_rels/.rels', $rootRelsXml);
        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRelsXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('xl/styles.xml', $stylesXml);
        $zip->addFromString('xl/sharedStrings.xml', $sstXml);
    } catch (Throwable $e) {
        $zip->close();
        @unlink($tmpFile);
        throw $e;
    }
    $zip->close();

    $content = file_get_contents($tmpFile);
    @unlink($tmpFile);
    if ($content === false) {
        throw new Exception('xlsx 临时文件读取失败');
    }
    return $content;
}
