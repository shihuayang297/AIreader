document.addEventListener('DOMContentLoaded', () => {
    
    // --- 0. 配置头像 ---
    const appRoot = document.getElementById('app-root');
    const userAvatarUrl = appRoot.dataset.graderAvatar; 
    // AI 头像用 FontAwesome 图标，或者你可以替换为图片 URL
    
    // --- 1. 评分联动 ---
    const slider = document.getElementById('grade-slider');
    const scoreText = document.getElementById('score-text');
    slider.addEventListener('input', (e) => {
        scoreText.innerText = e.target.value;
    });

    // --- 2. 悬浮球 & 窗口逻辑 ---
    const ball = document.getElementById('ai-ball');
    const windowEl = document.getElementById('ai-window');
    const minBtn = document.getElementById('btn-minimize');
    const dragHandle = document.getElementById('window-drag-handle');
    const resizeHandle = document.querySelector('.resize-handle');

    // 打开窗口
    ball.addEventListener('click', () => {
        ball.classList.add('hidden');
        windowEl.classList.remove('hidden');
    });

    // 最小化
    minBtn.addEventListener('click', () => {
        windowEl.classList.add('hidden');
        ball.classList.remove('hidden');
    });

    // --- 3. 拖拽移动 (Header Drag) ---
    let isDragging = false;
    let startX, startY, initLeft, initTop;

    dragHandle.addEventListener('mousedown', (e) => {
        isDragging = true;
        startX = e.clientX;
        startY = e.clientY;
        const rect = windowEl.getBoundingClientRect();
        initLeft = rect.left;
        initTop = rect.top;
        e.preventDefault();
    });

    // --- 4. 调整大小 (Resize Drag) ---
    let isResizing = false;
    let rStartX, rStartY, initW, initH;

    resizeHandle.addEventListener('mousedown', (e) => {
        isResizing = true;
        rStartX = e.clientX;
        rStartY = e.clientY;
        const rect = windowEl.getBoundingClientRect();
        initW = rect.width;
        initH = rect.height;
        e.preventDefault();
        e.stopPropagation(); // 防止触发移动
    });

    document.addEventListener('mousemove', (e) => {
        // 移动逻辑
        if (isDragging) {
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            windowEl.style.left = (initLeft + dx) + 'px';
            windowEl.style.top = (initTop + dy) + 'px';
            windowEl.style.right = 'auto';
            windowEl.style.bottom = 'auto';
        }
        // 调整大小逻辑
        if (isResizing) {
            const dx = e.clientX - rStartX;
            const dy = e.clientY - rStartY;
            windowEl.style.width = Math.max(300, initW + dx) + 'px';
            windowEl.style.height = Math.max(400, initH + dy) + 'px';
        }
    });

    document.addEventListener('mouseup', () => {
        isDragging = false;
        isResizing = false;
    });

    // --- 5. 聊天功能 (WeChat Style) ---
    const chatInput = document.getElementById('chat-input');
    const sendBtn = document.getElementById('btn-send');
    const chatBox = document.getElementById('chat-box');
    const feedbackArea = document.getElementById('feedback-area');

    // 获取当前时间 HH:mm
    function getTimeStr() {
        const now = new Date();
        return now.getHours().toString().padStart(2,'0') + ':' + 
               now.getMinutes().toString().padStart(2,'0');
    }

    // 渲染消息
    function renderMsg(role, text) {
        const time = getTimeStr();
        const row = document.createElement('div');
        row.className = `chat-row ${role}-row`; // ai-row 或 user-row

        let avatarHtml = '';
        let nameHtml = '';

        if (role === 'ai') {
            avatarHtml = `<div class="avatar-icon ai-bg"><i class="fa-solid fa-robot"></i></div>`;
            nameHtml = `<div class="chat-name">小师同学</div>`;
        } else {
            avatarHtml = `<div class="avatar-icon user-bg"><img src="${userAvatarUrl}"></div>`;
            // 用户不需要显示名字
        }
        
        const bubbleClass = role === 'ai' ? 'ai-bubble' : 'user-bubble';

        row.innerHTML = `
            <div class="avatar-container">${avatarHtml}</div>
            <div class="bubble-container">
                ${nameHtml}
                <div class="bubble ${bubbleClass}">${text}</div>
            </div>
        `;
        
        chatBox.appendChild(row);
        chatBox.scrollTop = chatBox.scrollHeight;
    }

    async function callAI(prompt, autoFill = false) {
        renderMsg('user', prompt);
        
        // 模拟 AI 思考中...
        const loadingId = 'loading-' + Date.now();
        const loadingRow = document.createElement('div');
        loadingRow.className = 'chat-row ai-row';
        loadingRow.id = loadingId;
        loadingRow.innerHTML = `
            <div class="avatar-container"><div class="avatar-icon ai-bg"><i class="fa-solid fa-robot"></i></div></div>
            <div class="bubble-container"><div class="bubble ai-bubble"><i class="fa-solid fa-ellipsis fa-fade"></i></div></div>
        `;
        chatBox.appendChild(loadingRow);
        chatBox.scrollTop = chatBox.scrollHeight;

        try {
            // 获取文章内容
            const context = document.querySelector('.paper-sheet').innerText;

            const formData = new FormData();
            formData.append('message', prompt);
            formData.append('context', context);
            formData.append('last_speaker', 'system');
            
            const res = await fetch('chat_api.php', { method: 'POST', body: formData });
            const data = await res.json();
            
            // 移除 Loading
            document.getElementById(loadingId).remove();

            const reply = Array.isArray(data) ? data[0].reply : data.reply;
            renderMsg('ai', reply);

            if (autoFill) {
                feedbackArea.value = reply;
                // 视觉提示
                feedbackArea.style.background = '#f0fdf4';
                setTimeout(() => feedbackArea.style.background = '#f9fafb', 1000);
            }

        } catch (e) {
            document.getElementById(loadingId).remove();
            renderMsg('ai', '🚫 连接超时，请稍后重试。');
        }
    }

    sendBtn.addEventListener('click', () => {
        const val = chatInput.value.trim();
        if(val) {
            callAI(val);
            chatInput.value = '';
        }
    });

    chatInput.addEventListener('keydown', (e) => {
        if(e.key === 'Enter') sendBtn.click();
    });

    // 快捷指令
    document.querySelectorAll('.chip').forEach(btn => {
        btn.addEventListener('click', () => {
            const prompt = btn.dataset.prompt;
            const isReview = prompt.includes('生成评语');
            callAI(prompt, isReview);
        });
    });
});