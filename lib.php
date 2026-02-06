<?php
defined('MOODLE_INTERNAL') || die();

/**
 * 添加新实例
 */
function aireader2_add_instance($data, $mform = null) {
    global $DB, $CFG;

    $data->timecreated = time();
    $data->timemodified = time();

    // #region agent log
    $logpath = isset($CFG->dirroot) ? $CFG->dirroot . '/mod/aireader2/.cursor/debug.log' : '/www/wwwroot/moodle/mod/aireader2/.cursor/debug.log';
    $logdir = dirname($logpath);
    if (!is_dir($logdir)) { @mkdir($logdir, 0755, true); }
    @file_put_contents($logpath, json_encode(['timestamp'=>time()*1000,'location'=>'lib.php:add_instance:entry','message'=>'add_instance started','data'=>['data_keys'=>array_keys((array)$data)],'hypothesisId'=>'H2','sessionId'=>'debug-session']) . "\n", FILE_APPEND | LOCK_EX);
    // #endregion

    // 1. 插入数据库
    $data->id = $DB->insert_record('aireader2', $data);

    // #region agent log
    @file_put_contents($logpath, json_encode(['timestamp'=>time()*1000,'location'=>'lib.php:add_instance:after_insert','message'=>'insert_record aireader2 ok','data'=>['id'=>$data->id],'hypothesisId'=>'H2','sessionId'=>'debug-session']) . "\n", FILE_APPEND | LOCK_EX);
    // #endregion

    // 2. 保存文件 (核心逻辑)
    // $data->coursemodule 是当前页面的 CMID，直接用，不要去查库
    $context = context_module::instance($data->coursemodule);

    if (!empty($data->paper_file)) {
        file_save_draft_area_files(
            $data->paper_file, 
            $context->id, 
            'mod_aireader2', 
            'paper_file', 
            0, 
            array('subdirs' => 0, 'maxfiles' => 1)
        );
    }

    // #region agent log
    @file_put_contents($logpath, json_encode(['timestamp'=>time()*1000,'location'=>'lib.php:add_instance:before_process_pdf','message'=>'before process_pdf_structure','data'=>['id'=>$data->id],'hypothesisId'=>'H3','sessionId'=>'debug-session']) . "\n", FILE_APPEND | LOCK_EX);
    // #endregion

    // 🔥🔥 核心修复：直接把 $data->coursemodule 传进去，避免去数据库查不到而报错
    aireader2_process_pdf_structure($data->id, $data->coursemodule);

    return $data->id;
}

/**
 * 更新实例
 */
function aireader2_update_instance($data, $mform = null) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    // 1. 保存文件
    $context = context_module::instance($data->coursemodule);

    if (!empty($data->paper_file)) {
        file_save_draft_area_files(
            $data->paper_file, 
            $context->id, 
            'mod_aireader2', 
            'paper_file', 
            0, 
            array('subdirs' => 0, 'maxfiles' => 1)
        );
    }

    // 2. 更新数据库
    if (!$DB->update_record('aireader2', $data)) {
        return false;
    }

    // 🔥🔥 核心修复：同样直接传入 CMID，确保解析流程顺畅
    aireader2_process_pdf_structure($data->id, $data->coursemodule);

    return true;
}

/**
 * 删除实例
 */
function aireader2_delete_instance($id) {
    global $DB, $CFG;

    if (!$aireader = $DB->get_record('aireader2', array('id' => $id))) {
        return false;
    }

    $cm = get_coursemodule_from_instance('aireader2', $aireader->id);
    $context = context_module::instance($cm->id);
    
    // 删除关联文件
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_aireader2', 'paper_file');

    $DB->delete_records('aireader2', array('id' => $aireader->id));
    
    // 如果有 submissions 表也删除
    if ($DB->get_manager()->table_exists('aireader2_submissions')) {
        $DB->delete_records('aireader2_submissions', array('aireader2id' => $aireader->id));
    }
    
    // 🔥 清理 Trigger Rules
    if ($DB->get_manager()->table_exists('aireader2_trigger_rules')) {
        $DB->delete_records('aireader2_trigger_rules', array('aireader2id' => $aireader->id));
    }

    // 🔥 清理本地知识库缓存文件
    $cacheFile = $CFG->dataroot . '/aireader2_cache/kb_' . $aireader->id . '.json';
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }

    return true;
}

