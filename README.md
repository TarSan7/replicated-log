# Replicated Log

A replicated log built with PHP and Swoole: one master and any number of secondaries.
The master appends a message, replicates it to all secondaries in parallel, and
responds once the number of ACKs required by the write concern (`w`) has arrived.
Failed deliveries are retried until they succeed, a heartbeat tracks the health of
every secondary, and the master goes read-only when it loses quorum.

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
(`DELAY_MS`), so you can see the master wait for its ACK with `w=3`.

| Node       | URL                     |
|------------|-------------------------|
| master     | http://localhost:8080   |
| secondary1 | http://localhost:8081   |
| secondary2 | http://localhost:8082   |

Logs from all nodes: `docker compose logs -f`

## Endpoints

### Master

Append a message with write concern `w` (1 = master only, up to master + all secondaries):

```sh
curl -X POST localhost:8080/messages -H 'content-type: application/json' -d '{"message":"hello","w":3}'
# 201 {"id":1,"message":"hello"}
```

The request blocks until `w-1` secondaries have ACKed; delivery to the rest continues
in the background. Without quorum it's rejected at once (see [Quorum](#quorum-read-only-mode)):

```sh
# 503 {"error":"no quorum: master is in read-only mode, appends are not accepted","alive_nodes":1,"quorum":2}
```

List messages:

```sh
curl localhost:8080/messages
# ["hello"]
```

Health of the secondaries and the master's mode:

```sh
curl localhost:8080/health
# {"mode":"read-write","alive_nodes":3,"quorum":2,
#  "secondaries":{"secondary1:8081":{"status":"healthy","failures":0,"last_seen":"2026-11-24T14:23:34.591+00:00"},
#                 "secondary2:8081":{"status":"healthy","failures":0,"last_seen":"2026-11-24T14:23:34.591+00:00"}}}
```

### Secondary

List messages:

```sh
curl localhost:8081/messages
curl localhost:8082/messages
# ["hello"]
```

`POST /replicate` and `GET /health` are internal: they're served on a separate port
(`INTERNAL_PORT`, 8081 inside the container) that isn't published, so only the master
can call them.

## Retries

The master delivers each message to each secondary in its own coroutine and retries
until the secondary ACKs; there's no attempt limit. So:

- a client with `w=3` and a secondary down stays blocked until that secondary is back
  and has ACKed;
- clients running in parallel aren't affected: waits yield the coroutine, so the
  single worker keeps serving other requests;
- messages a secondary missed while it was unavailable are delivered once it rejoins.
  The secondary drops duplicates and buffers out-of-order messages, so a retried
  message arriving twice or late is safe.

An attempt fails on a connection error, a non-200 response, or no response within
`REPLICATION_TIMEOUT`. The delay before the next attempt depends on the secondary's
health status:

| Status    | Delay before the next attempt                                                     |
|-----------|-----------------------------------------------------------------------------------|
| healthy   | exponential backoff: `RETRY_BASE_DELAY` doubled on each attempt, capped at `RETRY_MAX_DELAY`, ±20% jitter |
| suspected | the same, doubled                                                                  |
| unhealthy | no attempts; resumes as soon as the heartbeat sees it again, with the backoff reset |

## Health checks

A timer on the master calls `GET /health` on every secondary each
`HEALTH_INTERVAL_MS`, in parallel, with a 1s timeout. Each secondary goes through:

```
healthy ──1 missed check──► suspected ──3 missed in a row──► unhealthy
   ▲                                                              │
   └──────────────────── any successful check ◄───────────────────┘
```

Status changes are logged, and `GET /health` on the master shows the current state.
The status only decides when retries happen; a message counts as delivered only when
`/replicate` returns an ACK.

## Quorum (read-only mode)

Quorum is a majority of all nodes, the master included: 2 of 3 with the default
setup. Healthy and suspected secondaries count as alive, unhealthy ones don't.

Without quorum the master is read-only: `POST /messages` returns `503` for any `w`,
while `GET /messages` keeps working. Appends are accepted again as soon as enough
secondaries are back. Requests that were already waiting when quorum was lost keep
waiting rather than being cancelled.

## Configuration

Master environment variables (see `docker-compose.yml`):

| Variable              | Default | Meaning                                                        |
|-----------------------|---------|----------------------------------------------------------------|
| `SECONDARIES`         | –       | comma-separated `host:port` of the secondaries' internal ports |
| `REPLICATION_TIMEOUT` | 5       | seconds to wait for one `/replicate` attempt; keep it above the secondaries' `DELAY_MS` |
| `RETRY_BASE_DELAY`    | 0.5     | seconds before the first retry                                 |
| `RETRY_MAX_DELAY`     | 10      | upper bound for the delay between retries, in seconds          |
| `HEALTH_INTERVAL_MS`  | 1000    | how often the heartbeat runs                                   |

## Trying it out

Use `pause`/`unpause` to make a secondary unavailable. `stop`/`start` restarts it with
an empty in-memory log, so it can't catch up on messages it ACKed before the restart.

```sh
docker compose pause secondary2
curl -X POST localhost:8080/messages -H 'content-type: application/json' -d '{"message":"a","w":3}'   # blocks
curl -X POST localhost:8080/messages -H 'content-type: application/json' -d '{"message":"b","w":2}'   # returns at once
docker compose unpause secondary2                                                                    # "a" returns 201

docker compose pause secondary1 secondary2      # wait ~6s until /health shows "read-only"
curl -X POST localhost:8080/messages -H 'content-type: application/json' -d '{"message":"c","w":1}'   # 503
docker compose unpause secondary1 secondary2
```

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
$master->appendMessage('m1', 3);              // w=3: blocks ~2s until secondary1 acks

$transport->reorderMessages($master, $secondary1, 2);
$master->appendMessage('m2', 1);              // held
$master->appendMessage('m3', 1);              // delivered as m3, m2
$secondary1->listMessages();                  // ['m1', 'm2', 'm3']
```

Retry timing and the per-attempt timeout are set per test with
`new Transport(timeout: 0.5, retryBaseDelay: 0.1, retryMaxDelay: 0.5)`. Lost messages
and slow links drive the retry logic:

```php
$transport->dropMessage($master, $secondary1);  // every attempt fails, nothing arrives
$transport->removeDrop($master, $secondary1);   // the next retry gets through

$transport->setDelay($master, $secondary2, 3);  // longer than the timeout: the master retries,
                                                // and every copy still arrives (deduplicated)
$transport->removeDelay($master, $secondary2);

$transport->attempts($secondary1, 1);           // how many times message 1 was sent to S1
```

The heartbeat isn't simulated: `AlwaysHealthyMonitor` reports every secondary as
healthy, so the master always has quorum.
