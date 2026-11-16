---
title: "rekryt/amphp-vless — конспект репозитория (асинхронный VLESS-клиент для PHP на Amp)"
type: repository
source: https://github.com/rekryt/amphp-vless
local: references/amphp-vless/
commit: 065417f
version: v1.1.1
reviewed: 2026-11-16
---

# amphp-vless — конспект

> Конспект составлен по локальному сабмодулю `references/amphp-vless/` на коммите `065417f` (тег `v1.1.1`, дата коммита 2026-08-06). Весь `src/`, `bin/vless-proxy`, `README.md`, `composer.json`, `Dockerfile`, `docker-compose.example.yml`, CI и часть примеров прочитаны целиком; что прочитано частично или не прочитано — в разделе [«Что не прочитано»](#10-что-не-прочитано). Код не запускался (в сабмодуле нет `vendor/`), кроме одной проверки разбора ссылки — она помечена. Пути в таблицах — относительные ссылки от `notes/`; ссылки на строки — в формате проекта `references/amphp-vless/...@065417f:Lx-Ly (Имя)`.

## 1. Кратко

`rekryt/amphp-vless` — реализация клиентской стороны протокола VLESS на чистом PHP 8.2+ поверх AMPHP v3: без `xray`/`sing-box` рядом, без локального SOCKS-порта, без `exec` и FFI; из расширений нужен только `openssl`. Точка интеграции одна — класс `VlessConnector`, реализующий `Amp\Socket\SocketConnector`: всё в экосистеме AMPHP, что принимает коннектор (HTTP-клиент, пулы, драйверы БД, `amphp/ssh`), работает через туннель без изменений. Поддержаны транспорты `tcp`, `ws`, `httpupgrade`, `grpc`, `xhttp` (три режима), каждый с TLS и без; **не поддержаны и не будут** `flow=xtls-rprx-vision` и `security=reality` — такие конфигурации отвергаются при разборе, а не деградируют молча. В комплекте идёт `vless-proxy` — исполняемый файл и Docker-образ, который выставляет туннель как локальный SOCKS5- и/или HTTP-прокси для программ не на PHP. Библиотека закрывает маскировку «по адресу, домену и сигнатуре протокола», но не защищает от статистического анализа трафика (DPI) — это прямо написано в README.

## 2. Карта репозитория

| Путь | Что внутри |
|---|---|
| [README.md](../references/amphp-vless/README.md), [README.ru.md](../references/amphp-vless/README.ru.md) | Документация (англ. — 492 строки, рус. перевод — 484): установка, HTTP, конфигурация, транспорты, UDP, «чего нельзя», `vless-proxy`, Docker, примеры, тесты |
| [composer.json](../references/amphp-vless/composer.json) | Пакет `rekryt/amphp-vless`, namespace `Amp\Vless\`, бинарник `bin/vless-proxy`; runtime-зависимости: `amphp/amp ^3.1`, `byte-stream ^2.1`, `hpack ^3.2`, `http ^2.1`, `socket ^2.4`, `psr/log ^3`, `ext-openssl` |
| [src/VlessConnector.php](../references/amphp-vless/src/VlessConnector.php) | **Весь публичный вход**: `SocketConnector` через VLESS, `connectDatagram()`, фабрика транспорта `transportFor()` |
| [src/VlessConfig.php](../references/amphp-vless/src/VlessConfig.php) | Неизменяемое описание сервера; разбор `vless://`-ссылки и массива в формате sing-box; все отказы «это в PHP невозможно» |
| [src/Transport/](../references/amphp-vless/src/Transport/) | `TransportInterface` и пять транспортов: `TcpTransport` (TCP + опциональный TLS, база для остальных), `WebSocketTransport`, `HttpUpgradeTransport`, `GrpcTransport`, `XhttpTransport` |
| [src/Udp/VlessDatagramSocket.php](../references/amphp-vless/src/Udp/VlessDatagramSocket.php) | UDP поверх VLESS: датаграммы к одному адресату с 2-байтовым префиксом длины |
| [src/Server/](../references/amphp-vless/src/Server/) | Локальные «входы»: `ProxyServer` (общая часть), `Socks5ProxyServer`, `HttpProxyServer`, `ProxyCredentials` |
| [src/Exception/](../references/amphp-vless/src/Exception/) | Шесть файлов: маркер `VlessException` и пять классов ошибок |
| [src/Internal/](../references/amphp-vless/src/Internal/) | Помечено `@internal`: кодек VLESS (`RequestHeader`, `ResponseHeader`, `Address`, `Addons`, `Uuid`), `VlessSocket`, `BridgedSocket` (TLS до адресата через loopback-пару), `BufferedReader`, свой HTTP/2-клиент (`Http2/`), обёртки gRPC (`Grpc/`), WebSocket (`WebSocket/`), XHTTP (`Xhttp/`) |
| [bin/vless-proxy](../references/amphp-vless/bin/vless-proxy) | CLI: читает опции и переменные окружения, поднимает SOCKS5/HTTP-прокси через туннель |
| [Dockerfile](../references/amphp-vless/Dockerfile), [docker-compose.example.yml](../references/amphp-vless/docker-compose.example.yml) | Образ `vless-proxy` (php:8.3-cli-alpine, `--no-dev`, пользователь `nobody`) и шаблон сервиса с ограничениями и секретами |
| [examples/](../references/amphp-vless/examples/) | 27 запускаемых примеров + `bootstrap.php`; 01–13 — возможности, 14–27 — эксплуатация (диагностика, split-tunnel, failover, таймауты, воркер, цепочки, Telegram, SSH) |
| [test/Unit/](../references/amphp-vless/test/Unit/), [test/E2E/](../references/amphp-vless/test/E2E/), [test/Support/](../references/amphp-vless/test/Support/), [test/fixtures/vectors.json](../references/amphp-vless/test/fixtures/vectors.json) | 25 файлов юнит-тестов, 6 файлов E2E (нужен реальный сервер), фейки, эталонные байтовые векторы |
| [tools/gen-vectors/](../references/amphp-vless/tools/gen-vectors/) | Go-программа: генерирует `vectors.json` кодом `sing-vmess` (ревизия, на которой собран sing-box 1.13.16) |
| [package.json](../references/amphp-vless/package.json), `.prettierrc` | Только форматтер Prettier с PHP-плагином; к работе библиотеки отношения не имеет |
| [.github/workflows/ci.yml](../references/amphp-vless/.github/workflows/ci.yml), `phpunit.xml`, `psalm.xml` | CI: PHP 8.2/8.3/8.4, phpunit, psalm (уровень 4), проверка «в production-зависимостях нет HTTP-клиента/сервера» |
| [.env.example](../references/amphp-vless/.env.example) | Переменные для примеров и E2E (`VLESS_TEST_URI`, `SSH_*`, `TELEGRAM_*` и др.) |

## 3. Публичный API

Публичная поверхность мала: `VlessConnector`, `VlessConfig`, `Transport\*`, `Udp\VlessDatagramSocket`, `Server\*`, `Exception\*`. Всё в `Internal\` помечено `@internal`.

### 3.1. HTTP-запрос через VLESS (amphp/http-client)

`amphp/http-client` не модифицируется — меняется только коннектор:

```php
$connector = VlessConnector::fromUri('vless://<uuid>@example.org:443?security=tls&type=ws&path=%2Fws%2F');

$client = (new HttpClientBuilder())
    ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory($connector)))
    ->build();
