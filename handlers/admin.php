<?php
/**
 * 管理端接口入口 — 按业务域拆分到 handlers/admin/ 子目录
 */
require_once __DIR__ . '/admin/salary.php';
require_once __DIR__ . '/admin/contacts.php';
require_once __DIR__ . '/admin/roles.php';
require_once __DIR__ . '/admin/messages.php';
require_once __DIR__ . '/admin/settings.php';
require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/system.php';
require_once __DIR__ . '/admin/vacation.php';
