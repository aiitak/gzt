# 工资条系统 · 部署手册（运维版 DEPLOY.md）

> 适用于 **1Panel / OpenResty（Nginx）+ PHP 8.x / Apache + PHP / IIS + PHP** 环境。
> 零 Docker、零 Composer 依赖，单机即可运行。

---

## 一、系统要求

| 项目 | 最低要求 | 推荐 |
|------|---------|------|
| PHP 版本 | 7.4 | **8.1 / 8.2 / 8.3** |
| 必备 PHP 扩展 | `pdo_sqlite` 或 `pdo_mysql` 二选一、`zip`、`openssl`、`json` | + `curl`、`mbstring`、`fileinfo` |
| 内存 | 256 MB | 512 MB+ |
| 磁盘 | 100 MB（程序）+ 每月约 1–5 MB（数据增长） | SSD 推荐 |
| 操作系统 | Linux / Windows Server 均可 | **Linux 64 位** |
| HTTPS | 必须（企微 OAuth 和 Cookie secure 标志依赖） | 自动续期的 Let's Encrypt / 1Panel 免费证书 |

### PHP 配置建议（php.ini / .user.ini）

```ini
date.timezone         = Asia/Shanghai
memory_limit          = 256M
upload_max_filesize   = 20M
post_max_size         = 25M
max_execution_time    = 120   ; 解析大 Excel 时用
max_input_time        = 120
session.cookie_httponly = On
session.cookie_secure   = On  ; HTTPS 环境务必开启
session.cookie_samesite = Lax
expose_php            = Off   ; 隐藏 X-Powered-By
display_errors        = Off   ; 生产环境关闭页面报错
log_errors            = On
error_log             = /var/log/php/php_errors.log
open_basedir          = "项目根目录:/tmp:PHP 临时上传目录:数据目录"  ; 见「目录权限」
```

---

## 二、目录与文件权限

```
项目根目录（如 /www/sites/salary.example.com）
├── api.php
├── index.php
├── admin.php
├── callback.php
├── cron.php
├── install.php              # 安装完后建议 chmod 0400
├── config.php               # 0644
├── config.local.php         # 0600（含密钥！禁止 Web 读取，见 Nginx/Apache 配置）
├── handlers/                # 0755 / 文件 0644
├── templates/               # 0755 / 文件 0644
├── assets/                  # 0755 / 文件 0644
├── admin/
├── employee/
├── sql/
├── uploads/                 # 0750 或 0755，PHP-FPM 用户可写（存放 logo / 消息图片）
└── （不在 Web 根内）/www/private/salary_data/   # 0750，PHP-FPM 用户可写（SQLite 数据库目录）
```

