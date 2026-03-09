# ai-api-relay

**[中文文档](README.zh-CN.md)** | English

A lightweight PHP relay for Anthropic API requests. Sits between AI clients and third-party Anthropic API providers, enabling automatic failover across multiple providers and fixing prompt caching for clients that don't work out of the box.

## The Problem This Solves

When using third-party Anthropic API providers (resellers) through clients like OpenClaw, **prompt caching never works** — every request creates a new cache entry, zero cache reads, wasting money on repeated context.

**Root cause discovered:** Many third-party providers use `metadata.user_id` for sticky routing (session affinity). Without it, requests get distributed across multiple backend API keys, each with an isolated cache namespace. Cache is created but never read.

Claude Code works because it automatically includes `metadata.user_id`. OpenClaw doesn't.

**This proxy fixes it** by injecting a stable `metadata.user_id` (derived from client IP) into every request that's missing one.

## Features

- **Automatic failover** — tries providers in order, switches on connection error or 5xx
- **Circuit breaker** — skips providers that fail repeatedly; auto-recovers after a configurable timeout
- **Prompt caching fix** — per-provider `inject_user_id: true` auto-injects a stable `metadata.user_id` for cache routing affinity
- **Per-provider header injection** — add/append any headers per provider (e.g. beta flags)
- **Per-provider model mapping** — rewrite the requested model name per provider via `modelMap`
- **Per-provider path mapping** — rewrite the request path per provider via `pathMap`
- **Per-provider body injection** — override or inject any request body field per provider via `bodyInject`
- **Per-provider thinking toggle** — force-enable or force-disable extended thinking per provider
- **Transparent passthrough** — supports any path, method, streaming SSE and non-streaming
- **Cache hit/miss logging** — logs `cache_creation_input_tokens` and `cache_read_input_tokens` per request
- **Health check endpoint** — `GET /health` or `GET /status`
- **Debug mode** — full per-request JSON logs with headers, body, forwarded headers, and response body

## Setup

### Requirements

- PHP 7.4+ with `curl` extension
- A web server (Nginx/Apache) pointing to `index.php`

### Installation

```bash
git clone https://github.com/Tonyhzk/ai-api-relay.git
cd ai-api-relay
cp config.example.json config.json
# Edit config.json with your providers and keys
```

### Nginx Config (recommended)

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

## Configuration

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

### `injectHeaders` Syntax

| Value | Behavior |
|-------|----------|
| `"value"` | Replace the header entirely |
| `"+value"` | Append to existing header (comma-separated) |

### `modelMap` — Per-provider Model Substitution

Rewrite the model name in the request body before forwarding. Useful when a provider doesn't support a specific model.

```json
"modelMap": {
  "claude-opus-4-5": "claude-3-5-sonnet-20241022",
  "claude-sonnet-4-6": "claude-haiku-4-5-20251001"
}
```

### `thinking` — Per-provider Thinking Toggle

Control the `thinking` field in the request body per provider.

| Value | Behavior |
|-------|----------|
| `false` | Strip `thinking` and `temperature` fields (for providers that don't support reasoning) |
| `true` | Inject `{"type":"enabled","budget_tokens":8000}` |
| `{"budget_tokens": N}` | Inject with custom token budget |

Not set → pass through the original request unchanged.

### `pathMap` — Per-provider Path Mapping

Rewrite the request path before forwarding. Useful for providers that use non-standard API paths.

```json
"pathMap": {
  "/v1/messages": "/claude"
}
```

Unmatched paths are forwarded as-is.

### `bodyInject` — Per-provider Body Injection

Override or inject any top-level field in the request body before forwarding. Useful for enforcing limits or adding defaults that clients don't send.

```json
"bodyInject": {
  "max_tokens": 8192,
  "temperature": 0.7,
  "system": "You are a helpful assistant."
}
```

Fields in `bodyInject` always overwrite the client's original values.

### `circuit_breaker` — Circuit Breaker

Automatically skip providers that have been failing repeatedly, reducing latency caused by waiting on a dead endpoint.

| Field | Default | Description |
|-------|---------|-------------|
| `enabled` | `false` | Enable circuit breaker |
| `threshold` | `3` | Consecutive failures before tripping |
| `timeout` | `60` | Seconds to wait before retrying a tripped provider |

State is persisted to `logs/circuit.json`. A provider resets automatically after a successful request.

### Why inject `prompt-caching-2024-07-31`?

Clients like OpenClaw (using the Anthropic JS SDK) don't include prompt caching beta flags. The proxy appends them so the provider activates caching.

## How the Caching Fix Works

```
Without proxy:
  Request 1 → Provider (key A) → creates cache
  Request 2 → Provider (key B) → creates cache (different namespace!)
  Request 3 → Provider (key C) → creates cache (different namespace!)
  Result: 0 cache reads, full cost every time

With proxy (metadata.user_id injected):
  Request 1 → Provider (key A, sticky) → creates cache
  Request 2 → Provider (key A, sticky) → cache HIT ✓
  Request 3 → Provider (key A, sticky) → cache HIT ✓
  Result: ~90% cost savings on repeated context
```

The proxy generates a deterministic `user_id` from the client IP + auth key hash when `inject_user_id: true` is set on a provider:

```php
$stableUserId = 'proxy_' . hash('sha256', $clientIp . $authKey);
```

## Usage

Point your client to the proxy instead of the provider directly:

```
Base URL:  https://your-proxy.example.com
API Key:   (your auth_key from config.json)
```

The proxy transparently handles everything else.

## Logs

When `"debug": true` in config:

- `logs/proxy.log` — one line per request with cache hit/miss status
- `logs/debug.log` — detailed forwarding info
- `logs/requests/*.json` — full per-request dump (headers, body, forward headers)
- `logs/responses/*.json` — full upstream response body (non-streaming)
- `logs/responses/*.txt` — full upstream SSE stream (streaming)

Request and response files share the same ID for easy correlation.

Example proxy.log entry:
```
2026-03-03 20:22:57 STREAM_DONE {"provider":"ai580","code":200,"cache_usage":{"cache_creation_input_tokens":65,"cache_read_input_tokens":51566},"cache_status":"HIT(read=51566)"}
```

## Client Configuration

### OpenClaw

In `openclaw.json`, set `baseUrl` to your proxy:

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

### Claude Code

```bash
claude config set apiBaseUrl https://your-proxy.example.com
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for full release history.

## License

[MIT](LICENSE)
