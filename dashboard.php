<?php
require_once('../../config.php');

// ====================================================
// 1. 配置区域
// ====================================================

// 你的 Metabase 公开链接 (直接复制你刚才生成的)
$PUBLIC_DASHBOARD_URL = 'http://49.232.13.148:3000/public/dashboard/a0a4df3d-c515-41c9-853a-6aa989751419';

// ====================================================

$courseid = required_param('courseid', PARAM_INT);
require_login($courseid);
$context = context_course::instance($courseid);

// 权限检查：使用通用权限，确保管理员和老师都能进
require_capability('moodle/course:manageactivities', $context); 

// 设置页面信息
$PAGE->set_url('/mod/aireader2/dashboard.php', ['courseid' => $courseid]);
$PAGE->set_title('学情看板');
$PAGE->set_heading('AI 写作课程学情数据');
$PAGE->set_pagelayout('incourse'); 

// 2. 构建最终 URL
// 我们尝试把 course_id 拼接到 URL 后面
// 格式：公开链接 + ?course_id=数字 + 样式参数
$iframeUrl = $PUBLIC_DASHBOARD_URL . "?course_id=" . $courseid . "#bordered=false&titled=false&theme=transparent";

echo $OUTPUT->header();

// 顶部标题栏
echo '<div style="margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h3 style="margin-bottom:5px;">📊 课程学情总览</h3>
            <div class="text-muted">实时数据监控中心</div>
        </div>
        <a href="'.$CFG->wwwroot.'/course/view.php?id='.$courseid.'" class="btn btn-secondary">返回课程</a>
      </div>';

// 渲染 Iframe
echo '<div style="width: 100%; height: 1200px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
        <iframe
            src="' . $iframeUrl . '"
            frameborder="0"
            width="100%"
            height="100%"
            allowtransparency
        ></iframe>
      </div>';

echo $OUTPUT->footer();
?>