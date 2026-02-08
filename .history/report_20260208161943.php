<?php
require_once('../../config.php');

// 1. 接收参数
$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

// #region agent log
$logpath = $CFG->dirroot . '/mod/aireader2/.cursor/debug.log';
@file_put_contents($logpath, json_encode(['location'=>'report.php:entry','message'=>'report.php received id','data'=>['id'=>$id],'timestamp'=>time()*1000,'hypothesisId'=>'H2']) . "\n", FILE_APPEND | LOCK_EX);
// #endregion

// 2. 获取基础信息
$cm = get_coursemodule_from_id('aiwriter', $id);
// #region agent log
@file_put_contents($logpath, json_encode(['location'=>'report.php:after_get_cm','message'=>'get_coursemodule_from_id(aiwriter) result','data'=>['id'=>$id,'cm_found'=>!empty($cm),'mod_used'=>'aiwriter'],'timestamp'=>time()*1000,'hypothesisId'=>'H2']) . "\n", FILE_APPEND | LOCK_EX);
// #endregion
if (!$cm) { throw new moodle_exception('invalidcoursemodule'); }
if (!$course = $DB->get_record('course', array('id' => $cm->course))) { throw new moodle_exception('coursemisconf'); }
// 注意：如果你是在开发 aireader2，这里应该是 'aireader2'
if (!$aiwriter = $DB->get_record('aiwriter', array('id' => $cm->instance))) { throw new moodle_exception('invalidinstance', 'aiwriter'); }

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
// 严格检查权限：只有老师能进
require_capability('moodle/course:manageactivities', $context);

// =================================================================================
// 🔌 后端 API 处理 (AJAX)
// =================================================================================

// 辅助函数：返回 JSON
function send_json_response($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    
    // A. 保存目录结构 (Structure JSON)
    if ($action === 'save_structure') {
        $new_structure = $payload['structure']; // 前端传来的数组
        
        $update = new stdClass();
        $update->id = $aiwriter->id;
        // 存入数据库前转为 JSON 字符串
        $update->structure = json_encode($new_structure, JSON_UNESCAPED_UNICODE); 
        // 顺便更新下配置
        if (isset($payload['resources_json'])) {
            $update->resources_json = json_encode($payload['resources_json'], JSON_UNESCAPED_UNICODE);
        }
        
        $DB->update_record('aiwriter', $update);
        send_json_response(['status' => 'success', 'message' => '目录结构已更新']);
    }

    // B. 保存触发规则 (Trigger Rules)
    if ($action === 'save_rule') {
        $rule_data = $payload['rule'];
        
        $record = new stdClass();
        $record->aiwriterid = $aiwriter->id; // 注意：如果是 aireader2，字段名可能是 aireader2id
        $record->section_keyword = $rule_data['section_keyword'];
        $record->trigger_prompt = $rule_data['trigger_prompt'];
        
        if (!empty($rule_data['id'])) {
            // 更新
            $record->id = $rule_data['id'];
            $DB->update_record('aiwriter_trigger_rules', $record);
        } else {
            // 新增
            $new_id = $DB->insert_record('aiwriter_trigger_rules', $record);
            $record->id = $new_id;
        }
        send_json_response(['status' => 'success', 'data' => $record]);
    }

    // C. 删除触发规则
    if ($action === 'delete_rule') {
        $rule_id = $payload['id'];
        $DB->delete_records('aiwriter_trigger_rules', ['id' => $rule_id, 'aiwriterid' => $aiwriter->id]);
        send_json_response(['status' => 'success']);
    }
}

// =================================================================================
// 🎨 前端页面渲染 (Vue 容器)
// =================================================================================

$PAGE->set_url('/mod/aiwriter/report.php', ['id' => $id]);
$PAGE->set_title('导读配置中心');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse'); // 使用嵌入式布局，去掉太大的头部

echo $OUTPUT->header();

// 1. 准备初始数据
// 获取目录结构 (如果为空则给个默认空数组)
$structure_json = $aiwriter->structure ? $aiwriter->structure : '[]';

// 获取所有触发规则
// 注意：表名如果是 aireader2_trigger_rules 请自行替换
$rules = $DB->get_records('aiwriter_trigger_rules', ['aiwriterid' => $aiwriter->id]);
$rules_json = json_encode(array_values($rules));

// 2. 注入 Vue 容器
// 我们使用一个新的 ID 'admin-app'，避免和学生端的混淆
// 将 API URL 传给前端，方便 fetch 调用
echo '
<div id="admin-app"
    data-api-url="'.$CFG->wwwroot.'/mod/aiwriter/report.php?id='.$id.'"
    data-structure="'.htmlspecialchars($structure_json, ENT_QUOTES, 'UTF-8').'"
    data-rules="'.htmlspecialchars($rules_json, ENT_QUOTES, 'UTF-8').'"
>
    <div style="text-align:center; padding: 50px;">
        <i class="fa fa-spinner fa-spin" style="font-size:30px; color:#ccc;"></i>
        <p>正在加载配置控制台...</p>
    </div>
</div>
';

// 3. 加载前端资源
// ⚠️ 注意：这里你需要让 Cursor 帮你写一个新的 Vue 入口文件，或者复用原来的 index.js 但在里面判断挂载点
$ver = time(); // 开发阶段防止缓存
echo '<script type="module" crossorigin src="'.$CFG->wwwroot.'/mod/aiwriter/frontend/dist/assets/index.js?v='.$ver.'"></script>';
echo '<link rel="stylesheet" href="'.$CFG->wwwroot.'/mod/aiwriter/frontend/dist/assets/main.css?v='.$ver.'">';

echo $OUTPUT->footer();