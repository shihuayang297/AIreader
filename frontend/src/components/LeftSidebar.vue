<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { Clock, History, List, ChevronRight, Loader2 } from 'lucide-vue-next'
import logoImg from './logo.png'
import avatarImg from './avatar.png'

const props = defineProps({
  structure: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  // totalSeconds: 数据库里存的历史总时长
  totalSeconds: { type: Number, default: 0 }, 
  // revisionCount: 高亮总数 (需要在父组件传入 annotations.length)
  revisionCount: { type: Number, default: 0 }, 
  currentUser: { type: Object, default: () => ({ name: '同学' }) }
})

// 🔥🔥🔥 修改点1：注册新事件 'section-switch'
const emit = defineEmits(['item-click', 'section-switch'])

// ===========================
// ⏱️ 动态计时逻辑
// ===========================
const sessionSeconds = ref(0) // 本次会话增加的秒数
let timerInterval = null

// 格式化时间函数 (秒 -> 小时/分)
const formatTime = (totalSec) => {
  const s = Math.floor(totalSec)
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  // 如果不足1分钟，显示“<1分钟”或者具体的秒数，这里按分钟显示
  if (h === 0 && m === 0) return '刚刚'
  return h > 0 ? `${h}小时${m}分` : `${m}分钟`
}

// 计算最终显示的秒数 = 历史累计 + 本次时长
const displaySeconds = computed(() => {
  return props.totalSeconds + sessionSeconds.value
})

// 🔥🔥🔥 修改点2：新增点击处理函数
const handleSectionClick = (item) => {
  // 1. 原有逻辑：通知父组件翻页 (PDF跳转)
  emit('item-click', item.page)
  
  // 2. 新增逻辑：通知父组件“章节切换了”，触发领航者小师
  // 参数：item.title (例如 "1. Introduction")
  if (item.title) {
      console.log("👆 点击了章节:", item.title)
      emit('section-switch', item.title)
  }
}

onMounted(() => {
  // 启动计时器，每秒+1
  timerInterval = setInterval(() => {
    sessionSeconds.value++
  }, 1000)
})

onUnmounted(() => {
  // 清理计时器防止内存泄漏
  if (timerInterval) clearInterval(timerInterval)
})

const progress = 65 
</script>

