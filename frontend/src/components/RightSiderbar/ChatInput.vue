<script setup>
import { ref, watch, nextTick } from 'vue'
import { AtSign, Send } from 'lucide-vue-next'
import { agentPool } from './useAgentConfig.js'

const props = defineProps({
  activeMemberIds: Array,
  loading: Boolean
})

const emit = defineEmits(['send'])

const inputMsg = ref('')
const showMentionMenu = ref(false)

// 监听 @ 输入
watch(inputMsg, (newVal) => {
  if (newVal.endsWith('@')) showMentionMenu.value = true
  else if (!newVal.includes('@')) showMentionMenu.value = false
})

const selectMention = (agentId) => {
  // 提取名字中的简称，例如 "领航者·小师" -> "小师"
  const shortName = agentPool[agentId].name.split('·')[1] || agentPool[agentId].name
  inputMsg.value = inputMsg.value.slice(0, -1) + `@${shortName} ` 
  showMentionMenu.value = false
  document.getElementById('group-chat-input').focus()
}

const triggerQuickAction = (prompt) => {
  inputMsg.value = prompt
  document.getElementById('group-chat-input').focus()
}

const handleSend = () => {
  if (!inputMsg.value.trim() || props.loading) return
  emit('send', inputMsg.value)
  inputMsg.value = ''
  showMentionMenu.value = false
}

// 暴露给父组件调用，用于外部填入prompt
const setInput = (val) => { inputMsg.value = val }
defineExpose({ setInput, handleSend })
</script>

<template>
  <div class="bg-white border-t border-gray-100 shrink-0 z-20 relative flex flex-col shadow-[0_-4px_20px_rgba(0,0,0,0.02)]">
    
    <div class="flex gap-2 px-3 py-2.5 border-b border-gray-50 overflow-x-auto no-scrollbar">
      <template v-for="agentId in activeMemberIds" :key="agentId">
        <button v-if="agentPool[agentId] && agentPool[agentId].actionLabel" 
                @click="triggerQuickAction(agentPool[agentId].actionPrompt)" 
                class="flex items-center gap-1.5 px-3 py-1.5 bg-gray-50 text-gray-600 rounded-full text-[10px] hover:bg-white hover:text-blue-600 hover:shadow-sm border border-gray-100 hover:border-blue-100 transition-all shrink-0 font-medium whitespace-nowrap">
          <component :is="agentPool[agentId].icon" class="w-3 h-3 opacity-70" /> 
          {{ agentPool[agentId].actionLabel }}
        </button>
      </template>
    </div>

    <div v-if="showMentionMenu" class="absolute bottom-full left-4 mb-2 w-48 bg-white rounded-xl shadow-xl border border-gray-100 overflow-hidden animate-in zoom-in-95 duration-100 z-50">
      <div class="px-3 py-2 bg-gray-50 text-[10px] text-gray-400 font-bold border-b border-gray-100">指定回复人</div>
      <button v-for="id in activeMemberIds" :key="id" @click="selectMention(id)" class="w-full text-left px-3 py-2.5 hover:bg-blue-50 flex items-center gap-2 text-xs transition-colors group">
        <img :src="agentPool[id].avatar" class="w-5 h-5 rounded-full border border-gray-100" /> 
        <span class="text-gray-700 group-hover:text-blue-700 font-medium">{{ agentPool[id].name }}</span>
      </button>
    </div>

    <div class="p-4 pt-3">
      <div class="relative bg-gray-50 rounded-xl border border-transparent focus-within:border-blue-100 focus-within:bg-white focus-within:ring-2 focus-within:ring-blue-50 transition-all duration-200">
        <textarea 
          id="group-chat-input" 
          v-model="inputMsg" 
          @keydown.enter.prevent="handleSend" 
          class="w-full bg-transparent border-none text-[13px] focus:ring-0 resize-none h-14 p-3 custom-scrollbar placeholder:text-gray-400 leading-relaxed" 
          placeholder="输入消息，或 @ 指定伙伴..."
        ></textarea>
        
        <div class="flex justify-between items-center px-2 pb-2">
          <div class="flex gap-1">
            <button class="p-1.5 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="@某人" @click="inputMsg += '@'">
              <AtSign class="w-4 h-4" />
            </button>
          </div>
          <button @click="handleSend" 
                  :disabled="!inputMsg.trim() || loading" 
                  class="flex items-center gap-1.5 px-4 py-1.5 bg-slate-900 text-white text-[11px] font-bold rounded-lg hover:bg-black disabled:opacity-40 disabled:cursor-not-allowed transition-all active:scale-95 shadow-sm hover:shadow-md">
            发送 <Send class="w-3 h-3" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>