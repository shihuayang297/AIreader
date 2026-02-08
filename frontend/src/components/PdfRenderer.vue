<script setup>
import { ref, onMounted, onUnmounted, watch, nextTick } from 'vue'
import VuePdfEmbed from 'vue-pdf-embed'
import * as pdfjsLib from 'pdfjs-dist'
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker?url' 
import { Loader2, RefreshCw } from 'lucide-vue-next' // 引入刷新图标
import { parseOutline } from '@/utils/usePdfOutline.js' 

// 🔥 引入拆分后的模块
import AnnotationPopover from './AnnotationPopover.vue'
import BubbleMenu from './BubbleMenu.vue'
import { usePdfData } from '@/composables/usePdfData'
import { usePdfInteraction } from '@/composables/usePdfInteraction'

// 必须配置 Worker
pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl

const props = defineProps({
  pdfUrl: String,
  annotations: Array, 
  activeTool: {
    type: String,
    default: 'cursor'
  },
  scale: { type: Number, default: 1.0 },
  /** 后端已提供 structure 时为 true，跳过前端 parseOutline 以加快首屏 */
  skipOutlineParse: { type: Boolean, default: false }
})

const emit = defineEmits(['loaded', 'create-annotation', 'delete-annotation', 'outline-loaded', 'ai-ask', 'translate-request', 'page-change', 'update-annotation'])

const pdfContainer = ref(null)
const pdfContentRef = ref(null)
const pdfWidth = ref(800)
const isLoading = ref(true)
const pageCount = ref(0) 
const forceUpdateKey = ref(0) // 🔥 这个 key 改变会强制重新渲染高亮层
let resizeObserver = null
let mutationObserver = null // 🔥 新增：DOM 变化监听器
let selectionChangeTimer = null
const onSelectionChange = () => {
  if (selectionChangeTimer) clearTimeout(selectionChangeTimer)
  selectionChangeTimer = setTimeout(checkSelectionForBubble, 80)
}

// 1. 使用数据处理
const { parsedAnnotations } = usePdfData(props)

// 2. 使用交互处理
const { activePopover, handleMouseUp, handleHighlightClick, bubbleMenu, hideBubbleMenu, checkSelectionForBubble } = usePdfInteraction(props, emit, pdfContentRef, pdfContainer)

const onBubbleTranslate = (payload) => {
  hideBubbleMenu()
  window.getSelection()?.removeAllRanges()
  emit('translate-request', payload)
}
const onBubbleClose = () => {
  hideBubbleMenu()
  window.getSelection()?.removeAllRanges()
}

// 3. 布局与加载
const updateLayout = () => { 
  if (pdfContainer.value) pdfWidth.value = (pdfContainer.value.clientWidth - 20) * props.scale
  forceUpdateKey.value++ // 强制刷新高亮
}

watch(() => props.scale, updateLayout)

// 🔥🔥🔥 核心修复：监听 DOM 变化（解决 PDF 渲染延迟导致的高亮丢失） 🔥🔥🔥
const startDomObserver = () => {
    if (!pdfContentRef.value) return;
    
    // 如果已经有监控器，先断开
    if (mutationObserver) mutationObserver.disconnect();

    mutationObserver = new MutationObserver((mutations) => {
        // 只有当 vue-pdf-embed 里的子元素（页面）数量发生变化，或者属性变化时，才刷新
        let shouldUpdate = false;
        for (const mutation of mutations) {
            if (mutation.type === 'childList' || (mutation.type === 'attributes' && mutation.target.classList.contains('vue-pdf-embed'))) {
                shouldUpdate = true;
                break;
            }
        }
        if (shouldUpdate) {
            // console.log("⚡ [PdfRenderer] 检测到 PDF 页面 DOM 变化，重新定位高亮...");
            forceUpdateKey.value++;
        }
    });

    // 观察子节点变化 (childList) 和 子树 (subtree)
    mutationObserver.observe(pdfContentRef.value, { childList: true, subtree: true, attributes: true });
}

onMounted(() => { 
    window.addEventListener('resize', updateLayout); 
    setTimeout(updateLayout, 500) 
    
    if (pdfContentRef.value) {
      resizeObserver = new ResizeObserver(() => { forceUpdateKey.value++ })
      resizeObserver.observe(pdfContentRef.value)
      
      // 启动 DOM 监控
      startDomObserver();
    }
    document.addEventListener('selectionchange', onSelectionChange)
    // 在 document 上也监听 mouseup，避免事件未冒泡到 pdfContainer 时漏掉
    document.addEventListener('mouseup', handleMouseUp)
})

