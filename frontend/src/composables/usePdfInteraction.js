import { ref } from 'vue'

const BUBBLE_MENU_HEIGHT = 44
const BUBBLE_MENU_GAP = 8

export function usePdfInteraction(props, emit, pdfContentRef, pdfContainer) {
  
  // 增加 placement 属性，用于控制弹窗在上方还是下方
  const activePopover = ref({ 
    show: false, x: 0, y: 0, id: null, note: '', isEditing: false, placement: 'top' 
  })

  // 浏览模式：选中文字后的“翻译”气泡菜单
  const bubbleMenu = ref({ show: false, x: 0, y: 0, text: '' })
  let pendingHideTimer = null
  const hideBubbleMenu = () => {
    if (pendingHideTimer) clearTimeout(pendingHideTimer)
    pendingHideTimer = null
    bubbleMenu.value = { show: false, x: 0, y: 0, text: '' }
  }
  const scheduleHideBubbleMenu = () => {
    if (pendingHideTimer) return
    pendingHideTimer = setTimeout(() => {
      pendingHideTimer = null
      bubbleMenu.value = { show: false, x: 0, y: 0, text: '' }
    }, 180)
  }

  const checkSelectionForBubble = () => {
    const sel = window.getSelection()
    const tRaw = (sel && sel.toString()) ? sel.toString().trim() : ''
    if (props.activeTool !== 'cursor') return
    const t = tRaw
    if (!t || !sel || sel.rangeCount === 0) {
      scheduleHideBubbleMenu()
      return
    }
    if (pendingHideTimer) {
      clearTimeout(pendingHideTimer)
      pendingHideTimer = null
    }
    try {
      const r = sel.getRangeAt(0)
      if (r.collapsed) {
        scheduleHideBubbleMenu()
        return
      }
      let rect = r.getBoundingClientRect()
      const clientRects = r.getClientRects()
      if ((!rect.width && !rect.height) && clientRects.length > 0) {
        rect = clientRects[0]
      }
      const centerX = rect.left + rect.width / 2
      let y = rect.top - BUBBLE_MENU_HEIGHT - BUBBLE_MENU_GAP
      if (y < BUBBLE_MENU_GAP) y = rect.bottom + BUBBLE_MENU_GAP
      bubbleMenu.value = {
        show: true,
        x: centerX,
        y: Math.max(BUBBLE_MENU_GAP, y),
        text: t
      }
    } catch (e) {
      bubbleMenu.value = { show: false, x: 0, y: 0, text: '' }
    }
  }

  // --- 处理划词 (创建) ---
  const handleMouseUp = () => {
    // 如果正在编辑弹窗，不触发划词
    if (activePopover.value.show && activePopover.value.isEditing) return;
    
    // 如果弹窗已显示，点击其他地方则关闭弹窗
    if (activePopover.value.show) { 
        activePopover.value.show = false; 
        window.getSelection().removeAllRanges(); 
        return; 
    }

    const selection = window.getSelection()
    const text = selection.toString().trim()

    if (props.activeTool === 'cursor') {
      setTimeout(checkSelectionForBubble, 0)
      return
    }

    if (!text) return
    
    const range = selection.getRangeAt(0)
    const rects = Array.from(range.getClientRects())
    const container = pdfContentRef.value
    const pages = container ? Array.from(container.querySelectorAll('.vue-pdf-embed > div')) : [];
    
    if (pages.length === 0 || rects.length === 0) return;

    // 找到选区所在的页面
    const firstRect = rects[0]; 
    const midY = firstRect.top + (firstRect.height / 2);
    let targetPageIndex = 0;
    let targetPageEl = null;

    for (let i = 0; i < pages.length; i++) {
        const pRect = pages[i].getBoundingClientRect(); 
        if (midY >= pRect.top && midY <= pRect.bottom) {
            targetPageIndex = i; 
            targetPageEl = pages[i];
            break;
        }
    }

    if (!targetPageEl) { targetPageIndex = 0; targetPageEl = pages[0]; }

    // 计算相对坐标 (存入数据库用)
    const pageRect = targetPageEl.getBoundingClientRect();
    const relativeRects = rects.map(r => ({
        x: parseFloat(((r.left - pageRect.left) / pageRect.width).toFixed(6)),
        y: parseFloat(((r.top - pageRect.top) / pageRect.height).toFixed(6)),
        w: parseFloat((r.width / pageRect.width).toFixed(6)),
        h: parseFloat((r.height / pageRect.height).toFixed(6))
    }))

    // AI 伴读模式直接触发
    if (props.activeTool === 'ai') {
        emit('ai-ask', text)
        window.getSelection().removeAllRanges()
        return
    }

    // 高亮/笔记模式触发创建
    if (props.activeTool === 'highlight' || props.activeTool === 'note') {
        emit('create-annotation', { 
            page: targetPageIndex + 1, 
            type: props.activeTool, 
            quote: text, 
            rects: relativeRects, 
            position_data: JSON.stringify(relativeRects), 
            color: props.activeTool === 'highlight' ? 'rgba(255, 235, 59, 0.4)' : null 
        })
        window.getSelection().removeAllRanges()
    }
  }

  // --- 处理点击高亮 (弹窗定位 - 屏幕绝对坐标版) ---
  const handleHighlightClick = (e, ann) => {
    e.stopPropagation() 
    
    // 1. 获取高亮块在“整个屏幕”中的绝对位置
    const rect = e.target.getBoundingClientRect()
    
    const popoverW = 220;
    const popoverH = 160;
    
    // 🔥🔥🔥 核心修复：不再减去 containerRect.left 🔥🔥🔥
    // 既然之前的计算导致偏左（偏到了侧边栏里），说明你的 Popover 组件是基于【屏幕视口】定位的（Fixed 或 Teleport 到 Body）
    // 所以我们直接用屏幕坐标 rect.left，只做居中偏移
    
    let x = rect.left + (rect.width / 2) - (popoverW / 2);
    
    // 2. 边界检查 (基于屏幕宽度，防止超出屏幕)
    if (x < 10) x = 10;
    if (x + popoverW > window.innerWidth) x = window.innerWidth - popoverW - 10;

    // 3. Y 轴计算：也是直接用屏幕坐标
    // 默认显示在上方：高亮块顶部 - 弹窗高度 - 间距
    let y = rect.top - popoverH - 10;
    let placement = 'top';
    
    // 如果上方空间不足 (比如在屏幕顶部)，改在下方显示
    if (rect.top < 180) { 
        y = rect.bottom + 10;
        placement = 'bottom';
    }

    activePopover.value = {
      show: true, 
      x, 
      y, 
      id: ann.id,
      note: ann.note || '', 
      isEditing: false, 
      placement
    }
  }

  return {
    activePopover,
    handleMouseUp,
    handleHighlightClick,
    bubbleMenu,
    hideBubbleMenu,
    checkSelectionForBubble
  }
}