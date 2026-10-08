FROM phpswoole/swoole:php8.3-alpine

WORKDIR /app

COPY composer.json ./
COPY src ./src
RUN composer dump-autoload --optimize --no-dev

COPY server.php ./

CMD ["php", "/app/server.php"]
