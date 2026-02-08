<script setup>
import { ref, onMounted, onUnmounted, nextTick, computed } from 'vue'
import LeftSidebar from './components/LeftSidebar.vue'
import MiddlePdfReader from './components/MiddlePdfReader.vue' 
import RightSidebar from './components/RightSiderbar/index.vue'
import { Sparkles, Users, MousePointer2, BookOpenCheck } from 'lucide-vue-next'

// ===========================
// 1. 数据状态
// ===========================
const isDataReady = ref(false)
const isTeacher = ref(false) // 是否为教师

// 🔥 修改点：不要直接写死 true，先简单判断 URL 参数
const urlParams = new URLSearchParams(window.location.search)
const initialAction = urlParams.get('action')
const isPortalView = ref(!(initialAction === 'read' || initialAction === 'write')) 

const taskInfo = ref({ title: '加载中...', intro: '' })
const currentUser = ref({ name: '同学', avatar: '' })
const pdfUrl = ref('') 
const moduleId = ref(0) 
const pdfOutline = ref([]) 
const isOutlineLoading = ref(true)

const annotations = ref([])      
const totalReadSeconds = ref(0)  
const currentPage = ref(1)
let sessionFocusLost = 0

const currentSessionId = ref(1)
const currentSessionUid = ref('')
const chatApiUrl = ref('')
const ajaxUrl = ref('')

const activeTool = ref('cursor')

const lastTriggeredSection = ref('')

const triggerRules = ref([])

// 🔥🔥🔥 [修改] 记录已触发过的规则 ID (无论是否完成，只要触发过就不再自动弹) 🔥🔥🔥
const executedRuleIds = ref(new Set())

// 🔥🔥🔥 [修改] 待处理的任务挑战池 🔥🔥🔥
const pendingChallenges = ref([])

// 🔥🔥🔥 [新增] 当前正在进行的挑战 ID (话题标签) 🔥🔥🔥
// 当用户点击“开始回答”时，这个值会被设置为对应的 ruleId
const activeChallengeId = ref(0)

// ===========================
// 2. 计算属性
// ===========================
// 笔记灵感：高亮(黄色) + 批注(红色) 都计数
const highlightCount = computed(() => {
  return annotations.value ? annotations.value.filter(a => a.type === 'highlight' || a.type === 'note').length : 0
})

// ===========================
// 3. 界面状态与闲置检测
// ===========================
const isAiSidebarOpen = ref(true) 
const rightSidebarRef = ref(null)
const middleReaderRef = ref(null) 

const showNudgeBubble = ref(false)
const nudgeText = ref('')
let lastUserActionTime = Date.now()
let idleCheckTimer = null
const COMFORT_MESSAGES = ["休息一下吧 🌳", "有疑问问 AI 💡", "加油！💪"]

const updateLastActionTime = () => { lastUserActionTime = Date.now(); showNudgeBubble.value = false }
const startIdleChecker = () => {
    idleCheckTimer = setInterval(() => {
        if (Date.now() - lastUserActionTime > 600000 && !isAiSidebarOpen.value) {
            nudgeText.value = COMFORT_MESSAGES[Math.floor(Math.random() * COMFORT_MESSAGES.length)]
            showNudgeBubble.value = true
        }
    }, 1000)
}

const handleVisibilityChange = () => {
    if (document.hidden) {
        sessionFocusLost++
    }
}

// ===========================
// 4. 交互逻辑
// ===========================

// 进入阅读器
const enterReader = () => {
  isPortalView.value = false
}

const handleOutlineLoaded = (outline) => {
    if (pdfOutline.value.length > 0) return
    pdfOutline.value = outline
    isOutlineLoading.value = false 
}

const handleOutlineClick = (page) => {
    if (middleReaderRef.value) middleReaderRef.value.scrollToPage(page)
}

