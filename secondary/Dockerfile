FROM phpswoole/swoole:php8.3-alpine

WORKDIR /app

COPY composer.json ./
COPY src ./src
RUN composer dump-autoload --optimize --no-dev

COPY server.php ./

# 8080 is public (GET /messages); 8081 is internal (POST /replicate) — don't publish it.
EXPOSE 8080

CMD ["php", "/app/server.php"]
