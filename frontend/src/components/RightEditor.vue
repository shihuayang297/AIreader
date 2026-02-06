<script setup>
import { ref, onMounted, onUnmounted, watch, computed, nextTick } from 'vue'
import { useEditor, EditorContent, BubbleMenu } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Placeholder from '@tiptap/extension-placeholder'
import TextStyle from '@tiptap/extension-text-style'
import { Color } from '@tiptap/extension-color'
import Underline from '@tiptap/extension-underline'
import TextAlign from '@tiptap/extension-text-align'
import FontFamily from '@tiptap/extension-font-family'
import CharacterCount from '@tiptap/extension-character-count'
import { FontSize } from '../extensions/FontSize'

import { 
  Type, ChevronDown, Bold, Italic, Underline as UnderlineIcon, 
  Palette, AlignLeft, AlignCenter, Send, Lock,
  GripVertical, PenTool, X, Loader2, FileDown, CheckCircle2, Save,
  Languages, MessageSquareDashed, Sparkles 
} from 'lucide-vue-next'

const props = defineProps({
  initialDraft: String,
  moduleId: String,
  activeSeconds: Number,
  submissionStatus: String,
  floatingToolbar: { type: Boolean, default: false }
})

const emit = defineEmits(['update:wordCount', 'save:success', 'update:content', 'ai-action'])

const saveStatus = ref('未保存') 
const wordCount = ref(0)
const showSubmitModal = ref(false)
const confirmCountDown = ref(5)
let confirmTimer = null
let autoSaveTimer = null

// --- 悬浮球 & 拖拽逻辑 ---
const isToolbarExpanded = ref(false)
const toolbarRef = ref(null)
const position = ref({ x: 20, y: 20 }) // 调整初始位置
const isDragging = ref(false)
const dragOffset = ref({ x: 0, y: 0 })

// --- 下拉菜单逻辑 ---
const showFontMenu = ref(false)
const showSizeMenu = ref(false)
const toggleFontMenu = () => { showFontMenu.value = !showFontMenu.value; showSizeMenu.value = false }
const toggleSizeMenu = () => { showSizeMenu.value = !showSizeMenu.value; showFontMenu.value = false }
const closeMenus = () => { showFontMenu.value = false; showSizeMenu.value = false }

// --- 统计 ---
const getWordCount = (htmlString) => {
    let text = htmlString.replace(/<\/?[^>]+(>|$)/g, " ");
    text = text.replace(/[\r\n\t]+/g, " ").trim();
    if (!text) return 0;
    const cjkMatch = text.match(/[\u4e00-\u9fa5]/g);
    const cjkCount = cjkMatch ? cjkMatch.length : 0;
    const nonCjkString = text.replace(/[\u4e00-\u9fa5]/g, " ");
    const englishWords = nonCjkString.split(/\s+/).filter(word => word.length > 0);
    const enCount = englishWords.length;
    return cjkCount + enCount;
}

const startDrag = (e) => {
    isDragging.value = true
    dragOffset.value = { x: e.clientX - position.value.x, y: e.clientY - position.value.y }
    document.addEventListener('mousemove', onDrag)
    document.addEventListener('mouseup', stopDrag)
}
const onDrag = (e) => {
    if (!isDragging.value) return
    e.preventDefault()
    position.value = { x: e.clientX - dragOffset.value.x, y: e.clientY - dragOffset.value.y }
}
const stopDrag = () => {
    isDragging.value = false
    document.removeEventListener('mousemove', onDrag)
    document.removeEventListener('mouseup', stopDrag)
}

const isSubmitted = computed(() => props.submissionStatus === 'submitted')

// --- 编辑器 ---
const editor = useEditor({
  content: props.initialDraft,
  editable: !isSubmitted.value,
  extensions: [
    StarterKit, Underline, TextStyle, Color, FontFamily, CharacterCount, FontSize,
    TextAlign.configure({ types: ['heading', 'paragraph'] }),
    Placeholder.configure({ placeholder: '在此区域开始您的学术写作...' })
  ],
  editorProps: {
    attributes: {
      // 🌟 核心修改：移除 prose-lg，减小 padding (px-8 py-8)，移除 min-h-screen
      class: 'prose prose-slate prose-p:my-2 prose-headings:my-3 w-full max-w-none focus:outline-none h-full px-8 py-8 text-gray-800 font-serif leading-relaxed bg-white shadow-sm'
    }
  },
  onUpdate: ({ editor }) => {
    if (isSubmitted.value) return
    const htmlContent = editor.getHTML();
    wordCount.value = getWordCount(htmlContent);
    emit('update:wordCount', wordCount.value)
    emit('update:content', htmlContent) 
    if (saveStatus.value !== '保存中...') saveStatus.value = '未同步'
  }
})

