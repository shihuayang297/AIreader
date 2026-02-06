<?php
// 文件路径: /mod/aireader2/chat_api.php
define('AJAX_SCRIPT', true);
require('../../config.php');

// 1. 基础安全校验
try { 
    require_login(); 
} catch (Exception $e) { 
    die(json_encode([['role'=>'navigator', 'reply'=>'同学，请先登录 Moodle 系统后再开始学习哦。']])); 
}

global $DB, $USER, $CFG;
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

$action = optional_param('action', '', PARAM_TEXT);

// ============================================================
// 🚀 接口 1: 历史记录回显
// ============================================================
if ($action === 'load_history') {
    $cmid = required_param('cmid', PARAM_INT);
    $userid = $USER->id; 

    // 🔥 核心修正：获取 instance id
    $cm = get_coursemodule_from_id('aireader2', $cmid, 0, false, MUST_EXIST);
    $instance_id = $cm->instance;

    // 查询 mdl_aireader2_chat_log 表 (按时间顺序)
    $logs = $DB->get_records('aireader2_chat_log', 
        ['aireader2id' => $instance_id, 'userid' => $userid], // 🔥 使用 instance_id
        'timecreated ASC'
    );

    $history = [];
    foreach ($logs as $log) {
        // --- 1. 处理用户的提问 ---
        if (!empty($log->user_message)) {
            // 过滤掉系统自动触发的指令
            if (strpos($log->user_message, '[系统:') === false) {
                $history[] = [
                    'id' => 'u_' . $log->id,
                    'role' => 'user',
                    'content' => $log->user_message,
                    'time' => date('H:i', $log->timecreated)
                ];
            }
        }

        // --- 2. 处理 AI 的回复 ---
        if (!empty($log->ai_response)) {
            // 容错处理
            $agentId = $log->agent_name;
            if (empty($agentId) || $agentId === 'system') {
                $agentId = 'navigator';
            }
            
            // 如果 content 是 JSON 格式的卡片数据，直接放入 content
            $content = $log->ai_response;
            // 兼容旧字段 agent_id
            if (empty($agentId) && !empty($log->agent_id)) {
                $agentId = $log->agent_id;
            }
            // 兼容旧字段 content
            if (empty($content) && !empty($log->content)) {
                $content = $log->content;
            }

            $history[] = [
                'id' => 'ai_' . $log->id,
                'role' => 'ai',
                'agentId' => $agentId, 
                'content' => $content,
                'time' => date('H:i', $log->timecreated),
                'ruleId' => 0 // 注意：表没有 rule_id 字段 
            ];
        }
    }

    echo json_encode(['status' => 'success', 'data' => $history]);
    die; 
}

// ============================================================
// 🚀 接口 2: 直接保存消息 (用于前端生成的触发卡片)
// ============================================================
if ($action === 'save_log') {
    $message = required_param('message', PARAM_RAW); // 卡片内容的 JSON 字符串
    $agent_id = required_param('agent_id', PARAM_TEXT);
    $cmid = required_param('cmid', PARAM_INT);
    // 🔥🔥🔥 [新增] 接收 rule_id 🔥🔥🔥
    $rule_id = optional_param('rule_id', 0, PARAM_INT);
    $role = 'ai'; 

    // 🔥 核心修正：通过 cmid 获取 instance id
    $cm = get_coursemodule_from_id('aireader2', $cmid, 0, false, MUST_EXIST);
    $instance_id = $cm->instance;

    $record = new stdClass();
    $record->aireader2id = $instance_id; // 🔥 存入正确的实例 ID
    $record->userid = $USER->id;
    $record->agent_name = $agent_id; 
    $record->ai_response = $message; 
    // 注意：aireader2_chat_log 表没有 rule_id 字段，已移除
    $record->timecreated = time();

    // 为了兼容性，如果是新表结构有 user_message 字段，给个默认值
    if ($DB->get_manager()->field_exists('aireader2_chat_log', 'user_message')) {
        $record->user_message = ''; 
    }

    // 🔥🔥🔥 [新增] 如果是领航者触发了新任务，在 Tracker 表记录状态 (Pending) 🔥🔥🔥
    // 注意：表可能不存在，需要容错处理
    if ($agent_id === 'navigator' && $rule_id > 0 && $DB->get_manager()->table_exists('aireader2_challenge_tracker')) {
        // 🔥 使用正确的实例 ID 查询
        $existing = $DB->get_record('aireader2_challenge_tracker', ['userid'=>$USER->id, 'rule_id'=>$rule_id, 'aireader2id'=>$instance_id]);
        if (!$existing) {
            $tracker = new stdClass();
            $tracker->aireader2id = $instance_id; // 🔥 存入正确的实例 ID
            $tracker->userid = $USER->id;
            $tracker->rule_id = $rule_id;
            $tracker->status = 0; // 0 = 进行中
            $tracker->timecreated = time();
            $tracker->timemodified = time();
            $DB->insert_record('aireader2_challenge_tracker', $tracker);
        }
    }

    $DB->insert_record('aireader2_chat_log', $record);

    echo json_encode(['status' => 'success']);
    die;
}

