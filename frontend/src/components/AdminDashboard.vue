<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  structure: { type: Array, default: () => [] },
  rules: { type: Array, default: () => [] },
  apiUrl: { type: String, required: true },
  backUrl: { type: String, default: '' }
})

const activeTab = ref('structure') // structure | rules
const loading = ref(false)
const message = ref('')

// === 数据状态 ===
// 必须要深拷贝，否则无法编辑
const localStructure = ref(JSON.parse(JSON.stringify(props.structure)))
const localRules = ref(JSON.parse(JSON.stringify(props.rules)))

// === 目录结构逻辑 ===
const addSection = () => {
  localStructure.value.push({ title: 'New Section', page: 1, summary: '' })
}
const removeSection = (index) => {
  if(confirm('确定删除该章节吗？')) localStructure.value.splice(index, 1)
}
const moveSection = (index, direction) => {
  const newIndex = index + direction
  if (newIndex < 0 || newIndex >= localStructure.value.length) return
  const temp = localStructure.value[index]
  localStructure.value[index] = localStructure.value[newIndex]
  localStructure.value[newIndex] = temp
}

// === 触发规则逻辑 ===
// 获取所有章节标题供下拉选择
const sectionOptions = computed(() => localStructure.value.map(s => s.title))

const addRule = () => {
  localRules.value.push({
    id: null, // 新增的没有ID
    section_keyword: sectionOptions.value[0] || '',
    trigger_prompt: '你已进入该章节，请...'
  })
}
const deleteRule = async (index, rule) => {
  if(!confirm('确定删除这条规则吗？')) return

  if (rule.id) {
    // 如果是已保存的规则，请求后端删除
    await sendRequest('delete_rule', { id: rule.id })
  }
  localRules.value.splice(index, 1)
}

