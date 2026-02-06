<script setup>
import { ref, watch, onMounted, nextTick } from 'vue'
import VuePdfEmbed from 'vue-pdf-embed'
// 引入必要的图标
import { 
  BookOpen, ZoomIn, ZoomOut, RotateCw, 
  Highlighter, MessageSquarePlus, MousePointerClick 
} from 'lucide-vue-next'

// 接收父组件数据
const props = defineProps({
  // PDF 列表 (现在通常只有一个，但保留数组结构兼容性)
  pdfList: {
    type: Array,
    default: () => []
  }
})

// 定义事件：通知父组件有新动作（如划词）
const emit = defineEmits(['text-selected', 'page-changed'])

// --- PDF 状态管理 ---
const pdfSource = ref('')
const pageNum = ref(1)
const pageCount = ref(0)
const scale = ref(1.0) // 缩放比例
const rotation = ref(0) // 旋转角度
const isLoading = ref(true)

// --- 划词交互状态 ---
const showToolbar = ref(false) // 是否显示划词工具栏
const toolbarPosition = ref({ x: 0, y: 0 })
const selectedText = ref('')

// --- 初始化逻辑 ---
watch(() => props.pdfList, (list) => {
  if (list && list.length > 0) {
    // 默认加载第一个 PDF
    pdfSource.value = list[0].url
    isLoading.value = true
  }
}, { immediate: true, deep: true })

// --- PDF 事件处理 ---
const handleDocumentLoad = (doc) => {
  console.log("PDF 加载完毕，总页数:", doc.numPages)
  pageCount.value = doc.numPages
  isLoading.value = false
}

// --- 核心：划词监听 (数据感知的起点) ---
const handleMouseUp = () => {
  const selection = window.getSelection()
  const text = selection.toString().trim()

  if (text.length > 0) {
    // 1. 获取选区坐标，用于定位工具栏
    const range = selection.getRangeAt(0)
    const rect = range.getBoundingClientRect()
    
    // 2. 计算相对于视口的坐标 (显示在选区上方)
    toolbarPosition.value = {
      x: rect.left + (rect.width / 2) - 60, // 居中
      y: rect.top - 50 // 上方
    }
    
    selectedText.value = text
    showToolbar.value = true
    
    // 3. (高级功能预留) 这里应该计算相对于 PDF 页面的精确坐标
    // 用于存入数据库：{ page: pageNum.value, text: text, rect: ... }
  } else {
    // 如果没选中文本，隐藏工具栏
    showToolbar.value = false
  }
}

// --- 动作：触发 AI ---
const triggerAiAction = (type) => {
  console.log(`触发 AI 动作: ${type}, 内容: ${selectedText.value}`)
  
  // 发送事件给 App.vue -> RightSidebar
  emit('text-selected', {
    type: type, // 'explain' (百科) | 'ask' (提问)
    text: selectedText.value,
    page: pageNum.value
  })
  
  // 操作完隐藏
  showToolbar.value = false
  window.getSelection().removeAllRanges()
}

// --- 动作：高亮标注 (模拟) ---
const triggerHighlight = () => {
  console.log("用户点击了高亮")
  // TODO: 这里未来要调用后端 API 保存高亮数据
  // 并在前端 Canvas 层绘制颜色
  
  showToolbar.value = false
}

</script>

<template>
  <main class="flex-1 flex flex-col bg-slate-100 min-w-0 relative z-20 h-full">
    
    <div class="h-14 flex items-center justify-between px-4 border-b border-gray-200 bg-white shadow-sm shrink-0 z-30">
      
      <div class="flex items-center gap-2 text-sm font-bold text-gray-700 truncate max-w-[300px]">
        <BookOpen class="w-4 h-4 text-blue-600"/>
        <span>{{ props.pdfList[0]?.filename || '未加载文档' }}</span>
      </div>

      <div class="flex items-center gap-2 bg-gray-50 p-1 rounded-lg border border-gray-200">
        <button @click="scale = Math.max(0.5, scale - 0.1)" class="p-1.5 hover:bg-gray-200 rounded text-gray-600" title="缩小">
          <ZoomOut class="w-4 h-4"/>
        </button>
        <span class="text-xs font-mono w-12 text-center">{{ Math.round(scale * 100) }}%</span>
        <button @click="scale = Math.min(2.5, scale + 0.1)" class="p-1.5 hover:bg-gray-200 rounded text-gray-600" title="放大">
          <ZoomIn class="w-4 h-4"/>
        </button>
      </div>

      <div class="text-xs text-gray-400">
        {{ isLoading ? '加载中...' : `共 ${pageCount} 页` }}
      </div>
    </div>
    
    <div 
      class="flex-1 overflow-y-auto relative p-8 flex justify-center custom-scrollbar"
      @mouseup="handleMouseUp"
    >
      <div v-if="!pdfSource" class="mt-20 text-gray-400 flex flex-col items-center">
        <BookOpen class="w-12 h-12 mb-4 opacity-20"/>
        <p>请在设置中上传 PDF 论文</p>
      </div>

      <div v-else class="pdf-container shadow-lg">
        <VuePdfEmbed 
          :source="pdfSource"
          :scale="scale"
          :rotation="rotation"
          text-layer
          annotation-layer
          @loaded="handleDocumentLoad"
          class="bg-white"
        />
      </div>

      <div 
        v-if="showToolbar"
        class="fixed z-[999] bg-gray-900 text-white rounded-lg shadow-xl py-1.5 px-2 flex items-center gap-1 animate-in fade-in zoom-in duration-200"
        :style="{ top: toolbarPosition.y + 'px', left: toolbarPosition.x + 'px' }"
        @mousedown.stop 
      >
        <button @click="triggerAiAction('explain')" class="flex items-center gap-1 px-2 py-1 hover:bg-gray-700 rounded transition-colors text-xs font-medium">
          <MessageSquarePlus class="w-3.5 h-3.5 text-blue-400"/>
          百科
        </button>
        
        <div class="w-px h-3 bg-gray-600 mx-1"></div>

        <button @click="triggerHighlight" class="flex items-center gap-1 px-2 py-1 hover:bg-gray-700 rounded transition-colors text-xs font-medium">
          <Highlighter class="w-3.5 h-3.5 text-yellow-400"/>
          高亮
        </button>

        <div class="w-px h-3 bg-gray-600 mx-1"></div>

        <button @click="triggerAiAction('ask')" class="flex items-center gap-1 px-2 py-1 hover:bg-gray-700 rounded transition-colors text-xs font-medium">
          <MousePointerClick class="w-3.5 h-3.5 text-green-400"/>
          追问
        </button>
        
        <div class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 w-3 h-3 bg-gray-900 rotate-45"></div>
      </div>

    </div>
  </main>
</template>

<style scoped>
/* PDF 容器样式 */
.pdf-container {
  width: 100%;
  max-width: 900px; /* 限制最大宽度，模拟 A4 质感 */
  transition: transform 0.1s ease; /* 缩放时的平滑过渡 */
}

/* 滚动条美化 */
.custom-scrollbar::-webkit-scrollbar { width: 8px; height: 8px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

/* 深度选择器：调整 PDF 内部样式 */
:deep(.vue-pdf-embed > div) {
  margin-bottom: 20px; /* 页间距 */
  box-shadow: 0 2px 8px rgba(0,0,0,0.05); /* 页阴影 */
}
</style>