onUnmounted(() => {
  window.removeEventListener('resize', updateLayout)
  document.removeEventListener('selectionchange', onSelectionChange)
  document.removeEventListener('mouseup', handleMouseUp)
  if (selectionChangeTimer) clearTimeout(selectionChangeTimer)
  if (resizeObserver) resizeObserver.disconnect()
  if (mutationObserver) mutationObserver.disconnect()
})

watch(() => props.pdfUrl, (newVal) => { if (newVal) { isLoading.value = true; updateLayout() } }, { immediate: true })

const handleDocumentLoad = async (doc) => {
  pageCount.value = doc.numPages;
  emit('loaded', doc.numPages);

  if (props.skipOutlineParse) {
    // 后端已有 structure，跳过前端解析以缩短“正在解析文档...”时间
    emit('outline-loaded', []);
    setTimeout(() => { isLoading.value = false; updateLayout(); }, 200);
    setTimeout(() => { forceUpdateKey.value++ }, 600);
    setTimeout(() => { forceUpdateKey.value++ }, 1200);
  } else {
    const outline = await parseOutline(doc);
    emit('outline-loaded', outline);
    setTimeout(() => { isLoading.value = false; updateLayout(); }, 200);
    setTimeout(() => { forceUpdateKey.value++ }, 800);
    setTimeout(() => { forceUpdateKey.value++ }, 1500);
  }
}

const handleScroll = () => {
    if (!pdfContainer.value || !pdfContentRef.value) return;
    const container = pdfContainer.value;
    const pages = pdfContentRef.value.querySelectorAll('.vue-pdf-embed > div');
    const containerMid = container.scrollTop + (container.clientHeight / 2);
    let currentPage = 1;
    let currentHeight = 0;
    for (let i = 0; i < pages.length; i++) {
        const pageHeight = pages[i].clientHeight;
        if (containerMid >= currentHeight && containerMid < currentHeight + pageHeight) {
            currentPage = i + 1;
            break;
        }
        currentHeight += pageHeight;
    }
    emit('page-change', currentPage);
    
    // 🔥 滚动停止后也刷新一下，防止懒加载导致的偏移
    // (防抖处理略，这里简单处理)
    // forceUpdateKey.value++ 
}

