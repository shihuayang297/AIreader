<?php
require_once('../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/csvlib.class.php'); 

// 1. 接收参数
$id = required_param('id', PARAM_INT); 
$action = optional_param('action', '', PARAM_ALPHA); 
$userid = optional_param('userid', 0, PARAM_INT); 
$search = optional_param('search', '', PARAM_RAW); 
$filter_status = optional_param('status', 'all', PARAM_ALPHA); 

// 2. 获取基础信息
if (!$cm = get_coursemodule_from_id('aireader2', $id)) { throw new moodle_exception('invalidcoursemodule'); }
if (!$course = $DB->get_record('course', array('id' => $cm->course))) { throw new moodle_exception('coursemisconf'); }
if (!$aireader = $DB->get_record('aireader2', array('id' => $cm->instance))) { throw new moodle_exception('invalidaireader2id', 'aireader2'); }

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('moodle/course:manageactivities', $context);

// =================================================================================
// 📥 下载 Word 逻辑
// =================================================================================
if ($action === 'download' && $userid > 0) {
    $sub = $DB->get_record('aireader2_submissions', ['aireader2id' => $aireader->id, 'userid' => $userid]);
    $student = $DB->get_record('user', ['id' => $userid]);
    if ($sub && $student) {
        $filename = clean_filename($course->shortname . '_' . fullname($student) . '.doc');
        header("Content-type: application/vnd.ms-word");
        header("Content-Disposition: attachment;Filename=$filename");
        echo "<html><meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\"><body>";
        echo "<h1>" . format_string($aireader->name) . "</h1>";
        echo "<h3>学生: " . fullname($student) . "</h3>";
        echo "<p>状态: " . ($sub->status=='graded' ? '已批改 ('.$sub->grade.'分)' : '未批改') . "</p>";
        echo "<hr>";
        echo $sub->content; 
        echo "</body></html>";
        exit;
    }
}

// =================================================================================
// 🔍 数据查询
// =================================================================================

// A. 用户查询
$course_context = context_course::instance($course->id);
$params = ['contextid' => $course_context->id];
$where_sql = "ctx.id = :contextid";

if (!empty($search)) {
    $fullname_sql = $DB->sql_concat('u.lastname', 'u.firstname'); 
    $where_sql .= " AND ($fullname_sql LIKE :s1 OR u.firstname LIKE :s2 OR u.lastname LIKE :s3 OR u.username LIKE :s4)";
    $params['s1'] = "%$search%"; $params['s2'] = "%$search%"; $params['s3'] = "%$search%"; $params['s4'] = "%$search%";
}

$sql_users = "SELECT u.* FROM {user} u JOIN {role_assignments} ra ON ra.userid = u.id JOIN {context} ctx ON ctx.id = ra.contextid WHERE $where_sql ORDER BY u.lastname, u.firstname";
$users = $DB->get_records_sql($sql_users, $params);

// B. 提交记录 (包括 grade)
$submissions = $DB->get_records('aireader2_submissions', ['aireader2id' => $aireader->id], '', 'userid, id, content, word_count, writing_time, revision_count, status, timemodified, grade');

// C. AI 统计
$sql_chat = "SELECT userid, COUNT(*) as count FROM {aireader2_chat_log} WHERE aireader2id = ? GROUP BY userid";
$chat_counts = $DB->get_records_sql_menu($sql_chat, [$aireader->id]); 

// D. 数据整合
$stats = ['total' => 0, 'submitted' => 0, 'graded' => 0, 'total_time' => 0, 'total_ai' => 0];
$rows_to_display = [];

foreach ($users as $u) {
    if (has_capability('moodle/course:manageactivities', $context, $u)) continue; 

    $s = isset($submissions[$u->id]) ? $submissions[$u->id] : null;
    $ai_num = isset($chat_counts[$u->id]) ? $chat_counts[$u->id] : 0;

    // 状态判定逻辑 (核心修改)
    $current_status = 'missing';
    if ($s) {
        if ($s->status === 'graded') $current_status = 'graded';
        elseif ($s->status === 'submitted') $current_status = 'submitted';
        else $current_status = 'draft';
    }

    if ($filter_status !== 'all' && $filter_status !== $current_status) continue;

    $stats['total']++;
    if ($current_status === 'submitted' || $current_status === 'graded') {
        $stats['submitted']++;
        $stats['total_time'] += $s->writing_time;
        $stats['total_ai'] += $ai_num;
    }
    if ($current_status === 'graded') {
        $stats['graded']++;
    }

    $row = new stdClass();
    $row->user = $u;
    $row->submission = $s;
    $row->ai_count = $ai_num;
    $row->status_code = $current_status;
    $rows_to_display[] = $row;
}

