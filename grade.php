<?php
require_once('../../config.php');
require_once($CFG->libdir . '/gradelib.php');

// 1. 接收参数
$id = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);

// 2. 基础检查
if (!$cm = get_coursemodule_from_id('aireader2', $id)) { throw new moodle_exception('invalidcoursemodule'); }
if (!$course = $DB->get_record('course', array('id' => $cm->course))) { throw new moodle_exception('coursemisconf'); }
if (!$aireader = $DB->get_record('aireader2', array('id' => $cm->instance))) { throw new moodle_exception('invalidaireader2id', 'aireader2'); }

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('moodle/course:manageactivities', $context);

// 3. 处理评分提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $grade = required_param('grade', PARAM_INT);
    $feedback = optional_param('feedback', '', PARAM_RAW);
    
    $submission = $DB->get_record('aireader2_submissions', ['aireader2id'=>$aireader->id, 'userid'=>$userid]);
    if ($submission) {
        $submission->grade = $grade;
        $submission->status = 'graded';
        $submission->feedback = $feedback;
        $DB->update_record('aireader2_submissions', $submission);
    }
    aireader2_update_grades($aireader, $userid);
    redirect(new moodle_url('/mod/aireader2/report.php', ['id'=>$id]), "评分保存成功", null, \core\output\notification::NOTIFY_SUCCESS);
}

// 4. 读取数据
$student = $DB->get_record('user', ['id' => $userid]);
$grader = $USER; // 获取当前批改教师（我）的信息
$submission = $DB->get_record('aireader2_submissions', ['aireader2id'=>$aireader->id, 'userid'=>$userid]);

if (!$submission) {
    $submission = new stdClass();
    $submission->content = "<p style='text-align:center;color:#999;margin-top:100px;'>该学生暂无提交内容</p>";
    $submission->word_count = 0;
    $submission->writing_time = 0;
    $submission->revision_count = 0;
    $submission->grade = 0;
}

$ai_chat_count = $DB->count_records('aireader2_chat_log', ['aireader2id'=>$aireader->id, 'userid'=>$userid]);

// 获取头像 URL (用于 JS 聊天框)
$student_avatar_url = (new user_picture($student))->get_url($PAGE)->out(false);
$grader_avatar_url = (new user_picture($grader))->get_url($PAGE)->out(false); // 老师的头像

// 5. 渲染页面
$PAGE->set_url('/mod/aireader2/grade.php', ['id'=>$id, 'userid'=>$userid]);
$PAGE->set_pagelayout('embedded'); // 使用 embedded 减少干扰，但我们主要靠 CSS 强行覆盖
$PAGE->set_title('教师批改台 - ' . fullname($student));

echo $OUTPUT->header();
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="grade.css?v=<?php echo time(); ?>">