/**
 * 文件访问授权 (浏览器能否看到文件的关键)
 */
function aireader2_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) { return false; }
    require_login($course, true, $cm);

    // 只允许访问 paper_file
    if ($filearea !== 'paper_file') { return false; }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_aireader2/$filearea/$relativepath";

    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, false, $options);
}

function aireader2_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS: return true;
        case FEATURE_GROUPINGS: return true;
        case FEATURE_MOD_INTRO: return true;
        case FEATURE_BACKUP_MOODLE2: return true;
        case FEATURE_SHOW_DESCRIPTION: return true;
        default: return null;
    }
}

/**
 * 🔥🔥🔥 核心功能：双重解析 PDF (目录结构 + 全文知识库 + 自动生成触发规则) 🔥🔥🔥
 * @param int $aireader2id 活动实例 ID (mdl_aireader2 表的主键)
 * @param int $provided_cmid (可选)直接传入的 CMID，防止新建时查库失败
 * @return bool 是否成功
 */
function aireader2_process_pdf_structure($aireader2id, $provided_cmid = 0) {
    global $DB, $CFG;

    // #region agent log
    $logpath = isset($CFG->dirroot) ? $CFG->dirroot . '/mod/aireader2/.cursor/debug.log' : '/www/wwwroot/moodle/mod/aireader2/.cursor/debug.log';
    $logdir = dirname($logpath);
    if (!is_dir($logdir)) { @mkdir($logdir, 0755, true); }
    @file_put_contents($logpath, json_encode(['timestamp'=>time()*1000,'location'=>'lib.php:process_pdf_structure:entry','message'=>'process_pdf_structure started','data'=>['aireader2id'=>$aireader2id],'hypothesisId'=>'H1','sessionId'=>'debug-session']) . "\n", FILE_APPEND | LOCK_EX);
    // #endregion

    // 1. 获取活动信息
    $aireader = $DB->get_record('aireader2', array('id' => $aireader2id));
    if (!$aireader) return false;

    // 2. 获取 Context
    if ($provided_cmid) {
        $context = context_module::instance($provided_cmid);
    } else {
        $cm = get_coursemodule_from_instance('aireader2', $aireader2id);
        if (!$cm) return false;
        $context = context_module::instance($cm->id);
    }

    // 3. 找到 PDF 文件
    $fs = get_file_storage();
    $files = $fs->get_area_files(
        $context->id, 
        'mod_aireader2', 
        'paper_file', 
        0, 
        'sortorder DESC, id DESC', 
        false 
    );

    $file = reset($files); 
    if (!$file) return false;

    // 4. 复制到临时目录
    $tempdir = make_temp_directory('aireader_pdf_parse');
    $tempfilename = 'doc_' . $aireader2id . '_' . time() . '.pdf';
    $temppath = $tempdir . '/' . $tempfilename;
    $file->copy_content_to($temppath);

    // ==========================================
    // 动作 A：生成目录 & 自动创建 Trigger Rules
    // ==========================================
    $scriptStructure = $CFG->dirroot . '/mod/aireader2/utils/pdf_parser.py';
    if (file_exists($scriptStructure)) {
        $cmd = "python3 " . escapeshellarg($scriptStructure) . " " . escapeshellarg($temppath) . " 2>&1";
        $output = shell_exec($cmd);
        $structureData = json_decode($output, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($structureData)) {
            // 1. 更新 structure 字段
            $updateObj = new stdClass();
            $updateObj->id = $aireader2id;
            $updateObj->structure = json_encode($structureData, JSON_UNESCAPED_UNICODE);
            $DB->update_record('aireader2', $updateObj);

            // 2. 🔥🔥🔥 自动生成 Trigger Rules (仅当表存在时写入，避免“写入数据库时发生错误”)
            $tbl_exists = $DB->get_manager()->table_exists('aireader2_trigger_rules');
            // #region agent log
            @file_put_contents($logpath, json_encode(['timestamp'=>time()*1000,'location'=>'lib.php:process_pdf_structure:trigger_rules_check','message'=>'trigger_rules table_exists','data'=>['table_exists'=>$tbl_exists,'aireader2id'=>$aireader2id],'hypothesisId'=>'H1','sessionId'=>'debug-session','runId'=>'post-fix']) . "\n", FILE_APPEND | LOCK_EX);
            // #endregion

            if ($tbl_exists) {
                // 先清理旧规则，防止重复堆积
                $DB->delete_records('aireader2_trigger_rules', ['aireader2id' => $aireader2id]);

                foreach ($structureData as $section) {
                    if (!empty($section['summary'])) {
                        $keyword = '';
                        if (preg_match('/(Introduction|引言)/i', $section['title'])) {
                            $keyword = 'Introduction';
                            $prompt = '你已经阅读了引言部分，请总结一下作者提出的核心研究问题是什么？';
                        } elseif (preg_match('/(Methodology|Methods|方法)/i', $section['title'])) {
                            $keyword = 'Methodology';
                            $prompt = '在方法论部分，作者采用了哪些具体的数据收集手段？';
                        } elseif (preg_match('/(Discussion|讨论)/i', $section['title'])) {
                            $keyword = 'Discussion';
                            $prompt = '作者的讨论部分有哪些值得反思的局限性？';
                        } elseif (preg_match('/(Conclusion|结论)/i', $section['title'])) {
                            $keyword = 'Conclusion';
                            $prompt = '这篇论文的最终结论对未来的研究有什么启示？';
                        }

                        if ($keyword) {
                            $rule = new stdClass();
                            $rule->aireader2id = $aireader2id;
                            $rule->section_keyword = $keyword;
                            $rule->trigger_prompt = $prompt;
                            $rule->reference_content = $section['summary'];
                            $DB->insert_record('aireader2_trigger_rules', $rule);
                        }
                    }
                }
            }
        }
    }

    // ==========================================
    // 动作 B：生成全文知识库 (存入 moodledata 文件缓存)
    // ==========================================
    $scriptFull = $CFG->dirroot . '/mod/aireader2/utils/pdf_full_text.py';
    if (file_exists($scriptFull)) {
        $cmdFull = "python3 " . escapeshellarg($scriptFull) . " " . escapeshellarg($temppath) . " 2>&1";
        $outputFull = shell_exec($cmdFull);
        
        // 验证 JSON 合法性
        if (json_decode($outputFull)) {
            // 缓存目录路径 (Moodledata/aireader2_cache)
            $cacheDir = $CFG->dataroot . '/aireader2_cache';
            if (!file_exists($cacheDir)) {
                check_dir_exists($cacheDir, true, true);
            }
            // 写入知识库文件: kb_ID.json
            $cacheFile = $cacheDir . '/kb_' . $aireader2id . '.json';
            file_put_contents($cacheFile, $outputFull);
        }
    }

    // 5. 清理临时文件
    @unlink($temppath);

    return true;
}

/**
 * 更新成绩到 Moodle 成绩册
 */
function aireader2_update_grades($aireader, $userid = 0) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');
    $grades = [];
    if ($userid) {
        $submission = $DB->get_record('aireader2_submissions', ['aireader2id' => $aireader->id, 'userid' => $userid]);
        if ($submission && $submission->status === 'graded' && isset($submission->grade)) {
            $grades[$userid] = (object)['userid' => $userid, 'rawgrade' => (float)$submission->grade];
        }
    }
    $params = ['itemname' => $aireader->name];
    grade_update('mod/aireader2', $aireader->course, 'mod', 'aireader2', $aireader->id, 0, $grades, $params);
}