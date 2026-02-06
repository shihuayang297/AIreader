# -*- coding: utf-8 -*-
# 文件路径: mod/aireader/utils/pdf_parser.py
import pdfplumber
import sys
import json
import re
import time
import hashlib
import base64
import hmac
import datetime
from urllib.parse import urlparse
import urllib.request
import urllib.error

# ================= 配置区域 (请确保与 server/config.py 一致) =================
SPARK_APPID = "0d0ffb4b"
SPARK_API_SECRET = "YTU4OGUxZTMxMjU4ZjEwZDk4YzI4YTlm"
SPARK_API_KEY = "084ee0c577b8db253458a63525f87e11"
SPARK_URL = "wss://spark-openapi-n.cn-huabei-1.xf-yun.com/v1.1/chat_kjwx"
SPARK_DOMAIN = "kjwx"

# 需要自动生成总结的章节关键词 (正则)
TARGET_SECTIONS = re.compile(r'^(Introduction|Methodology|Methods|Discussion|Conclusion|引言|方法|讨论|结论)', re.I)

# ================= 简易版星火调用函数 (为了不依赖外部文件，直接内嵌) =================
def call_spark_summary(text):
    """调用星火大模型生成 Reference Content"""
    try:
        # 这里为了脚本的独立性，我们使用 WebSocket 的简易封装或者 HTTP 降级
        # 由于 Python 脚本运行环境复杂，这里为了稳定性，
        # 建议使用一段精简的 WebSocket 代码，或者如果你的服务器支持，调用 server.py 的接口
        # 为了演示完整逻辑，这里我们暂时返回一个"模拟AI总结"
        # ⚠️ 在生产环境中，请引入 websocket-client 库并复制完整的 SparkClient 类
        
        # --- 临时方案：如果环境里没有 websocket-client，这部分会报错 ---
        # 建议：仅仅提取原文片段作为 reference_content 往往比 AI 总结更准确
        # 这里的策略改为：提取该章节的前 800 个字作为 Context
        return text[:800].replace('\n', ' ') + "..."
    except Exception as e:
        return ""

# ================= 原有解析逻辑 + 增强 =================