// 🔥🔥🔥 核心逻辑：智能体触发规则 (包含去重、卡片展示、待办提醒、数据保存) 🔥🔥🔥
const handleSectionSwitch = async (sectionTitle) => {
    if (!triggerRules.value || triggerRules.value.length === 0) return;

    // 1. 查找匹配规则
    const matchedRule = triggerRules.value.find(rule => 
        sectionTitle.toLowerCase().includes(rule.section_keyword.toLowerCase())
    );

    if (!matchedRule) return;

    // 2. 核心去重：如果该规则ID已经触发过，直接终止
    if (executedRuleIds.value.has(matchedRule.id)) {
        return;
    }

    // 3. 标记为已触发
    executedRuleIds.value.add(matchedRule.id);

    console.log(`🔔 命中新规则！触发卡片: "${matchedRule.trigger_prompt}"`);

    // 4. 强制打开右侧栏
    if (!isAiSidebarOpen.value) isAiSidebarOpen.value = true

    // 5. 待办提醒逻辑
    let reminderText = "";
    if (pendingChallenges.value.length > 0) {
        const count = pendingChallenges.value.length;
        reminderText = `\n\n⚠️ **领航者温馨提醒**：同学，你前面还有 ${count} 个思维挑战选择了“稍后处理”，别忘了回顾哦！`;
    }

    // 6. 构造特殊消息对象 (Card Type)
    const cardMessage = {
        type: 'challenge_card', // 核心标识
        content: matchedRule.trigger_prompt + reminderText, // 卡片正文 + 提醒
        ruleId: matchedRule.id,
        section: sectionTitle,
        status: 'pending', // 初始状态
        // 🔥 [新增] 把数据库里的 reference_content 带上，虽然前端不显示，但保持数据完整
        referenceContent: matchedRule.reference_content || '' 
    };

    // 7. 在前端显示
    if (rightSidebarRef.value && rightSidebarRef.value.chatHistory) {
        rightSidebarRef.value.chatHistory.push({
            id: Date.now() + Math.random(),
            role: 'ai',
            agentId: 'navigator', // 领航者
            ...cardMessage, // 展开属性
            time: new Date().toLocaleTimeString('en-US', { hour12: false, hour: "2-digit", minute: "2-digit" })
        });

        nextTick(() => {
            if (rightSidebarRef.value.scrollToBottom) rightSidebarRef.value.scrollToBottom()
        })
    }

    // 8. 🔥🔥🔥 将触发记录保存到数据库 (持久化) 🔥🔥🔥
    try {
        const formData = new FormData();
        // 将整个对象转为 JSON 字符串存入 content 字段
        formData.append('message', JSON.stringify(cardMessage)); 
        formData.append('agent_id', 'navigator');
        formData.append('cmid', moduleId.value || 0);
        // 🔥🔥🔥 关键：带上 rule_id，让后端知道这是一条任务记录 🔥🔥🔥
        formData.append('rule_id', matchedRule.id);
        
        const chatUrl = chatApiUrl.value || 'chat_api.php'
        await fetch(`${chatUrl}?action=save_log`, { method: 'POST', body: formData });
        
        // 既然触发了，无论用户是否立刻回答，先加入待办池 (直到用户点击“开始回答”)
        if (!pendingChallenges.value.includes(matchedRule.id)) {
            pendingChallenges.value.push(matchedRule.id);
        }
        
    } catch (e) {
        console.error("保存触发记录失败:", e);
    }
}

// 🔥🔥🔥 [修改] 处理卡片交互 (监听 Sidebar 传来的事件) 🔥🔥🔥
const handleCardAction = async (payload) => {
    const { ruleId, action, prompt } = payload;
    console.log("⚡️ 卡片交互:", action, prompt);

    if (action === 'answer') {
        // 🅰️ 用户点击“开始回答”
        
        // 1. 设置当前活跃话题 (Topic_Tag)
        activeChallengeId.value = ruleId;
        
        // 2. 从 pending 列表移除
        pendingChallenges.value = pendingChallenges.value.filter(id => id !== ruleId);

        // 3. 切换到“脑洞工程师”智能体并追问
        if (rightSidebarRef.value) {
            rightSidebarRef.value.handleExternalRequest({
                type: 'action',
                agent: 'idea_engineer', // 切换到脑洞工程师
                // 🔥 [核心修改] 发送干净的指令，隐秘指令(Reference)将由后端 chat_api.php 注入
                prompt: `@小脑 我已准备好挑战这个问题：“${prompt}”。`,
                ruleId: ruleId // 显式传递
            });
        }

    } else if (action === 'later') {
        // 🅱️ 用户点击“稍后处理”
        if (!pendingChallenges.value.includes(ruleId)) {
            pendingChallenges.value.push(ruleId);
        }
        // 如果点击了稍后，且当前正好在聊这个话题，则清空活跃状态
        if (activeChallengeId.value === ruleId) {
            activeChallengeId.value = 0;
        }
        console.log("📥 已加入待处理任务");
    }
}

