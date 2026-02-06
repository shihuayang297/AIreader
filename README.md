# mod_aireader2 · 学术论文 AI 伴读

Moodle 活动模块：在课程中提供 PDF 论文阅读 + 高亮/批注 + AI 伴读（多智能体对话）。

## 环境要求

- Moodle 4.0+
- PHP 7.4+
- Python 3（用于 PDF 解析与 AI 服务）
- Node.js 18+（用于前端构建）

## 安装

1. 将本模块放入 `moodle/mod/aireader2/`
2. 访问 **网站管理 → 通知**，执行升级
3. 前端构建：`cd frontend && npm install && npm run build`

## 使用

在课程中「添加活动或资源」→ 选择「学术论文AI伴读2」→ 上传 PDF、保存。学生进入后可阅读、标注、与 AI 伴读对话。

## 仓库说明

- 私有仓库：由仓库创建者在 GitHub/GitLab 中设置 **Private** 并邀请协作者查看。
- 克隆后需在 `frontend` 目录执行 `npm install && npm run build` 生成 `frontend/dist/`。
