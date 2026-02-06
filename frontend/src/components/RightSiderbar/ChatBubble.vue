<script setup>
import { ref, computed } from 'vue'
import { agentPool } from './useAgentConfig.js'
// 🔥 新增：引入按钮图标 PlayCircle 和 Clock
import { Copy, Check, PlayCircle, Clock } from 'lucide-vue-next'

const props = defineProps({
  msg: Object,
  currentUserAvatar: String // 这里会接收到 avatar.png
})

// 🔥 新增：定义事件，用于将按钮点击传给父组件
const emit = defineEmits(['card-action'])

const isUser = computed(() => props.msg.role === 'user')
const isSystem = computed(() => props.msg.role === 'system')
const agent = computed(() => agentPool[props.msg.agentId] || agentPool['system'])

// 默认头像 (如果本地图片加载失败)
const defaultUserAvatar = 'https://api.dicebear.com/9.x/lorelei/svg?seed=User&backgroundColor=f1f5f9'

const copied = ref(false)
const copyToClipboard = async () => {
  try {
    await navigator.clipboard.writeText(props.msg.content)
    copied.value = true
    setTimeout(() => copied.value = false, 2000)
  } catch (err) { console.error(err) }
}

// 🔥 新增：处理卡片按钮点击逻辑
const handleAction = (action) => {
  emit('card-action', {
    ruleId: props.msg.ruleId,
    action: action,
    prompt: props.msg.content // 把问题内容带回去，方便追问
  })
}
</script>

<template>
  <div v-if="isSystem" class="flex justify-center my-3">
    <span class="text-[10px] text-gray-400 bg-gray-100/60 px-3 py-0.5 rounded-full border border-gray-100 tracking-wide">
      {{ msg.content }}
    </span>
  </div>

  <div v-else-if="isUser" class="flex flex-row-reverse items-start gap-2 mb-4 group px-1">
    <img :src="currentUserAvatar || defaultUserAvatar" class="w-8 h-8 rounded-full border-2 border-white shadow-sm bg-white object-cover shrink-0" />
    
    <div class="flex flex-col items-end max-w-[85%]">
      <div class="relative bg-slate-800 text-white px-3.5 py-2 rounded-2xl rounded-tr-sm shadow-sm text-[13px] leading-relaxed">
        {{ msg.content }}
      </div>
      <span class="text-[9px] text-gray-300 mt-1 mr-1 opacity-40">{{ msg.time }}</span>
    </div>
  </div>

  <div v-else class="flex items-start gap-2 mb-4 group animate-in slide-in-from-left-2 duration-300 w-full px-1">
    <img :src="agent.avatar" class="w-8 h-8 rounded-full bg-white shrink-0 object-cover border border-gray-100 shadow-sm mt-1" />
    
    <div class="flex flex-col items-start max-w-[90%] min-w-0">
      <div class="flex items-center gap-1.5 mb-1 ml-1">
        <span class="text-[11px] font-bold text-slate-700">{{ agent.name }}</span>
        <span :class="`text-[9px] px-1.5 py-[1px] rounded-md font-medium bg-opacity-20 ${agent.color.replace('text-', 'bg-').replace('50', '100')} ${agent.color}`">
          {{ agent.roleName }}
        </span>
      </div>
      
      <div class="relative group/bubble w-full">
        
        <div v-if="msg.type === 'challenge_card'" 
             class="bg-white border border-orange-200 rounded-xl shadow-sm overflow-hidden w-full transition-shadow duration-300 hover:shadow-md">
            
            <div class="bg-orange-50/50 px-3 py-2 border-b border-orange-100 flex items-center gap-2">
                <div class="bg-orange-500 text-white text-[10px] px-1.5 py-0.5 rounded font-bold">思维挑战</div>
                <span class="text-[10px] text-orange-800 font-medium truncate opacity-80">章节：{{ msg.section }}</span>
            </div>

            <div class="px-3.5 py-3 text-[13px] leading-relaxed text-slate-700 whitespace-pre-wrap break-words">
                {{ msg.content }}
            </div>

            <div v-if="msg.status === 'pending'" class="px-3 pb-3 flex gap-2">
                <button 
                    @click="handleAction('answer')"
                    class="flex-1 flex items-center justify-center gap-1 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 text-[11px] font-bold rounded border border-indigo-200 transition-colors"
                >
                    <PlayCircle class="w-3 h-3" />
                    开始回答
                </button>
                <button 
                    @click="handleAction('later')"
                    class="flex-1 flex items-center justify-center gap-1 py-1.5 bg-white hover:bg-gray-50 text-gray-500 text-[11px] font-medium rounded border border-gray-200 transition-colors"
                >
                    <Clock class="w-3 h-3" />
                    稍后处理
                </button>
            </div>
        </div>

        <div v-else class="bg-white text-slate-700 px-3.5 py-2.5 rounded-2xl rounded-tl-sm shadow-[0_1px_4px_rgba(0,0,0,0.04)] text-[13px] leading-relaxed border border-slate-100">
          <div class="whitespace-pre-wrap break-words">{{ msg.content }}</div>
        </div>

        <button 
          v-if="msg.type !== 'challenge_card'"
          @click="copyToClipboard"
          class="absolute -bottom-5 right-0 p-1 text-gray-300 hover:text-slate-600 transition-opacity opacity-0 group-hover/bubble:opacity-100"
          title="复制"
        >
          <Check v-if="copied" class="w-3 h-3 text-emerald-500" />
          <Copy v-else class="w-3 h-3" />
        </button>
      </div>
      
      <span class="text-[9px] text-gray-300 mt-1 ml-1 opacity-40">{{ msg.time }}</span>
    </div>
  </div>
</template>