$avg_time = $stats['submitted'] > 0 ? round($stats['total_time'] / $stats['submitted'] / 60) : 0;
$avg_ai = $stats['submitted'] > 0 ? round($stats['total_ai'] / $stats['submitted']) : 0;

// =================================================================================
// 📤 导出 Excel
// =================================================================================
if ($action === 'export') {
    $filename = clean_filename($course->shortname . '_成绩报表_' . date('Ymd'));
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename='.$filename.'.csv');
    echo "\xEF\xBB\xBF"; 
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['姓名', '学号', '状态', '成绩', '字数', '时长(分)', 'AI轮次', '更新时间']);
    foreach ($rows_to_display as $r) {
        $st_txt = match($r->status_code) { 'graded'=>'已批改', 'submitted'=>'已提交', 'draft'=>'草稿', default=>'未交' };
        $grade = ($r->status_code === 'graded' && $r->submission) ? $r->submission->grade : '-';
        fputcsv($fp, [
            fullname($r->user), $r->user->username, $st_txt, $grade,
            $r->submission->word_count??0, round(($r->submission->writing_time??0)/60), $r->ai_count,
            $r->submission ? userdate($r->submission->timemodified, '%Y-%m-%d %H:%M') : '-'
        ]);
    }
    fclose($fp); exit;
}

// =================================================================================
// 🎨 页面渲染
// =================================================================================
$PAGE->set_url('/mod/aireader2/report.php', ['id' => $id]);
$PAGE->set_title('作业管理');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

