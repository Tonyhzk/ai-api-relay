# ai-api-relay

**[中文文档](README.zh-CN.md)** | English

A lightweight PHP transparent proxy for AI API requests. Clients specify the target API URL directly in the endpoint, and the proxy forwards everything — API keys, headers, and request body — while optionally applying global transformations like model mapping, thinking toggle, and header injection.

## How It Works

The client encodes the full target URL into the relay's URL path:

```
https://your-relay.example.com/https://api.anthropic.com/v1/messages
                               └──────────── target URL ────────────┘
```

The relay extracts everything after the first `/` and forwards the request as-is.

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

## Configuration

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

| Field | Default | Description |
|-------|---------|-------------|
| `connect_timeout` | `10` | Connection timeout in seconds |
| `timeout` | `300` | Request timeout in seconds |
| `debug` | `false` | Enable full request/response logging |
| `inject_user_id` | `false` | Auto-inject `metadata.user_id` derived from client IP |
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

Set the relay URL as your Base URL, with the target API address embedded in the path:

```
Base URL:  https://your-relay.example.com/https://api.anthropic.com
API Key:   sk-ant-xxxxx (your own key, passed through to the target)
```

Switch targets by changing the Base URL:

| Target | Base URL |
|--------|----------|
| Anthropic | `https://your-relay.example.com/https://api.anthropic.com` |
| Third-party provider | `https://your-relay.example.com/https://api.provider.com` |

### Claude Code

```bash
claude config set apiBaseUrl https://your-relay.example.com/https://api.anthropic.com
```

### OpenClaw

In `openclaw.json`:

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
