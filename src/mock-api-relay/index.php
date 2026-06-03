<?php

if (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'Off');
ini_set('zlib.output_compression', 'Off');
ini_set('implicit_flush', 1);
set_time_limit(0);

$configPath = __DIR__ . '/config.json';
if (!file_exists($configPath)) {
    $configPath = __DIR__ . '/config.example.json';
}
$config = json_decode(file_get_contents($configPath), true) ?: [];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$body = file_get_contents('php://input');
$bodyData = json_decode($body, true) ?: [];
$requestId = date('Ymd_His') . '_' . substr(uniqid(), -6);
$debug = !empty($config['debug']);

if ($method === 'GET' && preg_match('#^/(health|status)$#', $path)) {
    sendJson(200, [
        'status' => 'ok',
        'mode' => 'mock',
        'time' => date('c'),
        'rules' => count($config['rules'] ?? []),
    ]);
}

$headers = collectHeaders();
logRequest($debug, $requestId, $method, $path, $headers, $bodyData);

$rule = findRule($config['rules'] ?? [], $method, $path, $bodyData, $headers);
if (!$rule) {
    $response = [
        'type' => 'error',
        'error' => [
            'type' => 'mock_rule_not_found',
            'message' => 'No mock rule matched this request.',
        ],
    ];
    logResponse($debug, $requestId, 'mock_rule_not_found', 404, $response);
    sendJson(404, $response);
}

$responseConfig = $rule['response'] ?? [];
$format = $responseConfig['format'] ?? inferFormat($path);
$stream = resolveStream($responseConfig['stream'] ?? 'auto', $bodyData);
$status = (int)($responseConfig['status'] ?? 200);

logEvent($debug, 'MATCH', [
    'request_id' => $requestId,
    'rule' => $rule['name'] ?? null,
    'format' => $format,
    'stream' => $stream,
]);

if ($stream) {
    sendStream($format, $status, $responseConfig, $bodyData, $requestId, $debug, $rule['name'] ?? null);
}

$response = buildResponse($format, $responseConfig, $bodyData);
logResponse($debug, $requestId, $rule['name'] ?? null, $status, $response);
sendJson($status, $response);

function collectHeaders() {
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }
    }
    if (!empty($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
    }
    return $headers;
}

function findRule($rules, $method, $path, $bodyData, $headers) {
    foreach ($rules as $rule) {
        if (matchesRule($rule['match'] ?? [], $method, $path, $bodyData, $headers)) {
            return $rule;
        }
    }
    return null;
}

function matchesRule($match, $method, $path, $bodyData, $headers) {
    if (!empty($match['method']) && strtoupper($match['method']) !== strtoupper($method)) return false;
    if (!empty($match['path']) && $match['path'] !== $path) return false;
    if (!empty($match['provider']) && strtolower($match['provider']) !== inferProvider($path, $headers)) return false;
    if (!empty($match['model']) && ($bodyData['model'] ?? null) !== $match['model']) return false;
    if (!empty($match['apiKey'])) {
        $apiKey = $headers['x-api-key'] ?? $headers['authorization'] ?? '';
        $apiKey = preg_replace('/^Bearer\s+/i', '', $apiKey);
        if ($apiKey !== $match['apiKey']) return false;
    }
    return true;
}

function inferProvider($path, $headers) {
    if (isset($headers['anthropic-version'])) return 'anthropic';
    if ($path === '/v1/messages') return 'anthropic';
    return 'openai';
}

function inferFormat($path) {
    if ($path === '/v1/messages') return 'anthropic_messages';
    if ($path === '/v1/chat/completions') return 'openai_chat';
    return 'openai_responses';
}

function resolveStream($streamConfig, $bodyData) {
    if ($streamConfig === 'auto') return !empty($bodyData['stream']);
    return (bool)$streamConfig;
}

function buildResponse($format, $responseConfig, $bodyData) {
    if ($format === 'raw_json') {
        return $responseConfig['json'] ?? new stdClass();
    }
    if ($format === 'anthropic_messages') {
        return buildAnthropicResponse($responseConfig, $bodyData);
    }
    if ($format === 'openai_chat') {
        return buildOpenAIChatResponse($responseConfig, $bodyData);
    }
    return buildOpenAIResponsesResponse($responseConfig, $bodyData);
}

