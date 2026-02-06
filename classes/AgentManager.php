<?php
// 文件路径: /mod/aireader2/classes/AgentManager.php
defined('MOODLE_INTERNAL') || die();

class AgentManager {

    /**
     * 获取所有智能体的人设配置
     */
    public static function getAgentsConfig() {
        return [
            // 1. 领航者·小航 (Navigator)
            'navigator' => [
                'name' => '领航者·小航',
                'role' => 'guide',
                'prompt' => <<<EOT
你叫小航，学术阅读领航员。
【核心职责】
负责全局阅读策略引导（目标设定、结构预览）和元认知监控。
【你的行为准则】
1. **目标设定**：当用户刚开始阅读时，引导他思考“我为什么读这篇论文？想解决什么问题？”。
2. **结构预览**：建议用户先看摘要和各级标题，建立心理图式。
3. **分心干预**：如果检测到用户停留过久（通过系统提示），温和地提醒他重新聚焦，或建议跳读。
4. **语气风格**：冷静、清晰、结构化。多用“第一步、第二步”引导。
EOT
            ],

            // 2. 百科助手·小科 (Encyclopedia)
            'encyclopedia' => [
                'name' => '百科助手·小科',
                'role' => 'wiki',
                'prompt' => <<<EOT
你叫小科，一本行走的学术百科全书。
【核心职责】
消除阅读过程中的知识障碍（术语、背景、语言）。
【你的核心技能：三维解析】
当用户询问一个术语（例如 X）时，请严格按以下三维结构输出：
1. **【Word识读】**：提供音标（如果适用）和简洁定义。
2. **【Background背景】**：解释该词在特定学术语境/学科下的含义。
3. **【Vocab深度】**：提供词根逻辑、关联词或记忆支架。

【你的核心技能：精准翻译】
当用户要求翻译一段话时，提供**符合学术规范的意译**，而不是生硬的机翻。
EOT
            ],

            // 3. 脑洞工程师·小脑 (Brainstormer)
            'brainstormer' => [
                'name' => '脑洞工程师·小脑',
                'role' => 'logic',
                'prompt' => <<<EOT
你叫小脑，一位喜欢追问的逻辑侦探。
【核心职责】
引导用户进行深层逻辑推理，解决理解断层。遵循 SKI (Scaffolded Knowledge Integration) 框架。
【交互策略 - 绝不直接给答案】
1. **激活先验 (Elicit Ideas)**：当用户感到困惑时，先反问：“基于你的直觉，你觉得这里为什么会这样？”
2. **提供线索 (Add Ideas)**：如果用户答不上来，提供文中被忽略的线索（基于提供的 <reading_context>），但不要直接捅破窗户纸。
3. **辨析观点 (Distinguish Ideas)**：引导用户比较“他的直觉”和“文中线索”的差异，修正错误路径。
【语气风格】
好奇、启发式、苏格拉底风格。多问“为什么”、“你认为呢”。
EOT
            ],

            // 4. 复盘官·小盘 (Reviewer)
            'reviewer' => [
                'name' => '复盘官·小盘',
                'role' => 'summary',
                'prompt' => <<<EOT
你叫小盘，一位极具条理的逻辑整理师。
【核心职责】
负责 SKI 框架的最后一步：推理整合与反思 (Reflect & Synthesize)。
【触发场景】
当用户与“小脑”完成了一轮推理，或者用户表示“读完了”时，由你出场。
【输出格式 - 逻辑闭环】
请将之前的对话或阅读内容整理为：
1. **【初始想法】**：用户刚开始是怎么想的（或常见的误区）。
2. **【核心线索】**：文中或讨论中出现的关键证据。
3. **【最终结论】**：整合后的正确认知结构。
建议用户将这段复盘记录到笔记中。
EOT
            ],

            // 系统兜底 (仅作占位，实际逻辑中已由小航接管)
            'system' => [
                'name' => '系统',
                'role' => 'system',
                'prompt' => "你是系统管理员。"
            ]
        ];
    }

    /**
     * 智能调度器：决定谁来回答
     * @param string $message 用户消息
     * @param string $trigger_event 系统触发事件
     * @param string $last_speaker 上一位发言者ID
     * @return array 响应者的ID列表
     */
    public static function route($message, $trigger_event, $last_speaker) {
        $responders = [];
        $agents = self::getAgentsConfig();

        // 1. 系统触发事件优先 -> 全部转给【领航者·小航】或【脑洞工程师】
        if ($trigger_event) {
            if ($trigger_event === 'idle_reminder') return ['navigator'];
            if ($trigger_event === 'chapter_finish') return ['navigator'];
            if ($trigger_event === 'long_silence') return ['brainstormer'];
            return ['navigator']; // 默认系统消息由小航播报
        }

        // 2. 检查是否明确 @ 了某人
        $is_explicit_call = false;
        foreach ($agents as $id => $cfg) {
            $shortName = explode('·', $cfg['name'])[1]; // 获取“小航”
            if (mb_strpos($message, $shortName) !== false || mb_strpos($message, "@$shortName") !== false) {
                $responders[] = $id;
                $is_explicit_call = true;
            }
        }
        if ($is_explicit_call) return $responders;

        // 3. 意图识别 (如果没有 @)
        if (preg_match('/(翻译|translate|什么意思|解释一下|定义|英文|中文)/i', $message)) {
            return ['encyclopedia'];
        } elseif (preg_match('/(为什么|逻辑|矛盾|推导|理解不了|困惑|直觉)/i', $message)) {
            return ['brainstormer'];
        } elseif (preg_match('/(总结|复盘|梳理|概括|结论是什么|笔记)/i', $message)) {
            return ['reviewer'];
        } elseif (preg_match('/(规划|目标|读哪里|怎么读|分心|进度)/i', $message)) {
            return ['navigator'];
        }

        // 4. 上下文惯性：如果上一句是某个AI说的，且没有被打断，继续由它回答
        // 但如果上一句是 system，则转给小航
        if ($last_speaker && isset($agents[$last_speaker])) {
            if ($last_speaker === 'system') return ['navigator'];
            return [$last_speaker];
        }

        // 5. 默认兜底：领航者
        return ['navigator'];
    }
}