<?php
/**
 * 数据库单例连接（PDO）
 *  - DB_TYPE === 'mysql'  → MariaDB / MySQL
 *  - 其它（默认）          → SQLite 3（单文件，零依赖）
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

/**
 * 期望的 schema 版本号，与 sql/schema_*.sql 头部注释 SCHEMA_VERSION 保持一致。
 * 两者不一致时会在自动迁移入口写 error_log，提醒运维补改 schema 或跑迁移。
 * 命名约定：YYYYMMDDNN，最后两位用于当天多次变更时自增。
 */
define('EXPECTED_SCHEMA_VERSION', '2026082701');

function db_ensure_column($db, $table, $col, $def) {
    try {
        $type = defined('DB_TYPE') ? DB_TYPE : 'sqlite';
        $has = false;
        if ($type === 'mysql') {
            $info = $db->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetchAll(PDO::FETCH_ASSOC);
            $has = count($info) > 0;
        } else {
            $info = $db->query("PRAGMA table_info('$table')")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($info as $c) {
                if ($c['name'] === $col) {
                    $has = true;
                    break;
                }
            }
        }
        if (!$has) {
            // 真实发生了补列动作时写日志，便于追溯：自动迁移生效了哪一步
            error_log("[db_migrate] ADD COLUMN {$table}.{$col} {$def} (schema=" . EXPECTED_SCHEMA_VERSION . ")");
            $db->exec("ALTER TABLE $table ADD COLUMN $col $def");
        }
    } catch (Throwable $e) {
        error_log("db_ensure_column({$table}.{$col}) failed: " . $e->getMessage());
    }
}

/**
 * 建表逻辑：所有 CREATE TABLE 语句（不含索引）
 */