```

- Источник: `references/amphp-vless/README.md@065417f:L36-L57`, `references/amphp-vless/examples/02-http-client.php@065417f:L35-L37`.
- `amphp/http-client` — **dev-зависимость** библиотеки (`references/amphp-vless/composer.json@065417f:L31-L49`); в проект его надо ставить самим.
- HTTPS работает «насквозь»: TLS согласуется с адресатом внутри туннеля, сертификат адресата проверяется как обычно, HTTP/2 согласуется, если сервер предлагает.
- Как это устроено: когда вызывающий передаёт `ConnectContext` с TLS-контекстом (так делает http-client для `https`), `connect()` возвращает не сам туннельный сокет, а конец loopback-пары `127.0.0.1`, на котором OpenSSL может выполнить `setupTls()`; байты перекачиваются в туннель двумя `pipe` в отдельном файбере — `references/amphp-vless/src/VlessConnector.php@065417f:L84-L95 (connect)`, `references/amphp-vless/src/Internal/BridgedSocket.php@065417f:L61-L93 (over)`, `L138-L153 (createPair)`. Без TLS-контекста моста нет.
- Имя адресата локально **не резолвится**: оно уходит в заголовке VLESS как домен, резолвит сервер (`references/amphp-vless/src/Internal/Address.php@065417f:L51-L85 (encodeHost)`; доказательство записывающим резолвером — `examples/15-no-dns-leak.php`, прочитана только шапка). Локально резолвится только имя самого VLESS-сервера.

### 3.2. «Сырой» TCP-сокет через VLESS (нужно для SSH)

```php
$socket = $connector->connect('tcp://host:22', null, new TimeoutCancellation(20));  // Amp\Socket\Socket
```

- `references/amphp-vless/src/VlessConnector.php@065417f:L62-L96 (connect)` — сигнатура ровно как у `SocketConnector::connect(SocketAddress|string $uri, ?ConnectContext $context, ?Cancellation $cancellation): Socket`.
- Формы адреса: `tcp://host:port`, `host:port`, `[::1]:443`, `InternetAddress`; unix-сокеты и адреса без порта — `ConfigException` (`L177-L222 (destinationOf)`).
- Возвращается `Internal\VlessSocket` — обычный `Socket`: заголовок запроса дописывается к первому `write()` либо отправляется при первом `read()`, если первым говорит сервер (как SSH с баннером версии) — `references/amphp-vless/src/Internal/VlessSocket.php@065417f:L62-L79 (read)`, `L81-L93 (write)`.
- `setupTls()` на этом сокете бросает `LogicException` (`L115-L121`): TLS до адресата возможен только через путь с `ConnectContext` (п. 3.1).
- Коннектор не хранит состояния и соединений: **каждый `connect()` строит новый туннель** (TCP → TLS → рукопожатие транспорта → заголовок VLESS). Мультиплексирования (Mux) нет: константа `COMMAND_MUX` есть в кодеке, но нигде не используется (`references/amphp-vless/src/Internal/RequestHeader.php@065417f:L30-L32`).
- Коннектор можно передать в любой драйвер, принимающий `SocketConnector`: MySQL, Redis (`examples/18-database.php`), `Amp\Ssh\connect()` (`examples/27-ssh-tunnel.php`).

### 3.3. Конфигурация

Три способа описать сервер (`references/amphp-vless/README.md@065417f:L59-L87`):

| Способ | Где | Замечания |
|---|---|---|
| `VlessConfig::fromUri('vless://…')` | `references/amphp-vless/src/VlessConfig.php@065417f:L169-L233 (fromUri)` | Формат `vless://<uuid>@<host>:<port>?<params>#<label>`; ключи query приводятся к нижнему регистру |
| `VlessConfig::fromArray([...])` | `L240-L286 (fromArray)` | Раскладка как у outbound sing-box: `server`, `server_port`, `uuid`, `flow`, `tag`, `tls{enabled,server_name,insecure,alpn,reality}`, `transport{type,path,host,headers,service_name}` |
| `new VlessConfig(...)` | `L86-L156 (__construct)` | Именованные аргументы: `server`, `port`, `uuid`, `tls`, `serverName`, `allowInsecure`, `alpn`, `transport`, `path`, `host`, `flow`, `label`, `serviceName`, `xhttp` |

Параметры ссылки, которые читает `fromUri`: `encryption` (только `none`/пусто), `security` (`none`/`tls`; `reality` и `xtls` — отказ), `type`, `host`, `sni` (по умолчанию = `host`), `allowInsecure`/`insecure`, `alpn` (через запятую), `path`, `flow`, `serviceName`, для XHTTP — `mode`, `x_padding_bytes`, `sc_max_each_post_bytes`, `extra` (JSON) (`references/amphp-vless/src/Internal/Xhttp/XhttpSettings.php@065417f:L50-L73 (fromQuery)`). Остальные параметры (например `fp`, `pbk`, `sid`, `headerType`) в коде не читаются вовсе.

Прочее:
- `uuid` не обязан быть UUID: произвольная строка хешируется в UUIDv5 с нулевым namespace, как в sing-vmess — `references/amphp-vless/src/Internal/Uuid.php@065417f:L34-L36 (toBytes)`.
- Управляющие символы (C0, DEL) в `server`, `sni`, `host`, `path`, `serviceName`, `alpn` — `ConfigException`: значения попадают в HTTP/1.1-заголовки рукопожатия, а ссылки приходят из подписок (`references/amphp-vless/src/VlessConfig.php@065417f:L529-L549 (assertNoControlCharacters)`).
- `allowInsecure` отключает проверку сертификата VLESS-сервера (`references/amphp-vless/src/Transport/TcpTransport.php@065417f:L145-L150`); `vless-proxy` при этом громко предупреждает на старте.
- Вспомогательные методы: `serverAddress()` (`L317-L321`), `effectiveServerName()` (`L302-L304`), `effectiveHost()` (`L309-L311`), `xhttpMode()` (`L330-L336`), `uuidBytes()` (`L293-L296`).

### 3.4. Транспорты и режимы

TLS — отдельный переключатель, не часть транспорта: каждый транспорт работает и с TLS, и без (`references/amphp-vless/README.md@065417f:L89-L122`).

