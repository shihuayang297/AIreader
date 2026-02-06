<?php
define('AJAX_SCRIPT', true);
require('../../config.php');

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');

echo "<h1>🔥 星火大模型连接测试 (SNI增强版) 🔥</h1>";

// ==========================================
// 1. 配置信息 (强制去除空格)
// ==========================================
$APPID = trim('0d0ffb4b');
$APISecret = trim('YTU4OGUxZTMxMjU4ZjEwZDk4YzI4YTlm');
$APIKey = trim('084ee0c577b8db253458a63525f87e11');
// 您的截图明确指出科研模型使用 kjwx
$Domain = 'kjwx'; 
$Url = 'wss://spark-openapi-n.cn-huabei-1.xf-yun.com/v1.1/chat_kjwx';

echo "<p>目标 URL: <strong>$Url</strong></p>";

// ==========================================
// 2. 生成鉴权 (手动构建，防止编码问题)
// ==========================================
$host = parse_url($Url, PHP_URL_HOST);
$path = parse_url($Url, PHP_URL_PATH);
// 强制使用 GMT 时间
$date = gmdate('D, d M Y H:i:s') . ' GMT';

// 打印原始签名串供检查
$signature_origin = "host: $host\ndate: $date\nGET $path HTTP/1.1";
echo "<div style='background:#eee; padding:5px; font-size:12px;'><strong>待加密字符串 (Signature Origin):</strong><br><pre>$signature_origin</pre></div>";

$signature_sha = hash_hmac('sha256', $signature_origin, $APISecret, true);
$signature = base64_encode($signature_sha);

$authorization_origin = "api_key=\"$APIKey\", algorithm=\"hmac-sha256\", headers=\"host date request-line\", signature=\"$signature\"";
$authorization = base64_encode($authorization_origin);

// 手动拼接 URL，确保编码正确
$finalUrl = $Url . '?authorization=' . urlencode($authorization) . '&date=' . urlencode($date) . '&host=' . urlencode($host);

// ==========================================
// 3. 建立 Socket 连接 (关键：开启 SNI)
// ==========================================
// 许多云服务器需要 SNI 才能正确连接到具体的子域名
$contextOptions = [
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'SNI_enabled' => true, // 🌟 开启 SNI
        'peer_name' => $host   // 🌟 指定域名
    ]
];
$context = stream_context_create($contextOptions);

$sock = stream_socket_client("ssl://$host:443", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);

if (!$sock) {
    die("<h2 style='color:red'>❌ Socket 连接失败</h2><p>错误代码: $errno <br> 错误信息: $errstr</p>");
}
echo "<p style='color:green'>✅ Socket TCP 连接成功 (SSL/SNI)</p>";

// ==========================================
// 4. WebSocket 握手
// ==========================================
$key = base64_encode(openssl_random_pseudo_bytes(16));
// 这里的 GET 路径必须包含 Query 参数
$pathWithQuery = $path . '?' . parse_url($finalUrl, PHP_URL_QUERY);

$head = "GET $pathWithQuery HTTP/1.1\r\n";
$head .= "Host: $host\r\n";
$head .= "Upgrade: websocket\r\n";
$head .= "Connection: Upgrade\r\n";
$head .= "Sec-WebSocket-Key: $key\r\n";
$head .= "Sec-WebSocket-Version: 13\r\n\r\n";

fwrite($sock, $head);

// 读取响应
$header = fread($sock, 2048);
echo "<textarea style='width:100%; height:120px; background:#222; color:#0f0; padding:10px; font-family:monospace;'>$header</textarea>";

if (strpos($header, ' 101 ') === false) {
    echo "<h2 style='color:red'>❌ WebSocket 握手失败</h2>";
    if (strpos($header, '401')) echo "<p><strong>诊断：</strong> 401 依然存在。请检查服务器时间是否准确（误差不能超过5分钟）。当前服务器时间: " . date('Y-m-d H:i:s') . "</p>";
    die();
}
echo "<p style='color:green'>✅ 握手成功！(HTTP 101 Switching Protocols)</p>";

// ==========================================
// 5. 发送测试消息
// ==========================================
$payload = [
    "header" => ["app_id" => $APPID],
    "parameter" => [
        "chat" => [
            "domain" => $Domain,
            "temperature" => 0.5,
            "max_tokens" => 2048
        ]
    ],
    "payload" => [
        "message" => [
            "text" => [
                ["role" => "user", "content" => "你好，请回复“连接成功”"]
            ]
        ]
    ]
];
$json_payload = json_encode($payload);

// Frame 构建
$len = strlen($json_payload);
$head = chr(129);
if ($len <= 125) {
    $head .= chr($len | 128);
} elseif ($len <= 65535) {
    $head .= chr(126 | 128) . pack('n', $len);
} else {
    $head .= chr(127 | 128) . pack('J', $len);
}
$mask = openssl_random_pseudo_bytes(4);
$masked_data = '';
for ($i = 0; $i < $len; $i++) {
    $masked_data .= $json_payload[$i] ^ $mask[$i % 4];
}
fwrite($sock, $head . $mask . $masked_data);

echo "<p>📩 消息已发送，等待回复...</p>";

// ==========================================
// 6. 接收数据
// ==========================================
echo "<div style='background:#f9f9f9; border:1px solid #ddd; padding:10px;'>";
$start = time();
$buffer = "";
while (!feof($sock) && (time() - $start < 10)) {
    $head = fread($sock, 2);
    if (strlen($head) < 2) continue;

    $payload_len = ord($head[1]) & 127;
    if ($payload_len == 126) {
        $head = fread($sock, 2);
        $payload_len = unpack('n', $head)[1];
    } elseif ($payload_len == 127) {
        $head = fread($sock, 8);
        $payload_len = unpack('J', $head)[1];
    }

    if ($payload_len > 0) {
        $msg = fread($sock, $payload_len);
        $json = json_decode($msg, true);
        
        if ($json) {
            // 检查业务错误
            if (isset($json['header']['code']) && $json['header']['code'] != 0) {
                echo "<p style='color:red'>❌ API业务错误: " . $json['header']['message'] . " (Code: " . $json['header']['code'] . ")</p>";
            }
            // 提取内容
            if (isset($json['payload']['choices']['text'])) {
                foreach ($json['payload']['choices']['text'] as $t) {
                    $buffer .= $t['content'];
                }
            }
            // 结束标志
            if (isset($json['header']['status']) && $json['header']['status'] == 2) {
                break;
            }
        }
    }
}
fclose($sock);
echo "</div>";

if ($buffer) {
    echo "<h2 style='color:green'>🎉 最终回复:</h2><div style='font-size:18px; font-weight:bold;'>$buffer</div>";
} else {
    echo "<p style='color:gray'>未收到文本回复，请检查上方是否有业务错误。</p>";
}
?>