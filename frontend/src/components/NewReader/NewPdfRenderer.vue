<script setup>
import { ref, watch, onUnmounted, onMounted, computed, nextTick } from 'vue'
import VuePdfEmbed from 'vue-pdf-embed'
import Highlighter from 'web-highlighter'
import { Loader2, Trash2 } from 'lucide-vue-next'

// --- 1. Worker 配置 (必须保留，否则 PDF 不加载) ---
import * as pdfjsLib from 'pdfjs-dist'
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker?url'
pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl

const props = defineProps({
  pdfUrl: String,
  annotations: Array, 
  scale: { type: Number, default: 1.0 },
  activeTool: { type: String, default: 'highlight' }
})

const emit = defineEmits(['loaded', 'create-annotation', 'delete-annotation', 'text-selected'])

const pdfContainerRef = ref(null) // 外层滚动容器
const pdfEmbedRef = ref(null)     // PDF 组件实例
const containerWidth = ref(800)
const isLoading = ref(true)
const pageCount = ref(0)
const activeMenu = ref({ show: false, x: 0, y: 0, id: null, dbId: null })

let highlighter = null

// 计算宽度
const finalPdfWidth = computed(() => {
    const w = Math.floor(containerWidth.value * props.scale) - 20
    return w > 0 ? w : 800
})

// ==========================================
// 1. 恢复旧代码的跳转逻辑 (Scroll)
// ==========================================
const scrollToPage = (pageNum) => {
    // 🔥 严格复刻你旧代码的逻辑
    if (!pdfEmbedRef.value || !pageNum) return
    
    // 获取 PDF 组件内部的所有 div (每一页都是一个 div)
    // 注意：这里需要拿到组件的 $el
    const container = pdfEmbedRef.value.$el || document.querySelector('.vue-pdf-embed')
    if (!container) return

    const pages = container.querySelectorAll('div') // 旧代码逻辑：找直接子级 div
    const targetPage = pages[pageNum - 1]
    
    if (targetPage) {
        console.log(`>>> 跳转至第 ${pageNum} 页`)
        targetPage.scrollIntoView({ behavior: 'smooth', block: 'start' })
    } else {
        console.warn(">>> 未找到页码 DOM", pageNum)
    }
}

// ==========================================
// 2. 高亮器逻辑 (Web-Highlighter)
// ==========================================
const initHighlighter = () => {
    if (highlighter) highlighter.dispose()
    
    // 必须指向包含 textLayer 的容器
    const rootEl = pdfEmbedRef.value?.$el || document.querySelector('.vue-pdf-embed')
    if (!rootEl) return

    try {
        // 实例化
        highlighter = new Highlighter({
            $root: rootEl,
            style: { className: 'highlight-marker', background: 'rgba(255, 235, 59, 0.5)' }
        });

        // 监听创建
        highlighter.on(Highlighter.event.CREATE, ({ sources }) => {
            if (props.activeTool !== 'highlight') {
                sources.forEach(s => highlighter.remove(s.id));
                return;
            }
            const source = sources[0];
            // 自动保存
            emit('create-annotation', {
                quote: source.text,
                position_data: JSON.stringify(source),
                type: 'highlight',
                page: 1 
            });
        });

        // 监听点击 (删除)
        highlighter.on(Highlighter.event.CLICK, ({ id }) => {
            const doms = highlighter.getDoms(id);
            const source = highlighter.getSource(id);
            if(doms.length > 0){
                 const rect = doms[0].getBoundingClientRect();
                 activeMenu.value = {
                     show: true, 
                     x: rect.left + (rect.width/2) - 40, 
                     y: rect.top - 50,
                     id: id,
                     dbId: source.extra?.dbId 
                 }
            }
        });
        
        restoreAnnotations();
    } catch (e) { console.error(e) }
}

const restoreAnnotations = () => {
    if(!highlighter || !props.annotations) return;
    props.annotations.forEach(ann => {
        try {
            if (!ann.position_data) return;
            const src = JSON.parse(ann.position_data);
            src.extra = { dbId: ann.id };
            highlighter.fromStore(src);
        } catch(e) {}
    })
}

// ==========================================
// 3. 生命周期与布局
// ==========================================
const updateLayout = () => {
    if (pdfContainerRef.value) {
        containerWidth.value = pdfContainerRef.value.clientWidth
    }
}

onMounted(() => {
    window.addEventListener('resize', updateLayout)
    setTimeout(updateLayout, 100)
})

onUnmounted(() => {
    window.removeEventListener('resize', updateLayout)
    if(highlighter) highlighter.dispose()
})

const handleDocumentLoad = (doc) => {
    pageCount.value = doc.numPages
    emit('loaded', doc.numPages)
    // 此时不要关 loading，等渲染完
}

// 🔥 关键：页面渲染完毕事件
const handlePdfRendered = () => {
    console.log(">>> PDF Rendered")
    isLoading.value = false
    
    // 延时确保 DOM 中的 TextLayer 已经生成
    setTimeout(() => {
        initHighlighter();
    }, 500);
}