| `type` (синонимы) | Статус | Реализация |
|---|---|---|
| `tcp` (`raw`) | да | `references/amphp-vless/src/Transport/TcpTransport.php@065417f:L36-L154 (TcpTransport)` |
| `ws` (`websocket`) | да | `references/amphp-vless/src/Transport/WebSocketTransport.php@065417f:L27-L65`; клиентское рукопожатие RFC 6455 и кадры — свои (`Internal/WebSocket/`) |
| `httpupgrade` | да | `references/amphp-vless/src/Transport/HttpUpgradeTransport.php@065417f:L31-L68` — upgrade без ключа и без кадров |
| `grpc` | да | `references/amphp-vless/src/Transport/GrpcTransport.php@065417f:L27-L111`; путь из `serviceName`: `name` → `/name/Tun`, `/a/b/c` → как есть, по умолчанию `GunService` (`L92-L110 (pathFor)`) |
| `xhttp` (`splithttp`) | да, режимы `packet-up`, `stream-up`, `stream-one` | `references/amphp-vless/src/Transport/XhttpTransport.php@065417f:L38-L163`; `auto` → `packet-up` (`references/amphp-vless/src/Internal/Xhttp/XhttpSettings.php@065417f:L86-L88 (resolvedMode)`) |
| `mkcp`/`kcp`, `quic`, `h3`, `http`, `h2`, `hysteria` | **нет** — `UnsupportedFeatureException` | `references/amphp-vless/src/VlessConfig.php@065417f:L60 (UNIMPLEMENTABLE_TRANSPORTS)`, `L356-L372 (assertTransportIsSupported)` |
| `security=tls` | да (TLS через OpenSSL: SNI, ALPN из ссылки, проверка сертификата) | `references/amphp-vless/src/Transport/TcpTransport.php@065417f:L128-L153 (tlsContext)` |
| `security=reality` | **нет** — `UnsupportedFeatureException` | `references/amphp-vless/src/VlessConfig.php@065417f:L374-L386 (assertSecurityIsSupported)` |
| `flow=xtls-rprx-vision` (и любой `xtls-rprx-*`), `security=xtls` | **нет** — `UnsupportedFeatureException` | `L342-L354 (assertFlowIsSupported)` |
| `encryption` ≠ `none` | **нет** — `ConfigException` | `L393-L404 (assertEncryptionIsNone)` |
| Mux, `xudp`, `packetaddr` | нет (не реализованы) | см. п. 3.2 и 3.5 |

- `grpc` и `xhttp` идут по HTTP/2, клиент HTTP/2 — собственный (`references/amphp-vless/src/Internal/Http2/Http2Connection.php@065417f:L37-L489`): `amphp/http-client` не отдаёт ответ, пока не завершено тело запроса, а у туннеля оно не завершается. Разбор входящих кадров и HPACK взяты из `amphp/http` и `amphp/hpack`.
- Выбор транспорта по конфигу — `references/amphp-vless/src/VlessConnector.php@065417f:L139-L166 (transportFor)`. Интерфейс транспорта — один метод `connect(?Cancellation): Socket` (`references/amphp-vless/src/Transport/TransportInterface.php@065417f:L20-L26`), свой транспорт можно передать вторым аргументом конструктора `VlessConnector`.
- **Цепочки.** `transportFor($config, $connector)` принимает `SocketConnector`, которым достигается сам VLESS-сервер: другой туннель, HTTP-прокси, SOCKS5, маршрутизирующий декоратор (`references/amphp-vless/examples/22-chaining.php@065417f:L64-L70 (tunnelThrough)`).
- **Глобальная установка.** `TcpTransport` захватывает `socketConnector()` один раз в конструкторе, чтобы туннель не начал звонить сам через себя, если `VlessConnector` потом поставят процессным коннектором по умолчанию (`references/amphp-vless/src/Transport/TcpTransport.php@065417f:L40-L68`).

### 3.5. UDP

```php
$socket = $connector->connectDatagram('udp://1.1.1.1:53');
$socket->send($query);
$answer = $socket->receive();   // null, когда туннель закрыт
```

- `references/amphp-vless/src/VlessConnector.php@065417f:L105-L117 (connectDatagram)`, `references/amphp-vless/src/Udp/VlessDatagramSocket.php@065417f:L37-L161` (`send` — `L67-L91`, `receive` — `L98-L124`).
- Это не UDP «из конца в конец»: туннель — TCP, каждая датаграмма идёт с 2-байтовым префиксом длины (до 65535 байт); порядок и доставка — от TCP. Адресат фиксируется при открытии. Режимы `xudp`/`packetaddr` не реализованы.
- SOCKS5-вход `vless-proxy` UDP не обслуживает (только `CONNECT`).

### 3.6. Таймауты и отмена

- Собственных таймаутов у коннектора нет: срок задаёт вызывающий через `Cancellation` (`TimeoutCancellation`, `DeferredCancellation`). Токен проходит через транспорт, TLS-рукопожатие и upgrade (`references/amphp-vless/examples/06-cancellation.php@065417f:L38-L118`). Отмена кооперативная — срабатывает в следующей точке приостановки, а не мгновенно.
- **У VLESS нет ответа «адресат достигнут»**: если сервер не смог соединиться с адресатом, клиент видит не ошибку, а тишину или обычный конец потока. Поэтому `connect()` успешен, как только построен туннель до сервера, а недостижимый адресат ловится только собственным дедлайном на чтение (`references/amphp-vless/examples/19-timeouts-retries.php@065417f:L278-L285`, `references/amphp-vless/examples/18-database.php@065417f:L248-L262`).
- Рекомендации для http-client из примера 19 (`L212-L229`, `L257-L276`): `tcpConnectTimeout` 10–15 с (в него входит весь подъём туннеля: TCP + TLS + upgrade), `tlsHandshakeTimeout` ~20 с (TLS адресата идёт через туннель), `transferTimeout` — по размеру тела, 1–2 повтора, обязательно переиспользовать соединения (пул).
- Стандартный коннектор amphp делает три попытки с экспоненциальной паузой, поэтому дедлайн короче ~14 с срабатывает раньше типизированной ошибки; для быстрого отказа и failover в транспорт передают `DnsSocketConnector` (`references/amphp-vless/examples/14-diagnostics.php@065417f:L377-L381`, `references/amphp-vless/examples/17-failover.php@065417f:L376-L393`).
- В `ProxyServer`: таймаут открытия туннеля 20 с (`DEFAULT_CONNECT_TIMEOUT`), таймаут на «клиент сказал, чего хочет» 10 с, потолок 512 одновременных соединений (`references/amphp-vless/src/Server/ProxyServer.php@065417f:L41-L61`).

### 3.7. Ошибки

Сводка — `references/amphp-vless/examples/14-diagnostics.php@065417f:L362-L375`; классы — `references/amphp-vless/src/Exception/`.

