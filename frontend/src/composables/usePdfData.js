import { computed } from 'vue'

export function usePdfData(props) {
  // 核心数据回显处理
  const parsedAnnotations = computed(() => {
    // 1. 安全检查
    if (!props.annotations || !Array.isArray(props.annotations)) {
        return [];
    }
    
    // 2. 遍历并清洗数据
    const cleanList = props.annotations.map((ann, index) => {
      let rects = ann.rects;
      
      // 解析 position_data
      if (!rects || rects.length === 0) {
        if (ann.position_data) {
          try {
            const raw = typeof ann.position_data === 'string' 
              ? JSON.parse(ann.position_data) 
              : ann.position_data;
            rects = Array.isArray(raw) ? raw : [raw];
          } catch (e) {
            console.error(`❌ [ID:${ann.id}] JSON 解析失败`, e);
            rects = [];
          }
        } else {
          rects = [];
        }
      }
      
      // 3. 坐标归一化
      const validRects = rects.map(r => ({
        x: Number(r.x ?? r.left ?? 0),
        y: Number(r.y ?? r.top ?? 0),
        w: Number(r.w ?? r.width ?? 0),
        h: Number(r.h ?? r.height ?? 0)
      })).filter(r => {
          return r.w > 0.001 && r.h > 0.001 && r.x >= 0 && r.y >= 0;
      });

      // 4. 颜色修复
      let safeColor = ann.color;
      if (!safeColor || safeColor.length < 5 || !safeColor.trim().endsWith(')')) {
          safeColor = 'rgba(255, 235, 59, 0.4)'; 
      }

      // 🔥🔥🔥 [核心修复] 字段映射：把 page_num 赋给 page 🔥🔥🔥
      // 后端传的是 page_num (字符串), 前端 PdfRenderer 用的是 page (数字)
      const pageNumber = Number(ann.page || ann.page_num || 1);

      // 🔥🔥🔥 [新增] 处理软删除状态 🔥🔥🔥
      // 确保转为数字，防止后端传字符串 "1"
      const isDeleted = Number(ann.is_deleted || 0);

      return { 
          ...ann, 
          id: ann.id, // 确保 ID 存在
          page: pageNumber, // 🔥 关键：统一字段名为 page，并转为数字
          rects: validRects,
          color: safeColor, 
          note: ann.note || '',
          quote: ann.quote || '',
          is_deleted: isDeleted // 将删除状态带入
      };
    });

    // 5. 返回有效数据
    // 🔥🔥🔥 [新增] 过滤条件：排除掉 is_deleted 为 1 的数据 🔥🔥🔥
    const result = cleanList.filter(ann => ann.rects.length > 0 && ann.is_deleted !== 1);
    
    return result;
  })

  return {
    parsedAnnotations
  }
}