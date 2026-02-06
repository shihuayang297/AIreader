import { ref } from 'vue'

export function usePdfAnnotations(props) {
  const annotations = ref([])
  const saveStatus = ref('') 

  // 加载标注
  const loadAnnotations = async () => {
    if (!props.moduleId) return
    try {
      const res = await fetch(`ajax.php?action=get_annotations&id=${props.moduleId}`)
      const json = await res.json()
      if (json.status === 'success') {
        // 🌟 关键修正：加载时给每个数据都补一个 tempId，方便后续操作统一
        annotations.value = json.data.map(a => ({
            ...a,
            // 如果没有 tempId，就用 id 当作 tempId，确保数据结构一致
            tempId: a.tempId || a.id 
        }))
        console.log(`[Data] 成功加载 ${json.data.length} 条标注`)
      }
    } catch (e) {
      console.error("加载标注失败:", e)
    }
  }

  // 保存标注
  const saveAnnotation = async (data) => {
    if (!props.moduleId) {
      alert("错误：组件未初始化完成(ID缺失)，无法保存")
      return
    }
    
    saveStatus.value = 'saving'
    // 生成一个纯数字的时间戳作为临时ID
    const tempId = Date.now()
    data.tempId = tempId
    
    // 乐观更新：立刻推入数组，让用户马上看到高亮
    annotations.value.push(data)

    try {
      const res = await fetch(`ajax.php?action=save_annotation&id=${props.moduleId}`, {
        method: 'POST',
        body: JSON.stringify(data)
      })
      const json = await res.json()
      if (json.status === 'success') {
        saveStatus.value = 'success'
        // 找到刚才那个临时数据，把后端返回的正式 ID 赋给它
        const target = annotations.value.find(a => a.tempId === tempId)
        if (target) {
            target.id = json.id // 绑定真实ID
        }
        setTimeout(() => saveStatus.value = '', 1500)
      } else {
        saveStatus.value = 'error'
        console.error("保存失败:", json.msg)
        // 回滚：如果存失败了，把刚才那个假的删掉，免得误导用户
        deleteAnnotation(tempId)
      }
    } catch (e) {
      saveStatus.value = 'error'
      console.error(e)
      deleteAnnotation(tempId)
    }
  }

  // 🌟🌟🌟 核心修复：强力删除逻辑 🌟🌟🌟
  const deleteAnnotation = async (targetId) => {
    if (!targetId) return console.warn("删除失败：未接收到 ID");

    console.log(">>> 正在删除标注，目标 ID:", targetId);
    console.log(">>> 删除前数量:", annotations.value.length);

    // 1. 强力过滤 (前端先删)
    // 使用 String() 强制转换，防止 数字 vs 字符串 导致比对失败
    const strTargetId = String(targetId);

    const originalLength = annotations.value.length;
    
    annotations.value = annotations.value.filter(a => {
        // 只要数据的 id 或 tempId 等于目标 ID，就过滤掉
        const idMatch = a.id && String(a.id) === strTargetId;
        const tempIdMatch = a.tempId && String(a.tempId) === strTargetId;
        
        // 如果匹配上了，就返回 false (剔除)；否则返回 true (保留)
        return !(idMatch || tempIdMatch);
    });

    console.log(">>> 删除后数量:", annotations.value.length);

    // 如果数量没变，说明 ID 没对上，打印出来调试
    if (annotations.value.length === originalLength) {
        console.warn("⚠️ 警告：前端数组未发生变化，可能 ID 不匹配。当前列表:", JSON.parse(JSON.stringify(annotations.value)));
    }

    // 2. 发送请求给后端 (静默删除)
    if (props.moduleId) {
      try {
        const res = await fetch(`ajax.php?action=delete_annotation&id=${props.moduleId}&ann_id=${targetId}`)
        const json = await res.json()
        if (json.status === 'success') {
            console.log(">>> 后端删除成功");
        } else {
            console.warn(">>> 后端删除返回错误:", json.msg);
        }
      } catch (e) { 
        console.error(">>> 删除接口调用失败:", e);
        // 注意：这里我们通常不回滚。如果前端删了但后端没删，用户刷新一下也就同步了。
        // 比起“点删除了却删不掉”，用户更能容忍“刷新后又回来了”。
      }
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