| Исключение | Родитель | Когда | Повторять? |
|---|---|---|---|
| `ConfigException` | `\InvalidArgumentException` | Ошибка в ссылке/параметрах, до любого I/O | нет |
| `UnsupportedFeatureException` | `\InvalidArgumentException` | Vision, REALITY, нереализуемый транспорт, неизвестный режим XHTTP | нет |
| `VlessConnectException` | `Amp\Socket\ConnectException` | VLESS-сервер недостижим (`references/amphp-vless/src/Transport/TcpTransport.php@065417f:L74-L88`) | да / failover |
| `HandshakeException` | `Amp\Socket\ConnectException` | Сервер ответил, но слой отказал; в сообщении назван слой: `tls`, `websocket`, `grpc`, `xhttp`, `vless` (`references/amphp-vless/src/Exception/HandshakeException.php@065417f:L20-L27 (atLayer)`) | да |
| `ProtocolException` | `Amp\Socket\SocketException` | Туннель работал, поток сломался (битый кадр, обрыв внутри сообщения) | да |
| `VlessException` | интерфейс-маркер | Любое из перечисленного | — |
| `Amp\CancelledException` | — | Сработал свой дедлайн; **не** `VlessException` | по ситуации |

Сетевые ошибки наследуют иерархию `amphp/socket`, поэтому код, ловящий `ConnectException`, работает без знания о VLESS. Неверный UUID отдельной ошибки не даёт: сервер просто закрывает поток, клиент получает `HandshakeException` слоя `vless` («connection closed before the response header arrived», `references/amphp-vless/src/Internal/ResponseHeader.php@065417f:L36-L45`) — это вывод из кода, сам случай с сервером не проверялся.

## 4. vless-proxy

**Что это.** Исполняемый файл `vendor/bin/vless-proxy` (`references/amphp-vless/bin/vless-proxy`, 337 строк) — локальный прокси, который принимает обычные прокси-подключения и каждое проводит через VLESS-туннель. Нужен программам не на PHP: `curl`, браузер, `git`, `ssh` (`references/amphp-vless/README.md@065417f:L195-L335`).

**Двери.**

| Дверь | Класс | Что умеет |
|---|---|---|
| SOCKS5 (`--socks[=addr]`, по умолчанию `127.0.0.1:1080`) | `references/amphp-vless/src/Server/Socks5ProxyServer.php@065417f:L34-L286` | Только `CONNECT`; `BIND` и `UDP ASSOCIATE` отклоняются кодом 0x07 (`L83-L88`). Адреса IPv4/IPv6/домен. Любой TCP-протокол |
| HTTP (`--http[=addr]`, по умолчанию `127.0.0.1:8080`) | `references/amphp-vless/src/Server/HttpProxyServer.php@065417f:L36-L350` | `CONNECT` (HTTPS и любой TCP) и запросы в абсолютной форме для обычного HTTP; на plain-HTTP — один обмен на соединение (`Connection: close`, `L286-L289`); hop-by-hop заголовки, включая `Proxy-Authorization`, адресату не пересылаются |

Если не задана ни одна дверь — поднимается SOCKS5 на адресе по умолчанию (`references/amphp-vless/bin/vless-proxy@065417f:L221-L244`).

**Параметры** (`references/amphp-vless/bin/vless-proxy@065417f:L37-L88 (USAGE)`): `--uri`, `--socks[=addr]`, `--http[=addr]`, `--user`, `--password`, `--timeout=<сек>` (20), `--quiet`, `--help`. Каждому соответствует переменная окружения: `VLESS_URI` (также принимается `VLESS_TEST_URI`), `VLESS_PROXY_SOCKS`, `VLESS_PROXY_HTTP`, `VLESS_PROXY_USER`, `VLESS_PROXY_PASSWORD`, `VLESS_PROXY_TIMEOUT`, `VLESS_PROXY_QUIET`; у каждой есть форма `<ИМЯ>_FILE` — значение читается из файла (Docker/Kubernetes secrets), хвостовые переводы строк срезаются (`L134-L157 (environment)`). Опции командной строки главнее окружения. `.env` не читается.

**Авторизация.** Выключена, пока не заданы **оба** — логин и пароль; половина пары — отказ на старте (`references/amphp-vless/src/Server/ProxyCredentials.php@065417f:L49-L64 (fromPair)`). Действует на обе двери: SOCKS5 — метод username/password (RFC 1929), HTTP — `Proxy-Authorization: Basic` и ответ `407`. Сравнение за постоянное время (`L73-L84 (matches)`). После неверной попытки — задержка, удваивающаяся по адресу источника: 0,25 с … до 8 с, сброс при успехе (`references/amphp-vless/src/Server/ProxyServer.php@065417f:L200-L227 (punish, succeeded)`). Всё, что пришло от клиента и попадает в лог, экранируется (`L250-L259 (printable)`).

**Предупреждения на старте:** `allowInsecure` в ссылке → «CERTIFICATE VERIFICATION IS OFF»; прослушивание не на loopback без учётных данных → «OPEN PROXY» (`references/amphp-vless/bin/vless-proxy@065417f:L291-L318`). Это предупреждения в stderr, запуск не блокируется.

**Docker** (`references/amphp-vless/README.md@065417f:L337-L384`):
- `references/amphp-vless/Dockerfile@065417f:L29-L91`: двухстадийная сборка, `composer install --no-dev`, база `php:8.3-cli-alpine` + `pcntl` (для корректной остановки по SIGTERM), `USER nobody`, `ENV VLESS_PROXY_SOCKS=0.0.0.0:1080`, `EXPOSE 1080 8080`, `ENTRYPOINT ["php", "bin/vless-proxy"]`. Базовые образы — аргументы сборки, чтобы закрепить по digest.
- `references/amphp-vless/docker-compose.example.yml@065417f:L16-L148`: сервис `vless-proxy`, порты опубликованы на `127.0.0.1`, `read_only`, `no-new-privileges`, `cap_drop: ALL`, лимиты `mem_limit: 256m`, `pids_limit: 256`, `cpus: 1.0`, healthcheck, закомментированный блок `secrets`.
- Готового образа в реестре нет: строка `image: ghcr.io/rekryt/amphp-vless:latest` закомментирована с пометкой «once the image is published» (`L19-L20`) — образ собирается из исходников.

**Встраивание** (`references/amphp-vless/README.md@065417f:L386-L402`): обе двери — обычные классы.

```php
$server = new Socks5ProxyServer(new VlessConnector($config), $logger, 20.0, $credentials);
$server->listen('127.0.0.1:1080');   // возвращается сразу; процесс удерживает вызывающий
```

