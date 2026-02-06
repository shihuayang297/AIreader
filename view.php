<?php
require('../../config.php');
require_once($CFG->dirroot.'/mod/aireader2/lib.php');

// 接收参数
$id = required_param('id', PARAM_INT); 
$action = optional_param('action', '', PARAM_ALPHA); 

// 1. 获取课程模块信息
if (!$cm = get_coursemodule_from_id('aireader2', $id)) { throw new moodle_exception('invalidcoursemodule'); }
if (!$course = $DB->get_record('course', array('id' => $cm->course))) { throw new moodle_exception('coursemisconf'); }
if (!$aireader = $DB->get_record('aireader2', array('id' => $cm->instance))) { throw new moodle_exception('invalidaireader2id', 'aireader2'); }

// 2. 登录与权限检查
require_login($course, true, $cm);
$context = context_module::instance($cm->id);

// 3. 触发日志
$event = \mod_aireader2\event\course_module_viewed::create(['objectid' => $aireader->id, 'context' => $context]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('aireader2', $aireader);
$event->trigger();

// 4. 设置页面
$PAGE->set_url('/mod/aireader2/view.php', array('id' => $cm->id));
$PAGE->set_title(format_string($aireader->name));
$PAGE->set_heading(format_string($course->fullname));

// 判断角色
$is_teacher = has_capability('moodle/course:manageactivities', $context);

// =================================================================================
// 🎓 界面路由逻辑 (教师端 vs 学生过渡界面 vs 真正的阅读器)
// =================================================================================

// 🔥 核心修正：判断当前是否应该进入阅读器模式（action 为 write 或 read 时）
$is_reader_mode = ($action === 'write' || $action === 'read');

if (!$is_reader_mode) {
    echo $OUTPUT->header();
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">';
    
    echo '
    <style>
        .portal-container { max-width: 1100px; margin: 40px auto; font-family: system-ui, -apple-system, sans-serif; text-align: center; }
        .portal-header { margin-bottom: 50px; }
        .header-icon { font-size: 48px; background: linear-gradient(135deg, #2563eb, #9333ea); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 15px; display: inline-block; }
        .portal-title { font-size: 32px; font-weight: 800; color: #1e293b; margin: 0 0 10px 0; letter-spacing: -0.5px; }
        .portal-sub { color: #64748b; font-size: 16px; max-width: 600px; margin: 0 auto; }
        .card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; padding: 10px; }
        .action-card { background: #fff; border-radius: 24px; padding: 40px 30px; text-decoration: none !important; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid rgba(0,0,0,0.05); box-shadow: 0 10px 30px -10px rgba(0,0,0,0.05); display: flex; flex-direction: column; align-items: center; position: relative; overflow: hidden; height: 100%; }
        .action-card:hover { transform: translateY(-8px); box-shadow: 0 20px 40px -10px rgba(0,0,0,0.12); }
        .icon-circle { width: 80px; height: 80px; border-radius: 22px; display: flex; align-items: center; justify-content: center; font-size: 32px; margin-bottom: 25px; transition: transform 0.3s ease; }
        .action-card:hover .icon-circle { transform: scale(1.1) rotate(5deg); }
        .card-title { font-size: 20px; font-weight: 700; color: #334155; margin-bottom: 12px; }
        .card-content { font-size: 14px; color: #64748b; line-height: 1.6; text-align: left; width: 100%; }
        .agent-list { list-style: none; padding: 0; margin: 0; }
        .agent-list li { margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .agent-list b { color: #1e293b; }
        .btn-start { margin-top: auto; background: #ea580c; color: white !important; padding: 12px 32px; border-radius: 12px; font-weight: 700; transition: all 0.2s; cursor: pointer; border: none; }
        .action-card:hover .btn-start { background: #c2410c; transform: scale(1.05); }

        /* 风格颜色 */
        .style-blue .icon-circle { background: #eff6ff; color: #2563eb; }
        .style-green .icon-circle { background: #f0fdf4; color: #16a34a; }
        .style-orange .icon-circle { background: #fff7ed; color: #ea580c; }
    </style>

    <div class="portal-container">
        <div class="portal-header">
            <div class="header-icon"><i class="fa-solid fa-robot"></i></div>
            <h1 class="portal-title">'.format_string($aireader->name).'</h1>
            <p class="portal-sub">欢迎进入 AI 学术伴读空间。四位不同专长的 AI 伙伴将协助您深度读懂论文。</p>
        </div>

        <div class="card-grid">';

    if ($is_teacher) {
        // 教师端卡片
        echo '
            <a href="'.$CFG->wwwroot.'/mod/aireader2/report.php?id='.$id.'" class="action-card style-blue">
                <div class="icon-circle"><i class="fa-solid fa-list-check"></i></div>
                <div class="card-title">阅读记录</div>
                <div class="card-content" style="text-align:center">查看学生的阅读时长、批注内容及完成情况。</div>
            </a>
            <a href="'.$CFG->wwwroot.'/mod/aireader2/dashboard.php?courseid='.$course->id.'" class="action-card style-green">
                <div class="icon-circle"><i class="fa-solid fa-chart-line"></i></div>
                <div class="card-title">学情看板</div>
                <div class="card-content" style="text-align:center">全景式数据大屏，实时监控班级整体阅读进度。</div>
            </a>
            <a href="'.$PAGE->url->out(false, ['action' => 'write']).'" class="action-card style-orange">
                <div class="icon-circle"><i class="fa-solid fa-book-reader"></i></div>
                <div class="card-title">体验伴读</div>
                <div class="card-content" style="text-align:center">进入学生视景，体验 PDF 阅读与 AI 智能体实时交互。</div>
            </a>';
    } else {
        // 学生端过渡卡片
        echo '
            <div class="action-card style-blue">
                <div class="icon-circle"><i class="fa-solid fa-users-gear"></i></div>
                <div class="card-title">伴读伙伴</div>
                <div class="card-content">
                    <ul class="agent-list">
                        <li><i class="fa-solid fa-compass" style="color:#2563eb"></i> <b>领航者-小师:</b> 规划进度，推送思维挑战</li>
                        <li><i class="fa-solid fa-magnifying-glass" style="color:#4f46e5"></i> <b>百科助手:</b> 术语解析，长难句翻译</li>
                        <li><i class="fa-solid fa-lightbulb" style="color:#ea580c"></i> <b>脑洞工程师:</b> 引导推理，解决理解障碍</li>
                        <li><i class="fa-solid fa-clipboard-check" style="color:#059669"></i> <b>复盘官:</b> 逻辑梳理，巩固学习成效</li>
                    </ul>
                </div>
            </div>
            <div class="action-card style-green">
                <div class="icon-circle"><i class="fa-solid fa-hand-pointer"></i></div>
                <div class="card-title">交互指南</div>
                <div class="card-content">
                    <p><b>划线查询：</b>选中论文中的文本，即可召唤百科助手进行翻译或解释。<br><br>
                    <b>思维挑战：</b>点击领航者抛出的橙色卡片，开始深度思考。脑洞工程师会通过对话引导你找到答案。</p>
                </div>
            </div>
            <a href="'.$PAGE->url->out(false, ['action' => 'read']).'" class="action-card style-orange">
                <div class="icon-circle"><i class="fa-solid fa-feather-pointed"></i></div>
                <div class="card-title">开始研读</div>
                <div class="card-content" style="text-align:center; margin-bottom:20px;">进入沉浸式阅读界面，与 AI 伙伴开启学术探索之旅。</div>
                <div class="btn-start">立即开启</div>
            </a>';
    }

    echo '
        </div>
    </div>
    ';
    echo $OUTPUT->footer();
    exit; 
}

// =================================================================================
// 👇 阅读器加载逻辑 (当 action=write 或 action=read 时运行到这里)
// =================================================================================

// 调试：检查数据库表是否存在
if (!$DB->get_manager()->table_exists('aireader2')) {
    throw new moodle_exception('Plugin aireader2 not installed. Please go to Site administration > Notifications to install it.');
}

echo $OUTPUT->header();

// 1. 获取 PDF 文件 (使用正确的 paper_file 区域)
$pdf_files = [];
$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_aireader2', 'paper_file', 0, 'sortorder DESC, id ASC', false);

foreach ($files as $file) {
    if ($file->is_directory()) continue;
    
    // 宽松的 PDF 类型判断
    $mimetype = $file->get_mimetype();
    $filename = $file->get_filename();
    
    if (strpos($mimetype, 'pdf') !== false || substr(strtolower($filename), -4) === '.pdf') {
        $url = moodle_url::make_pluginfile_url(
            $file->get_contextid(), $file->get_component(), $file->get_filearea(),
            $file->get_itemid(), $file->get_filepath(), $file->get_filename()
        );
        $pdf_files[] = ['filename' => $filename, 'url' => $url->out(false)];
    }
}

// 2. 获取用户数据
$submission = $DB->get_record('aireader2_submissions', [
    'aireader2id' => $aireader->id,
    'userid' => $USER->id
]);

$draft_content = ''; $writing_time = 0; $revision_count = 0; $submission_status = 'draft';
if ($submission) {
    $draft_content = $submission->content;
    $writing_time = $submission->writing_time;
    $revision_count = $submission->revision_count;
    $submission_status = $submission->status;
}

// 3. 用户信息
$user_fullname = fullname($USER);
$user_picture = new user_picture($USER);
$user_picture->size = 1; 
$user_avatar = $user_picture->get_url($PAGE)->out(false);

// 4. 数据输出
$rubric_attr = htmlspecialchars($aireader->rubric_json ?? '[]', ENT_QUOTES, 'UTF-8');
$reading_attr = isset($aireader->resources_json) ? htmlspecialchars($aireader->resources_json, ENT_QUOTES, 'UTF-8') : '';
$draft_attr = htmlspecialchars($draft_content ?? '', ENT_QUOTES, 'UTF-8');
$pdf_list_json = htmlspecialchars(json_encode($pdf_files), ENT_QUOTES, 'UTF-8');

// 🔥 核心增强：向 Vue 传递 isTeacher 标志，确保 Vue 内部渲染逻辑同步
echo '<div id="app" 
        data-is-teacher="'.($is_teacher ? '1' : '0').'"
        data-title="'.s($aireader->name).'" 
        data-intro="'.s($aireader->intro).'" 
        data-reading="'.$reading_attr.'"
        data-rubric="'.$rubric_attr.'"
        data-pdflist="'.$pdf_list_json.'" 
        data-draft="'.$draft_attr.'"
        data-time="'.$writing_time.'"
        data-revisions="'.$revision_count.'"
        data-status="'.$submission_status.'"
        data-username="'.s($user_fullname).'" 
        data-useravatar="'.$user_avatar.'"
      ></div>';

$ver = time(); 
echo '<script type="module" crossorigin src="'.$CFG->wwwroot.'/mod/aireader2/frontend/dist/assets/index.js?v='.$ver.'"></script>';
echo '<link rel="stylesheet" href="'.$CFG->wwwroot.'/mod/aireader2/frontend/dist/assets/main.css?v='.$ver.'">';

echo $OUTPUT->footer();