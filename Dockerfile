# 使用官方 PHP 8.2 + Apache 镜像
FROM php:8.2-apache

# 开启 Apache 的 rewrite 模块 (备用，增加兼容性)
RUN a2enmod rewrite

# 将本地的 PHP 脚本复制到容器的 Web 根目录
COPY index.php /var/www/html/index.php

# 暴露 80 端口供 Render 绑定
EXPOSE 80
