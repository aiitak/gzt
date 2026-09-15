-- =========================================================
-- 工资条系统 数据库结构（SQLite 3）
-- 由 install.php 向导执行（选择 SQLite 时用 PDO 内存临时句柄批量导入；也可手动 sqlite3 app.sqlite3 < schema_sqlite.sql）。
-- 所有表/索引/种子使用 CREATE TABLE/INDEX IF NOT EXISTS、INSERT OR IGNORE，幂等可反复执行。
--
-- SCHEMA_VERSION = 2026082701 （与 db.php 内置期望版本对比，不一致写日志提示补改 schema）
---   变更记录：
---     2026082701  新增 approval_orders 审批单基础表（info 字段为 gzcompress+base64 压缩 JSON）
--   变更记录：
--     2026082401  新增 users.vacation_rule_type/special_start/special_days/special_cap 四列（特殊年假规则）
--   变更记录：
--     2026082201  全量对齐 db.php：补 departments.sort_order / users.hire_date
--                 新增 vacation_rules/approvals/ledger + approval_callback_logs 四表
--                 补 vacation_rules 两档封顶三列（cap1_max_days/cap2_min/cap2_max_days）
--                 补 seed：roles/bonus_types.year_end、10 条 msg_templates、vacation_rules 默认当年行
--                 补全部 CREATE INDEX（与 db_migrate_indexes 完全对齐）
-- =========================================================

-- ---------- 组织与账号 ----------
CREATE TABLE IF NOT EXISTS departments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    dept_id    INTEGER NOT NULL,
    name       TEXT NOT NULL DEFAULT '',
    parent_id  INTEGER DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    UNIQUE (dept_id)
);
CREATE INDEX IF NOT EXISTS idx_departments_sort ON departments(sort_order);

CREATE TABLE IF NOT EXISTS roles (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    role_key    TEXT NOT NULL,
    role_name   TEXT NOT NULL,
    description TEXT DEFAULT '',
    UNIQUE (role_key)
);

CREATE TABLE IF NOT EXISTS users (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    userid            TEXT NOT NULL,
    name              TEXT NOT NULL DEFAULT '',
    dept_id           INTEGER DEFAULT 0,
    role              TEXT NOT NULL DEFAULT 'employee',
    password          TEXT DEFAULT NULL,
    has_set_password  INTEGER NOT NULL DEFAULT 0,
    admin_login       TEXT DEFAULT NULL,
    admin_pass        TEXT DEFAULT NULL,
    wecom_userid      TEXT DEFAULT NULL,
    hire_date         TEXT DEFAULT NULL,  -- 入职日期（年假计算主数据源）
    vacation_exempt  INTEGER NOT NULL DEFAULT 0,  -- 年假豁免：1=不享受年假（无论工龄），0=按规则计算
    vacation_rule_type INTEGER NOT NULL DEFAULT 0,  -- 年假规则类型：0=常规规则, 1=两年增加一天
    vacation_special_start TEXT DEFAULT NULL,  -- 特殊年假规则起点日期（不依赖入职日期）
    vacation_special_days INTEGER NOT NULL DEFAULT 0,  -- 特殊年假规则起点基础天数
    vacation_special_cap INTEGER NOT NULL DEFAULT 15,  -- 特殊年假规则独立封顶天数
    created_at        TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (userid),
    UNIQUE (admin_login)
);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_wecom ON users(wecom_userid);

CREATE TABLE IF NOT EXISTS assignments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    assign_type TEXT NOT NULL DEFAULT 'user',
    target_id   TEXT NOT NULL DEFAULT '',
    hr_userid   TEXT NOT NULL DEFAULT '',
    created_at  TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_assignments_type ON assignments(assign_type, target_id);