function db_create_tables($db, $isMysql) {
    $sqls = [
        "CREATE TABLE IF NOT EXISTS departments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dept_id INTEGER NOT NULL,
            name TEXT NOT NULL DEFAULT '',
            parent_id INTEGER DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            UNIQUE (dept_id)
        )",
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            userid TEXT NOT NULL,
            name TEXT NOT NULL DEFAULT '',
            dept_id INTEGER DEFAULT 0,
            role TEXT NOT NULL DEFAULT 'employee',
            password TEXT DEFAULT NULL,
            has_set_password INTEGER NOT NULL DEFAULT 0,
            admin_login TEXT DEFAULT NULL,
            admin_pass TEXT DEFAULT NULL,
            wecom_userid TEXT DEFAULT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (userid),
            UNIQUE (admin_login)
        )",
        "CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            role_key TEXT NOT NULL,
            role_name TEXT NOT NULL,
            description TEXT DEFAULT '',
            UNIQUE (role_key)
        )",
        "CREATE TABLE IF NOT EXISTS assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            assign_type TEXT NOT NULL DEFAULT 'user',
            target_id TEXT NOT NULL DEFAULT '',
            hr_userid TEXT NOT NULL DEFAULT '',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS salary (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            userid TEXT NOT NULL,
            year INTEGER NOT NULL,
            month INTEGER NOT NULL,
            real_pay REAL NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'draft',
            confirmed INTEGER NOT NULL DEFAULT 0,
            confirmed_at TEXT DEFAULT NULL,
            pushed_at TEXT DEFAULT NULL,
            last_remind_at TEXT DEFAULT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (year, month, userid)
        )",
        "CREATE TABLE IF NOT EXISTS salary_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            salary_id INTEGER NOT NULL,
            item_name TEXT NOT NULL,
            item_value TEXT DEFAULT '',
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_deduction INTEGER NOT NULL DEFAULT 0,
            category TEXT NOT NULL DEFAULT ''
        )",
        "CREATE TABLE IF NOT EXISTS salary_columns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_name TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'income',
            sort_order INTEGER NOT NULL DEFAULT 0,
            visible INTEGER NOT NULL DEFAULT 1,
            UNIQUE (item_name)
        )",
        "CREATE TABLE IF NOT EXISTS feedback (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            salary_id INTEGER DEFAULT NULL,
            bonus_id INTEGER DEFAULT NULL,
            userid TEXT NOT NULL,
            content TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            assignee TEXT DEFAULT NULL,
            reply TEXT DEFAULT NULL,
            reply_at TEXT DEFAULT NULL,
            reply_by TEXT DEFAULT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            \"key\" TEXT NOT NULL,
            \"value\" TEXT,
            UNIQUE (\"key\")
        )",
        "CREATE TABLE IF NOT EXISTS message_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL DEFAULT '',
            touser TEXT NOT NULL DEFAULT '',
            sender TEXT NOT NULL DEFAULT '',
            msgtype TEXT NOT NULL DEFAULT '',
            recipients TEXT,
            title TEXT NOT NULL DEFAULT '',
            content TEXT,
            status INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tag_id INTEGER NOT NULL,
            tagname TEXT NOT NULL DEFAULT '',
            UNIQUE (tag_id)
        )",
        "CREATE TABLE IF NOT EXISTS tag_users (
            tag_id INTEGER NOT NULL,
            userid TEXT NOT NULL,
            name TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (tag_id, userid)
        )",
        // 操作审计日志：覆盖所有写操作，便于追溯
        "CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            op_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            op_user TEXT NOT NULL,
            op_type TEXT NOT NULL,
            op_target TEXT DEFAULT '',
            op_result TEXT DEFAULT 'ok',
            ip TEXT DEFAULT '',
            ua TEXT DEFAULT '',
            detail TEXT DEFAULT ''
        )",
        // 奖金类型字典表（仅内置年终奖一种，其余由管理员自定义）
        "CREATE TABLE IF NOT EXISTS bonus_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type_key TEXT NOT NULL UNIQUE,
            type_name TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0
        )",
        // 奖金主表：一年一类型一人一条
        "CREATE TABLE IF NOT EXISTS bonus (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            userid TEXT NOT NULL,
            name TEXT NOT NULL DEFAULT '',
            dept_name TEXT NOT NULL DEFAULT '',
            year INTEGER NOT NULL,
            bonus_type TEXT NOT NULL,
            amount REAL NOT NULL DEFAULT 0,
            remark TEXT DEFAULT '',
            status TEXT NOT NULL DEFAULT 'draft',
            pushed_at TEXT DEFAULT NULL,
            confirmed INTEGER NOT NULL DEFAULT 0,
            confirmed_at TEXT DEFAULT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (year, bonus_type, userid)
        )",
        // 奖金明细项：支持多层表头（同 salary_items）
        "CREATE TABLE IF NOT EXISTS bonus_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bonus_id INTEGER NOT NULL,
            item_name TEXT NOT NULL,
            item_value TEXT DEFAULT '',
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_deduction INTEGER NOT NULL DEFAULT 0,
            category TEXT NOT NULL DEFAULT ''
        )",
        "CREATE TABLE IF NOT EXISTS msg_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL DEFAULT '',
            tpl_key TEXT NOT NULL DEFAULT '',
            msgtype TEXT NOT NULL DEFAULT 'text',
            title TEXT NOT NULL DEFAULT '',
            content TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        // 年假规则：可配置的工龄门槛/基准天数/封顶等，按 year 唯一，每年独立一条规则
        "CREATE TABLE IF NOT EXISTS vacation_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL UNIQUE,
            rule_name TEXT NOT NULL DEFAULT '默认年假规则',
            min_years INTEGER NOT NULL DEFAULT 3,
            base_days REAL NOT NULL DEFAULT 3,
            year1_prorate INTEGER NOT NULL DEFAULT 1,
            increment_per_year REAL NOT NULL DEFAULT 1,
            max_days REAL NOT NULL DEFAULT 10,
            cap1_max_days REAL NOT NULL DEFAULT 10,
            cap2_min REAL NOT NULL DEFAULT 20,
            cap2_max_days REAL NOT NULL DEFAULT 15,
            round_precision INTEGER NOT NULL DEFAULT 2,
            allow_cross_year INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        // 年假审批单原始快照：企微审批单全量数据，sp_no 唯一，用于追溯/防重/状态机
        "CREATE TABLE IF NOT EXISTS vacation_approvals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sp_no TEXT NOT NULL,
            template_id TEXT NOT NULL DEFAULT '',
            sp_name TEXT NOT NULL DEFAULT '',
            sp_status INTEGER NOT NULL DEFAULT 0,
            apply_userid TEXT NOT NULL DEFAULT '',
            apply_name TEXT NOT NULL DEFAULT '',
            apply_time TEXT DEFAULT NULL,
            finish_time TEXT DEFAULT NULL,
            leave_start TEXT DEFAULT NULL,
            leave_end TEXT DEFAULT NULL,
            leave_days REAL NOT NULL DEFAULT 0,
            year INTEGER NOT NULL DEFAULT 0,
            hire_date_raw TEXT DEFAULT NULL,
            raw_json TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (sp_no)
        )",
        // 年假流水账：最终扣减只认这张表；source=1审批通过(负) 2手动调整(±) 3系统调整(±) 4审批撤销(正)
        "CREATE TABLE IF NOT EXISTS vacation_ledger (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            userid TEXT NOT NULL,
            name TEXT NOT NULL DEFAULT '',
            year INTEGER NOT NULL,
            delta REAL NOT NULL DEFAULT 0,
            source INTEGER NOT NULL DEFAULT 2,
            ref_no TEXT DEFAULT NULL,
            remark TEXT NOT NULL DEFAULT '',
            op_by TEXT NOT NULL DEFAULT '',
            tm TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        // 审批回调日志：企微 POST 回调的结构化记录，替代文件日志
        "CREATE TABLE IF NOT EXISTS approval_callback_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            received_at TEXT NOT NULL DEFAULT '',
            method TEXT NOT NULL DEFAULT '',
            remote_ip TEXT NOT NULL DEFAULT '',
            sp_no TEXT NOT NULL DEFAULT '',
            template_id TEXT NOT NULL DEFAULT '',
            sp_status INTEGER NOT NULL DEFAULT 0,
            apply_name TEXT NOT NULL DEFAULT '',
            signature_ok INTEGER NOT NULL DEFAULT 0,
            decrypt_ok INTEGER NOT NULL DEFAULT 0,
            sync_status TEXT NOT NULL DEFAULT '',
            sync_detail TEXT NOT NULL DEFAULT '',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        // 审批单基础表：所有审批单（含年假/考勤/补卡等）的统一存储，info 字段为 gzcompress+base64 压缩的 JSON 原文
        "CREATE TABLE IF NOT EXISTS approval_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sp_no TEXT NOT NULL,
            sp_name TEXT NOT NULL DEFAULT '',
            sp_status INTEGER NOT NULL DEFAULT 0,
            template_id TEXT NOT NULL DEFAULT '',
            apply_time TEXT DEFAULT NULL,
            userid TEXT NOT NULL DEFAULT '',
            name TEXT NOT NULL DEFAULT '',
            info MEDIUMTEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (sp_no)
        )",
    ];
    foreach ($sqls as $s) {
        try {
            // MySQL 不兼容 SQLite 的 AUTOINCREMENT 关键字，替换为 AUTO_INCREMENT
            if ($isMysql) {
                $s = str_replace('AUTOINCREMENT', 'AUTO_INCREMENT', $s);
            }
            $db->exec($s);
            // 仅当执行了 DDL（没有抛异常且不是已存在跳过）时尝试记录；因 CREATE TABLE IF NOT EXISTS
            // 在 SQLite/MySQL 中即使已存在也不会报错，这里不额外查表存在性以减少开销。
            if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?/i', $s, $m)) {
                $tbl = $m[1];
                $type = $isMysql ? 'mysql' : 'sqlite';
                // 粗略探测：刚建的表 SQLite/SHOW TABLES 查一次即可，若自增 ID=1 说明新表
                try {
                    if ($isMysql) {
                        $row = $db->query("SHOW TABLE STATUS LIKE '{$tbl}'")->fetch(PDO::FETCH_ASSOC);
                        if (!empty($row) && isset($row['Auto_increment']) && (int)$row['Auto_increment'] <= 1) {
                            error_log("[db_migrate] CREATE TABLE {$tbl} ({$type}, schema=" . EXPECTED_SCHEMA_VERSION . ")");
                        }
                    } else {
                        $n = (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='{$tbl}'")->fetchColumn();
                        if ($n > 0) {
                            // 无法区分是本次建的还是已存在的，降级为 debug 级别日志，只在 PHP.ini log_level=DEBUG 时关心
                        }
                    }
                } catch (Throwable $e) {}
            }
        } catch (Throwable $e) {
            error_log("db_create_tables statement failed: " . $e->getMessage() . " [sql=" . substr($s, 0, 120) . "]");
        }
    }
}