const findSectionByPage = (structure, pageNum) => {
    if (!Array.isArray(structure)) return null;
    for (const item of structure) {
        if (parseInt(item.page) === pageNum) {
            return item;
        }
        if (item.children || item.items) {
            const found = findSectionByPage(item.children || item.items, pageNum);
            if (found) return found;
        }
    }
    return null;
}

const handleCreateAnnotation = async (newAnn) => {
    const tempId = Date.now();
    const localAnn = { ...newAnn, id: tempId, session_id: currentSessionId.value }; 
    annotations.value.push(localAnn);

    try {
        const formData = new FormData();
        formData.append('page', newAnn.page);
        formData.append('type', newAnn.type);
        formData.append('quote', newAnn.quote);
        formData.append('position_data', newAnn.position_data); 
        formData.append('color', newAnn.color || '');
        formData.append('note', newAnn.note || '');
        formData.append('session_id', currentSessionId.value);
        
        const base = ajaxUrl.value || 'ajax.php'
        const res = await fetch(`${base}?action=create_annotation&id=${moduleId.value}`, {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        
        if (json.status === 'success' && json.data && json.data.id) {
            const index = annotations.value.findIndex(a => a.id === tempId);
            if (index !== -1) annotations.value[index].id = json.data.id;
            // #region agent log
            fetch('http://localhost:7245/ingest/a2cd8cc6-3ab9-472d-a750-ad20d0da1930',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'App.vue:create_annotation:success',message:'annotation saved',data:{serverId:json.data.id},timestamp:Date.now(),sessionId:'debug-session',hypothesisId:'H1'})}).catch(()=>{})
            // #endregion
        }
    } catch (e) {
        console.error("保存标注失败:", e);
    }
}

const handleDeleteAnnotation = async (id) => {
    if (!id) return;
    annotations.value = annotations.value.filter(a => a.id !== id && a.tempId !== id)
    
    try {
        await fetch(`${ajaxUrl.value || 'ajax.php'}?action=delete_annotation&id=${moduleId.value}&ann_id=${id}`, { method: 'POST' });
    } catch (e) {
        console.error("删除失败:", e);
    }
}

const handleUpdateAnnotation = async ({ id, note }) => {
    const target = annotations.value.find(a => a.id === id);
    if (target) target.note = note;

    try {
        const formData = new FormData();
        formData.append('ann_id', id);
        formData.append('note', note);
        await fetch(`${ajaxUrl.value || 'ajax.php'}?action=update_annotation_note&id=${moduleId.value}`, {
            method: 'POST',
            body: formData
        });
    } catch (e) {
        console.error("笔记保存失败:", e);
    }
}

const handlePageChange = (page) => {
    currentPage.value = page;
    if (pdfOutline.value && pdfOutline.value.length > 0) {
        const matchedSection = findSectionByPage(pdfOutline.value, page);
        if (matchedSection && matchedSection.title) {
            handleSectionSwitch(matchedSection.title);
        }
    }
}

const handlePdfInteraction = (payload) => {
    updateLastActionTime()
    if (payload.type === 'explain' || payload.type === 'ask') {
        isAiSidebarOpen.value = true
        let targetAgent = 'noah'
        let message = ''
        if (payload.type === 'explain') message = `@noah 请解释：\n\n"${payload.text}"`
        else if (payload.type === 'ask') { targetAgent = 'sogo'; message = `@sogo 我有疑问：\n\n"${payload.text}"\n\n` }
        
        nextTick(() => {
            rightSidebarRef.value?.handleExternalRequest({ type: 'action', agent: targetAgent, prompt: message })
        })
    }
}

