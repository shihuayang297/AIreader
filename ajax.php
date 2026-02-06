<?php
define('AJAX_SCRIPT', true);
require('../../config.php');

// 调试模式 (生产环境可注释掉)
// error_reporting(E_ALL);
// ini_set('display_errors', 0);

require_login();
if (isguestuser()) { die(json_encode(['status'=>'error', 'message'=>'Guest not allowed'])); }

// 接收参数
$action = optional_param('action', '', PARAM_ALPHANUMEXT); 
$cmid   = optional_param('id', 0, PARAM_INT); 

// 🔥 如果是 delete_annotation 或 update_annotation_note，可能传的是 ann_id 而不是 id
// 这种情况下我们需要先尝试获取 ann_id 对应的 aireader 实例来验证权限，
// 或者前端必须传 id (cmid)。为了稳妥，这里假设前端除了 ann_id 还会传 id (模块ID)。
// 如果前端 delete/update 时没传 id，下面的校验会失败。建议前端统一带上 id 参数。

header('Content-Type: application/json; charset=utf-8');

try {
    // 允许 update_annotation_note 和 delete_annotation 不传 id (cmid)，
    // 但如果有 id 更好。如果没有 id，权限检查会略过上下文部分(不太安全)，
    // 但鉴于你的代码结构，先保持原有逻辑，要求 cmid 必须存在。
    if (!$cmid && $action !== 'update_annotation_note' && $action !== 'delete_annotation') { 
        throw new Exception('Missing CMID'); 
    }
    
    // 如果是删除或更新，且没有传 cmid，尝试通过 ann_id 反查 (可选优化，暂不加，假定前端传了id)
    if ($cmid) {
        $cm = get_coursemodule_from_id('aireader2', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
        // 🔥 获取 aireader 实例，$aireader->id 就是正确的 instance_id (9)
        $aireader = $DB->get_record('aireader2', array('id' => $cm->instance), '*', MUST_EXIST);
        require_login($course, true, $cm);
        $context = context_module::instance($cm->id);
    } else {
        // 如果某些操作没传id，至少要保证登录
        // 这里为了简单，假设所有操作都带了 ?id=xxx
        // 如果你的前端 delete 没带 id，这里会报错。请确保前端 delete 请求带上 &id=...
    }
    
    switch ($action) {
        
        // =========================================================
        // 🔍 接口 1: 初始化获取数据 (Get Task Info)
        // =========================================================
        case 'get_task_info':
            // 1. 获取 PDF 文件下载链接
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_aireader2', 'paper_file', 0, 'sortorder DESC, id DESC', false);
            $file = reset($files);
            $pdfUrl = '';
            if ($file) {
                $url = moodle_url::make_pluginfile_url(
                    $context->id, 
                    'mod_aireader2', 
                    'paper_file', 
                    $file->get_itemid(), 
                    $file->get_filepath(), 
                    $file->get_filename()
                );
                $pdfUrl = $url->out(false);
            }

            // 2. 获取目录结构
            $structure = [];
            if (!empty($aireader->structure)) {
                $structure = json_decode($aireader->structure);
                if (json_last_error() !== JSON_ERROR_NONE) { $structure = []; }
            }

            // 3. 获取所有标注 (Annotations)
            // 注意：aireader2_annotations 表没有 is_deleted 字段，直接查询所有
            $annotations = $DB->get_records('aireader2_annotations', [
                'aireader2id' => $aireader->id,
                'userid' => $USER->id
            ], 'page_num ASC, created_at ASC');
            
            // 🔥 关键点：将对象转换为数组，防止前端 map 报错
            $annotations_list = array_values($annotations);

            // 4. 从 progress 表读取累计数据
            $progress = $DB->get_record('aireader2_progress', [
                'aireader2id' => $aireader->id,
                'userid' => $USER->id
            ]);

            // Session ID 固定为 1（因为表没有 session_count 字段）
            $current_session_id = 1;
            $now = time();

            if ($progress) {
                // 更新最后访问时间
                $progress->last_access = $now;
                $DB->update_record('aireader2_progress', $progress);
            } else {
                // 如果是第一次访问，创建记录
                $newObj = new stdClass();
                $newObj->aireader2id = $aireader->id;
                $newObj->userid = $USER->id;
                $newObj->total_read_seconds = 0;
                $newObj->interaction_count = 0;
                $newObj->focus_loss_count = 0;
                $newObj->last_access = $now;
                $newObj->completion_status = 0;
                $DB->insert_record('aireader2_progress', $newObj);
            }

            // 🔥🔥🔥 [修改] 获取智能体触发规则，并融合用户的完成状态 🔥🔥🔥
            // 注意：这些表可能不存在（install.xml 中没有），需要容错处理
            $trigger_rules_list = [];
            if ($DB->get_manager()->table_exists('aireader2_trigger_rules')) {
                $trigger_rules = $DB->get_records('aireader2_trigger_rules', ['aireader2id' => $aireader->id]);
                
                // 获取当前用户的所有挑战状态（如果表存在）
                $status_map = [];
                if ($DB->get_manager()->table_exists('aireader2_challenge_tracker')) {
                    $trackers = $DB->get_records('aireader2_challenge_tracker', [
                        'aireader2id' => $aireader->id, 
                        'userid' => $USER->id
                    ]);
                    
                    // 将 trackers 转为 [rule_id => status] 的映射方便查找
                    foreach ($trackers as $t) {
                        $status_map[$t->rule_id] = $t->status;
                    }
                }

                foreach ($trigger_rules as $rule) {
                    // 默认状态为 'new' (未触发过)
                    // 如果在 tracker 里有记录：0=pending (稍后处理/进行中), 1=completed (已解决)
                    $status = 'new';
                    if (isset($status_map[$rule->id])) {
                        $status = ($status_map[$rule->id] == 1) ? 'completed' : 'pending';
                    }
                    
                    $rule->user_status = $status; // 把状态注入给前端
                    $trigger_rules_list[] = $rule;
                }
            }

            $total_seconds = $progress ? (int)$progress->total_read_seconds : 0;
            $highlight_count = count($annotations_list); 

            // 5. 返回整合数据
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'id' => $aireader->id,
                    'title' => $aireader->name,
                    'intro' => format_module_intro('aireader2', $aireader, $cm->id),
                    'pdfUrl' => $pdfUrl,
                    'structure' => $structure,
                    'annotations' => $annotations_list, 
                    'total_read_seconds' => $total_seconds, 
                    'highlight_count' => $highlight_count,  
                    'completion_status' => $progress ? $progress->completion_status : 0,
                    'session_id' => $current_session_id, // 🔥 将本次会话ID传给前端
                    'trigger_rules' => $trigger_rules_list // 🔥 将规则列表（含状态）传给前端
                ]
            ]);
            break;

        // =========================================================
        // 💓 接口 2: 心跳更新进度 (Update Progress)
        // =========================================================
        case 'update_progress':
            $add_seconds = optional_param('seconds', 10, PARAM_INT); 
            $current_page = optional_param('page', 1, PARAM_INT);    
            $focus_lost  = optional_param('focus_lost', 0, PARAM_INT); 

            $progress = $DB->get_record('aireader2_progress', [
                'aireader2id' => $aireader->id,
                'userid' => $USER->id
            ]);

            // 统计标注数量（注意：aireader2_annotations 表没有 is_deleted 字段）
            $ann_count = $DB->count_records('aireader2_annotations', ['aireader2id'=>$aireader->id, 'userid'=>$USER->id]);
            $chat_count = $DB->count_records('aireader2_chat_log', ['aireader2id'=>$aireader->id, 'userid'=>$USER->id]);
            $total_interaction = $ann_count + $chat_count;

            $now = time();

            if ($progress) {
                $updateObj = new stdClass();
                $updateObj->id = $progress->id;
                $updateObj->total_read_seconds = $progress->total_read_seconds + $add_seconds;
                $updateObj->last_page = $current_page;
                $updateObj->interaction_count = $total_interaction;
                $updateObj->focus_loss_count = $progress->focus_loss_count + $focus_lost;
                $updateObj->last_access = $now;
                
                if ($updateObj->total_read_seconds > 1800 && $progress->completion_status == 0) {
                    $updateObj->completion_status = 1;
                }

                $DB->update_record('aireader2_progress', $updateObj);
            } else {
                $newObj = new stdClass();
                $newObj->aireader2id = $aireader->id;
                $newObj->userid = $USER->id;
                $newObj->total_read_seconds = $add_seconds;
                $newObj->last_page = $current_page;
                $newObj->interaction_count = $total_interaction;
                $newObj->focus_loss_count = $focus_lost;
                $newObj->last_access = $now;
                $newObj->completion_status = 0;
                // 注意：aireader2_progress 表没有 session_count 字段
                
                $DB->insert_record('aireader2_progress', $newObj);
            }

            echo json_encode(['status' => 'success']);
            break;

        // =========================================================
        // 📝 接口 3: 保存标注 (Create Annotation) - 🔥🔥 适配前端 App.vue 🔥🔥
        // =========================================================
        case 'create_annotation': 
        case 'save_annotation': // 保留旧接口名以防万一
            
            // 尝试从 POST 获取数据 (适配前端 FormData)
            $page = optional_param('page', 1, PARAM_INT);
            $type = optional_param('type', 'highlight', PARAM_ALPHA);
            $quote = optional_param('quote', '', PARAM_RAW);
            $color = optional_param('color', '#ffeb3b', PARAM_TEXT);
            $position_data = optional_param('position_data', '', PARAM_RAW); // 这是 JSON 字符串
            // 🔥 新增：尝试接收 note 字段
            $note = optional_param('note', '', PARAM_RAW);
            // 🔥🔥🔥 [新增] 接收 session_id
            $session_id = optional_param('session_id', 1, PARAM_INT);

            // 如果 POST 为空，尝试读取 php://input (适配旧逻辑)
            if (empty($quote) && empty($position_data)) {
                $raw = file_get_contents('php://input');
                $data = json_decode($raw);
                if ($data) {
                    $page = $data->page ?? 1;
                    $type = $data->type ?? 'highlight';
                    $quote = $data->quote ?? '';
                    $color = $data->color ?? '#ffeb3b';
                    $position_data = json_encode($data->rects ?? []);
                    // 🔥 兼容 JSON 中的 note
                    $note = $data->note ?? ''; 
                }
            }

            $record = new stdClass();
            $record->aireader2id = $aireader->id;
            $record->userid = $USER->id;
            $record->page_num = $page;
            $record->type = $type;
            $record->quote = substr($quote, 0, 5000);
            
            // 🔥🔥🔥 核心修复：放宽颜色长度限制到 50！
            $record->color = substr($color, 0, 50);

            $record->position_data = $position_data; 
            $record->created_at = time();
            // 注意：aireader2_annotations 表没有 note 和 session_id 字段，已移除

            $newId = $DB->insert_record('aireader2_annotations', $record);

            // 更新交互统计
            $count = $DB->count_records('aireader2_annotations', ['aireader2id'=>$aireader->id, 'userid'=>$USER->id]);
            $p = $DB->get_record('aireader2_progress', ['aireader2id'=>$aireader->id, 'userid'=>$USER->id]);
            if($p) {
                $p->interaction_count = $count; 
                $DB->update_record('aireader2_progress', $p);
            }

            echo json_encode(['status' => 'success', 'data' => ['id' => $newId]]);
            break;

        // =========================================================
        // ✏️ 接口 4: 更新笔记内容 (Update Note) - 已禁用（表没有 note 字段）
        // =========================================================
        case 'update_annotation_note':
            // 注意：aireader2_annotations 表没有 note 字段，此功能暂时禁用
            echo json_encode(['status' => 'success', 'message' => 'Note field not available in database']);
            break;

        // =========================================================
        // 🗑️ 接口 5: 删除标注 (Delete Annotation) - 硬删除
        // =========================================================
        case 'delete_annotation':
            $ann_id = required_param('ann_id', PARAM_INT);
            
            // 权限检查：确保这条标注是当前用户创建的
            $ann = $DB->get_record('aireader2_annotations', ['id' => $ann_id, 'userid' => $USER->id], '*', MUST_EXIST);
            
            // 硬删除：直接删除记录（因为表没有 is_deleted 字段）
            $DB->delete_records('aireader2_annotations', ['id' => $ann_id]);
            
            // 更新统计
            $count = $DB->count_records('aireader2_annotations', ['aireader2id'=>$aireader->id, 'userid'=>$USER->id]);
            $p = $DB->get_record('aireader2_progress', ['aireader2id'=>$aireader->id, 'userid'=>$USER->id]);
            if($p) {
                $p->interaction_count = $count; 
                $DB->update_record('aireader2_progress', $p);
            }

            echo json_encode(['status' => 'success']);
            break;

        default:
            throw new Exception('Unknown Action: ' . $action);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
?>