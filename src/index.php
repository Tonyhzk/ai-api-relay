<?php
/**
 * ai-api-relay - 透明代理
 *
 * 从 URL 路径提取目标地址，透传所有请求头和请求体。
 * 支持任意路径、任意 HTTP 方法、SSE 流式透传。
 *
 * 用法：https://relay域名/https://目标API地址/v1/messages
 */

// 禁用输出缓冲
if (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'Off');
ini_set('zlib.output_compression', 'Off');
ini_set('implicit_flush', 1);
set_time_limit(0);

// 加载配置
$configPath = __DIR__ . '/config.json';
$config = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];

// 解析请求
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// 健康检查
if ($method === 'GET' && preg_match('#^/(health|status)$#', $uri)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'mode' => 'passthrough', 'time' => date('c')]);
    exit;
}

// 从 URI 提取目标 URL：去掉开头的 /，剩余部分即为完整目标 URL
$targetUrl = substr($uri, 1);

// 验证目标 URL
if (!$targetUrl || !preg_match('#^https?://#i', $targetUrl)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Missing or invalid target URL. Usage: /https://api.example.com/path']]);
    exit;
}

// 读取请求体
$body = file_get_contents('php://input');
$bodyData = json_decode($body, false);
$isStreaming = !empty($bodyData->stream);

// 收集需要转发的请求头（透传所有相关 header）
$forwardHeaders = ['Content-Type: application/json'];
$headerKeys = [
    'HTTP_X_API_KEY'          => 'x-api-key',
    'HTTP_AUTHORIZATION'      => 'authorization',
    'HTTP_ANTHROPIC_VERSION'  => 'anthropic-version',
    'HTTP_ANTHROPIC_BETA'     => 'anthropic-beta',
];
foreach ($headerKeys as $serverKey => $headerName) {
    if (!empty($_SERVER[$serverKey])) {
        $forwardHeaders[] = $headerName . ': ' . $_SERVER[$serverKey];
    }
}
// 透传 Content-Type（如果客户端指定了非默认值）
if (!empty($_SERVER['CONTENT_TYPE'])) {
    $forwardHeaders[0] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
}

// 日志函数
function logEvent($msg, $data = []) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $entry = date('Y-m-d H:i:s') . ' ' . $msg;
    if ($data) $entry .= ' ' . json_encode($data, JSON_UNESCAPED_UNICODE);
    @file_put_contents($logDir . '/proxy.log', $entry . "\n", FILE_APPEND | LOCK_EX);
}

$debug = !empty($config['debug']);
function logDebug($msg, $data = []) {
    global $debug;
    if (!$debug) return;
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $entry = date('Y-m-d H:i:s') . ' [DEBUG] ' . $msg;
    if ($data) $entry .= "\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    @file_put_contents($logDir . '/debug.log', $entry . "\n\n", FILE_APPEND | LOCK_EX);
}

