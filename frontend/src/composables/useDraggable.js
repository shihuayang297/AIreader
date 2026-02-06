import { ref, onMounted, onUnmounted } from 'vue'

export function useDraggable(initialRight = 60, initialTop = 80) {
  const x = ref(0)
  const y = ref(initialTop)
  const isDragging = ref(false)
  
  // 窗口尺寸 (用于计算边界)
  const windowWidth = window.innerWidth
  
  // 初始化位置：默认靠右
  // 我们存储的是 left 和 top，所以需要计算一下
  onMounted(() => {
      x.value = window.innerWidth - 400 - initialRight // 400是面板宽度
  })

  let startX = 0
  let startY = 0
  let initialLeft = 0
  let initialTopPos = 0

  const startDrag = (event) => {
    isDragging.value = true
    startX = event.clientX
    startY = event.clientY
    initialLeft = x.value
    initialTopPos = y.value

    document.addEventListener('mousemove', onDrag)
    document.addEventListener('mouseup', stopDrag)
    // 防止选中文字
    document.body.style.userSelect = 'none'
  }

  const onDrag = (event) => {
    if (!isDragging.value) return
    const dx = event.clientX - startX
    const dy = event.clientY - startY
    
    // 更新位置
    x.value = initialLeft + dx
    y.value = initialTopPos + dy
  }

  const stopDrag = () => {
    isDragging.value = false
    document.removeEventListener('mousemove', onDrag)
    document.removeEventListener('mouseup', stopDrag)
    document.body.style.userSelect = ''
  }

  return {
    x,
    y,
    isDragging,
    startDrag
  }
}