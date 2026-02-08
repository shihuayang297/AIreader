import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
// 🔥 引入你刚才新建的教师端组件 (确保文件路径正确)
import AdminDashboard from './components/AdminDashboard.vue'

// ========================================================
// 场景 1: 学生端 / 阅读器界面
// (对应 view.php 输出的 <div id="app">)
// ========================================================
const studentEl = document.getElementById('app')
if (studentEl) {
    // 挂载学生端应用
    createApp(App).mount('#app')
}

// ========================================================
// 场景 2: 教师端 / 配置中心界面
// (对应 report.php 输出的 <div id="admin-app">)
// ========================================================
const adminEl = document.getElementById('admin-app')
if (adminEl) {
    // 1. 从 PHP 输出的 data-属性中提取数据
    let structure = []
    let rules = []
    
    try {
        // PHP 传过来的是 JSON 字符串，需要解析成对象数组
        structure = JSON.parse(adminEl.dataset.structure || '[]')
        rules = JSON.parse(adminEl.dataset.rules || '[]')
    } catch (e) {
        console.error('解析配置数据失败:', e)
    }

    const apiUrl = adminEl.dataset.apiUrl

    // 2. 挂载教师端应用，并通过 props 把数据传进去
    const app = createApp(AdminDashboard, {
        structure: structure,
        rules: rules,
        apiUrl: apiUrl
    })
    
    app.mount('#admin-app')
}