**关键目录**：
- **数据目录**：SQLite 数据库文件 **强烈建议放在站点根目录之外**，彻底杜绝被 Web 直接下载。安装向导 Step 2 的「数据目录」填绝对路径，例如 `/www/private/salary.example.com`。
- **uploads/**：可位于 Web 根内（后台 logo / 消息图片），但须配置 Web 服务器禁止脚本执行（见下节）。

---

## 三、Web 服务器配置（必做！）

代码层只防范了应用层攻击，**部署层必须用 Web 服务器配置再加一道屏障**。按你的环境三选一：

### 3.1 Nginx / OpenResty（1Panel 默认）

把以下内容放入 1Panel 站点「配置文件 / 额外配置」或站点 `.conf` 的 `server {}` 块内：

```nginx
# ---------- 安全响应头 ----------
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;

# HTTPS 强制（证书配置好后启用）
# add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

# ---------- 禁止访问敏感文件 ----------
# PHP 源码 include 文件
location ~* ^/(config|config\.local|db|auth|wecom|excel|settings|functions|version)\.php$ {
    return 403;
}
# 私有目录
location ~* ^/(\.git|\.env|\.svn|\.htaccess|\.htpasswd|composer\.json|composer\.lock) {
    return 403;
}
# SQL 源文件 / 临时文件
location ~* ^/(sql)/.*\.(sql)$ { return 403; }
location ~* ~$ { return 403; }

# ---------- install.php 限制（仅安装期可访问） ----------
# 安装完成后把下面两行注释取消，阻断 install.php 入口
# location = /install.php {
#     allow 你的办公网公网IP;
#     deny all;
#     try_files $uri =404;
# }

# ---------- cron.php 阻断 ----------
# 正常走 1Panel 计划任务的 curl 可穿透；下面这行会阻止浏览器/公网直接调用
# （若你在公网 curl 本系统触发 cron，不要加此规则，改为只允许内网 IP）
# location = /cron.php {
#     allow 127.0.0.1;
#     allow ::1;
#     deny all;
# }

# ---------- SQLite 数据兜底防护 ----------
# 如果数据目录不幸落在 Web 根内（salary_data/），启用下面一行，否则可省
# location ^~ /salary_data/ { return 403; }

# ---------- uploads 禁止脚本执行 ----------
# 防止攻击者上传伪装成 logo 的 .php 伪装文件执行
location ^~ /uploads/ {
    location ~* \.(php|php5|phtml|phar)$ { return 403; }
    try_files $uri =404;
}

# ---------- 伪静态 / 重写规则（让 /api/ /employee/ /admin/ 正常工作） ----------
location /api/ {
    rewrite ^/api/(.*)$ /api.php?path=$1 last;
}
location = /employee/ {
    rewrite ^ /employee/router.php?page=salary last;
}
location /employee/ {
    rewrite ^/employee/(.+)$ /employee/router.php?page=$1&$args? last;
}
location = /admin/ {
    rewrite ^ /admin/router.php?page=dashboard last;
}
location /admin/ {
    rewrite ^/admin/(.+)$ /admin/router.php?page=$1&$args? last;
}

# ---------- 管理后台 IP 白名单（可选，但强烈推荐） ----------
# 把 203.0.113.0/24 换成你们公司办公网出口 IP 段
# location ^~ /admin/ {
#     allow 203.0.113.0/24;
#     deny all;
#     rewrite ^/admin/(.+)$ /admin/router.php?page=$1&$args? last;
# }
# location ~* ^/api/admin/ {
#     allow 203.0.113.0/24;
#     deny all;
#     rewrite ^/api/(.*)$ /api.php?path=$1 last;
# }
```

### 3.2 Apache（.htaccess，放项目根目录）

```apache
# ---------- 安全响应头 ----------
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    # Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
</IfModule>

# ---------- 禁止访问敏感文件 ----------
<FilesMatch "^(config|config\.local|db|auth|wecom|excel|settings|functions|version)\.php$">
    Require all denied
</FilesMatch>
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
<FilesMatch "~$">
    Require all denied
</FilesMatch>

# ---------- install.php 限制（安装完成后取消下一行注释） ----------
# <Files "install.php">
#     Require ip 203.0.113.0/24
# </Files>

# ---------- 禁止列出目录 ----------
Options -Indexes -MultiViews

# ---------- uploads 禁止脚本执行 ----------
<Directory "uploads">
    <FilesMatch "\.(php|php5|phtml|phar)$">
        Require all denied
    </FilesMatch>
</Directory>

# ---------- 伪静态 ----------
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    RewriteRule ^api/(.*)$               api.php?path=$1                   [QSA,L]
    RewriteRule ^employee/$              employee/router.php?page=salary    [QSA,L]
    RewriteRule ^employee/(.+)$          employee/router.php?page=$1        [QSA,L]
    RewriteRule ^admin/$                 admin/router.php?page=dashboard    [QSA,L]
    RewriteRule ^admin/(.+)$             admin/router.php?page=$1           [QSA,L]
</IfModule>
```

并在**数据目录**（安装完后检查 install.php 的输出，数据目录会自动生成以下两个文件）再放一份 `.htaccess`：

```apache
Require all denied
```

### 3.3 IIS（web.config，放项目根目录）

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <httpProtocol>
      <customHeaders>
        <add name="X-Content-Type-Options" value="nosniff" />
        <add name="X-Frame-Options" value="SAMEORIGIN" />
        <add name="X-XSS-Protection" value="1; mode=block" />
        <add name="Referrer-Policy" value="strict-origin-when-cross-origin" />
      </customHeaders>
    </httpProtocol>

    <security>
      <requestFiltering>
        <hiddenSegments>
          <add segment=".git" />
          <add segment=".env" />
          <add segment="sql" />
          <add segment="salary_data" />
        </hiddenSegments>
        <fileExtensions applyToWebDAV="false">
          <add fileExtension=".sql" allowed="false" />
          <add fileExtension=".db" allowed="false" />
          <add fileExtension="~" allowed="false" />
        </fileExtensions>
      </requestFiltering>
    </security>

    <rewrite>
      <rules>
        <rule name="API" stopProcessing="true">
          <match url="^api/(.*)$" />
          <action type="Rewrite" url="api.php?path={R:1}" appendQueryString="true" />
        </rule>
        <rule name="EmployeeRoot" stopProcessing="true">
          <match url="^employee/$" />
          <action type="Rewrite" url="employee/router.php?page=salary" appendQueryString="true" />
        </rule>
        <rule name="Employee" stopProcessing="true">
          <match url="^employee/(.+)$" />
          <action type="Rewrite" url="employee/router.php?page={R:1}" appendQueryString="true" />
        </rule>
        <rule name="AdminRoot" stopProcessing="true">
          <match url="^admin/$" />
          <action type="Rewrite" url="admin/router.php?page=dashboard" appendQueryString="true" />
        </rule>
        <rule name="Admin" stopProcessing="true">
          <match url="^admin/(.+)$" />
          <action type="Rewrite" url="admin/router.php?page={R:1}" appendQueryString="true" />
        </rule>
      </rules>
    </rewrite>

    <handlers>
      <!-- uploads/ 禁止脚本执行 -->
    </handlers>
  </system.webServer>

  <!-- 阻止直接访问 config.php / config.local.php 等 include 文件 -->
  <location path="config.php">
    <system.webServer><authorization><deny users="*" /></authorization></system.webServer>
  </location>
  <location path="config.local.php">
    <system.webServer><authorization><deny users="*" /></authorization></system.webServer>
  </location>
  <location path="db.php">
    <system.webServer><authorization><deny users="*" /></authorization></system.webServer>
  </location>
  <location path="auth.php">
    <system.webServer><authorization><deny users="*" /></authorization></system.webServer>
  </location>
  <location path="wecom.php">
    <system.webServer><authorization><deny users="*" /></authorization></system.webServer>
  </location>
  <location path="excel.php">
    <system.webServer><authorization><deny users="*" /></authorization></system.webServer>
  </location>
  <location path="settings.php">
    <system.webServer><authorization><deny users="*" /></authorization></system.webServer>
  </location>
  <location path="functions.php">
    <system.webServer><authorization><deny users="*" /></authorization></system.webServer>
  </location>
</configuration>
```

数据目录（若落在 Web 根内）再放一份 `web.config`：

```xml
<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>
```

---

## 四、初始化安装

```bash
# 浏览器访问
https://你的域名/install.php
```

三步式向导：
1. **环境检测**：PHP 版本 + 扩展 + 目录写入权限
2. **数据库选择**：SQLite（推荐零依赖小团队）或 MySQL/MariaDB
3. **管理员账号 + 密钥**：随机生成 JWT_SECRET / CRON_KEY，写入 `config.local.php`

> ⚠️ 安装完成后，**把 `install.php` 改为只读（chmod 0400）**，并在 Nginx/Apache 中启用 install.php 的 IP 白名单或直接阻断。

---

## 五、定时任务（Cron）

`CRON_KEY` 见安装时生成的 `config.local.php`。

自 v3.6 起，所有定时任务统一通过 **`task=all` 单入口** 调度（内部按时间窗口分发催办/推送/年假同步/日志清理），不再需要配置多条计划任务。

### 5.1 方式 A：1Panel 计划任务（推荐）

进入 1Panel → 计划任务 → 新建 **1 条 Shell 脚本任务**（每分钟执行即可）：

```bash
# 统一入口：每分钟执行，内部按时间窗口自动分发
#   · 00-59 分：push（每月指定日期/时间后自动下发工资条，内部按 last_auto_push 去重）
#   · 每小时：remind（催办未确认，≥55 分钟栅栏 + 工作时间 + 单条 1 小时去重）
#   · 每天 02:00-05:00：年假审批增量同步（回看过去 N 天，≥23 小时栅栏）
#   · 每天 03:00-06:00：日志清理（audit_log/回调日志/slog 文件/feedback，≥23 小时栅栏）
curl -fsS "https://gzx.jxf.ink/cron.php?task=all&key=替换CRON_KEY" >> /var/log/cron-all.log 2>&1
```

### 5.2 方式 B：系统 crontab（Linux）

```crontab
# 统一入口：每分钟（内部按时间窗口分发 4 个子任务）
* * * * * curl -fsS "https://salary.example.com/cron.php?task=all&key=替换CRON_KEY" >> /var/log/cron-all.log 2>&1
```

### 5.3 并发与兼容说明

- **全局锁**：`task=all` 每次执行前会获取 `settings.timer_global_lock`（TTL=10 分钟），防止多实例/重叠执行导致重复推送或重复扣减年假；异常崩溃时锁会在 10 分钟后自动过期。
- **旧 crontab 兼容兜底**：若服务器上仍保留了 `task=remind` / `task=push` 两条旧计划任务，代码层会通过 `timer_last_run_legacy_*` 记录做 ≥55 分钟 / 23 小时互斥，避免同一窗口内重复催办或重复推送。**建议最终只保留 `task=all` 一条，减少维护成本。**
- **各子任务独立栅栏**：
  - `last_run_push`：每次 push 尝试（无论是否实际执行）都会写入；实际推送仍受 `last_auto_push == yyyymm` 去重兜底。
  - `last_run_remind`：实际完成一次催办（salary+bonus）后写入，≥55 分钟才允许下一次（避免旧配置"每小时"与"每分钟调度"叠加导致重复）。
  - `last_run_vacation_sync`：≥23 小时 + 02:00-05:00 时间窗口。
  - `last_run_log_cleanup`：≥23 小时 + 03:00-06:00 时间窗口。

### 5.4 方式 C：CLI 本地调用（最安全，彻底绕开 Web）

```bash
php /www/sites/salary.example.com/cron.php all     替换CRON_KEY   # 推荐：统一入口
php /www/sites/salary.example.com/cron.php remind  替换CRON_KEY   # 调试：独立催办
php /www/sites/salary.example.com/cron.php push    替换CRON_KEY   # 调试：独立推送
```

---

## 六、企业微信后台配置

### 6.1 自建应用

| 项 | 填写内容 |
|---|---------|
| 可信域名 / 网页授权 | `salary.example.com` |
| 应用主页 | `https://salary.example.com/index.php` |
| 消息回调 URL | `https://salary.example.com/callback.php` |
| Token / EncodingAESKey | 与后台「系统设置 → 企业微信」保持一致 |

### 6.2 通讯录事件服务器（推荐开启）

| 项 | 填写内容 |
|---|---------|
| 回调 URL | `https://salary.example.com/callback.php?from=contact`（**末尾 `?from=contact` 不可省**） |
| Token / EncodingAESKey | 与后台「系统设置 → 通讯录同步 → 📡 通讯录事件服务器」保持一致 |
| 可信 IP 白名单 | 把本服务器公网 IP 加入企微后台可信 IP 段 |

---

## 七、备份策略

### 7.1 SQLite（单文件）备份

```bash
# 每日 03:00 冷备份（复制 + gzip + 保留 30 天）
0 3 * * * /bin/bash -c 'set -e; \
  DATE=$(date +%Y%m%d-%H%M%S); \
  BACKUP_DIR="/www/backup/salary"; \
  mkdir -p "$BACKUP_DIR"; \
  cp -a /www/private/salary.example.com/app.sqlite3 "$BACKUP_DIR/app.sqlite3.$DATE"; \
  gzip -9 "$BACKUP_DIR/app.sqlite3.$DATE"; \
  find "$BACKUP_DIR" -name "app.sqlite3.*.gz" -mtime +30 -delete; \
  cp -a /www/sites/salary.example.com/config.local.php "$BACKUP_DIR/config.local.php.$DATE.gz"'
```

> **建议**：把备份目录同步到异地（对象存储 / 另一台服务器），避免单机故障。

### 7.2 MySQL 备份

```bash
0 3 * * * /usr/bin/mysqldump --single-transaction --routines \
  -u salary -p'数据库密码' salary | gzip -9 > /www/backup/salary/db-$(date +\%Y\%m\%d).sql.gz
```

### 7.3 额外备份文件清单

每次改动后记得备份：
- `config.local.php`（含 JWT 密钥，丢失后全部会话失效）
- `uploads/` 目录（logo / 消息图片）
- 企微后台「自建应用」和「通讯录事件服务器」的 Token / EncodingAESKey 截图

---

## 八、升级流程（代码有新版本时）

1. **备份**：按第七章做一次冷备份（数据库 + config.local.php）
2. **替换代码**：除 `config.local.php`、`uploads/`、`salary_data/`（或数据库）外，全部用新版本覆盖
3. **清理模板缓存**：如果启用了 OPcache，重启 PHP-FPM：
   ```bash
   service php-fpm-82 restart   # 或 1Panel 对应 PHP 版本
   ```
4. **触发自动迁移**：登录一次管理后台 `/admin/dashboard`（任意 admin 账号即可）。
   - **v2026082401 自动迁移清单**（db_migrate_columns 毫秒级完成，不需要手工 SQL）：
     - `users` 表 ADD 4 列：`vacation_rule_type TINYINT DEFAULT 0`、`vacation_special_start DATE`、`vacation_special_days INT`、`vacation_special_cap INT DEFAULT 15`
     - `salary` 表 ADD 2 列：`status TINYINT DEFAULT 0`、`confirmed TINYINT DEFAULT 0`（修复催办任务 `Unknown column 'status'`）
     - `bonus` 表 ADD 2 列：`status TINYINT DEFAULT 0`、`confirmed TINYINT DEFAULT 0`
   - 迁移是否成功验证：打开 `/admin/vacation_overview` 不报错、打开 `/admin/dashboard` 数据概览员工总数 > 0 = 迁移完成
5. **访问 /admin/info**：确认部署说明第十二章「重要修复记录」含最新 2026-08-24 / 2026-08-25 两节；`/admin/bb` 版本记录 APP_VERSION 与 config.php 一致
6. **冒烟测试**：
   - 登录员工端 `/employee/salary`
   - 登录管理端 `/admin/dashboard`
   - 调一次 `/api/auth/me` 看返回结构
   - 手动跑一次 `cron.php?task=all&key=CRON_KEY`（不会实际发消息除非窗口匹配）

---

## 九、部署自检清单 ✅

上线前**逐条打勾**：

| 分类 | 检查项 | OK |
|------|--------|----|
| 🔐 HTTPS | 证书有效，企微 OAuth Callback 全部 https:// | ☐ |
| 🔐 密钥 | `config.local.php` JWT_SECRET 非占位符且 ≥ 32 字节；CRON_KEY 非空；文件权限 0600 | ☐ |
| 🔐 目录 | `salary_data/` 在站点根目录**之外**（或 Nginx/Apache 已配置 403） | ☐ |
| 🔐 敏感文件 | Nginx/Apache 规则阻断 `config.php / config.local.php / .git / sql/*.sql` 直接访问 | ☐ |
| 🔐 install.php | 安装完成后 `chmod 0400` 或 Web 层阻断 | ☐ |
| 🔐 cron.php | Web 层只允许本机/内网 IP，或 CLI 方式触发不走 Web | ☐ |
| 🔐 uploads | `uploads/` 禁止 .php / .phtml 脚本执行 | ☐ |
| 🔐 PHP 配置 | `display_errors=Off`、`expose_php=Off`、`allow_url_fopen` 视情况关闭 | ☐ |
| 📂 写入权限 | `uploads/`、数据目录对 PHP-FPM 用户可写；其他 PHP 文件为只读 | ☐ |
| 📅 时区 | `date.timezone=Asia/Shanghai`（php.ini 或 config.php 第 12 行兜底） | ☐ |
| ⏰ 定时任务 | 只保留 1 条 `cron.php?task=all&key=CRON_KEY`（每分钟执行，内部按时间窗口分发：催办/推送/年假同步/日志清理）；旧的 remind/push 两条可删除或保留做兼容兜底（代码层 55min/23h 栅栏防重复） | ☐ |
| 📊 数据一致性 | 数据概览 / 通讯录 / 年假总览 三处「员工总数」完全相等；已离职部门、`HIST_%` 前缀历史账号、本地 admin 账号均不计入；验证 SQL 口径：`wecom_userid IS NOT NULL AND userid NOT LIKE 'HIST_%' AND COALESCE(departments.name,'') NOT IN ('已离职','未匹配')` | ☐ |
| 🏥 催办列迁移 | `cron.php?task=all&key=CRON_KEY` 执行后无 `Unknown column 'salary.status' / 'bonus.confirmed'` 报错；若仍报错说明自动迁移未触发 → 手动登录一次 admin 重新触发后再试 | ☐ |
| 💾 备份 | SQLite 文件/数据库每日自动备份 + 异地副本 + 最近一次手动恢复验证成功 | ☐ |
| 🏢 企微 | 可信域名/回调 URL/Token-AESKey 两处均配置；通讯录事件服务器 `?from=contact` 后缀正确 | ☐ |
| 🧪 冒烟 | 员工端登录/工资列表/详情密码验证、管理端登录/上传小文件/推送测试号 均正常 | ☐ |

---

## 十、常见故障排查

### 10.1 员工在企微里打开应用 → 404 / 500
- 检查 Nginx 重写规则是否生效（看 3.1 伪静态部分）
- 查看 PHP 错误日志：`/var/log/php/php_errors.log` 或 Nginx `error.log`
- `config.local.php` 的权限是否为 0600，路径是否正确

### 10.2 消息推送失败（员工收不到）
- 登录后台「系统设置 → 企业微信」，点「测试推送」
- 检查「消息日志」页是否返回 `invalid access_token` → 重新填 Agent Secret
- 若企微后台设置了「可信 IP」，把本服务器出口 IP 加进去

### 10.3 催办定时任务不触发
- 在 1Panel「计划任务 - 执行日志」看 curl 返回
- 直接命令行执行 curl 看结果：返回 `{success: true}` 才算成功
- CRON_KEY 与 `config.local.php` 是否一字不差（注意 URL 编码 `&`）

### 10.4 上传 Excel 提示"文件过大"或"上传失败"
- `php.ini`：`upload_max_filesize`、`post_max_size` 都调到 ≥ 20M
- `max_execution_time` ≥ 120s（大 Excel 解析耗时）
- 1Panel 站点「PHP 版本 → 设置」也有对应的配置面板，改完要重载

### 10.5 SQLite 文件被下载（安全事故应急）
- 立即在 Nginx 加 `location ^~ /salary_data/ { return 403; }` 并 `nginx -s reload`
- 把数据目录**迁移到站点根目录之外**，修改 `config.local.php` 中 `DB_FILE` 常量
- 检查 access.log 是否有外部 IP 成功下载 `.db`，若有：通知全体员工修改工资条查看密码、JWT 密钥重新生成（全员会话失效）、企微应用 Secret 重新生成

---

> 📎 补充阅读：项目内管理后台 `/admin/info` 有面向业务用户的版本（含目录导航、浮动目录、修复记录表，受 admin 权限控制）。本文档面向运维，重点在 Web 服务器配置和安全加固，两份可互相补充。
