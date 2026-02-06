<?php
defined('MOODLE_INTERNAL') || die();

class SparkHelper {
    public static function chat($config, $system_prompt, $user_content) {
        $Url = $config['url']; 
        $APPID = $config['app_id']; 
        $APIKey = $config['api_key']; 
        $APISecret = $config['api_secret']; 
        $Domain = $config['domain'];

        $host = parse_url($Url, PHP_URL_HOST); 
        $path = parse_url($Url, PHP_URL_PATH); 
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        
        // 鉴权签名逻辑
        $signature_origin = "host: $host\ndate: $date\nGET $path HTTP/1.1";
        $signature_sha = hash_hmac('sha256', $signature_origin, $APISecret, true);
        $signature = base64_encode($signature_sha);
        $authorization_origin = "api_key=\"$APIKey\", algorithm=\"hmac-sha256\", headers=\"host date request-line\", signature=\"$signature\"";
        $authorization = base64_encode($authorization_origin);
        
        $finalUrl = $Url . '?authorization=' . urlencode($authorization) . '&date=' . urlencode($date) . '&host=' . urlencode($host);

        // WebSocket 连接
        $contextOptions = [ 'ssl' => [ 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host ] ];
        $context = stream_context_create($contextOptions);
        $sock = stream_socket_client("ssl://$host:443", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        
        if (!$sock) return "（网络连接失败：无法连接到 AI 服务器，请检查服务器网络设置。）";

        // 握手
        $pathWithQuery = $path . '?' . parse_url($finalUrl, PHP_URL_QUERY);
        $key = base64_encode(openssl_random_pseudo_bytes(16));
        $head = "GET $pathWithQuery HTTP/1.1\r\nHost: $host\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n";
        fwrite($sock, $head);
        $header = fread($sock, 4096);
        
        if (strpos($header, ' 101 ') === false) { 
            fclose($sock); 
            return "（鉴权失败：请检查后台 API Key 配置是否正确。）"; 
        }
        
        // 发送数据
        $data = [ 
            "header" => ["app_id" => $APPID], 
            "parameter" => ["chat" => ["domain" => $Domain, "temperature" => 0.5, "max_tokens" => 4096]], 
            "payload" => ["message" => ["text" => [ 
                ["role" => "system", "content" => $system_prompt], 
                ["role" => "user", "content" => $user_content] 
            ]]] 
        ];
        
        self::sendFrame($sock, json_encode($data));
        
        // 接收响应
        $full_response = "";
        $start_time = time();
        while (!feof($sock)) {
            if (time() - $start_time > 30) break; // 30秒超时
            $frame = self::readFrame($sock);
            if ($frame === null) break;
            $json = json_decode($frame, true);
            if (!$json) continue;
            
            if (isset($json['header']['code']) && $json['header']['code'] != 0) { 
                $full_response .= " (API Error: {$json['header']['message']})"; 
                break; 
            }
            if (isset($json['payload']['choices']['text'])) { 
                foreach ($json['payload']['choices']['text'] as $text) { 
                    $full_response .= $text['content']; 
                } 
            }
            if (isset($json['header']['status']) && $json['header']['status'] == 2) break; // 完成
        }
        fclose($sock);
        return $full_response ?: "（智能体正在思考，但未返回内容，请重试。）";
    }
    
    private static function sendFrame($sock, $data) { $len = strlen($data); $head = chr(129); if ($len <= 125) { $head .= chr($len | 128); } elseif ($len <= 65535) { $head .= chr(126 | 128) . pack('n', $len); } else { $head .= chr(127 | 128) . pack('J', $len); } $mask = openssl_random_pseudo_bytes(4); $masked_data = ''; for ($i = 0; $i < $len; $i++) { $masked_data .= $data[$i] ^ $mask[$i % 4]; } fwrite($sock, $head . $mask . $masked_data); }
    private static function readFrame($sock) { $head = fread($sock, 2); if (strlen($head) < 2) return null; $payload_len = ord($head[1]) & 127; if ($payload_len == 126) { $head = fread($sock, 2); $payload_len = unpack('n', $head)[1]; } elseif ($payload_len == 127) { $head = fread($sock, 8); $payload_len = unpack('J', $head)[1]; } if ($payload_len > 0) { return fread($sock, $payload_len); } return ""; }
}