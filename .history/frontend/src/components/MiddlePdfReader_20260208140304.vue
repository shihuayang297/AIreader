<script setup>
import { ref, watch } from 'vue'
// 🔥 移除 Hook 引用，因为它会导致和 App.vue 重复请求数据库
// import { usePdfAnnotations } from '@/composables/usePdfAnnotations' 
import PdfToolbar from './PdfToolbar.vue' 
import PdfRenderer from './PdfRenderer.vue' 

const props = defineProps({ 
  pdfUrl: String, 
  moduleId: [String, Number],
  annotations: Array, 
  activeTool: {
    type: String,
    default: 'cursor'
  },
  /** 后端已提供 structure 时为 true，PDF 加载时跳过前端 parseOutline 以加快首屏 */
  skipOutlineParse: { type: Boolean, default: false }
})

const emit = defineEmits([
  'text-selected', 
  'outline-loaded', 
  'page-change',
  'update:activeTool', 
  'create-annotation', 
  'delete-annotation', 
  'update-annotation',
  'ai-action'
])

// 2. 本地状态
const pageCount = ref(0)
const rendererRef = ref(null) 
const scale = ref(1.0) 

// 3. 暴露跳转方法
const scrollToPage = (page) => {
  if (rendererRef.value) {
    rendererRef.value.scrollToPage(page)
  }
}
defineExpose({ scrollToPage })

// 4. 辅助函数：处理高亮创建
const handleCreate = (data) => {
    // 🔥 修复：只通知父组件，不要自己存，防止双重存储
    emit('create-annotation', data);
}

// 5. 辅助函数：处理删除
const handleDelete = (id) => {
    // 🔥 修复：只通知父组件
    emit('delete-annotation', id);
}

// 6. 处理笔记更新
const handleUpdateNote = ({ id, note }) => {
    // 🔥 修复：只通知父组件，不要自己 fetch
    emit('update-annotation', { id, note });
}

// 7. 缩放控制
const handleZoomIn = () => { if(scale.value < 2.5) scale.value += 0.1 }
const handleZoomOut = () => { if(scale.value > 0.6) scale.value -= 0.1 }

</script>

<template>
  <div class="h-full flex flex-col bg-[#f0f2f5] font-sans overflow-hidden relative">
    
    <PdfToolbar 
      :page-count="pageCount"
      :active-tool="activeTool"
      @update:activeTool="(val) => emit('update:activeTool', val)"
      @zoom-in="handleZoomIn"
      @zoom-out="handleZoomOut"
    />

    <div class="flex-1 overflow-hidden relative flex flex-col">
        <PdfRenderer
          ref="rendererRef"
          :pdf-url="pdfUrl"
          :annotations="annotations" 
          :active-tool="activeTool"
          :scale="scale"
          :skip-outline-parse="skipOutlineParse"
          @loaded="(pc) => pageCount = pc"
          @create-annotation="handleCreate"
          @delete-annotation="handleDelete"
          @update-annotation="handleUpdateNote"
          @outline-loaded="(data) => emit('outline-loaded', data)"
          @ai-ask="(text) => emit('text-selected', { type: 'explain', text })"
          @ai-action="(payload) => emit('ai-action', payload)"
          @page-change="(page) => emit('page-change', page)" 
        />
    </div>
  </div>
</template>