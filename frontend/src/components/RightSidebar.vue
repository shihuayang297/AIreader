<script setup>
import { ref, nextTick, watch, computed } from 'vue'
import { 
    X, MoreHorizontal, AtSign, Send, 
    Compass, Library, Lightbulb, ClipboardCheck, MessageSquare, Sparkles 
} from 'lucide-vue-next'

const props = defineProps({
  isOpen: Boolean,
  currentUser: { type: Object, default: () => ({ name: '同学' }) },
  task: { type: Object, default: () => ({ title: '学术阅读任务' }) }
})

const emit = defineEmits(['close'])

// ==========================================
// 1. AI 阅读专用智能体定义 (基于 XLSX 和 SKIMIM 论文)
// ==========================================
const mandatoryAgents = ['navigator'] // 领航者默认常驻
const agentPool = {
  // 1. 领航者：策略智能体 (对应 0118多智能体设计框架3.0 - 领航者.csv)
  navigator: { 
    id: 'navigator', 
    name: '领航者·小航', 
    roleName: '策略导引', 
    tag: 'Guide', 
    color: 'bg-blue-100 text-blue-700', 
    avatar: 'https://api.dicebear.com/7.x/avataaars/svg?seed=Felix&backgroundColor=b6e3f4', 
    desc: '阅读目标设定、进度监控、元认知提醒', 
    actionLabel: '阅读规划', 
    actionPrompt: '@小航 我刚开始读这篇论文，请引导我进行“目标设定-结构预览”：', 
    icon: Compass 
  },

  // 2. 百科助手：知识智能体 (对应 0118多智能体设计框架3.0 - 百科助手.csv)
  encyclopedia: { 
    id: 'encyclopedia', 
    name: '百科助手·小科', 
    roleName: '知识百科', 
    tag: 'Wiki', 
    color: 'bg-indigo-100 text-indigo-700', 
    avatar: 'https://api.dicebear.com/7.x/avataaars/svg?seed=Jack&backgroundColor=e5e7eb', 
    desc: '术语三维解析(词义/背景/词根)、长难句翻译', 
    actionLabel: '术语/翻译', 
    actionPrompt: '@小科 请帮我解释一下这个术语（或翻译这段话）：', 
    icon: Library 
  },

  // 3. 脑洞工程师：推理智能体 (基于 SKIMIM 论文 - Elicit/Add/Distinguish 阶段)
  brainstormer: { 
    id: 'brainstormer', 
    name: '脑洞工程师·小脑', 
    roleName: '循证推理', 
    tag: 'Logic', 
    color: 'bg-orange-100 text-orange-700', 
    avatar: 'https://api.dicebear.com/7.x/avataaars/svg?seed=Aneka&backgroundColor=ffdfbf', 
    desc: '解决逻辑断层，引导 Bridging Inference 与观点辨析', 
    actionLabel: '逻辑探究', 
    actionPrompt: '@小脑 我觉得这段逻辑有点矛盾（或难以理解），能不能引导我进行推导？', 
    icon: Lightbulb 
  },

  // 4. 复盘官：整合智能体 (基于 SKIMIM 论文 - Reflect & Synthesize 阶段)
  reviewer: { 
    id: 'reviewer', 
    name: '复盘官·小盘', 
    roleName: '认知整合', 
    tag: 'Review', 
    color: 'bg-emerald-100 text-emerald-700', 
    avatar: 'https://api.dicebear.com/7.x/avataaars/svg?seed=Snuggles&backgroundColor=c0f2dc', 
    desc: '梳理“初始假设-关键证据-最终结论”的逻辑闭环', 
    actionLabel: '总结复盘', 
    actionPrompt: '@小盘 我理解得差不多了，请帮我梳理一下刚才的推理逻辑闭环：', 
    icon: ClipboardCheck 
  },

  system: { id: 'system', name: '系统通知', roleName: 'System', avatar: 'https://api.dicebear.com/7.x/initials/svg?seed=SY&backgroundColor=e5e7eb' }
}