const toggleAiSidebar = () => { isAiSidebarOpen.value = !isAiSidebarOpen.value }

let heartbeatTimer = null
const startHeartbeat = () => {
    if (heartbeatTimer) clearInterval(heartbeatTimer)
    heartbeatTimer = setInterval(() => {
        if (!moduleId.value) return
        const intervalSec = 10 
        const lostCountToSend = sessionFocusLost
        sessionFocusLost = 0 
        fetch(`${ajaxUrl.value || 'ajax.php'}?action=update_progress&id=${moduleId.value}&seconds=${intervalSec}&page=${currentPage.value}&focus_lost=${lostCountToSend}`)
            .then(() => { })
            .catch(err => console.error("💔 心跳失败", err))
    }, 10000) 
}

onMounted(async () => {
  document.addEventListener("visibilitychange", handleVisibilityChange)
  window.addEventListener('mousemove', updateLastActionTime)
  window.addEventListener('keydown', updateLastActionTime)
  startIdleChecker()
  startHeartbeat() 

  const appEl = document.getElementById('app')
  const urlParams = new URLSearchParams(window.location.search)
  if (urlParams.get('id')) moduleId.value = urlParams.get('id')

  // 获取 Moodle 后端传递的状态
  if (appEl && appEl.dataset) {
    chatApiUrl.value = appEl.dataset.chatApiUrl || ''
    ajaxUrl.value = appEl.dataset.ajaxUrl || ''
    // 角色识别与门户逻辑
    isTeacher.value = appEl.dataset.isTeacher === '1'
    const action = urlParams.get('action')
    // 🔥 核心修正：如果后端指定了 action (如 write 或 read)，或者 initialAction 有效，直接关闭门户
    if (action === 'write' || action === 'read' || initialAction === 'write' || initialAction === 'read') {
        isPortalView.value = false
    }

    taskInfo.value = { 
        title: appEl.dataset.title || '任务', 
        intro: appEl.dataset.intro || '' 
    }
    currentUser.value = { name: appEl.dataset.username || '同学', avatar: appEl.dataset.useravatar || '' }
    try {
        const pdfList = JSON.parse(appEl.dataset.pdflist || '[]')
        if (pdfList.length > 0) pdfUrl.value = pdfList[0].url
    } catch (e) {}
  }

  // 高亮回显：先拉取 get_task_info（含 annotations）再显示阅读器，与 aireader 一致，避免刷新后高亮不显示
  if (moduleId.value) {
      try {
          const base = ajaxUrl.value || 'ajax.php'
          const res = await fetch(`${base}?action=get_task_info&id=${moduleId.value}&_t=${Date.now()}`)
          const json = await res.json()
          if (json.status === 'success' && json.data) {
              taskInfo.value.title = json.data.title || taskInfo.value.title || '无标题'
              taskInfo.value.intro = json.data.intro ? String(json.data.intro) : ''
              if (json.data.pdfUrl) pdfUrl.value = json.data.pdfUrl
              if (json.data.structure && Array.isArray(json.data.structure)) {
                  pdfOutline.value = json.data.structure
                  isOutlineLoading.value = false
              }
              if (json.data.annotations && Array.isArray(json.data.annotations)) {
                  annotations.value = json.data.annotations
                  // #region agent log
                  const arr = json.data.annotations
                  const first = arr[0]
                  fetch('http://localhost:7245/ingest/a2cd8cc6-3ab9-472d-a750-ad20d0da1930',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'App.vue:get_task_info:annotations_set',message:'annotations from get_task_info',data:{count:arr.length,firstId:first?.id,hasPositionData:!!first?.position_data,positionDataLen:typeof first?.position_data==='string'?first.position_data.length:0},timestamp:Date.now(),sessionId:'debug-session',hypothesisId:'H2','runId':'post-fix'})}).catch(()=>{})
                  // #endregion
              }
              if (json.data.total_read_seconds) {
                  totalReadSeconds.value = parseInt(json.data.total_read_seconds)
              }
              if (json.data.session_id) {
                  currentSessionId.value = parseInt(json.data.session_id)
              }
              if (json.data.trigger_rules) {
                  triggerRules.value = json.data.trigger_rules
                  triggerRules.value.forEach(rule => {
                      if (rule.user_status === 'pending') {
                          executedRuleIds.value.add(rule.id)
                          pendingChallenges.value.push(rule.id)
                      } else if (rule.user_status === 'completed') {
                          executedRuleIds.value.add(rule.id)
                      }
                  })
              }
          }
      } catch (e) {
          console.error('>>> App: 初始化数据请求失败', e)
      }
      isDataReady.value = true
  }
})