/**
 * 字段迁移：ALTER TABLE ADD COLUMN + 旧数据回填
 */
function db_migrate_columns($db, $isMysql) {
    db_ensure_column($db, 'users', 'admin_login', 'TEXT DEFAULT NULL');
    db_ensure_column($db, 'users', 'admin_pass', 'TEXT DEFAULT NULL');
    // 旧库兼容：wecom_userid 已在建表语句中定义，此处仅为已有数据库补字段
    db_ensure_column($db, 'users', 'wecom_userid', 'TEXT DEFAULT NULL');
    // 旧库兼容：msg_templates 增加 tpl_key（业务模板唯一键），并补唯一索引
    db_ensure_column($db, 'msg_templates', 'tpl_key', 'TEXT NOT NULL DEFAULT \'\'');
    // 给空 tpl_key 的旧记录赋唯一值（legacy_{id}），避免用户自定义模板在创建唯一索引时被清理删除
    try {
        if (DB_TYPE === 'mysql') {
            $db->exec("UPDATE msg_templates SET tpl_key = CONCAT('legacy_', id) WHERE tpl_key = ''");
        } else {
            $db->exec("UPDATE msg_templates SET tpl_key = 'legacy_' || id WHERE tpl_key = ''");
        }
    } catch (Throwable $e) {}
    db_ensure_column($db, 'message_log', 'sender', 'TEXT NOT NULL DEFAULT \'\'');
    db_ensure_column($db, 'message_log', 'msgtype', 'TEXT NOT NULL DEFAULT \'\'');
    db_ensure_column($db, 'message_log', 'recipients', 'TEXT');
    db_ensure_column($db, 'message_log', 'title', 'TEXT NOT NULL DEFAULT \'\'');
    // 工资明细项增加 category 字段，存储多层表头的分组名（如"应发工资""扣款"）
    db_ensure_column($db, 'salary_items', 'category', 'TEXT NOT NULL DEFAULT \'\'');
    // 旧库兼容：salary 表补 status/confirmed 列（催办/推送/确认流程依赖）
    db_ensure_column($db, 'salary', 'status', 'TEXT NOT NULL DEFAULT \'draft\'');
    db_ensure_column($db, 'salary', 'confirmed', 'INTEGER NOT NULL DEFAULT 0');
    db_ensure_column($db, 'salary', 'name', 'TEXT NOT NULL DEFAULT \'\'');
    db_ensure_column($db, 'salary', 'dept_name', 'TEXT NOT NULL DEFAULT \'\'');
    // 回填 salary.name / salary.dept_name：从 users + departments 表同步
    try {
        $db->exec("UPDATE salary SET name = (SELECT name FROM users WHERE users.userid = salary.userid) WHERE name = ''");
        $db->exec("UPDATE salary SET dept_name = (SELECT d.name FROM users u JOIN departments d ON u.dept_id = d.dept_id WHERE u.userid = salary.userid) WHERE dept_name = ''");
    } catch (Throwable $e) {
    }
    // 反馈表增加 reply_by 字段，记录回复人
    db_ensure_column($db, 'feedback', 'reply_by', 'TEXT DEFAULT NULL');
    // 反馈表固化工资条年月：工资条删除后 salary_id 失效，用 salary_year/month 保留月份归属，避免反馈被误归入"通用反馈"
    db_ensure_column($db, 'feedback', 'salary_year', 'INTEGER DEFAULT NULL');
    db_ensure_column($db, 'feedback', 'salary_month', 'INTEGER DEFAULT NULL');
    // 反馈表支持奖金关联
    db_ensure_column($db, 'feedback', 'bonus_id', 'INTEGER DEFAULT NULL');
    // 奖金催办去重：与工资条 last_remind_at 语义一致（1小时内只催一次）
    db_ensure_column($db, 'bonus', 'last_remind_at', 'TEXT DEFAULT NULL');
    // 旧库兼容：bonus 表补 status/confirmed 列（催办/推送/确认流程依赖）
    db_ensure_column($db, 'bonus', 'status', 'TEXT NOT NULL DEFAULT \'draft\'');
    db_ensure_column($db, 'bonus', 'confirmed', 'INTEGER NOT NULL DEFAULT 0');
    // 软删除字段：回收站功能使用，deleted_at 为 NULL 表示正常数据
    db_ensure_column($db, 'salary', 'deleted_at', 'TEXT DEFAULT NULL');
    db_ensure_column($db, 'salary', 'deleted_by', 'TEXT DEFAULT NULL');
    db_ensure_column($db, 'bonus', 'deleted_at', 'TEXT DEFAULT NULL');
    db_ensure_column($db, 'bonus', 'deleted_by', 'TEXT DEFAULT NULL');
    // 反馈表固化奖金年份/类型：奖金删除后 bonus_id 失效，用 bonus_year/bonus_type 保留归属
    db_ensure_column($db, 'feedback', 'bonus_year', 'INTEGER DEFAULT NULL');
    db_ensure_column($db, 'feedback', 'bonus_type', 'TEXT DEFAULT NULL');
    // 年假：员工入职日期（应享年假主数据源），审批同步只回填空值，不覆盖 HR 手动维护
    db_ensure_column($db, 'users', 'hire_date', 'TEXT DEFAULT NULL');
    // 年假豁免开关：1=不享受年假（无论工龄），0=按规则计算（只影响今后计算，历史流水保留）
    db_ensure_column($db, 'users', 'vacation_exempt', 'INTEGER NOT NULL DEFAULT 0');
    // 年假特殊规则：类型枚举（0=常规规则, 1=两年增加一天）
    db_ensure_column($db, 'users', 'vacation_rule_type', 'INTEGER NOT NULL DEFAULT 0');
    // 年假特殊规则：起点日期（不依赖入职日期）
    db_ensure_column($db, 'users', 'vacation_special_start', 'TEXT DEFAULT NULL');
    // 年假特殊规则：起点基础天数
    db_ensure_column($db, 'users', 'vacation_special_days', 'INTEGER NOT NULL DEFAULT 0');
    // 年假特殊规则：独立封顶天数（默认15天）
    db_ensure_column($db, 'users', 'vacation_special_cap', 'INTEGER NOT NULL DEFAULT 15');
    // departments 排序字段（缺失会导致 vacation_overview 部门列表 ORDER BY sort_order 报错）
    db_ensure_column($db, 'departments', 'sort_order', 'INTEGER NOT NULL DEFAULT 0');
    // vacation_rules：按 year 唯一，每年独立一条规则；allow_cross_year 控制是否允许跨年
    db_ensure_column($db, 'vacation_rules', 'year', 'INTEGER NOT NULL DEFAULT 0');
    db_ensure_column($db, 'vacation_rules', 'allow_cross_year', 'INTEGER NOT NULL DEFAULT 0');
    try {
        // 把现有默认规则的 year 补为当前年份，便于 vacation_get_rules 按年查到；已存在非 0 year 则跳过
        $curYear = (int)date('Y');
        if ($isMysql) {
            $db->exec("UPDATE vacation_rules SET `year` = {$curYear}, allow_cross_year = 0 WHERE `year` = 0 LIMIT 1");
        } else {
            $db->exec("UPDATE vacation_rules SET year = {$curYear}, allow_cross_year = 0 WHERE year = 0 LIMIT 1");
        }
    } catch (Throwable $e) {}
    // vacation_approvals：年度归属，便于按年统计查询
    db_ensure_column($db, 'vacation_approvals', 'year', 'INTEGER NOT NULL DEFAULT 0');
    // vacation_rules：两档封顶（档1：未封顶结果>cap1 且 <cap2_min → cap1_max_days；档2：≥cap2_min → cap2_max_days）
    //  —— 说明：max_days 作为旧字段冗余保留，cap1 缺失时会从 max_days 回退（见 vacation_get_rules alias）
    db_ensure_column($db, 'vacation_rules', 'cap1_max_days', 'REAL NOT NULL DEFAULT 10');
    db_ensure_column($db, 'vacation_rules', 'cap2_min',      'REAL NOT NULL DEFAULT 20');
    db_ensure_column($db, 'vacation_rules', 'cap2_max_days', 'REAL NOT NULL DEFAULT 15');
    try {
        // 兜底回填：老库只有 max_days 没有 cap1 的，同步一份过来作为档1起点（不覆盖已有非 0/NULL 值）
        if ($isMysql) {
            $db->exec("UPDATE vacation_rules SET cap1_max_days = max_days WHERE cap1_max_days IS NULL OR cap1_max_days = 0");
        } else {
            $db->exec("UPDATE vacation_rules SET cap1_max_days = max_days WHERE cap1_max_days IS NULL OR cap1_max_days = 0");
        }
    } catch (Throwable $e) {}
}

