<script setup>
import { computed } from 'vue'
import { Languages } from 'lucide-vue-next'

const props = defineProps({
  visible: { type: Boolean, default: false },
  x: { type: Number, default: 0 },
  y: { type: Number, default: 0 },
  selectionText: { type: String, default: '' }
})

const emit = defineEmits(['translate', 'close'])

const bubbleStyle = computed(() => ({
  position: 'fixed',
  left: `${props.x}px`,
  top: `${props.y}px`,
  transform: 'translate(-50%, 0)',
  zIndex: 2147483647,
  background: '#1e1e1e',
  color: '#fff',
  padding: '8px 12px',
  borderRadius: '9999px',
  display: 'flex',
  alignItems: 'center',
  gap: '4px',
  boxShadow: '0 8px 30px rgba(0,0,0,0.4)',
  border: '1px solid rgba(55,65,81,0.5)'
}))

// 仅翻译：交给右侧百科助手·小科
const handleTranslate = () => {
  const text = (props.selectionText || '').trim()
  if (!text) return
  const message = `@小科 请将以下内容翻译成通顺的学术中文（若为中文则翻译成英文）：\n\n${text}`
  emit('translate', { agentId: 'encyclopedia', message, selection: text })
  emit('close')
}
</script>

<template>
  <Teleport to="#app">
    <div
      v-if="visible && selectionText"
      class="aireader2-bubble-menu"
      :style="bubbleStyle"
    >
      <button
        type="button"
        class="flex items-center gap-1.5 px-2 py-1 rounded-md hover:bg-blue-500/20 text-blue-200 hover:text-blue-100 transition-all"
        title="翻译"
        @click="handleTranslate"
      >
        <Languages class="w-4 h-4 shrink-0" />
        <span class="text-xs font-medium">翻译</span>
      </button>
    </div>
  </Teleport>
</template>