watch(() => props.submissionStatus, (newVal) => { if (editor.value) editor.value.setEditable(newVal !== 'submitted') })
watch(() => props.initialDraft, (newVal) => { if (editor.value && !editor.value.getText()) editor.value.commands.setContent(newVal) })

// --- AI 配置 ---
const agentConfig = {
  translate: { agentName: '小翻', systemPrompt: '小翻同学，请将以下文本翻译成通顺、专业的学术英语（英文则翻译成中文）：\n\n' },
  critique: { agentName: '小思', systemPrompt: '小思，请批判性地审视以下文本，指出其逻辑漏洞、论证薄弱点，并给出具体的改进建议：\n\n' },
  polish: { agentName: '小查', systemPrompt: '小查同学，请检查以下文本的编辑拼写和语法错误：\n\n' }
}

const handleAiAction = (type) => {
    const { from, to } = editor.value.state.selection
    const text = editor.value.state.doc.textBetween(from, to, ' ')
    if (!text) return 
    const config = agentConfig[type]
    if (!config) return
    const fullMessage = `${config.systemPrompt}${text}`
    emit('ai-action', { targetAgent: config.agentName, actionType: type, message: fullMessage, selection: text })
}

// --- 保存/提交 ---
const saveToCloud = async (isAuto = false, isSubmission = false) => {
  if (!props.moduleId || isSubmitted.value) return 
  if (!isAuto && !isSubmission) saveStatus.value = '保存中...'
  try {
    const formData = new FormData(); 
    formData.append('id', props.moduleId); 
    formData.append('content', editor.value.getHTML()); 
    formData.append('duration_inc', props.activeSeconds); 
    formData.append('word_count', wordCount.value); 
    formData.append('is_autosave', isAuto ? 1 : 0); 
    if (isSubmission) formData.append('is_submission', 1)

    const res = await fetch('save.php', { method: 'POST', body: formData }); 
    const data = await res.json()
    if (data.status === 'success') {
        const timeStr = new Date().toLocaleTimeString('zh-CN', {hour:'2-digit', minute:'2-digit'})
        saveStatus.value = isAuto ? `已自动同步 ${timeStr}` : `已保存 ${timeStr}`
        emit('save:success', data)
    }
  } catch (e) { console.error(e); saveStatus.value = '保存失败' }
}

const openSubmitModal = () => { if (isSubmitted.value) return; showSubmitModal.value = true; confirmCountDown.value = 5; if (confirmTimer) clearInterval(confirmTimer); confirmTimer = setInterval(() => { confirmCountDown.value--; if (confirmCountDown.value <= 0) clearInterval(confirmTimer) }, 1000) }
const confirmSubmit = () => { if (confirmCountDown.value > 0) return; saveToCloud(false, true); showSubmitModal.value = false }

const downloadLocal = () => {
  const htmlContent = editor.value.getHTML(); 
  const fullHtml = `<html><head><meta charset="utf-8"><title>作业</title></head><body style="font-family: 'SimSun';">${htmlContent}</body></html>`; 
  const blob = new Blob([fullHtml], { type: 'application/msword;charset=utf-8' }); 
  const url = URL.createObjectURL(blob); 
  const a = document.createElement('a'); a.href = url; a.download = `我的作业.doc`; a.click()
}

const setFont = (font) => { editor.value.chain().focus().setFontFamily(font).run(); showFontMenu.value = false }
const setSize = (size) => { editor.value.chain().focus().setFontSize(size).run(); showSizeMenu.value = false }

onMounted(() => {
    if(props.initialDraft) wordCount.value = getWordCount(props.initialDraft);
    autoSaveTimer = setInterval(() => saveToCloud(true), 60000) 
    document.addEventListener('click', (e) => { if (!e.target.closest('.font-menu-trigger')) closeMenus() })
})
onUnmounted(() => { clearInterval(autoSaveTimer); clearInterval(confirmTimer) })
defineExpose({ saveToCloud })
</script>

