# ai-api-relay

[English](README.md) | **中文文档**

一个轻量 PHP 中继，部署在 AI 客户端与第三方 Anthropic API 代理商之间。实现多代理商自动故障转移，并修复客户端开箱即用时无法命中 Prompt Cache 的问题。

## 解决了什么问题

通过 OpenClaw 等客户端使用第三方 Anthropic API 代理商时，**Prompt Cache 完全失效**——每次请求都创建新缓存，命中次数为零，大量浪费重复上下文的费用。

**根因**：多数第三方代理商使用 `metadata.user_id` 做会话亲和路由（Sticky Routing）。没有这个字段时，请求被随机分发到多个后端 API Key，每个 Key 有独立的缓存命名空间，缓存只创建、从不命中。

Claude Code 能正常缓存是因为它自动携带了 `metadata.user_id`，而 OpenClaw 没有。

**本代理的修复方式**：对每个缺少 `metadata.user_id` 的请求，自动注入一个由客户端 IP 派生的稳定 `user_id`。

## 功能特性

- **自动故障转移** — 按优先级依次尝试代理商，连接失败或 5xx 时自动切换
- **熔断器** — 自动跳过连续失败的代理商，超时后自动恢复
- **Prompt Cache 修复** — 对配置了 `inject_user_id: true` 的代理商，自动注入 `metadata.user_id`，使代理商做缓存路由亲和
- **按代理商注入 Headers** — 可对每个代理商单独追加或覆盖任意 Header（如 Beta Flag）
- **按代理商模型替换** — 通过 `modelMap` 在转发前替换请求中的模型名称
- **按代理商路径映射** — 通过 `pathMap` 在转发前替换请求路径
- **按代理商请求体注入** — 通过 `bodyInject` 覆盖或注入任意请求体字段
- **按代理商推理开关** — 可对每个代理商强制开启或关闭 Extended Thinking
- **透明转发** — 支持任意路径、任意 HTTP 方法、SSE 流式和非流式响应
- **缓存命中日志** — 每次请求记录 `cache_creation_input_tokens` 和 `cache_read_input_tokens`
- **健康检查接口** — `GET /health` 或 `GET /status`
- **Debug 模式** — 完整的请求级 JSON 日志，含 Headers、Body、转发详情及上游响应体

## 部署

### 环境要求

- PHP 7.4+，需启用 `curl` 扩展
- Nginx 或 Apache，将请求路由到 `index.php`

### 安装

```bash
git clone https://github.com/Tonyhzk/ai-api-relay.git
cd ai-api-relay
cp config.example.json config.json
# 编辑 config.json，填入代理商地址和 Key
```

### Nginx 配置（推荐）

```nginx
server {
    listen 443 ssl;
    server_name your-proxy.example.com;

    root /path/to/ai-api-relay;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
        fastcgi_buffering off;
    }
}
```

## 配置说明

```json
{
  "auth_key": "sk-your-proxy-key",
  "connect_timeout": 10,
  "timeout": 300,
  "debug": false,
  "circuit_breaker": {
    "enabled": true,
    "threshold": 3,
    "timeout": 60
  },
  "providers": [
    {
      "name": "provider1",
      "enabled": true,
      "baseUrl": "https://api.provider1.com",
      "apiKey": "sk-provider1-key",
      "injectHeaders": {
        "anthropic-beta": "+prompt-caching-2024-07-31,extended-cache-ttl-2025-04-11"
      },
      "modelMap": {
        "claude-sonnet-4-6": "claude-haiku-4-5-20251001"
      },
      "pathMap": {
        "/v1/messages": "/claude"
      },
      "bodyInject": {
        "max_tokens": 8192,
        "temperature": 0.7
      },
      "thinking": false
    }
  ]
}
```

### `injectHeaders` 语法

| 值格式 | 行为 |
|--------|------|
| `"value"` | 完全替换该 Header |
| `"+value"` | 追加到已有 Header 末尾（逗号分隔） |

### `modelMap` — 按代理商模型替换

在转发前将请求体中的模型名替换为目标模型，适用于代理商不支持特定模型的场景。

```json
"modelMap": {
  "claude-opus-4-5": "claude-3-5-sonnet-20241022",
  "claude-sonnet-4-6": "claude-haiku-4-5-20251001"
}
```

