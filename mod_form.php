<?php
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot.'/course/moodleform_mod.php');

class mod_aireader2_mod_form extends moodleform_mod {

    function definition() {
        global $CFG;
        $mform = $this->_form;

        // -------------------------------------------------------------------------------
        // 1. 通用设置 (General)
        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), array('size'=>'64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $this->standard_intro_elements(get_string('description'));

        // -------------------------------------------------------------------------------
        // 2. AI 伴读设置 (AI Reader Settings)
        // -------------------------------------------------------------------------------
        // 注意：这里使用了你在 lang 文件里定义的新名字，如果没改 lang 文件可能会显示英文
        $mform->addElement('header', 'aisettings', get_string('modulename', 'aireader2') . ' Settings');

        // [核心修改]：只保留 PDF 上传，删除原来的长文本编辑器
        
        // 添加文件上传控件 (字段名：paper_file)
        $mform->addElement('filemanager', 'paper_file', get_string('upload_paper', 'aireader2'), null,
            array('subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 1, 'accepted_types' => array('.pdf')));
        
        // 添加帮助按钮
        $mform->addHelpButton('paper_file', 'upload_paper', 'aireader2');

        // [关键] 设置为必填项：必须上传 PDF 才能创建活动
        $mform->addRule('paper_file', null, 'required', null, 'client');

        // -------------------------------------------------------------------------------
        // 3. 评分标准设置 (Rubric Settings) - 保持原样
        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'rubric_header', 'Rubric Settings (Grading Criteria)');

        $repeatcount = 1;
        if ($from_form = optional_param('rubric_repeats', 0, PARAM_INT)) {
            $repeatcount = $from_form;
        } else if ($this->_instance && !empty($this->_instance->rubric_json)) {
            $decoded = json_decode($this->_instance->rubric_json);
            if (is_array($decoded)) $repeatcount = count($decoded);
        }
        if (optional_param('add_criterion', false, PARAM_BOOL)) $repeatcount++;
        if ($repeatcount < 1) $repeatcount = 1;

        $mform->addElement('hidden', 'rubric_repeats');
        $mform->setType('rubric_repeats', PARAM_INT);
        $mform->setConstant('rubric_repeats', $repeatcount); 

        for ($i = 0; $i < $repeatcount; $i++) {
            $mform->addElement('html', '<div style="background:#f9f9f9; border:1px solid #eee; padding:10px; margin-bottom:10px; border-radius:4px;">');
            $mform->addElement('static', 'label_'.$i, '', '<strong>Criterion '.($i+1).'</strong>');
            $mform->addElement('text', 'rubric_title['.$i.']', 'Title', array('size' => 50));
            $mform->setType('rubric_title['.$i.']', PARAM_TEXT);
            $mform->addElement('textarea', 'rubric_desc['.$i.']', 'Description', 'wrap="virtual" rows="2" cols="50"');
            $mform->setType('rubric_desc['.$i.']', PARAM_TEXT);
            $mform->addElement('html', '</div>');
        }

        $mform->addElement('submit', 'add_criterion', 'Add another criterion');
        $mform->registerNoSubmitButton('add_criterion');

        // -------------------------------------------------------------------------------
        // 4. Moodle 标准组件
        // -------------------------------------------------------------------------------
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * 数据预处理：用于编辑活动时回显已有的数据
     */
    public function data_preprocessing(&$default_values) {
        parent::data_preprocessing($default_values);

        // 1. [核心修改] 回填 PDF 文件数据
        if ($this->current->instance) {
            // 将数组转为对象，避免 Moodle 某些版本报错 "Attempt to assign property on array"
            $data_obj = (object)$default_values;

            // 准备文件管理器，回显之前上传的 PDF
            // 注意：第二个参数 'paper_file' 必须与 definition 中的字段名一致
            file_prepare_standard_filemanager($data_obj, 'paper_file', 
                array('subdirs' => 0, 'maxfiles' => 1, 'accepted_types' => array('.pdf')), 
                $this->context, 'mod_aireader2', 'paper_file', 
                0 // itemid 通常为 0，因为一个活动实例只有一个这样的文件区
            );

            // 将处理好的文件数据赋值回默认值数组
            $default_values['paper_file'] = $data_obj->paper_file;
        }

        // 2. 回填评分标准 (保持原样)
        if (isset($default_values['rubric_json'])) {
            $rubrics = json_decode($default_values['rubric_json']);
            if (is_array($rubrics)) {
                foreach ($rubrics as $key => $item) {
                    $default_values['rubric_title['.$key.']'] = isset($item->title) ? $item->title : '';
                    $default_values['rubric_desc['.$key.']']  = isset($item->desc) ? $item->desc : '';
                }
            }
        }
    }
}

// ... 原有的代码 ...
$string['upload_paper'] = '上传学术论文 (PDF)';
$string['upload_paper_help'] = '请上传一份 PDF 格式的学术论文。系统将自动解析其结构，供学生在 AI 伴读模式下阅读。';