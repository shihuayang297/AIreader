<?php
require_once('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT); // cmid，可选，用于学生明细关联的活动
$tab = optional_param('tab', optional_param('view', 'overview', PARAM_ALPHA), PARAM_ALPHA); // overview | detail，兼容 view=detail
// 注意：uid 延后读取，避免批量导出时请求带 uid[] 数组导致 clean() 报错
$search = optional_param('search', '', PARAM_RAW);
$export_batch = optional_param('export_batch', 0, PARAM_INT); // 批量导出
$export_student_excel = optional_param('export_student_excel', 0, PARAM_INT); // 导出该生本周 Excel
$export_student_txt = optional_param('export_student_txt', 0, PARAM_INT);   // 导出该生质性文本
$detail_week = optional_param('detail_week', '', PARAM_RAW); // 筛选周次 如 20250201 或 all

require_login($courseid);
$context = context_course::instance($courseid);
require_capability('moodle/course:manageactivities', $context);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// 本课程下所有 aireader2 活动（用于学生明细）
$modinfo = get_fast_modinfo($course);
$aireader2_cms = [];
foreach ($modinfo->get_cms() as $cm) {
    if ($cm->modname === 'aireader2') {
        $aireader2_cms[$cm->id] = $cm;
    }
}
// 若传入 id 则用该活动，否则用第一个
$cm = null;
$aireader = null;
if ($id && isset($aireader2_cms[$id])) {
    $cm = $aireader2_cms[$id];
    $aireader = $DB->get_record('aireader2', ['id' => $cm->instance], '*', MUST_EXIST);
} elseif (!empty($aireader2_cms)) {
    $cm = reset($aireader2_cms);
    $id = $cm->id;
    $aireader = $DB->get_record('aireader2', ['id' => $cm->instance], '*', MUST_EXIST);
}

// 当前活动总页数（用于进度 页/总）：从 structure JSON 解析
$detail_total_pages = 0;
if ($aireader && !empty($aireader->structure)) {
    $struct = json_decode($aireader->structure, true);
    if (is_array($struct)) {
        foreach ($struct as $item) {
            if (isset($item['page']) && (int)$item['page'] > $detail_total_pages) {
                $detail_total_pages = (int)$item['page'];
            }
        }
    }
}

// ========== 批量导出已选同学数据（CSV） ==========
if ($export_batch && $aireader && $id) {
    $uids = optional_param_array('uid', [], PARAM_INT);
    $uids = array_filter(array_map('intval', $uids));
    $instance_id = $aireader->id;
    $course_context = context_course::instance($courseid);
    $valid_uids = [];
    if (!empty($uids)) {
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $enrolled = $DB->get_records_sql("SELECT ra.userid FROM {role_assignments} ra JOIN {context} ctx ON ctx.id = ra.contextid WHERE ctx.id = ? AND ra.userid IN ($placeholders)", array_merge([$course_context->id], $uids));
        foreach ($enrolled as $r) {
            if (!has_capability('moodle/course:manageactivities', $context, $r->userid)) $valid_uids[] = $r->userid;
        }
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="学情导出_' . preg_replace('/[^\p{L}\p{N}\-_]/u', '_', $aireader->name) . '_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    $out = fopen('php://output', 'w');
    fputcsv($out, ['学号', '姓名', '阅读时长(分钟)', '进度(页/总)', '交互轮数', '笔记总数']);
    foreach ($valid_uids as $userid) {
        $u = $DB->get_record('user', ['id' => $userid], 'id, username, firstname, lastname');
        $read_sec = 0;
        $last_page = 1;
        if ($DB->get_manager()->table_exists('aireader2_progress')) {
            $p = $DB->get_record('aireader2_progress', ['aireader2id' => $instance_id, 'userid' => $userid], 'total_read_seconds, last_page');
            if ($p) { $read_sec = (int)$p->total_read_seconds; $last_page = (int)$p->last_page; }
        }
        $ann = $DB->count_records('aireader2_annotations', ['aireader2id' => $instance_id, 'userid' => $userid]);
        $chat = $DB->count_records('aireader2_chat_log', ['aireader2id' => $instance_id, 'userid' => $userid]);
        $total_p = $detail_total_pages > 0 ? $detail_total_pages : $last_page;
        $progress_str = $detail_total_pages > 0 ? $last_page . '/' . $total_p : (string)$last_page;
        fputcsv($out, [$u ? $u->username : $userid, $u ? fullname($u) : '', (int)floor($read_sec / 60), $progress_str, $chat, $ann]);
    }
    fclose($out);
    exit;
}

// 学生明细选中的学生 id（放在批量导出之后读，避免 uid[] 数组触发 clean() 报错）
$uid = optional_param('uid', 0, PARAM_INT);

// ========== 导出该生本周 Excel（CSV）/ 导出质性文本 ==========
if (($export_student_excel || $export_student_txt) && $uid > 0 && $aireader) {
    $user_obj = $DB->get_record('user', ['id' => $uid], 'id, username, firstname, lastname');
    if ($user_obj && !has_capability('moodle/course:manageactivities', $context, $user_obj)) {
        $instance_id = $aireader->id;
            $week_start = 0;
            $week_end = 999999999;
            if ($detail_week !== '' && $detail_week !== 'all') {
                $t = strtotime($detail_week . ' 00:00:00');
                if ($t) { $week_start = $t; $week_end = $t + 7 * 86400 - 1; }
            }
            if ($export_student_excel) {
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="' . ($user_obj->username ?: 'student') . '_week_' . ($detail_week ?: 'all') . '.csv"');
                echo "\xEF\xBB\xBF";
                $out = fopen('php://output', 'w');
                fputcsv($out, ['类型', '序号', '角色/SKI', '内容', '时间']);
                $chat_cols_export = ['id'];
                if ($DB->get_manager()->field_exists('aireader2_chat_log', 'sender_type')) $chat_cols_export[] = 'sender_type';
                if ($DB->get_manager()->field_exists('aireader2_chat_log', 'role')) $chat_cols_export[] = 'role';
                if ($DB->get_manager()->field_exists('aireader2_chat_log', 'agent_name')) $chat_cols_export[] = 'agent_name';
                $chat_cols_export[] = $DB->get_manager()->field_exists('aireader2_chat_log', 'content') ? 'content' : 'user_message, ai_response';
                $chat_cols_export[] = 'timecreated';
                $chat_sel_export = implode(', ', $chat_cols_export);
                $chats = $DB->get_records_sql("SELECT $chat_sel_export FROM {aireader2_chat_log} WHERE aireader2id = ? AND userid = ? ORDER BY timecreated", [$instance_id, $uid]);
                foreach ($chats as $i => $row) {
                    if ($detail_week !== '' && $detail_week !== 'all' && ($row->timecreated < $week_start || $row->timecreated > $week_end)) continue;
                    $role = (isset($row->role) && $row->role === 'user') || (isset($row->sender_type) && $row->sender_type === 'user') ? '学生' : (isset($row->agent_name) ? $row->agent_name : 'AI');
                    $content = isset($row->content) ? $row->content : (isset($row->user_message) ? $row->user_message : '') . (isset($row->ai_response) ? "\n" . $row->ai_response : '');
                    fputcsv($out, ['对话', $i + 1, $role, $content, userdate($row->timecreated, '%Y-%m-%d %H:%M:%S')]);
                }
                $anns = $DB->get_records_sql("SELECT id, page_num, quote AS ann_quote, created_at FROM {aireader2_annotations} WHERE aireader2id = ? AND userid = ? ORDER BY page_num, created_at", [$instance_id, $uid]);
                foreach ($anns as $i => $row) {
                    if ($detail_week !== '' && $detail_week !== 'all' && ($row->created_at < $week_start || $row->created_at > $week_end)) continue;
                    $quote = isset($row->ann_quote) ? $row->ann_quote : (isset($row->quote) ? $row->quote : '');
                    fputcsv($out, ['笔记', $i + 1, 'P' . $row->page_num, $quote, userdate($row->created_at, '%Y-%m-%d %H:%M:%S')]);
                }
                fclose($out);
                exit;
            }
            if ($export_student_txt) {
                header('Content-Type: text/plain; charset=UTF-8');
                header('Content-Disposition: attachment; filename="' . ($user_obj->username ?: 'student') . '_qualitative_' . ($detail_week ?: 'all') . '.txt"');
                $lines = [];
                $chat_cols_txt = [];
                if ($DB->get_manager()->field_exists('aireader2_chat_log', 'sender_type')) $chat_cols_txt[] = 'sender_type';
                if ($DB->get_manager()->field_exists('aireader2_chat_log', 'role')) $chat_cols_txt[] = 'role';
                if ($DB->get_manager()->field_exists('aireader2_chat_log', 'agent_name')) $chat_cols_txt[] = 'agent_name';
                $chat_cols_txt[] = $DB->get_manager()->field_exists('aireader2_chat_log', 'content') ? 'content' : 'user_message, ai_response';
                $chat_cols_txt[] = 'timecreated';
                $chat_sel_txt = implode(', ', $chat_cols_txt);
                $chats = $DB->get_records_sql("SELECT $chat_sel_txt FROM {aireader2_chat_log} WHERE aireader2id = ? AND userid = ? ORDER BY timecreated", [$instance_id, $uid]);
                foreach ($chats as $row) {
                    if ($detail_week !== '' && $detail_week !== 'all' && ($row->timecreated < $week_start || $row->timecreated > $week_end)) continue;
                    $role = (isset($row->role) && $row->role === 'user') || (isset($row->sender_type) && $row->sender_type === 'user') ? '学生' : (isset($row->agent_name) ? $row->agent_name : 'AI');
                    $content = isset($row->content) ? $row->content : (isset($row->user_message) ? $row->user_message : '') . (isset($row->ai_response) ? "\n" . $row->ai_response : '');
                    $lines[] = '[' . userdate($row->timecreated, '%Y-%m-%d %H:%M:%S') . '] ' . $role . ': ' . $content;
                }
                $anns = $DB->get_records_sql("SELECT page_num, quote AS ann_quote, created_at FROM {aireader2_annotations} WHERE aireader2id = ? AND userid = ? ORDER BY page_num, created_at", [$instance_id, $uid]);
                foreach ($anns as $row) {
                    if ($detail_week !== '' && $detail_week !== 'all' && ($row->created_at < $week_start || $row->created_at > $week_end)) continue;
                    $quote = isset($row->ann_quote) ? $row->ann_quote : (isset($row->quote) ? $row->quote : '');
                    $lines[] = '[' . userdate($row->created_at, '%Y-%m-%d %H:%M:%S') . '] 笔记 P' . $row->page_num . ': ' . $quote;
                }
                echo implode("\n", $lines);
                exit;
            }
    }
}

$PUBLIC_DASHBOARD_URL = 'http://49.232.13.148:3000/public/dashboard/a0a4df3d-c515-41c9-853a-6aa989751419';
$iframeUrl = $PUBLIC_DASHBOARD_URL . "?course_id=" . $courseid . "#bordered=false&titled=false&theme=transparent";

$PAGE->set_url('/mod/aireader2/dashboard.php', ['courseid' => $courseid, 'id' => $id, 'tab' => $tab, 'uid' => $uid]);
$PAGE->set_title('学情看板');
$PAGE->set_heading('AI 写作课程学情数据');
$PAGE->set_pagelayout('embedded'); // 全屏：隐藏 Moodle 顶栏/侧栏/页脚，仅保留本模块内容

// 整体驾驶舱：课程级汇总与智能体交互数据（用于图表）
$overview_agent_counts = [];
$overview_total_read = 0;
$overview_total_ann = 0;
$overview_total_chat = 0;
$overview_student_count = 0;
$overview_activity_chart = []; // [label => [read_min, ann, chat]]
if (!empty($aireader2_cms)) {
    $aid_list = array_map(function($c) { return $c->instance; }, $aireader2_cms);
    $placeholders = implode(',', array_fill(0, count($aid_list), '?'));
    if ($DB->get_manager()->table_exists('aireader2_chat_log')) {
        $agent_rows = $DB->get_records_sql(
            "SELECT agent_name, COUNT(*) AS cnt FROM {aireader2_chat_log} WHERE aireader2id IN ($placeholders) AND " . $DB->sql_isnotempty('aireader2_chat_log', 'agent_name', true, true) . " GROUP BY agent_name",
            $aid_list
        );
        foreach ($agent_rows as $r) { $overview_agent_counts[$r->agent_name] = (int)$r->cnt; }
        $overview_total_chat = (int)$DB->get_field_sql("SELECT COUNT(*) FROM {aireader2_chat_log} WHERE aireader2id IN ($placeholders)", $aid_list);
    }
    if ($DB->get_manager()->table_exists('aireader2_annotations')) {
        $overview_total_ann = (int)$DB->get_field_sql("SELECT COUNT(*) FROM {aireader2_annotations} WHERE aireader2id IN ($placeholders)", $aid_list);
    }
    if ($DB->get_manager()->table_exists('aireader2_progress')) {
        $read_sum = $DB->get_field_sql("SELECT COALESCE(SUM(total_read_seconds),0) FROM {aireader2_progress} WHERE aireader2id IN ($placeholders)", $aid_list);
        $overview_total_read = (int)floor($read_sum / 60);
    }
    $course_context = context_course::instance($courseid);
    $overview_student_count = $DB->count_records_sql("SELECT COUNT(DISTINCT ra.userid) FROM {role_assignments} ra JOIN {context} ctx ON ctx.id = ra.contextid WHERE ctx.id = ? AND ra.userid NOT IN (SELECT id FROM {user} WHERE deleted = 1)", [$course_context->id]);
    foreach ($aireader2_cms as $c) {
        $aid = $c->instance;
        $read_min = 0;
        if ($DB->get_manager()->table_exists('aireader2_progress')) {
            $sum = $DB->get_field_sql("SELECT COALESCE(SUM(total_read_seconds),0) FROM {aireader2_progress} WHERE aireader2id = ?", [$aid]);
            $read_min = (int)floor($sum / 60);
        }
        $overview_activity_chart[] = [
            'label' => $c->name,
            'read_min' => $read_min,
            'ann' => $DB->count_records('aireader2_annotations', ['aireader2id' => $aid]),
            'chat' => $DB->get_manager()->table_exists('aireader2_chat_log') ? $DB->count_records('aireader2_chat_log', ['aireader2id' => $aid]) : 0,
        ];
    }
}
$overview_agent_chart_json = json_encode(array_values(array_map(function($k, $v) { return ['label' => $k, 'value' => $v]; }, array_keys($overview_agent_counts), array_values($overview_agent_counts))), JSON_UNESCAPED_UNICODE);
$overview_activity_chart_json = json_encode($overview_activity_chart, JSON_UNESCAPED_UNICODE);
$overview_read_buckets = ['0-10分钟' => 0, '10-30分钟' => 0, '30-60分钟' => 0, '60分钟以上' => 0];
$overview_top_students = [];
$overview_activity_summary = [];
$overview_participation_pct = 0;
$overview_avg_read = 0;
$overview_avg_ann = 0;
$overview_avg_chat = 0;
$overview_students_with_any = 0;
$overview_read_buckets_json = '[]';
$overview_read_buckets_labels_json = '[]';
if (!empty($aireader2_cms)) {
    $aid_list = array_map(function($c) { return $c->instance; }, $aireader2_cms);
    $placeholders = implode(',', array_fill(0, count($aid_list), '?'));
    $ctx_course = context_course::instance($courseid);
    if ($DB->get_manager()->table_exists('aireader2_progress')) {
        $user_read_rows = $DB->get_records_sql("SELECT userid, COALESCE(SUM(total_read_seconds),0) AS sec FROM {aireader2_progress} WHERE aireader2id IN ($placeholders) GROUP BY userid", $aid_list);
        foreach ($user_read_rows as $r) {
            $min = (int)floor($r->sec / 60);
            if ($min <= 10) $overview_read_buckets['0-10分钟']++;
            elseif ($min <= 30) $overview_read_buckets['10-30分钟']++;
            elseif ($min <= 60) $overview_read_buckets['30-60分钟']++;
            else $overview_read_buckets['60分钟以上']++;
        }
    }
    if ($DB->get_manager()->table_exists('aireader2_progress') && $DB->get_manager()->table_exists('aireader2_annotations') && $DB->get_manager()->table_exists('aireader2_chat_log')) {
        $overview_students_with_any = (int)$DB->get_field_sql(
            "SELECT COUNT(DISTINCT u.id) FROM (SELECT userid AS id FROM {aireader2_progress} WHERE aireader2id IN ($placeholders) UNION SELECT userid FROM {aireader2_annotations} WHERE aireader2id IN ($placeholders) UNION SELECT userid FROM {aireader2_chat_log} WHERE aireader2id IN ($placeholders)) u",
            array_merge($aid_list, $aid_list, $aid_list)
        );
        $overview_participation_pct = $overview_student_count > 0 ? round($overview_students_with_any / $overview_student_count * 100, 1) : 0;
        if ($overview_students_with_any > 0) {
            $overview_avg_read = (int)round($overview_total_read / $overview_students_with_any);
            $overview_avg_ann = round($overview_total_ann / $overview_students_with_any, 1);
            $overview_avg_chat = round($overview_total_chat / $overview_students_with_any, 1);
        }
    }
    $top_engagement = array();
    foreach ($aireader2_cms as $c) {
        $aid = $c->instance;
        if ($DB->get_manager()->table_exists('aireader2_progress')) {
            $rows = $DB->get_records('aireader2_progress', array('aireader2id' => $aid), '', 'userid, total_read_seconds');
            foreach ($rows as $r) {
                if (!isset($top_engagement[$r->userid])) $top_engagement[$r->userid] = array('read_min' => 0, 'ann' => 0, 'chat' => 0);
                $top_engagement[$r->userid]['read_min'] += (int)floor($r->total_read_seconds / 60);
            }
        }
        $ann_rows = $DB->get_records_sql("SELECT userid, COUNT(*) AS cnt FROM {aireader2_annotations} WHERE aireader2id = ? GROUP BY userid", array($aid));
        foreach ($ann_rows as $r) {
            if (!isset($top_engagement[$r->userid])) $top_engagement[$r->userid] = array('read_min' => 0, 'ann' => 0, 'chat' => 0);
            $top_engagement[$r->userid]['ann'] += (int)$r->cnt;
        }
        if ($DB->get_manager()->table_exists('aireader2_chat_log')) {
            $chat_rows = $DB->get_records_sql("SELECT userid, COUNT(*) AS cnt FROM {aireader2_chat_log} WHERE aireader2id = ? GROUP BY userid", array($aid));
            foreach ($chat_rows as $r) {
                if (!isset($top_engagement[$r->userid])) $top_engagement[$r->userid] = array('read_min' => 0, 'ann' => 0, 'chat' => 0);
                $top_engagement[$r->userid]['chat'] += (int)$r->cnt;
            }
        }
    }
    uasort($top_engagement, function($a, $b) { $sa = $a['read_min'] + $a['ann'] * 2 + $a['chat'] * 2; $sb = $b['read_min'] + $b['ann'] * 2 + $b['chat'] * 2; return $sb - $sa; });
    $top5 = array_slice($top_engagement, 0, 5, true);
    foreach ($top5 as $userid => $stats) {
        $u = $DB->get_record('user', array('id' => $userid), 'id, firstname, lastname, username');
        $overview_top_students[] = (object)array('name' => $u ? fullname($u) : 'User#'.$userid, 'username' => $u ? $u->username : '', 'read_min' => $stats['read_min'], 'ann' => $stats['ann'], 'chat' => $stats['chat']);
    }
    foreach ($aireader2_cms as $c) {
        $aid = $c->instance;
        $participated = (int)$DB->get_field_sql("SELECT COUNT(DISTINCT userid) FROM (SELECT userid FROM {aireader2_progress} WHERE aireader2id = ? UNION SELECT userid FROM {aireader2_annotations} WHERE aireader2id = ? UNION SELECT userid FROM {aireader2_chat_log} WHERE aireader2id = ?) t", array($aid, $aid, $aid));
        $read_min = 0;
        if ($DB->get_manager()->table_exists('aireader2_progress')) {
            $sum = $DB->get_field_sql("SELECT COALESCE(SUM(total_read_seconds),0) FROM {aireader2_progress} WHERE aireader2id = ?", array($aid));
            $read_min = (int)floor($sum / 60);
        }
        $ann = $DB->count_records('aireader2_annotations', array('aireader2id' => $aid));
        $chat = $DB->get_manager()->table_exists('aireader2_chat_log') ? $DB->count_records('aireader2_chat_log', array('aireader2id' => $aid)) : 0;
        $pct = $overview_student_count > 0 ? round($participated / $overview_student_count * 100, 1) : 0;
        $overview_activity_summary[] = (object)array('name' => $c->name, 'participated' => $participated, 'read_min' => $read_min, 'ann' => $ann, 'chat' => $chat, 'participation_pct' => $pct);
    }
    $overview_read_buckets_json = json_encode(array_values($overview_read_buckets), JSON_UNESCAPED_UNICODE);
    $overview_read_buckets_labels_json = json_encode(array_keys($overview_read_buckets), JSON_UNESCAPED_UNICODE);
}

// 学生明细：获取学生列表及选中学生的详情数据
$detail_user = null;
$detail_annotations_list = [];
$detail_chat_agents = [];
$detail_user_questions = [];
$detail_wordcloud_json = '[]';
$detail_dialogue_log = [];
$detail_annotations_for_table = [];
$detail_weeks_options = ['' => '全部', 'all' => '全部'];
$students_for_list = [];
$progress_table_rows = []; // 按 (学生, 任务) 一行，用于看板表格

if ($tab === 'detail' && !empty($aireader2_cms)) {
    $course_context = context_course::instance($courseid);
    $params = ['contextid' => $course_context->id];
    $sql_users = "SELECT u.* FROM {user} u
        JOIN {role_assignments} ra ON ra.userid = u.id
        JOIN {context} ctx ON ctx.id = ra.contextid
        WHERE ctx.id = :contextid
        ORDER BY u.lastname, u.firstname";
    $all_students = $DB->get_records_sql($sql_users, $params);
    $instance_id = $aireader ? $aireader->id : null;

    // 构建「学生阅读进度」表格数据：每个 (学生, 任务) 一行，便于按任务区分
    foreach ($aireader2_cms as $cid => $c) {
        $aid = $c->instance;
        $ar = $DB->get_record('aireader2', ['id' => $aid], 'id, name, structure');
        $total_pages_act = 0;
        if ($ar && !empty($ar->structure)) {
            $struct = json_decode($ar->structure, true);
            if (is_array($struct)) {
                foreach ($struct as $item) {
                    if (isset($item['page']) && (int)$item['page'] > $total_pages_act) $total_pages_act = (int)$item['page'];
                }
            }
        }
        foreach ($all_students as $u) {
            if (has_capability('moodle/course:manageactivities', $context, $u)) continue;
            $read_sec = 0;
            $last_page = 1;
            if ($DB->get_manager()->table_exists('aireader2_progress')) {
                $prog = $DB->get_record('aireader2_progress', ['aireader2id' => $aid, 'userid' => $u->id], 'total_read_seconds, last_page');
                if ($prog) {
                    $read_sec = (int)$prog->total_read_seconds;
                    $last_page = isset($prog->last_page) ? (int)$prog->last_page : 1;
                }
            }
            $ann_count = $DB->count_records('aireader2_annotations', ['aireader2id' => $aid, 'userid' => $u->id]);
            $chat_count = $DB->count_records('aireader2_chat_log', ['aireader2id' => $aid, 'userid' => $u->id]);
            $progress_table_rows[] = (object)[
                'user' => $u,
                'cm_id' => $cid,
                'cm_name' => $c->name,
                'read_minutes' => (int)floor($read_sec / 60),
                'last_page' => $last_page,
                'total_pages' => $total_pages_act,
                'ann_count' => $ann_count,
                'chat_count' => $chat_count,
            ];
        }
    }

    // 左侧列表仍按「当前活动」：只显示当前 id 下的学生
    if ($aireader && $instance_id) {
        $total_pages_here = isset($detail_total_pages) ? (int)$detail_total_pages : 0;
        foreach ($all_students as $u) {
            if (has_capability('moodle/course:manageactivities', $context, $u)) continue;
            $read_sec = 0;
            $last_page = 1;
            if ($DB->get_manager()->table_exists('aireader2_progress')) {
                $prog = $DB->get_record('aireader2_progress', ['aireader2id' => $instance_id, 'userid' => $u->id], 'total_read_seconds, last_page');
                if ($prog) {
                    $read_sec = (int)$prog->total_read_seconds;
                    $last_page = isset($prog->last_page) ? (int)$prog->last_page : 1;
                }
            }
            $ann_count = $DB->count_records('aireader2_annotations', ['aireader2id' => $instance_id, 'userid' => $u->id]);
            $chat_count = $DB->count_records('aireader2_chat_log', ['aireader2id' => $instance_id, 'userid' => $u->id]);
            $students_for_list[] = (object)[
                'user' => $u,
                'read_minutes' => (int)floor($read_sec / 60),
                'ann_count' => $ann_count,
                'chat_count' => $chat_count,
                'last_page' => $last_page,
                'total_pages' => $total_pages_here,
            ];
        }
    }

    if ($uid > 0) {
        $detail_user = $DB->get_record('user', ['id' => $uid]);
    }
    // 回退：SQL/编码未命中时（如中文姓名字段 LIKE 不匹配），用 PHP 在左侧同一批学生里按姓名/学号匹配，确保列表里能看到「张旭」则搜索「张旭」能出详情
    if (!$detail_user && trim($search) !== '' && !empty($students_for_list)) {
        $search_trim = trim($search);
        foreach ($students_for_list as $s) {
            $u = $s->user;
            $full1 = $u->lastname . $u->firstname;
            $full2 = $u->firstname . $u->lastname;
            if (stripos($u->firstname, $search_trim) !== false || stripos($u->lastname, $search_trim) !== false
                || stripos($u->username, $search_trim) !== false || stripos($full1, $search_trim) !== false
                || stripos($full2, $search_trim) !== false) {
                $detail_user = $u;
                $uid = (int)$u->id;
                break;
            }
        }
    }

    if ($detail_user && $aireader) {
        $instance_id = $aireader->id;
        $uid = (int)$detail_user->id;
        $detail_progress = [];
        $detail_annotations = [];
        $detail_chat_by_agent = [];
        $detail_access_days = 0;
        $detail_total_read_min = 0;
        $detail_total_ann = 0;
        $detail_total_chat = 0;
        foreach ($aireader2_cms as $cid => $c) {
            $aid = $c->instance;
            $detail_progress[$aid] = null;
            $detail_annotations[$aid] = 0;
            if ($DB->get_manager()->table_exists('aireader2_progress')) {
                $p = $DB->get_record('aireader2_progress', ['aireader2id' => $aid, 'userid' => $uid], 'total_read_seconds, last_access');
                if ($p) {
                    $detail_progress[$aid] = $p;
                    $detail_total_read_min += (int)floor($p->total_read_seconds / 60);
                }
            }
            $detail_annotations[$aid] = $DB->count_records('aireader2_annotations', ['aireader2id' => $aid, 'userid' => $uid]);
            $detail_total_ann += $detail_annotations[$aid];
            $detail_total_chat += $DB->count_records('aireader2_chat_log', ['aireader2id' => $aid, 'userid' => $uid]);
        }
        $aid_list = array_map(function($c) { return $c->instance; }, $aireader2_cms);
        if ($DB->get_manager()->table_exists('aireader2_chat_log') && !empty($aid_list)) {
            $placeholders = implode(',', array_fill(0, count($aid_list), '?'));
            $days_sql = "SELECT COUNT(DISTINCT FLOOR(timecreated/86400)) FROM {aireader2_chat_log} WHERE userid = ? AND aireader2id IN ($placeholders)";
            $detail_access_days = (int)$DB->get_field_sql($days_sql, array_merge([$uid], $aid_list));
        }
        try {
            // 标注内容列表：quote 为 MySQL 保留字，用 AS ann_quote 避免歧义
            $detail_annotations_list = $DB->get_records_sql(
                "SELECT a.id, a.aireader2id, a.page_num, a.type, a.quote AS ann_quote, a.created_at
                 FROM {aireader2_annotations} a
                 WHERE a.aireader2id = ? AND a.userid = ?
                 ORDER BY a.page_num, a.created_at",
                [$instance_id, $uid]
            );
            // 和哪些智能体聊了（去重，仅 AI 消息的 agent_name）
            $agents_rows = $DB->get_records_sql(
                "SELECT DISTINCT agent_name FROM {aireader2_chat_log}
                 WHERE aireader2id = ? AND userid = ? AND " . $DB->sql_isnotempty('aireader2_chat_log', 'agent_name', true, true) . "
                 ORDER BY agent_name",
                [$instance_id, $uid]
            );
            $detail_chat_agents = array_map(function($r) { return $r->agent_name; }, $agents_rows);
            // 与各智能体交互分布（条数）
            $agent_counts = $DB->get_records_sql(
                "SELECT agent_name, COUNT(*) AS cnt FROM {aireader2_chat_log}
                 WHERE aireader2id = ? AND userid = ? AND " . $DB->sql_isnotempty('aireader2_chat_log', 'agent_name', true, true) . "
                 GROUP BY agent_name",
                [$instance_id, $uid]
            );
            foreach ($agent_counts as $r) { $detail_chat_by_agent[$r->agent_name] = (int)$r->cnt; }
            // 用户提问列表（user 消息：role=user 或 sender_type=user）
            if ($DB->get_manager()->field_exists('aireader2_chat_log', 'role')) {
                $detail_user_questions = $DB->get_records('aireader2_chat_log', [
                    'aireader2id' => $instance_id, 'userid' => $uid, 'role' => 'user'
                ], 'timecreated ASC', 'id, content, timecreated');
            } else {
                $detail_user_questions = $DB->get_records('aireader2_chat_log', [
                    'aireader2id' => $instance_id, 'userid' => $uid, 'sender_type' => 'user'
                ], 'timecreated ASC', 'id, content, timecreated');
            }
            // 词云用：标注原文 + 用户 content 合并为词频（使用 ann_quote 避免 MySQL 保留字 quote）
            $texts = [];
            foreach ($detail_annotations_list as $a) {
                $quote_raw = isset($a->ann_quote) ? $a->ann_quote : (isset($a->quote) ? $a->quote : '');
                if ($quote_raw !== '') $texts[] = $quote_raw;
            }
            foreach ($detail_user_questions as $q) {
                if (!empty($q->content)) $texts[] = $q->content;
            }
            $full_text = implode(' ', $texts);
            $words = preg_split('/[\s\p{P}\p{Z}+]/u', $full_text, -1, PREG_SPLIT_NO_EMPTY);
            $words = array_filter($words, function($w) { return mb_strlen($w) >= 2; });
            $freq = array_count_values($words);
            arsort($freq);
            $wordcloud_arr = array_slice(array_map(function($word, $count) {
                return ['text' => $word, 'weight' => $count];
            }, array_keys($freq), array_values($freq)), 0, 50);
            $detail_wordcloud_json = json_encode($wordcloud_arr, JSON_UNESCAPED_UNICODE);
            // 实验交互详情复盘：多智能体对话记录 + 阅读笔记与高亮（支持按周筛选）
            $detail_dialogue_log = [];
            $detail_annotations_for_table = [];
            $detail_weeks_options = ['' => '全部', 'all' => '全部'];
            try {
                $chat_tbl = 'aireader2_chat_log';
                $dbman = $DB->get_manager();
                $chat_cols = ['id'];
                if ($dbman->field_exists($chat_tbl, 'sender_type')) { $chat_cols[] = 'sender_type'; }
                if ($dbman->field_exists($chat_tbl, 'role')) { $chat_cols[] = 'role'; }
                if ($dbman->field_exists($chat_tbl, 'agent_name')) { $chat_cols[] = 'agent_name'; }
                if ($dbman->field_exists($chat_tbl, 'content')) {
                    $chat_cols[] = 'content';
                } elseif ($dbman->field_exists($chat_tbl, 'user_message') && $dbman->field_exists($chat_tbl, 'ai_response')) {
                    $chat_cols[] = 'user_message';
                    $chat_cols[] = 'ai_response';
                }
                $chat_cols[] = 'timecreated';
                $chat_sel = implode(', ', $chat_cols);
                $all_chat = $DB->get_records_sql(
                    "SELECT $chat_sel FROM {aireader2_chat_log} WHERE aireader2id = ? AND userid = ? ORDER BY timecreated ASC",
                    [$instance_id, $uid]
                );
                // 旧表只有 user_message/ai_response 时，拆成两条展示（先用户后 AI）
                if (!empty($all_chat) && !$dbman->field_exists($chat_tbl, 'content') && $dbman->field_exists($chat_tbl, 'user_message')) {
                    $expanded = [];
                    foreach ($all_chat as $r) {
                        if (!empty($r->user_message)) {
                            $expanded[] = (object)['id' => $r->id, 'role' => 'user', 'sender_type' => 'user', 'agent_name' => '', 'content' => $r->user_message, 'timecreated' => $r->timecreated];
                        }
                        if (!empty($r->ai_response)) {
                            $expanded[] = (object)['id' => $r->id, 'role' => 'ai', 'sender_type' => 'ai', 'agent_name' => isset($r->agent_name) ? $r->agent_name : '', 'content' => $r->ai_response, 'timecreated' => $r->timecreated];
                        }
                    }
                    $all_chat = $expanded;
                }
                $all_ann = $DB->get_records_sql(
                    "SELECT id, page_num, type, quote AS ann_quote, created_at FROM {aireader2_annotations} WHERE aireader2id = ? AND userid = ? ORDER BY page_num, created_at",
                    [$instance_id, $uid]
                );
                $min_ts = null;
                $max_ts = null;
                foreach ($all_chat as $r) { if ($min_ts === null || $r->timecreated < $min_ts) $min_ts = $r->timecreated; if ($max_ts === null || $r->timecreated > $max_ts) $max_ts = $r->timecreated; }
                foreach ($all_ann as $r) { if ($min_ts === null || $r->created_at < $min_ts) $min_ts = $r->created_at; if ($max_ts === null || $r->created_at > $max_ts) $max_ts = $r->created_at; }
                if ($min_ts !== null && $max_ts !== null) {
                    for ($t = $min_ts; $t <= $max_ts; $t += 604800) {
                        $key = date('Y-m-d', $t);
                        $end = $t + 604800 - 1;
                        $detail_weeks_options[$key] = date('n/j', $t) . ' - ' . date('n/j', min($end, $max_ts));
                    }
                }
                $week_start = null;
                $week_end = null;
                if ($detail_week !== '' && $detail_week !== 'all') {
                    $t = strtotime($detail_week . ' 00:00:00');
                    if ($t) { $week_start = $t; $week_end = $t + 7 * 86400 - 1; }
                }
                foreach ($all_chat as $row) {
                    if ($week_start !== null && ($row->timecreated < $week_start || $row->timecreated > $week_end)) continue;
                    $detail_dialogue_log[] = $row;
                }
                foreach ($all_ann as $row) {
                    if ($week_start !== null && ($row->created_at < $week_start || $row->created_at > $week_end)) continue;
                    $detail_annotations_for_table[] = $row;
                }
            } catch (Exception $e) {
                $detail_dialogue_log = [];
                $detail_annotations_for_table = [];
            }
        } catch (Exception $e) {
            $detail_annotations_list = [];
            $detail_chat_agents = [];
            $detail_chat_by_agent = [];
            $detail_user_questions = [];
            $detail_wordcloud_json = '[]';
            $detail_dialogue_log = [];
            $detail_annotations_for_table = [];
            $detail_weeks_options = ['' => '全部', 'all' => '全部'];
        }
    }
}

echo $OUTPUT->header();

// 全屏：隐藏 Moodle 自带顶栏、导航、页脚、区块，仅保留本模块内容（与阅读平台一致）
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* 全屏覆盖 Moodle 原生 UI */
body.pagelayout-embedded #page-header,
body.pagelayout-embedded .navbar,
body.pagelayout-embedded #page-footer,
body.pagelayout-embedded .block,
body.pagelayout-embedded #block-region-side-pre,
body.pagelayout-embedded #block-region-side-post,
body.pagelayout-embedded nav { display: none !important; }
body.pagelayout-embedded #page { margin: 0; padding: 0; max-width: none; }
body.pagelayout-embedded #page-content { padding: 0; }
/* 校园风格背景图 + 内容区半透明衬底 */
.dashboard-fullscreen {
  min-height: 100vh;
  padding: 0;
  font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
  position: relative;
  background: linear-gradient(135deg, #e8f4fc 0%, #d4e9f7 50%, #c5e3f0 100%);
  background-image: url(https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=1920&q=80), linear-gradient(135deg, rgba(248,252,255,0.88) 0%, rgba(230,244,252,0.92) 100%);
  background-size: cover, cover;
  background-position: center center, 0 0;
  background-blend-mode: normal, normal;
}
@media (prefers-reduced-motion: no-preference) { .dashboard-fullscreen { background-attachment: fixed; } }
.tc-body { position: relative; z-index: 1; }
/* 顶部栏：青春风格背景图 + 深色遮罩保可读 */
.tc-header {
  font-family: "STKaiti", "华文楷体", "KaiTi", "楷体", serif;
  background: url(https://images.unsplash.com/photo-1523580494863-6f3031224c94?w=1600&q=75) center center / cover no-repeat;
  color: #fff;
  padding: 12px 28px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  box-shadow: 0 4px 20px rgba(0,0,0,0.2);
  position: relative;
  min-height: 52px;
  border-bottom: 1px solid rgba(255,255,255,0.15);
  overflow: hidden;
}
.tc-header::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, rgba(60,120,180,0.35) 0%, rgba(80,140,200,0.28) 50%, rgba(70,130,190,0.32) 100%);
  pointer-events: none;
}
.tc-header::after {
  content: '';
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
  pointer-events: none;
  z-index: 1;
}
.tc-header > * { position: relative; z-index: 1; }
.tc-header h1 {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 12px;
  letter-spacing: 0.06em;
  text-shadow: 0 1px 3px rgba(0,0,0,0.35);
}
.tc-header h1 i {
  opacity: 1;
  font-size: 24px;
  filter: drop-shadow(0 1px 2px rgba(0,0,0,0.2));
  color: #fff;
}
.tc-header .sub {
  font-family: "STKaiti", "华文楷体", "KaiTi", "楷体", serif;
  opacity: 0.9;
  font-size: 13px;
  margin-top: 2px;
  font-weight: 500;
  letter-spacing: 0.04em;
}
.tc-header .btn-back {
  font-family: "STKaiti", "华文楷体", "KaiTi", "楷体", serif;
  background: rgba(255,255,255,0.2);
  color: #fff;
  padding: 8px 20px;
  border-radius: 10px;
  text-decoration: none;
  font-size: 14px;
  font-weight: 600;
  letter-spacing: 0.03em;
  transition: all 0.2s ease;
  border: 1px solid rgba(255,255,255,0.28);
}
.tc-header .btn-back:hover {
  background: rgba(255,255,255,0.32);
  color: #fff;
  transform: translateY(-1px);
}
.tc-tabs {
  font-family: "STKaiti", "华文楷体", "KaiTi", "楷体", serif;
  display: flex;
  gap: 0;
  padding: 0 28px;
  background: rgba(255,255,255,0.85);
  backdrop-filter: blur(8px);
  border-bottom: 2px solid rgba(224,230,240,0.9);
  box-shadow: 0 2px 10px rgba(0,0,0,0.04);
}
.tc-tabs a {
  padding: 12px 24px;
  text-decoration: none;
  color: #455a64;
  font-weight: 600;
  font-size: 15px;
  letter-spacing: 0.05em;
  border-bottom: 3px solid transparent;
  transition: color 0.2s, border-color 0.2s;
  margin-bottom: -2px;
}
.tc-tabs a:hover { color: #1565c0; }
.tc-tabs a.active { color: #1565c0; border-bottom-color: #1565c0; }
.tc-body { position: relative; z-index: 1; padding: 24px 28px; max-width: 1600px; margin: 0 auto; }
/* 整体驾驶舱：统计卡片 */
.tc-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
.tc-card { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 4px 20px rgba(0,82,217,0.08); border: 1px solid rgba(0,82,217,0.08); transition: all 0.25s; position: relative; overflow: hidden; }
.tc-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: linear-gradient(180deg, #0052D9, #2680eb); }
.tc-card:hover { box-shadow: 0 8px 32px rgba(0,82,217,0.15); transform: translateY(-2px); }
.tc-card .label { font-size: 13px; color: #8a939d; margin-bottom: 6px; font-weight: 500; }
.tc-card .value { font-size: 32px; font-weight: 800; color: #0052D9; letter-spacing: -0.02em; }
.tc-card .value.green { color: #00a870; }
.tc-card .value.orange { color: #e37318; }
.tc-card .value.purple { color: #7c3aed; }
/* 图表区 */
.tc-chart-row { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-bottom: 28px; }
.tc-chart-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 22px; margin-bottom: 28px; }
.tc-chart-box { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 4px 20px rgba(0,82,217,0.08); border: 1px solid rgba(0,82,217,0.06); }
.tc-chart-box h3 { margin: 0 0 18px 0; font-size: 16px; color: #1a1a1a; font-weight: 700; }
.tc-chart-box .chart-wrap { height: 280px; position: relative; }
.tc-chart-box .chart-wrap.sm { height: 240px; }
.tc-dash-row { display: grid; grid-template-columns: 1fr 1.2fr; gap: 22px; margin-bottom: 28px; }
.tc-insight-box { background: linear-gradient(135deg, #fff 0%, #f8faff 100%); border-radius: 16px; padding: 24px; box-shadow: 0 4px 20px rgba(0,82,217,0.08); margin-bottom: 28px; }
.tc-insight-box h3 { margin: 0 0 16px 0; font-size: 16px; font-weight: 700; color: #1a1a1a; }
.tc-insight-box ul { margin: 0; padding-left: 22px; color: #444; line-height: 1.9; font-size: 14px; }
.tc-table-wrap { overflow-x: auto; }
.tc-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.tc-table th, .tc-table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #eee; }
.tc-table th { background: #f5f7fa; color: #5c6b7a; font-weight: 600; }
.tc-table .pct { font-weight: 700; color: #0052D9; }
.tc-top5-list { list-style: none; padding: 0; margin: 0; }
.tc-top5-list li { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-radius: 10px; margin-bottom: 8px; background: #f8fafc; border: 1px solid #eee; }
.tc-top5-list .rank { width: 28px; height: 28px; border-radius: 8px; background: linear-gradient(135deg, #0052D9, #2680eb); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; flex-shrink: 0; }
.tc-top5-list .name { font-weight: 600; color: #1a1a1a; }
.tc-top5-list .stats { font-size: 12px; color: #8a939d; }
.tc-cards-sm { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 28px; }
.tc-card-sm { background: #fff; border-radius: 12px; padding: 18px 20px; box-shadow: 0 2px 14px rgba(0,82,217,0.06); display: flex; align-items: center; gap: 14px; }
.tc-card-sm .icon-sm { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.tc-card-sm .icon-sm.a { background: #e6f0ff; color: #0052D9; }
.tc-card-sm .icon-sm.b { background: #e6f9f0; color: #00a870; }
.tc-card-sm .icon-sm.c { background: #fff4e6; color: #e37318; }
.tc-card-sm .icon-sm.d { background: #f3e8ff; color: #7c3aed; }
.tc-card-sm .label-sm { font-size: 12px; color: #8a939d; }
.tc-card-sm .value-sm { font-size: 20px; font-weight: 700; color: #1a1a1a; }
.tc-iframe-wrap { background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,82,217,0.1); border: 1px solid rgba(0,82,217,0.08); height: 520px; }
.tc-iframe-wrap iframe { width: 100%; height: 100%; border: 0; }
/* 学生明细：与整体驾驶舱同标准，详实美观 */
.detail-layout { display: flex; gap: 24px; min-height: 640px; align-items: stretch; }
.detail-left {
  width: 340px; flex-shrink: 0;
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 4px 20px rgba(0,82,217,0.08);
  padding: 24px;
  border: 1px solid rgba(0,82,217,0.08);
  display: flex; flex-direction: column;
}
.detail-left .detail-left-title { font-size: 16px; font-weight: 700; color: #1a1a1a; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; }
.detail-left .detail-left-title i { color: #1565c0; font-size: 18px; }
.detail-left .detail-left-desc { font-size: 13px; color: #64748b; margin-bottom: 20px; line-height: 1.5; }
.detail-left .detail-activity-wrap { margin-bottom: 16px; }
.detail-left .detail-activity-wrap label { font-size: 12px; color: #64748b; display: block; margin-bottom: 6px; }
.detail-left .detail-activity-wrap select {
  width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px;
  background: #f8fafc; color: #1e293b;
}
.detail-search { display: flex; gap: 10px; margin-bottom: 20px; }
.detail-search input {
  flex: 1; padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px;
  background: #f8fafc; transition: border-color 0.2s, box-shadow 0.2s;
}
.detail-search input:focus { outline: none; border-color: #1565c0; box-shadow: 0 0 0 3px rgba(21,101,192,0.12); background: #fff; }
.detail-search button {
  padding: 12px 20px; background: linear-gradient(135deg, #1565c0, #1976d2); color: #fff; border: none;
  border-radius: 10px; cursor: pointer; font-size: 14px; font-weight: 600;
  display: flex; align-items: center; gap: 8px; transition: transform 0.2s, box-shadow 0.2s;
}
.detail-search button:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(21,101,192,0.35); }
.detail-student-list { list-style: none; padding: 0; margin: 0; flex: 1; overflow-y: auto; }
.detail-student-list li {
  padding: 14px 16px; border-radius: 12px; margin-bottom: 10px; cursor: pointer;
  display: flex; align-items: center; gap: 14px; transition: all 0.2s;
  background: #f8fafc; border: 1px solid #e2e8f0;
}
.detail-student-list li:hover { background: #eef4ff; border-color: rgba(21,101,192,0.25); }
.detail-student-list li.active { background: linear-gradient(135deg, #e3f2fd, #e8f0fe); border-color: #1565c0; box-shadow: 0 2px 12px rgba(21,101,192,0.15); }
.detail-student-list .avatar {
  width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #1565c0, #42a5f5);
  color: #fff; font-weight: 700; font-size: 15px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.detail-student-list li.active .avatar { background: linear-gradient(135deg, #0d47a1, #1565c0); }
.detail-student-list .name { font-weight: 700; color: #1a1a1a; font-size: 14px; }
.detail-student-list .meta { font-size: 12px; color: #64748b; margin-top: 2px; }
.detail-student-list .badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.detail-student-list .badge { font-size: 11px; padding: 2px 8px; border-radius: 6px; background: #e0f2fe; color: #0369a1; }
.detail-student-list .badge.ann { background: #fef3c7; color: #b45309; }
.detail-student-list .badge.chat { background: #f3e8ff; color: #7c3aed; }
.detail-right {
  flex: 1; background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,82,217,0.08);
  padding: 28px; border: 1px solid rgba(0,82,217,0.08); overflow-y: auto;
}
.detail-placeholder {
  text-align: center; padding: 80px 32px; color: #64748b;
  background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 16px; border: 2px dashed #cbd5e1;
}
.detail-placeholder .icon-wrap { width: 80px; height: 80px; margin: 0 auto 24px; border-radius: 20px; background: linear-gradient(135deg, #e0f2fe, #bae6fd); color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 36px; }
.detail-placeholder h3 { font-size: 18px; font-weight: 700; color: #334155; margin-bottom: 12px; }
.detail-placeholder p { font-size: 14px; line-height: 1.6; margin-bottom: 24px; }
.detail-placeholder .tips { text-align: left; max-width: 320px; margin: 0 auto; font-size: 13px; color: #64748b; line-height: 1.8; }
.detail-placeholder .tips span { display: block; padding-left: 20px; position: relative; }
.detail-placeholder .tips span::before { content: '•'; position: absolute; left: 0; color: #94a3b8; }
.detail-main .detail-hero { margin-bottom: 28px; padding-bottom: 24px; border-bottom: 2px solid #e2e8f0; }
.detail-main .detail-hero h2 { font-size: 22px; font-weight: 700; color: #1a1a1a; margin: 0 0 8px 0; display: flex; align-items: center; gap: 12px; }
.detail-main .detail-hero h2 i { color: #1565c0; font-size: 24px; }
.detail-main .detail-hero .username { font-size: 14px; color: #64748b; }
.detail-main .detail-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin: 24px 0; }
.detail-main .detail-card {
  background: #fff; border-radius: 14px; padding: 20px; text-align: center;
  box-shadow: 0 2px 12px rgba(0,82,217,0.06); border: 1px solid #e2e8f0;
  transition: transform 0.2s, box-shadow 0.2s;
}
.detail-main .detail-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,82,217,0.12); }
.detail-main .detail-card .dc-icon { width: 44px; height: 44px; margin: 0 auto 12px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
.detail-main .detail-card .dc-icon.blue { background: #e0f2fe; color: #0284c7; }
.detail-main .detail-card .dc-icon.green { background: #d1fae5; color: #059669; }
.detail-main .detail-card .dc-icon.purple { background: #ede9fe; color: #7c3aed; }
.detail-main .detail-card .dc-icon.orange { background: #ffedd5; color: #ea580c; }
.detail-main .detail-card .dc-value { font-size: 26px; font-weight: 800; color: #1a1a1a; }
.detail-main .detail-card .dc-label { font-size: 12px; color: #64748b; margin-top: 4px; }
.detail-main .detail-block {
  margin-top: 28px; padding: 24px; background: #f8fafc; border-radius: 16px;
  border: 1px solid #e2e8f0; box-shadow: 0 2px 12px rgba(0,0,0,0.03);
}
.detail-main .detail-block h4 { font-size: 16px; font-weight: 700; color: #1a1a1a; margin: 0 0 16px 0; display: flex; align-items: center; gap: 10px; }
.detail-main .detail-block h4 i { color: #1565c0; font-size: 18px; }
.detail-main .detail-list { list-style: none; padding: 0; margin: 0; }
.detail-main .detail-list li {
  padding: 14px 16px; background: #fff; border-radius: 10px; margin-bottom: 10px; font-size: 14px; color: #334155;
  border: 1px solid #e2e8f0; transition: background 0.2s;
}
.detail-main .detail-list li:hover { background: #f1f5f9; }
.detail-main .detail-table { width: 100%; border-collapse: collapse; font-size: 14px; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,0.05); }
.detail-main .detail-table th, .detail-main .detail-table td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #e2e8f0; }
.detail-main .detail-table th { background: #f1f5f9; color: #475569; font-weight: 600; }
.detail-main .detail-table tr:hover td { background: #f8fafc; }
.wordcloud-wrap { width: 100%; height: 220px; display: flex; align-items: center; justify-content: center; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 16px; }
/* 学生阅读进度实时看板 */
.progress-dashboard-wrap { background: #fff; border-radius: 16px; padding: 24px; margin-bottom: 24px; box-shadow: 0 4px 20px rgba(0,82,217,0.08); border: 1px solid rgba(0,82,217,0.08); }
.progress-dashboard-title { margin: 0 0 8px 0; font-size: 17px; font-weight: 700; color: #1a1a1a; display: flex; align-items: center; gap: 10px; }
.progress-dashboard-desc { margin: 0 0 16px 0; font-size: 13px; color: #64748b; line-height: 1.5; }
.progress-toolbar { margin-bottom: 16px; }
.progress-toolbar-inner { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.progress-toolbar-label { font-size: 14px; color: #475569; white-space: nowrap; }
.progress-select-activity { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; min-width: 180px; }
.progress-search-input { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; width: 180px; }
.progress-search-btn { padding: 8px 16px; background: linear-gradient(135deg, #1565c0, #1976d2); color: #fff; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; }
.progress-search-btn:hover { opacity: 0.95; }
.progress-table .col-check { width: 44px; text-align: center; }
.progress-table .progress-pages { color: #1565c0; font-weight: 600; }
.progress-table tr.active-row { background: #e3f2fd; }
.btn-detail-link { color: #1565c0; font-weight: 600; text-decoration: none; }
.btn-detail-link:hover { text-decoration: underline; }
.progress-export-actions { margin-top: 16px; }
.btn-batch-export { background: linear-gradient(135deg, #00a870, #059669); color: #fff; border: none; padding: 12px 24px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; }
.btn-batch-export:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,168,112,0.4); }
.btn-batch-export:disabled { opacity: 0.5; cursor: not-allowed; }
/* 实验交互详情复盘 */
.review-section { margin-top: 28px; padding: 24px; background: #f8fafc; border-radius: 16px; border: 1px solid #e2e8f0; }
.review-section h4 { margin: 0 0 16px 0; font-size: 16px; font-weight: 700; color: #1a1a1a; display: flex; align-items: center; gap: 10px; }
.review-section h4 i.fa-comments { color: #7c3aed; }
.review-section h4 i.fa-highlighter { color: #e37318; }
.review-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 16px; margin-bottom: 20px; }
.review-filters label { font-size: 14px; color: #475569; }
.review-filters select { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; }
.review-export-btns { display: flex; gap: 12px; flex-wrap: wrap; }
.review-export-btns a { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; font-size: 14px; font-weight: 600; text-decoration: none; transition: transform 0.2s; }
.review-export-btns .btn-export-excel { background: linear-gradient(135deg, #00a870, #059669); color: #fff; }
.review-export-btns .btn-export-txt { background: linear-gradient(135deg, #0ea5e9, #0284c7); color: #fff; }
.review-export-btns a:hover { transform: translateY(-1px); }
.review-table { width: 100%; border-collapse: collapse; font-size: 14px; background: #fff; border-radius: 12px; overflow: hidden; margin-top: 12px; }
.review-table th, .review-table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; }
.review-table th { background: #f1f5f9; color: #475569; font-weight: 600; }
.review-table .cell-content { max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
/* 对话冒泡 */
.chat-bubbles-wrap { max-height: 400px; overflow-y: auto; padding: 12px 0; }
.chat-bubble { margin-bottom: 14px; max-width: 85%; clear: both; }
.chat-bubble-user { float: left; text-align: left; }
.chat-bubble-ai { float: right; text-align: right; }
.chat-bubble-user .chat-bubble-content { background: #e3f2fd; color: #1565c0; border-radius: 14px 14px 14px 4px; padding: 10px 14px; display: inline-block; text-align: left; }
.chat-bubble-ai .chat-bubble-content { background: #f3e8ff; color: #5b21b6; border-radius: 14px 14px 4px 14px; padding: 10px 14px; display: inline-block; text-align: left; }
.chat-bubble-label { font-size: 11px; color: #64748b; margin-bottom: 4px; }
.chat-bubble-ai .chat-bubble-label { text-align: right; }
.chat-bubble-time { font-size: 11px; color: #94a3b8; margin-top: 4px; }
.chat-bubble-ai .chat-bubble-time { text-align: right; }
.chat-bubbles-wrap::after { content: ''; display: table; clear: both; }
</style>

<div class="dashboard-fullscreen">
    <header class="tc-header">
        <div>
            <h1><i class="fa-solid fa-gauge-high"></i> 学情看板 <?php echo s($course->shortname); ?> · 学情驾驶舱</h1>
            <div class="sub"><?php echo $tab === 'overview' ? '实时数据监控中心' : '按学生查看学情'; ?></div>
        </div>
        <a href="<?php echo $CFG->wwwroot; ?>/course/view.php?id=<?php echo $courseid; ?>" class="btn-back"><i class="fa-solid fa-chevron-left"></i> 返回课程</a>
    </header>

    <div class="tc-tabs">
        <a href="?courseid=<?php echo $courseid; ?>&id=<?php echo $id; ?>&tab=overview" class="<?php echo $tab === 'overview' ? 'active' : ''; ?>">整体驾驶舱</a>
        <a href="?courseid=<?php echo $courseid; ?>&id=<?php echo $id; ?>&tab=detail" class="<?php echo $tab === 'detail' ? 'active' : ''; ?>">学生明细</a>
    </div>

    <div class="tc-body">
<?php if ($tab === 'overview') { ?>
        <div class="tc-cards">
            <div class="tc-card"><div class="icon-wrap blue"><i class="fa-solid fa-user-graduate"></i></div><div class="label">参与学生</div><div class="value"><?php echo (int)$overview_student_count; ?></div><div class="unit">人</div></div>
            <div class="tc-card"><div class="icon-wrap green"><i class="fa-solid fa-book-open"></i></div><div class="label">累计阅读</div><div class="value green"><?php echo (int)$overview_total_read; ?></div><div class="unit">分钟</div></div>
            <div class="tc-card"><div class="icon-wrap orange"><i class="fa-solid fa-pen-fancy"></i></div><div class="label">标注与批注</div><div class="value orange"><?php echo (int)$overview_total_ann; ?></div><div class="unit">条</div></div>
            <div class="tc-card"><div class="icon-wrap purple"><i class="fa-solid fa-robot"></i></div><div class="label">AI 交互</div><div class="value purple"><?php echo (int)$overview_total_chat; ?></div><div class="unit">条</div></div>
        </div>
        <div class="tc-cards-sm">
            <div class="tc-card-sm"><div class="icon-sm a"><i class="fa-solid fa-chart-pie"></i></div><div><div class="label-sm">课程参与率</div><div class="value-sm"><?php echo (float)$overview_participation_pct; ?>%</div></div></div>
            <div class="tc-card-sm"><div class="icon-sm b"><i class="fa-solid fa-clock"></i></div><div><div class="label-sm">人均阅读（有进度学生）</div><div class="value-sm"><?php echo (int)$overview_avg_read; ?> 分钟</div></div></div>
            <div class="tc-card-sm"><div class="icon-sm c"><i class="fa-solid fa-pen-nib"></i></div><div><div class="label-sm">人均标注</div><div class="value-sm"><?php echo (float)$overview_avg_ann; ?> 条</div></div></div>
            <div class="tc-card-sm"><div class="icon-sm d"><i class="fa-solid fa-comment-dots"></i></div><div><div class="label-sm">人均 AI 对话</div><div class="value-sm"><?php echo (float)$overview_avg_chat; ?> 条</div></div></div>
        </div>
        <div class="tc-chart-row-3">
            <div class="tc-chart-box">
                <h3><i class="fa-solid fa-robot"></i> 与各智能体交互分布</h3>
                <div class="chart-wrap"><canvas id="chart-agent"></canvas></div>
            </div>
            <div class="tc-chart-box">
                <h3><i class="fa-solid fa-list-check"></i> 各任务参与度</h3>
                <div class="chart-wrap"><canvas id="chart-activity"></canvas></div>
            </div>
            <div class="tc-chart-box">
                <h3><i class="fa-solid fa-chart-column"></i> 学生阅读时长分布</h3>
                <div class="chart-wrap sm"><canvas id="chart-read-buckets"></canvas></div>
            </div>
        </div>
        <div class="tc-dash-row">
            <div class="tc-chart-box">
                <h3><i class="fa-solid fa-trophy"></i> 活跃学生 TOP5</h3>
                <ul class="tc-top5-list">
                    <?php foreach (array_slice($overview_top_students, 0, 5) as $i => $s) { ?>
                    <li><span class="rank"><?php echo $i + 1; ?></span><span class="name"><?php echo s($s->name); ?></span><span class="stats">阅读 <?php echo (int)$s->read_min; ?> 分钟 · 标注 <?php echo (int)$s->ann; ?> · AI <?php echo (int)$s->chat; ?> 条</span></li>
                    <?php } ?>
                    <?php if (empty($overview_top_students)) { ?><li style="justify-content:center; color:#8a939d;">暂无数据</li><?php } ?>
                </ul>
            </div>
            <div class="tc-chart-box">
                <h3><i class="fa-solid fa-clipboard-list"></i> 任务完成概况</h3>
                <div class="tc-table-wrap">
                    <table class="tc-table">
                        <thead><tr><th>任务名称</th><th>参与人数</th><th>参与率</th><th>总阅读(分钟)</th><th>标注数</th><th>AI 交互</th></tr></thead>
                        <tbody>
                        <?php foreach ($overview_activity_summary as $row) { ?>
                        <tr><td><?php echo s($row->name); ?></td><td><?php echo (int)$row->participated; ?></td><td class="pct"><?php echo (float)$row->participation_pct; ?>%</td><td><?php echo (int)$row->read_min; ?></td><td><?php echo (int)$row->ann; ?></td><td><?php echo (int)$row->chat; ?></td></tr>
                        <?php } ?>
                        <?php if (empty($overview_activity_summary)) { ?><tr><td colspan="6" style="text-align:center; color:#8a939d;">暂无任务数据</td></tr><?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="tc-insight-box">
            <h3><i class="fa-solid fa-lightbulb"></i> 数据洞察</h3>
            <ul>
                <li>本课程共 <strong><?php echo (int)$overview_student_count; ?></strong> 名学生，已有 <strong><?php echo (int)$overview_students_with_any; ?></strong> 名产生阅读/标注或 AI 交互记录，参与率 <strong><?php echo (float)$overview_participation_pct; ?>%</strong>。</li>
                <li>累计阅读 <strong><?php echo (int)$overview_total_read; ?></strong> 分钟，标注 <strong><?php echo (int)$overview_total_ann; ?></strong> 条，与智能体对话 <strong><?php echo (int)$overview_total_chat; ?></strong> 条，反映整体学习投入与 AI 使用情况。</li>
                <li>下方嵌入报表可进一步查看按时间、任务、学生的细粒度分析，建议关注参与率偏低的任务并做学情干预。</li>
            </ul>
        </div>
        <div class="tc-iframe-wrap">
            <iframe src="<?php echo s($iframeUrl); ?>" frameborder="0" width="100%" height="100%" allowtransparency></iframe>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>
        (function(){
            var agentData = <?php echo $overview_agent_chart_json; ?>;
            var colors = ['#0052D9','#00a870','#e37318','#7c3aed','#0ea5e9','#f59e0b'];
            if (agentData.length) {
                new Chart(document.getElementById('chart-agent'), {
                    type: 'doughnut',
                    data: { labels: agentData.map(function(d){ return d.label; }), datasets: [{ data: agentData.map(function(d){ return d.value; }), backgroundColor: colors.slice(0, agentData.length), borderWidth: 0 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
                });
            } else { document.getElementById('chart-agent').parentNode.innerHTML = '<p style="color:#8a939d;text-align:center;padding:40px;">暂无智能体交互数据</p>'; }
            var actData = <?php echo $overview_activity_chart_json; ?>;
            if (actData.length) {
                new Chart(document.getElementById('chart-activity'), {
                    type: 'bar',
                    data: {
                        labels: actData.map(function(d){ return d.label; }),
                        datasets: [
                            { label: '阅读(分钟)', data: actData.map(function(d){ return d.read_min; }), backgroundColor: 'rgba(0,82,217,0.7)' },
                            { label: '标注数', data: actData.map(function(d){ return d.ann; }), backgroundColor: 'rgba(227,115,24,0.7)' },
                            { label: 'AI交互', data: actData.map(function(d){ return d.chat; }), backgroundColor: 'rgba(124,58,237,0.7)' }
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: false }, y: { beginAtZero: true } }, plugins: { legend: { position: 'top' } } }
                });
            } else { document.getElementById('chart-activity').parentNode.innerHTML = '<p style="color:#8a939d;text-align:center;padding:40px;">暂无任务数据</p>'; }
            var bucketLabels = <?php echo $overview_read_buckets_labels_json; ?>;
            var bucketData = <?php echo $overview_read_buckets_json; ?>;
            var bucketColors = ['#0052D9','#2680eb','#00a870','#e37318'];
            if (document.getElementById('chart-read-buckets') && bucketData.length) {
                new Chart(document.getElementById('chart-read-buckets'), {
                    type: 'bar',
                    data: { labels: bucketLabels, datasets: [{ label: '学生数', data: bucketData, backgroundColor: bucketColors }] },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { beginAtZero: true } }, plugins: { legend: { display: false } } }
                });
            } else if (document.getElementById('chart-read-buckets')) {
                document.getElementById('chart-read-buckets').parentNode.innerHTML = '<p style="color:#8a939d;text-align:center;padding:40px;">暂无阅读分布数据</p>';
            }
        })();
        </script>
<?php } else { ?>
<?php if (!$aireader) { ?>
<div class="alert alert-info">请先在课程中添加「学术论文AI伴读」活动，再查看学生明细。</div>
<?php } else { ?>
<!-- 学生阅读进度实时看板：按任务区分 + 任务筛选 + 搜索 -->
<div class="progress-dashboard-wrap">
    <h3 class="progress-dashboard-title"><i class="fa-solid fa-table-list"></i> 学生阅读进度实时看板</h3>
    <p class="progress-dashboard-desc">下表按「任务/活动」区分每位学生在不同任务下的阅读与交互数据；可先选择任务、搜索学生，再点击「查看详情」跳转到右侧学情详情。</p>
    <!-- 筛选栏：选择任务 + 搜索 -->
    <form method="get" class="progress-toolbar">
        <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
        <input type="hidden" name="tab" value="detail">
        <div class="progress-toolbar-inner">
            <label class="progress-toolbar-label">选择任务/活动：</label>
            <select name="id" onchange="this.form.submit()" class="progress-select-activity">
                <?php foreach ($aireader2_cms as $cid => $c) {
                    $sel = ($id == $cid) ? ' selected' : '';
                    echo '<option value="'.(int)$cid.'"'.$sel.'>'.s($c->name).'</option>';
                } ?>
            </select>
            <label class="progress-toolbar-label">搜索学号/姓名：</label>
            <input type="text" name="search" value="<?php echo s($search); ?>" placeholder="输入学号或姓名" class="progress-search-input">
            <button type="submit" class="progress-search-btn"><i class="fa-solid fa-magnifying-glass"></i> 搜索</button>
        </div>
    </form>
    <form id="form-batch-export" method="get" action="<?php echo $CFG->wwwroot; ?>/mod/aireader2/dashboard.php" target="_blank">
        <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="tab" value="detail">
        <input type="hidden" name="export_batch" value="1">
        <div class="tc-table-wrap">
            <table class="tc-table progress-table">
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" id="progress-select-all" title="全选"></th>
                        <th>学号</th>
                        <th>姓名</th>
                        <th>任务/活动</th>
                        <th>阅读时长</th>
                        <th>进度(页/总)</th>
                        <th>交互轮数</th>
                        <th>笔记总数</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $progress_rows_shown = 0;
                foreach ($progress_table_rows as $s) {
                    if ((int)$s->cm_id !== (int)$id) continue; // 只显示当前选中任务下的行
                    $u = $s->user;
                    if ($search !== '' && stripos(fullname($u), $search) === false && stripos($u->username, $search) === false) continue;
                    $progress_str = $s->total_pages > 0 ? (int)$s->last_page . ' / ' . (int)$s->total_pages : (int)$s->last_page . ' / —';
                    $detail_url = new moodle_url('/mod/aireader2/dashboard.php', ['courseid' => $courseid, 'id' => $s->cm_id, 'tab' => 'detail', 'uid' => $u->id]);
                    if ($search !== '') $detail_url->param('search', $search);
                    $detail_url_str = $detail_url->out(false) . '#detail-right';
                    $progress_rows_shown++;
                ?>
                    <tr class="<?php echo ($uid == $u->id && (int)$id == (int)$s->cm_id) ? ' active-row' : ''; ?>">
                        <td class="col-check"><input type="checkbox" name="uid[]" value="<?php echo (int)$u->id; ?>" class="progress-row-cb"></td>
                        <td><?php echo s($u->username); ?></td>
                        <td><?php echo s(fullname($u)); ?></td>
                        <td><?php echo s($s->cm_name); ?></td>
                        <td><?php echo (int)$s->read_minutes; ?>min</td>
                        <td class="progress-pages"><?php echo $progress_str; ?></td>
                        <td><?php echo (int)$s->chat_count; ?>轮</td>
                        <td><?php echo (int)$s->ann_count; ?>条</td>
                        <td><a href="<?php echo $detail_url_str; ?>" class="btn-detail-link">查看详情</a></td>
                    </tr>
                <?php } ?>
                <?php if ($progress_rows_shown === 0) { ?>
                    <tr><td colspan="9" style="text-align:center; color:#8a939d;">暂无数据（请选择任务或调整搜索）</td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <div class="progress-export-actions">
            <button type="submit" id="btn-batch-export" class="btn-batch-export" disabled>批量导出已选同学数据</button>
        </div>
    </form>
</div>

<div class="detail-layout">
    <div class="detail-left">
        <div class="detail-left-title"><i class="fa-solid fa-user-graduate"></i> 按学生查看学情</div>
        <p class="detail-left-desc">在左侧选择或搜索学生，右侧将展示该生的阅读时长、标注、AI 交互及任务完成情况。</p>
        <?php if (count($aireader2_cms) > 1) { ?>
        <div class="detail-activity-wrap">
            <label><i class="fa-solid fa-list-check"></i> 当前活动</label>
            <form method="get">
                <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
                <input type="hidden" name="tab" value="detail">
                <select name="id" onchange="this.form.submit()">
                    <?php foreach ($aireader2_cms as $cid => $c) {
                        $sel = ($cid == $id) ? ' selected' : '';
                        echo '<option value="'.$cid.'"'.$sel.'>'.s($c->name).'</option>';
                    } ?>
                </select>
            </form>
        </div>
        <?php } ?>
        <form method="get" class="detail-search">
            <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <input type="hidden" name="tab" value="detail">
            <input type="text" name="search" placeholder="输入姓名或学号搜索" value="<?php echo s($search); ?>">
            <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> 搜索</button>
        </form>
        <ul class="detail-student-list">
            <?php
            foreach ($students_for_list as $s) {
                $u = $s->user;
                if ($search !== '' && stripos(fullname($u), $search) === false && stripos($u->username, $search) === false) continue;
                $url = new moodle_url('/mod/aireader2/dashboard.php', ['courseid' => $courseid, 'id' => $id, 'tab' => 'detail', 'uid' => $u->id]);
                if ($search !== '') $url->param('search', $search);
                $active = ($uid == $u->id) ? ' active' : '';
                $first_char = mb_substr(trim(fullname($u)), 0, 1);
                if ($first_char === '') $first_char = '?';
            ?>
            <li class="<?php echo $active; ?>" onclick="location.href='<?php echo $url->out(false); ?>';">
                <span class="avatar"><?php echo s($first_char); ?></span>
                <div style="flex:1; min-width:0;">
                    <div class="name"><?php echo fullname($u); ?></div>
                    <div class="meta"><?php echo s($u->username); ?></div>
                    <div class="badges">
                        <span class="badge">阅读 <?php echo (int)$s->read_minutes; ?> 分钟</span>
                        <?php if ($s->ann_count > 0) { ?><span class="badge ann">标注 <?php echo (int)$s->ann_count; ?></span><?php } ?>
                        <?php if ($s->chat_count > 0) { ?><span class="badge chat">AI <?php echo (int)$s->chat_count; ?> 条</span><?php } ?>
                    </div>
                </div>
            </li>
            <?php } ?>
        </ul>
    </div>
    <div class="detail-right" id="detail-right">
        <?php if (!$detail_user) { ?>
        <div class="detail-placeholder">
            <div class="icon-wrap"><i class="fa-solid fa-user-plus"></i></div>
            <h3>选择学生查看学情</h3>
            <p>在左侧列表中点击一名学生，即可在右侧查看其阅读时长、标注、AI 交互及任务完成情况。</p>
            <div class="tips">
                <span>支持按姓名或学号搜索</span>
                <span>可切换「当前活动」查看不同任务下的学情</span>
                <span>右侧将展示汇总卡片、任务明细、智能体交互与词云等</span>
            </div>
        </div>
        <?php } else {
            $student_name = fullname($detail_user);
        ?>
        <div class="detail-main">
            <div class="detail-hero">
                <h2><i class="fa-solid fa-user-check"></i> <?php echo s($student_name); ?></h2>
                <div class="username">学号 / 用户名：<?php echo s($detail_user->username); ?></div>
            </div>

            <div class="detail-cards">
                <div class="detail-card">
                    <div class="dc-icon blue"><i class="fa-solid fa-book-open"></i></div>
                    <div class="dc-value"><?php echo (int)$detail_total_read_min; ?></div>
                    <div class="dc-label">累计阅读（分钟）</div>
                </div>
                <div class="detail-card">
                    <div class="dc-icon green"><i class="fa-solid fa-pen-fancy"></i></div>
                    <div class="dc-value"><?php echo (int)$detail_total_ann; ?></div>
                    <div class="dc-label">标注与批注数</div>
                </div>
                <div class="detail-card">
                    <div class="dc-icon purple"><i class="fa-solid fa-robot"></i></div>
                    <div class="dc-value"><?php echo (int)$detail_total_chat; ?></div>
                    <div class="dc-label">AI 交互条数</div>
                </div>
                <div class="detail-card">
                    <div class="dc-icon orange"><i class="fa-solid fa-calendar-days"></i></div>
                    <div class="dc-value"><?php echo (int)$detail_access_days; ?></div>
                    <div class="dc-label">有进度天数</div>
                </div>
            </div>

            <div class="detail-block">
                <h4><i class="fa-solid fa-clipboard-list"></i> 任务明细</h4>
                <table class="detail-table">
                    <thead><tr><th>任务名称</th><th>阅读时长</th><th>标注/批注数</th><th>AI 交互</th><th>完成状态</th></tr></thead>
                    <tbody>
                    <?php foreach ($aireader2_cms as $cid => $c) {
                        $aid = $c->instance;
                        $p = isset($detail_progress[$aid]) ? $detail_progress[$aid] : null;
                        $read_min = $p && isset($p->total_read_seconds) ? floor($p->total_read_seconds / 60) : 0;
                        $ann = isset($detail_annotations[$aid]) ? $detail_annotations[$aid] : 0;
                        $chat = $DB->count_records('aireader2_chat_log', ['aireader2id' => $aid, 'userid' => $uid]);
                    ?>
                        <tr><td><?php echo s($c->name); ?></td><td><?php echo (int)$read_min; ?>分钟</td><td><?php echo (int)$ann; ?></td><td><?php echo (int)$chat; ?>条</td><td>进行中</td></tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>

            <div class="detail-block">
                <h4><i class="fa-solid fa-chart-pie"></i> 与各智能体交互分布</h4>
                <?php if (!empty($detail_chat_by_agent)) {
                    $detail_agent_chart_json = json_encode(array_values(array_map(function($k, $v) { return ['label' => $k, 'value' => $v]; }, array_keys($detail_chat_by_agent), array_values($detail_chat_by_agent))), JSON_UNESCAPED_UNICODE);
                ?>
                <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
                <div class="chart-wrap" style="height:220px; position:relative;"><canvas id="chart-detail-agent"></canvas></div>
                <p style="font-size:12px; color:#999; margin-top:8px;">有进度记录的天数: <?php echo (int)$detail_access_days; ?>天(近似反映使用频率)</p>
                <script>
                (function(){
                    var data = <?php echo $detail_agent_chart_json; ?>;
                    if (data.length && document.getElementById('chart-detail-agent')) {
                        if (typeof Chart !== 'undefined') {
                            new Chart(document.getElementById('chart-detail-agent'), {
                                type: 'doughnut',
                                data: { labels: data.map(function(d){ return d.label; }), datasets: [{ data: data.map(function(d){ return d.value; }), backgroundColor: ['#0052D9','#00a870','#e37318','#7c3aed','#0ea5e9','#f59e0b'], borderWidth: 0 }] },
                                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
                            });
                        }
                    }
                })();
                </script>
                <?php } else { ?>
                <p style="color:#999;">暂无AI对话记录</p>
                <?php } ?>
            </div>

            <!-- 实验交互详情复盘 -->
            <div class="detail-block review-section">
                <h4><i class="fa-solid fa-filter"></i> 筛选与导出</h4>
                <div class="review-filters">
                    <label>筛选周次：</label>
                    <form method="get" style="display:inline-flex; align-items:center; gap:10px;" id="form-review-week">
                        <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                        <input type="hidden" name="tab" value="detail">
                        <input type="hidden" name="uid" value="<?php echo (int)$uid; ?>">
                        <?php if ($search !== '') { ?><input type="hidden" name="search" value="<?php echo s($search); ?>"><?php } ?>
                        <select name="detail_week" onchange="this.form.submit()">
                            <?php foreach ($detail_weeks_options as $wk_val => $wk_label) {
                                $sel = ($detail_week === $wk_val || ($detail_week === '' && $wk_val === '')) ? ' selected' : '';
                                echo '<option value="'.s($wk_val).'"'.$sel.'>'.s($wk_label).'</option>';
                            } ?>
                        </select>
                    </form>
                    <span class="text-muted" style="font-size:12px; color:#64748b;">(切换周次查看该生不同阶段数据)</span>
                </div>
                <div class="review-export-btns">
                    <a href="<?php echo $CFG->wwwroot; ?>/mod/aireader2/dashboard.php?courseid=<?php echo $courseid; ?>&id=<?php echo $id; ?>&tab=detail&uid=<?php echo $uid; ?>&export_student_excel=1&detail_week=<?php echo urlencode($detail_week); ?>" class="btn-export-excel" target="_blank"><i class="fa-solid fa-file-excel"></i> 导出该生本周 Excel</a>
                    <a href="<?php echo $CFG->wwwroot; ?>/mod/aireader2/dashboard.php?courseid=<?php echo $courseid; ?>&id=<?php echo $id; ?>&tab=detail&uid=<?php echo $uid; ?>&export_student_txt=1&detail_week=<?php echo urlencode($detail_week); ?>" class="btn-export-txt" target="_blank"><i class="fa-solid fa-file-lines"></i> 导出质性文本 (.txt)</a>
                </div>

                <h4 style="margin-top:24px;"><i class="fa-solid fa-comments"></i> 对话与提问（冒泡形式）</h4>
                <div class="review-filters" style="margin-bottom:12px;">
                    <label>筛选智能体：</label>
                    <select id="filter-dialogue-agent" class="progress-select-activity" style="min-width:160px;">
                        <option value="">全部</option>
                        <option value="__user__">学生</option>
                        <?php foreach (array_keys($detail_chat_by_agent) as $agent) { echo '<option value="'.s($agent).'">'.s($agent).'</option>'; } ?>
                    </select>
                </div>
                <div class="chat-bubbles-wrap" id="chat-bubbles-wrap">
                    <?php foreach ($detail_dialogue_log as $row) {
                        $is_user = (isset($row->role) && $row->role === 'user') || (isset($row->sender_type) && $row->sender_type === 'user');
                        $role_label = $is_user ? '学生' : (isset($row->agent_name) ? $row->agent_name : 'AI');
                        $data_agent = $is_user ? '__user__' : (isset($row->agent_name) ? $row->agent_name : '');
                    ?>
                    <div class="chat-bubble <?php echo $is_user ? 'chat-bubble-user' : 'chat-bubble-ai'; ?>" data-agent="<?php echo s($data_agent); ?>">
                        <div class="chat-bubble-label"><?php echo s($role_label); ?></div>
                        <div class="chat-bubble-content"><?php echo nl2br(s($row->content)); ?></div>
                        <div class="chat-bubble-time"><?php echo userdate($row->timecreated, '%Y-%m-%d %H:%M'); ?></div>
                    </div>
                    <?php } ?>
                    <?php if (empty($detail_dialogue_log)) { ?><p class="text-muted" style="text-align:center; padding:24px;">暂无对话记录</p><?php } ?>
                </div>

                <h4 style="margin-top:24px;"><i class="fa-solid fa-highlighter"></i> 标注与笔记（合并高亮/划线）</h4>
                <div class="review-filters" style="margin-bottom:12px;">
                    <label>类型：</label>
                    <select id="filter-ann-type" class="progress-select-activity" style="min-width:120px;">
                        <option value="">全部</option>
                        <option value="highlight">高亮标注</option>
                        <option value="ink">划线批注</option>
                    </select>
                    <label style="margin-left:12px;">是否有笔记：</label>
                    <select id="filter-ann-note" class="progress-select-activity" style="min-width:100px;">
                        <option value="">全部</option>
                        <option value="1">有</option>
                        <option value="0">无</option>
                    </select>
                </div>
                <div class="tc-table-wrap">
                    <table class="review-table" id="annotations-merged-table">
                        <thead><tr><th>序号</th><th>页码</th><th>类型</th><th>高亮/原文</th><th>笔记</th><th>创建时间</th></tr></thead>
                        <tbody>
                        <?php
                        $ann_merged = !empty($detail_annotations_for_table) ? $detail_annotations_for_table : $detail_annotations_list;
                        foreach ($ann_merged as $i => $a) {
                            $quote_raw = isset($a->ann_quote) ? $a->ann_quote : (isset($a->quote) ? $a->quote : '');
                            $atype = isset($a->type) ? $a->type : 'highlight';
                            $type_label = ($atype === 'ink') ? '划线批注' : '高亮标注';
                            $has_note = ($quote_raw !== '' && trim(strip_tags($quote_raw)) !== '') ? '1' : '0';
                        ?>
                            <tr data-ann-type="<?php echo s($atype); ?>" data-has-note="<?php echo $has_note; ?>">
                                <td><?php echo $i + 1; ?></td>
                                <td>P<?php echo (int)$a->page_num; ?></td>
                                <td><?php echo s($type_label); ?></td>
                                <td class="cell-content" title="<?php echo s($quote_raw); ?>"><?php echo s(mb_substr(strip_tags($quote_raw), 0, 80)); ?><?php echo mb_strlen(strip_tags($quote_raw)) > 80 ? '…' : ''; ?></td>
                                <td><?php echo $quote_raw !== '' ? s(mb_substr(strip_tags($quote_raw), 0, 60)) . (mb_strlen(strip_tags($quote_raw)) > 60 ? '…' : '') : '—'; ?></td>
                                <td><?php echo userdate($a->created_at, '%Y-%m-%d %H:%M'); ?></td>
                            </tr>
                        <?php } ?>
                        <?php if (empty($ann_merged)) { ?><tr><td colspan="6" style="text-align:center; color:#8a939d;">暂无标注与笔记</td></tr><?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="detail-block">
                <h4><i class="fa-solid fa-cloud"></i> 标注与提问词云</h4>
                <div class="wordcloud-wrap" id="wordcloud-detail-wrap" data-words="<?php echo s($detail_wordcloud_json); ?>">
                    <canvas id="wordcloud-detail" width="600" height="220"></canvas>
                </div>
                <script>
                (function(){
                    var wrap = document.getElementById('wordcloud-detail-wrap');
                    if (!wrap) return;
                    var data = [];
                    try { data = JSON.parse(wrap.getAttribute('data-words') || '[]'); } catch(e) {}
                    var canvas = document.getElementById('wordcloud-detail');
                    if (!canvas || data.length === 0) { if (canvas) { var ctx = canvas.getContext('2d'); ctx.fillStyle='#999'; ctx.font='14px sans-serif'; ctx.fillText('暂无词云数据', 20, 110); } return; }
                    var ctx = canvas.getContext('2d');
                    var maxW = Math.max.apply(null, data.map(function(d){ return d.weight; }));
                    var x = 30, y = 40, lineH = 28;
                    ctx.fillStyle = '#333';
                    data.slice(0, 30).forEach(function(d) {
                        var size = 12 + Math.round((d.weight / maxW) * 14);
                        ctx.font = size + 'px sans-serif';
                        if (x + ctx.measureText(d.text).width > 560) { x = 30; y += lineH; }
                        ctx.fillStyle = 'hsl(' + (Math.random()*200 + 180) + ', 60%, 40%)';
                        ctx.fillText(d.text, x, y);
                        x += ctx.measureText(d.text).width + 10;
                    });
                })();
                </script>
            </div>
        </div>
        <?php } ?>
    </div>
</div>
<?php } ?>
<?php } ?>
    </div><!-- tc-body -->
</div><!-- dashboard-fullscreen -->

<?php if ($tab === 'detail') { ?>
<script>
(function(){
    function runWhenReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }
    runWhenReady(function() {
        var form = document.getElementById('form-batch-export');
        var btnExport = document.getElementById('btn-batch-export');
        if (!form || !btnExport) return;
        function updateExportBtn() {
            var cbs = form.querySelectorAll('.progress-row-cb');
            var any = false;
            for (var i = 0; i < cbs.length; i++) { if (cbs[i].checked) { any = true; break; } }
            btnExport.disabled = !any;
        }
        form.addEventListener('change', function(e) {
            if (e.target.id === 'progress-select-all') {
                var checked = e.target.checked;
                form.querySelectorAll('.progress-row-cb').forEach(function(cb) { cb.checked = checked; });
            }
            if (e.target.id === 'progress-select-all' || e.target.classList.contains('progress-row-cb')) {
                updateExportBtn();
            }
        });
        updateExportBtn();
    });
    // 若 URL 带 uid（点击了「查看详情」），滚动到右侧详情区
    var m = window.location.search.match(/[?&]uid=(\d+)/);
    if (m && document.getElementById('detail-right')) {
        var el = document.getElementById('detail-right');
        setTimeout(function() { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 100);
    }

    // 对话冒泡：按智能体筛选
    var filterAgent = document.getElementById('filter-dialogue-agent');
    var chatWrap = document.getElementById('chat-bubbles-wrap');
    if (filterAgent && chatWrap) {
        filterAgent.addEventListener('change', function() {
            var val = this.value;
            var bubbles = chatWrap.querySelectorAll('.chat-bubble');
            bubbles.forEach(function(el) {
                var agent = el.getAttribute('data-agent') || '';
                el.style.display = (val === '' || agent === val) ? '' : 'none';
            });
        });
    }
    // 标注与笔记：按类型、是否有笔记筛选
    var filterAnnType = document.getElementById('filter-ann-type');
    var filterAnnNote = document.getElementById('filter-ann-note');
    var annTable = document.getElementById('annotations-merged-table');
    if (annTable) {
        function filterAnnRows() {
            var typeVal = filterAnnType ? filterAnnType.value : '';
            var noteVal = filterAnnNote ? filterAnnNote.value : '';
            var rows = annTable.querySelectorAll('tbody tr[data-ann-type]');
            rows.forEach(function(tr) {
                var t = tr.getAttribute('data-ann-type') || '';
                var n = tr.getAttribute('data-has-note') || '0';
                var showType = (typeVal === '' || t === typeVal);
                var showNote = (noteVal === '' || n === noteVal);
                tr.style.display = (showType && showNote) ? '' : 'none';
            });
        }
        if (filterAnnType) filterAnnType.addEventListener('change', filterAnnRows);
        if (filterAnnNote) filterAnnNote.addEventListener('change', filterAnnRows);
    }
})();
</script>
<?php } ?>

<?php
echo $OUTPUT->footer();