Конструктор — `references/amphp-vless/src/Server/ProxyServer.php@065417f:L86-L93`: `VlessConnector $connector`, `LoggerInterface $logger`, `float $connectTimeout`, `?ProxyCredentials $credentials`, `float $negotiationTimeout`, `int $maxConnections`. Методы: `listen()` (`L108-L181`), `stop()`, `isListening()`, `address()`. Тип первого аргумента — именно `VlessConnector`, а не `SocketConnector`: декоратор с маршрутизацией или failover напрямую не передать (заключение примера 17, `references/amphp-vless/examples/17-failover.php@065417f:L395-L396`, говорит, что `FailoverConnector` подходит и к «local proxy inbounds» без адаптации — по сигнатуре это не так; моё наблюдение).

Ещё две формы — в примерах: проброс TCP-порта (`examples/13-tcp-port-forward.php`) и обратный прокси (`examples/12-reverse-proxy.php`, не читал).

## 5. Чего нельзя

Раздел README «What cannot be supported» — `references/amphp-vless/README.md@065417f:L140-L193`.

**Две возможности VLESS в PHP недостижимы структурно:**

1. **`flow: xtls-rprx-vision`.** Vision нужен доступ к буферам TLS-записей и к «голому» сокету под установленной TLS-сессией. Эталонная реализация на Go достаёт их рефлексией и небезопасной арифметикой указателей из приватных полей `crypto/tls.Conn`. В PHP TLS — потоковый фильтр внутри OpenSSL, ни расшифрованные буферы, ни сокет под ним недоступны.
2. **REALITY.** Клиент должен собрать TLS ClientHello, остановиться до отправки, переписать байты по фиксированному смещению и продолжить рукопожатие. Даже Go-шный `crypto/tls` этого не умеет — эталон использует форк uTLS. В PHP это означало бы писать TLS 1.3 с нуля.

Оба случая отвергаются при конфигурации с объяснением (`UnsupportedFeatureException`), а не подменяются обычным TLS: пользователь, считающий трафик замаскированным, не должен оставаться в этом заблуждении.

**Цена:**

- **Без Vision трафик — это TLS внутри TLS** (внешняя сессия с сервером, внутренняя с адресатом). Двойная обёртка видна анализу трафика по размерам записей и таймингам независимо от содержимого.
- **Без REALITY нужен настоящий домен с настоящим сертификатом**, и сервер можно опознать как прокси активным зондированием — именно это REALITY скрывает, отдавая неопознанному клиенту чужой настоящий сайт.
- Гипотетическая самописная реализация не помогла бы: защита REALITY держится на том, что ClientHello побайтно неотличим от браузерного; у PHP с OpenSSL отпечаток OpenSSL, а у самописного стека он был бы уникальным — маяк, а не маскировка.

**Для чего библиотека годится:**

| Сценарий | Годится |
|---|---|
| API заблокирован по адресу или домену | да |
| БД или кеш, принимающие соединения только из VPN | да |
| Блокировка по сигнатуре протокола | да |
| DPI со статистическим анализом трафика | **нет** — нужен sing-box или xray |

Последняя строка сформулирована как граница, а не оговорка: против активного анализа трафика единственным ответом остаётся внешний процесс, которого проект как раз избегает.

**Практическое следствие:** сервер, выдающий только ссылки вида `security=reality` и/или `flow=xtls-rprx-vision`, этой библиотекой использовать нельзя вообще — нужен отдельный inbound (например `ws`/`grpc`/`xhttp` + TLS с настоящим сертификатом) либо внешний `xray`/`sing-box`.

Кроме того, не реализованы (см. п. 3.4, 3.5): транспорты `mkcp`, `quic`, `h3`, `http`/`h2`, `hysteria`; Mux; UDP-режимы `xudp`/`packetaddr`; в SOCKS5-входе — `BIND` и `UDP ASSOCIATE`.

## 6. Что это даёт Меркурию

Общий вывод: единицей сетевого слоя агента удобно сделать `Amp\Socket\SocketConnector`. «Маршрут» (напрямую / HTTP-прокси / SOCKS5 / VLESS / VLESS через прокси) — это фабрика, возвращающая коннектор; HTTP-клиент модели, HTTP-инструменты, SSH и драйверы БД получают коннектор и о маршруте не знают. Так устроены и примеры библиотеки (split-tunnel, failover, chaining — декораторы `SocketConnector`).

Важно: **amphp-vless не содержит клиентских коннекторов для HTTP- и SOCKS5-прокси.** В ней есть серверы SOCKS5/HTTP (двери `vless-proxy`), а исходящие соединения через чужой HTTP/SOCKS5-прокси (требование BRIEF «HTTP/HTTPS/SOCKS5-прокси») — отдельная задача: пример 22 для HTTP-прокси ссылается на `Http1TunnelConnector` из `amphp/http-tunnel` (`references/amphp-vless/examples/22-chaining.php@065417f:L195-L199`); SOCKS5-клиент надо искать или писать.

### (а) Вызовы API модели через VLESS — фаза 2

- **Берём как есть:** `VlessConfig::fromUri()` + `VlessConnector` + `HttpClientBuilder->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory($connector)))`. Потоковый ответ модели — это чтение тела по мере поступления; README заявляет потоковое чтение без накопления (`examples/07-streaming.php`, файл не читал).
- **Настраиваем:** таймауты по примеру 19 (п. 3.6); один долгоживущий клиент с пулом на маршрут — каждое новое соединение платит полный подъём туннеля; свой дедлайн на «первый байт ответа», потому что недостижимый адресат даёт тишину.
- **Дописываем:** (1) адаптер «настройка маршрута → `SocketConnector`» с кешированием клиента по маршруту; (2) политику повторов — не повторять `ConfigException`/`UnsupportedFeatureException`, остальное повторять с джиттером (образец — `references/amphp-vless/examples/20-worker.php@065417f:L72-L95`); (3) потолок одновременных туннелей (`LocalSemaphore`, там же `L135`); (4) при нескольких серверах — failover-декоратор по образцу примера 17; (5) валидацию ссылки при сохранении настроек с понятным сообщением владельцу, если ссылка с REALITY/Vision; (6) хранение `vless://` как секрета и маскирование в трассах — ссылка содержит UUID, а сообщения исключений содержат адрес сервера и SNI.
- **Ограничение:** канал до провайдера модели будет «TLS в TLS» (п. 5). Если сеть сервера режет трафик статистическим DPI — библиотека не поможет, нужен внешний xray/sing-box, и тогда агент ходит через него как через обычный SOCKS5/HTTP-прокси.

### (б) HTTP-запросы инструментов и браузер — фазы 3, 8