// 默认激活：领航者(策略) 和 百科助手(工具)
const activeMemberIds = ref(['navigator', 'encyclopedia']) 
const showSettings = ref(false)
const inputMsg = ref('')
const chatBoxRef = ref(null)
const isLoading = ref(false)
const showMentionMenu = ref(false)

// 临时选中状态 (Settings 面板用)
const tempSelectedIds = ref([])

const getFullTime = () => { const now = new Date(); return `${now.getHours().toString().padStart(2,'0')}:${now.getMinutes().toString().padStart(2,'0')}`; }

const chatHistory = ref([
  { 
      id: 1, 
      role: 'ai', 
      agentId: 'navigator', 
      content: `你好 ${props.currentUser.name}！我是【领航者·小航】。🧭\n\n我们将基于 SKI (Scaffolded Knowledge Integration) 框架辅助你进行深度阅读。\n\n你可以：\n🔍 @小科 查询术语或翻译\n🧠 @小脑 在遇到逻辑障碍时进行推理解构\n📝 @小盘 在理解后生成逻辑复盘\n\n现在，你可以点击下方的“阅读规划”开始第一步！`, 
      time: getFullTime() 
  }
])

const lastSpeakerId = computed(() => {
    for (let i = chatHistory.value.length - 1; i >= 0; i--) {
        const msg = chatHistory.value[i]
        if (msg.role === 'ai' && msg.agentId !== 'system') return msg.agentId
    }
    return 'navigator'
})

const scrollToBottom = async () => { await nextTick(); if (chatBoxRef.value) chatBoxRef.value.scrollTop = chatBoxRef.value.scrollHeight; }

watch(inputMsg, (newVal) => {
  if (newVal.endsWith('@')) showMentionMenu.value = true
  else if (!newVal.includes('@')) showMentionMenu.value = false
})

const selectMention = (agentId) => {
  const shortName = agentPool[agentId].name.split('·')[1]
  inputMsg.value = inputMsg.value.slice(0, -1) + `@${shortName} ` 
  showMentionMenu.value = false
  document.getElementById('group-chat-input').focus()
}

// ==========================================
// 2. 聊天与网络请求
// ==========================================
const sendMessage = async () => {
  if (!inputMsg.value.trim() || isLoading.value) return
  const userText = inputMsg.value
  chatHistory.value.push({ id: Date.now(), role: 'user', content: userText, time: getFullTime() })
  inputMsg.value = ''
  showMentionMenu.value = false
  scrollToBottom()
  isLoading.value = true
  
  try {
    const formData = new FormData()
    formData.append('message', userText)
    formData.append('active_agents', JSON.stringify(activeMemberIds.value)) 
    formData.append('last_speaker', lastSpeakerId.value)
    
    // Moodle 环境参数
    const urlParams = new URLSearchParams(window.location.search)
    formData.append('cmid', urlParams.get('id') || 0)
    formData.append('user_name', props.currentUser.name)
    
    // 构建历史上下文
    const historyPayload = chatHistory.value.slice(-10).map(msg => { 
        const name = msg.role === 'user' ? '用户' : (agentPool[msg.agentId]?.name || 'System')
        return `[${name}]: ${msg.content}` 
    }).join('\n')
    formData.append('chat_history', historyPayload)

    // 发送请求
    const res = await fetch('chat_api.php', { method: 'POST', body: formData })
    
    // --- 模拟回复 (用于演示，实际会走 chat_api.php) ---
    // 实际部署时请确保 chat_api.php 能够根据 agentId 调用不同的 Prompt
    /*
    await new Promise(r => setTimeout(r, 1000));
    const mockReply = { role: 'navigator', reply: '收到，正在根据 SKI 框架为你生成回复...' };
    if (userText.includes('小脑')) mockReply.role = 'brainstormer';
    if (userText.includes('小科')) mockReply.role = 'encyclopedia';
    if (userText.includes('小盘')) mockReply.role = 'reviewer';
    const data = [mockReply];
    */
    // ------------------------------------------------

    const data = await res.json()
    const replies = Array.isArray(data) ? data : [data]
    
    // 逐条显示回复
    replies.forEach((reply, index) => { 
        const safeRole = agentPool[reply.role] ? reply.role : 'system'
        setTimeout(() => { 
            chatHistory.value.push({ 
                id: Date.now() + index, 
                role: 'ai', 
                agentId: safeRole, 
                content: reply.reply || '...', 
                time: getFullTime() 
            })
            scrollToBottom() 
        }, index * 800) 
    })
  } catch (e) { 
      chatHistory.value.push({ id: Date.now(), role: 'ai', agentId: 'system', content: '连接 AI 服务失败，请检查网络或后端配置。', time: getFullTime() }) 
      console.error(e)
  } finally { 
      isLoading.value = false
      scrollToBottom()
  }
}

