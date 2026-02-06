<template>
  <div class="flex flex-col items-center justify-center min-h-screen bg-[#eef2f6] p-8">
    <div class="text-center mb-12">
      <div class="flex justify-center mb-4">
        <img src="/mod/aireader/pix/icon.png" class="w-16 h-16" />
      </div>
      <h1 class="text-3xl font-bold text-slate-800 mb-2">上课作业：研习清华大学多智能体论文</h1>
      <p class="text-slate-500">欢迎进入 AI 学术伴读空间。在这里，四位不同专长的 AI 伙伴将全程协助你深度读懂论文。</p>
    </div>

    <div class="flex gap-8 max-w-6xl w-full">
      
      <div class="flex-1 bg-white rounded-3xl p-8 shadow-sm border border-slate-100 flex flex-col items-center text-center hover:shadow-xl transition-all">
        <div class="w-16 h-16 bg-blue-50 rounded-2xl flex items-center justify-center mb-6">
          <Users class="text-blue-500 w-8 h-8" />
        </div>
        <h3 class="text-xl font-bold text-slate-800 mb-4">你的伴读战队</h3>
        <ul class="text-left space-y-3 text-sm text-slate-600">
          <li class="flex items-start gap-2">
            <span class="font-bold text-blue-600 shrink-0">· 领航者:</span> 规划阅读路径，提供思维挑战
          </li>
          <li class="flex items-start gap-2">
            <span class="font-bold text-indigo-600 shrink-0">· 百科手:</span> 随时查阅术语，精准翻译长难句
          </li>
          <li class="flex items-start gap-2">
            <span class="font-bold text-orange-600 shrink-0">· 脑洞师:</span> 引导逻辑推理，解决理解断层
          </li>
          <li class="flex items-start gap-2">
            <span class="font-bold text-emerald-600 shrink-0">· 复盘官:</span> 梳理逻辑闭环，巩固学习成效
          </li>
        </ul>
      </div>

      <div class="flex-1 bg-white rounded-3xl p-8 shadow-sm border border-slate-100 flex flex-col items-center text-center hover:shadow-xl transition-all">
        <div class="w-16 h-16 bg-green-50 rounded-2xl flex items-center justify-center mb-6">
          <MousePointer2 class="text-green-500 w-8 h-8" />
        </div>
        <h3 class="text-xl font-bold text-slate-800 mb-4">交互式研读</h3>
        <p class="text-slate-600 text-sm mb-6 leading-relaxed">
          阅读过程中，你可以通过<b>划线选词</b>查询百科，或者点击领航者抛出的<b>思维卡片</b>接受挑战。
          遇到逻辑难题时，脑洞工程师会通过苏格拉底式提问引导你思考，最后复盘官会为你生成专属的思维复盘报告。
        </p>
      </div>

      <div class="flex-1 bg-white rounded-3xl p-8 shadow-sm border border-slate-100 flex flex-col items-center text-center hover:shadow-xl transition-all cursor-pointer" @click="$emit('start')">
        <div class="w-16 h-16 bg-orange-50 rounded-2xl flex items-center justify-center mb-6">
          <BookOpenCheck class="text-orange-500 w-8 h-8" />
        </div>
        <h3 class="text-xl font-bold text-slate-800 mb-4">开始深度研读</h3>
        <p class="text-slate-500 text-sm mb-8">进入学生视景，体验 PDF 阅读与 AI 智能体实时交互。</p>
        <button class="mt-auto px-8 py-3 bg-orange-500 text-white rounded-xl font-bold hover:bg-orange-600 transition-colors">
           立即开启
        </button>
      </div>

    </div>
  </div>
</template>