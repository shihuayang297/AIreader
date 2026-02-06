<script setup>
import { ref, watch, onMounted } from 'vue'
import NewPdfRenderer from './NewPdfRenderer.vue'
import NewPdfToolbar from './NewPdfToolbar.vue' // 指向刚才新建的 Toolbar

const props = defineProps({
  pdfUrl: String,
  moduleId: String,
  annotations: Array 
})

const emit = defineEmits(['create-annotation', 'delete-annotation', 'text-selected', 'page-change', 'outline-loaded'])

const rendererRef = ref(null)
const pageCount = ref(0)
const scale = ref(1.0)
const activeTool = ref('highlight')
const saveStatus = ref('ready') // saving, success, error

const handleCreateAnnotation = async (payload) => {
    saveStatus.value = 'saving';
    try {
        const res = await fetch(`ajax.php?action=save_annotation&id=${props.moduleId}`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if(json.status === 'success') {
            const newAnn = { ...payload, id: json.id };
            emit('create-annotation', newAnn); 
            saveStatus.value = 'success';
            setTimeout(() => saveStatus.value = 'ready', 2000);
        }
    } catch(e) {
        saveStatus.value = 'error';
        console.error("保存失败", e);
    }
}

const handleDeleteAnnotation = async (dbId) => {
    if(!dbId) return;
    try {
        await fetch(`ajax.php?action=delete_annotation&id=${props.moduleId}&ann_id=${dbId}`);
        emit('delete-annotation', dbId); 
    } catch(e) {
        console.error("删除失败", e);
    }
}

const handleTextSelected = (text) => emit('text-selected', { type: 'ask', text });
</script>

<template>
  <div class="h-full flex flex-col bg-[#f0f2f5] font-sans overflow-hidden relative">
    
    <NewPdfToolbar 
      :page-count="pageCount"
      :save-status="saveStatus" 
      :active-tool="activeTool"
      :scale="scale"
      @update:activeTool="(val) => activeTool = val"
      @zoom-in="scale = Math.min(2.5, scale + 0.1)"
      @zoom-out="scale = Math.max(0.5, scale - 0.1)"
    />

    <NewPdfRenderer
      ref="rendererRef"
      :pdf-url="pdfUrl"
      :annotations="annotations"
      :scale="scale"
      :active-tool="activeTool"
      @loaded="(pc) => pageCount = pc"
      @create-annotation="handleCreateAnnotation"
      @delete-annotation="handleDeleteAnnotation"
      @text-selected="handleTextSelected"
    />
  </div>
</template>