<template>
  <div class="h-full w-full bg-[#f3f4f6] flex flex-col overflow-hidden">
    
    <div class="w-full bg-white border-b border-gray-200 shadow-sm z-30 shrink-0">
      
      <div class="max-w-4xl mx-auto h-14 px-8 flex items-center justify-between">
          
          <div class="flex items-center gap-3">
            <div class="w-1.5 h-4 bg-[#003366] rounded-full"></div>
            <h1 class="text-base font-bold text-gray-800 tracking-tight">写作区</h1>
            <span v-if="!isSubmitted" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-blue-50 text-blue-700 border border-blue-100">Drafting</span>
          </div>

          <div class="flex items-center gap-3 md:gap-4">
              
              <div class="hidden md:flex items-center gap-2 text-xs text-gray-400">
                 <span class="font-medium text-gray-600">{{ wordCount }}</span> 字
              </div>
              
              <div class="h-3 w-px bg-gray-200 hidden md:block"></div>

              <div class="flex items-center gap-1.5 text-xs min-w-[60px] justify-end">
                <Loader2 v-if="saveStatus === '保存中...'" class="w-3 h-3 animate-spin text-blue-600"/>
                <CheckCircle2 v-else-if="saveStatus.includes('已')" class="w-3 h-3 text-green-600"/>
                <span class="truncate text-[10px]" :class="{'text-green-600': saveStatus.includes('已'), 'text-blue-600': saveStatus === '保存中...', 'text-gray-400': true}">{{ saveStatus === '未同步' ? '未保存' : (saveStatus === '保存中...' ? '保存中' : '已保存') }}</span>
              </div>

              <div class="flex items-center gap-2">
                 <button @click="saveToCloud(false)" class="p-1.5 rounded-md hover:bg-gray-100 text-gray-600 transition-colors" title="保存">
                    <Save class="w-4 h-4"/>
                 </button>

                 <button @click="downloadLocal" class="p-1.5 rounded-md hover:bg-gray-100 text-gray-600 transition-colors" title="导出 Word">
                    <FileDown class="w-4 h-4"/>
                 </button>

                 <button v-if="!isSubmitted" @click="openSubmitModal" class="flex items-center gap-1 px-3 py-1.5 bg-[#003366] text-white rounded-md text-xs font-bold shadow-sm hover:bg-[#00254a] transition-all ml-1">
                    提交 <Send class="w-3 h-3"/>
                 </button>
                 <div v-else class="flex items-center gap-1 px-2 py-1 bg-green-50 text-green-700 rounded text-xs font-bold border border-green-200 ml-1">
                    <Lock class="w-3 h-3"/> 已交
                 </div>
              </div>
          </div>
      </div>
    </div>

    <div class="flex-1 overflow-y-auto bg-[#fafafa] relative p-4 custom-scrollbar" @click="!isSubmitted && editor?.chain().focus().run()">
        
        <div ref="toolbarRef" class="absolute z-40 flex flex-col items-start gap-2 select-none" :style="{ left: position.x + 'px', top: position.y + 'px' }">
          <div class="flex items-center bg-white/90 backdrop-blur-xl shadow-[0_4px_20px_rgba(0,0,0,0.12)] border border-white/50 rounded-full p-1.5 gap-1 hover:scale-105 transition-all cursor-default ring-1 ring-black/5">
              <div @mousedown="startDrag" class="w-6 h-8 flex items-center justify-center cursor-move text-gray-300 hover:text-gray-500 border-r border-gray-100 pr-1"><GripVertical class="w-4 h-4" /></div>
              <button @click="isToolbarExpanded = !isToolbarExpanded" class="w-8 h-8 flex items-center justify-center rounded-full transition-all" :class="isToolbarExpanded ? 'bg-blue-50 text-blue-600 rotate-90' : 'hover:bg-gray-50 text-gray-600'">
                <X v-if="isToolbarExpanded" class="w-4 h-4" /><PenTool v-else class="w-4 h-4" />
              </button>
          </div>
          <div v-if="isToolbarExpanded" class="bg-white/95 backdrop-blur-md shadow-xl border border-gray-200/60 rounded-xl p-2 flex flex-col gap-1 w-max animate-in fade-in slide-in-from-top-2 duration-200 ml-2 ring-1 ring-black/5">
               <div class="flex items-center gap-1 border-b border-gray-100 pb-1 mb-1">
                 <div class="relative font-menu-trigger">
                    <button @click.stop="toggleFontMenu" class="flex items-center gap-1 px-2 py-1 hover:bg-gray-100 rounded text-gray-700 text-xs font-bold" :class="showFontMenu ? 'bg-gray-100 text-blue-600' : ''"><Type class="w-3.5 h-3.5"/> 字体 <ChevronDown class="w-3 h-3"/></button>
                    <div v-show="showFontMenu" class="absolute top-full left-0 mt-2 w-28 bg-white border border-gray-200 shadow-xl rounded-lg py-1 z-50">
                       <button @click="setFont('SimSun')" class="w-full text-left px-3 py-2 text-xs hover:bg-blue-50 font-serif">宋体</button>
                       <button @click="setFont('SimHei')" class="w-full text-left px-3 py-2 text-xs hover:bg-blue-50 font-sans">黑体</button>
                       <button @click="setFont('Arial')" class="w-full text-left px-3 py-2 text-xs hover:bg-blue-50 font-sans">Arial</button>
                    </div>
                 </div>
                 <div class="relative font-menu-trigger">
                    <button @click.stop="toggleSizeMenu" class="flex items-center gap-1 px-2 py-1 hover:bg-gray-100 rounded text-gray-700 text-xs font-bold" :class="showSizeMenu ? 'bg-gray-100 text-blue-600' : ''">字号 <ChevronDown class="w-3 h-3"/></button>
                    <div v-show="showSizeMenu" class="absolute top-full left-0 mt-2 w-20 bg-white border border-gray-200 shadow-xl rounded-lg py-1 z-50">
                       <button v-for="s in [12,14,16,18,20,24,30]" :key="s" @click="setSize(s+'px')" class="w-full text-left px-3 py-1.5 text-xs hover:bg-blue-50">{{ s }}px</button>
                    </div>
                 </div>
               </div>
          </div>
        </div>

        <bubble-menu 
            v-if="editor" 
            :editor="editor" 
            :tippy-options="{ duration: 100, maxWidth: 500, placement: 'top' }" 
            class="flex items-center gap-1 bg-[#1e1e1e] text-white px-3 py-2 rounded-full shadow-[0_8px_30px_rgba(0,0,0,0.4)] border border-gray-700/50 backdrop-blur-md"
        >
            <button @click="editor.chain().focus().toggleBold().run()" :class="['p-1.5 rounded-full hover:bg-white/20 transition', editor.isActive('bold') ? 'text-[#4dabf7] bg-white/10' : 'text-gray-300']"><Bold class="w-4 h-4" /></button>
            <button @click="editor.chain().focus().toggleItalic().run()" :class="['p-1.5 rounded-full hover:bg-white/20 transition', editor.isActive('italic') ? 'text-[#4dabf7] bg-white/10' : 'text-gray-300']"><Italic class="w-4 h-4" /></button>
            <button @click="editor.chain().focus().toggleUnderline().run()" :class="['p-1.5 rounded-full hover:bg-white/20 transition', editor.isActive('underline') ? 'text-[#4dabf7] bg-white/10' : 'text-gray-300']"><UnderlineIcon class="w-4 h-4" /></button>
            <button @click="editor.chain().focus().setColor('#ef4444').run()" :class="['p-1.5 rounded-full hover:bg-white/20 transition', editor.isActive('textStyle', { color: '#ef4444' }) ? 'text-red-500 bg-white/10' : 'text-gray-300']"><Palette class="w-4 h-4" /></button>
            <div class="w-px h-5 bg-gray-600 mx-2"></div>
            <button @click="handleAiAction('translate')" class="flex items-center gap-1 px-2 py-1 rounded-md hover:bg-blue-500/20 text-blue-200 hover:text-blue-100 transition-all" title="翻译"><Languages class="w-4 h-4" /><span class="text-xs font-medium">翻译</span></button>
            <button @click="handleAiAction('critique')" class="flex items-center gap-1 px-2 py-1 rounded-md hover:bg-orange-500/20 text-orange-200 hover:text-orange-100 transition-all" title="批判"><MessageSquareDashed class="w-4 h-4" /><span class="text-xs font-medium">批判</span></button>
            <button @click="handleAiAction('polish')" class="flex items-center gap-1 px-2 py-1 rounded-md hover:bg-purple-500/20 text-purple-200 hover:text-purple-100 transition-all" title="润色"><Sparkles class="w-3.5 h-3.5" /><span class="text-xs font-medium">润色</span></button>
        </bubble-menu>

        <div class="max-w-4xl mx-auto bg-white min-h-full shadow-sm border border-gray-200 rounded-sm">
           <editor-content :editor="editor" class="h-full" />
        </div>
    </div>

    <div v-if="showSubmitModal" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm">
        <div class="bg-white rounded-xl shadow-2xl p-6 w-[400px] border border-gray-200 animate-in fade-in zoom-in duration-200">
            <div class="text-center mb-6">
               <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-3 text-[#003366]"><Send class="w-6 h-6"/></div>
               <h3 class="text-lg font-bold text-gray-800 mb-1">确认提交作业？</h3>
               <p class="text-sm text-gray-500">提交后将无法再修改内容。</p>
            </div>
            <div class="flex gap-3">
               <button @click="showSubmitModal=false" class="flex-1 py-2.5 rounded-lg border border-gray-300 text-gray-700 font-bold text-sm hover:bg-gray-50 transition-colors">取消</button>
               <button @click="confirmSubmit" :disabled="confirmCountDown > 0" :class="['flex-1 py-2.5 rounded-lg text-white font-bold text-sm flex items-center justify-center', confirmCountDown > 0 ? 'bg-gray-400' : 'bg-[#003366]']"><span v-if="confirmCountDown > 0">{{ confirmCountDown }}s</span><span v-else>确认提交</span></button>
            </div>
        </div>
    </div>

  </div>
</template>

<style scoped>
/* 隐藏默认滚动条但保留滚动功能 */
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 3px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }

:deep(.ProseMirror) { min-height: 100%; outline: none; }
:deep(.prose) { max-width: none !important; margin: 0 !important; }
</style>