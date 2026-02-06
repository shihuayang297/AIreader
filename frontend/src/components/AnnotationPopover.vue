<script setup>
import { computed } from 'vue'
import { Loader2, Trash2, X, MessageSquare, Save, Edit3 } from 'lucide-vue-next'

const props = defineProps({
  modelValue: {
    type: Object,
    required: true,
    default: () => ({ show: false })
  }
})

const emit = defineEmits(['update:modelValue', 'save', 'delete'])

// 🔥 核心修复：使用 computed 包装，确保父组件数据变动时这里能实时更新
const popover = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val)
})

const close = () => {
  // 更新时也要基于当前最新的值，防止覆盖
  emit('update:modelValue', { ...popover.value, show: false })
}

const handleSave = () => {
  emit('save')
}

const handleDelete = () => {
  emit('delete')
}
</script>

<template>
  <div v-if="popover.show" 
       class="fixed z-[9999] bg-white text-gray-700 text-xs rounded-xl shadow-2xl border border-gray-100 p-3 w-[240px] flex flex-col gap-2 animate-in zoom-in duration-200"
       :style="{ top: popover.y + 'px', left: popover.x + 'px' }"
       @mousedown.stop
       @mouseup.stop>
       
    <div class="flex justify-between items-center border-b border-gray-100 pb-2">
        <span class="text-xs font-bold text-slate-600 flex items-center gap-1.5">
            <Edit3 class="w-3.5 h-3.5"/> 笔记 / 管理
        </span>
        <button @click.stop="close" class="hover:bg-gray-100 p-1 rounded-md text-gray-400 transition-colors cursor-pointer">
            <X class="w-3.5 h-3.5"/>
        </button>
    </div>

    <div class="w-full">
        <textarea v-if="popover.isEditing" 
                  v-model="popover.note"
                  class="w-full border border-gray-200 rounded-lg p-2 h-24 text-xs bg-slate-50 focus:bg-white focus:ring-2 focus:ring-blue-100 focus:border-blue-300 outline-none transition-all resize-none leading-relaxed" 
                  placeholder="输入您的思考..." 
                  @mousedown.stop></textarea>
        
        <div v-else class="text-xs p-3 bg-yellow-50/50 border border-yellow-100/50 rounded-lg text-slate-600 min-h-[60px] max-h-[120px] overflow-y-auto leading-relaxed break-words">
            {{ popover.note || '暂无笔记内容' }}
        </div>
    </div>

    <div class="flex items-center justify-between pt-1">
        <button v-if="!popover.isEditing" 
                @click.stop="popover.isEditing = true" 
                class="text-xs text-indigo-600 hover:bg-indigo-50 px-2.5 py-1.5 rounded-md font-medium transition-colors flex items-center gap-1 cursor-pointer">
            <MessageSquare class="w-3.5 h-3.5"/> {{ popover.note ? '修改' : '添加笔记' }}
        </button>
        
        <button v-else 
                @click.stop="handleSave" 
                class="text-xs bg-indigo-600 text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md font-medium transition-colors shadow-sm shadow-indigo-200 flex items-center gap-1 cursor-pointer">
            <Save class="w-3.5 h-3.5"/> 保存
        </button>

        <button @click.stop="handleDelete" 
                class="text-xs text-rose-500 hover:bg-rose-50 px-2.5 py-1.5 rounded-md transition-colors flex items-center gap-1 cursor-pointer">
            <Trash2 class="w-3.5 h-3.5"/> 删除
        </button>
    </div>
  </div>
</template>