- **HTTP-инструмент агента:** тот же коннектор, что и в (а). Полезно сразу взять идею split-tunnel (`references/amphp-vless/examples/16-split-tunnel.php@065417f:L49-L145 (RoutingConnector)`): локальные и приватные адреса — всегда напрямую, остальное — по правилу; «по умолчанию в туннель», чтобы забытый хост не утёк мимо.
- **Моё наблюдение (безопасность):** при маршруте через VLESS имя резолвит удалённый сервер, значит агент не видит итоговый IP и не может проверить его на приватные диапазоны; `127.0.0.1` и `10.x` на том конце означают сеть VLESS-сервера. Защита от SSRF для HTTP-инструмента в этом режиме возможна только по имени хоста и политике, а не по резолву.
- **Chrome в соседнем контейнере: да, прокси ему дать можно.** `vless-proxy` поднимается отдельным сервисом compose из `Dockerfile` референса; Chrome получает `--proxy-server=socks5://vless-proxy:1080` или `http://vless-proxy:8080`. Что учесть:
  - Порты наружу не публиковать, сервис держать во внутренней сети compose вместе с контейнером браузера. Внутри контейнера привязка — `0.0.0.0`, поэтому без учётных данных процесс при каждом старте будет печатать «OPEN PROXY» — это предупреждение, а не отказ.
  - С авторизацией: HTTP-дверь отвечает `407` + `Basic` — Puppeteer умеет отвечать на это через `page.authenticate()`. Про SOCKS5 с логином в Chrome: насколько мне известно, Chrome не поддерживает авторизацию SOCKS5 — **это знание не из репозитория, проверить перед выбором двери.**
  - SOCKS5-дверь обслуживает только TCP `CONNECT`: UDP (QUIC/HTTP3, WebRTC) через прокси не пойдёт. Для целевых сайтов это означает откат на HTTP/2 поверх TCP; утечка UDP мимо прокси должна быть закрыта на стороне контейнера браузера (вывод из кода, на практике не проверял).
  - Потолок 512 соединений и 256 МБ в шаблоне compose — для одного браузера с запасом, но каждая вкладка открывает десятки соединений, и каждое — отдельный туннель до VLESS-сервера.
  - Альтернатива отдельному контейнеру — поднять `Socks5ProxyServer`/`HttpProxyServer` внутри процесса ядра (п. 4, «Встраивание»). Отдельный контейнер изолирует нагрузку браузера от агентского цикла; я бы начинал с него.
- **Прочие не-PHP инструменты** (git, curl в песочнице): тот же `vless-proxy` через `http_proxy`/`https_proxy` или `--socks5-hostname` (README `L289-L310`).

### (в) SSH поверх VLESS вместе с amphp-ssh — фаза 7

Стыковка уже есть с обеих сторон и показана примером:

- `Amp\Ssh\connect()` принимает необязательный `?SocketConnector $connector` и использует его вместо процессного — `references/amphp-ssh/src/functions.php@9d171ac:L37-L46, L54 (connect)`.
- `VlessConnector` — это `SocketConnector`; пример: `references/amphp-vless/examples/27-ssh-tunnel.php@065417f:L142-L151`:

```php
$ssh = \Amp\Ssh\connect(
    'tcp://host:22',
    $authentication,
    cancellation: new TimeoutCancellation(30),
    hostKeyVerifier: $knownHosts,        // в примере AcceptAnyHostKey — в Меркурии так нельзя
    connector: new VlessConnector($config),
);
```

- SSH-сервер говорит первым; `VlessSocket::read()` отправляет заголовок VLESS до первого чтения, так что взаимной блокировки нет (`references/amphp-vless/src/Internal/VlessSocket.php@065417f:L62-L66`). Сам `amphp-ssh` к тому же первым пишет свою строку идентификации (`references/amphp-ssh/src/functions.php@9d171ac:L57`).
- Проверка ключа хоста работает как обычно: `amphp-ssh` берёт хост и порт из URI, а не из адреса сокета (`L71-L72`).
- В `amphp-vless` пакет `amphp/ssh` — dev-зависимость (`dev-master` из VCS `rekryt/amphp-ssh`, `references/amphp-vless/composer.json@065417f:L39, L76-L81`); в Меркурии оба пакета ставятся как обычные зависимости.
- **Берём как есть:** саму связку; коннектор передаём параметром, а не ставим глобально (вывод примера 27, `L290-L294`).
- **Дописываем:** выбор маршрута на уровне записи сервера в реестре (напрямую / прокси / VLESS-профиль); проверку ключа хоста через свой `KnownHosts`, а не `AcceptAnyHostKey`; дедлайны на команды; понятную ошибку «SSH-сервер недостижим с VLESS-сервера» — она придёт как обрыв при чтении баннера (у `amphp-ssh` это `ServerIdentificationException::connectionClosed`, `L153-L155`), а не как отказ соединения.
- **Условие:** SSH-сервер должен быть достижим **с VLESS-сервера**; на SSH-сервере источником соединения будет адрес VLESS-сервера (firewall, `AllowUsers`, fail2ban считают по нему).
- Возможна и обратная вложенность: `SshResource::createTunnel()` возвращает `Socket`, но TLS на нём недоступен (README amphp-ssh `L90-L93`) — для HTTPS через SSH-туннель потребуется мост вроде `BridgedSocket`. Сам `BridgedSocket` — `@internal`, опираться на него напрямую нельзя.

### Чего библиотека для Меркурия не закрывает

- Исходящие через HTTP/SOCKS5-прокси как клиент (см. выше).
- `ping`: ICMP через VLESS не ходит (протокол несёт только TCP и UDP-датаграммы) — диагностику «через туннель» придётся делать TCP-пробой; это мой вывод из устройства протокола.
- Серверы только с REALITY/Vision.

## 7. Ограничения и риски

**Зрелость.**
- Версия `v1.1.1`, коммит от 2026-08-06, в `composer.json` один автор (судя по имени аккаунта GitHub — владелец проекта Меркурий). Сабмодуль поверхностный (`--depth 1`), история коммитов мне не видна.
- Готового Docker-образа в реестре нет (п. 4).
- Реализация протокола сделана по чтению `sing-vmess`/`sing-box` с перекрёстной проверкой по `Xray-core`; архитектура коннектора — по `amphp/http-tunnel` (README `L481-L488`).

**Тесты.**
- Юнит-тесты: 25 файлов, 281 тест-метод (посчитано по `function test`); E2E: 6 файлов, 27 методов, требуют реальный сервер в `.env` и сами пропускаются без него. **Я тесты не запускал и тела тестов не читал** (кроме шапки `NetworkTestCase`).
- Кодек проверяется по эталонным байтовым векторам, сгенерированным Go-кодом апстрима (`tools/gen-vectors`, пин на ревизию `sing-vmess` из sing-box 1.13.16) — README `L475-L479`.
- CI: PHP 8.2/8.3/8.4, `composer test` + psalm; отдельная задача «новейшие зависимости» (может падать, не блокируя); задача-страж, что в production-дереве нет `amphp/http-client`, `http-server`, `websocket-client`.
- В шапке `NetworkTestCase` автор фиксирует ~4 % потерь соединений на своей сети и один повтор «на полную тишину» в E2E (`references/amphp-vless/test/E2E/NetworkTestCase.php@065417f:L18-L47`) — напоминание, что «пустой ответ» от туннеля нужно уметь повторять.