// 监听数据变化
watch(() => props.annotations, () => {
    if(highlighter) restoreAnnotations();
}, { deep: true })

// 监听工具切换
watch(() => props.activeTool, (val) => {
    if (val === 'highlight') {
        // 切换到标注模式时，确保文字层在最上
        document.body.classList.add('highlight-mode')
    } else {
        document.body.classList.remove('highlight-mode')
    }
})

// 删除逻辑
const executeDelete = () => {
    if (activeMenu.value.dbId) emit('delete-annotation', activeMenu.value.dbId);
    if (highlighter) highlighter.remove(activeMenu.value.id);
    activeMenu.value.show = false;
}

// 划词交互 (AI)
const handleMouseUp = () => {
    const sel = window.getSelection();
    const text = sel.toString().trim();
    if(props.activeTool === 'ai' && text.length > 0 && !activeMenu.value.show) {
        emit('text-selected', text);
        sel.removeAllRanges(); 
    }
}

// 暴露方法给父组件
defineExpose({ scrollToPage })
</script>

<template>
  <div class="relative w-full h-full bg-[#f0f2f5] overflow-auto flex justify-center py-8 custom-scrollbar" 
       ref="pdfContainerRef"
       @mouseup="handleMouseUp">
       
    <div v-if="isLoading" class="absolute inset-0 z-50 flex flex-col items-center justify-center bg-[#f0f2f5] backdrop-blur-sm">
       <div class="bg-white p-8 rounded-2xl shadow-xl flex flex-col items-center gap-4 border border-gray-100">
          <Loader2 class="w-12 h-12 text-blue-600 animate-spin" />
          <div class="text-sm font-bold text-gray-700">正在解析文档...</div>
       </div>
    </div>

    <div v-if="pdfUrl" class="shadow-xl border border-gray-200/60 bg-white transition-all duration-200 ease-out origin-top"
         :style="{ width: finalPdfWidth + 'px', cursor: activeTool === 'cursor' ? 'grab' : 'text' }">
        
        <VuePdfEmbed 
            ref="pdfEmbedRef"
            :source="{ url: pdfUrl, withCredentials: true }" 
            :width="finalPdfWidth" 
            :text-layer="true" 
            :annotation-layer="true" 
            class="pdf-wrapper"
            @loaded="handleDocumentLoad"
            @rendered="handlePdfRendered"
        />
    </div>

    <div v-if="activeMenu.show" 
         class="fixed z-[9999] bg-slate-800 text-white text-xs rounded-lg shadow-xl py-1.5 px-3 flex items-center gap-2 animate-in zoom-in duration-200"
         :style="{ top: activeMenu.y + 'px', left: activeMenu.x + 'px' }"
         @mousedown.stop>
      <button @click="executeDelete" class="flex items-center gap-1 hover:text-red-400 transition-colors">
          <Trash2 class="w-3.5 h-3.5" /> 删除
      </button>
    </div>
    <div v-if="activeMenu.show" @mousedown="activeMenu.show = false" class="fixed inset-0 z-[9998]" ></div>
  </div>
</template>

<style scoped>
/* 🔥🔥🔥 核心 CSS：解决高亮画不上的问题 🔥🔥🔥 */

/* 1. 强制让文字层位于最顶层，并且可以点击 */
:deep(.textLayer) {
    position: absolute !important;
    top: 0; left: 0; right: 0; bottom: 0;
    overflow: hidden;
    opacity: 0.1; /* 保持微弱可见，便于调试，稳定后可设为0 */
    line-height: 1.0 !important;
    mix-blend-mode: multiply;
    z-index: 50 !important; /* 关键：必须比 Canvas 高 */
    pointer-events: auto !important; /* 关键：允许鼠标交互 */
}

/* 2. 让文字节点透明但可选 */
:deep(.textLayer > span) {
    color: transparent;
    position: absolute;
    white-space: pre;
    cursor: text;
    transform-origin: 0% 0%;
}

/* 3. 选中文本时的背景色 (原生选区) */
:deep(.textLayer ::selection) {
    background: rgba(0, 89, 255, 0.2);
}

/* 4. 消除 PDF 页面间隙 */
:deep(.pdf-wrapper) {
    display: flex;
    flex-direction: column;
}
:deep(.vue-pdf-embed > div) {
    margin-bottom: 0px !important;
    position: relative !important;
}

/* 5. Web-Highlighter 的高亮样式 */
:global(.highlight-marker) {
    background: rgba(255, 235, 59, 0.5) !important;
    cursor: pointer;
    border-bottom: 2px solid #fbc02d;
    position: absolute !important; /* 确保定位正确 */
    z-index: 40 !important; /* 在文字层之下，Canvas 之上 (如果可能) */
}

.custom-scrollbar::-webkit-scrollbar { width: 8px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
</style>