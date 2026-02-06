<?php
define('AJAX_SCRIPT', true);
require('../../config.php');

$id = required_param('id', PARAM_INT); 
$content = required_param('content', PARAM_RAW); 
$duration_inc = optional_param('duration_inc', 0, PARAM_INT); 
$word_count = optional_param('word_count', 0, PARAM_INT); 
$is_autosave = optional_param('is_autosave', 0, PARAM_INT); // 修改：接收INT类型 (0或1)
$is_submission = optional_param('is_submission', 0, PARAM_BOOL); 

if (!$cm = get_coursemodule_from_id('aireader2', $id)) {
    throw new moodle_exception('invalidcoursemodule');
}
if (!$aireader = $DB->get_record('aireader2', array('id' => $cm->instance))) {
    throw new moodle_exception('invalidaireader2id', 'aireader2');
}
require_login($cm->course, false, $cm);

$now = time();

try {
    $transaction = $DB->start_delegated_transaction();

    $submission = $DB->get_record('aireader2_submissions', [
        'aireader2id' => $aireader->id,
        'userid' => $USER->id
    ]);

    // 确定新状态
    $new_status = $is_submission ? 'submitted' : 'draft';

    if (!$submission) {
        $submission = new stdClass();
        $submission->aireader2id = $aireader->id;
        $submission->userid = $USER->id;
        $submission->content = $content;
        $submission->word_count = $word_count;
        $submission->writing_time = $duration_inc; 
        $submission->revision_count = 1;
        $submission->status = $new_status;
        $submission->timecreated = $now;
        $submission->timemodified = $now;
        
        $submission->id = $DB->insert_record('aireader2_submissions', $submission);
    } else {
        // 如果已经提交过了，允许覆盖更新，但状态保持 logic 需要注意
        if ($submission->status === 'submitted' && !$is_submission) {
             // 如果已提交但这次只是普通保存，通常前端会禁止，但后端也做个兜底，
             // 这里暂时允许覆盖。
        }

        $submission->content = $content;
        $submission->word_count = $word_count;
        // 时长总是累加
        $submission->writing_time = (int)$submission->writing_time + $duration_inc; 
        
        // 【关键修改】只有非自动保存时，才增加版本号
        if (!$is_autosave) {
            $submission->revision_count = (int)$submission->revision_count + 1; 
        }

        $submission->status = $new_status; 
        $submission->timemodified = $now;
        
        $DB->update_record('aireader2_submissions', $submission);
    }

    // 记录历史快照
    $step = new stdClass();
    $step->submissionid = $submission->id;
    $step->content = $content;
    $step->action = $is_submission ? 'submit' : ($is_autosave ? 'auto_save' : 'manual_save');
    $step->timecreated = $now;
    
    $DB->insert_record('aireader2_steps', $step);

    $transaction->allow_commit();

    echo json_encode([
        'status' => 'success',
        'message' => $is_submission ? '作业已提交' : '已保存',
        'submission_status' => $new_status,
        'time' => date('H:i:s'),
        'total_time' => $submission->writing_time,
        'revisions' => $submission->revision_count
    ]);

} catch (Exception $e) {
    if ($transaction) $transaction->rollback($e);
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}