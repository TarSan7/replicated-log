# Replicated Log

A replicated log built with PHP and Swoole: one master and any number of secondaries.
The master appends a message, replicates it to all secondaries in parallel, and
responds only after every secondary has acknowledged it.

```
master/      master node (public API, replication)
secondary/   secondary node (public API + internal /replicate endpoint)
tests/       integration tests
```

## Run

```sh
docker compose up --build
```

This starts a master and two secondaries. `secondary2` acknowledges with a 3s delay
(`DELAY_MS`), so you can see the master wait for all ACKs.

| Node       | URL                     |
|------------|-------------------------|
| master     | http://localhost:8080   |
| secondary1 | http://localhost:8081   |
| secondary2 | http://localhost:8082   |

Logs from all nodes: `docker compose logs -f`

## Endpoints

### Master

Append a message (blocks until all secondaries ACK):

```sh
curl -X POST localhost:8080/messages -d '{"message":"hello"}'
# 201 {"id":1,"message":"hello"}
```

List messages:

```sh
curl localhost:8080/messages
# ["hello"]
```

### Secondary

List messages:

```sh
curl localhost:8081/messages
curl localhost:8082/messages
# ["hello"]
```

`POST /replicate` is internal: it's served on a separate port (`INTERNAL_PORT`, 8081
inside the container) that isn't published, so only the master can call it.

## Tests

Run in Docker (separate compose file, doesn't need the app running):

```sh
docker compose -f docker-compose.test.yml run --rm --build tests
```

Or locally (needs PHP 8.2+ with the Swoole extension):

```sh
cd tests
composer install
vendor/bin/phpunit ReplicationTest.php
```

The tests run the real master and secondary code in one process with a mocked
transport in place of HTTP, so network delays can be set per secondary:

```php
$transport->setDelay($master, $secondary1, 2.0);
$master->appendMessage('m1'); // blocks ~2s
```