<template>
  <aside class="w-[280px] flex-shrink-0 flex flex-col z-30 shadow-[4px_0_20px_rgba(0,0,0,0.1)] relative overflow-hidden text-white bg-[#1e293b]" style="font-family: 'STKaiti', 'KaiTi', '华文楷体', serif;">
    <div class="absolute inset-0 bg-gradient-to-b from-[#334155] to-[#0f172a] z-0"></div>
    
    <div class="h-20 flex items-center px-4 border-b border-white/10 relative z-10">
      <img :src="logoImg" alt="Logo" class="w-10 h-10 mr-3 object-contain drop-shadow-md">
      <div class="flex flex-col">
          <span class="text-lg font-bold tracking-wider leading-none mb-1 text-slate-100">京师论文AI伴读平台</span>
          <span class="text-xs text-slate-400 uppercase tracking-widest font-sans font-bold">BNU Smart Reader</span>
      </div>
    </div>
    
    <div class="p-4 relative z-10 border-b border-white/5 bg-white/[0.02]">
       <div class="flex items-center gap-4 mb-6">
          <div class="relative w-20 h-20 flex items-center justify-center flex-shrink-0">
              <svg class="transform -rotate-90 w-20 h-20" viewBox="0 0 80 80">
                  <circle cx="40" cy="40" r="34" stroke="rgba(255,255,255,0.1)" stroke-width="6" fill="transparent" />
                  <circle cx="40" cy="40" r="34" stroke="#eab308" stroke-width="6" fill="transparent" stroke-dasharray="213" :stroke-dashoffset="213 - (213 * progress) / 100" stroke-linecap="round" />
              </svg>
              <div class="absolute flex flex-col items-center justify-center inset-0">
                  <span class="text-2xl font-bold text-slate-100 leading-none">{{ progress }}%</span>
                  <span class="text-[10px] text-slate-400 mt-0.5 font-sans">研读率</span>
              </div>
          </div>
          <div class="flex flex-col justify-center">
              <div class="text-lg font-bold text-slate-100 mb-1 leading-tight">加油，<br>{{ currentUser.name }}!</div>
              <div class="text-xs text-slate-400 leading-tight mt-1">格物致知，<br>博学笃行~</div>
          </div>
       </div>
       
       <div class="grid grid-cols-2 gap-2">
          <div class="bg-white/5 p-2.5 rounded-xl border border-white/5 backdrop-blur-sm">
              <div class="flex items-center gap-2 mb-1">
                  <div class="w-6 h-6 rounded-full bg-orange-500/20 flex items-center justify-center text-orange-400"><Clock class="w-3.5 h-3.5" /></div>
                  <span class="text-xs text-slate-300 font-bold">累积研读</span>
              </div>
              <div class="text-xl font-bold text-slate-100 pl-1">{{ formatTime(displaySeconds) }}</div>
          </div>
          
          <div class="bg-white/5 p-2.5 rounded-xl border border-white/5 backdrop-blur-sm">
              <div class="flex items-center gap-2 mb-1">
                  <div class="w-6 h-6 rounded-full bg-rose-500/20 flex items-center justify-center text-rose-400"><History class="w-3.5 h-3.5" /></div>
                  <span class="text-xs text-slate-300 font-bold">笔记灵感</span>
              </div>
              <div class="text-xl font-bold text-slate-100 pl-1">{{ revisionCount }} <span class="text-xs font-normal opacity-60">次</span></div>
          </div>
       </div>
    </div>
    
    <div class="flex-1 overflow-y-auto px-3 py-4 space-y-4 custom-scrollbar relative z-10">
      <div>
        <div class="flex items-center justify-between mb-3 px-1">
           <div class="flex items-center gap-2">
               <div class="w-7 h-7 rounded-lg bg-indigo-500/20 flex items-center justify-center text-indigo-300 shadow-sm flex-shrink-0"><List class="w-4 h-4 fill-current opacity-80" /></div>
               <span class="text-lg font-bold text-slate-200 tracking-widest whitespace-nowrap">论文结构</span>
           </div>
           <span class="text-[10px] text-slate-500 bg-white/5 px-2 py-0.5 rounded-full">Outline</span>
        </div>
        
        <div class="space-y-2">
          <div v-if="loading" class="text-center py-8 text-slate-400 text-sm flex flex-col items-center">
              <Loader2 class="w-6 h-6 animate-spin mb-2 opacity-50"/>
              正在解析目录...
          </div>

          <template v-else-if="structure && structure.length > 0">
              <div v-for="(item, index) in structure" :key="index" 
                   @click="handleSectionClick(item)"
                   class="group p-3 rounded-xl bg-white/5 border border-white/5 hover:bg-white/10 hover:border-indigo-500/30 transition-all duration-200 backdrop-blur-sm cursor-pointer flex items-center justify-between">
                <div class="flex items-start gap-2.5 overflow-hidden">
                  <div class="w-1.5 h-1.5 rounded-full bg-indigo-400 mt-2 flex-shrink-0 group-hover:bg-indigo-300 transition-colors"></div>
                  <div class="text-[15px] font-bold text-slate-300 group-hover:text-white leading-tight truncate pr-2 transition-colors">{{ item.title }}</div>
                </div>
                <div class="flex items-center text-slate-500 group-hover:text-indigo-300 transition-colors">
                    <span v-if="item.page" class="text-xs mr-1 font-sans opacity-70">P{{ item.page }}</span>
                    <ChevronRight class="w-3.5 h-3.5 opacity-0 group-hover:opacity-100 transition-opacity" />
                </div>
              </div>
          </template>
          
          <div v-else class="text-center py-8 text-slate-500 text-sm">
              <div class="mb-2 opacity-50 text-2xl">📑</div>
              <span>该文档未检测到目录结构</span>
              <div class="text-xs opacity-50 mt-1">这可能是因为PDF为纯图片或未内嵌大纲</div>
          </div>
        </div>
      </div>
    </div>

    <div class="px-4 py-4 border-t border-white/10 bg-[#0f172a]/50 backdrop-blur-lg relative z-10">
        <div class="flex items-center gap-3">
            <div class="w-14 h-14 rounded-full border-[3px] border-white/10 flex items-center justify-center overflow-hidden shadow-lg bg-slate-700 relative group">
                <img :src="avatarImg" alt="学生头像" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500">
            </div>
            <div class="flex flex-col justify-center min-w-0">
                <span class="text-2xl font-bold text-slate-100 tracking-wide leading-none mb-1 truncate">{{ currentUser.name }}</span>
                <div class="flex items-center gap-1.5">
                    <div class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
                    <span class="text-sm text-slate-400 font-sans">专注学习中</span>
                </div>
            </div>
        </div>
    </div>
  </aside>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.02); }
.custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 2px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.25); }
</style>