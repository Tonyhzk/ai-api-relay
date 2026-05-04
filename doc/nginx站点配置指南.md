# AI API Relay Nginx 站点配置指南

## 新增站点时需要修改的位置

以下标记 `[需改]` 的地方，每个站点必须替换为实际值：

```nginx
server {
    listen 80 ;
    listen 443 ssl ;
    server_name [需改] 域名;                                          # 例: <域名>
    index index.php index.html index.htm default.php default.htm default.html;
    access_log /www/sites/[需改] 域名/log/access.log main;
    error_log /www/sites/[需改] 域名/log/error.log;
    merge_slashes off;                                                 # ⚠️ 必须保留，否则透传URL中的 // 会被吞掉
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
    root /www/sites/[需改] 域名/index;
    location / {
        try_files $uri /index.php$is_args$args;                      # ⚠️ 必须保留，确保所有路径走 index.php
    }
    location ~ ^/config\.json$ {                                      # ⚠️ 必须保留，禁止外部访问配置文件
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
    ssl_certificate /www/sites/[需改] 域名/ssl/fullchain.pem;
    ssl_certificate_key /www/sites/[需改] 域名/ssl/privkey.pem;
    ssl_protocols TLSv1.3 TLSv1.2;
    ssl_ciphers ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384:DHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-SHA384:ECDHE-RSA-AES128-SHA256:!aNULL:!eNULL:!EXPORT:!DSS:!DES:!RC4:!3DES:!MD5:!PSK:!KRB5:!SRP:!CAMELLIA:!SEED;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    error_page 497 https://$host$request_uri;
    proxy_set_header X-Forwarded-Proto https;
    add_header Strict-Transport-Security "max-age=31536000";
}
```

## 需要替换的位置汇总

| # | 位置 | 替换内容 | 说明 |
|---|------|----------|------|
| 1 | `server_name` | 域名 | 站点绑定的域名 |
| 2 | `access_log` 路径 | `/www/sites/域名/log/access.log` | 访问日志路径 |
| 3 | `error_log` 路径 | `/www/sites/域名/log/error.log` | 错误日志路径 |
| 4 | `root` 路径 | `/www/sites/域名/index` | 站点根目录，指向 index.php 所在目录 |
| 5 | `ssl_certificate` 路径 | `/www/sites/域名/ssl/fullchain.pem` | SSL 证书路径 |
| 6 | `ssl_certificate_key` 路径 | `/www/sites/域名/ssl/privkey.pem` | SSL 私钥路径 |

共 6 处，全部是域名/路径替换，其余配置各站点通用。

## ⚠️ 不可删除的关键配置

以下是透传代理必须的配置，新增站点时不可省略：

- **`merge_slashes off;`** — 禁止 Nginx 合并 URL 中的 `//`，否则透传目标地址（如 `https://api.anthropic.com/v1/messages`）会被破坏
- **`location / { try_files ... }`** — 确保所有请求路由到 index.php
- **`location ~ ^/config\.json$ { return 404; }`** — 禁止外部直接访问 config.json，防止 API Key 等敏感配置泄露