// ============================================================
// 🚀 接口 3: 转发给 Python AI 服务 (POST)
// ============================================================

$AI_SERVICE_URL = 'http://127.0.0.1:8000/chat';

try {
    $message = optional_param('message', '', PARAM_RAW); 
    $trigger_event = optional_param('trigger_event', '', PARAM_ALPHAEXT); 
    
    if (empty($message) && empty($trigger_event)) {
        die(json_encode([['role'=>'navigator', 'reply'=>'收到空请求，请重新输入。']]));
    }

    $cmid = optional_param('cmid', 0, PARAM_INT); 
    if (!$cmid) {
        die(json_encode([['role'=>'navigator', 'reply'=>'系统错误：缺少任务ID (cmid)。']]));
    }

    $cm = get_coursemodule_from_id('aireader2', $cmid, 0, false, MUST_EXIST);
    $instance_id = $cm->instance; 

    // 读取本地知识库
    $current_page = optional_param('current_page', 1, PARAM_INT);
    $kb_file = $CFG->dataroot . '/aireader2_cache/kb_' . $instance_id . '.json';
    $page_content = "";
    
    if (file_exists($kb_file)) {
        $json_str = file_get_contents($kb_file);
        $kb_data = json_decode($json_str, true);
        if (isset($kb_data['pages'][$current_page])) {
            $page_content = $kb_data['pages'][$current_page];
            if (mb_strlen($page_content) < 100) {
                $prev = $kb_data['pages'][$current_page - 1] ?? "";
                $next = $kb_data['pages'][$current_page + 1] ?? "";
                $page_content = "【上页片段】$prev\n【本页核心】$page_content\n【下页片段】$next";
            }
        } else {
            $page_content = "（本页无文本内容）";
        }
    }

    // 构造请求
    $active_agents_json = optional_param('active_agents', '[]', PARAM_RAW);
    $active_agents = json_decode($active_agents_json, true) ?: ['navigator'];
    $chat_history = optional_param('chat_history', '', PARAM_RAW);
    $user_name = optional_param('user_name', '同学', PARAM_TEXT);
    // 🔥🔥🔥 [新增] 接收当前活跃的 rule_id 🔥🔥🔥
    $current_rule_id = optional_param('rule_id', 0, PARAM_INT);
    
    // 🔥🔥🔥 [核心修改] 接收前端指定的 last_speaker (target_agent) 🔥🔥🔥
    $target_agent = optional_param('last_speaker', '', PARAM_TEXT);

    // ============================================================
    // 🧠 核心逻辑：后端自动注入参考答案与复盘上帝视角
    // ============================================================
    $llm_message = $message; 

    // 场景 A：如果是脑洞工程师任务，注入参考答案
    // 注意：表可能不存在，需要容错处理
    if ($target_agent === 'idea_engineer' && $current_rule_id > 0 && $DB->get_manager()->table_exists('aireader2_trigger_rules')) {
        $rule = $DB->get_record('aireader2_trigger_rules', ['id' => $current_rule_id]);
        if ($rule && !empty($rule->reference_content)) {
            $llm_message = $message . "\n\n" . 
                "[系统隐秘指令]\n" . 
                "以下是该章节的核心事实/原文片段（Ground Truth），请你务必基于此内容进行提问，不要瞎编：\n" . 
                "\"\"\"\n" . $rule->reference_content . "\n\"\"\"\n" . 
                "请基于上述事实，运用 SKI 理论对我进行引导。你可以通过“引用文中的某个短语”来给我提示（Scaffolding），引导我注意到这些细节，而不是漫无目的地问直觉。";
        }
    }

    // 场景 B：如果是复盘官，注入全话题上下文
    // 注意：表可能不存在，需要容错处理
    if ($target_agent === 'reviewer' && $current_rule_id > 0 && $DB->get_manager()->table_exists('aireader2_trigger_rules')) {
        // 1. 获取该话题的标准信息
        $rule = $DB->get_record('aireader2_trigger_rules', ['id' => $current_rule_id]);
        $ground_truth = $rule ? $rule->reference_content : "未提供原文参考";
        $original_question = $rule ? $rule->trigger_prompt : "未记录初始问题";

        // 2. 获取历史记录（注意：表没有 rule_id 字段，无法按 rule_id 过滤）
        $history_logs = $DB->get_records('aireader2_chat_log', [
            'userid' => $USER->id,
            'aireader2id' => $instance_id
        ], 'timecreated ASC');

        $discussion_log = "";
        foreach ($history_logs as $log) {
            $role = ($log->agent_name && $log->agent_name != 'system') ? $log->agent_name : 'Student';
            $txt = !empty($log->user_message) ? $log->user_message : $log->ai_response;
            // 清洗 JSON 卡片
            if (strpos($txt, '{') === 0) { 
                $json = json_decode($txt, true);
                $txt = $json['content'] ?? $txt;
            }
            // 清洗隐秘指令字符，防止复盘官看到后台逻辑
            $txt = preg_replace('/\[系统隐秘指令\].*$/s', '', $txt);
            $discussion_log .= "[$role]: " . trim($txt) . "\n";
        }

        // 3. 构建复盘官专用上帝视角 Prompt
        $llm_message = "请对我刚才的学习过程进行复盘。\n\n" . 
            "[系统注入数据包]\n" . 
            "1. **核心议题**：$original_question\n" . 
            "2. **标准答案/原文真相**：$ground_truth\n" . 
            "3. **对话全纪录**：\n\"\"\"\n$discussion_log\n\"\"\"\n\n" . 
            "请基于以上数据，按照你的 Output Format 进行深度复盘。";
        
        // 复盘模式下不需要额外的冗余 chat_history
        $chat_history = "";
    }

    // ============================================================
    // 🔥🔥🔥 核心逻辑：基于 Topic_Tag (rule_id) 筛选纯净上下文 🔥🔥🔥
    // ============================================================
    // 如果是普通脑洞过程（非复盘），也进行话题隔离
    if ($target_agent === 'idea_engineer' && $current_rule_id > 0) {
        $history_filters = [
            'aireader2id' => $instance_id, 
            'userid' => $USER->id
            // 注意：表没有 rule_id 字段，无法按话题过滤
        ];
        $logs = $DB->get_records('aireader2_chat_log', $history_filters, 'timecreated DESC', '*', 0, 50);
        $logs = array_reverse($logs); 

        $chat_history = ""; 
        foreach ($logs as $log) {
            $role_name = ($log->agent_name && $log->agent_name !== 'system') ? $log->agent_name : 'User';
            if (empty($log->user_message) && !empty($log->ai_response)) {
                $content = $log->ai_response;
                if (strpos($content, '{') === 0) {
                    $json = json_decode($content, true);
                    if (isset($json['content'])) $content = $json['content'];
                }
                $chat_history .= "[$role_name]: $content\n";
            } elseif (!empty($log->user_message)) {
                $chat_history .= "[User]: {$log->user_message}\n";
            }
        }
    }

    $payload = [
        'message' => $llm_message, 
        'chat_history' => $chat_history,
        'page_content' => $page_content, 
        'current_page' => $current_page,
        'user_name' => $user_name,
        'trigger_event' => !empty($trigger_event) ? $trigger_event : null,
        'active_agents' => $active_agents,
        'target_agent' => !empty($target_agent) ? $target_agent : null
    ];

    // 发起 cURL
    $ch = curl_init($AI_SERVICE_URL);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60); 
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch) || $http_code !== 200) {
        $error_msg = curl_error($ch);
        throw new Exception("AI 服务连接失败 (Code: $http_code). 请联系管理员检查 Python 服务是否启动。");
    }
    curl_close($ch);

    $ai_data = json_decode($response, true);

    // 记录日志
    if (is_array($ai_data)) {
        foreach ($ai_data as $reply_item) {
            try {
                if ($DB->get_manager()->table_exists('aireader2_chat_log')) {
                    $log = new stdClass();
                    $log->userid = $USER->id; 
                    $log->aireader2id = $instance_id; 
                    $log->agent_name = $reply_item['role']; 
                    $log->user_message = !empty($trigger_event) ? "[系统事件:$trigger_event] $message" : $message; 
                    $log->ai_response = $reply_item['reply']; 
                    $log->timecreated = time();
                    // 注意：aireader2_chat_log 表没有 rule_id 字段，已移除
                    
                    $DB->insert_record('aireader2_chat_log', $log);

                    if ($reply_item['role'] === 'reviewer' && $current_rule_id > 0 && $DB->get_manager()->table_exists('aireader2_challenge_tracker')) {
                        $tracker = $DB->get_record('aireader2_challenge_tracker', ['userid'=>$USER->id, 'rule_id'=>$current_rule_id, 'aireader2id'=>$instance_id]);
                        if ($tracker) {
                            $tracker->status = 1; // 1 = Resolved
                            $tracker->timemodified = time();
                            $DB->update_record('aireader2_challenge_tracker', $tracker);
                        }
                    }
                }
            } catch (Exception $e) {}
        }
        echo $response;
    } else {
        throw new Exception("AI 返回格式异常");
    }

} catch (Exception $e) {
    echo json_encode([[ 'role' => 'navigator', 'reply' => "（系统提示）" . $e->getMessage() ]]);
}
?>