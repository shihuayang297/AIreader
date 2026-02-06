import re

def classify_encyclopedia_intent(message: str) -> str:
    """
    判断百科助手的回复意图：词汇解析、概念查询、学术翻译
    """
    # 1. 清洗输入
    msg_clean = message.replace("@小科", "").replace("@百科", "").strip()
    msg_lower = msg_clean.lower()
    
    # 2. 提取特征：所有的英文字符序列
    # 找出所有连续的英文字母
    english_words = re.findall(r'[a-zA-Z\-\_]+', msg_clean)
    english_word_count = len(english_words)
    
    # ================= 核心修改逻辑开始 =================
    
    # 1. 【学术翻译】 (encyclopedia_trans)
    # 判定依据：英文单词数 > 4 (说明是长句子，不管有没有写“翻译”二字，都当句子翻)
    if english_word_count > 4: 
        return "encyclopedia_trans"
        
    # 2. 【词汇解析】 (encyclopedia_term) -- 优先级上调！
    # 判定依据：包含少量英文单词 (1-4个)
    # 逻辑修复：哪怕用户说了 "翻译 education"，因为 education 是短词，
    # 我们依然走 "Term" 模式。因为 Term 模式给出的卡片(音标/释义/背景)比单纯的“教育”更有价值，且能避免大模型复读bug。
    if english_word_count > 0:
        return "encyclopedia_term"
    
    # 3. 【学术翻译】(补充兜底)
    # 如果没有英文单词（比如汉译英），或者其他漏网之鱼，且用户明确说了“翻译”
    if any(k in msg_lower for k in ["翻译", "translate", "中文意思", "汉译", "怎么说"]):
        return "encyclopedia_trans"

    # 4. 【概念查询】 (encyclopedia_concept)
    # 默认兜底：如果不符合上述特征，默认走概念解释（比较通用）
    return "encyclopedia_concept"