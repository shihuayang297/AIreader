<?php
require('../../config.php');

$id = required_param('id', PARAM_INT); // CM ID
$sid = required_param('sid', PARAM_INT); // Submission ID

if (!$cm = get_coursemodule_from_id('aireader2', $id)) {
    throw new moodle_exception('invalidcoursemodule');
}
if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
    throw new moodle_exception('coursemisconf');
}
if (!$aireader = $DB->get_record('aireader2', array('id' => $cm->instance))) {
    throw new moodle_exception('invalidaireader2id', 'aireader2');
}

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('moodle/course:manageactivities', $context);

// 获取 submission
$submission = $DB->get_record('aireader2_submissions', ['id' => $sid], '*', MUST_EXIST);
$student = $DB->get_record('user', ['id' => $submission->userid], '*', MUST_EXIST);

$PAGE->set_url('/mod/aireader2/detail.php', array('id' => $cm->id, 'sid' => $sid));
$PAGE->set_title('作业详情: ' . fullname($student));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

// 简单的详情页样式
echo '
<style>
.paper-container {
    background: #f4f6f9;
    padding: 40px;
    border-radius: 8px;
    display: flex;
    justify-content: center;
    margin-top: 20px;
}
.paper {
    background: white;
    width: 210mm; /* A4 宽度 */
    min-height: 297mm; /* A4 高度 */
    padding: 25mm;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    font-family: "Times New Roman", "SimSun", serif; /* 宋体，仿学术论文 */
    line-height: 1.8;
    color: #333;
    font-size: 16px;
}
.meta-info {
    margin-bottom: 20px;
    padding: 20px;
    background: #fff;
    border-left: 5px solid #003366;
    border-radius: 4px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    display: flex;
    gap: 30px;
    align-items: center;
    flex-wrap: wrap;
}
.meta-item {
    display: flex;
    flex-direction: column;
}
.meta-label { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }
.meta-value { font-size: 18px; font-weight: bold; color: #003366; margin-top: 4px; }
.student-badge { display: flex; align-items: center; gap: 10px; }
</style>
';

// 顶部信息栏
$userpic = $OUTPUT->user_picture($student, ['size' => 50]);
$time_display = floor($submission->writing_time/60) . ' 分钟';
$status_text = ($submission->status == 'submitted') ? '<span style="color:green">已提交</span>' : '<span style="color:orange">草稿</span>';

echo '<div class="meta-info">';
echo '<div class="student-badge">' . $userpic . '<div><div class="meta-label">学生姓名</div><div class="meta-value">'.fullname($student).'</div></div></div>';
echo '<div class="meta-item"><span class="meta-label">当前状态</span><span class="meta-value">'.$status_text.'</span></div>';
echo '<div class="meta-item"><span class="meta-label">文章字数</span><span class="meta-value">'.$submission->word_count.' 字</span></div>';
echo '<div class="meta-item"><span class="meta-label">投入时间</span><span class="meta-value">'.$time_display.'</span></div>';
echo '<div class="meta-item"><span class="meta-label">提交/更新时间</span><span class="meta-value">'.userdate($submission->timemodified).'</span></div>';
echo '</div>';

// 试卷内容区
echo '<div class="paper-container">';
echo '<div class="paper">';
if (empty($submission->content)) {
    echo '<p style="color:#999; text-align:center;">该生尚未写入任何内容。</p>';
} else {
    echo $submission->content; // 输出 HTML 内容
}
echo '</div>';
echo '</div>';

// 底部返回
echo '<div style="margin:30px 0; text-align:center;">';
echo $OUTPUT->single_button(new moodle_url('/mod/aireader2/report.php', ['id'=>$cm->id]), '返回列表');
echo '</div>';

echo $OUTPUT->footer();