// Debug: 记录请求详情
$requestId = date('Ymd_His') . '_' . substr(uniqid(), -6);
if ($debug) {
    $reqLogDir = __DIR__ . '/logs/requests';
    if (!is_dir($reqLogDir)) @mkdir($reqLogDir, 0755, true);
    $reqLogFile = $reqLogDir . '/' . $requestId . '.json';

    $incomingHeaders = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $headerName = str_replace('_', '-', strtolower(substr($k, 5)));
            $incomingHeaders[$headerName] = $v;
        }
    }
    if (!empty($_SERVER['CONTENT_TYPE'])) {
        $incomingHeaders['content-type'] = $_SERVER['CONTENT_TYPE'];
    }

    $reqData = [
        'request_id' => $requestId,
        'timestamp' => date('c'),
        'method' => $method,
        'target_url' => $targetUrl,
        'stream' => $isStreaming,
        'headers' => $incomingHeaders,
        'body_length' => strlen($body),
        'body' => $bodyData,
    ];
    @file_put_contents($reqLogFile, json_encode($reqData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ── 请求体修改（全局配置）─────────────────────────────────────────────
$finalBody = $body;
$modifiedBodyData = null; // 惰性 clone，有修改时才创建

// 自动注入 metadata.user_id
if (!empty($config['inject_user_id']) && $bodyData && empty(($modifiedBodyData ?? $bodyData)->metadata->user_id)) {
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $clientIp = explode(',', $clientIp)[0];
    $stableUserId = 'proxy_' . hash('sha256', $clientIp);
    $modifiedBodyData = $modifiedBodyData ?? clone $bodyData;
    if (!isset($modifiedBodyData->metadata)) {
        $modifiedBodyData->metadata = new stdClass();
    }
    $modifiedBodyData->metadata->user_id = $stableUserId;
}

// 模型替换：modelMap 中匹配则替换 model 字段
if (!empty($config['modelMap']) && is_array($config['modelMap']) && $bodyData && isset($bodyData->model)) {
    $originalModel = $bodyData->model;
    if (isset($config['modelMap'][$originalModel])) {
        $mappedModel = $config['modelMap'][$originalModel];
        $modifiedBodyData = $modifiedBodyData ?? clone $bodyData;
        $modifiedBodyData->model = $mappedModel;
        logEvent("MODEL_MAP", ['from' => $originalModel, 'to' => $mappedModel]);
        logDebug("MODEL_MAP", ['from' => $originalModel, 'to' => $mappedModel]);
    }
}

// 推理开关：控制请求体中的 thinking 字段
//   false                    → 强制关闭（移除 thinking 和 temperature 字段）
//   true                     → 强制开启，默认 budget_tokens=8000
//   {"budget_tokens": N}     → 强制开启，自定义额度
if (isset($config['thinking']) && $bodyData) {
    $modifiedBodyData = $modifiedBodyData ?? clone $bodyData;
    if ($config['thinking'] === false) {
        unset($modifiedBodyData->thinking);
        unset($modifiedBodyData->temperature);
        logEvent("THINKING_OFF", []);
    } else {
        $budgetTokens = is_array($config['thinking']) && isset($config['thinking']['budget_tokens'])
            ? (int)$config['thinking']['budget_tokens']
            : 8000;
        $modifiedBodyData->thinking = (object)['type' => 'enabled', 'budget_tokens' => $budgetTokens];
        logEvent("THINKING_ON", ['budget_tokens' => $budgetTokens]);
    }
}

// 请求体字段注入：bodyInject 中的字段直接覆盖请求体对应字段
if (!empty($config['bodyInject']) && is_array($config['bodyInject']) && $bodyData) {
    $modifiedBodyData = $modifiedBodyData ?? clone $bodyData;
    foreach ($config['bodyInject'] as $field => $value) {
        $modifiedBodyData->$field = $value;
    }
    logEvent("BODY_INJECT", ['fields' => array_keys($config['bodyInject'])]);
}

// 有修改则统一序列化
if ($modifiedBodyData !== null) {
    $finalBody = json_encode($modifiedBodyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// 注入全局自定义 headers
if (!empty($config['injectHeaders']) && is_array($config['injectHeaders'])) {
    foreach ($config['injectHeaders'] as $hName => $hValue) {
        $hNameLower = strtolower($hName);
        if (str_starts_with($hValue, '+')) {
            $appendVal = substr($hValue, 1);
            $found = false;
            foreach ($forwardHeaders as &$h) {
                if (stripos($h, $hNameLower . ':') === 0 || stripos($h, $hName . ':') === 0) {
                    $h = rtrim($h, ', ') . ',' . $appendVal;
                    $found = true;
                    break;
                }
            }
            unset($h);
            if (!$found) {
                $forwardHeaders[] = $hName . ': ' . $appendVal;
            }
        } else {
            $replaced = false;
            foreach ($forwardHeaders as &$h) {
                if (stripos($h, $hNameLower . ':') === 0 || stripos($h, $hName . ':') === 0) {
                    $h = $hName . ': ' . $hValue;
                    $replaced = true;
                    break;
                }
            }
            unset($h);
            if (!$replaced) {
                $forwardHeaders[] = $hName . ': ' . $hValue;
            }
        }
    }
}
// ────────────────────────────────────────────────────────────────────────

logEvent("FORWARD", ['method' => $method, 'target' => $targetUrl, 'stream' => $isStreaming]);
logDebug("FORWARD_REQUEST", ['target' => $targetUrl, 'method' => $method, 'headers' => $forwardHeaders, 'body_length' => strlen($finalBody)]);

// 构建 curl 请求
$ch = curl_init($targetUrl);
$curlOpts = [
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $forwardHeaders,
    CURLOPT_CONNECTTIMEOUT => $config['connect_timeout'] ?? 10,
    CURLOPT_TIMEOUT        => $config['timeout'] ?? 300,
    CURLOPT_SSL_VERIFYPEER => true,
];
if ($finalBody !== '' && $finalBody !== false) {
    $curlOpts[CURLOPT_POSTFIELDS] = $finalBody;
}
curl_setopt_array($ch, $curlOpts);

if ($isStreaming) {
    // === 流式模式 ===
    $httpCode = 0;
    $headersSent = false;
    $upstreamContentType = 'application/octet-stream';
    $responseLog = '';

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$httpCode, &$upstreamContentType) {
        if (preg_match('/^HTTP\/\S+ (\d+)/', $header, $m)) {
            $httpCode = (int)$m[1];
        }
        if (preg_match('/^Content-Type:\s*(.+)/i', $header, $m)) {
            $upstreamContentType = trim($m[1]);
        }
        return strlen($header);
    });

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$httpCode, &$headersSent, &$upstreamContentType, $targetUrl, &$responseLog) {
        if (!$headersSent) {
            http_response_code($httpCode);
            header('Content-Type: ' . $upstreamContentType);
            if ($httpCode >= 200 && $httpCode < 300 && stripos($upstreamContentType, 'event-stream') !== false) {
                header('Cache-Control: no-cache');
                header('Connection: keep-alive');
            }
            header('X-Target: ' . parse_url($targetUrl, PHP_URL_HOST));
            $headersSent = true;
        }
        global $debug;
        if ($debug) {
            $responseLog .= $data;
        }
        echo $data;
        if (ob_get_level()) ob_flush();
        flush();
        return strlen($data);
    });

    curl_exec($ch);
    $curlErr = curl_errno($ch);
    $curlErrMsg = curl_error($ch);
    $finalCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    @curl_close($ch);

    if ($headersSent) {
        logEvent("STREAM_DONE", ['target' => $targetUrl, 'code' => $finalCode]);
        if ($debug && $responseLog !== '') {
            $resLogDir = __DIR__ . '/logs/responses';
            if (!is_dir($resLogDir)) @mkdir($resLogDir, 0755, true);
            @file_put_contents($resLogDir . '/' . $requestId . '.txt', $responseLog);
        }
        exit;
    }

    // 未输出任何数据就失败了
    $lastError = $curlErrMsg ?: "HTTP $finalCode";
    logEvent("STREAM_FAIL", ['target' => $targetUrl, 'error' => $lastError]);
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['type' => 'error', 'error' => ['type' => 'proxy_error', 'message' => "Target unreachable: $lastError"]]);
    exit;

} else {
    // === 非流式模式 ===
    $responseHeaders = [];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
        if (preg_match('/^Content-Type:\s*(.+)/i', $header, $m)) {
            $responseHeaders['content-type'] = trim($m[1]);
        }
        return strlen($header);
    });
    $response = curl_exec($ch);
    $curlErr = curl_errno($ch);
    $curlErrMsg = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    @curl_close($ch);

    if ($curlErr) {
        logEvent("FAIL", ['target' => $targetUrl, 'error' => $curlErrMsg]);
        http_response_code(502);
        header('Content-Type: application/json');
        echo json_encode(['type' => 'error', 'error' => ['type' => 'proxy_error', 'message' => "Target unreachable: $curlErrMsg"]]);
        exit;
    }

    // 原样返回（包括 4xx、5xx）
    http_response_code($httpCode);
    header('Content-Type: ' . ($responseHeaders['content-type'] ?? 'application/json'));
    header('X-Target: ' . parse_url($targetUrl, PHP_URL_HOST));
    logEvent("OK", ['target' => $targetUrl, 'code' => $httpCode]);

    if ($debug) {
        $resLogDir = __DIR__ . '/logs/responses';
        if (!is_dir($resLogDir)) @mkdir($resLogDir, 0755, true);
        $resData = [
            'request_id' => $requestId,
            'target' => $targetUrl,
            'code' => $httpCode,
            'response_length' => strlen($response),
            'response' => json_decode($response, true) ?? $response,
        ];
        @file_put_contents($resLogDir . '/' . $requestId . '.json', json_encode($resData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    echo $response;
    exit;
}
