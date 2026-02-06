import { ref, watch } from 'vue'

export function usePdfAnnotations(props) {
  const annotations = ref([])
  const saveStatus = ref('') // '' | 'saving' | 'success' | 'error'

  // 加载标注
  const loadAnnotations = async () => {
    if (!props.moduleId) return
    try {
      const res = await fetch(`ajax.php?action=get_annotations&id=${props.moduleId}`)
      const json = await res.json()
      if (json.status === 'success') {
        annotations.value = json.data
        console.log(`[Data] 成功恢复 ${json.data.length} 条标注`)
      }
    } catch (e) {
      console.error("加载标注失败:", e)
    }
  }

  // 保存标注
  const saveAnnotation = async (data) => {
    if (!props.moduleId) {
      alert("系统繁忙(ID未就绪)，请刷新页面")
      return
    }
    
    // 乐观更新：先显示在界面上
    saveStatus.value = 'saving'
    const tempId = Date.now()
    data.tempId = tempId
    annotations.value.push(data)

    try {
      const res = await fetch(`ajax.php?action=save_annotation&id=${props.moduleId}`, {
        method: 'POST',
        body: JSON.stringify(data)
      })
      const json = await res.json()
      if (json.status === 'success') {
        saveStatus.value = 'success'
        // 更新真实ID
        const target = annotations.value.find(a => a.tempId === tempId)
        if (target) target.id = json.id
        setTimeout(() => saveStatus.value = '', 1500)
      } else {
        saveStatus.value = 'error'
        alert("保存失败: " + json.msg)
      }
    } catch (e) {
      saveStatus.value = 'error'
      console.error(e)
    }
  }

  // 删除标注
  const deleteAnnotation = async (id) => {
    // 前端先删
    annotations.value = annotations.value.filter(a => a.id !== id)
    
    // 后端删
    if (props.moduleId) {
      try {
        await fetch(`ajax.php?action=delete_annotation&id=${props.moduleId}&ann_id=${id}`)
      } catch (e) { console.error(e) }
    }
  }

  return {
    annotations,
    saveStatus,
    loadAnnotations,
    saveAnnotation,
    deleteAnnotation
  }
}