const scrollToPage = (pageNum) => {
  if (!pdfContentRef.value || !pageNum) return
  const pages = pdfContentRef.value.querySelectorAll('.vue-pdf-embed > div')
  const targetPage = pages[pageNum - 1]
  if (targetPage) targetPage.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
defineExpose({ scrollToPage })

// ==========================================
// 3. 渲染样式计算
// ==========================================
const getPageElement = (pageIndex) => {
    if (!pdfContentRef.value) return null;
    const pages = pdfContentRef.value.querySelectorAll('.vue-pdf-embed > div');
    if (!pages || pages.length < pageIndex) return null;
    return pages[pageIndex - 1]; 
}

const getAnnotationStyle = (ann, rect) => {
    // 🔥 防御：如果坐标全是 0，直接隐藏（坏数据）
    if (rect.x === 0 && rect.y === 0 && rect.w === 0 && rect.h === 0) {
        return { display: 'none' };
    }

    const pageEl = getPageElement(ann.page);
    
    // 如果还没渲染出这一页 DOM，先隐藏
    if (!pageEl) return { display: 'none' };

    const containerRect = pdfContentRef.value.getBoundingClientRect();
    const pageRect = pageEl.getBoundingClientRect();
    
    // 🔥 防御：如果页面高度还没算出来 (0)，也隐藏
    if (pageRect.height === 0) return { display: 'none' };

    const offsetTop = pageRect.top - containerRect.top;
    const offsetLeft = pageRect.left - containerRect.left;

    const w = pageRect.width;
    const h = pageRect.height;

    const style = {
        top: `${offsetTop + rect.y * h}px`, 
        left: `${offsetLeft + rect.x * w}px`,
        width: `${rect.w * w}px`,
        height: `${rect.h * h}px`,
        position: 'absolute',
        mixBlendMode: 'multiply',
        cursor: 'pointer',
        zIndex: 20
    };

    if (ann.type === 'note') {
        style.borderBottom = '2px solid #ef4444'; 
        style.backgroundColor = 'transparent'; 
    } else {
        style.backgroundColor = ann.color || 'rgba(255, 235, 59, 0.4)';
    }

    return style;
}

// 事件桥接
const saveNote = () => {
    emit('update-annotation', { id: activePopover.value.id, note: activePopover.value.note })
    const target = props.annotations.find(a => a.id === activePopover.value.id);
    if(target) target.note = activePopover.value.note;
    activePopover.value.isEditing = false;
    activePopover.value.show = false;
}

const executeDelete = () => {
    if (activePopover.value.id) emit('delete-annotation', activePopover.value.id)
    activePopover.value.show = false
}

// 手动刷新函数
const manualRefresh = () => {
    console.log("🔄 手动刷新高亮层...");
    forceUpdateKey.value++;
}
</script>

<template>
  <div ref="pdfContainer" 
       class="flex-1 overflow-auto bg-[#f0f2f5] flex justify-center py-8 custom-scrollbar relative" 
       @mouseup="handleMouseUp"
       @scroll="handleScroll">
    
    <div v-if="isLoading" class="absolute inset-0 z-50 flex flex-col items-center justify-center bg-[#f0f2f5] backdrop-blur-sm">
      <div class="bg-white p-8 rounded-2xl shadow-xl flex flex-col items-center gap-4 border border-gray-100">
          <Loader2 class="w-12 h-12 text-blue-600 animate-spin"></Loader2>
          <div class="text-sm font-bold text-gray-700">正在解析文档...</div>
      </div>
    </div>

    <div class="fixed top-20 right-8 z-50">
        <button @click="manualRefresh" class="p-2 bg-white rounded-full shadow-lg hover:bg-gray-100 text-gray-600" title="刷新高亮">
            <RefreshCw class="w-5 h-5" />
        </button>
    </div>

    <div v-if="pdfUrl" ref="pdfContentRef" class="relative transition-all duration-300 ease-out origin-top shadow-xl border border-gray-200/60 bg-white" :style="{ width: pdfWidth + 'px', cursor: activeTool !== 'cursor' ? 'text' : 'default' }">
      <div v-memo="[pdfUrl, pdfWidth]">
          <VuePdfEmbed :source="{ url: pdfUrl, withCredentials: true }" :width="pdfWidth" :text-layer="true" :annotation-layer="true" class="pdf-no-gap bg-white" @loaded="handleDocumentLoad"></VuePdfEmbed>
      </div>
      
      <div class="absolute inset-0 z-20 pointer-events-none" :key="forceUpdateKey">
         <template v-for="(ann, i) in parsedAnnotations" :key="i">
           <div v-for="(rect, j) in ann.rects" 
                :key="j" 
                @click.stop="(e) => handleHighlightClick(e, ann)" 
                class="pointer-events-auto hover:opacity-80 transition-opacity" 
                :style="getAnnotationStyle(ann, rect)">
           </div>
         </template>
      </div>
    </div>

    <AnnotationPopover v-model="activePopover" @save="saveNote" @delete="executeDelete" />
    <BubbleMenu
      :visible="bubbleMenu.show"
      :x="bubbleMenu.x"
      :y="bubbleMenu.y"
      :selection-text="bubbleMenu.text"
      @translate="onBubbleTranslate"
      @close="onBubbleClose"
    />
  </div>
</template>

<style scoped>
:deep(.pdf-no-gap) { display: flex !important; flex-direction: column !important; gap: 0 !important; line-height: 0 !important; font-size: 0 !important; }
:deep(.vue-pdf-embed > div) { margin: 0 !important; padding: 0 !important; display: block !important; height: auto !important; position: relative !important; border: none !important; box-shadow: none !important; margin-bottom: -1px !important; }
:deep(.vue-pdf-embed canvas), :deep(.vue-pdf-embed img) { display: block !important; width: 100% !important; margin: 0 !important; padding: 0 !important; vertical-align: bottom !important; }
:deep(.textLayer), :deep(.annotationLayer) { position: absolute !important; top: 0; left: 0; right: 0; bottom: 0; overflow: hidden; opacity: 1 !important; line-height: 1.0 !important; mix-blend-mode: multiply; z-index: 10; pointer-events: auto !important; margin: 0 !important; }
:deep(.annotationLayer) { pointer-events: none !important; }
:deep(.textLayer > span) { color: transparent; position: absolute; white-space: pre; cursor: text; transform-origin: 0% 0%; font-size: initial; }
:deep(.textLayer ::selection) { background: rgba(0, 89, 255, 0.5); }
.custom-scrollbar::-webkit-scrollbar { width: 8px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
</style>