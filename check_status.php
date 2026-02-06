<?php
require('../../config.php');
require_login();
global $CFG, $DB;

$cmid = optional_param('id', 0, PARAM_INT);
echo "<h1>🔍 AIReader 深度诊断</h1>";

// 1. 检查目录权限
$cacheDir = $CFG->dataroot . '/aireader2_cache';
echo "<p><strong>缓存目录:</strong> $cacheDir</p>";

if (!file_exists($cacheDir)) {
    echo "<p style='color:red'>❌ 目录不存在！尝试创建...</p>";
    if (mkdir($cacheDir, 0777, true)) {
        echo "<p style='color:green'>✅ 创建成功！</p>";
    } else {
        echo "<p style='color:red'>❌ 创建失败！请手动在宝塔赋予 moodledata 777 权限。</p>";
    }
} else {
    echo "<p style='color:green'>✅ 目录已存在。</p>";
    if (is_writable($cacheDir)) {
        echo "<p style='color:green'>✅ 目录可写。</p>";
    } else {
        echo "<p style='color:red'>❌ 目录不可写！请执行 chmod 777。</p>";
    }
}

// 2. 检查 Python 环境
$pyVersion = shell_exec("python3 --version 2>&1");
echo "<p><strong>Python 版本:</strong> $pyVersion</p>";
if (empty($pyVersion) || strpos($pyVersion, 'Python') === false) {
    echo "<p style='color:red'>❌ PHP 无法调用 python3！可能是 shell_exec 被禁用或路径不对。</p>";
} else {
    echo "<p style='color:green'>✅ Python 环境正常。</p>";
}

// 3. 检查具体知识库文件
if ($cmid) {
    $kbFile = $cacheDir . '/kb_' . $cmid . '.json';
    echo "<p><strong>检查任务 ID ($cmid) 的知识库:</strong> $kbFile</p>";
    if (file_exists($kbFile)) {
        $size = filesize($kbFile);
        echo "<p style='color:green'>✅ 知识库文件存在！大小: $size 字节。</p>";
        $data = json_decode(file_get_contents($kbFile), true);
        if ($data && isset($data['pages'])) {
            echo "<p style='color:green'>✅ JSON 解析正常，包含 " . count($data['pages']) . " 页内容。</p>";
        } else {
            echo "<p style='color:red'>❌ JSON 内容为空或格式错误！</p>";
        }
    } else {
        echo "<p style='color:red'>❌ 文件不存在！请回到 Moodle 编辑页面重新保存一次！</p>";
    }
} else {
    echo "<p>⚠️ 请在 URL 后面加上 ?id=你的活动ID 来检查特定任务。</p>";
}