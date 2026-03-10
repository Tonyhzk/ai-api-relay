# ai-api-relay

[English](README.md) | **中文文档**

一个轻量 PHP 透明代理。客户端在 URL 路径中指定目标 API 地址，代理透传 API Key、请求头和请求体，同时可选择性地应用全局变换（模型映射、推理开关、Header 注入等）。

## 工作原理

客户端将完整的目标 URL 编码到 relay 的路径中：

```
https://your-relay.example.com/https://api.anthropic.com/v1/messages
                               └──────────── 目标 URL ────────────┘
```

Relay 提取第一个 `/` 后面的所有内容，直接转发请求。

## 功能特性

- **透明转发** — API Key、请求头、请求体直接透传到目标
- **客户端指定目标** — 无需服务端配置 provider，客户端控制请求去向
- **全局模型替换** — 通过 `modelMap` 在转发前替换请求中的模型名称
- **全局推理开关** — 可强制开启或关闭 Extended Thinking
- **全局 Header 注入** — 通过 `injectHeaders` 追加或覆盖任意 Header（如 Beta Flag）
- **全局请求体注入** — 通过 `bodyInject` 覆盖或注入任意请求体字段
- **User ID 注入** — 自动注入 `metadata.user_id`，使代理商做缓存路由亲和
- **SSE 流式透传** — 实时透传流式响应
- **健康检查接口** — `GET /health` 或 `GET /status`
- **Debug 模式** — 完整的请求级 JSON 日志，含 Headers、Body 及上游响应

## 部署

### 环境要求

- PHP 7.4+，需启用 `curl` 扩展
- Nginx 或 Apache，将请求路由到 `index.php`

### 安装

```bash
git clone https://github.com/Tonyhzk/ai-api-relay.git
cd ai-api-relay
cp src/config.example.json src/config.json
# 按需编辑 config.json
```

### Nginx 配置（推荐）

```nginx
server {
    listen 443 ssl;
    server_name your-relay.example.com;

    root /path/to/ai-api-relay/src;

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
  "connect_timeout": 10,
  "timeout": 300,
  "debug": false,
  "inject_user_id": false,
  "modelMap": {
    "claude-opus-4-5": "claude-sonnet-4-5"
  },
  "thinking": true,
  "injectHeaders": {
    "anthropic-beta": "+prompt-caching-2024-07-31,extended-cache-ttl-2025-04-11"
  },
  "bodyInject": {
    "max_tokens": 8192
  }
}
```

| 字段 | 默认值 | 说明 |
|------|--------|------|
| `connect_timeout` | `10` | 连接超时（秒） |
| `timeout` | `300` | 请求超时（秒） |
| `debug` | `false` | 开启完整请求/响应日志 |
| `inject_user_id` | `false` | 自动注入由客户端 IP 派生的 `metadata.user_id` |
| `modelMap` | `{}` | 模型名称映射表 |
| `thinking` | _（不设置）_ | 推理开关（见下方） |
| `injectHeaders` | `{}` | 注入/追加的 Headers |
| `bodyInject` | `{}` | 注入/覆盖的请求体字段 |

### `modelMap` — 模型替换

转发前替换请求体中的模型名称。

```json
"modelMap": {
  "claude-opus-4-5": "claude-sonnet-4-5",
  "claude-sonnet-4-6": "claude-haiku-4-5-20251001"
}
```

### `thinking` — 推理开关

控制转发请求体中的 `thinking` 字段。

| 值 | 行为 |
|----|------|
| `false` | 强制移除 `thinking` 和 `temperature` 字段 |
| `true` | 强制注入 `{"type":"enabled","budget_tokens":8000}` |
| `{"budget_tokens": N}` | 强制注入，自定义 token 额度 |

不配置此字段则透传原始请求，不做修改。

### `injectHeaders` — Header 注入

| 值格式 | 行为 |
|--------|------|
| `"value"` | 完全替换该 Header |
| `"+value"` | 追加到已有 Header 末尾（逗号分隔） |

### `bodyInject` — 请求体注入

转发前覆盖或注入请求体顶层字段。

```json
"bodyInject": {
  "max_tokens": 8192,
  "temperature": 0.7
}
```

`bodyInject` 中的字段始终覆盖客户端原始值。

## 使用方式

将 relay 地址设为 Base URL，目标 API 地址嵌入路径中：

```
Base URL:  https://your-relay.example.com/https://api.anthropic.com
API Key:   sk-ant-xxxxx（你自己的 Key，原样透传到目标）
```

切换目标只需修改 Base URL：

| 目标 | Base URL |
|------|----------|
| Anthropic 官方 | `https://your-relay.example.com/https://api.anthropic.com` |
| 第三方代理商 | `https://your-relay.example.com/https://api.provider.com` |

### Claude Code 配置

```bash
claude config set apiBaseUrl https://your-relay.example.com/https://api.anthropic.com
```

### OpenClaw 配置

在 `openclaw.json` 中：

```json
{
  "models": {
    "providers": {
      "anthropic": {
        "baseUrl": "https://your-relay.example.com/https://api.provider.com",
        "apiKey": "sk-your-own-key"
      }
    }
  }
}
```

## 日志说明

在 config 中设置 `"debug": true` 后：

- `logs/proxy.log` — 每次请求一行
- `logs/debug.log` — 详细转发信息
- `logs/requests/*.json` — 完整的请求快照（Headers、Body）
- `logs/responses/*.json` — 完整的上游响应体（非流式）
- `logs/responses/*.txt` — 完整的上游 SSE 流（流式）

请求和响应文件共享同一个 ID，便于对照查看。

## 更新日志

查看 [CHANGELOG_CN.md](CHANGELOG_CN.md) 了解完整版本历史。

## License

[MIT](LICENSE)