echo '
<style>
    .report-container { background: #f5f7fa; padding: 25px; font-family: -apple-system, sans-serif; }
    
    /* 顶部栏 */
    .toolbar { background: #fff; padding: 15px 20px; border-radius: 12px; border: 1px solid #e0e0e0; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
    .form-control-custom { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; }
    
    /* 统计卡片 */
    .stat-row { display: flex; gap: 15px; margin-bottom: 25px; }
    .stat-box { flex: 1; background: #fff; padding: 15px 20px; border-radius: 10px; border: 1px solid #e0e0e0; display: flex; align-items: center; gap: 15px; }
    .stat-icon { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    
    /* 颜色定义 */
    .c-blue { background: #e3f2fd; color: #1976d2; }
    .c-green { background: #e8f5e9; color: #2e7d32; }
    .c-orange { background: #fff3e0; color: #ef6c00; }
    .c-purple { background: #f3e5f5; color: #9333ea; }
    
    /* 表格 */
    .student-table { width: 100%; border-collapse: separate; border-spacing: 0; background: #fff; border-radius: 10px; overflow: hidden; border: 1px solid #e0e0e0; }
    .student-table th { background: #f8f9fa; padding: 15px; text-align: left; font-size: 13px; color: #666; font-weight: 600; border-bottom: 1px solid #eee; }
    .student-table td { padding: 15px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
    .student-table tr:hover { background: #fcfcfc; }

    /* 状态徽章 */
    .badge { padding: 5px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; }
    .b-graded { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; } /* 蓝色-已批改 */
    .b-sub { background: #e8f5e9; color: #166534; border: 1px solid #bbf7d0; } /* 绿色-已提交 */
    .b-draft { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; } /* 黄色-草稿 */
    .b-miss { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; } /* 红色-未交 */

    .score-txt { font-weight: 800; color: #2563eb; font-size: 16px; }
    .score-none { color: #ccc; font-size: 14px; }

    .btn-grade { background: #2563eb; color: #fff; padding: 6px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; display: inline-block; transition:0.2s;}
    .btn-grade:hover { background: #1d4ed8; color:#fff; text-decoration:none; }
    .btn-dl { padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px; color: #555; background: #fff; margin-right:5px;}
    .btn-dl:hover { background: #f5f5f5; }
</style>
';

echo '<div class="report-container">';

// 1. 顶部栏
echo '<div class="toolbar">
        <h2 style="margin:0; font-size:20px; color:#333; font-weight:700;">' . format_string($aireader->name) . '</h2>
        <form method="GET" style="display:flex; gap:10px;">
            <input type="hidden" name="id" value="'.$id.'">
            <select name="status" class="form-control-custom" onchange="this.form.submit()">
                <option value="all" '.($filter_status=='all'?'selected':'').'>全部状态</option>
                <option value="graded" '.($filter_status=='graded'?'selected':'').'>✅ 已批改</option>
                <option value="submitted" '.($filter_status=='submitted'?'selected':'').'>📩 待批改 (已提交)</option>
                <option value="draft" '.($filter_status=='draft'?'selected':'').'>📝 写作中</option>
                <option value="missing" '.($filter_status=='missing'?'selected':'').'>❌ 未提交</option>
            </select>
            <input type="text" name="search" class="form-control-custom" placeholder="姓名/学号" value="'.s($search).'">
            <button type="submit" class="btn-grade" style="border:none; cursor:pointer;">搜索</button>
            <a href="?id='.$id.'&action=export" class="btn-dl" style="text-decoration:none; display:flex; align-items:center;"><i class="fa fa-file-excel-o"></i> 导出</a>
            <a href="view.php?id='.$id.'" class="btn-dl" style="text-decoration:none;">返回</a>
        </form>
      </div>';

// 2. 统计数据
echo '<div class="stat-row">
        <div class="stat-box"><div class="stat-icon c-purple"><i class="fa fa-check-circle"></i></div><div><div style="font-weight:bold; font-size:18px;">'.$stats['graded'].' / '.$stats['submitted'].'</div><div style="font-size:12px; color:#888;">已批 / 已交</div></div></div>
        <div class="stat-box"><div class="stat-icon c-blue"><i class="fa fa-users"></i></div><div><div style="font-weight:bold; font-size:18px;">'.$stats['total'].'</div><div style="font-size:12px; color:#888;">总人数</div></div></div>
        <div class="stat-box"><div class="stat-icon c-green"><i class="fa fa-clock-o"></i></div><div><div style="font-weight:bold; font-size:18px;">'.$avg_time.'m</div><div style="font-size:12px; color:#888;">平均时长</div></div></div>
        <div class="stat-box"><div class="stat-icon c-orange"><i class="fa fa-comments"></i></div><div><div style="font-weight:bold; font-size:18px;">'.$avg_ai.'</div><div style="font-size:12px; color:#888;">平均AI交互</div></div></div>
      </div>';

// 3. 表格
if (empty($rows_to_display)) {
    echo '<div style="padding:40px; text-align:center; background:#fff; color:#999; border-radius:10px;">暂无数据</div>';
} else {
    echo '<table class="student-table">
            <thead>
                <tr>
                    <th width="20%">学生信息</th>
                    <th width="10%">状态</th>
                    <th width="10%">成绩</th> <th width="30%">过程数据</th>
                    <th width="15%">最后更新</th>
                    <th width="15%" style="text-align:right">操作</th>
                </tr>
            </thead>
            <tbody>';
            
    foreach ($rows_to_display as $r) {
        $sub = $r->submission;
        
        // 状态显示逻辑
        if ($r->status_code === 'graded') $badge = '<span class="badge b-graded">✅ 已批改</span>';
        elseif ($r->status_code === 'submitted') $badge = '<span class="badge b-sub">已提交</span>';
        elseif ($r->status_code === 'draft') $badge = '<span class="badge b-draft">草稿中</span>';
        else $badge = '<span class="badge b-miss">未提交</span>';
        
        // 成绩显示逻辑
        $grade_html = '<span class="score-none">-</span>';
        if ($r->status_code === 'graded' && $sub) {
            $grade_html = '<span class="score-txt">'.(int)$sub->grade.'</span>';
        }

        // 数据
        $meta = '-';
        if ($sub) {
            $mins = round($sub->writing_time / 60);
            $meta = '<span style="color:#666; font-size:12px; margin-right:8px;"><i class="fa fa-clock-o"></i> '.$mins.'分</span>' .
                    '<span style="color:#666; font-size:12px; margin-right:8px;"><i class="fa fa-font"></i> '.$sub->word_count.'字</span>' .
                    '<span style="background:#f3e5f5; color:#9333ea; padding:2px 6px; border-radius:4px; font-size:12px;"><i class="fa fa-comments"></i> '.$r->ai_count.'</span>';
        }

        echo '<tr>
                <td>
                    <div style="display:flex; align-items:center; gap:10px;">
                        '.$OUTPUT->user_picture($r->user, ['size'=>36]).'
                        <div style="line-height:1.2">
                            <div style="font-weight:600; font-size:14px; color:#333;">'.fullname($r->user).'</div>
                            <div style="font-size:12px; color:#999;">'.$r->user->username.'</div>
                        </div>
                    </div>
                </td>
                <td>'.$badge.'</td>
                <td>'.$grade_html.'</td> <td>'.$meta.'</td>
                <td style="color:#888; font-size:13px;">'.($sub ? userdate($sub->timemodified, '%Y-%m-%d %H:%M') : '-').'</td>
                <td style="text-align:right;">';
        
        if ($sub) {
            echo '<a href="?id='.$id.'&action=download&userid='.$r->user->id.'" class="btn-dl" title="下载"><i class="fa fa-download"></i></a>';
            echo '<a href="grade.php?id='.$id.'&userid='.$r->user->id.'" class="btn-grade"><i class="fa fa-pencil"></i> '.(($r->status_code=='graded')?'重批':'批改').'</a>';
        } else {
            echo '<span style="color:#ccc; font-size:12px; margin-right:10px;">无数据</span>';
        }
        
        echo '  </td>
              </tr>';
    }
    echo '</tbody></table>';
}

echo '</div>'; 
echo $OUTPUT->footer();
?>