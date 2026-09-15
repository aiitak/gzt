<?php
/**
 * 管理端入口（备选）：重定向到 /admin/dashboard
 * 正式部署推荐使用 /admin/ 重写规则（见 /admin/info 部署说明）。
 */
header('Location: /admin/dashboard');
exit;
