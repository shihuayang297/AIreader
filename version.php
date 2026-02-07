<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_aireader2';
// mod/aireader2/version.php
$plugin->version   = 2026020600; // 与数据库中已安装版本一致，避免升级时报“不能降级”
$plugin->requires  = 2022041900;      // Requires Moodle 4.0+
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = 'v1.0 (Build 3)';