/**
 * 索引创建：所有 CREATE INDEX 语句 + msg_templates.tpl_key 唯一索引
 */
function db_migrate_indexes($db, $isMysql) {
    $sqls = [
        "CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_users_wecom ON users(wecom_userid)",
        "CREATE INDEX IF NOT EXISTS idx_assignments_type ON assignments(assign_type, target_id)",
        "CREATE INDEX IF NOT EXISTS idx_salary_status ON salary(status)",
        "CREATE INDEX IF NOT EXISTS idx_salary_user ON salary(userid)",
        // 复合索引：覆盖「按年月+状态」查询（如 do_push、handle_admin_push_months 等高频查询）
        "CREATE INDEX IF NOT EXISTS idx_salary_ym_status ON salary(year, month, status)",
        // 复合索引：覆盖「按用户+年月」查询（如 handle_salary_detail / handle_salary_chart）
        "CREATE INDEX IF NOT EXISTS idx_salary_user_ym ON salary(userid, year, month)",
        "CREATE INDEX IF NOT EXISTS idx_salary_items_salary ON salary_items(salary_id)",
        "CREATE INDEX IF NOT EXISTS idx_feedback_user ON feedback(userid)",
        "CREATE INDEX IF NOT EXISTS idx_feedback_status ON feedback(status)",
        "CREATE INDEX IF NOT EXISTS idx_feedback_assignee ON feedback(assignee)",
        "CREATE INDEX IF NOT EXISTS idx_message_log_type_user ON message_log(type, touser)",
        "CREATE INDEX IF NOT EXISTS idx_audit_time ON audit_log(op_time)",
        "CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_log(op_user)",
        "CREATE INDEX IF NOT EXISTS idx_audit_type ON audit_log(op_type)",
        "CREATE INDEX IF NOT EXISTS idx_bonus_user ON bonus(userid)",
        "CREATE INDEX IF NOT EXISTS idx_bonus_year_type ON bonus(year, bonus_type(255))",
        "CREATE INDEX IF NOT EXISTS idx_bonus_status ON bonus(status)",
        "CREATE INDEX IF NOT EXISTS idx_bonus_items_bonus ON bonus_items(bonus_id)",
        "CREATE INDEX IF NOT EXISTS idx_msg_templates_msgtype ON msg_templates(msgtype)",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_msg_templates_tplkey ON msg_templates(tpl_key)",
        // 年假审批单：按模板/状态/申请人查询高频
        "CREATE INDEX IF NOT EXISTS idx_vac_app_tpl_status ON vacation_approvals(template_id, sp_status)",
        "CREATE INDEX IF NOT EXISTS idx_vac_app_user ON vacation_approvals(apply_userid)",
        "CREATE INDEX IF NOT EXISTS idx_vac_app_status_time ON vacation_approvals(sp_status, finish_time)",
        // 年假流水账：按员工+年度聚合算余额是最高频；按来源/时间筛选
        "CREATE INDEX IF NOT EXISTS idx_vac_ledger_user_year ON vacation_ledger(userid, year)",
        "CREATE INDEX IF NOT EXISTS idx_vac_ledger_source_tm ON vacation_ledger(source, tm)",
        "CREATE INDEX IF NOT EXISTS idx_vac_ledger_ref ON vacation_ledger(ref_no)",
        // sp_no 索引：LEFT JOIN vacation_approvals 按 sp_no 关联时避免全表扫描
        "CREATE INDEX IF NOT EXISTS idx_vac_ledger_sp_no ON vacation_ledger(sp_no)",
        // 年假规则：按激活状态+排序取生效规则
        "CREATE INDEX IF NOT EXISTS idx_vac_rules_active ON vacation_rules(is_active, sort_order)",
        // 审批单基础表：按状态/模板/申请人查询
        "CREATE INDEX IF NOT EXISTS idx_ao_status ON approval_orders(sp_status)",
        "CREATE INDEX IF NOT EXISTS idx_ao_tpl ON approval_orders(template_id)",
        "CREATE INDEX IF NOT EXISTS idx_ao_user ON approval_orders(userid)",
    ];
    foreach ($sqls as $s) {
        try {
            // SQLite 不支持 MySQL 前缀索引语法 col(255)，去掉前缀长度限定避免执行失败
            if (!$isMysql) {
                $s = preg_replace('/\((\d+)\)(?=\s*[,)])/', '', $s);
            }
            // MySQL 不支持 CREATE INDEX IF NOT EXISTS 语法，需先检查索引是否存在
            if ($isMysql && preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+(\w+)\s+ON\s+(\w+)\s*\((.+)\)$/i', $s, $m)) {
                $unique = !empty($m[1]);
                $idxName = $m[2];
                $tblName = $m[3];
                $checkStmt = $db->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?");
                $checkStmt->execute([$tblName, $idxName]);
                if ((int)$checkStmt->fetchColumn() > 0) {
                    continue; // 索引已存在，跳过
                }
                $cols = $m[4];
                $db->exec(($unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ') . $idxName . ' ON ' . $tblName . ' (' . $cols . ')');
            } else {
                $db->exec($s);
            }
        } catch (Throwable $e) {
            // 幂等：索引已存在则忽略
        }
    }
    // 显式创建 msg_templates.tpl_key 唯一索引（兼容旧库 tpl_key 列后加的场景）
    try {
        if ($isMysql) {
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'msg_templates' AND index_name = 'idx_msg_templates_tplkey'");
            $checkStmt->execute();
            if ((int)$checkStmt->fetchColumn() === 0) {
                $db->exec('CREATE UNIQUE INDEX idx_msg_templates_tplkey ON msg_templates(tpl_key)');
            }
        } else {
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_msg_templates_tplkey ON msg_templates(tpl_key)');
        }
    } catch (Throwable $e) {
    }
}

/**
 * 清理废弃数据：删除废弃模板 + 统一部门名称
 */
function db_cleanup_deprecated($db) {
    // 迁移：删除已废弃的旧模板（feedback_to_hr/feedback_reply 已拆分为工资/奖金两类）
    $deprecatedKeys = ['feedback_to_hr', 'feedback_reply', 'year_end_bonus'];
    try {
        $delStmt = $db->prepare("DELETE FROM msg_templates WHERE tpl_key = ?");
        foreach ($deprecatedKeys as $oldKey) {
            try { $delStmt->execute([$oldKey]); } catch (Throwable $e) {}
        }
        // 兜底：按名称模糊删除所有含"年终奖"的旧模板（不论tpl_key是否为空）
        try { $db->exec("DELETE FROM msg_templates WHERE name LIKE '%年终奖%'"); } catch (Throwable $e) {}
    } catch (Throwable $e) {}
    // 统一部门名称：将"已辞职"改为"已离职"（"未匹配"保留，用于工资条上传未匹配员工）
    try {
        $db->exec("UPDATE departments SET name = '已离职' WHERE name = '已辞职'");
        $db->exec("UPDATE salary SET dept_name = '已离职' WHERE dept_name = '已辞职'");
        // HIST_ 前缀用户空 dept_name 不再强制设为"已离职"，保留空值由具体场景设置
    } catch (Throwable $e) {
    }
}

/**
 * 内置数据：角色、消息模板、奖金类型
 */
function db_seed_builtin_data($db) {
    try {
        $rolesSql = (defined('DB_TYPE') && DB_TYPE === 'mysql')
            ? "INSERT IGNORE INTO roles (role_key, role_name, description) VALUES
                ('admin','管理员','分配角色、全局配置'),
                ('finance','财务','上传工资条、下发'),
                ('hr','人事','回复员工反馈'),
                ('employee','员工','查看/确认/反馈')"
            : "INSERT OR IGNORE INTO roles (role_key, role_name, description) VALUES
                ('admin','管理员','分配角色、全局配置'),
                ('finance','财务','上传工资条、下发'),
                ('hr','人事','回复员工反馈'),
                ('employee','员工','查看/确认/反馈')";
        $db->exec($rolesSql);
    } catch (Throwable $e) {
    }
    // 写入内置业务消息模板（不存在时插入；保留用户自定义内容不覆盖）
    $builtinTemplates = [
        [
            'tpl_key' => 'salary_send',
            'name' => '工资下发通知（员工）',
            'msgtype' => 'template_card',
            'title' => '{年月}工资条已发布',
            'content' => "亲爱的 {姓名}，{年月} 月薪资已生成。\n请点击卡片查看明细并确认签收：{链接}"
        ],
        [
            'tpl_key' => 'push_result',
            'name' => '工资下发结果通知（管理员/财务）',
            'msgtype' => 'text',
            'title' => '',
            'content' => '【下发结果】{年}年{月}月 工资条已下发 {成功数} 人（共 {总数} 人）。'
        ],
        [
            'tpl_key' => 'salary_remind',
            'name' => '工资催办确认通知（员工）',
            'msgtype' => 'template_card',
            'title' => '{年月}工资条待确认',
            'content' => "亲爱的 {姓名}，您还有 {年}年{月}月 工资条尚未确认。\n请点击卡片查看明细并完成签收。"
        ],
        [
            'tpl_key' => 'feedback_to_hr_salary',
            'name' => '工资反馈通知（人事收）',
            'msgtype' => 'template_card',
            'title' => '新的工资反馈待处理',
            'content' => '员工 {姓名}({账号}) 对 {年月} 工资条提出反馈，请点击查看：{链接}'
        ],
        [
            'tpl_key' => 'feedback_to_hr_bonus',
            'name' => '奖金反馈通知（人事收）',
            'msgtype' => 'template_card',
            'title' => '新的奖金反馈待处理',
            'content' => '员工 {姓名}({账号}) 对 {年}年{奖金类型} 奖金提出反馈，请点击查看：{链接}'
        ],
        [
            'tpl_key' => 'feedback_reply_salary',
            'name' => '工资反馈回复通知（员工收）',
            'msgtype' => 'template_card',
            'title' => '【反馈已回复】{年月}工资反馈',
            'content' => '您提交的工资条反馈已由人事回复，点击查看详情：{链接}'
        ],
        [
            'tpl_key' => 'feedback_reply_bonus',
            'name' => '奖金反馈回复通知（员工收）',
            'msgtype' => 'template_card',
            'title' => '【反馈已回复】{年}年{奖金类型}反馈',
            'content' => '您提交的奖金反馈已由人事回复，点击查看详情：{链接}'
        ],
        [
            'tpl_key' => 'bonus_push_result',
            'name' => '奖金下发结果通知（管理员/财务）',
            'msgtype' => 'text',
            'title' => '',
            'content' => '【下发结果】{年}年「{奖金类型}」已下发 {成功数} 人（共 {总数} 人）。'
        ],
        [
            'tpl_key' => 'bonus_send',
            'name' => '奖金下发通知（员工）',
            'msgtype' => 'template_card',
            'title' => '{年}年{奖金类型}已发放',
            'content' => "亲爱的【{姓名}】同志您好！感谢您的辛勤付出，您{年}年的{奖金类型}已经发放，请点击查看详情。如有问题请及时反馈！"
        ],
        [
            'tpl_key' => 'bonus_remind',
            'name' => '奖金催办确认通知（员工）',
            'msgtype' => 'template_card',
            'title' => '{奖金类型}待确认',
            'content' => "亲爱的 {姓名}，您还有 {年} 年的 {奖金类型} 尚未确认。\n请点击卡片查看明细并完成签收。"
        ],
    ];
    $insBuiltin = $db->prepare(
        (defined('DB_TYPE') && DB_TYPE === 'mysql')
            ? "INSERT IGNORE INTO msg_templates (tpl_key, name, msgtype, title, content) VALUES (?,?,?,?,?)"
            : "INSERT OR IGNORE INTO msg_templates (tpl_key, name, msgtype, title, content) VALUES (?,?,?,?,?)"
    );
    foreach ($builtinTemplates as $t) {
        try { $insBuiltin->execute([$t['tpl_key'], $t['name'], $t['msgtype'], $t['title'], $t['content']]); } catch (Throwable $e) {}
    }
    // 已存在记录的名称可能过时（如"工资条下发通知"→"工资下发通知（员工）"），需 UPDATE 修正
    $updName = $db->prepare("UPDATE msg_templates SET name = ? WHERE tpl_key = ?");
    foreach ($builtinTemplates as $t) {
        try { $updName->execute([$t['name'], $t['tpl_key']]); } catch (Throwable $e) {}
    }
    // 内置奖金类型：仅"年终奖"一种，其余由管理员自定义
    $bt = $db->prepare(
        (defined('DB_TYPE') && DB_TYPE === 'mysql')
            ? "INSERT IGNORE INTO bonus_types (type_key, type_name, sort_order) VALUES (?,?,0)"
            : "INSERT OR IGNORE INTO bonus_types (type_key, type_name, sort_order) VALUES (?,?,0)"
    );
    try { $bt->execute(['year_end', '年终奖']); } catch (Throwable $e) {}
    // 内置年假默认规则：满3年生效，首年剩余月/12×3，逐年+1，档1封顶10天（>10且<20），档2封顶15天（≥20），四舍五入2位
    $vr = $db->prepare(
        (defined('DB_TYPE') && DB_TYPE === 'mysql')
            ? "INSERT IGNORE INTO vacation_rules (rule_name, min_years, base_days, year1_prorate, increment_per_year, max_days, cap1_max_days, cap2_min, cap2_max_days, round_precision, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,1)"
            : "INSERT OR IGNORE INTO vacation_rules (rule_name, min_years, base_days, year1_prorate, increment_per_year, max_days, cap1_max_days, cap2_min, cap2_max_days, round_precision, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,1)"
    );
    try { $vr->execute(['默认年假规则', 3, 3.0, 1, 1.0, 10.0, 10.0, 20.0, 15.0, 2, 0]); } catch (Throwable $e) {}
}

/**
 * 自动确保表结构完整（幂等）。
 * 拆分为建表→字段迁移→索引→清理→内置数据→时区触发器，按依赖顺序执行。
 */
function db_ensure_schema($db) {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $isMysql = (defined('DB_TYPE') && DB_TYPE === 'mysql');

    // schema_version 校验：期望版本 vs settings 中记录的上次版本
    // —— 差异不阻塞任何动作，只写 error_log 提示运维同步补改 schema.sql 或清理缓存
    try {
        $getV = $isMysql
            ? $db->prepare("SELECT `value` FROM settings WHERE `key` = 'schema_version' LIMIT 1")
            : $db->prepare("SELECT value FROM settings WHERE key = 'schema_version' LIMIT 1");
        $getV->execute();
        $storedVersion = (string)$getV->fetchColumn();
        if ($storedVersion !== EXPECTED_SCHEMA_VERSION) {
            error_log(sprintf(
                "[db_migrate] schema_version mismatch: stored=%s expected=%s (db=%s). 请同步更新 sql/schema_mysql.sql / schema_sqlite.sql 的 SCHEMA_VERSION 注释，或确认自动迁移已补表补列。",
                $storedVersion === '' ? '(not set)' : $storedVersion,
                EXPECTED_SCHEMA_VERSION,
                $isMysql ? 'mysql' : 'sqlite'
            ));
            // 写入本次期望版本，避免每次请求都打一遍日志；下次升级 EXPECTED_SCHEMA_VERSION 时会再次触发
            try {
                $setV = $isMysql
                    ? $db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('schema_version', ?)
                                    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
                    : $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', ?)");
                $setV->execute([EXPECTED_SCHEMA_VERSION]);
            } catch (Throwable $e) {
                error_log("[db_migrate] persist schema_version failed: " . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        // settings 表可能还不存在（首次建库走 db_create_tables 之前），忽略即可
    }

    db_create_tables($db, $isMysql);
    db_migrate_columns($db, $isMysql);
    db_migrate_indexes($db, $isMysql);
    db_cleanup_deprecated($db);
    db_seed_builtin_data($db);

    // SQLite 下 CURRENT_TIMESTAMP 返回 UTC 时间，与 MySQL 的 SET time_zone='+08:00' 行为不一致。
    // 用 AFTER INSERT 触发器将 created_at 修正为北京时间（+8 小时），覆盖所有有 created_at 字段的表。
    // 触发器幂等（CREATE TRIGGER IF NOT EXISTS），MySQL 不需要（已通过 SET time_zone 修复）。
    // 必须在所有数据写入之后创建，避免 seed 数据的 created_at 被触发器二次修改。
    if (!$isMysql) {
        $tzTriggers = [
            'users'        => 'id',
            'assignments'  => 'id',
            'salary'       => 'id',
            'feedback'     => 'id',
            'message_log'  => 'id',
            'msg_templates'=> 'id',
            // audit_log 主键 id + 字段 op_time（与 created_at 不同名，单独处理）
            'bonus'        => 'id',
            'vacation_rules'     => 'id',
            'vacation_approvals' => 'id',
            'vacation_ledger'    => 'id',
        ];
        foreach ($tzTriggers as $tbl => $pk) {
            try {
                $db->exec("CREATE TRIGGER IF NOT EXISTS trg_{$tbl}_created_at
                    AFTER INSERT ON {$tbl}
                    FOR EACH ROW
                    BEGIN
                        UPDATE {$tbl} SET created_at = datetime('now', '+8 hours') WHERE {$pk} = NEW.{$pk};
                    END");
            } catch (Throwable $e) {
                // 表可能不存在或触发器已存在，忽略
            }
        }
        // audit_log 字段名是 op_time 不是 created_at，单独写触发器
        try {
            $db->exec("CREATE TRIGGER IF NOT EXISTS trg_audit_log_optime
                AFTER INSERT ON audit_log
                FOR EACH ROW
                BEGIN
                    UPDATE audit_log SET op_time = datetime('now', '+8 hours') WHERE id = NEW.id;
                END");
        } catch (Throwable $e) {}
    }
}

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $type = defined('DB_TYPE') ? DB_TYPE : 'sqlite';
        if ($type === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                DB_HOST, DB_PORT, DB_NAME
            );
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // 统一 MySQL 会话时区为东八区，避免 CURRENT_TIMESTAMP 与 PHP 时区错位
            @$pdo->exec("SET time_zone = '+08:00'");
            // MySQL 也需要自动迁移建表
            db_ensure_schema($pdo);
        } else {
            $file = defined('DB_FILE') ? DB_FILE : (dirname(__DIR__) . '/salary_data/app.sqlite3');
            $dir  = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $dsn  = 'sqlite:' . $file;
            $pdo  = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // WAL 模式提升并发读写能力；busy_timeout 避免 cron 与 Web 写冲突时直接报错
            @$pdo->exec('PRAGMA journal_mode=WAL');
            @$pdo->exec('PRAGMA busy_timeout=5000');
            @$pdo->exec('PRAGMA foreign_keys=OFF');
            // 自动确保表结构完整（幂等）：缺哪张表建哪张，根治 no such table
            db_ensure_schema($pdo);
        }
    }
    return $pdo;
}

/**
 * 生成跨数据库的 UPSERT（INSERT ON CONFLICT）SQL
 *
 * @param string $table 表名
 * @param array  $cols  字段名数组
 * @param string $conflictKey 冲突键（UNIQUE 约束的字段或字段组合，逗号分隔）
 * @param array  $updateCols 冲突时要更新的字段名数组（默认更新除冲突键外的所有 cols）
 * @return string 适配 SQLite / MariaDB 的 SQL 语句
 */
function db_upsert_sql($table, $cols, $conflictKey, $updateCols = null) {
    $colsList = '`' . implode('`, `', $cols) . '`';
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    if ($updateCols === null) {
        $conflictArr = array_map('trim', explode(',', $conflictKey));
        $updateCols = array_values(array_diff($cols, $conflictArr));
    }
    // updateCols 为空时，退化为 INSERT OR IGNORE / INSERT IGNORE
    if (empty($updateCols)) {
        if (DB_TYPE === 'mysql') {
            return "INSERT IGNORE INTO `$table` ($colsList) VALUES ($placeholders)";
        } else {
            return "INSERT OR IGNORE INTO `$table` ($colsList) VALUES ($placeholders)";
        }
    }
    if (DB_TYPE === 'mysql') {
        $sets = array_map(fn($c) => "`$c` = VALUES(`$c`)", $updateCols);
        return "INSERT INTO `$table` ($colsList) VALUES ($placeholders)
                ON DUPLICATE KEY UPDATE " . implode(', ', $sets);
    } else {
        $sets = array_map(fn($c) => "`$c` = excluded.`$c`", $updateCols);
        // 支持复合冲突键：'tag_id,userid' → `tag_id`, `userid`（逐列加反引号）
        $conflictCols = implode(', ', array_map(fn($c) => '`' . trim($c) . '`', explode(',', $conflictKey)));
        return "INSERT INTO `$table` ($colsList) VALUES ($placeholders)
                ON CONFLICT($conflictCols) DO UPDATE SET " . implode(', ', $sets);
    }
}

/**
 * 检测系统是否已安装（settings 表是否存在且含初始记录）
 */
function is_installed() {
    try {
        $db = get_db();
        // 必须检查 installed 设置项，而非仅检查表是否存在（get_db 会自动建表）
        $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->execute(['installed']);
        $row = $stmt->fetch();
        return $row && $row['value'] === '1';
    } catch (Throwable $e) {
        return false;
    }
}