// === API 通用方法 ===
const sendRequest = async (action, data) => {
  loading.value = true
  message.value = ''
  try {
    const res = await fetch(`${props.apiUrl}&action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    })
    const json = await res.json()
    if (json.status === 'success') {
      message.value = '✅ 操作成功'
      setTimeout(() => message.value = '', 2000)
      return json
    } else {
      alert('Error: ' + json.message)
    }
  } catch (e) {
    console.error(e)
    alert('请求失败，请检查网络')
  } finally {
    loading.value = false
  }
}

const saveStructure = async () => {
  await sendRequest('save_structure', { structure: localStructure.value })
}

const saveRule = async (rule) => {
  const res = await sendRequest('save_rule', { rule })
  if (res && res.data && res.data.id) {
    rule.id = res.data.id // 回填 ID
  }
}
</script>

<template>
  <div class="admin-dash">
    <!-- 顶栏：深色渐变 + 玻璃感 -->
    <header class="admin-dash-header">
      <div class="admin-dash-header-bg"></div>
      <div class="admin-dash-header-inner">
        <div class="admin-dash-brand">
          <div class="admin-dash-brand-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><path d="M8 7h8"/><path d="M8 11h8"/></svg>
          </div>
          <div>
            <h1 class="admin-dash-title">导读配置中心</h1>
            <p class="admin-dash-sub">可视化管理论文结构与 AI 触发逻辑</p>
          </div>
        </div>
        <div class="admin-dash-header-actions">
          <transition name="toast">
            <span v-if="message" class="admin-dash-toast">{{ message }}</span>
          </transition>
          <a v-if="backUrl" :href="backUrl" class="admin-dash-back">
            <svg class="admin-dash-back-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            <span>返回活动</span>
          </a>
        </div>
      </div>
    </header>

    <!-- 标签栏：胶囊 + 高亮条 -->
    <div class="admin-dash-tabs-wrap">
      <div class="admin-dash-tabs">
        <button
          type="button"
          @click="activeTab = 'structure'"
          class="admin-dash-tab"
          :class="{ active: activeTab === 'structure' }"
        >
          <span class="admin-dash-tab-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
          </span>
          <span>目录结构</span>
        </button>
        <button
          type="button"
          @click="activeTab = 'rules'"
          class="admin-dash-tab"
          :class="{ active: activeTab === 'rules' }"
        >
          <span class="admin-dash-tab-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 0-4 4v2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2h-2V6a4 4 0 0 0-4-4z"/></svg>
          </span>
          <span>AI 触发规则</span>
        </button>
      </div>
    </div>

    <!-- 内容区 -->
    <div class="admin-dash-body">
      <div class="admin-dash-content">

        <!-- 目录结构 Tab -->
        <template v-if="activeTab === 'structure'">
          <div class="admin-dash-panel admin-dash-panel-structure">
            <div class="admin-dash-panel-head">
              <h3 class="admin-dash-panel-title">
                <span class="admin-dash-panel-title-dot"></span>
                章节列表
              </h3>
              <button
                type="button"
                @click="saveStructure"
                :disabled="loading"
                class="admin-dash-btn-primary"
              >
                <svg class="admin-dash-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></svg>
                {{ loading ? '保存中...' : '保存整个结构' }}
              </button>
            </div>

            <div class="admin-dash-list">
              <div
                v-for="(item, idx) in localStructure"
                :key="idx"
                class="admin-dash-card admin-dash-section-row"
              >
                <span class="admin-dash-section-num">{{ idx + 1 }}</span>
                <div class="admin-dash-section-move">
                  <button
                    type="button"
                    @click="moveSection(idx, -1)"
                    :disabled="idx === 0"
                    class="admin-dash-icon-btn"
                    title="上移"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 15l-6-6-6 6"/></svg>
                  </button>
                  <button
                    type="button"
                    @click="moveSection(idx, 1)"
                    :disabled="idx === localStructure.length - 1"
                    class="admin-dash-icon-btn"
                    title="下移"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                  </button>
                </div>
                <div class="admin-dash-section-fields">
                  <div class="admin-dash-field admin-dash-field-page">
                    <label>页码</label>
                    <input v-model.number="item.page" type="number" class="admin-dash-input" min="1" />
                  </div>
                  <div class="admin-dash-field admin-dash-field-title">
                    <label>章节标题 (用于定位)</label>
                    <input v-model="item.title" type="text" class="admin-dash-input" placeholder="输入章节标题" />
                  </div>
                  <div class="admin-dash-field admin-dash-field-summary">
                    <label>摘要 (可选)</label>
                    <input v-model="item.summary" type="text" class="admin-dash-input admin-dash-input-muted" placeholder="可选摘要" />
                  </div>
                </div>
                <button
                  type="button"
                  @click="removeSection(idx)"
                  class="admin-dash-remove"
                  title="删除章节"
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
              </div>
            </div>

            <button type="button" @click="addSection" class="admin-dash-add-more">
              <span class="admin-dash-add-more-icon">+</span>
              <span>添加新章节</span>
            </button>
          </div>
        </template>

        <!-- AI 触发规则 Tab -->
        <template v-else>
          <div class="admin-dash-panel admin-dash-panel-rules">
            <div class="admin-dash-panel-head">
              <h3 class="admin-dash-panel-title">
                <span class="admin-dash-panel-title-dot admin-dash-panel-title-dot-purple"></span>
                智能体触发配置
              </h3>
              <button type="button" @click="addRule" class="admin-dash-btn-secondary">
                <svg class="admin-dash-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                新增规则
              </button>
            </div>

            <div class="admin-dash-rules-list">
              <div
                v-for="(rule, idx) in localRules"
                :key="idx"
                class="admin-dash-card admin-dash-rule-card"
              >
                <div class="admin-dash-rule-grid">
                  <div class="admin-dash-field">
                    <label>触发位置 (Keyword)</label>
                    <select v-model="rule.section_keyword" class="admin-dash-select">
                      <option value="" disabled>选择章节...</option>
                      <option v-for="opt in sectionOptions" :key="opt" :value="opt">{{ opt }}</option>
                    </select>
                    <p class="admin-dash-hint">当 AI 检测到阅读进度到达此章节时触发。</p>
                  </div>
                  <div class="admin-dash-field admin-dash-field-wide">
                    <label>AI 引导话术 (Prompt)</label>
                    <textarea
                      v-model="rule.trigger_prompt"
                      rows="3"
                      class="admin-dash-textarea"
                      placeholder="例如：你已经进入了方法论部分，请思考..."
                    ></textarea>
                  </div>
                </div>
                <div class="admin-dash-rule-actions">
                  <button type="button" @click="deleteRule(idx, rule)" class="admin-dash-btn-ghost danger">
                    <svg class="admin-dash-btn-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6M14 11v6"/></svg>
                    删除
                  </button>
                  <button
                    type="button"
                    @click="saveRule(rule)"
                    :disabled="loading"
                    class="admin-dash-btn-primary admin-dash-btn-sm"
                  >
                    <svg class="admin-dash-btn-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></svg>
                    {{ loading ? '...' : '保存此规则' }}
                  </button>
                </div>
              </div>
            </div>

            <div v-if="localRules.length === 0" class="admin-dash-empty">
              <div class="admin-dash-empty-icon-wrap">
                <svg class="admin-dash-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
              </div>
              <p>暂无规则，点击右上角「新增规则」添加。</p>
            </div>
          </div>
        </template>

      </div>
    </div>
  </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap');

/* 腾讯白蓝浅色风格：主色 #006EFF，背景 #f5f7fa / #fff */
.admin-dash {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
  position: relative;
  z-index: 1;
  background: #f5f7fa;
}

/* ========== 顶栏：白底 + 腾讯蓝 ========== */
.admin-dash-header {
  color: #1a1a1a;
  padding: 18px 32px;
  position: relative;
  min-height: 68px;
  overflow: hidden;
}
.admin-dash-header-bg {
  position: absolute;
  inset: 0;
  background: #fff;
  border-bottom: 1px solid #e4e7ed;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.admin-dash-header-bg::after { display: none; }
.admin-dash-header-inner {
  position: relative;
  z-index: 1;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
}
.admin-dash-brand { display: flex; align-items: center; gap: 16px; }
.admin-dash-brand-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: #e8f4ff;
  border: 1px solid #cce5ff;
  display: flex;
  align-items: center;
  justify-content: center;
}
.admin-dash-brand-icon svg { width: 24px; height: 24px; color: #006EFF; }
.admin-dash-title {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: #1a1a1a;
  letter-spacing: -0.02em;
}
.admin-dash-sub {
  margin: 4px 0 0 0;
  font-size: 13px;
  font-weight: 500;
  color: #5c6b7a;
  letter-spacing: 0.02em;
}
.admin-dash-header-actions { display: flex; align-items: center; gap: 14px; }
.admin-dash-toast {
  background: #00c48c;
  color: #fff;
  padding: 8px 16px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  box-shadow: 0 2px 12px rgba(0,196,140,0.35);
  animation: admin-toast-in 0.3s ease, admin-toast-pulse 2s ease-in-out 0.3s infinite;
}
@keyframes admin-toast-in { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
@keyframes admin-toast-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.9; } }
.admin-dash-back {
  background: #fff;
  color: #006EFF;
  padding: 8px 18px;
  border-radius: 8px;
  text-decoration: none;
  font-size: 14px;
  font-weight: 600;
  transition: all 0.2s ease;
  border: 1px solid #cce5ff;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.admin-dash-back:hover { background: #e8f4ff; color: #0052d9; border-color: #006EFF; }
.admin-dash-back-icon { width: 18px; height: 18px; flex-shrink: 0; color: #006EFF; }

/* ========== 标签栏：白底 + 蓝下划线 ========== */
.admin-dash-tabs-wrap {
  padding: 0 32px;
  background: #fff;
  border-bottom: 1px solid #e4e7ed;
  box-shadow: 0 1px 2px rgba(0,0,0,0.03);
}
.admin-dash-tabs { display: inline-flex; gap: 0; padding: 0; }
.admin-dash-tab {
  padding: 14px 24px;
  border: none;
  background: transparent;
  cursor: pointer;
  color: #5c6b7a;
  font-weight: 600;
  font-size: 14px;
  font-family: inherit;
  letter-spacing: 0.02em;
  border-bottom: 3px solid transparent;
  margin-bottom: -1px;
  transition: all 0.2s ease;
  display: inline-flex;
  align-items: center;
  gap: 10px;
}
.admin-dash-tab:hover { color: #006EFF; }
.admin-dash-tab.active {
  color: #006EFF;
  border-bottom-color: #006EFF;
  background: transparent;
  box-shadow: none;
}
.admin-dash-tab-icon { display: flex; }
.admin-dash-tab-icon svg { width: 18px; height: 18px; }
.admin-dash-tab:not(.active) .admin-dash-tab-icon svg { color: #8a9bab; }
.admin-dash-tab.active .admin-dash-tab-icon svg { color: #006EFF; }

/* ========== 内容区 ========== */
.admin-dash-body { flex: 1; position: relative; z-index: 1; padding: 32px; overflow-y: auto; }
.admin-dash-content { max-width: 980px; margin: 0 auto; }

/* ========== 面板：白卡片 + 腾讯蓝左边线 ========== */
.admin-dash-panel {
  background: #fff;
  border-radius: 12px;
  padding: 28px 32px;
  box-shadow: 0 2px 12px rgba(0,0,0,0.06);
  border: 1px solid #e4e7ed;
  position: relative;
  overflow: hidden;
}
.admin-dash-panel::before {
  content: '';
  position: absolute;
  left: 0; top: 0; bottom: 0;
  width: 4px;
  background: #006EFF;
  border-radius: 4px 0 0 4px;
}
.admin-dash-panel-rules::before { background: #006EFF; }
.admin-dash-panel-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.admin-dash-panel-title {
  margin: 0;
  font-size: 17px;
  font-weight: 700;
  color: #1a1a1a;
  display: flex;
  align-items: center;
  gap: 10px;
  letter-spacing: -0.02em;
}
.admin-dash-panel-title-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #006EFF;
}
.admin-dash-panel-title-dot-purple { background: #006EFF; }

/* ========== 按钮：腾讯蓝主色 ========== */
.admin-dash-btn-primary {
  background: #006EFF;
  color: #fff;
  border: none;
  padding: 10px 20px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.2s ease;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 2px 8px rgba(0,110,255,0.25);
}
.admin-dash-btn-primary:hover:not(:disabled) {
  background: #0052d9;
  box-shadow: 0 4px 12px rgba(0,110,255,0.3);
}
.admin-dash-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.admin-dash-btn-secondary {
  background: #fff;
  color: #006EFF;
  border: 1px solid #cce5ff;
  padding: 10px 20px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.2s ease;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.admin-dash-btn-secondary:hover { background: #e8f4ff; border-color: #006EFF; }
.admin-dash-btn-icon { width: 18px; height: 18px; flex-shrink: 0; }
.admin-dash-btn-icon-sm { width: 16px; height: 16px; flex-shrink: 0; }
.admin-dash-btn-sm { padding: 8px 16px; font-size: 13px; }
.admin-dash-btn-ghost {
  background: #fff;
  border: 1px solid #e4e7ed;
  color: #5c6b7a;
  padding: 8px 16px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.2s;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.admin-dash-btn-ghost:hover { background: #f5f7fa; color: #1a1a1a; border-color: #c8cdd4; }
.admin-dash-btn-ghost.danger { color: #e34d59; border-color: #ffd4d8; background: #fff; }
.admin-dash-btn-ghost.danger:hover { background: #fff0f2; color: #c93542; }

/* ========== 章节列表卡片：浅灰底 + 蓝点缀 ========== */
.admin-dash-list { display: flex; flex-direction: column; gap: 14px; }
.admin-dash-section-row {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 18px 22px;
  background: #fff;
  border-radius: 10px;
  border: 1px solid #e4e7ed;
  transition: all 0.2s ease;
  position: relative;
  overflow: hidden;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.admin-dash-section-row::before {
  content: '';
  position: absolute;
  left: 0; top: 0; bottom: 0;
  width: 3px;
  background: #006EFF;
  opacity: 0;
  transition: opacity 0.2s;
}
.admin-dash-section-row:hover {
  border-color: #cce5ff;
  box-shadow: 0 2px 12px rgba(0,110,255,0.08);
}
.admin-dash-section-row:hover::before { opacity: 1; }
.admin-dash-section-num {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  background: #e8f4ff;
  color: #006EFF;
  font-size: 13px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.admin-dash-section-move { display: flex; flex-direction: column; gap: 4px; padding-top: 2px; flex-shrink: 0; }
.admin-dash-icon-btn {
  width: 36px;
  height: 32px;
  border: 1px solid #e4e7ed;
  background: #fff;
  color: #5c6b7a;
  border-radius: 8px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
}
.admin-dash-icon-btn svg { width: 16px; height: 16px; }
.admin-dash-icon-btn:hover:not(:disabled) { background: #e8f4ff; color: #006EFF; border-color: #cce5ff; }
.admin-dash-icon-btn:disabled { opacity: 0.35; cursor: not-allowed; }
.admin-dash-section-fields { flex: 1; display: grid; grid-template-columns: 80px 1fr 1.4fr; gap: 16px; align-items: start; min-width: 0; }
.admin-dash-field label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: #5c6b7a;
  margin-bottom: 6px;
}
.admin-dash-input,
.admin-dash-select,
.admin-dash-textarea {
  width: 100%;
  padding: 10px 14px;
  border: 1px solid #e4e7ed;
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
  background: #fff;
  color: #1a1a1a;
  transition: all 0.2s;
}
.admin-dash-input:focus,
.admin-dash-select:focus,
.admin-dash-textarea:focus {
  outline: none;
  border-color: #006EFF;
  box-shadow: 0 0 0 2px rgba(0,110,255,0.12);
}
.admin-dash-input-muted { color: #5c6b7a; }
.admin-dash-textarea { resize: vertical; min-height: 80px; }
.admin-dash-remove {
  width: 36px;
  height: 36px;
  border: none;
  background: #fff0f2;
  color: #e34d59;
  border-radius: 8px;
  cursor: pointer;
  flex-shrink: 0;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  border: 1px solid #ffd4d8;
}
.admin-dash-remove svg { width: 16px; height: 16px; }
.admin-dash-remove:hover { background: #ffd4d8; color: #c93542; }

.admin-dash-add-more {
  width: 100%;
  margin-top: 20px;
  padding: 16px;
  border: 1px dashed #cce5ff;
  border-radius: 10px;
  background: #fafcff;
  color: #006EFF;
  font-size: 14px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.admin-dash-add-more:hover {
  border-color: #006EFF;
  background: #e8f4ff;
}
.admin-dash-add-more-icon { font-size: 20px; font-weight: 700; line-height: 1; }

/* ========== 规则卡片 ========== */
.admin-dash-rules-list { display: flex; flex-direction: column; gap: 16px; }
.admin-dash-rule-card {
  padding: 22px 24px;
  background: #fff;
  border-radius: 10px;
  border: 1px solid #e4e7ed;
  transition: all 0.2s ease;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.admin-dash-rule-card:hover { border-color: #cce5ff; box-shadow: 0 2px 12px rgba(0,110,255,0.06); }
.admin-dash-rule-grid { display: grid; grid-template-columns: 1fr 1.6fr; gap: 22px; margin-bottom: 18px; }
.admin-dash-field-wide { min-width: 0; }
.admin-dash-hint { font-size: 12px; color: #8a9bab; margin-top: 6px; line-height: 1.4; }
.admin-dash-rule-actions { display: flex; justify-content: flex-end; gap: 12px; padding-top: 18px; border-top: 1px solid #e4e7ed; }

/* ========== 空状态 ========== */
.admin-dash-empty {
  text-align: center;
  padding: 48px 28px;
  background: #fafcff;
  border-radius: 12px;
  border: 1px dashed #cce5ff;
  color: #5c6b7a;
  font-size: 14px;
  font-weight: 500;
}
.admin-dash-empty-icon-wrap {
  width: 56px;
  height: 56px;
  margin: 0 auto 16px;
  border-radius: 12px;
  background: #e8f4ff;
  border: 1px solid #cce5ff;
  display: flex;
  align-items: center;
  justify-content: center;
}
.admin-dash-empty-icon { width: 28px; height: 28px; color: #006EFF; }
.admin-dash-empty p { margin: 0; }

/* 简单过渡 */
.toast-enter-active, .toast-leave-active { transition: opacity 0.25s, transform 0.25s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(-8px); }

@media (max-width: 768px) {
  .admin-dash-section-fields { grid-template-columns: 1fr; }
  .admin-dash-rule-grid { grid-template-columns: 1fr; }
  .admin-dash-header-inner { flex-direction: column; align-items: flex-start; }
  .admin-dash-tabs { flex-wrap: wrap; }
  .admin-dash-body { padding: 20px; }
  .admin-dash-panel { padding: 24px; }
}
</style>
