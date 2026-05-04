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

## 完整配置示例

以下是补全3段配置后的完整参考（1Panel 自动生成的部分不用改，只需确认3段配置已添加）：

```nginx
server {
    listen 80 ;
    listen 443 ssl ;
    server_name <域名>;
    index index.php index.html index.htm default.php default.htm default.html;
    access_log /www/sites/<域名>/log/access.log main;
    error_log /www/sites/<域名>/log/error.log;
    merge_slashes off;
    location ~ ^/(\.user.ini|\.htaccess|\.git|\.env|\.svn|\.project|LICENSE|README.md) {
        return 404;
    }
    location ^~ /.well-known/acme-challenge {
        allow all;
        root /usr/share/nginx/html;
    }
    if ( $uri ~ "^/\.well-known/.*\.(php|jsp|py|js|css|lua|ts|go|zip|tar\.gz|rar|7z|sql|bak)$" ) {
        return 403;
    }
    error_page 404 /404.html;
    root /www/sites/<域名>/index;
    location / {
        try_files $uri /index.php$is_args$args;
    }
    location ~ ^/config\.json$ {
        return 404;
    }
    location ~ [^/]\.php(/|$) {
        fastcgi_pass 127.0.0.1:9000;
        include fastcgi-php.conf;
        include fastcgi_params;
        set $real_script_name $fastcgi_script_name;
        if ($fastcgi_script_name ~ "^(.+?\.php)(/.+)$" ) {
            set $real_script_name $1;
            set $path_info $2;
        }
        fastcgi_param SCRIPT_FILENAME $document_root$real_script_name;
        fastcgi_param SCRIPT_NAME $real_script_name;
        fastcgi_param PATH_INFO $path_info;
    }
    http2 on;
    if ($scheme = http) {
        return 301 https://$host$request_uri;
    }
    ssl_certificate /www/sites/<域名>/ssl/fullchain.pem;
    ssl_certificate_key /www/sites/<域名>/ssl/privkey.pem;
    ssl_protocols TLSv1.3 TLSv1.2;
    ssl_ciphers ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384:DHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-SHA384:ECDHE-RSA-AES128-SHA256:!aNULL:!eNULL:!EXPORT:!DSS:!DES:!RC4:!3DES:!MD5:!PSK:!KRB5:!SRP:!CAMELLIA:!SEED;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    error_page 497 https://$host$request_uri;
    proxy_set_header X-Forwarded-Proto https;
    add_header Strict-Transport-Security "max-age=31536000";
}