**Зависимости.**
- Runtime: шесть пакетов `amphp/*` + `psr/log` + `ext-openssl`; HTTP-клиента в runtime нет намеренно. Для `vless-proxy` в Docker дополнительно ставится `pcntl`.
- **npm-часть:** `package.json` объявляет только `prettier` и `@prettier/plugin-php` со скриптами `format`/`format-check` для `src` и `examples`; в описании прямо сказано: «Formatter toolchain only. The package itself is pure PHP and needs no Node» (`references/amphp-vless/package.json@065417f:L2-L12`). На установку и работу библиотеки не влияет.
- `tools/gen-vectors` — отдельный Go-модуль, нужен только для перегенерации фикстур.

**Известные ограничения (из документации и кода).**
- Нет Vision, REALITY, Mux, mkcp/quic/h3, xudp (п. 5).
- Нет подтверждения достижимости адресата — недостижимый адресат выглядит как тишина (п. 3.6).
- Каждый `connect()` — новый туннель; латентность первого запроса кратно выше прямой (пример 19), HTTPS добавляет второе TLS-рукопожатие через туннель.
- UDP — только «подключённый» сокет к одному адресату, поверх TCP.
- HTTP-дверь на plain-HTTP не держит keep-alive.
- Защита от перебора пароля прокси не переживает рестарт и не рассчитана на распределённый перебор (так и написано в коде, `references/amphp-vless/src/Server/ProxyServer.php@065417f:L196-L198`).

**Мои наблюдения по коду** (не из документации; проверить перед тем, как на них опираться):

1. **Ссылка gRPC с `mode=gun`/`mode=multi` отвергается.** `fromUri()` вызывает `XhttpSettings::fromQuery()` для любого транспорта (`references/amphp-vless/src/VlessConfig.php@065417f:L230`), а тот принимает в `mode` только режимы XHTTP (`references/amphp-vless/src/Internal/Xhttp/XhttpSettings.php@065417f:L53-L59, L132-L146`). Проверено запуском `XhttpSettings::fromQuery(['mode' => 'gun'])` на PHP 8.2: `UnsupportedFeatureException: Unknown XHTTP mode "gun"`. То, что панели кладут `mode=gun` в gRPC-ссылки, — моё знание извне репозитория. Обход: убирать `mode` из gRPC-ссылок перед разбором или собирать конфиг через `fromArray()`/конструктор.
2. **`fromArray()` не читает настройки XHTTP** (режим, padding) — аргумент `xhttp` не передаётся (`L271-L285`), значит через массив XHTTP всегда `packet-up` с умолчаниями; режим доступен только через ссылку или третьим аргументом `XhttpTransport`.
3. **`fromArray()` отвергает любой ключ `tls.reality`**, даже `{"enabled": false}`: проверка — `isset($tls['reality'])` (`L262`).
4. **ALPN для gRPC/XHTTP не выставляется автоматически.** `TcpTransport` задаёт ALPN, только если он есть в конфиге (`references/amphp-vless/src/Transport/TcpTransport.php@065417f:L141-L143`). Если сервер или reverse-proxy требует `h2` в ALPN, а в ссылке нет `alpn=h2`, HTTP/2 может не согласоваться. На практике не проверял.
5. **`ConnectContext`, переданный в `VlessConnector::connect()`, используется только как признак «нужен TLS до адресата»** (`references/amphp-vless/src/VlessConnector.php@065417f:L69, L91`): в транспорт уходит лишь `Cancellation`, а таймаут соединения, `bindTo` и т. п. из контекста вызывающего не применяются. Контекст для соединения с VLESS-сервером задаётся при создании транспорта (`transportFor($config, $connector, $context)`).
6. **Отмена не достаёт до некоторых мест HTTP/2-транспортов.** Цикл чтения соединения запущен без `Cancellation` (`references/amphp-vless/src/Internal/Http2/Http2Connection.php@065417f:L115-L127`); в XHTTP `packet-up` каждый `write()` — отдельный POST с ожиданием ответа без `Cancellation` (`references/amphp-vless/src/Internal/Xhttp/XhttpSocket.php@065417f:L90-L119 (post)`), ожидание окна отправки тоже без него (`Http2Connection.php L247-L268 (awaitWindow)`). Зависший сервер может подвесить `write()`; страховка — закрывать сокет по собственному таймеру.
7. **`packet-up` дорог на запись:** один HTTP-запрос на каждый `write()` с ожиданием ответа. Для интерактивных протоколов (SSH) это заметная латентность; при выборе inbound для Меркурия предпочтительнее `ws`, `grpc` или потоковые режимы XHTTP.
8. **Keepalive нет:** клиент HTTP/2 отвечает на PING, но сам не шлёт (`Http2Connection.php L457-L463`); в WebSocket-обёртке — так же. Долгие простаивающие соединения (SSH-сессия, пул к API) держатся на таймаутах промежуточных узлов.
9. **TLS до адресата стоит loopback-пары на соединение:** на каждое HTTPS-соединение на миг открывается слушатель `127.0.0.1:0` (с проверкой, что принят именно свой клиент), живут два лишних сокета и два `pipe`-файбера (`references/amphp-vless/src/Internal/BridgedSocket.php@065417f:L61-L93, L138-L153`). `getRemoteAddress()` такого сокета возвращает адрес VLESS-сервера, а не адресата (`L115-L117`) — учитывать в логах и трассах.
10. **Healthcheck в шаблоне compose проверяет только открытый локальный порт** (`fsockopen('127.0.0.1', 1080)`), хотя комментарий обещает проверку «туннель действительно поднят» (`references/amphp-vless/docker-compose.example.yml@065417f:L123-L137`). Для Меркурия нужен свой healthcheck сквозным запросом через прокси.
11. **`ProxyServer` принимает именно `VlessConnector`**, а не `SocketConnector` (`references/amphp-vless/src/Server/ProxyServer.php@065417f:L86-L87`): встроить двери поверх маршрутизирующего/failover-коннектора без правки нельзя.
12. **Параметр `fp` (отпечаток TLS) молча игнорируется** — ожидаемо (отпечаток всегда OpenSSL), но ссылка с `fp=chrome` не вызовет ни ошибки, ни предупреждения.
13. В `HttpProxyServer` порт из `CONNECT host:port` приводится `(int)` без проверки диапазона (`references/amphp-vless/src/Server/HttpProxyServer.php@065417f:L311, L320`), а текст 502-ответа клиенту включает сообщение исключения (с адресом VLESS-сервера, `L198-L202`). При внутреннем использовании некритично.

