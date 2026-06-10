<?php
/*
  职球圈全量中转 PHP 统一矩阵（云端部署版）
  - 支持 HTTPS 自动识别 (适配 Render/Vercel)
  - 自动构建 M3U/TXT 订阅
  - TS 流式中转代理
*/

define('BROWSER_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

// ==========================================
// 1. 工具函数：获取当前的基础 URL（处理 HTTPS 协议）
// ==========================================
function get_base_url() {
    $protocol = 'http';
    // 识别本地 HTTPS 或负载均衡器转发的 HTTPS 标头
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
        $protocol = 'https';
    }
    $host = $_SERVER['HTTP_HOST'];
    $script = explode('?', $_SERVER['REQUEST_URI'])[0];
    return $protocol . "://" . $host . $script;
}

// ==========================================
// 2. 核心网络请求函数
// ==========================================
function http_request($url, $method = 'GET', $body = null, $custom_headers = [], $is_ts_stream = false) {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => !$is_ts_stream,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $is_ts_stream ? 0 : 20, // 视频流不设超时
        CURLOPT_USERAGENT      => BROWSER_UA,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $body;
    }

    if (!empty($custom_headers)) {
        $options[CURLOPT_HTTPHEADER] = $custom_headers;
    }

    // 针对 TS 视频切片进行流式泵送
    if ($is_ts_stream) {
        $options[CURLOPT_WRITEFUNCTION] = function($ch, $data) {
            echo $data;
            if (ob_get_level() > 0) ob_flush();
            flush();
            return strlen($data);
        };
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    return [$response, $error];
}

// ==========================================
// 3. 业务逻辑路由
// ==========================================
$action = $_GET['action'] ?? '';
$current_url = get_base_url();

try {
    switch ($action) {
        // --- 获取订阅列表 ---
        case 'm3u':
        case 'txt':
            list($json_data) = http_request("https://api.sportlive.cc/data/events.json");
            $events = json_decode($json_data, true);
            $streams = [];

            if (isset($events['events']) && is_array($events['events'])) {
                foreach ($events['events'] as $ev) {
                    if (isset($ev['channels']) && is_array($ev['channels'])) {
                        foreach ($ev['channels'] as $ch) {
                            if (($ch['islive'] ?? 0) === 1) {
                                $streams[] = [
                                    'id'    => $ch['id'],
                                    'title' => str_replace(' ', '_', $ev['competition'] . '_' . ($ev['title'] ?? '未知赛事') . '_' . ($ch['islg'] ?? '原音'))
                                ];
                            }
                        }
                    }
                }
            }

            if ($action === 'm3u') {
                header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
                echo "#EXTM3U\n";
                foreach ($streams as $s) {
                    echo "#EXTINF:-1 tvg-name=\"{$s['title']}\" group-title=\"职球圈\",{$s['title']}\n{$current_url}?action=play&id={$s['id']}\n";
                }
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                echo "职球圈,#genre#\n";
                foreach ($streams as $s) {
                    echo "{$s['title']},{$current_url}?action=play&id={$s['id']}\n";
                }
            }
            break;

        // --- 播放解析重定向 ---
        case 'play':
            $id = $_GET['id'] ?? '';
            if (empty($id)) die("Missing ID");

            // 请求上游接口获取真实 m3u8 地址
            list($api_res) = http_request("https://data.stnye.cc/data/stream.php", "POST", "id=" . urlencode($id), [
                'Content-Type: application/x-www-form-urlencoded'
            ]);
            $res_arr = json_decode($api_res, true);

            if (($res_arr['status'] ?? '') !== 'success' || empty($res_arr['content'])) {
                http_response_code(502);
                die("Upstream parsing failed");
            }

            $real_m3u8_url = str_replace('\/', '/', $res_arr['content']);
            list($m3u8_content) = http_request($real_m3u8_url);

            // 重写 m3u8 内容
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $m3u8_content));
            
            header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: no-store');

            foreach ($lines as $line) {
                $trim_line = trim($line);
                if (empty($trim_line)) continue;
                if (strpos($trim_line, '#') === 0) {
                    echo $line . "\n";
                } else {
                    // 补全 TS 路径并代理
                    $ts_absolute_url = (strpos($trim_line, 'http') === 0) ? $trim_line : dirname($real_m3u8_url) . '/' . $trim_line;
                    echo $current_url . "?action=ts&tsurl=" . urlencode($ts_absolute_url) . "\n";
                }
            }
            break;

        // --- TS 切片代理泵送 ---
        case 'ts':
            $ts_url = $_GET['tsurl'] ?? '';
            if (empty($ts_url)) die("Missing TS URL");

            header('Content-Type: video/MP2T');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: public, max-age=3600');

            // 实时流式转发
            http_request($ts_url, 'GET', null, [
                'Referer: https://elive.mayizhibo.net/'
            ], true);
            break;

        default:
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
            echo "<h2>IPTV 代理矩阵已就绪</h2>";
            echo "<li>M3U 订阅: <a href='{$current_url}?action=m3u'>{$current_url}?action=m3u</a></li>";
            echo "<li>TXT 订阅: <a href='{$current_url}?action=txt'>{$current_url}?action=txt</a></li>";
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage();
}
