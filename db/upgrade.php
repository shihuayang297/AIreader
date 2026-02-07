<?php
defined('MOODLE_INTERNAL') || die();

/**
 * 插件升级函数
 */
function xmldb_aireader2_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // =========================================================================
    // 1. 创建 aireader2_chat_log 表 (旧逻辑)
    // =========================================================================
    if ($oldversion < 2026010102) {
        $table = new xmldb_table('aireader2_chat_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('aireader2id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('agent_name', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('user_message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('ai_response', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('fk_aireader', XMLDB_KEY_FOREIGN, ['aireader2id'], 'aireader2', ['id']);
        $table->add_index('lookup_user_task', XMLDB_INDEX_NOTUNIQUE, ['userid', 'aireader2id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026010102, 'aireader2');
    }

    // =========================================================================
    // 2. 创建 aireader2_annotations 表 (高亮/标注)
    // =========================================================================
    if ($oldversion < 2026010103) {
        $table = new xmldb_table('aireader2_annotations');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('aireader2id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('page_num', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'highlight');
        $table->add_field('quote', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('color', XMLDB_TYPE_CHAR, '20', null, null, null, '#ffeb3b');
        $table->add_field('position_data', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('created_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_ann_aireader', XMLDB_KEY_FOREIGN, ['aireader2id'], 'aireader2', ['id']);
        $table->add_key('fk_ann_user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('lookup_user_ann', XMLDB_INDEX_NOTUNIQUE, ['aireader2id', 'userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026010103, 'aireader2');
    }

    // =========================================================================
    // 3. 添加 structure 字段 (目录结构)
    // =========================================================================
    if ($oldversion < 2026011901) {
        $table = new xmldb_table('aireader2');
        $field = new xmldb_field('structure', XMLDB_TYPE_TEXT, null, null, null, null, null, 'rubric_json');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026011901, 'aireader2');
    }

    // =========================================================================
    // 🔥🔥🔥 4. 创建 aireader2_progress 表 (学习行为数据挖掘) 🔥🔥🔥
    // 对应版本号：2026012000 (注意：必须去修改 version.php 到这个数字)
    // =========================================================================
    if ($oldversion < 2026012000) {
        
        // 定义新表
        $table = new xmldb_table('aireader2_progress');

        // 基础字段
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('aireader2id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        
        // 阅读进度
        $table->add_field('total_read_seconds', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('last_page', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        
        // 🔥 数据挖掘字段 (JSON存储各页停留时间、交互统计、专注度统计)
        $table->add_field('page_dwell_time', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('interaction_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('focus_loss_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        
        // 状态字段
        $table->add_field('last_access', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('completion_status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

        // 键和索引
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        // 确保每个用户在每个活动中只有一条进度记录
        $table->add_key('uq_user_progress', XMLDB_KEY_UNIQUE, ['aireader2id', 'userid']);

        // 创建表
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 保存升级点
        upgrade_mod_savepoint(true, 2026012000, 'aireader2');
    }

    // =========================================================================
    // 5. 创建 aireader2_trigger_rules 表 (章节思维挑战规则，避免创建活动时报“写入数据库时发生错误”)
    // =========================================================================
    if ($oldversion < 2026012100) {
        $table = new xmldb_table('aireader2_trigger_rules');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('aireader2id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('section_keyword', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('trigger_prompt', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('reference_content', XMLDB_TYPE_TEXT, null, null, null, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_trigger_aireader2', XMLDB_KEY_FOREIGN, ['aireader2id'], 'aireader2', ['id']);
        // 不再单独 add_index：外键已对 aireader2id 建索引，再加会与 fk_trigger_aireader2 冲突

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026012100, 'aireader2');
    }

    // =========================================================================
    // 6. aireader2_annotations.color 从 20 扩为 64（存 rgba(255,235,59,0.4) 需 24 字符，否则插入失败导致高亮不落库）
    // =========================================================================
    if ($oldversion < 2026012200) {
        $table = new xmldb_table('aireader2_annotations');
        $field = new xmldb_field('color', XMLDB_TYPE_CHAR, '64', null, null, null, '#ffeb3b');
        if ($dbman->field_exists($table, 'color')) {
            $dbman->change_field_precision($table, $field);
        }
        upgrade_mod_savepoint(true, 2026012200, 'aireader2');
    }

    // =========================================================================
    // 7. 版本号与 version.php 对齐，避免“不能降级”错误（无表结构变更）
    // =========================================================================
    if ($oldversion < 2026020600) {
        upgrade_mod_savepoint(true, 2026020600, 'aireader2');
    }

    return true;
}