-- ---------- 工资 ----------
CREATE TABLE IF NOT EXISTS salary (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    userid         TEXT NOT NULL,
    year           INTEGER NOT NULL,
    month          INTEGER NOT NULL,
    real_pay       REAL NOT NULL DEFAULT 0,
    status         TEXT NOT NULL DEFAULT 'draft',
    confirmed      INTEGER NOT NULL DEFAULT 0,
    name           TEXT NOT NULL DEFAULT '',
    dept_name      TEXT NOT NULL DEFAULT '',
    confirmed_at   TEXT DEFAULT NULL,
    pushed_at      TEXT DEFAULT NULL,
    last_remind_at TEXT DEFAULT NULL,
    deleted_at     TEXT DEFAULT NULL,
    deleted_by     TEXT DEFAULT NULL,
    created_at     TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (year, month, userid)
);
CREATE INDEX IF NOT EXISTS idx_salary_status ON salary(status);
CREATE INDEX IF NOT EXISTS idx_salary_user ON salary(userid);
CREATE INDEX IF NOT EXISTS idx_salary_ym_status ON salary(year, month, status);
CREATE INDEX IF NOT EXISTS idx_salary_user_ym ON salary(userid, year, month);

CREATE TABLE IF NOT EXISTS salary_items (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    salary_id    INTEGER NOT NULL,
    item_name    TEXT NOT NULL,
    item_value   TEXT DEFAULT '',
    sort_order   INTEGER NOT NULL DEFAULT 0,
    is_deduction INTEGER NOT NULL DEFAULT 0,
    category     TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_salary_items_salary ON salary_items(salary_id);

CREATE TABLE IF NOT EXISTS salary_columns (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    item_name  TEXT NOT NULL,
    category   TEXT NOT NULL DEFAULT 'income',
    sort_order INTEGER NOT NULL DEFAULT 0,
    visible    INTEGER NOT NULL DEFAULT 1,
    UNIQUE (item_name)
);

-- ---------- 反馈 ----------
CREATE TABLE IF NOT EXISTS feedback (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    salary_id    INTEGER DEFAULT NULL,
    bonus_id     INTEGER DEFAULT NULL,
    userid       TEXT NOT NULL,
    content      TEXT NOT NULL,
    status       TEXT NOT NULL DEFAULT 'pending',
    assignee     TEXT DEFAULT NULL,
    reply        TEXT DEFAULT NULL,
    reply_at     TEXT DEFAULT NULL,
    reply_by     TEXT DEFAULT NULL,
    salary_year  INTEGER DEFAULT NULL,
    salary_month INTEGER DEFAULT NULL,
    bonus_year   INTEGER DEFAULT NULL,
    bonus_type   TEXT DEFAULT NULL,
    created_at   TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_feedback_user ON feedback(userid);
CREATE INDEX IF NOT EXISTS idx_feedback_status ON feedback(status);
CREATE INDEX IF NOT EXISTS idx_feedback_assignee ON feedback(assignee);

-- ---------- 系统设置 & 消息日志 ----------
CREATE TABLE IF NOT EXISTS settings (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    "key"   TEXT NOT NULL,
    "value" TEXT,
    UNIQUE ("key")
);

CREATE TABLE IF NOT EXISTS message_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    type       TEXT NOT NULL DEFAULT '',
    touser     TEXT NOT NULL DEFAULT '',
    sender     TEXT NOT NULL DEFAULT '',
    msgtype    TEXT NOT NULL DEFAULT '',
    recipients TEXT,
    title      TEXT NOT NULL DEFAULT '',
    content    TEXT,
    status     INTEGER NOT NULL DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_message_log_type_user ON message_log(type, touser);

-- ---------- 标签 ----------
CREATE TABLE IF NOT EXISTS tags (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    tag_id  INTEGER NOT NULL,
    tagname TEXT NOT NULL DEFAULT '',
    UNIQUE (tag_id)
);

CREATE TABLE IF NOT EXISTS tag_users (
    tag_id INTEGER NOT NULL,
    userid TEXT NOT NULL,
    name   TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (tag_id, userid)
);

-- ---------- 消息模板 ----------
CREATE TABLE IF NOT EXISTS msg_templates (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL DEFAULT '',
    tpl_key    TEXT NOT NULL DEFAULT '',
    msgtype    TEXT NOT NULL DEFAULT 'text',
    title      TEXT NOT NULL DEFAULT '',
    content    TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_msg_templates_msgtype ON msg_templates(msgtype);
CREATE UNIQUE INDEX IF NOT EXISTS idx_msg_templates_tplkey ON msg_templates(tpl_key);

-- ---------- 奖金 ----------
CREATE TABLE IF NOT EXISTS bonus_types (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    type_key   TEXT NOT NULL UNIQUE,
    type_name  TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS bonus (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    userid         TEXT NOT NULL,
    name           TEXT NOT NULL DEFAULT '',
    dept_name      TEXT NOT NULL DEFAULT '',
    year           INTEGER NOT NULL,
    bonus_type     TEXT NOT NULL,
    amount         REAL NOT NULL DEFAULT 0,
    remark         TEXT DEFAULT NULL,
    status         TEXT NOT NULL DEFAULT 'draft',
    pushed_at      TEXT DEFAULT NULL,
    confirmed      INTEGER NOT NULL DEFAULT 0,
    confirmed_at   TEXT DEFAULT NULL,
    last_remind_at TEXT DEFAULT NULL,
    deleted_at     TEXT DEFAULT NULL,
    deleted_by     TEXT DEFAULT NULL,
    created_at     TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (year, bonus_type, userid)
);
CREATE INDEX IF NOT EXISTS idx_bonus_user ON bonus(userid);
CREATE INDEX IF NOT EXISTS idx_bonus_year_type ON bonus(year, bonus_type);
CREATE INDEX IF NOT EXISTS idx_bonus_status ON bonus(status);

CREATE TABLE IF NOT EXISTS bonus_items (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    bonus_id     INTEGER NOT NULL,
    item_name    TEXT NOT NULL,
    item_value   TEXT DEFAULT '',
    sort_order   INTEGER NOT NULL DEFAULT 0,
    is_deduction INTEGER NOT NULL DEFAULT 0,
    category     TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_bonus_items_bonus ON bonus_items(bonus_id);

-- ---------- 审计日志 ----------
CREATE TABLE IF NOT EXISTS audit_log (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    op_time   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    op_user   TEXT NOT NULL,
    op_type   TEXT NOT NULL,
    op_target TEXT DEFAULT '',
    op_result TEXT NOT NULL DEFAULT 'ok',
    ip        TEXT NOT NULL DEFAULT '',
    ua        TEXT NOT NULL DEFAULT '',
    detail    TEXT DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_audit_time ON audit_log(op_time);
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_log(op_user);
CREATE INDEX IF NOT EXISTS idx_audit_type ON audit_log(op_type);

-- =============== 年假模块 ===============

CREATE TABLE IF NOT EXISTS vacation_rules (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    year               INTEGER NOT NULL UNIQUE,
    rule_name          TEXT NOT NULL DEFAULT '默认年假规则',
    min_years          INTEGER NOT NULL DEFAULT 3,
    base_days          REAL NOT NULL DEFAULT 3,
    year1_prorate      INTEGER NOT NULL DEFAULT 1,
    increment_per_year REAL NOT NULL DEFAULT 1,
    max_days           REAL NOT NULL DEFAULT 10,
    cap1_max_days      REAL NOT NULL DEFAULT 10,
    cap2_min           REAL NOT NULL DEFAULT 20,
    cap2_max_days      REAL NOT NULL DEFAULT 15,
    round_precision    INTEGER NOT NULL DEFAULT 2,
    allow_cross_year   INTEGER NOT NULL DEFAULT 0,
    sort_order         INTEGER NOT NULL DEFAULT 0,
    is_active          INTEGER NOT NULL DEFAULT 1,
    created_at         TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at         TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_vac_rules_active ON vacation_rules(is_active, sort_order);

CREATE TABLE IF NOT EXISTS vacation_approvals (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    sp_no         TEXT NOT NULL UNIQUE,
    template_id   TEXT NOT NULL DEFAULT '',
    sp_name       TEXT NOT NULL DEFAULT '',
    sp_status     INTEGER NOT NULL DEFAULT 0,
    apply_userid  TEXT NOT NULL DEFAULT '',
    apply_name    TEXT NOT NULL DEFAULT '',
    apply_time    TEXT DEFAULT NULL,
    finish_time   TEXT DEFAULT NULL,
    leave_start   TEXT DEFAULT NULL,
    leave_end     TEXT DEFAULT NULL,
    leave_days    REAL NOT NULL DEFAULT 0,
    year          INTEGER NOT NULL DEFAULT 0,
    hire_date_raw TEXT DEFAULT NULL,
    raw_json      TEXT DEFAULT NULL,
    created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at    TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_vac_app_tpl_status ON vacation_approvals(template_id, sp_status);
CREATE INDEX IF NOT EXISTS idx_vac_app_user ON vacation_approvals(apply_userid);
CREATE INDEX IF NOT EXISTS idx_vac_app_status_time ON vacation_approvals(sp_status, finish_time);

CREATE TABLE IF NOT EXISTS vacation_ledger (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    userid     TEXT NOT NULL,
    name       TEXT NOT NULL DEFAULT '',
    year       INTEGER NOT NULL,
    delta      REAL NOT NULL DEFAULT 0,
    source     INTEGER NOT NULL DEFAULT 2,
    ref_no     TEXT DEFAULT NULL,
    remark     TEXT NOT NULL DEFAULT '',
    op_by      TEXT NOT NULL DEFAULT '',
    tm         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_vac_ledger_user_year ON vacation_ledger(userid, year);
CREATE INDEX IF NOT EXISTS idx_vac_ledger_source_tm ON vacation_ledger(source, tm);
CREATE INDEX IF NOT EXISTS idx_vac_ledger_ref ON vacation_ledger(ref_no);
CREATE INDEX IF NOT EXISTS idx_vac_ledger_sp_no ON vacation_ledger(sp_no);

CREATE TABLE IF NOT EXISTS approval_callback_logs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    received_at  TEXT NOT NULL DEFAULT '',
    method       TEXT NOT NULL DEFAULT '',
    remote_ip    TEXT NOT NULL DEFAULT '',
    sp_no        TEXT NOT NULL DEFAULT '',
    template_id  TEXT NOT NULL DEFAULT '',
    sp_status    INTEGER NOT NULL DEFAULT 0,
    apply_name   TEXT NOT NULL DEFAULT '',
    signature_ok INTEGER NOT NULL DEFAULT 0,
    decrypt_ok   INTEGER NOT NULL DEFAULT 0,
    sync_status  TEXT NOT NULL DEFAULT '',
    sync_detail  TEXT NOT NULL DEFAULT '',
    created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_acl_created ON approval_callback_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_acl_sp_no ON approval_callback_logs(sp_no);

-- 审批单基础表（所有审批单统一存储，info 为 gzcompress+base64 压缩 JSON 原文）
CREATE TABLE IF NOT EXISTS approval_orders (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sp_no        TEXT NOT NULL,
    sp_name      TEXT NOT NULL DEFAULT '',
    sp_status    INTEGER NOT NULL DEFAULT 0,
    template_id  TEXT NOT NULL DEFAULT '',
    apply_time   TEXT DEFAULT NULL,
    userid       TEXT NOT NULL DEFAULT '',
    name         TEXT NOT NULL DEFAULT '',
    info         TEXT,
    created_at   TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at   TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (sp_no)
);
CREATE INDEX IF NOT EXISTS idx_ao_status ON approval_orders(sp_status);
CREATE INDEX IF NOT EXISTS idx_ao_tpl ON approval_orders(template_id);
CREATE INDEX IF NOT EXISTS idx_ao_user ON approval_orders(userid);

-- =========================================================
-- 内置种子数据（全部幂等：INSERT OR IGNORE / 唯一键冲突忽略）
-- 顺序与 db.php db_seed_builtin_data 严格对齐，改一处必改另一处
-- =========================================================

-- 1. 默认角色（4 种）
INSERT OR IGNORE INTO roles (role_key, role_name, description) VALUES
    ('admin',    '管理员', '分配角色、全局配置'),
    ('finance',  '财务',   '上传工资条、下发'),
    ('hr',       '人事',   '回复员工反馈'),
    ('employee', '员工',   '查看/确认/反馈');

-- 2. 默认奖金类型
INSERT OR IGNORE INTO bonus_types (type_key, type_name, sort_order) VALUES
    ('year_end', '年终奖', 0);

-- 3. 内置业务消息模板（10 条）
INSERT OR IGNORE INTO msg_templates (tpl_key, name, msgtype, title, content) VALUES
    ('salary_send',              '工资下发通知（员工）',                 'template_card', '{年月}工资条已发布',            '亲爱的 {姓名}，{年月} 月薪资已生成。
请点击卡片查看明细并确认签收：{链接}'),
    ('push_result',              '工资下发结果通知（管理员/财务）',      'text',          '',                           '【下发结果】{年}年{月}月 工资条已下发 {成功数} 人（共 {总数} 人）。'),
    ('salary_remind',            '工资催办确认通知（员工）',             'template_card', '{年月}工资条待确认',          '亲爱的 {姓名}，您还有 {年}年{月}月 工资条尚未确认。
请点击卡片查看明细并完成签收。'),
    ('feedback_to_hr_salary',    '工资反馈通知（人事收）',              'template_card', '新的工资反馈待处理',            '员工 {姓名}({账号}) 对 {年月} 工资条提出反馈，请点击查看：{链接}'),
    ('feedback_to_hr_bonus',     '奖金反馈通知（人事收）',              'template_card', '新的奖金反馈待处理',            '员工 {姓名}({账号}) 对 {年}年「{奖金类型}」奖金提出反馈，请点击查看：{链接}'),
    ('feedback_reply_salary',    '工资反馈回复通知（员工收）',          'template_card', '【反馈已回复】{年月}工资反馈',   '您提交的工资条反馈已由人事回复，点击查看详情：{链接}'),
    ('feedback_reply_bonus',     '奖金反馈回复通知（员工收）',          'template_card', '【反馈已回复】{年}年{奖金类型}反馈', '您提交的奖金反馈已由人事回复，点击查看详情：{链接}'),
    ('bonus_push_result',        '奖金下发结果通知（管理员/财务）',      'text',          '',                           '【下发结果】{年}年「{奖金类型}」已下发 {成功数} 人（共 {总数} 人）。'),
    ('bonus_send',               '奖金下发通知（员工）',                 'template_card', '{年}年{奖金类型}已发放',       '亲爱的【{姓名}】同志您好！感谢您的辛勤付出，您{年}年的{奖金类型}已经发放，请点击查看详情。如有问题请及时反馈！'),
    ('bonus_remind',             '奖金催办确认通知（员工）',             'template_card', '{奖金类型}待确认',             '亲爱的 {姓名}，您还有 {年} 年的 {奖金类型} 尚未确认。
请点击卡片查看明细并完成签收。');

-- 4. 默认年假规则（当年一条，满3年生效+首年折算+逐年递增1+两档封顶）
--    注：strftime('%Y','now') = 当年年份（UTC+8 误差<=1 天，install.php 向导会再补）
INSERT OR IGNORE INTO vacation_rules
    (year, rule_name, min_years, base_days, year1_prorate, increment_per_year,
     max_days, cap1_max_days, cap2_min, cap2_max_days,
     round_precision, allow_cross_year, sort_order, is_active) VALUES
    (CAST(strftime('%Y','now') AS INTEGER), '默认年假规则', 3, 3.0, 1, 1.0, 10.0, 10.0, 20.0, 15.0, 2, 0, 0, 1);
