-- =========================================================
-- 工资条系统 数据库结构（MySQL 5.7+ / MariaDB 10.3+）
-- 由 install.php 向导执行（选择 MySQL/MariaDB 时自动执行）；也可手动 SOURCE 导入。
-- 字符集固定 utf8mb4，支持 4 字节 emoji；InnoDB 引擎，支持事务与行锁。
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
--                 补 seed：bonus_types.year_end、10 条 msg_templates 内置模板、vacation_rules 默认当年行
-- =========================================================

-- ---------- 组织与账号 ----------
CREATE TABLE IF NOT EXISTS `departments` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `dept_id`    BIGINT NOT NULL,
    `name`       VARCHAR(128) NOT NULL DEFAULT '',
    `parent_id`  BIGINT NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序，小的排前',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_dept_id` (`dept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `roles` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_key`    VARCHAR(32) NOT NULL,
    `role_name`   VARCHAR(64) NOT NULL,
    `description` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userid`            VARCHAR(128) NOT NULL,
    `name`              VARCHAR(64) NOT NULL DEFAULT '',
    `dept_id`           BIGINT NOT NULL DEFAULT 0,
    `role`              VARCHAR(32) NOT NULL DEFAULT 'employee',
    `password`          VARCHAR(255) DEFAULT NULL,
    `has_set_password`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `admin_login`       VARCHAR(64) DEFAULT NULL,
    `admin_pass`        VARCHAR(255) DEFAULT NULL,
    `wecom_userid`      VARCHAR(128) DEFAULT NULL,
    `hire_date`         DATE DEFAULT NULL COMMENT '入职日期（年假计算主数据源，审批同步只回填空值）',
    `vacation_exempt`  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '年假豁免：1=不享受年假（无论工龄），0=按规则计算',
    `vacation_rule_type` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '年假规则类型：0=常规规则, 1=两年增加一天',
    `vacation_special_start` DATE DEFAULT NULL COMMENT '特殊年假规则起点日期（不依赖入职日期）',
    `vacation_special_days` INT NOT NULL DEFAULT 0 COMMENT '特殊年假规则起点基础天数',
    `vacation_special_cap` INT NOT NULL DEFAULT 15 COMMENT '特殊年假规则独立封顶天数',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_userid` (`userid`),
    UNIQUE KEY `uk_admin_login` (`admin_login`),
    UNIQUE KEY `uk_wecom_userid` (`wecom_userid`),
    KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assignments` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `assign_type` VARCHAR(16) NOT NULL DEFAULT 'user',
    `target_id`   VARCHAR(128) NOT NULL DEFAULT '',
    `hr_userid`   VARCHAR(128) NOT NULL DEFAULT '',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_assign_type` (`assign_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 工资 ----------
CREATE TABLE IF NOT EXISTS `salary` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userid`         VARCHAR(128) NOT NULL,
    `year`           INT NOT NULL,
    `month`          TINYINT UNSIGNED NOT NULL,
    `real_pay`       DECIMAL(12,2) NOT NULL DEFAULT 0,
    `status`         VARCHAR(16) NOT NULL DEFAULT 'draft',
    `confirmed`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `name`           VARCHAR(64) NOT NULL DEFAULT '',
    `dept_name`      VARCHAR(64) NOT NULL DEFAULT '',
    `confirmed_at`   DATETIME DEFAULT NULL,
    `pushed_at`      DATETIME DEFAULT NULL,
    `last_remind_at` DATETIME DEFAULT NULL,
    `deleted_at`     DATETIME DEFAULT NULL,
    `deleted_by`     VARCHAR(128) DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_year_month_userid` (`year`, `month`, `userid`),
    KEY `idx_status` (`status`),
    KEY `idx_userid` (`userid`),
    KEY `idx_ym_status` (`year`, `month`, `status`),
    KEY `idx_user_ym` (`userid`, `year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `salary_items` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `salary_id`    BIGINT UNSIGNED NOT NULL,
    `item_name`    VARCHAR(128) NOT NULL,
    `item_value`   VARCHAR(64) NOT NULL DEFAULT '',
    `sort_order`   INT NOT NULL DEFAULT 0,
    `is_deduction` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `category`     VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_salary_id` (`salary_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `salary_columns` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `item_name`  VARCHAR(128) NOT NULL,
    `category`   VARCHAR(64) NOT NULL DEFAULT 'income',
    `sort_order` INT NOT NULL DEFAULT 0,
    `visible`    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_item_name` (`item_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 反馈 ----------
CREATE TABLE IF NOT EXISTS `feedback` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `salary_id`    BIGINT UNSIGNED DEFAULT NULL,
    `bonus_id`     BIGINT UNSIGNED DEFAULT NULL,
    `userid`       VARCHAR(128) NOT NULL,
    `content`      TEXT NOT NULL,
    `status`       VARCHAR(16) NOT NULL DEFAULT 'pending',
    `assignee`     VARCHAR(128) DEFAULT NULL,
    `reply`        TEXT DEFAULT NULL,
    `reply_at`     DATETIME DEFAULT NULL,
    `reply_by`     VARCHAR(128) DEFAULT NULL,
    `salary_year`  INT DEFAULT NULL,
    `salary_month` INT DEFAULT NULL,
    `bonus_year`   INT DEFAULT NULL,
    `bonus_type`   VARCHAR(64) DEFAULT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_userid`   (`userid`),
    KEY `idx_status`   (`status`),
    KEY `idx_assignee` (`assignee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 系统设置 & 消息日志 ----------
CREATE TABLE IF NOT EXISTS `settings` (
    `id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`   VARCHAR(128) NOT NULL,
    `value` TEXT,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_log` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type`       VARCHAR(32) NOT NULL DEFAULT '',
    `touser`     VARCHAR(255) NOT NULL DEFAULT '',
    `sender`     VARCHAR(128) NOT NULL DEFAULT '',
    `msgtype`    VARCHAR(16) NOT NULL DEFAULT '',
    `recipients` TEXT,
    `title`      VARCHAR(255) NOT NULL DEFAULT '',
    `content`    MEDIUMTEXT,
    `status`     TINYINT NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- 索引名与 db_migrate_indexes 保持一致，避免迁移检测到缺名时再建重复索引
    KEY `idx_message_log_type_user` (`type`, `touser`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 标签 & 部门成员标签 ----------
CREATE TABLE IF NOT EXISTS `tags` (
    `id`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tag_id`  BIGINT NOT NULL,
    `tagname` VARCHAR(64) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tag_id` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tag_users` (
    `tag_id` BIGINT NOT NULL,
    `userid` VARCHAR(128) NOT NULL,
    `name`   VARCHAR(64) NOT NULL DEFAULT '',
    PRIMARY KEY (`tag_id`, `userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 消息模板（员工通知、人事/财务结果通知）----------
CREATE TABLE IF NOT EXISTS `msg_templates` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(128) NOT NULL DEFAULT '',
    `tpl_key`    VARCHAR(64) NOT NULL DEFAULT '',
    `msgtype`    VARCHAR(16) NOT NULL DEFAULT 'text',
    `title`      VARCHAR(255) NOT NULL DEFAULT '',
    `content`    TEXT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_msg_templates_tplkey` (`tpl_key`),
    KEY `idx_msg_templates_msgtype` (`msgtype`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 奖金 ----------
CREATE TABLE IF NOT EXISTS `bonus_types` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type_key`   VARCHAR(64) NOT NULL,
    `type_name`  VARCHAR(64) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_type_key` (`type_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bonus` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userid`         VARCHAR(128) NOT NULL,
    `name`           VARCHAR(64) NOT NULL DEFAULT '',
    `dept_name`      VARCHAR(64) NOT NULL DEFAULT '',
    `year`           INT NOT NULL,
    `bonus_type`     VARCHAR(64) NOT NULL,
    `amount`         DECIMAL(12,2) NOT NULL DEFAULT 0,
    `remark`         TEXT,
    `status`         VARCHAR(16) NOT NULL DEFAULT 'draft',
    `pushed_at`      DATETIME DEFAULT NULL,
    `confirmed`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `confirmed_at`   DATETIME DEFAULT NULL,
    `last_remind_at` DATETIME DEFAULT NULL,
    `deleted_at`     DATETIME DEFAULT NULL,
    `deleted_by`     VARCHAR(128) DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_year_type_userid` (`year`, `bonus_type`, `userid`),
    KEY `idx_bonus_user` (`userid`),
    KEY `idx_bonus_year_type` (`year`, `bonus_type`),
    KEY `idx_bonus_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bonus_items` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bonus_id`     BIGINT UNSIGNED NOT NULL,
    `item_name`    VARCHAR(128) NOT NULL,
    `item_value`   VARCHAR(64) NOT NULL DEFAULT '',
    `sort_order`   INT NOT NULL DEFAULT 0,
    `is_deduction` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `category`     VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_bonus_items_bonus` (`bonus_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 审计日志 ----------
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `op_time`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `op_user`   VARCHAR(128) NOT NULL,
    `op_type`   VARCHAR(64) NOT NULL,
    `op_target` VARCHAR(255) NOT NULL DEFAULT '',
    `op_result` VARCHAR(16) NOT NULL DEFAULT 'ok',
    `ip`        VARCHAR(64) NOT NULL DEFAULT '',
    `ua`        VARCHAR(255) NOT NULL DEFAULT '',
    `detail`    TEXT,
    PRIMARY KEY (`id`),
    KEY `idx_audit_time` (`op_time`),
    KEY `idx_audit_user` (`op_user`),
    KEY `idx_audit_type` (`op_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============== 年假模块 ===============

CREATE TABLE IF NOT EXISTS `vacation_rules` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `year`               INT NOT NULL COMMENT '规则归属年度，每年唯一一条',
    `rule_name`          VARCHAR(128) NOT NULL DEFAULT '默认年假规则',
    `min_years`          INT NOT NULL DEFAULT 3 COMMENT '工龄门槛（满几年生效）',
    `base_days`          DECIMAL(6,2) NOT NULL DEFAULT 3.00 COMMENT '首年基准天数',
    `year1_prorate`      TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '首年是否按剩余月份折算：1=是 0=否',
    `increment_per_year` DECIMAL(6,2) NOT NULL DEFAULT 1.00 COMMENT '逐年递增天数',
    `max_days`           DECIMAL(6,2) NOT NULL DEFAULT 10.00 COMMENT '历史兼容：原单档封顶，等价于 cap1_max_days',
    `cap1_max_days`      DECIMAL(6,2) NOT NULL DEFAULT 10.00 COMMENT '档1封顶：未封顶结果>档1且<档2阈值时，固定为此值（通常10天）',
    `cap2_min`           DECIMAL(6,2) NOT NULL DEFAULT 20.00 COMMENT '档2阈值：未封顶结果>=此值时，升级到档2封顶（通常20）',
    `cap2_max_days`      DECIMAL(6,2) NOT NULL DEFAULT 15.00 COMMENT '档2封顶：未封顶结果>=档2阈值时，固定为此值（通常15天）',
    `round_precision`    INT NOT NULL DEFAULT 2 COMMENT '四舍五入小数位（≥3 时 floor 取整，<3 保留小数）',
    `allow_cross_year`   TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否允许跨年休假：1=是 0=否',
    `sort_order`         INT NOT NULL DEFAULT 0,
    `is_active`          TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否启用：1=是 0=否',
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_vac_rules_year` (`year`),
    KEY `idx_vac_rules_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vacation_approvals` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sp_no`          VARCHAR(64) NOT NULL COMMENT '企微审批单号（唯一）',
    `template_id`    VARCHAR(128) NOT NULL DEFAULT '',
    `sp_name`        VARCHAR(128) NOT NULL DEFAULT '',
    `sp_status`      TINYINT NOT NULL DEFAULT 0 COMMENT '1=审批中 2=已通过 3=已驳回 4=已撤回 6=已通过后撤销',
    `apply_userid`   VARCHAR(128) NOT NULL DEFAULT '',
    `apply_name`     VARCHAR(64) NOT NULL DEFAULT '',
    `apply_time`     DATETIME DEFAULT NULL,
    `finish_time`    DATETIME DEFAULT NULL,
    `leave_start`    DATETIME DEFAULT NULL COMMENT '请假开始（含时分，兼容历史 YYYY-MM-DD）',
    `leave_end`      DATETIME DEFAULT NULL COMMENT '请假结束（含时分，兼容历史 YYYY-MM-DD）',
    `leave_days`     DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `year`           INT NOT NULL DEFAULT 0 COMMENT '归属年度（从休假起始日期解析）',
    `hire_date_raw`  DATE DEFAULT NULL COMMENT '审批单控件中解析到的入职日期（仅作展示）',
    `raw_json`       MEDIUMTEXT COMMENT '审批单完整 ApplyData JSON，便于追溯',
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sp_no` (`sp_no`),
    KEY `idx_vac_app_tpl_status` (`template_id`, `sp_status`),
    KEY `idx_vac_app_user` (`apply_userid`),
    KEY `idx_vac_app_status_time` (`sp_status`, `finish_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vacation_ledger` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userid`     VARCHAR(128) NOT NULL COMMENT '员工账号',
    `name`       VARCHAR(64) NOT NULL DEFAULT '',
    `year`       INT NOT NULL COMMENT '归属年度（不允许跨年）',
    `delta`      DECIMAL(6,2) NOT NULL DEFAULT 0.00 COMMENT '变动值：source=1只允许负；source=4只允许正；source=2/3正负通用',
    `source`     TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1=审批通过 2=手动调整 3=系统调整 4=审批撤销',
    `ref_no`     VARCHAR(64) DEFAULT NULL COMMENT '关联编号：审批sp_no / 原手动流水id',
    `remark`     VARCHAR(500) NOT NULL DEFAULT '',
    `op_by`      VARCHAR(128) NOT NULL DEFAULT '' COMMENT '操作人：SYSTEM / 管理员登录名',
    `tm`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '业务发生时间',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vac_ledger_user_year` (`userid`, `year`),
    KEY `idx_vac_ledger_source_tm` (`source`, `tm`),
    KEY `idx_vac_ledger_ref` (`ref_no`),
    KEY `idx_vac_ledger_sp_no` (`sp_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 审批回调日志表（替代文件日志，结构化存储）
CREATE TABLE IF NOT EXISTS `approval_callback_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `received_at` VARCHAR(20) NOT NULL DEFAULT '',
    `method` VARCHAR(8) NOT NULL DEFAULT '',
    `remote_ip` VARCHAR(45) NOT NULL DEFAULT '',
    `sp_no` VARCHAR(64) NOT NULL DEFAULT '',
    `template_id` VARCHAR(128) NOT NULL DEFAULT '',
    `sp_status` TINYINT NOT NULL DEFAULT 0,
    `apply_name` VARCHAR(64) NOT NULL DEFAULT '',
    `signature_ok` TINYINT NOT NULL DEFAULT 0,
    `decrypt_ok` TINYINT NOT NULL DEFAULT 0,
    `sync_status` VARCHAR(20) NOT NULL DEFAULT '',
    `sync_detail` VARCHAR(500) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_acl_created` (`created_at`),
    KEY `idx_acl_sp_no` (`sp_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 审批单基础表（所有审批单统一存储，info 为 gzcompress+base64 压缩 JSON 原文）
CREATE TABLE IF NOT EXISTS `approval_orders` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sp_no`       VARCHAR(64) NOT NULL,
    `sp_name`     VARCHAR(128) NOT NULL DEFAULT '',
    `sp_status`   TINYINT NOT NULL DEFAULT 0,
    `template_id` VARCHAR(128) NOT NULL DEFAULT '',
    `apply_time`  DATETIME DEFAULT NULL,
    `userid`      VARCHAR(64) NOT NULL DEFAULT '',
    `name`        VARCHAR(64) NOT NULL DEFAULT '',
    `info`        MEDIUMTEXT,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ao_sp_no` (`sp_no`),
    KEY `idx_ao_status` (`sp_status`),
    KEY `idx_ao_tpl` (`template_id`),
    KEY `idx_ao_user` (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 内置种子数据（全部幂等：INSERT IGNORE / 唯一键冲突忽略）
-- 顺序与 db.php db_seed_builtin_data 严格对齐，改一处必改另一处
-- =========================================================

-- 1. 默认角色（4 种）
INSERT IGNORE INTO `roles` (`role_key`, `role_name`, `description`) VALUES
    ('admin',    '管理员', '分配角色、全局配置'),
    ('finance',  '财务',   '上传工资条、下发'),
    ('hr',       '人事',   '回复员工反馈'),
    ('employee', '员工',   '查看/确认/反馈');

-- 2. 默认奖金类型（仅内置 年终奖 1 种，其余由管理员自定义）
INSERT IGNORE INTO `bonus_types` (`type_key`, `type_name`, `sort_order`) VALUES
    ('year_end', '年终奖', 0);

-- 3. 内置业务消息模板（10 条，与 db_seed_builtin_data 顺序/字段完全一致）
--    只 INSERT 新 tpl_key；UPDATE 名称/内容 由 install.php 向导在后续步骤再跑一次单独 UPDATE 兜底
INSERT IGNORE INTO `msg_templates` (`tpl_key`, `name`, `msgtype`, `title`, `content`) VALUES
    ('salary_send',              '工资下发通知（员工）',                 'template_card', '{年月}工资条已发布',            "亲爱的 {姓名}，{年月} 月薪资已生成。\n请点击卡片查看明细并确认签收：{链接}"),
    ('push_result',              '工资下发结果通知（管理员/财务）',      'text',          '',                           '【下发结果】{年}年{月}月 工资条已下发 {成功数} 人（共 {总数} 人）。'),
    ('salary_remind',            '工资催办确认通知（员工）',             'template_card', '{年月}工资条待确认',          "亲爱的 {姓名}，您还有 {年}年{月}月 工资条尚未确认。\n请点击卡片查看明细并完成签收。"),
    ('feedback_to_hr_salary',    '工资反馈通知（人事收）',              'template_card', '新的工资反馈待处理',            '员工 {姓名}({账号}) 对 {年月} 工资条提出反馈，请点击查看：{链接}'),
    ('feedback_to_hr_bonus',     '奖金反馈通知（人事收）',              'template_card', '新的奖金反馈待处理',            '员工 {姓名}({账号}) 对 {年}年「{奖金类型}」奖金提出反馈，请点击查看：{链接}'),
    ('feedback_reply_salary',    '工资反馈回复通知（员工收）',          'template_card', '【反馈已回复】{年月}工资反馈',   '您提交的工资条反馈已由人事回复，点击查看详情：{链接}'),
    ('feedback_reply_bonus',     '奖金反馈回复通知（员工收）',          'template_card', '【反馈已回复】{年}年{奖金类型}反馈', '您提交的奖金反馈已由人事回复，点击查看详情：{链接}'),
    ('bonus_push_result',        '奖金下发结果通知（管理员/财务）',      'text',          '',                           '【下发结果】{年}年「{奖金类型}」已下发 {成功数} 人（共 {总数} 人）。'),
    ('bonus_send',               '奖金下发通知（员工）',                 'template_card', '{年}年{奖金类型}已发放',       "亲爱的【{姓名}】同志您好！感谢您的辛勤付出，您{年}年的{奖金类型}已经发放，请点击查看详情。如有问题请及时反馈！"),
    ('bonus_remind',             '奖金催办确认通知（员工）',             'template_card', '{奖金类型}待确认',             "亲爱的 {姓名}，您还有 {年} 年的 {奖金类型} 尚未确认。\n请点击卡片查看明细并完成签收。");

-- 4. 默认年假规则（当年一条，满3年生效+首年折算+逐年递增1+两档封顶）
INSERT IGNORE INTO `vacation_rules`
    (`year`, `rule_name`, `min_years`, `base_days`, `year1_prorate`, `increment_per_year`,
     `max_days`, `cap1_max_days`, `cap2_min`, `cap2_max_days`,
     `round_precision`, `allow_cross_year`, `sort_order`, `is_active`) VALUES
    (YEAR(CURDATE()), '默认年假规则', 3, 3.00, 1, 1.00, 10.00, 10.00, 20.00, 15.00, 2, 0, 0, 1);