### `thinking` — 按代理商推理开关

控制转发给该代理商的请求体中的 `thinking` 字段。

| 值 | 行为 |
|----|------|
| `false` | 强制移除 `thinking` 和 `temperature` 字段（适用于不支持推理的代理商） |
| `true` | 强制注入 `{"type":"enabled","budget_tokens":8000}` |
| `{"budget_tokens": N}` | 强制注入,自定义 token 额度 |

不配置此字段则透传原始请求，行为不变。

### `pathMap` — 按代理商路径映射

转发前将请求路径替换为目标路径，适用于使用非标准 API 路径的代理商。

```json
"pathMap": {
  "/v1/messages": "/claude"
}
```

未命中的路径原样转发。

### `bodyInject` — 按代理商请求体注入

转发前覆盖或注入请求体顶层字段，适用于强制限制或添加客户端未发送的默认值。

```json
"bodyInject": {
  "max_tokens": 8192,
  "temperature": 0.7,
  "system": "You are a helpful assistant."
}
```

`bodyInject` 中的字段始终覆盖客户端原始值。

### `circuit_breaker` — 熔断器

自动跳过连续失败的代理商，避免在无响应的节点上浪费等待时间。

| 字段 | 默认值 | 说明 |
|------|--------|------|
| `enabled` | `false` | 是否启用熔断器 |
| `threshold` | `3` | 触发熔断的连续失败次数 |
| `timeout` | `60` | 熔断后等待重试的秒数 |

状态持久化至 `logs/circuit.json`，代理商成功响应后自动重置。

### 为什么要注入 `prompt-caching-2024-07-31`？

OpenClaw 等客户端（使用 Anthropic JS SDK）默认不携带 Prompt Cache 相关的 Beta Flag。代理自动追加后，代理商才会激活缓存功能。

## 缓存修复原理

```
修复前（无代理）：
  请求 1 → 代理商（Key A）→ 创建缓存
  请求 2 → 代理商（Key B）→ 创建缓存（不同命名空间！）
  请求 3 → 代理商（Key C）→ 创建缓存（不同命名空间！）
  结果：缓存命中 0 次，每次全量计费

修复后（有代理注入 metadata.user_id）：
  请求 1 → 代理商（Key A，亲和）→ 创建缓存
  请求 2 → 代理商（Key A，亲和）→ 缓存命中 ✓
  请求 3 → 代理商（Key A，亲和）→ 缓存命中 ✓
  结果：重复上下文费用节省约 90%
```

代理在 provider 配置了 `inject_user_id: true` 时，用客户端 IP + Auth Key 的哈希生成稳定的 `user_id`：

```php
$stableUserId = 'proxy_' . hash('sha256', $clientIp . $authKey);
```

## 使用方式

将客户端的 Base URL 改为代理地址即可，其余配置不变：

```
Base URL:  https://your-proxy.example.com
API Key:   （config.json 中的 auth_key）
```

### OpenClaw 配置

在 `openclaw.json` 中修改 provider 的 `baseUrl`：

```json
{
  "models": {
    "providers": {
      "anthropic": {
        "baseUrl": "https://your-proxy.example.com",
        "apiKey": "sk-your-proxy-key"
      }
    }
  }
}
```

### Claude Code 配置

```bash
claude config set apiBaseUrl https://your-proxy.example.com
```

## 日志说明

在 config 中设置 `"debug": true` 后：

- `logs/proxy.log` — 每次请求一行，含缓存命中/未命中状态
- `logs/debug.log` — 详细转发信息
- `logs/requests/*.json` — 完整的请求快照（Headers、Body、转发 Headers）
- `logs/responses/*.json` — 完整的上游响应体（非流式）
- `logs/responses/*.txt` — 完整的上游 SSE 流（流式）

请求和响应文件共享同一个 ID，便于对照查看。

proxy.log 示例：

```
2026-03-03 20:22:57 STREAM_DONE {"provider":"ai580","code":200,"cache_usage":{"cache_creation_input_tokens":65,"cache_read_input_tokens":51566},"cache_status":"HIT(read=51566)"}
```

## 更新日志

查看 [CHANGELOG_CN.md](CHANGELOG_CN.md) 了解完整版本历史。

## License

[MIT](LICENSE)