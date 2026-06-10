<?php
/*
  职球圈全量中转 PHP 统一矩阵（香港服务器直连版）
  使用方法：
  - 获取 M3U 订阅：http://你的香港服务器IP或域名/live.php?action=m3u
  - 获取 TXT 订阅：http://你的香港服务器IP或域名/live.php?action=txt
*/

define('BROWSER_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

// ==========================================
// 1. 核心网络请求函数（使用香港本地网络直连）
// ==========================================
function http_request($url, $method = 'GET', $body = null, $custom_headers = [], $is_ts_stream = false) {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => !$is_ts_stream,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => $is_ts_stream ? 0 : 15, // 视频流不设超时
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

    // 针对 TS 视频切片进行流式泵送：服务器边下载边吐给国内播放器，不占内存
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
    curl_close($ch);

    return [$response];
}

// ==========================================
// 2. 业务路由核心
// ==========================================
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'm3u':
        case 'txt':
            // 1. 抓取赛事列表
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

            // 动态获取当前脚本的绝对 URL
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $current_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . explode('?', $_SERVER['REQUEST_URI'])[0];

            // 2. 输出订阅格式
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

        case 'play':
            $id = $_GET['id'] ?? '';
            if (empty($id)) die("缺少 ID");

            // 向上游动态解析接口索要真实地址（此时发出请求的是香港 IP，CF 不会拦截）
            list($api_res) = http_request("https://data.stnye.cc/data/stream.php", "POST", "id=" . urlencode($id), [
                'Content-Type: application/x-www-form-urlencoded'
            ]);
            $res_arr = json_decode($api_res, true);

            if (($res_arr['status'] ?? '') !== 'success' || empty($res_arr['content'])) {
                http_response_code(502);
                die("上游解析流失败");
            }

            $real_m3u8_url = str_replace('\/', '/', $res_arr['content']);
            list($m3u8_content) = http_request($real_m3u8_url);

            // 重写 M3U8 文本，把里面的 TS 切片全部强制代理回你的香港 PHP 接口
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $m3u8_content));
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $current_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . explode('?', $_SERVER['REQUEST_URI'])[0];

            header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: no-store');

            foreach ($lines as $line) {
                $trim_line = trim($line);
                if (empty($trim_line)) continue;
                if (strpos($trim_line, '#') === 0) {
                    echo $line . "\n";
                } else {
                    // 补全 TS 的绝对路径，并套上当前的 PHP 代理壳子
                    $ts_absolute_url = (strpos($trim_line, 'http') === 0) ? $trim_line : dirname($real_m3u8_url) . '/' . $trim_line;
                    echo $current_url . "?action=ts&tsurl=" . urlencode($ts_absolute_url) . "\n";
                }
            }
            break;

        case 'ts':
            $ts_url = $_GET['tsurl'] ?? '';
            if (empty($ts_url)) die("缺少视频切片参数");

            header('Content-Type: video/MP2T');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: public, max-age=3600');

            // 关键：香港服务器代替国内播放器去下载视频流，并实时喂给国内的播放器
            http_request($ts_url, 'GET', null, [
                'Referer: https://elive.mayizhibo.net/'
            ], true);
            break;

        default:
            http_response_code(404);
            echo "IPTV 代理矩阵已就绪。请使用 ?action=m3u 或 ?action=txt 获取订阅。";
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "发生错误: " . $e->getMessage();
}
