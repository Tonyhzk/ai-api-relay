# AI API Relay Nginx 站点配置指南

1Panel 创建站点后会自动生成域名、路径、SSL 等变量，不用管。

只需要额外添加以下 **3 段配置**（1Panel 站点设置的自定义配置中添加）：

## 1. merge_slashes off

```
merge_slashes off;
```

必须加。Nginx 默认会把 URL 中的 `//` 合并为 `/`，导致透传代理的目标地址（如 `https://api.anthropic.com/v1/messages`）被破坏。

加在 `server` 块顶层，`error_log` 之后即可。

## 2. 路由到 index.php

```
location / {
    try_files $uri /index.php$is_args$args;
}
```

确保所有请求都走 index.php 处理。

## 3. 禁止访问 config.json

```
location ~ ^/config\.json$ {
    return 404;
}
```

防止外部直接访问 config.json，避免 API Key 等敏感配置泄露。