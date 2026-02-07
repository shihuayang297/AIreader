<script setup>
import { ref, nextTick, onMounted, onUnmounted } from 'vue'
import { MoreHorizontal, Pin } from 'lucide-vue-next'
import { agentPool, defaultActiveIds } from './useAgentConfig.js'
import ChatBubble from './ChatBubble.vue'
import AgentSettings from './AgentSettings.vue'
import ChatInput from './ChatInput.vue'
import localAvatar from '../avatar.png'

const props = defineProps({
  isOpen: Boolean,
  currentUser: { type: Object, default: () => ({ name: '同学', avatar: '' }) },
  task: { type: Object, default: () => ({ title: '学术阅读任务' }) },
  pendingTasks: {
    type: Array,
    default: () => []
  },
  // 🔥🔥🔥 [新增] 接收当前活跃的挑战 ID 🔥🔥🔥
  activeChallengeId: {
    type: Number,
    default: 0
  },
  chatApiUrl: { type: String, default: '' }
})

const emit = defineEmits(['card-action'])

const activeMemberIds = ref([...defaultActiveIds]) 
const showSettings = ref(false)
const chatBoxRef = ref(null)
const isLoading = ref(false)
const inputRef = ref(null)

const loadingText = ref('正在思考...')
let loadingInterval = null

const startLoadingAnimation = () => {
  const steps = ['正在读题...', '正在检索知识...', '正在组织语言...', '正在输入...']
  let i = 0; loadingText.value = steps[0];
  loadingInterval = setInterval(() => { i = (i + 1) % steps.length; loadingText.value = steps[i] }, 1000)
}
const stopLoadingAnimation = () => { if (loadingInterval) clearInterval(loadingInterval) }

const getFullTime = () => { const now = new Date(); return `${now.getHours().toString().padStart(2,'0')}:${now.getMinutes().toString().padStart(2,'0')}`; }

// 定义默认欢迎语
const defaultWelcomeMsg = { 
      id: 1, 
      role: 'ai', 
      agentId: 'navigator', 
      content: `你好 ${props.currentUser.name}！我是【领航者·小师】。\n我们是你的学术论文伴读小分队，随时准备协助你进行深度阅读。\n\n你可以 @小科 查术语，或者点击下方按钮开始规划！`, 
      time: getFullTime() 
}

const chatHistory = ref([defaultWelcomeMsg])

const scrollToBottom = async () => { await nextTick(); if (chatBoxRef.value) chatBoxRef.value.scrollTop = chatBoxRef.value.scrollHeight; }

// ==========================================
// 历史记录加载逻辑
// ==========================================
const loadHistory = async () => {
    try {
        const urlParams = new URLSearchParams(window.location.search)
        const cmid = urlParams.get('id') || 0

        // #region agent log
        fetch('http://localhost:7245/ingest/a2cd8cc6-3ab9-472d-a750-ad20d0da1930', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ location: 'RightSiderbar:loadHistory start', message: 'load history', data: { cmid }, timestamp: Date.now(), sessionId: 'debug-session', hypothesisId: 'B' }) }).catch(() => {})
        // #endregion

        const apiBase = props.chatApiUrl || 'chat_api.php'
        const res = await fetch(`${apiBase}?action=load_history&cmid=${cmid}`)
        if (res.ok) {
            const result = await res.json()

            // #region agent log
            fetch('http://localhost:7245/ingest/a2cd8cc6-3ab9-472d-a750-ad20d0da1930', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ location: 'RightSiderbar:loadHistory result', message: 'history loaded', data: { status: result.status, dataLen: Array.isArray(result.data) ? result.data.length : 0 }, timestamp: Date.now(), sessionId: 'debug-session', hypothesisId: 'B' }) }).catch(() => {})
            // #endregion

            if (result.status === 'success' && Array.isArray(result.data) && result.data.length > 0) {
                // 遍历数据，尝试解析 JSON 内容 (针对 challenge_card)
                chatHistory.value = result.data.map(msg => {
                    try {
                        if (msg.content && typeof msg.content === 'string' && msg.content.trim().startsWith('{')) {
                            const parsed = JSON.parse(msg.content);
                            if (parsed.type === 'challenge_card') {
                                return { ...msg, ...parsed }; 
                            }
                        }
                    } catch (e) { }
                    return msg;
                });
                scrollToBottom()
            }
        }
    } catch (e) {
        console.error("加载历史记录失败:", e)
    }
}

onMounted(() => {
    loadHistory()
})

const onCardAction = (payload) => {
    emit('card-action', payload)
}

