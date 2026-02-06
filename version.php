<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_aireader2';
// mod/aireader2/version.php
$plugin->version   = 2026012200; // 与 upgrade.php 中 color 字段扩长升级点一致（高亮回显）
$plugin->requires  = 2022041900;      // Requires Moodle 4.0+
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = 'v1.0 (Build 3)';