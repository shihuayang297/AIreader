<?php
// 文件路径: /wwwroot/moodle/mod/aireader2/test_debug.php
require('../../config.php');
require_login();
global $CFG;

echo "<h1>AIReader 调试面板</h1>";

// 1. 检查 Python 环境
$output = shell_exec("python3 --version 2>&1");
echo "<strong>Python 版本:</strong> " . $output . "<br>";

// 2. 检查脚本是否存在
$script = $CFG->dirroot . '/mod/aireader2/utils/pdf_full_text.py';
echo "<strong>全文解析脚本路径:</strong> " . $script . "<br>";
if (file_exists($script)) {
    echo "✅ 脚本存在<br>";
} else {
    echo "❌ 脚本不存在！请创建文件！<br>";
}

// 3. 检查 moodledata 写入权限
$cacheDir = $CFG->dataroot . '/aireader2_cache';
echo "<strong>缓存目录:</strong> " . $cacheDir . "<br>";
if (!file_exists($cacheDir)) {
    if (mkdir($cacheDir, 0777, true)) {
        echo "✅ 缓存目录创建成功<br>";
    } else {
        echo "❌ 缓存目录创建失败 (权限不足)<br>";
    }
} else {
    echo "✅ 缓存目录已存在<br>";
}

// 4. 手动测试解析 (填入你服务器上任意一个 PDF 的真实绝对路径)
// 你可以在数据库 mdl_files 表里找一个 contenthash，或者上传一个 test.pdf 到 utils 目录测试
$testPdf = $CFG->dirroot . '/mod/aireader2/utils/test.pdf'; // ⚠️ 请手动上传一个 test.pdf 到 utils 文件夹
if (file_exists($testPdf)) {
    echo "<strong>开始测试解析 test.pdf...</strong><br>";
    $cmd = "python3 " . escapeshellarg($script) . " " . escapeshellarg($testPdf) . " 2>&1";
    $output = shell_exec($cmd);
    echo "<textarea style='width:100%;height:300px;'>$output</textarea>";
} else {
    echo "⚠️ 请在 utils 目录下上传一个 test.pdf 以便测试解析功能。<br>";
}