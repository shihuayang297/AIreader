<script setup>
import { ref } from 'vue'
import { Sparkles, SpellCheck, Fingerprint, Users, X, Bot, SendHorizonal, GripHorizontal } from 'lucide-vue-next'
// 1. 引入拖拽逻辑
import { useDraggable } from '../composables/useDraggable.js'

const emit = defineEmits(['toggle-ai'])

// 2. 初始化拖拽：默认距离右边 60px，距离顶部 100px (比多智能体稍微低一点)
const { x, y, startDrag } = useDraggable(60, 150)

const activeTool = ref(null)
const isPanelOpen = ref(false)
const chatInput = ref('')
const chatHistory = ref([{ role: 'ai', content: '您好！我是京师智慧学术助手。请选择上方工具进行辅助。' }])

const toggleTool = (name) => {
  if (name === 'multi-agent') {
      isPanelOpen.value = false
      activeTool.value = null
      emit('toggle-ai')
      return
  }
  if (activeTool.value === name && isPanelOpen.value) {
    isPanelOpen.value = false
    activeTool.value = null
  } else {
    activeTool.value = name
    isPanelOpen.value = true
  }
}

const sendMessage = () => {
    if(!chatInput.value.trim()) return
    chatHistory.value.push({ role: 'user', content: chatInput.value })
    chatInput.value = ''
    setTimeout(() => { chatHistory.value.push({ role: 'ai', content: '分析中...' }) }, 800)
}
</script>

<template>
  <div class="flex h-full relative z-40">
    
    <div 
         v-if="isPanelOpen"
         class="fixed w-[350px] h-[500px] bg-white border border-gray-300 shadow-2xl z-[9999] flex flex-col rounded-xl overflow-hidden"
         :style="{ left: x + 'px', top: y + 'px' }"
    >
        <div 
            @mousedown="startDrag"
            class="h-12 flex items-center justify-between px-4 border-b border-gray-100 bg-gray-50 cursor-move select-none"
        >
           <div class="flex items-center gap-2 font-bold text-gray-800">
             <GripHorizontal class="w-4 h-4 text-gray-400 mr-1"/>
             <span>{{ activeTool === 'chat' ? '智能学术助手' : (activeTool === 'grammar' ? '语法纠错' : '辅助工具') }}</span>
           </div>
           <button @click="isPanelOpen=false; activeTool=null" @mousedown.stop class="p-1 hover:bg-gray-200 rounded-md text-gray-500"><X class="w-4 h-4"/></button>
        </div>

        <div class="flex-1 overflow-y-auto bg-white p-4 custom-scrollbar">
            <div v-if="activeTool === 'chat'" class="flex flex-col h-full">
                <div class="flex-1 space-y-4 pb-4">
                    <div v-for="(msg, i) in chatHistory" :key="i" :class="['flex gap-3', msg.role==='user'?'flex-row-reverse':'']">
                        <div :class="['w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 border', msg.role==='ai'?'bg-white border-blue-100':'bg-blue-100 border-blue-200']">
                            <Bot v-if="msg.role==='ai'" class="w-4 h-4 text-[#003366]"/>
                            <div v-else class="w-4 h-4 text-xs font-bold text-blue-800 flex items-center justify-center">ME</div>
                        </div>
                        <div :class="['p-3 rounded-xl text-sm leading-relaxed max-w-[85%] shadow-sm', msg.role==='user'?'bg-[#003366] text-white rounded-br-sm':'bg-gray-50 text-gray-700 border border-gray-100 rounded-bl-sm']">
                            {{ msg.content }}
                        </div>
                    </div>
                </div>
                <div class="mt-auto sticky bottom-0">
                    <div class="relative">
                        <input v-model="chatInput" @keyup.enter="sendMessage" type="text" placeholder="输入您的问题..." class="w-full bg-white border border-gray-200 rounded-full py-2.5 pl-4 pr-10 text-sm focus:ring-2 focus:ring-blue-100 outline-none shadow-sm">
                        <button @click="sendMessage" class="absolute right-1.5 top-1.5 p-1.5 bg-[#003366] text-white rounded-full hover:bg-[#002a50]"><SendHorizonal class="w-3.5 h-3.5"/></button>
                    </div>
                </div>
            </div>
            <div v-else class="flex flex-col items-center justify-center h-full text-gray-400 opacity-60">
                <p class="text-sm font-medium">功能模块开发中...</p>
            </div>
        </div>
    </div>

    <div class="w-[50px] bg-white border-l border-gray-200 flex flex-col items-center py-4 gap-4 z-40 shadow-sm flex-shrink-0">
        <button @click="toggleTool('chat')" :class="['w-10 h-10 rounded-xl flex items-center justify-center transition-all', activeTool==='chat'?'bg-blue-50 text-[#003366]':'text-gray-400 hover:bg-gray-100']"><Sparkles class="w-5 h-5"/></button>
        <button @click="toggleTool('grammar')" :class="['w-10 h-10 rounded-xl flex items-center justify-center transition-all', activeTool==='grammar'?'bg-red-50 text-red-600':'text-gray-400 hover:bg-gray-100']"><SpellCheck class="w-5 h-5"/></button>
        <button @click="toggleTool('check')" :class="['w-10 h-10 rounded-xl flex items-center justify-center transition-all', activeTool==='check'?'bg-amber-50 text-amber-600':'text-gray-400 hover:bg-gray-100']"><Fingerprint class="w-5 h-5"/></button>
        <div class="w-6 h-px bg-gray-200 my-1"></div>
        <button @click="toggleTool('multi-agent')" class="w-10 h-10 rounded-xl flex items-center justify-center transition-all text-gray-400 hover:bg-purple-50 hover:text-purple-600"><Users class="w-5 h-5"/></button>
    </div>
  </div>
</template>