function buildOpenAIResponsesResponse($responseConfig, $bodyData) {
    $id = 'resp_' . bin2hex(random_bytes(8));
    $output = [];
    foreach (normalizeImages($responseConfig) as $image) {
        if (($image['type'] ?? '') === 'base64') {
            $output[] = [
                'id' => 'ig_' . bin2hex(random_bytes(8)),
                'type' => 'image_generation_call',
                'status' => 'completed',
                'result' => $image['data'] ?? '',
                'output_format' => mediaTypeToFormat($image['media_type'] ?? 'image/png'),
                'size' => $image['size'] ?? null,
            ];
        }
    }
    $text = composeText($responseConfig);
    if ($text !== '') {
        $output[] = [
            'id' => 'msg_' . bin2hex(random_bytes(8)),
            'type' => 'message',
            'status' => 'completed',
            'role' => 'assistant',
            'content' => [[
                'type' => 'output_text',
                'text' => $text,
                'annotations' => [],
            ]],
        ];
    }
    return [
        'id' => $id,
        'object' => 'response',
        'created_at' => time(),
        'status' => 'completed',
        'model' => $bodyData['model'] ?? 'mock-model',
        'output' => $output,
        'usage' => buildOpenAIUsage($responseConfig),
    ];
}

function buildOpenAIChatResponse($responseConfig, $bodyData) {
    return [
        'id' => 'chatcmpl_' . bin2hex(random_bytes(8)),
        'object' => 'chat.completion',
        'created' => time(),
        'model' => $bodyData['model'] ?? 'mock-model',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => composeText($responseConfig),
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => buildOpenAIUsage($responseConfig),
    ];
}

function buildAnthropicResponse($responseConfig, $bodyData) {
    $content = [];
    $text = composeText($responseConfig);
    if ($text !== '') {
        $content[] = ['type' => 'text', 'text' => $text];
    }
    foreach (normalizeImages($responseConfig) as $image) {
        if (($image['type'] ?? '') === 'base64') {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image['media_type'] ?? 'image/png',
                    'data' => $image['data'] ?? '',
                ],
            ];
        }
    }
    return [
        'id' => 'msg_' . bin2hex(random_bytes(8)),
        'type' => 'message',
        'role' => 'assistant',
        'model' => $bodyData['model'] ?? 'mock-model',
        'content' => $content,
        'stop_reason' => 'end_turn',
        'stop_sequence' => null,
        'usage' => buildAnthropicUsage($responseConfig),
    ];
}

function sendStream($format, $status, $responseConfig, $bodyData, $requestId, $debug, $ruleName) {
    http_response_code($status);
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    if ($format === 'anthropic_messages') {
        streamAnthropic($responseConfig, $bodyData);
    } elseif ($format === 'openai_chat') {
        streamOpenAIChat($responseConfig, $bodyData);
    } else {
        streamOpenAIResponses($responseConfig, $bodyData);
    }
    logResponse($debug, $requestId, $ruleName, $status, [
        'stream' => true,
        'format' => $format,
        'text' => composeText($responseConfig),
        'images' => normalizeImages($responseConfig),
    ]);
    exit;
}

function streamOpenAIResponses($responseConfig, $bodyData) {
    $responseId = 'resp_' . bin2hex(random_bytes(8));
    sse('response.created', ['type' => 'response.created', 'response' => ['id' => $responseId, 'status' => 'in_progress']]);
    foreach (normalizeImages($responseConfig) as $image) {
        if (($image['type'] ?? '') === 'base64') {
            $item = [
                'id' => 'ig_' . bin2hex(random_bytes(8)),
                'type' => 'image_generation_call',
                'status' => 'completed',
                'result' => $image['data'] ?? '',
                'output_format' => mediaTypeToFormat($image['media_type'] ?? 'image/png'),
                'size' => $image['size'] ?? null,
            ];
            sse('response.output_item.done', ['type' => 'response.output_item.done', 'item' => $item, 'output_index' => 0]);
        }
    }
    $text = composeText($responseConfig);
    if ($text !== '') {
        sse('response.output_item.added', ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'role' => 'assistant'], 'output_index' => 0]);
        sse('response.content_part.added', ['type' => 'response.content_part.added', 'part' => ['type' => 'output_text', 'text' => ''], 'content_index' => 0]);
        foreach (splitText($text) as $delta) {
            sse('response.output_text.delta', ['type' => 'response.output_text.delta', 'delta' => $delta, 'output_index' => 0, 'content_index' => 0]);
        }
        sse('response.output_text.done', ['type' => 'response.output_text.done', 'text' => $text, 'output_index' => 0, 'content_index' => 0]);
    }
    sse('response.completed', ['type' => 'response.completed', 'response' => ['id' => $responseId, 'status' => 'completed']]);
}