// ==========================================
// 3. 群聊成员管理
// ==========================================
const openSettings = () => {
    tempSelectedIds.value = [...activeMemberIds.value]
    showSettings.value = true
}

const toggleTempMember = (id) => {
    if (mandatoryAgents.includes(id)) return 
    if (tempSelectedIds.value.includes(id)) {
        tempSelectedIds.value = tempSelectedIds.value.filter(m => m !== id)
    } else {
        tempSelectedIds.value.push(id)
    }
}

const confirmSettings = () => {
    const oldIds = activeMemberIds.value
    const newIds = tempSelectedIds.value
    
    const added = newIds.filter(id => !oldIds.includes(id))
    const removed = oldIds.filter(id => !newIds.includes(id))
    
    added.forEach(id => {
        chatHistory.value.push({ id: Date.now() + Math.random(), role: 'system', content: `👋 【${agentPool[id].name}】 加入了研讨。`, time: getFullTime() })
    })
    removed.forEach(id => {
        chatHistory.value.push({ id: Date.now() + Math.random(), role: 'system', content: `💨 【${agentPool[id].name}】 暂时离开了。`, time: getFullTime() })
    })
    
    if (added.length > 0 || removed.length > 0) {
        activeMemberIds.value = newIds
        scrollToBottom()
    }
    showSettings.value = false
}

// ==========================================
// 4. 外部接口 (响应 App.vue 的调用)
// ==========================================
const handleExternalRequest = async (payload) => {
    // payload: { type: 'action', agent: 'encyclopedia', prompt: '...' }
    
    const { agent, prompt } = payload
    
    // 1. 如果该智能体不在群里，自动拉入
    if (agent && !activeMemberIds.value.includes(agent)) {
        activeMemberIds.value.push(agent)
        chatHistory.value.push({ 
            id: Date.now(), 
            role: 'system', 
            content: `👋 响应你的请求，【${agentPool[agent].name}】 已自动加入研讨。`, 
            time: getFullTime() 
        })
    }

    // 2. 填入 prompt 并自动发送
    inputMsg.value = prompt
    await nextTick()
    sendMessage()
}

defineExpose({ handleExternalRequest })

const triggerQuickAction = (prompt) => {
    inputMsg.value = prompt
    document.getElementById('group-chat-input').focus()
}
</script>