onUnmounted(() => {
    if (heartbeatTimer) clearInterval(heartbeatTimer)
    if (idleCheckTimer) clearInterval(idleCheckTimer)
    document.removeEventListener("visibilitychange", handleVisibilityChange)
    window.removeEventListener('mousemove', updateLastActionTime)
    window.removeEventListener('keydown', updateLastActionTime)
})
</script>

<template>
  <div v-if="isPortalView" class="fixed inset-0 z-[200] flex flex-col items-center justify-center bg-[#f8fafc] overflow-y-auto p-8 font-sans">
    <div class="max-w-5xl w-full">
      <div class="text-center mb-16 animate-in fade-in slide-in-from-bottom-4 duration-700">
        <div class="w-20 h-20 bg-indigo-600 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-xl shadow-indigo-200">
          <Sparkles class="w-10 h-10 text-white" />
        </div>
        <h1 class="text-4xl font-extrabold text-slate-900 mb-4 tracking-tight">{{ taskInfo.title }}</h1>
        <p class="text-slate-500 text-lg max-w-2xl mx-auto leading-relaxed">
          欢迎进入 AI 学术伴读空间。四位不同专长的 AI 伙伴将全程协助你完成深度阅读挑战。
        </p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-12">
        
        <div class="bg-white rounded-[2rem] p-8 shadow-sm border border-slate-100 flex flex-col hover:shadow-xl transition-all duration-300 group">
          <div class="w-14 h-14 bg-blue-50 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
            <Users class="w-7 h-7 text-blue-600" />
          </div>
          <h3 class="text-xl font-bold text-slate-800 mb-4">伴读战队</h3>
          <ul class="space-y-4 text-sm text-slate-600 flex-1">
            <li class="flex items-start gap-3">
              <span class="font-bold text-blue-600 shrink-0">领航者:</span> 规划阅读路径，推送思维卡片。
            </li>
            <li class="flex items-start gap-3">
              <span class="font-bold text-indigo-600 shrink-0">百科助手:</span> 术语深度解析，精准学术翻译。
            </li>
            <li class="flex items-start gap-3">
              <span class="font-bold text-orange-600 shrink-0">脑洞师:</span> 引导逻辑推理，攻克理解断层。
            </li>
            <li class="flex items-start gap-3">
              <span class="font-bold text-emerald-600 shrink-0">复盘官:</span> 梳理逻辑闭环，生成复盘报告。
            </li>
          </ul>
        </div>

        <div class="bg-white rounded-[2rem] p-8 shadow-sm border border-slate-100 flex flex-col hover:shadow-xl transition-all duration-300 group">
          <div class="w-14 h-14 bg-green-50 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
            <MousePointer2 class="w-7 h-7 text-green-600" />
          </div>
          <h3 class="text-xl font-bold text-slate-800 mb-4">交互指南</h3>
          <div class="space-y-6 text-sm text-slate-600 flex-1">
            <div>
              <p class="font-bold text-slate-800 mb-1">划线选词：</p>
              <p>在阅读器中选中任何单词或句子，即可召唤 AI 百科进行实时翻译或概念解释。</p>
            </div>
            <div>
              <p class="font-bold text-slate-800 mb-1">思维挑战：</p>
              <p>当阅读到关键段落，领航者会弹出挑战卡片。通过与脑洞工程师对话来完善你的理解。</p>
            </div>
          </div>
        </div>

        <div @click="enterReader" class="bg-gradient-to-br from-orange-50 to-white rounded-[2rem] p-8 shadow-sm border border-orange-100 flex flex-col items-center text-center cursor-pointer hover:shadow-xl transition-all duration-300 group">
          <div class="w-14 h-14 bg-orange-100 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
            <BookOpenCheck class="w-7 h-7 text-orange-600" />
          </div>
          <h3 class="text-xl font-bold text-slate-800 mb-4">开始研读</h3>
          <p class="text-slate-500 text-sm mb-10 leading-relaxed">
            准备好与 AI 伙伴一起探索论文了吗？点击下方按钮立即开启沉浸式研读。
          </p>
          <button class="mt-auto w-full py-4 bg-orange-600 text-white rounded-2xl font-bold text-lg shadow-lg shadow-orange-200 hover:bg-orange-700 active:scale-95 transition-all">
            立即开启
          </button>
        </div>

      </div>
      <p class="text-center text-slate-400 text-xs">AI 学术伴读系统 · 基于多智能体协同架构</p>
    </div>
  </div>

  <div v-else class="fixed inset-0 z-[100] flex w-full h-screen bg-[#eef2f6] overflow-hidden font-sans text-slate-800">
    
    <div class="shrink-0 w-[260px] border-r border-gray-800 bg-[#0f172a] z-30 flex flex-col shadow-xl">
        <LeftSidebar 
            v-if="isDataReady"
            class="flex-1 h-full min-h-0" 
            :task="taskInfo" 
            :current-user="currentUser" 
            :structure="pdfOutline" 
            :loading="isOutlineLoading" 
            :total-seconds="totalReadSeconds"
            :revision-count="highlightCount"
            @item-click="handleOutlineClick"
            @section-switch="handleSectionSwitch"
        />
    </div>

    <div class="flex-1 min-w-0 bg-[#eef2f6] relative flex flex-col z-10 items-center">
        <div class="w-full h-full">
            <MiddlePdfReader 
                ref="middleReaderRef"
                v-model:activeTool="activeTool"
                :pdf-url="pdfUrl" 
                :module-id="moduleId"
                :annotations="annotations"
                :skip-outline-parse="pdfOutline.length > 0"
                @create-annotation="handleCreateAnnotation"
                @delete-annotation="handleDeleteAnnotation"
                @update-annotation="handleUpdateAnnotation"
                @text-selected="handlePdfInteraction"
                @outline-loaded="handleOutlineLoaded"
                @page-change="handlePageChange"
            />
        </div>
    </div>

    <div class="shrink-0 bg-white border-l border-gray-200 transition-all duration-300 ease-[cubic-bezier(0.25,0.8,0.25,1)] relative z-20 flex flex-col shadow-[-4px_0_24px_rgba(0,0,0,0.05)]"
        :style="{ width: isAiSidebarOpen ? '500px' : '0px', overflow: 'hidden' }">
        <div class="w-[500px] h-full"> 
            <RightSidebar 
                v-if="isDataReady"
                ref="rightSidebarRef" 
                :is-open="isAiSidebarOpen" 
                @close="isAiSidebarOpen = false" 
                :current-user="currentUser" 
                :task="taskInfo"
                :pending-tasks="pendingChallenges"
                :active-challenge-id="activeChallengeId"
                :chat-api-url="chatApiUrl"
                @card-action="handleCardAction" 
            />
        </div>
    </div>

    <div v-if="!isAiSidebarOpen" class="fixed bottom-8 right-8 z-[50] cursor-pointer group" @click="toggleAiSidebar">
        <div class="w-14 h-14 rounded-full bg-slate-900 shadow-2xl flex items-center justify-center hover:scale-110 active:scale-95 border border-slate-700">
            <Sparkles class="w-6 h-6 text-white animate-pulse" />
        </div>
    </div>
  </div>
</template>

<style scoped>
/* 动画 */
.animate-in {
  animation: animate-in 0.5s ease-out;
}
@keyframes animate-in {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>