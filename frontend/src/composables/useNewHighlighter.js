import Highlighter from 'web-highlighter';
import { ref, nextTick } from 'vue';

export function useNewHighlighter(containerRef, onSave, onDelete) {
    const highlighter = ref(null);
    const isReady = ref(false);

    // 初始化高亮器
    const initHighlighter = () => {
        if (!containerRef.value) return;

        // 实例化，绑定到 PDF 容器
        highlighter.value = new Highlighter({
            $root: containerRef.value,
            exceptSelectors: ['.ignore-highlight'], // 忽略的元素
            style: {
                className: 'my-highlight-class',
                background: 'rgba(255, 235, 59, 0.4)', // 默认黄色
                cursor: 'pointer'
            }
        });

        // 监听：划词完成 (创建高亮)
        highlighter.value.on(Highlighter.event.CREATE, ({ sources }) => {
            // sources 是一个数组（因为可能跨行产生多段高亮）
            // 我们通常只取第一个source作为ID索引，或者把整个sources存进去
            // 为了方便管理，我们这里处理单次划词
            const source = sources[0];
            
            const payload = {
                // 生成唯一 ID (前端临时，后端保存后会更新)
                tempId: source.id, 
                // 核心：存文本，用于数据挖掘！
                quote: source.text, 
                // 核心：存 DOM 序列化数据，用于回显！
                position_data: JSON.stringify(source),
                type: 'highlight',
                page: 1 // web-highlighter 是全局的，这里页码更多是做索引，可根据 DOM 所在父级计算
            };
            
            onSave(payload);
        });

        // 监听：点击高亮 (删除或笔记)
        highlighter.value.on(Highlighter.event.CLICK, ({ id }) => {
            // 抛出事件让 UI 处理（显示气泡菜单）
            onDelete(id); 
        });

        isReady.value = true;
        console.log('>>> Highlighter 初始化完成');
    };

    // 回显数据 (从数据库加载)
    const restoreHighlights = (dbAnnotations) => {
        if (!highlighter.value || !dbAnnotations) return;
        
        dbAnnotations.forEach(ann => {
            try {
                if (!ann.position_data) return;
                const source = JSON.parse(ann.position_data);
                
                // 关键：把数据库 ID 注入到 source 里，保证点击时能拿到数据库 ID
                source.extra = { dbId: ann.id, note: ann.note || '' };
                
                // 渲染
                highlighter.value.fromStore(source);
            } catch (e) {
                console.error("高亮还原失败:", e);
            }
        });
    };

    // 删除高亮
    const removeHighlight = (id) => {
        if (highlighter.value) {
            highlighter.value.remove(id);
        }
    };

    return {
        initHighlighter,
        restoreHighlights,
        removeHighlight
    };
}