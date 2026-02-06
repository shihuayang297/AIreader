import { Compass, Library, Lightbulb, ClipboardCheck } from 'lucide-vue-next'

// 假设你没有本地头像，这里统一使用 DiceBear 在线头像，保证能显示
export const agentPool = {
  // 1. 领航者
  navigator: { 
    id: 'navigator', 
    name: '领航者·小师', 
    roleName: '策略导引', 
    tag: 'Guide', 
    color: 'bg-blue-50 text-blue-600', 
    avatar: 'https://api.dicebear.com/9.x/notionists/svg?seed=Felix&backgroundColor=e0f2fe&glassesProbability=100', 
    desc: '阅读目标设定、进度监控、元认知提醒', 
    actionLabel: '阅读规划', 
    actionPrompt: '@小师 我刚开始读这篇论文，请引导我进行“目标设定-结构预览”：', 
    icon: Compass 
  },

  // 2. 百科助手
  encyclopedia: { 
    id: 'encyclopedia', 
    name: '百科助手·小科', 
    roleName: '知识百科', 
    tag: 'Wiki', 
    color: 'bg-indigo-50 text-indigo-600', 
    avatar: 'https://api.dicebear.com/9.x/notionists/svg?seed=Jessica&backgroundColor=e0e7ff', 
    desc: '术语三维解析(词义/背景/词根)、长难句翻译', 
    actionLabel: '术语/翻译', 
    actionPrompt: '@小科 请帮我解释一下这个术语（或翻译这段话）：', 
    icon: Library 
  },

  // 3. 脑洞工程师 (🔥 核心修改：键名和 id 从 brainstormer 改为 idea_engineer 🔥)
  idea_engineer: { 
    id: 'idea_engineer', // 必须和 App.vue 里的 agent: 'idea_engineer' 一致
    name: '脑洞工程师·小脑', 
    roleName: '循证推理', 
    tag: 'Logic', 
    color: 'bg-orange-50 text-orange-600', 
    avatar: 'https://api.dicebear.com/9.x/notionists/svg?seed=Leo&backgroundColor=ffedd5', 
    desc: '解决逻辑断层，引导 Bridging Inference 与观点辨析', 
    actionLabel: '逻辑探究', 
    actionPrompt: '@小脑 我觉得这段逻辑有点矛盾，能不能引导我进行推导？', 
    icon: Lightbulb 
  },

  // 4. 复盘官
  reviewer: { 
    id: 'reviewer', 
    name: '复盘官·小盘', 
    roleName: '认知整合', 
    tag: 'Review', 
    color: 'bg-emerald-50 text-emerald-600', 
    avatar: 'https://api.dicebear.com/9.x/notionists/svg?seed=Maria&backgroundColor=d1fae5', 
    desc: '梳理“初始假设-关键证据-最终结论”的逻辑闭环', 
    actionLabel: '总结复盘', 
    actionPrompt: '@小盘 我理解得差不多了，请帮我梳理一下刚才的推理逻辑闭环：', 
    icon: ClipboardCheck 
  },

  // 系统通知
  system: { 
    id: 'system', 
    name: '系统通知', 
    roleName: 'System', 
    avatar: 'https://api.dicebear.com/9.x/initials/svg?seed=SY&backgroundColor=f3f4f6' 
  }
}

// 默认在线列表 (注意这里也要用 idea_engineer)
export const defaultActiveIds = ['navigator', 'encyclopedia', 'idea_engineer', 'reviewer']
export const mandatoryAgents = ['navigator']