// 🔥🔥🔥 [修改] 增加 forceAgentId 参数，用于按钮点击时强制指定回复者 🔥🔥🔥
const handleSendMessage = async (text, forceAgentId = null) => {
  chatHistory.value.push({ id: Date.now(), role: 'user', content: text, time: getFullTime() })
  scrollToBottom()
   
  isLoading.value = true
  startLoadingAnimation()

  // 1. 确定目标回复者 (逻辑优化)
  let targetResponder = 'navigator' // 默认值

  if (forceAgentId) {
      // 🔥 如果强制指定了 (比如通过按钮)，直接使用
      targetResponder = forceAgentId
  } else {
      // 否则才去文本里找 @
      for (const id of activeMemberIds.value) {
          const name = agentPool[id].name
          const shortName = name.split('·')[1] 
          if (text.includes(`@${name}`) || text.includes(`@${shortName}`)) {
              targetResponder = id
              break
          }
      }
  }

  try {
    const formData = new FormData()
    formData.append('message', text)
    formData.append('active_agents', JSON.stringify(activeMemberIds.value)) 
    formData.append('last_speaker', targetResponder)
    
    // 🔥🔥🔥 [修改] 传递 activeChallengeId 给后端，用于话题隔离 🔥🔥🔥
    formData.append('rule_id', props.activeChallengeId)

    const urlParams = new URLSearchParams(window.location.search)
    formData.append('cmid', urlParams.get('id') || 0)
    formData.append('user_name', props.currentUser.name)
    
    // 动态决定记忆长度
    const isReviewTask = targetResponder === 'reviewer' || text.includes('复盘') || text.includes('总结');
    const historyLimit = isReviewTask ? -100 : -15;

    const historyPayload = chatHistory.value.slice(historyLimit).map(msg => { 
        const name = msg.role === 'user' ? '用户' : (agentPool[msg.agentId]?.name || 'System')
        return `[${name}]: ${msg.content}` 
    }).join('\n')

    formData.append('chat_history', historyPayload)

    // #region agent log
    fetch('http://localhost:7245/ingest/a2cd8cc6-3ab9-472d-a750-ad20d0da1930', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ location: 'RightSiderbar:before fetch chat_api', message: 'send message', data: { cmid: urlParams.get('id') || 0, textLen: (text || '').length }, timestamp: Date.now(), sessionId: 'debug-session', hypothesisId: 'D' }) }).catch(() => {})
    // #endregion

    const apiBase = props.chatApiUrl || 'chat_api.php'
    console.log('[RightSiderbar] chat POST URL:', apiBase)
    const res = await fetch(apiBase, { method: 'POST', body: formData })
    const rawText = await res.text()
    console.log('[RightSiderbar] chat POST response:', res.status, res.url)
    let data = null
    if (res.ok && rawText) {
        try { data = JSON.parse(rawText) } catch (e) { console.warn('[RightSiderbar] chat response not JSON:', rawText.slice(0, 300)) }
    }
    if (data && !Array.isArray(data)) {
        console.warn('[RightSiderbar] chat response is object not array, raw (first 500):', rawText.slice(0, 500))
    }

    // #region agent log
    fetch('http://localhost:7245/ingest/a2cd8cc6-3ab9-472d-a750-ad20d0da1930', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ location: 'RightSiderbar:after fetch chat_api', message: 'response', data: { resOk: res.ok, dataIsArray: Array.isArray(data), dataLen: Array.isArray(data) ? data.length : (data ? 1 : 0), rawPreview: rawText ? rawText.slice(0, 200) : '' }, timestamp: Date.now(), sessionId: 'debug-session', hypothesisId: 'D' }) }).catch(() => {})
    // #endregion

    // 兜底模拟逻辑
    if (!data || (Array.isArray(data) && data.length === 0) || data.status === 'error') {
        console.warn("API异常，启用前端模拟...")
        await new Promise(r => setTimeout(r, 800)) 
        let replyContent = "我收到你的消息了！"
        if (targetResponder === 'navigator') replyContent = "收到！作为领航者，建议你先关注一下摘要部分的核心观点。"
        if (targetResponder === 'idea_engineer') replyContent = "这个问题很有趣，你觉得背后的逻辑是什么？" // 模拟脑洞回复
        data = [{ role: targetResponder, reply: replyContent }]
    }

    let replies = Array.isArray(data) ? data : [data]

    // 智能去重与去噪
    const hasRealAgentReply = replies.some(r => r.role !== 'system' && agentPool[r.role])

    if (hasRealAgentReply) {
        replies = replies.filter(r => r.role !== 'system')
    }

    replies.forEach((reply, index) => { 
        let finalRole = reply.role
        
        if (!agentPool[finalRole] || finalRole === 'system') {
            finalRole = targetResponder
        }

        let text = (reply.reply ?? reply.content ?? '').trim() || ''
        if (text === '' || text === '...') text = '（暂无回复，请稍后再试）'
        setTimeout(() => { 
            chatHistory.value.push({ 
                id: Date.now() + index, 
                role: 'ai', 
                agentId: finalRole, 
                content: text, 
                time: getFullTime() 
            })
            scrollToBottom() 
        }, index * 800) 
    })

  } catch (e) { 
      setTimeout(() => {
          chatHistory.value.push({ 
              id: Date.now(), role: 'ai', agentId: targetResponder, 
              content: "网络连接有点小波动，但我听到了！", 
              time: getFullTime() 
          })
          scrollToBottom()
      }, 1000)
  } finally { 
      stopLoadingAnimation()
      isLoading.value = false
      scrollToBottom()
  }
}

