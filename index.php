<?php
/**
 * 员工端入口（备选）：重定向到 /employee/salary
 * 正式部署推荐使用 /employee/ 重写规则（见 /admin/info 部署说明）。
 */
header('Location: /employee/salary');
exit;