def smart_logic_parse(file_path):
    # --- 1. 增强型正则库 ---
    EN_RE = r'^(\d+(\.\d+){0,2})[\s]+[A-Z\u4e00-\u9fa5]'
    CN_RE = r'^([一二三四五六七八九十]+[、\s]|[（(][一二三四五六七八九十]+[)）]|第[一二三四五六七八九十]+[章节])'
    KEY_RE = r'^(Abstract|Introduction|Conclusion|References|Reference|摘要|结论|参考文献)'
    
    START_PAGE_LIMIT = re.compile(r'^(Abstract|摘要)', re.I)
    SYSTEM_KEY_CHECK = re.compile(KEY_RE, re.I)
    COMBINED_RE = re.compile(f'({EN_RE}|{CN_RE}|{KEY_RE})', re.I)

    try:
        with pdfplumber.open(file_path) as pdf:
            # 采样正文字号
            font_stats = {}
            for page in pdf.pages[:3]:
                for char in page.chars:
                    s = round(char.get('size', 0), 2)
                    if 6 < s < 25: font_stats[s] = font_stats.get(s, 0) + 1
            body_size = max(font_stats, key=font_stats.get) if font_stats else 10

            all_potential_lines = [] 
            raw_candidates = []

            for i, page in enumerate(pdf.pages):
                lines = page.extract_text_lines(layout=True)
                for line in lines:
                    text = line['text'].strip()
                    if len(text) < 2: continue
                    all_potential_lines.append({"text": text, "page": i + 1})

                    if i == 0 and not START_PAGE_LIMIT.match(text): continue
                    if not line['chars']: continue
                    
                    char_sample = line['chars'][0]
                    curr_size = round(char_sample['size'], 2)
                    score = 0
                    match = COMBINED_RE.match(text)

                    if len(text) > 80 and text[-1] in '.,，。': continue

                    if SYSTEM_KEY_CHECK.match(text) and curr_size >= body_size: score += 100
                    elif match and curr_size > body_size + 0.1: score += 90
                    elif match:
                        if not re.search(r'[\d]\s*[,，\)\%\:]', text[:15]) and "M =" not in text: score += 60
                    elif curr_size > body_size + 1.5: score += 40

                    if score >= 50:
                        raw_candidates.append({
                            "title": text.split('  ')[0].strip(),
                            "page": i + 1,
                            "index": text.split(' ')[0].rstrip('.')
                        })

            # --- 去重与逻辑处理 ---
            unique_candidates = []
            seen_titles = set()
            for c in raw_candidates:
                if c['title'] not in seen_titles:
                    unique_candidates.append(c)
                    seen_titles.add(c['title'])

            final_output = []
            existing_indices = {c['index'] for c in unique_candidates if re.match(r'^\d', c['index'])}
            last_valid_main = 0

            for item in unique_candidates:
                if re.search(r'(SD\s*=|p\s*[<=]|vol\.|http|M\s*=)', item['title'], re.I): continue
                
                curr_idx = item['index']
                if re.match(r'^\d', curr_idx):
                    try:
                        main_num = int(curr_idx.split('.')[0])
                        if main_num < last_valid_main or main_num > last_valid_main + 1:
                            if last_valid_main != 0: continue
                        last_valid_main = main_num
                    except: continue

                if '.' in curr_idx:
                    parts = curr_idx.split('.')
                    for depth in range(1, len(parts)):
                        parent_idx = ".".join(parts[:depth])
                        if parent_idx not in existing_indices:
                            found_title = f"{parent_idx}."
                            search_pattern = re.compile(rf'^{re.escape(parent_idx)}\.?\s+([A-Z\u4e00-\u9fa5].*)')
                            for p_line in reversed(all_potential_lines):
                                if p_line['page'] <= item['page']:
                                    m = search_pattern.match(p_line['text'])
                                    if m:
                                        found_title = p_line['text'].split('  ')[0].strip()
                                        break
                            final_output.append({"title": found_title, "page": item['page']})
                            existing_indices.add(parent_idx)

                final_output.append({"title": item['title'], "page": item['page']})

            final_output.sort(key=lambda x: x['page'])

            # =========================================================================
            # 🔥🔥🔥 新增核心逻辑：提取重点章节原文 (Reference Content) 🔥🔥🔥
            # =========================================================================
            processed_output = []
            total_sections = len(final_output)

            for idx, item in enumerate(final_output):
                # 默认没有 summary
                item['summary'] = ""
                
                # 1. 判断是否是重点章节 (Intro, Methodology, etc.)
                if TARGET_SECTIONS.search(item['title']):
                    start_page = item['page']
                    # 确定结束页码：下一章的起始页，或者是文档末尾
                    if idx + 1 < total_sections:
                        end_page = final_output[idx+1]['page']
                    else:
                        end_page = len(pdf.pages)
                    
                    # 2. 提取该范围内的文本
                    extracted_text = ""
                    # 限制提取页数，防止爆内存，最多提取 3 页
                    extract_limit = min(end_page, start_page + 2) 
                    
                    for p_num in range(start_page, extract_limit + 1):
                        # pdfplumber页码从0开始，所以要 -1
                        if p_num <= len(pdf.pages):
                            page_text = pdf.pages[p_num-1].extract_text()
                            if page_text:
                                extracted_text += page_text + "\n"
                    
                    # 3. 生成 Reference Content (这里我们直接截取前1000字作为"原文片段")
                    # 这一步非常关键：我们不需要 AI 实时总结，直接把原文喂给"脑洞工程师"效果更好
                    # 因为脑洞工程师的 Prompt 已经具备了处理原文的能力
                    clean_text = extracted_text.replace('\n', ' ').strip()
                    item['summary'] = clean_text[:1200]  # 限制长度，存入数据库

                processed_output.append(item)

            print(json.dumps(processed_output, ensure_ascii=False))

    except Exception:
        print("[]")

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("[]")
    else:
        smart_logic_parse(sys.argv[1])