function streamOpenAIChat($responseConfig, $bodyData) {
    $id = 'chatcmpl_' . bin2hex(random_bytes(8));
    foreach (splitText(composeText($responseConfig)) as $delta) {
        sse(null, [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $bodyData['model'] ?? 'mock-model',
            'choices' => [['index' => 0, 'delta' => ['content' => $delta], 'finish_reason' => null]],
        ]);
    }
    sse(null, [
        'id' => $id,
        'object' => 'chat.completion.chunk',
        'created' => time(),
        'model' => $bodyData['model'] ?? 'mock-model',
        'choices' => [['index' => 0, 'delta' => new stdClass(), 'finish_reason' => 'stop']],
    ]);
    echo "data: [DONE]\n\n";
    flush();
}

function streamAnthropic($responseConfig, $bodyData) {
    $messageId = 'msg_' . bin2hex(random_bytes(8));
    sse('message_start', ['type' => 'message_start', 'message' => ['id' => $messageId, 'type' => 'message', 'role' => 'assistant', 'model' => $bodyData['model'] ?? 'mock-model', 'content' => [], 'stop_reason' => null]]);
    $index = 0;
    $text = composeText($responseConfig);
    if ($text !== '') {
        sse('content_block_start', ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'text', 'text' => '']]);
        foreach (splitText($text) as $delta) {
            sse('content_block_delta', ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => $delta]]);
        }
        sse('content_block_stop', ['type' => 'content_block_stop', 'index' => $index]);
        $index++;
    }
    foreach (normalizeImages($responseConfig) as $image) {
        if (($image['type'] ?? '') === 'base64') {
            sse('content_block_start', ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['media_type'] ?? 'image/png', 'data' => $image['data'] ?? '']]]);
            sse('content_block_stop', ['type' => 'content_block_stop', 'index' => $index]);
            $index++;
        }
    }
    sse('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => outputTokens($responseConfig)]]);
    sse('message_stop', ['type' => 'message_stop']);
}

function composeText($responseConfig, $includeUrls = true) {
    $text = (string)($responseConfig['text'] ?? '');
    return $includeUrls ? appendUrls($text, $responseConfig) : $text;
}

function appendUrls($text, $responseConfig) {
    $urls = $responseConfig['urls'] ?? [];
    foreach (normalizeImages($responseConfig) as $image) {
        if (($image['type'] ?? '') === 'url' && !empty($image['url'])) $urls[] = $image['url'];
    }
    if (!$urls) return $text;
    $suffix = implode("\n", array_values($urls));
    return $text === '' ? $suffix : $text . "\n" . $suffix;
}

function normalizeImages($responseConfig) {
    return is_array($responseConfig['images'] ?? null) ? $responseConfig['images'] : [];
}

function splitText($text) {
    if ($text === '') return [];
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    $chunks = [];
    for ($i = 0; $i < $length; $i += 4) {
        $chunks[] = function_exists('mb_substr') ? mb_substr($text, $i, 4, 'UTF-8') : substr($text, $i, 4);
    }
    return $chunks;
}

function sse($event, $data) {
    if ($event) echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
}

function sendJson($status, $data) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function buildOpenAIUsage($responseConfig) {
    $usage = $responseConfig['usage'] ?? [];
    return [
        'input_tokens' => (int)($usage['input_tokens'] ?? 0),
        'output_tokens' => (int)($usage['output_tokens'] ?? outputTokens($responseConfig)),
        'total_tokens' => (int)($usage['total_tokens'] ?? (($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? outputTokens($responseConfig)))),
    ];
}

function buildAnthropicUsage($responseConfig) {
    $usage = $responseConfig['usage'] ?? [];
    return [
        'input_tokens' => (int)($usage['input_tokens'] ?? 0),
        'output_tokens' => (int)($usage['output_tokens'] ?? outputTokens($responseConfig)),
    ];
}

function outputTokens($responseConfig) {
    $text = composeText($responseConfig);
    return max(1, (int)ceil((function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) / 4));
}

function mediaTypeToFormat($mediaType) {
    $parts = explode('/', $mediaType);
    return $parts[1] ?? 'png';
}

function logEvent($debug, $event, $data = []) {
    if (!$debug) return;
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/mock.log', date('Y-m-d H:i:s') . ' ' . $event . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

function logRequest($debug, $requestId, $method, $path, $headers, $bodyData) {
    if (!$debug) return;
    $dir = __DIR__ . '/logs/requests';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/' . $requestId . '.json', json_encode([
        'request_id' => $requestId,
        'timestamp' => date('c'),
        'method' => $method,
        'path' => $path,
        'headers' => $headers,
        'body' => $bodyData,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function logResponse($debug, $requestId, $ruleName, $status, $response) {
    if (!$debug) return;
    $dir = __DIR__ . '/logs/responses';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/' . $requestId . '.json', json_encode([
        'request_id' => $requestId,
        'timestamp' => date('c'),
        'target' => 'mock',
        'rule' => $ruleName,
        'status' => $status,
        'response' => $response,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}
