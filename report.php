<?php
require_once('../../config.php');

// 1. 接收参数
$id = required_param('id', PARAM_INT);
// 使用 PARAM_ALPHANUMEXT 保留下划线，否则 save_structure / save_rule / delete_rule 会被 PARAM_ALPHA 过滤成错误值导致返回 HTML 而非 JSON
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

// 2. 获取基础信息
// 🔥🔥🔥 核心修正：这里必须填 'aireader2'，否则 ID 对不上会报错
if (!$cm = get_coursemodule_from_id('aireader2', $id)) { throw new moodle_exception('invalidcoursemodule'); }
if (!$course = $DB->get_record('course', array('id' => $cm->course))) { throw new moodle_exception('coursemisconf'); }
if (!$aireader = $DB->get_record('aireader2', array('id' => $cm->instance))) { throw new moodle_exception('invalidaireader2id', 'aireader2'); }

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
        $update->id = $aireader->id;
        // 存入数据库前转为 JSON 字符串
        $update->structure = json_encode($new_structure, JSON_UNESCAPED_UNICODE);
        // 顺便更新下配置
        if (isset($payload['resources_json'])) {
            $update->resources_json = json_encode($payload['resources_json'], JSON_UNESCAPED_UNICODE);
        }

        // 🔥 修正表名：aireader2
        $DB->update_record('aireader2', $update);
        send_json_response(['status' => 'success', 'message' => '目录结构已更新']);
    }

    // B. 保存触发规则 (Trigger Rules)
    if ($action === 'save_rule') {
        $rule_data = $payload['rule'];
        
        $record = new stdClass();
        // 🔥 修正字段名：aireader2id
        $record->aireader2id = $aireader->id; 
        $record->section_keyword = $rule_data['section_keyword'];
        $record->trigger_prompt = $rule_data['trigger_prompt'];
        
        if (!empty($rule_data['id'])) {
            // 更新
            $record->id = $rule_data['id'];
            // 🔥 修正表名：aireader2_trigger_rules
            $DB->update_record('aireader2_trigger_rules', $record);
        } else {
            // 新增
            // 🔥 修正表名：aireader2_trigger_rules
            $new_id = $DB->insert_record('aireader2_trigger_rules', $record);
            $record->id = $new_id;
        }
        send_json_response(['status' => 'success', 'data' => $record]);
    }

    // C. 删除触发规则
    if ($action === 'delete_rule') {
        $rule_id = $payload['id'];
        // 🔥 修正表名：aireader2_trigger_rules
        $DB->delete_records('aireader2_trigger_rules', ['id' => $rule_id, 'aireader2id' => $aireader->id]);
        send_json_response(['status' => 'success']);
    }
}

// =================================================================================
// 🎨 前端页面渲染 (Vue 容器)
// =================================================================================

$PAGE->set_url('/mod/aireader2/report.php', ['id' => $id]);
$PAGE->set_title('导读配置中心');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('embedded'); // 全屏：与学情看板一致，隐藏 Moodle 顶栏/侧栏/页脚

echo $OUTPUT->header();

// 全屏样式：与学情看板一致
echo '<style>
body.pagelayout-embedded .block,
body.pagelayout-embedded #block-region-side-pre,
body.pagelayout-embedded #block-region-side-post,
body.pagelayout-embedded nav { display: none !important; }
body.pagelayout-embedded #page { margin: 0; padding: 0; max-width: none; }
body.pagelayout-embedded #page-content { padding: 0; }
.admin-config-fullscreen {
  min-height: 100vh;
  padding: 0;
  font-family: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
  background: #f5f7fa;
}
.admin-config-fullscreen #admin-app { min-height: 100vh; display: flex; flex-direction: column; }
</style>';

// 1. 准备初始数据
// 获取目录结构 (如果为空则给个默认空数组)
$structure_json = $aireader->structure ? $aireader->structure : '[]';

// 获取所有触发规则
// 🔥 修正表名：aireader2_trigger_rules 和字段名 aireader2id
$rules = $DB->get_records('aireader2_trigger_rules', ['aireader2id' => $aireader->id]);
$rules_json = json_encode(array_values($rules));

// 2. 注入 Vue 容器（全屏包裹，与学情看板一致）
echo '<div class="admin-config-fullscreen">';
$back_url = $CFG->wwwroot . '/mod/aireader2/view.php?id=' . $id;
echo '<div id="admin-app"
    data-api-url="'.$CFG->wwwroot.'/mod/aireader2/report.php?id='.$id.'"
    data-structure="'.htmlspecialchars($structure_json, ENT_QUOTES, 'UTF-8').'"
    data-rules="'.htmlspecialchars($rules_json, ENT_QUOTES, 'UTF-8').'"
    data-back-url="'.htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8').'"
>
    <div style="display:flex;align-items:center;justify-content:center;min-height:50vh;flex-direction:column;gap:12px;color:#64748b;">
        <i class="fa fa-spinner fa-spin" style="font-size:32px; color:#1565c0;"></i>
        <p style="font-size:15px;font-weight:500;">正在加载导读配置中心...</p>
    </div>
</div>';
echo '</div>';

// 3. 加载前端资源
$ver = time(); // 开发阶段防止缓存
// 确保路径指向 aireader2 的前端资源
echo '<script type="module" crossorigin src="'.$CFG->wwwroot.'/mod/aireader2/frontend/dist/assets/index.js?v='.$ver.'"></script>';
echo '<link rel="stylesheet" href="'.$CFG->wwwroot.'/mod/aireader2/frontend/dist/assets/main.css?v='.$ver.'">';

echo $OUTPUT->footer();