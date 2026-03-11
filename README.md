# ai-api-relay

**[中文文档](README.zh-CN.md)** | English

A lightweight PHP transparent proxy for AI API requests. Supports three ways to specify the target API: embedded in the API key, encoded in the URL path, or via a config default. The proxy forwards everything — headers and request body — while optionally applying global transformations like model mapping, thinking toggle, and header injection.

## How It Works

The relay resolves the target URL in priority order:

**1. API Key prefix (recommended)** — embed the target URL in the API key, separated by `::`:

```
API Key:   https://api.provider.com/v1::sk-your-actual-key
Base URL:  https://your-relay.example.com
```

**2. URL path (legacy)** — encode the target in the relay's URL path:

```
https://your-relay.example.com/https://api.anthropic.com/v1/messages
                               └──────────── target URL ────────────┘
```

**3. Config default** — set `defaultTargetUrl` in config.json, then just use the relay URL directly:

```
Base URL:  https://your-relay.example.com
API Key:   sk-your-key
Config:    "defaultTargetUrl": "https://api.anthropic.com/v1"
```

## Features

- **Transparent passthrough** — API key, headers, and body forwarded directly to the target
- **Client-specified target** — no server-side provider configuration needed; the client controls where requests go
- **Global model mapping** — rewrite model names via `modelMap` before forwarding
- **Global thinking toggle** — force-enable or force-disable extended thinking
- **Global header injection** — add or append any headers (e.g. beta flags) via `injectHeaders`
- **Global body injection** — override or inject any request body field via `bodyInject`
- **User ID injection** — auto-inject `metadata.user_id` for cache routing affinity
- **SSE streaming** — full streaming passthrough with real-time output
- **Health check endpoint** — `GET /health` or `GET /status`
- **Debug mode** — full per-request JSON logs with headers, body, and response

## Setup

### Requirements

- PHP 7.4+ with `curl` extension
- A web server (Nginx/Apache) routing all requests to `index.php`

### Installation

```bash
git clone https://github.com/Tonyhzk/ai-api-relay.git
cd ai-api-relay
cp src/config.example.json src/config.json
# Edit config.json as needed
```

### Nginx Config (recommended)

A reference config is provided in [`doc/nigix-origin.conf`](doc/nigix-origin.conf). Key points specific to this relay:

```nginx
server {
    listen 80;
    server_name relayai.website.com;
    root /www/sites/relayai.website.com/index;

    # CRITICAL: preserve :// in URL paths like /https://api.example.com
    merge_slashes off;

    # Protect config file from direct access
    location ~ ^/config\.json$ {
        return 404;
    }

    # Route all requests to index.php
    location / {
        try_files $uri /index.php$is_args$args;
    }

    # PHP handler with streaming support
    location ~ [^/]\.php(/|$) {
        fastcgi_pass 127.0.0.1:9000;
        include fastcgi-php.conf;
        include fastcgi_params;
        # ... other fastcgi params ...
        fastcgi_buffering off;
    }
}
```

| Directive | Purpose |
|-----------|---------|
| `merge_slashes off` | Prevents Nginx from merging `://` to `:/` in the URL path |
| `location /` | Catch-all route, forwards all requests to `index.php` |
| `fastcgi_buffering off` | Enables real-time SSE streaming passthrough |
| `location ~ ^/config\.json$` | Blocks direct access to the config file |

## Configuration

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

| Field | Default | Description |
|-------|---------|-------------|
| `connect_timeout` | `10` | Connection timeout in seconds |
| `timeout` | `300` | Request timeout in seconds |
| `debug` | `false` | Enable full request/response logging |
| `inject_user_id` | `false` | Auto-inject `metadata.user_id` derived from client IP |
| `defaultTargetUrl` | _(not set)_ | Fallback target URL when not specified in API key or path |
| `modelMap` | `{}` | Model name substitution map |
| `thinking` | _(not set)_ | Thinking toggle (see below) |
| `injectHeaders` | `{}` | Headers to inject/append |
| `bodyInject` | `{}` | Request body fields to inject/override |

### `modelMap` — Model Substitution

Rewrite the model name in the request body before forwarding.

```json
"modelMap": {
  "claude-opus-4-5": "claude-sonnet-4-5",
  "claude-sonnet-4-6": "claude-haiku-4-5-20251001"
}
```

### `thinking` — Thinking Toggle

Control the `thinking` field in the request body.

| Value | Behavior |
|-------|----------|
| `false` | Strip `thinking` and `temperature` fields |
| `true` | Inject `{"type":"enabled","budget_tokens":8000}` |
| `{"budget_tokens": N}` | Inject with custom token budget |

Not set → pass through the original request unchanged.

### `injectHeaders` — Header Injection

| Value | Behavior |
|-------|----------|
| `"value"` | Replace the header entirely |
| `"+value"` | Append to existing header (comma-separated) |

### `bodyInject` — Body Injection

Override or inject any top-level field in the request body before forwarding.

```json
"bodyInject": {
  "max_tokens": 8192,
  "temperature": 0.7
}
```

Fields in `bodyInject` always overwrite the client's original values.

## Usage

### Method 1: API Key Encoding (Recommended)

Embed the target URL in the API key using `::` separator:

```
Base URL:  https://your-relay.example.com
API Key:   https://api.anthropic.com/v1::sk-ant-xxxxx
```

Switch targets by changing the API key prefix:

| Target | API Key Format |
|--------|----------------|
| Anthropic | `https://api.anthropic.com/v1::sk-ant-xxxxx` |
| Third-party | `https://api.provider.com/v1::sk-your-key` |

### Method 2: URL Path Encoding (Legacy)

Encode the target in the relay's URL path:

```
Base URL:  https://your-relay.example.com/https://api.anthropic.com
API Key:   sk-ant-xxxxx
```

### Method 3: Config Default

Set `defaultTargetUrl` in config.json:

```json
{
  "defaultTargetUrl": "https://api.anthropic.com/v1"
}
```

Then use the relay directly:

```
Base URL:  https://your-relay.example.com
API Key:   sk-ant-xxxxx
```

### Claude Code

```bash
# Method 1: API key encoding
ANTHROPIC_API_KEY="https://api.anthropic.com/v1::sk-ant-xxxxx"
claude config set apiBaseUrl https://your-relay.example.com

# Method 2: URL path encoding (legacy)
claude config set apiBaseUrl https://your-relay.example.com/https://api.anthropic.com
```

### OpenClaw

In `openclaw.json`:

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

## Logs

When `"debug": true` in config:

- `logs/proxy.log` — one line per request
- `logs/debug.log` — detailed forwarding info
- `logs/requests/*.json` — full per-request dump (headers, body)
- `logs/responses/*.json` — full upstream response body (non-streaming)
- `logs/responses/*.txt` — full upstream SSE stream (streaming)

Request and response files share the same ID for easy correlation.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for full release history.

## License

[MIT](LICENSE)
