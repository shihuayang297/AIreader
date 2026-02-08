<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  structure: { type: Array, default: () => [] },
  rules: { type: Array, default: () => [] },
  apiUrl: { type: String, required: true }
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
  <div class="min-h-screen bg-gray-50 p-6 font-sans text-gray-800">
    
    <div class="max-w-5xl mx-auto mb-6 flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">📚 导读配置中心</h1>
        <p class="text-sm text-gray-500 mt-1">可视化管理论文结构与 AI 触发逻辑</p>
      </div>
      <div v-if="message" class="bg-green-100 text-green-700 px-4 py-2 rounded-lg text-sm font-bold animate-pulse">
        {{ message }}
      </div>
    </div>

    <div class="max-w-5xl mx-auto bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
      
      <div class="flex border-b border-gray-200 bg-gray-50/50">
        <button 
          @click="activeTab = 'structure'"
          class="px-6 py-4 text-sm font-bold transition-colors border-b-2"
          :class="activeTab === 'structure' ? 'border-blue-600 text-blue-600 bg-white' : 'border-transparent text-gray-500 hover:text-gray-700'"
        >
          📑 目录结构 (Structure)
        </button>
        <button 
          @click="activeTab = 'rules'"
          class="px-6 py-4 text-sm font-bold transition-colors border-b-2"
          :class="activeTab === 'rules' ? 'border-purple-600 text-purple-600 bg-white' : 'border-transparent text-gray-500 hover:text-gray-700'"
        >
          🧠 AI 触发规则 (Rules)
        </button>
      </div>

      <div v-if="activeTab === 'structure'" class="p-6">
        <div class="flex justify-between items-center mb-4">
          <h3 class="font-bold text-gray-700">章节列表</h3>
          <button @click="saveStructure" :disabled="loading" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50 text-sm font-bold transition">
            {{ loading ? '保存中...' : '💾 保存整个结构' }}
          </button>
        </div>

        <div class="space-y-3">
          <div v-for="(item, idx) in localStructure" :key="idx" class="flex gap-3 items-start p-3 bg-gray-50 rounded-xl border border-gray-100 group hover:border-blue-200 transition">
            
            <div class="flex flex-col gap-1 pt-2">
              <button @click="moveSection(idx, -1)" class="text-gray-400 hover:text-blue-500 disabled:opacity-30" :disabled="idx===0">⬆️</button>
              <button @click="moveSection(idx, 1)" class="text-gray-400 hover:text-blue-500 disabled:opacity-30" :disabled="idx===localStructure.length-1">⬇️</button>
            </div>

            <div class="flex-1 grid grid-cols-12 gap-3">
              <div class="col-span-1">
                <label class="text-[10px] uppercase text-gray-400 font-bold">页码</label>
                <input v-model.number="item.page" type="number" class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
              </div>
              <div class="col-span-4">
                <label class="text-[10px] uppercase text-gray-400 font-bold">章节标题 (用于定位)</label>
                <input v-model="item.title" type="text" class="w-full border border-gray-300 rounded px-2 py-1 text-sm font-bold focus:ring-2 focus:ring-blue-500 outline-none">
              </div>
              <div class="col-span-7">
                <label class="text-[10px] uppercase text-gray-400 font-bold">摘要 (可选)</label>
                <input v-model="item.summary" type="text" class="w-full border border-gray-300 rounded px-2 py-1 text-sm text-gray-600 focus:ring-2 focus:ring-blue-500 outline-none">
              </div>
            </div>

            <button @click="removeSection(idx)" class="mt-6 text-red-400 hover:text-red-600 p-1">✕</button>
          </div>
        </div>

        <button @click="addSection" class="mt-4 w-full py-2 border-2 border-dashed border-gray-300 text-gray-400 rounded-xl hover:border-blue-400 hover:text-blue-500 font-bold transition">
          + 添加新章节
        </button>
      </div>

      <div v-else class="p-6">
        <div class="flex justify-between items-center mb-4">
          <h3 class="font-bold text-gray-700">智能体触发配置</h3>
          <button @click="addRule" class="bg-purple-100 text-purple-700 px-4 py-2 rounded-lg hover:bg-purple-200 text-sm font-bold transition">
            + 新增规则
          </button>
        </div>

        <div class="space-y-4">
          <div v-for="(rule, idx) in localRules" :key="idx" class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm relative hover:shadow-md transition">
             <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                
                <div class="md:col-span-1">
                   <label class="block text-xs font-bold text-gray-500 mb-1">触发位置 (Keyword)</label>
                   <select v-model="rule.section_keyword" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-purple-500 outline-none">
                     <option value="" disabled>选择章节...</option>
                     <option v-for="opt in sectionOptions" :key="opt" :value="opt">{{ opt }}</option>
                   </select>
                   <p class="text-[10px] text-gray-400 mt-1">当 AI 检测到阅读进度到达此章节时触发。</p>
                </div>

                <div class="md:col-span-2">
                   <label class="block text-xs font-bold text-gray-500 mb-1">AI 引导话术 (Prompt)</label>
                   <textarea v-model="rule.trigger_prompt" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-purple-500 outline-none resize-none" placeholder="例如：你已经进入了方法论部分，请思考..."></textarea>
                </div>
             </div>

             <div class="mt-3 flex justify-end gap-2 border-t border-gray-100 pt-2">
                <button @click="deleteRule(idx, rule)" class="text-xs text-red-500 px-3 py-1 hover:bg-red-50 rounded">删除</button>
                <button @click="saveRule(rule)" :disabled="loading" class="text-xs bg-purple-600 text-white px-4 py-1.5 rounded hover:bg-purple-700 disabled:opacity-50 font-bold">
                   {{ loading ? '...' : '保存此规则' }}
                </button>
             </div>
          </div>
        </div>

        <div v-if="localRules.length === 0" class="text-center py-10 text-gray-400 bg-gray-50 rounded-xl">
           暂无规则，点击右上角添加。
        </div>
      </div>

    </div>
  </div>
</template>