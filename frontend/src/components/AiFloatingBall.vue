<script setup>
import { ref } from 'vue'
import { Sparkles, X, Minimize2, Bot, User, SendHorizonal } from 'lucide-vue-next'

const showChatBox = ref(false)
const chatInput = ref('')
const chatHistory = ref([
    { role: 'ai', content: '嘿！我是您的随身写作精灵，有问题随时点我。' }
])

const toggleChat = () => showChatBox.value = !showChatBox.value

const sendMessage = () => {
    if(!chatInput.value.trim()) return
    chatHistory.value.push({ role: 'user', content: chatInput.value })
    chatInput.value = ''
    setTimeout(() => {
        chatHistory.value.push({ role: 'ai', content: '收到！' })
    }, 500)
}
</script>

<template>
  <div class="absolute bottom-6 right-20 z-50 flex flex-col items-end gap-3 pointer-events-none">
    <div class="pointer-events-auto flex flex-col items-end gap-3">
        <transition name="chat-pop">
            <div v-if="showChatBox" class="w-[300px] h-[400px] bg-white rounded-2xl shadow-2xl border border-gray-100 flex flex-col overflow-hidden">
                <div class="h-10 bg-gradient-to-r from-[#003366] to-[#004080] flex items-center justify-between px-4 text-white">
                    <span class="text-xs font-bold">快捷提问</span>
                    <button @click="showChatBox=false"><Minimize2 class="w-3.5 h-3.5 opacity-80"/></button>
                </div>
                <div class="flex-1 p-3 overflow-y-auto bg-gray-50 custom-scrollbar space-y-3">
                    <div v-for="(msg, i) in chatHistory" :key="i" :class="['flex gap-2', msg.role==='user'?'flex-row-reverse':'']">
                        <div :class="['p-2 rounded-lg text-xs max-w-[85%]', msg.role==='user'?'bg-[#003366] text-white':'bg-white border text-gray-700']">
                            {{ msg.content }}
                        </div>
                    </div>
                </div>
                <div class="p-2 bg-white border-t border-gray-100 flex gap-2">
                    <input v-model="chatInput" @keyup.enter="sendMessage" class="flex-1 bg-gray-100 rounded-full px-3 py-1.5 text-xs outline-none" placeholder="问点什么...">
                    <button @click="sendMessage" class="bg-[#003366] text-white p-1.5 rounded-full"><SendHorizonal class="w-3.5 h-3.5"/></button>
                </div>
            </div>
        </transition>

        <button
          @click="toggleChat"
          :class="['flex items-center justify-center w-12 h-12 rounded-full shadow-lg transition-all duration-300 group', showChatBox ? 'bg-white text-[#003366]' : 'bg-gradient-to-br from-[#003366] to-[#00509d] text-white hover:scale-110']"
        >
          <X v-if="showChatBox" class="w-5 h-5" />
          <Sparkles v-else class="w-6 h-6 group-hover:animate-pulse" />
        </button>
    </div>
  </div>
</template>

<style>
.chat-pop-enter-active, .chat-pop-leave-active { transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
.chat-pop-enter-from, .chat-pop-leave-to { opacity: 0; transform: scale(0.8) translateY(20px); }
</style>