const handleExternalRequest = async (payload) => {
    const { agent, prompt } = payload
    if (agent && !activeMemberIds.value.includes(agent)) {
        activeMemberIds.value.push(agent)
        chatHistory.value.push({ 
            id: Date.now(), role: 'system', agentId: 'system',
            content: `👋 【${agentPool[agent].name}】 已加入。`, time: getFullTime() 
        })
    }
    // 🔥 如果是 action 指令，说明要直接调用 sendMessage (用于“开始回答”按钮)
    if (payload.type === 'action') {
        // 🔥 这里传入第二个参数 agent (即 idea_engineer)，强制指定回复者
        handleSendMessage(prompt, agent)
    } else if (inputRef.value) {
        // 否则只是填入输入框
        inputRef.value.setInput(prompt)
        await nextTick()
        inputRef.value.handleSend()
    }
}

const handleSettingsUpdate = (newIds) => {
    activeMemberIds.value = newIds
    scrollToBottom()
}

onUnmounted(() => stopLoadingAnimation())

defineExpose({ 
    chatHistory, 
    scrollToBottom, 
    handleExternalRequest 
})
</script>

<template>
  <div class="h-full w-full flex flex-col bg-white overflow-hidden relative border-l border-gray-100">
    
    <div class="h-16 flex items-center justify-between px-5 bg-white border-b border-gray-100 shrink-0 select-none z-20 shadow-sm relative">
       <div class="absolute inset-0 bg-gradient-to-r from-blue-50/30 to-white z-0 pointer-events-none"></div>

       <div class="flex items-center gap-3 relative z-10">
         <div class="flex -space-x-3 transition-all duration-300 py-1 pl-1">
             <div v-for="id in activeMemberIds.slice(0, 4)" :key="id" class="relative group transition-transform hover:scale-110 hover:z-10">
                 <img :src="agentPool[id].avatar" class="w-10 h-10 rounded-full border-[3px] border-white bg-indigo-50 shadow-sm" />
             </div>
             <div v-if="activeMemberIds.length > 4" class="w-10 h-10 rounded-full bg-white border-[3px] border-gray-100 flex items-center justify-center text-[10px] text-gray-400 font-bold shadow-sm">
                +{{activeMemberIds.length-4}}
             </div>
         </div>
         
         <div class="flex flex-col">
             <div class="flex items-center gap-2">
                 <span class="font-bold text-slate-800 text-[17px] font-kaiti tracking-wide">学术论文AI伴读小分队</span>
                 <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                 </span>
             </div>
             <span class="text-[10px] text-slate-400 font-sans mt-0.5">当前在线人数: {{ activeMemberIds.length }}</span>
         </div>
       </div>
       
       <button @click="showSettings = true" class="p-2 rounded-xl hover:bg-slate-100 text-gray-400 hover:text-slate-700 transition-all active:scale-95 relative z-10">
         <MoreHorizontal class="w-5 h-5"/>
       </button>
    </div>

    <div v-if="pendingTasks && pendingTasks.length > 0" 
         class="bg-yellow-50 border-b border-yellow-100 px-4 py-2 flex items-center justify-between animate-fade-in z-10">
        <div class="flex items-center gap-2 text-xs text-yellow-700 font-medium">
            <Pin class="w-3.5 h-3.5 fill-yellow-700" />
            <span>你还有 {{ pendingTasks.length }} 个待处理的思维挑战任务</span>
        </div>
        <button class="text-xs text-yellow-600 hover:text-yellow-800 underline">点击展开</button>
    </div>

    <AgentSettings 
        :show="showSettings" 
        :current-active-ids="activeMemberIds"
        @update:activeIds="handleSettingsUpdate"
        @close="showSettings = false"
    />

    <div class="flex-1 overflow-y-auto p-4 space-y-2 bg-[#f8fafc] custom-scrollbar" ref="chatBoxRef">
       <ChatBubble 
         v-for="msg in chatHistory" 
         :key="msg.id" 
         :msg="msg" 
         :current-user-avatar="localAvatar" 
         @card-action="onCardAction"
       />
       
       <div v-if="isLoading" class="flex items-center gap-2 ml-3 mt-4 animate-pulse">
           <img :src="agentPool['navigator'].avatar" class="w-6 h-6 rounded-full opacity-50" />
           <span class="text-[10px] text-slate-400 font-medium font-mono">{{ loadingText }}</span>
       </div>
    </div>

    <ChatInput 
        ref="inputRef"
        :active-member-ids="activeMemberIds" 
        :loading="isLoading" 
        @send="handleSendMessage" 
    />
    
  </div>
</template>

<style scoped> 
.font-kaiti { font-family: "STKaiti", "华文楷体", "KaiTi", serif; }
.custom-scrollbar::-webkit-scrollbar { width: 5px; } 
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; } 
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; } 
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; } 
/* 🔥 新增淡入动画 */
.animate-fade-in { animation: fadeIn 0.3s ease-in-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
</style>