# -*- coding: utf-8 -*-
import pdfplumber
import sys
import json
import os

def extract_full_text(file_path):
    knowledge_base = {
        "pages": {} # 结构： { 1: "第一页内容...", 2: "第二页内容..." }
    }
    try:
        with pdfplumber.open(file_path) as pdf:
            for i, page in enumerate(pdf.pages):
                # 提取纯文本，如果提取失败则为空字符串
                text = page.extract_text() or ""
                # 页码从 1 开始
                knowledge_base["pages"][i + 1] = text.strip()
        
        # 直接输出 JSON，供 PHP 捕获
        print(json.dumps(knowledge_base, ensure_ascii=False))
        
    except Exception as e:
        # 出错时返回空结构，避免 PHP 报错
        print(json.dumps({"pages": {}, "error": str(e)}))

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"pages": {}, "error": "No file path provided"}))
    else:
        extract_full_text(sys.argv[1])