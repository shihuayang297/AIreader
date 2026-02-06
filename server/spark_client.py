# spark_client.py
import _thread as thread
import base64
import datetime
import hashlib
import hmac
import json
import time
import ssl
from urllib.parse import urlparse, urlencode
from datetime import datetime
from time import mktime
from typing import List, Optional
import websocket  # pip install websocket-client

# 引入配置
from config import SPARK_APPID, SPARK_API_SECRET, SPARK_API_KEY, SPARK_URL, SPARK_DOMAIN

# 兼容性导入
try:
    from langchain_core.language_models.llms import LLM
except ImportError:
    try:
        from langchain.llms.base import LLM
    except ImportError:
        from langchain.llms import LLM

class SparkLLM(LLM):
    """
    自定义的 LangChain 包装器，用于连接星火 WebSocket 接口
    """
    response_content: str = ""  # 声明类属性以兼容 Pydantic

    @property
    def _llm_type(self) -> str:
        return "spark"

    def _call(self, prompt: str, stop: Optional[List[str]] = None) -> str:
        self.response_content = ""
        wsUrl = self.create_url()
        ws = websocket.WebSocketApp(
            wsUrl, 
            on_message=self.on_message, 
            on_error=self.on_error, 
            on_close=self.on_close, 
            on_open=self.on_open
        )
        ws.prompt = prompt
        ws.run_forever(sslopt={"cert_reqs": ssl.CERT_NONE})
        return self.response_content

    def create_url(self):
        u = urlparse(SPARK_URL)
        host = u.hostname
        path = u.path
        now = datetime.now()
        date = mktime(now.timetuple())
        date_str = time.strftime("%a, %d %b %Y %H:%M:%S GMT", time.gmtime(date))
        
        signature_origin = "host: {}\ndate: {}\nGET {} HTTP/1.1".format(host, date_str, path)
        signature_sha = hmac.new(SPARK_API_SECRET.encode('utf-8'), signature_origin.encode('utf-8'), digestmod=hashlib.sha256).digest()
        signature_sha_base64 = base64.b64encode(signature_sha).decode(encoding='utf-8')
        
        authorization_origin = 'api_key="{}", algorithm="hmac-sha256", headers="host date request-line", signature="{}"'.format(SPARK_API_KEY, signature_sha_base64)
        authorization = base64.b64encode(authorization_origin.encode('utf-8')).decode(encoding='utf-8')
        
        v = {
            "authorization": authorization,
            "date": date_str,
            "host": host
        }
        return SPARK_URL + '?' + urlencode(v)

    def on_error(self, ws, error):
        print("Spark Error:", error)

    def on_close(self, ws, one, two):
        pass

    def on_open(self, ws):
        thread.start_new_thread(self.run, (ws,))

    def run(self, ws, *args):
        data = json.dumps(self.gen_params(ws.prompt))
        ws.send(data)

    def on_message(self, ws, message):
        data = json.loads(message)
        code = data['header']['code']
        if code != 0:
            print(f'请求错误: {code}, {data}')
            self.response_content = f"Error Code {code}: {data['header']['message']}"
            ws.close()
        else:
            choices = data["payload"]["choices"]
            status = choices["status"]
            content = choices["text"][0]["content"]
            self.response_content += content
            if status == 2:
                ws.close()

    def gen_params(self, prompt):
        return {
            "header": {"app_id": SPARK_APPID, "uid": "1234"},
            "parameter": {
                "chat": {
                    "domain": SPARK_DOMAIN,
                    "temperature": 0.5,
                    "max_tokens": 2048
                }
            },
            "payload": {"message": {"text": [{"role": "user", "content": prompt}]}}
        }