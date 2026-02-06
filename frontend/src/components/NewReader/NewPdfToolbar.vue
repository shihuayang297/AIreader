<script setup>
import { computed } from 'vue'
import { 
  ZoomIn, ZoomOut, GraduationCap, 
  MousePointer2, Highlighter, Sparkles, 
} from 'lucide-vue-next'

const props = defineProps({
  pageCount: Number,
  saveStatus: String, // 'saving' | 'success' | 'error' | 'ready'
  activeTool: String, // 'cursor' | 'highlight' | 'ai'
  scale: Number
})

const emit = defineEmits(['update:activeTool', 'zoom-in', 'zoom-out'])

const saveStatusText = computed(() => {
  if (props.saveStatus === 'saving') return '同步中'
  if (props.saveStatus === 'success') return '已保存'
  if (props.saveStatus === 'error') return '离线'
  return '就绪'
})
</script>

<template>
  <div class="h-14 px-5 flex items-center justify-between bg-white/95 backdrop-blur-xl border-b border-slate-200/60 shadow-[0_2px_15px_-3px_rgba(0,0,0,0.02)] z-30 transition-all duration-300">
    
    <div class="flex items-center gap-3 select-none">
      <div class="w-8 h-8 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-600">
        <GraduationCap class="w-4.5 h-4.5" stroke-width="2" />
      </div>
      <div class="flex flex-col justify-center h-full">
        <span class="text-[15px] font-bold text-slate-700 font-kaiti leading-tight tracking-wide">
          论文沉浸阅读
        </span>
        <span class="text-[10px] text-slate-400 font-kaiti scale-95 origin-left">
          BNU Smart Reader · {{ pageCount || '-' }} 页
        </span>
      </div>
    </div>

    <div class="absolute left-1/2 -translate-x-1/2 flex items-center bg-slate-100/80 p-0.5 rounded-lg border border-slate-200/50">
      <button 
        v-for="tool in [
          { id: 'cursor', icon: MousePointer2, label: '浏览' },
          { id: 'highlight', icon: Highlighter, label: '标注' },
          { id: 'ai', icon: Sparkles, label: 'AI伴读' }
        ]" 
        :key="tool.id"
        @click="$emit('update:activeTool', tool.id)"
        class="relative px-3 py-1 rounded-md flex items-center gap-1.5 transition-all duration-200 group font-kaiti"
        :class="activeTool === tool.id 
          ? 'bg-white text-indigo-600 shadow-sm ring-1 ring-black/5' 
          : 'text-slate-500 hover:text-slate-900 hover:bg-slate-200/50'"
      >
        <component 
          :is="tool.icon" 
          class="w-3.5 h-3.5 transition-transform duration-200"
          :class="activeTool === tool.id ? 'scale-110' : 'group-hover:scale-105'" 
        />
        <span class="text-[13px] font-medium tracking-wide">{{ tool.label }}</span>
      </button>
    </div>

    <div class="flex items-center gap-4">
      
      <div class="flex items-center gap-1.5 transition-opacity duration-300" :class="saveStatus ? 'opacity-100' : 'opacity-0'">
        <div class="w-1.5 h-1.5 rounded-full" 
             :class="{
               'bg-blue-500 animate-pulse': saveStatus === 'saving',
               'bg-emerald-500': saveStatus === 'success',
               'bg-red-400': saveStatus === 'error',
               'bg-slate-300': saveStatus === 'ready'
             }"></div>
        <span class="text-xs text-slate-400 font-kaiti pt-0.5">{{ saveStatusText }}</span>
      </div>

      <div class="h-4 w-px bg-slate-200"></div>

      <div class="flex items-center gap-1">
        <button @click="$emit('zoom-out')" class="p-1.5 rounded-md text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors" title="缩小">
          <ZoomOut class="w-4 h-4" />
        </button>
        <span class="text-xs font-mono w-8 text-center text-slate-500 select-none">{{ Math.round(scale * 100) }}%</span>
        <button @click="$emit('zoom-in')" class="p-1.5 rounded-md text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors" title="放大">
          <ZoomIn class="w-4 h-4" />
        </button>
      </div>

    </div>
  </div>
</template>

<style scoped>
.font-kaiti { font-family: "STKaiti", "华文楷体", "KaiTi", serif; }
</style>