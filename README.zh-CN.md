# ai-api-relay

[English](README.md) | **中文文档**

一个轻量 PHP 透明代理。支持三种方式指定目标 API：嵌入 API Key、编码在 URL 路径中、或通过配置默认值。代理透传请求头和请求体，同时可选择性地应用全局变换（模型映射、推理开关、Header 注入等）。

## 工作原理

Relay 按优先级解析目标 URL：

**1. API Key 前缀（推荐）** — 在 API Key 中嵌入目标 URL，用 `::` 分隔：

```
API Key:   https://api.provider.com/v1::sk-your-actual-key
Base URL:  https://your-relay.example.com
```

**2. URL 路径（旧方式）** — 将目标编码在 relay 的 URL 路径中：

```
https://your-relay.example.com/https://api.anthropic.com/v1/messages
                               └──────────── 目标 URL ────────────┘
```

**3. 配置默认值** — 在 config.json 中设置 `defaultTargetUrl`，然后直接使用 relay 地址：

```
Base URL:  https://your-relay.example.com
API Key:   sk-your-key
配置:      "defaultTargetUrl": "https://api.anthropic.com/v1"
```

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

参考配置文件：[`doc/nigix-origin.conf`](doc/nigix-origin.conf)。以下是本 relay 需要的关键配置：

```nginx
server {
    listen 80;
    server_name relayai.website.com;
    root /www/sites/relayai.website.com/index;

    # 关键：保留 URL 路径中的 ://（如 /https://api.example.com）
    merge_slashes off;

    # 禁止直接访问配置文件
    location ~ ^/config\.json$ {
        return 404;
    }

    # 所有请求路由到 index.php
    location / {
        try_files $uri /index.php$is_args$args;
    }

    # PHP 处理器，支持流式传输
    location ~ [^/]\.php(/|$) {
        fastcgi_pass 127.0.0.1:9000;
        include fastcgi-php.conf;
        include fastcgi_params;
        # ... 其他 fastcgi 参数 ...
        fastcgi_buffering off;
    }
}
```

| 指令 | 作用 |
|------|------|
| `merge_slashes off` | 防止 Nginx 将 URL 路径中的 `://` 合并为 `:/` |
| `location /` | Catch-all 路由，将所有请求转发到 `index.php` |
| `fastcgi_buffering off` | 支持 SSE 流式实时透传 |
| `location ~ ^/config\.json$` | 阻止直接访问配置文件 |

## 配置说明

```json
{
  "connect_timeout": 10,
  "timeout": 300,
  "debug": false,
  "inject_user_id": false,
  "defaultTargetUrl": "https://api.anthropic.com/v1",
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
| `defaultTargetUrl` | _（不设置）_ | 后备目标 URL，当 API Key 和路径均未指定目标时使用 |
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

### 方式一：API Key 编码（推荐）

在 API Key 中嵌入目标 URL，用 `::` 分隔：

```
Base URL:  https://your-relay.example.com
API Key:   https://api.anthropic.com/v1::sk-ant-xxxxx
```

切换目标只需修改 API Key 前缀：

| 目标 | API Key 格式 |
|------|-------------|
| Anthropic 官方 | `https://api.anthropic.com/v1::sk-ant-xxxxx` |
| 第三方代理商 | `https://api.provider.com/v1::sk-your-key` |

### 方式二：URL 路径编码（旧方式）

将目标编码在 relay 的 URL 路径中：

```
Base URL:  https://your-relay.example.com/https://api.anthropic.com
API Key:   sk-ant-xxxxx
```

### 方式三：配置默认值

在 config.json 中设置 `defaultTargetUrl`：

```json
{
  "defaultTargetUrl": "https://api.anthropic.com/v1"
}
```

然后直接使用 relay 地址：

```
Base URL:  https://your-relay.example.com
API Key:   sk-ant-xxxxx
```

### Claude Code 配置

```bash
# 方式一：API Key 编码
ANTHROPIC_API_KEY="https://api.anthropic.com/v1::sk-ant-xxxxx"
claude config set apiBaseUrl https://your-relay.example.com

# 方式二：URL 路径编码（旧方式）
claude config set apiBaseUrl https://your-relay.example.com/https://api.anthropic.com
```

### OpenClaw 配置

在 `openclaw.json` 中：

```json
{
  "models": {
    "providers": {
      "anthropic": {
        "baseUrl": "https://your-relay.example.com",
        "apiKey": "https://api.provider.com/v1::sk-your-own-key"
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