## 8. Что читать под вопрос

| Вопрос | Файл |
|---|---|
| Как сделать HTTP/HTTPS-запрос через VLESS | [examples/02-http-client.php](../references/amphp-vless/examples/02-http-client.php), [README.md](../references/amphp-vless/README.md) (L36–L57) |
| Как получить произвольное TCP-соединение | [src/VlessConnector.php](../references/amphp-vless/src/VlessConnector.php) (L62–L96), [examples/01-tcp-raw.php](../references/amphp-vless/examples/01-tcp-raw.php) |
| Как разбирается `vless://`-ссылка, какие параметры понимаются | [src/VlessConfig.php](../references/amphp-vless/src/VlessConfig.php) (L169–L233), [src/Internal/Xhttp/XhttpSettings.php](../references/amphp-vless/src/Internal/Xhttp/XhttpSettings.php) |
| Что будет отвергнуто и почему | [src/VlessConfig.php](../references/amphp-vless/src/VlessConfig.php) (L342–L404), [src/Exception/UnsupportedFeatureException.php](../references/amphp-vless/src/Exception/UnsupportedFeatureException.php), [README.md](../references/amphp-vless/README.md) (L140–L193) |
| Какой транспорт как устроен | [src/Transport/](../references/amphp-vless/src/Transport/), для HTTP/2 — [src/Internal/Http2/Http2Connection.php](../references/amphp-vless/src/Internal/Http2/Http2Connection.php) |
| Как работает HTTPS внутри туннеля (TLS до адресата) | [src/Internal/BridgedSocket.php](../references/amphp-vless/src/Internal/BridgedSocket.php) |
| SSH поверх VLESS | [examples/27-ssh-tunnel.php](../references/amphp-vless/examples/27-ssh-tunnel.php), [../references/amphp-ssh/src/functions.php](../references/amphp-ssh/src/functions.php) |
| VLESS через прокси или другой туннель | [examples/22-chaining.php](../references/amphp-vless/examples/22-chaining.php), [src/Transport/TcpTransport.php](../references/amphp-vless/src/Transport/TcpTransport.php) |
| Часть трафика в туннель, часть напрямую | [examples/16-split-tunnel.php](../references/amphp-vless/examples/16-split-tunnel.php) |
| Несколько серверов, failover | [examples/17-failover.php](../references/amphp-vless/examples/17-failover.php) |
| Какие ставить таймауты и повторы | [examples/19-timeouts-retries.php](../references/amphp-vless/examples/19-timeouts-retries.php), [examples/06-cancellation.php](../references/amphp-vless/examples/06-cancellation.php) |
| Долгоживущий процесс: бэкофф, потолок параллелизма, что повторять | [examples/20-worker.php](../references/amphp-vless/examples/20-worker.php) |
| Какое исключение что значит | [examples/14-diagnostics.php](../references/amphp-vless/examples/14-diagnostics.php) (L362–L381), [src/Exception/](../references/amphp-vless/src/Exception/) |
| Бот Telegram через туннель | [examples/25-telegram.php](../references/amphp-vless/examples/25-telegram.php) |
| БД/Redis через туннель | [examples/18-database.php](../references/amphp-vless/examples/18-database.php) |
| UDP/DNS через туннель | [src/Udp/VlessDatagramSocket.php](../references/amphp-vless/src/Udp/VlessDatagramSocket.php), [examples/09-dns-udp.php](../references/amphp-vless/examples/09-dns-udp.php) |
| Параметры и поведение `vless-proxy` | [bin/vless-proxy](../references/amphp-vless/bin/vless-proxy), [README.md](../references/amphp-vless/README.md) (L195–L335) |
| Прокси в Docker рядом с другими сервисами | [Dockerfile](../references/amphp-vless/Dockerfile), [docker-compose.example.yml](../references/amphp-vless/docker-compose.example.yml) |
| Встроить SOCKS5/HTTP-вход в своё приложение | [src/Server/ProxyServer.php](../references/amphp-vless/src/Server/ProxyServer.php), [examples/11-http-proxy-server.php](../references/amphp-vless/examples/11-http-proxy-server.php) |
| Авторизация прокси и защита от перебора | [src/Server/ProxyCredentials.php](../references/amphp-vless/src/Server/ProxyCredentials.php), [src/Server/ProxyServer.php](../references/amphp-vless/src/Server/ProxyServer.php) (L200–L227) |
| Формат заголовков VLESS на проводе | [src/Internal/RequestHeader.php](../references/amphp-vless/src/Internal/RequestHeader.php), [src/Internal/Address.php](../references/amphp-vless/src/Internal/Address.php), [test/fixtures/vectors.json](../references/amphp-vless/test/fixtures/vectors.json) |
| Чтение списка подписки | [examples/23-subscription.php](../references/amphp-vless/examples/23-subscription.php) (не читал) |

## 9. Оценка

Первоисточник с кодом, написанный под тот же стек, что и Меркурий (PHP 8.2+, AMPHP v3), и тем же автором. Для сетевого слоя агента это готовый строительный блок: интеграция через `SocketConnector` совпадает с тем, как подключаются `amphp/http-client` и `amphp-ssh`, а примеры 14–27 по сути описывают эксплуатационные решения (таймауты, повторы, failover, split-tunnel), которые Меркурию всё равно понадобятся. Главные ограничения — принципиальные (нет REALITY/Vision, нет подтверждения достижимости адресата) и одно практическое (нет клиентских коннекторов HTTP/SOCKS5-прокси). Наблюдения из раздела 7 стоит проверить на реальном сервере до фазы 2.

## 10. Что не прочитано

- **README.ru.md** — сверены только заголовки разделов (совпадают с английским README); текст не читал, конспект опирается на `README.md`.
- **Примеры:** целиком прочитаны `bootstrap.php`, 01, 02, 06, 13, 16, 19, 20, 22, 27; у 10, 14, 15, 17, 18, 25 — только шапка и заключительные выводы; **не читал** 03, 04, 05, 07, 08, 09, 11, 12, 21, 23, 24, 26 (знаю о них из таблицы в README).
- **Тесты:** `test/Unit/*`, `test/E2E/*`, `test/Support/*` — только имена файлов, число тест-методов и шапка `NetworkTestCase`; тела не читал, тесты не запускал.
- **tools/gen-vectors/main.go** — первые 60 строк из 252; `go.mod` — начало; `go.sum` не читал. `test/fixtures/vectors.json` — первые 30 строк.
- **composer.lock, package-lock.json, LICENSE, .gitattributes, .editorconfig, .dockerignore, .gitignore, .prettierignore** — не читал.
- **amphp-ssh** — только `README.md` (L14–L112) и `src/functions.php`; полноценный конспект репозитория — отдельная задача.
- Ничего не проверялось на реальном VLESS-сервере: все утверждения о поведении в сети — из кода и документации.
