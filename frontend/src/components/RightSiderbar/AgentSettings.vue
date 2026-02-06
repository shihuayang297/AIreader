<script setup>
import { ref, watch } from 'vue'
import { agentPool, mandatoryAgents } from './useAgentConfig.js'

const props = defineProps({
  show: Boolean,
  currentActiveIds: Array
})

const emit = defineEmits(['update:activeIds', 'close'])

const tempSelectedIds = ref([])

// 每次打开时，同步当前的选中状态
watch(() => props.show, (val) => {
  if (val) tempSelectedIds.value = [...props.currentActiveIds]
})

const toggleTempMember = (id) => {
  if (mandatoryAgents.includes(id)) return 
  if (tempSelectedIds.value.includes(id)) {
    tempSelectedIds.value = tempSelectedIds.value.filter(m => m !== id)
  } else {
    tempSelectedIds.value.push(id)
  }
}

const confirm = () => {
  emit('update:activeIds', tempSelectedIds.value)
  emit('close')
}
</script>

<template>
  <div v-if="show" class="absolute inset-0 top-14 bg-white/95 backdrop-blur-md z-30 flex flex-col animate-in fade-in zoom-in-95 duration-200">
    <div class="px-6 py-4 border-b border-gray-50">
      <h3 class="font-bold text-gray-800 text-sm mb-1">阅读团队配置</h3>
      <p class="text-xs text-gray-500">点击卡片召唤或遣散智能体，打造你的专属阅读小组。</p>
    </div>
    
    <div class="flex-1 overflow-y-auto px-6 py-4 pb-24 custom-scrollbar">
      <div class="grid grid-cols-1 gap-3">
        <div v-for="(agent, key) in agentPool" :key="key" v-show="key !== 'system'" 
             @click="toggleTempMember(agent.id)" 
             class="group relative p-3 border rounded-xl flex items-center gap-3 cursor-pointer transition-all duration-200" 
             :class="[
               tempSelectedIds.includes(agent.id) ? 'border-blue-500 bg-blue-50/40 ring-1 ring-blue-500' : 'border-gray-100 bg-white hover:border-blue-200 hover:shadow-sm',
               mandatoryAgents.includes(agent.id) ? 'opacity-70 cursor-not-allowed' : ''
             ]"
        >
          <img :src="agent.avatar" class="w-11 h-11 rounded-full bg-white border border-gray-100" />
          <div class="flex-1">
            <div class="flex items-center gap-2 mb-0.5">
              <span class="font-bold text-sm text-gray-800">{{ agent.name }}</span>
              <span :class="`text-[9px] px-1.5 py-0.5 rounded font-medium ${agent.color}`">{{ agent.roleName }}</span>
            </div>
            <p class="text-[11px] text-gray-500 line-clamp-1">{{ agent.desc }}</p>
          </div>
          
          <div v-if="tempSelectedIds.includes(agent.id)" class="w-5 h-5 bg-blue-500 rounded-full flex items-center justify-center text-white shadow-sm transition-transform scale-100">
            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
          </div>
          <div v-else class="w-5 h-5 border-2 border-gray-200 rounded-full group-hover:border-blue-300 transition-colors"></div>
        </div>
      </div>
    </div>
    
    <div class="absolute bottom-0 left-0 right-0 p-4 bg-white/90 border-t border-gray-100 flex justify-end backdrop-blur">
      <button @click="confirm" class="px-6 py-2 bg-slate-900 text-white text-xs font-bold rounded-xl hover:bg-black transition-transform active:scale-95 shadow-lg">
        完成配置
      </button>
    </div>
  </div>
</template>