<template>
  <div class="h-full w-full flex flex-col bg-white overflow-hidden relative">
    
    <div class="h-14 flex items-center justify-between px-4 bg-white border-b border-gray-100 shrink-0 select-none z-20">
       <div class="flex items-center gap-3">
         <div class="flex -space-x-2 hover:space-x-1 transition-all duration-300">
             <div v-for="id in activeMemberIds.slice(0, 3)" :key="id" class="relative group">
                 <img :src="agentPool[id].avatar" class="w-8 h-8 rounded-full border-2 border-white bg-gray-50 shadow-sm" />
             </div>
             <div v-if="activeMemberIds.length > 3" class="w-8 h-8 rounded-full bg-gray-100 border-2 border-white flex items-center justify-center text-[10px] text-gray-500 font-bold shadow-sm">+{{activeMemberIds.length-3}}</div>
         </div>
         <div class="flex flex-col">
             <div class="flex items-center gap-2"><span class="font-bold text-slate-800 text-sm">AI 研讨小组</span><div class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></div></div>
             <span class="text-[10px] text-gray-400">学术阅读伴侣 · {{ activeMemberIds.length }}人在线</span>
         </div>
       </div>
       <div class="flex items-center gap-1">
         <button @click="openSettings" class="p-1.5 rounded-full hover:bg-gray-100 text-gray-500 transition-colors" title="管理成员"><MoreHorizontal class="w-4 h-4"/></button>
         <button @click="$emit('close')" class="p-1.5 rounded-full hover:bg-red-50 text-gray-400 hover:text-red-500 transition-colors"><X class="w-4 h-4"/></button>
       </div>
    </div>

    <div v-if="showSettings" class="absolute inset-0 top-14 bg-white/98 backdrop-blur z-30 flex flex-col animate-in fade-in zoom-in-95 duration-200">
        <div class="px-6 py-4"><h3 class="font-bold text-gray-800 text-sm mb-1">阅读团队配置</h3><p class="text-xs text-gray-500">点击卡片召唤或遣散智能体。</p></div>
        <div class="flex-1 overflow-y-auto px-6 pb-20 custom-scrollbar">
            <div class="grid grid-cols-1 gap-2">
                <div v-for="(agent, key) in agentPool" :key="key" v-show="key !== 'system'" 
                     @click="toggleTempMember(agent.id)" 
                     class="group relative p-2.5 border rounded-xl flex items-center gap-3 cursor-pointer transition-all duration-200" 
                     :class="[
                        tempSelectedIds.includes(agent.id) ? 'border-blue-500 bg-blue-50/30 ring-1 ring-blue-500' : 'border-gray-100 bg-white hover:border-blue-200',
                        mandatoryAgents.includes(agent.id) ? 'opacity-80 cursor-not-allowed' : ''
                      ]"
                >
                    <img :src="agent.avatar" class="w-10 h-10 rounded-full bg-gray-50" />
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-0.5">
                            <span class="font-bold text-xs text-gray-800">{{ agent.name }}</span>
                            <span :class="`text-[8px] px-1 py-0.5 rounded font-medium ${agent.color}`">{{ agent.roleName }}</span>
                        </div>
                        <p class="text-[10px] text-gray-500 line-clamp-1">{{ agent.desc }}</p>
                    </div>
                    <div v-if="tempSelectedIds.includes(agent.id)" class="w-5 h-5 bg-blue-500 rounded-full flex items-center justify-center text-white shadow-sm">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                    </div>
                    <div v-else class="w-5 h-5 border-2 border-gray-200 rounded-full group-hover:border-blue-300"></div>
                </div>
            </div>
        </div>
        <div class="absolute bottom-0 left-0 right-0 p-4 bg-white border-t border-gray-100 flex justify-end">
            <button @click="confirmSettings" class="px-5 py-1.5 bg-gray-900 text-white text-xs font-bold rounded-lg hover:bg-black transition-transform active:scale-95 shadow-lg">完成配置</button>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto p-4 space-y-4 bg-[#f8f9fa] custom-scrollbar" ref="chatBoxRef">
       <template v-for="msg in chatHistory" :key="msg.id">
           <div v-if="msg.role === 'system'" class="flex justify-center my-2"><span class="text-[10px] text-gray-500 bg-gray-200/60 px-3 py-1 rounded-full border border-gray-200">{{ msg.content }}</span></div>
           
           <div v-else-if="msg.role === 'user'" class="flex flex-row-reverse items-start gap-2 group">
               <div class="w-8 h-8 rounded-lg bg-gray-900 flex items-center justify-center shrink-0 shadow-md text-white text-xs font-bold">我</div>
               <div class="flex flex-col items-end max-w-[85%]">
                   <div class="relative bg-blue-600 text-white px-3 py-2 rounded-xl rounded-tr-sm shadow-sm text-xs leading-relaxed border border-blue-500">{{ msg.content }}</div>
                   <span class="text-[9px] text-gray-400 mt-1 mr-1 opacity-0 group-hover:opacity-100 transition-opacity">{{ msg.time }}</span>
               </div>
           </div>
           
           <div v-else class="flex items-start gap-2 group animate-in slide-in-from-left-2 duration-300">
              <img :src="agentPool[msg.agentId]?.avatar || agentPool['system'].avatar" class="w-8 h-8 rounded-lg bg-white shrink-0 object-cover border border-gray-200 shadow-sm" />
              <div class="flex flex-col items-start max-w-[90%]">
                  <div class="flex items-center gap-2 mb-1 ml-1">
                      <span class="text-[10px] font-bold text-gray-600">{{ agentPool[msg.agentId]?.name || '系统' }}</span>
                      <span class="text-[8px] px-1 py-0.5 rounded bg-gray-100 text-gray-500">{{ agentPool[msg.agentId]?.roleName || 'System' }}</span>
                  </div>
                  <div class="relative bg-white text-gray-800 px-3 py-2 rounded-xl rounded-tl-sm shadow-sm text-xs leading-relaxed border border-gray-100 group-hover:shadow-md transition-shadow">
                      <div class="whitespace-pre-wrap">{{ msg.content }}</div>
                  </div>
                  <span class="text-[9px] text-gray-300 mt-1 ml-1 opacity-0 group-hover:opacity-100 transition-opacity">{{ msg.time }}</span>
              </div>
           </div>
       </template>
       <div v-if="isLoading" class="flex items-center gap-2 ml-2 mt-2"><div class="flex space-x-1"><div class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce"></div><div class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.1s"></div><div class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.2s"></div></div><span class="text-[10px] text-gray-400">正在思考...</span></div>
    </div>

    <div class="bg-white border-t border-gray-200 shrink-0 z-20 relative flex flex-col">
        
        <div class="flex gap-2 px-3 py-2 border-b border-gray-50 overflow-x-auto no-scrollbar">
            <template v-for="agentId in activeMemberIds" :key="agentId">
                <button v-if="agentPool[agentId] && agentPool[agentId].actionLabel" @click="triggerQuickAction(agentPool[agentId].actionPrompt)" class="flex items-center gap-1 px-2.5 py-1 bg-gray-50 text-gray-600 rounded-full text-[10px] hover:bg-gray-100 border border-gray-200 transition-colors shrink-0 font-medium whitespace-nowrap">
                    <component :is="agentPool[agentId].icon" class="w-3 h-3 text-gray-500" /> {{ agentPool[agentId].actionLabel }}
                </button>
            </template>
        </div>

        <div v-if="showMentionMenu" class="absolute bottom-full left-4 mb-2 w-48 bg-white rounded-xl shadow-xl border border-gray-100 overflow-hidden animate-in zoom-in-95 duration-100">
            <div class="px-3 py-2 bg-gray-50 text-[10px] text-gray-500 font-bold border-b border-gray-100">指定回复人</div>
            <button v-for="id in activeMemberIds" :key="id" @click="selectMention(id)" class="w-full text-left px-3 py-2 hover:bg-blue-50 flex items-center gap-2 text-xs transition-colors"><img :src="agentPool[id].avatar" class="w-5 h-5 rounded-full" /> <span class="text-gray-700">{{ agentPool[id].name }}</span></button>
        </div>

        <div class="p-3 pt-2">
           <textarea id="group-chat-input" v-model="inputMsg" @keydown.enter.prevent="sendMessage" class="w-full bg-transparent border-none text-xs focus:ring-0 resize-none h-12 p-0 custom-scrollbar placeholder:text-gray-300 leading-relaxed" placeholder="输入消息，或 @ 指定伙伴..."></textarea>
           <div class="flex justify-between items-center mt-1">
               <div class="flex gap-2"><button class="p-1.5 text-gray-400 hover:text-blue-500 hover:bg-blue-50 rounded-lg transition-colors" title="@某人" @click="inputMsg += '@'"><AtSign class="w-4 h-4" /></button></div>
               <button @click="sendMessage" :disabled="!inputMsg.trim() || isLoading" class="flex items-center gap-1 px-3 py-1 bg-gray-900 text-white text-[10px] font-bold rounded-lg hover:bg-black disabled:opacity-50 disabled:cursor-not-allowed transition-all active:scale-95">发送 <Send class="w-3 h-3" /></button>
           </div>
        </div>
    </div>
    
  </div>
</template>

<style scoped> 
.custom-scrollbar::-webkit-scrollbar { width: 4px; } 
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; } 
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 3px; } 
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #d1d5db; } 
.no-scrollbar::-webkit-scrollbar { display: none; } 
</style>