# Build context is the repo root (see docker-compose.test.yml):
# the tests autoload ../master/src and ../secondary/src.
FROM phpswoole/swoole:php8.3-alpine

WORKDIR /app/tests

COPY tests/composer.json tests/composer.lock ./
RUN composer install --no-interaction --no-progress

COPY master/src /app/master/src
COPY secondary/src /app/secondary/src
COPY tests /app/tests

CMD ["vendor/bin/phpunit", "ReplicationTest.php"]