<div id="app-root" class="app-fullscreen" 
     data-sesskey="<?php echo sesskey(); ?>"
     data-grader-avatar="<?php echo $grader_avatar_url; ?>"
     data-ai-avatar="<?php echo $CFG->wwwroot.'/mod/aireader2/pix/ai_avatar.png'; // 建议放个图片，没有则用默认图标 ?>">

    <aside class="sidebar-left">
        <div class="sidebar-bg"></div>

        <div class="brand-header">
            <i class="fa-solid fa-feather-pointed brand-logo"></i>
            <div class="brand-text">
                <div class="b-main">Smart Writer</div>
                <div class="b-sub">教师批改端</div>
            </div>
        </div>

        <div class="student-focus-area">
            <div class="student-icon-box">
                <i class="fa-solid fa-user-graduate"></i>
                <div class="icon-glow"></div>
            </div>
            <div class="focus-info">
                <div class="f-status">批阅中</div>
                <div class="f-name"><?php echo fullname($student); ?></div>
                <div class="f-id"><?php echo $student->username; ?></div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="s-icon c-orange"><i class="fa-regular fa-clock"></i></div>
                <div class="s-data">
                    <span class="val"><?php echo round($submission->writing_time / 60); ?></span>
                    <span class="unit">分钟</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="s-icon c-blue"><i class="fa-solid fa-align-left"></i></div>
                <div class="s-data">
                    <span class="val"><?php echo $submission->word_count; ?></span>
                    <span class="unit">字数</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="s-icon c-purple"><i class="fa-solid fa-robot"></i></div>
                <div class="s-data">
                    <span class="val"><?php echo $ai_chat_count; ?></span>
                    <span class="unit">AI交互</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="s-icon c-rose"><i class="fa-solid fa-code-branch"></i></div>
                <div class="s-data">
                    <span class="val"><?php echo $submission->revision_count; ?></span>
                    <span class="unit">版本</span>
                </div>
            </div>
        </div>

        <div class="sidebar-footer">
            <a href="report.php?id=<?php echo $id; ?>" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i> 返回列表
            </a>
        </div>
    </aside>

    <main class="main-content">
        <div class="paper-wrapper">
            <div class="paper-sheet">
                <?php echo $submission->content; ?>
            </div>
        </div>
    </main>

    <aside class="sidebar-right">
        <div class="panel-header">
            <span>评分面板</span>
            <span class="badge">Grade</span>
        </div>

        <form method="POST" class="grading-form">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            
            <div class="score-display-box">
                <div class="current-score" id="score-text"><?php echo (int)$submission->grade; ?></div>
                <div class="total-score">/ 100</div>
            </div>
            
            <div class="slider-container">
                <input type="range" name="grade" id="grade-slider" min="0" max="100" value="<?php echo (int)$submission->grade; ?>">
            </div>

            <div class="feedback-container">
                <label><i class="fa-solid fa-comment-dots"></i> 教师评语</label>
                <textarea name="feedback" id="feedback-area" placeholder="请输入评语，或唤醒“小师同学”协助生成..."><?php echo strip_tags($submission->feedback ?? ''); ?></textarea>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-check"></i> 提交成绩
            </button>
        </form>
    </aside>

    <div id="ai-ball" class="ai-ball">
        <div class="ball-waves"></div>
        <div class="ball-core">
            <i class="fa-solid fa-sparkles"></i>
        </div>
        <div class="ball-label">小师</div>
    </div>

    <div id="ai-window" class="ai-window hidden">
        <div class="chat-header" id="window-drag-handle">
            <div class="chat-title">
                <span class="dot-status"></span>
                小师同学 (AI 助教)
            </div>
            <div class="window-controls">
                <button id="btn-minimize"><i class="fa-solid fa-minus"></i></button>
            </div>
        </div>

        <div class="chat-body" id="chat-box">
            <div class="chat-time-pill">上午 10:23</div>
            
            <div class="chat-row ai-row">
                <div class="avatar-container">
                    <div class="avatar-icon ai-bg"><i class="fa-solid fa-robot"></i></div>
                </div>
                <div class="bubble-container">
                    <div class="chat-name">小师同学</div>
                    <div class="bubble ai-bubble">
                        老师好！我是小师同学。<br>把我也当成您的学生，让我来帮您检查作业吧！✨
                    </div>
                </div>
            </div>
        </div>

        <div class="chat-footer">
            <div class="quick-chips">
                <div class="chip" data-prompt="请生成一份200字的评语，包含优点和建议。">✨ 生成评语</div>
                <div class="chip" data-prompt="帮我检查这篇论文的逻辑结构。">🔍 查逻辑</div>
            </div>
            <div class="input-bar">
                <input type="text" id="chat-input" placeholder="发消息...">
                <button id="btn-send"><i class="fa-solid fa-paper-plane"></i></button>
            </div>
        </div>

        <div class="resize-handle"></div>
    </div>

</div>

<script src="grade.js?v=<?php echo time(); ?>"></script>

<?php echo $OUTPUT->footer(); ?>
