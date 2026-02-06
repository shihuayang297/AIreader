# server.py
from typing import List, Optional
from fastapi import FastAPI
from pydantic import BaseModel
import uvicorn
import re

# 🔥 导入模块
from config import PROMPTS
from spark_client import SparkLLM
from utils import classify_encyclopedia_intent

# 🔥 新增：导入你的策略库 (用于查表获取话术)
from strategies import SECTION_MAP, EVENT_MAP, DEFAULT_SECTION_MSG

# ================= FastAPI 服务层 =================
app = FastAPI()

class AgentRequest(BaseModel):
    message: str
    chat_history: str
    page_content: str
    current_page: int
    user_name: str
    trigger_event: Optional[str] = None
    active_agents: List[str]

# 🔥 强力清洗函数 (去除干扰词)
def clean_user_input(msg: str) -> str:
    # 1. 移除所有智能体的名字
    msg = msg.replace("@小科", "").replace("@百科", "") \
             .replace("@小脑", "").replace("@脑洞", "") \
             .replace("@小盘", "").replace("@复盘", "") \
             .replace("@小师", "").replace("@领航", "")
    
    # 2. 移除常见的指令废话 (让模型只看到核心内容，防止被翻译指令带偏)
    msg = re.sub(r'(翻译|解释|单词|一下|意思|含义|是指|什么|是|：|:)', ' ', msg)
    
    return msg.strip()

# 🔥🔥🔥 核心逻辑：根据触发事件获取对应的策略指令 🔥🔥🔥
def get_strategy_instruction(trigger_event, user_message):
    # 1. 章节切换事件 (section_switch)
    if trigger_event == 'section_switch':
        msg_lower = user_message.lower()
        # 遍历策略表，模糊匹配章节关键词
        for keyword, script in SECTION_MAP.items():
            if keyword in msg_lower:
                return f"检测到学生进入了【{keyword}】章节。请对学生说：\n{script}"
        # 没匹配到，用默认话术
        return f"检测到学生进入新章节。请对学生说：\n{DEFAULT_SECTION_MSG}"

    # 2. 其他系统事件 (idle_reminder, start, etc.)
    elif trigger_event in EVENT_MAP:
        return f"检测到系统触发事件【{trigger_event}】。请对学生说：\n{EVENT_MAP[trigger_event]}"

    # 3. 未知事件兜底
    return "学生正在与你互动。请给予鼓励。"

@app.post("/chat")
async def chat_endpoint(req: AgentRequest):
    try:
        # ==========================================
        # 1. 初始化默认状态 (兜底逻辑)
        # ==========================================
        target_agent = "navigator" # 默认兜底
        prompt_key = "navigator" # 默认 Prompt 键
        
        # 🔥 核心：默认的策略指令 (自由对话模式)
        # 如果没有任何触发事件，也没 @ 别人，领航者将执行这条指令进行自由聊天
        strategy_instruction = "学生正在与你进行自由对话。请作为导师，根据学生的输入给予亲切的回应、鼓励或学术解答。不要机械地重复指令。"
        
        # 先清洗用户的输入
        user_msg_clean = clean_user_input(req.message)
        
        # ==========================================
        # 2. 智能路由 (Router)
        # ==========================================
        
        # [优先级 A] 系统触发事件
        if req.trigger_event:
            target_agent = "navigator" 
            prompt_key = "navigator"
            # 🔥 查表获取具体的教学脚本
            strategy_instruction = get_strategy_instruction(req.trigger_event, req.message)
            print(f"📚 Strategy Triggered: {req.trigger_event}")
        
        # [优先级 B] 根据用户 @ 意图路由
        elif any(x in req.message for x in ["@小科", "@百科"]):
            target_agent = "encyclopedia"
            # 调用意图分类器 (使用原始消息判断意图更准)
            prompt_key = classify_encyclopedia_intent(req.message)
            print(f"🔍 Intent Detected: {prompt_key}") 
            strategy_instruction = "" # 其他智能体不需要策略指令

        elif any(x in req.message for x in ["@小脑", "@脑洞", "推理", "逻辑"]):
            target_agent = "idea_engineer"
            prompt_key = "idea_engineer"
            strategy_instruction = ""

        elif any(x in req.message for x in ["@小盘", "@复盘", "总结"]):
            target_agent = "reviewer"
            prompt_key = "reviewer"
            strategy_instruction = ""

        elif any(x in req.message for x in ["@小师", "@领航"]):
            target_agent = "navigator"
            prompt_key = "navigator"
            # 用户明确 @领航者，进入主动问答模式
            strategy_instruction = "学生正在主动向你提问。请根据上面的论文摘要和你的专业知识进行解答。"
        
        # [优先级 C] 无触发、无@ -> 保持上面的默认 strategy_instruction (自由对话)

        # ==========================================
        # 3. 组装 Prompt
        # ==========================================
        prompt_tmpl = PROMPTS.get(prompt_key, PROMPTS["navigator"])
        
        final_prompt = prompt_tmpl.format(
            current_page=req.current_page,
            trigger_event=req.trigger_event or "无",
            page_content=req.page_content[:3000],
            subject_context="教育技术学",
            user_name=req.user_name,
            user_input=user_msg_clean, # 注入清洗后的用户问题
            
            # 🔥 注入策略指令 (领航者Prompt会用到这个变量，其他智能体忽略)
            strategy_instruction=strategy_instruction 
        )
        
        # 加上历史记录和当前问题
        final_input = f"""
        [System Instruction]:
        {final_prompt}

        [Chat History]:
        {req.chat_history}

        [User Input]:
        {user_msg_clean} 
        """

        # --- D. 调用星火模型 ---
        print(f"🤖 Activating Agent: {target_agent} (Prompt: {prompt_key})")
        
        # 实例化新的 LLM 对象 (从 spark_client 导入)
        current_llm = SparkLLM() 
        response_text = current_llm.invoke(final_input)

        # --- E. 返回结果 ---
        return [{
            "role": target_agent,
            "reply": response_text
        }]

    except Exception as e:
        print(f"Error: {e}")
        return [{
            "role": "navigator",
            "reply": f"（小航系统报警）连接星火大脑失败：{str(e)}"
        }]

if __name__ == "__main__":
    # 使用 0.0.0.0 允许外部访问，端口 8000
    print("🔥🔥🔥 完整策略版服务启动：支持策略库+自由对话 🔥🔥🔥")
    uvicorn.run(app, host="0.0.0.0", port=8000)