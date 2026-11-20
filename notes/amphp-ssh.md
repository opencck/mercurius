---
title: "amphp-ssh — конспект репозитория (асинхронный SSH-клиент для PHP на Amp)"
type: repository
source: https://github.com/rekryt/amphp-ssh
local: references/amphp-ssh/
commit: 9d171ac
reviewed: 2026-11-16
---

# amphp-ssh — конспект

> Конспект составлен по локальному клону `references/amphp-ssh/` на коммите `9d171ac` (2026-08-06). Код не запускался и тесты не прогонялись — всё ниже получено чтением файлов. Что прочитано целиком, а что только по сигнатурам, расписано в разделе [«Что не прочитано»](#10-что-не-прочитано). Пометка **«наблюдение»** означает мой вывод из кода, которого нет в документации репозитория. Ссылки вида `references/amphp-ssh/src/...@9d171ac:L10-L40 (Имя)` — в формате проекта для `ref:`-комментариев; номера строк сверены с файлами.

## 1. Кратко

`amphp/ssh` 2.0 — клиент SSH-2 на чистом PHP поверх AMPHP v3: протокол реализован с нуля, без libssh2, весь API синхронный по виду и приостанавливает файбер там, где ждёт. Умеет подключаться с проверкой ключа хоста (по умолчанию `known_hosts`), аутентифицироваться паролем, ключом (RSA, ECDSA, Ed25519), сертификатом и через ssh-agent, выполнять команды (`Process`), держать интерактивный shell с PTY (`Shell`) и открывать туннели `direct-tcpip` (`Tunnel`, реализует `Amp\Socket\Socket`). Много каналов мультиплексируются в одном соединении. SFTP, SCP, подсистем, обратного проброса портов, keepalive и переподключения в библиотеке нет. Транспорт подменяется штатно: `connect()` принимает `Amp\Socket\SocketConnector`, поэтому SSH через прокси, VLESS или другой SSH-туннель делается без правок библиотеки.

## 2. Карта репозитория

| Путь | Что внутри |
|------|-----------|
| [README.md](../references/amphp-ssh/README.md) | Установка, требования, базовые примеры, туннели, проверка ключа хоста, таблица алгоритмов, сертификаты, ssh-agent, запуск тестов |
| [MIGRATION.md](../references/amphp-ssh/MIGRATION.md) | Переход 1.x → 2.0; главное — раздел «Behaviour that changed silently» (L168-L292): проверка ключей хоста, запись в закрытый stdin, отмена `join()`, `kill()`, `$cwd` |
| [composer.json](../references/amphp-ssh/composer.json) | Имя пакета `amphp/ssh`, зависимости, автозагрузка трёх `functions.php` |
| [src/functions.php](../references/amphp-ssh/src/functions.php) | `connect()` — единственная точка входа; разбор URI, чтение строки идентификации сервера |
| [src/SshResource.php](../references/amphp-ssh/src/SshResource.php) | Установленное соединение: `createSession()`, `createTunnel()`, `close()` |
| [src/Process.php](../references/amphp-ssh/src/Process.php) | Выполнение команды (`exec`): stdin/stdout/stderr, код выхода, `$cwd`, `$env` |
| [src/Shell.php](../references/amphp-ssh/src/Shell.php) | Интерактивный shell с PTY, изменение размера окна |
| [src/Tunnel.php](../references/amphp-ssh/src/Tunnel.php), [src/TunnelAddress.php](../references/amphp-ssh/src/TunnelAddress.php) | Туннель `direct-tcpip` как `Amp\Socket\Socket`; адрес с именем хоста вместо IP |
| [src/Authentication/](../references/amphp-ssh/src/Authentication/) | Интерфейс `Authentication` и методы: `UsernamePassword`, `PublicKey`, `AgentAuthentication`; загрузка ключей (PEM, `openssh-key-v1`), сертификаты, клиент ssh-agent |
| [src/HostKey/](../references/amphp-ssh/src/HostKey/) | `HostKeyVerifier`, `KnownHosts`, `AcceptAnyHostKey`; проверка подписи ключа хоста, сертификаты хоста |
| [src/Channel/](../references/amphp-ssh/src/Channel/) | Каналы (`Channel`, `Session`, `DirectTcpIp`), диспетчер входящих пакетов, адаптеры каналов к потокам, исключения каналов |
| [src/Negotiator.php](../references/amphp-ssh/src/Negotiator.php) | Согласование алгоритмов, обмен ключами, вывод сеансовых ключей, повторный обмен (rekey) |
| [src/KeyExchange/](../references/amphp-ssh/src/KeyExchange/) | Curve25519 и группы Диффи — Хеллмана 14/16/18 |
| [src/Encryption/](../references/amphp-ssh/src/Encryption/), [src/Mac/](../references/amphp-ssh/src/Mac/) | Шифры (ChaCha20-Poly1305, AES-GCM, AES-CTR/CBC) и HMAC |
| [src/Transport/](../references/amphp-ssh/src/Transport/) | Бинарный пакетный протокол: чтение и запись пакетов, декодирование сообщений, логирование, шлюз на время rekey, функции чтения полей |
| [src/Message/](../references/amphp-ssh/src/Message/) | 40 классов сообщений SSH (кодирование и разбор) |
| [src/Internal/](../references/amphp-ssh/src/Internal/) | DER, ECDSA, таблица сигналов |
| [examples/](../references/amphp-ssh/examples/) | 13 примеров; перечень с пояснениями — [examples/README.md](../references/amphp-ssh/examples/README.md). Параметры берутся из переменных окружения ([bootstrap.php](../references/amphp-ssh/examples/bootstrap.php)) |
| [tests/](../references/amphp-ssh/tests/) | PHPUnit 9: модульные тесты без сервера и интеграционные, которые пропускаются без `SSH_LOCAL_HOST` |
| [docker/](../references/amphp-ssh/docker/) | Тестовые sshd: `legacy.Dockerfile` (Alpine 3.6, старые алгоритмы), `modern.Dockerfile` (Alpine 3.21, настройки по умолчанию), `setup-test-sshd.sh` для CI |
| [.github/workflows/ci.yml](../references/amphp-ssh/.github/workflows/ci.yml) | Модульные тесты на PHP 8.1–8.4, проверка стиля, интеграционные в трёх режимах: legacy, modern, rekey |

## 3. Публичный API

Внутренние классы помечены `@internal`. Публичная поверхность — `connect()`, `SshResource`, `Process`, `Shell`, `Tunnel`, классы аутентификации и проверки ключа хоста, исключения.

### 3.1. Подключение

`references/amphp-ssh/src/functions.php@9d171ac:L37-L91 (connect)`

```php
function connect(
    string $uri,                              // "host:port", "[v6]:port" или просто host (порт 22)
    Authentication $authentication,
    ?LoggerInterface $logger = null,          // PSR-3; по умолчанию NullLogger
    string $identification = 'SSH-2.0-AmpSSH_0.1',
    ?Cancellation $cancellation = null,
    ?ConnectContext $connectContext = null,
    ?HostKeyVerifier $hostKeyVerifier = null, // по умолчанию new KnownHosts()
    ?SocketConnector $connector = null        // по умолчанию Amp\Socket\socketConnector()
): SshResource
```

- Порядок внутри: TCP-соединение через коннектор → обмен строками идентификации → согласование и обмен ключами → проверка ключа хоста → аутентификация → запуск диспетчера каналов.
- Всё до возврата — «всё или ничего»: при любом исключении или отмене сокет закрывается до выхода из функции (L86-L90).
- `$cancellation` действует только до возврата. Дальше соединение закрывается явным `SshResource::close()`.
- Собственного таймаута нет: если узел принял соединение и молчит, `connect()` ждёт бесконечно. Таймаут задаётся через `cancellation: new TimeoutCancellation(N)` или `ConnectContext::withConnectTimeout()` — [MIGRATION.md](../references/amphp-ssh/MIGRATION.md) L240-L242, [examples/error-handling.php](../references/amphp-ssh/examples/error-handling.php) L74-L80.
- Хост и порт для проверки ключа берутся из `$uri`: `references/amphp-ssh/src/functions.php@9d171ac:L104-L126 (split_authority)`.

`references/amphp-ssh/src/SshResource.php@9d171ac:L16-L90 (SshResource)`

| Метод | Строки | Что делает |
|-------|--------|-----------|
| `createSession(): Session` | L28-L30 | Низкоуровневый канал-сессия; `Session` помечен `@internal` |
| `createTunnel(string $host, int $port, string $originatorHost = '127.0.0.1', int $originatorPort = 0): Tunnel` | L47-L57 | Открывает `direct-tcpip` и возвращает сокет |
| `close(): void` | L66-L81 | Завершает каналы (потребители видят конец потока, а не ошибку), шлёт `SSH_MSG_DISCONNECT`, закрывает сокет; идемпотентен, вызывается из деструктора (L87-L89) |
| `isClosed(): bool` | L83-L85 | `true` только после локального `close()` |

### 3.2. Аутентификация

Интерфейс: `references/amphp-ssh/src/Authentication/Authentication.php@9d171ac:L8-L23 (Authentication)` — один метод `authenticate(BinaryPacketHandler $handler, string $sessionId, ?Cancellation $cancellation = null): void`. В `connect()` передаётся ровно один объект, то есть один метод на соединение.

| Способ | Класс и ссылка | Особенности |
|--------|----------------|-------------|
| Пароль | `references/amphp-ssh/src/Authentication/UsernamePassword.php@9d171ac:L10-L49 (UsernamePassword)` — `new UsernamePassword($username, $password)` | Успех — только `SSH_MSG_USERAUTH_SUCCESS`, всё остальное считается отказом |
| Ключ из файла | `references/amphp-ssh/src/Authentication/PublicKey.php@9d171ac:L15-L169 (PublicKey)` — `new PublicKey($username, $privateKeyPath = '~/.ssh/id_rsa', $passphrase = '', ?string $certificatePath = null)` | Принимает **путь к файлу**, не содержимое ключа. Файл читается через `Amp\File` при аутентификации (L49-L53). Сначала ключ предлагается без подписи, подпись делается только после `PK_OK` (L64-L81) |
| Форматы ключей | `references/amphp-ssh/src/Authentication/PrivateKeyLoader.php@9d171ac:L21-L47 (PrivateKeyLoader::load)`, `references/amphp-ssh/src/Authentication/OpenSshPrivateKey.php@9d171ac:L34-L98 (OpenSshPrivateKey::parse)` | PEM (RSA, EC) через OpenSSL — с парольной фразой; контейнер `openssh-key-v1` (Ed25519, ECDSA) — только незашифрованный. Зашифрованный `openssh-key-v1` отклоняется с подсказкой (L47-L53), ключи `sk-*` — тоже (L83-L92). **Наблюдение:** ветки для RSA внутри `openssh-key-v1` в `parse()` нет (L75-L97) — такой ключ попадёт в «not supported»; RSA работает только в PEM |
| Сертификат пользователя | `references/amphp-ssh/src/Authentication/PublicKey.php@9d171ac:L136-L148 (PublicKey::withCertificate)`, `references/amphp-ssh/src/Authentication/CertifiedKey.php@9d171ac:L19-L61 (CertifiedKey)` | Файл `<ключ>-cert.pub` рядом с ключом подхватывается сам; явный путь — четвёртым аргументом, его отсутствие — ошибка |
| ssh-agent | `references/amphp-ssh/src/Authentication/AgentAuthentication.php@9d171ac:L24-L157 (AgentAuthentication)` — `new AgentAuthentication($username, ?string $comment = null, ?string $address = null, ?Agent $agent = null)`; клиент агента — `references/amphp-ssh/src/Authentication/Agent.php@9d171ac:L52-L73 (Agent::connect)` | Адрес по умолчанию из `SSH_AUTH_SOCK`, unix-сокет. Ключи перебираются по очереди, `$comment` сужает выбор до одного. Единственный путь для зашифрованных `openssh-key-v1` и аппаратных ключей |
| Баннер сервера | `references/amphp-ssh/src/Authentication/ReadsAuthenticationMessages.php@9d171ac:L46-L48 (getBanner)` | Читается с объекта аутентификации после `connect()` |
| Алгоритм подписи RSA | `references/amphp-ssh/src/Authentication/RsaKey.php@9d171ac:L32-L49 (RsaKey::getSignatureAlgorithm)` | По `server-sig-algs` из `SSH_MSG_EXT_INFO`: `rsa-sha2-512` → `rsa-sha2-256` → `ssh-rsa`; без EXT_INFO — `ssh-rsa` |

Не реализовано: `keyboard-interactive`, `hostbased`, `none`, цепочка из нескольких методов (например, ключ + одноразовый код). В коде есть только типы `password` и `publickey`.

### 3.3. Выполнение команд

`references/amphp-ssh/src/Process.php@9d171ac:L15-L229 (Process)`

| Метод | Строки | Поведение |
|-------|--------|-----------|
| `__construct(SshResource $ssh, string $command, ?string $cwd = null, array $env = [])` | L58-L72 | Канал создаётся сразу. `$cwd` экранируется одинарными кавычками и склеивается как `cd '<cwd>' && <command>` (L63-L65, `quote` L86-L88); `~` и `$HOME` в нём не раскрываются. **Сама команда не экранируется — это текст для удалённого shell** |
| `start(): void` | L90-L118 | Открывает канал, шлёт `env` на каждую переменную, затем `exec`. Повторный вызов — `StatusError` |
| `join(?Cancellation $c = null): int\|false` | L130-L144 | Код выхода или `false`, если сервер закрыл канал без статуса. Отмена только отсоединяет вызывающего: процесс продолжает работать, повторный `join()` дождётся того же результата. При обрыве соединения бросает сохранённое исключение |
| `signal(int $signo)`, `kill()` | L146-L156 | Запрос `signal` по RFC 4254. Номер переводится в имя по таблице `references/amphp-ssh/src/Internal/Signals.php@9d171ac:L22-L92 (Signals)`; `ext-pcntl` не нужен |
| `isRunning(): bool` | L158-L160 | Запущен и канал не завершён |
| `getStdin(): WritableStream`, `getStdout()`, `getStderr(): ReadableStream` | L162-L172 | stdout и stderr — отдельные потоки. `getStdin()->end()` шлёт EOF; без него `cat`, `mysql` и подобные ждут вечно |

Важное про поведение:

- **`kill()` и `signal()` — совет серверу, а не гарантия.** По README, MIGRATION (L251-L260) и [examples/timeouts.php](../references/amphp-ssh/examples/timeouts.php) L50-L73, sshd из OpenSSH на этот запрос не реагирует; интеграционный тест `testSignal` помечен пропущенным с той же причиной. Рекомендуемый способ ограничить команду — `timeout N cmd` на стороне сервера.
- Переменные окружения сервер обычно отклоняет (`AcceptEnv` по умолчанию узкий); `Process` при отказе бросает `SessionEnvException`, `Shell` шлёт без ожидания ответа и отказ не замечает — [examples/env-and-cwd.php](../references/amphp-ssh/examples/env-and-cwd.php).
- Ненулевой код выхода — не исключение. Успех проверяется как `=== 0`, потому что возможен `false`.
- Вывод можно читать потоково (`read()`, `ByteStream\splitLines()`), а не только `ByteStream\buffer()` — [examples/streaming-output.php](../references/amphp-ssh/examples/streaming-output.php).
- На одном соединении команды идут и последовательно, и параллельно (`Amp\async` на команду); предел — `MaxSessions` на сервере, по умолчанию 10 — [examples/multiple-commands.php](../references/amphp-ssh/examples/multiple-commands.php).
- **Наблюдение:** у `Process` нет метода закрытия канала. Публичных способов оборвать зависшую команду ровно два: `kill()` (может быть проигнорирован) и `SshResource::close()` — закрытие всего соединения со всеми каналами.
- **Наблюдение:** `Process` обрабатывает только `exit-status` (L189-L191). Сообщение `exit-signal` декодируется (`references/amphp-ssh/src/Message/ChannelRequestExitSignal.php@9d171ac:L14-L58`), но до вызывающего не доходит: команда, убитая сигналом, даёт `join() === false` без имени сигнала.

### 3.4. Интерактивные сессии и PTY

`references/amphp-ssh/src/Shell.php@9d171ac:L15-L182 (Shell)`

| Метод | Строки | Поведение |
|-------|--------|-----------|
| `__construct(SshResource $ssh, array $env = [])` | L39-L47 | |
| `start(int $columns = 80, int $rows = 24, int $width = 800, int $height = 600): void` | L68-L93 | Открывает канал, шлёт `env` без ожидания ответа, затем `pty-req` и `shell` |
| `changeWindowSize(...)` | L95-L101 | Запрос `window-change` |
| `join()`, `signal()`, `kill()`, `isRunning()`, `getStdin()`, `getStdout()`, `getStderr()` | L52-L66, L103-L129 | Как у `Process` |

- PTY запрашивается всегда и только в `Shell`. `Process` псевдотерминал не выделяет — команда с `sudo`, которой нужен tty, через `Process` его не получит.
- Тип терминала задаётся переменной `TERM` в `$env` — [examples/pty-shell.php](../references/amphp-ssh/examples/pty-shell.php) L52-L55. Что уходит в самом `pty-req` (тип терминала, режимы), я не дочитал — см. раздел 10.
- Низкоуровневые запросы — `references/amphp-ssh/src/Channel/Session.php@9d171ac:L16-L79 (Session)`: `env` L21-L33, `signal` L35-L41, `pty` L43-L52, `changeWindowSize` L54-L63, `shell` L65-L70, `exec` L72-L78. Через `SshResource::createSession()` можно собрать «PTY + exec одной команды», но это внутренний API без гарантий совместимости.

### 3.5. Передача файлов

**SFTP и SCP нет.** Константа `TYPE_SUBSYSTEM` объявлена (`references/amphp-ssh/src/Message/ChannelRequest.php@9d171ac:L19`), но класса запроса и метода в `Session` для подсистем нет; поиск по `sftp`, `scp` в `src/`, `examples/`, `tests/`, README ничего не находит.

Что предлагает репозиторий вместо этого — потоковая передача через stdin/stdout команды: [examples/stdin-piping.php](../references/amphp-ssh/examples/stdin-piping.php) L24-L34 (`cat > файл` + `ByteStream\pipe()` + `end()`), проверка размера через `wc -c`. Записи режутся по размеру пакета и окну получателя: `references/amphp-ssh/src/Channel/Channel.php@9d171ac:L266-L283 (Channel::data)`. Большие объёмы в обе стороны покрыты интеграционными тестами `testLargeStdinWrite`, `testLargeStdoutRead`.

### 3.6. Туннели и проброс портов

`references/amphp-ssh/src/Tunnel.php@9d171ac:L33-L199 (Tunnel)`, создаётся через `references/amphp-ssh/src/SshResource.php@9d171ac:L47-L57 (createTunnel)`.

- Это `ssh -L` без локального порта: сервер сам резолвит имя и соединяется с адресом, клиент получает объект `Amp\Socket\Socket`. Его можно отдать любому коду, который принимает сокет.
- Методы: `read(?Cancellation, ?int $limit)` L81-L103, `write()` L112-L114, `end()` L119-L121, `close()` L127-L139, `onClose()` L145-L147, `getRemoteAddress()` / `getLocalAddress()` L152-L165.
- **TLS поверх туннеля недоступен**: `isTlsConfigurationAvailable()` возвращает `false`, `setupTls()` бросает `SocketException` (L180-L198). Причина — PHP включает TLS на ресурсе сокета, а у канала ресурса нет. Через туннель достижим сервис без TLS; HTTPS или БД с обязательным TLS «от нас до цели» так не получить.
- Отказ сервера соединиться приходит как `ChannelException` с причиной от сервера.
- Туннелей может быть несколько, рядом с командами, на одном соединении; закрытие одного не трогает остальные — [examples/tunnel.php](../references/amphp-ssh/examples/tunnel.php).
- Нет: локального слушающего порта, обратного проброса (`tcpip-forward` / `forwarded-tcpip`), динамического SOCKS (`-D`), проброса агента и X11.

### 3.7. Проверка ключа хоста

Две независимые проверки: подпись обмена ключами (всегда, внутри `Negotiator`) и доверие ключу (`HostKeyVerifier`). Проверка доверия идёт **до** аутентификации, так что чужому серверу не уходит ни пароль, ни подпись.

| Что | Ссылка |
|-----|--------|
| Интерфейс: `verify(string $host, int $port, string $format, string $key): void` | `references/amphp-ssh/src/HostKey/HostKeyVerifier.php@9d171ac:L12-L22 (HostKeyVerifier)` |
| `KnownHosts(?string $path = null, bool $rejectUnknown = true)` — формат OpenSSH: имена, `[host]:port`, шаблоны, хешированные `\|1\|`, `@revoked`, `@cert-authority` | `references/amphp-ssh/src/HostKey/KnownHosts.php@9d171ac:L17-L107 (KnownHosts)`; путь по умолчанию — `$HOME` или `%USERPROFILE%` + `/.ssh/known_hosts` (L34-L38) |
| `AcceptAnyHostKey` — отключение проверки, только явно | `references/amphp-ssh/src/HostKey/AcceptAnyHostKey.php@9d171ac:L17-L20 (AcceptAnyHostKey)` |
| Сертификаты хоста: подпись CA, срок, тип, principals | `references/amphp-ssh/src/HostKey/Certificate.php@9d171ac:L184-L231 (Certificate::validate)`, `references/amphp-ssh/src/HostKey/KnownHosts.php@9d171ac:L114-L137 (verifyCertificate)` |
| Проверка подписи (RSA, ECDSA, Ed25519) | `references/amphp-ssh/src/HostKey/HostKeySignature.php@9d171ac:L46-L68 (HostKeySignature::verify)` |

- `new KnownHosts($path, rejectUnknown: false)` — неизвестный хост проходит, изменившийся ключ отклоняется.
- `KnownHosts` только читает файл: записи новых ключей (TOFU) в библиотеке нет.
- Свой верификатор — анонимный класс на десяток строк. Пример закрепления отпечатка `SHA256:...` в формате `ssh-keygen -lf` и «записывающего» верификатора — [examples/host-key-verification.php](../references/amphp-ssh/examples/host-key-verification.php) L37-L85.

### 3.8. Таймауты и отмена

| Где | Что делает `Cancellation` |
|-----|---------------------------|
| `connect(cancellation:)` | Обрывает подключение целиком, сокет закрывается. Покрывает TCP, обмен ключами, проверку хоста, аутентификацию |
| `Process::join()`, `Shell::join()` | Отсоединяет ожидающего, команда продолжает работать |
| `getStdout()->read($c)`, `Tunnel::read($c)` | Отменяет одно чтение (`references/amphp-ssh/src/Channel/ChannelInputStream.php@9d171ac:L34-L52`) |
| Запись в stdin или туннель | Отмены нет: `write()` ждёт окна получателя, пока канал не закроется (`references/amphp-ssh/src/Channel/Channel.php@9d171ac:L295-L306 (awaitRemoteWindow)`) |
| `createTunnel()`, `Process::start()` | Параметра отмены нет; ожидание ответа сервера ограничивается только закрытием соединения |

Встроенных таймаутов (на команду, на простой, keepalive) нет ни одного. Приём «ждать с опросом» — цикл `join(new TimeoutCancellation(1))` — показан в [examples/timeouts.php](../references/amphp-ssh/examples/timeouts.php) L89-L104.

### 3.9. Ошибки

Порядок соответствует стадиям протокола — [examples/error-handling.php](../references/amphp-ssh/examples/error-handling.php) L8-L13.

| Исключение | Когда | Ссылка |
|-----------|-------|--------|
| `Amp\Socket\ConnectException` | Хост не ответил (DNS, порт, файрвол) | из `amphp/socket` |
| `ServerIdentificationException` | Ответил не SSH-сервер: закрыл соединение или прислал больше 65536 байт без строки `SSH-` | `references/amphp-ssh/src/Transport/ServerIdentificationException.php@9d171ac:L15-L32` |
| `HostKeyVerificationException` | Подпись не сошлась или ключ не доверен. Повторять нельзя | `references/amphp-ssh/src/HostKey/HostKeyVerificationException.php@9d171ac:L11-L12` |
| `AuthenticationFailureException` | Сервер отклонил учётные данные; также нет файла ключа, неподдерживаемый формат, нет агента | `references/amphp-ssh/src/Authentication/AuthenticationFailureException.php@9d171ac:L5-L6` |
| `PublicKeyNotAcceptedException` (наследник предыдущего) | Ключ не принят ещё до подписи — его нет в `authorized_keys` или алгоритм запрещён | `references/amphp-ssh/src/Authentication/PublicKeyNotAcceptedException.php@9d171ac:L19-L20` |
| `ChannelException` | Канал не открылся (в том числе отказ туннеля), соединение потеряно во время работы | `references/amphp-ssh/src/Channel/ChannelException.php@9d171ac:L5-L6` |
| `ChannelFailureException` | Сервер ответил `CHANNEL_FAILURE` на запрос | `references/amphp-ssh/src/Channel/ChannelFailureException.php@9d171ac:L8-L20` |
| `SessionEnvException` | Сервер не принял переменную окружения | `references/amphp-ssh/src/Channel/SessionEnvException.php@9d171ac:L5-L6` |
| `StatusError` (наследник `\Error`) | Неверный порядок вызовов: `join()` до `start()`, повторный `start()`, сигнал незапущенному | `references/amphp-ssh/src/StatusError.php@9d171ac:L5-L6` |
| `Amp\ByteStream\ClosedException` | Запись в завершённый stdin или туннель | `references/amphp-ssh/src/Channel/ChannelOutputStream.php@9d171ac:L34-L40` |
| `Amp\CancelledException` | Сработала отмена | из `amphp/amp` |
| `\RuntimeException` без своего класса | Нет общего алгоритма («No common … algorithm» с обоими списками), неверный MAC, неожиданный пакет при обмене ключами | `references/amphp-ssh/src/Negotiator.php@9d171ac:L356-L369 (negotiateName)`, `references/amphp-ssh/src/Transport/PayloadReader.php@9d171ac:L109-L111` |

Общего базового класса у исключений библиотеки нет: три ветки наследуют `\RuntimeException` независимо, `StatusError` — `\Error`. При обрыве соединения данные, которые уже пришли, остаются читаемыми (потоки завершаются, а не бросают), а ожидающие ответа запросы и `join()` получают `ChannelException` — `references/amphp-ssh/src/Channel/Channel.php@9d171ac:L456-L469 (doFail)`. Была ли команда выполнена на сервере, после обрыва неизвестно.

### 3.10. Логирование

В `connect()` передаётся PSR-3 логгер. `references/amphp-ssh/src/Transport/LoggerHandler.php@9d171ac:L16-L76 (LoggerHandler)` пишет на уровне `debug` имена классов входящих и исходящих сообщений и смену шифров, на `warning` — неизвестные номера пакетов. Содержимое пакетов (пароли, данные каналов) в лог не попадает — логируются только имена классов и текст `SSH_MSG_DEBUG` от сервера.

## 4. Как подключиться через свой транспорт

**Можно, штатно.** Восьмой параметр `connect()` — `?Amp\Socket\SocketConnector $connector`. Библиотека вызывает у него ровно один метод и дальше работает только с интерфейсом `Amp\Socket\Socket` (`read`, `write`, `close`):

```php
// ref: references/amphp-ssh/src/functions.php@9d171ac:L54 (connect)
$socket = ($connector ?? \Amp\Socket\socketConnector())->connect($uri, $connectContext, $cancellation);
```

Нижний слой получает именно интерфейс, а не ресурс: `references/amphp-ssh/src/Transport/PayloadHandler.php@9d171ac:L22-L26`, `PayloadReader.php@9d171ac:L27-L32`, `PayloadWriter.php@9d171ac:L22-L26`. TLS, `stream_select` и прочие операции над ресурсом библиотека не использует, поэтому подходит любой объект `Socket`: SOCKS5- или HTTP-CONNECT-туннель, поток VLESS, другой SSH-туннель.

Комментарий автора к параметру (L32-L35): коннектор заменяет глобальный `socketConnector()` только для этого соединения — выбор маршрута через прокси или другой туннель не должен требовать подмены глобального состояния.

Пример из тестов репозитория — коннектор, который перенаправляет соединение:

```php
// ref: references/amphp-ssh/tests/ConnectTest.php@9d171ac:L196-L223 (testGivenConnectorMakesTheConnection)
$connector = new class($address) implements Socket\SocketConnector {
    public ?string $requestedUri = null;

    public function __construct(private readonly string $address) {
    }

    public function connect(
        Socket\SocketAddress|string $uri,
        ?Socket\ConnectContext $context = null,
        ?Cancellation $cancellation = null
    ): Socket\Socket {
        $this->requestedUri = (string) $uri;

        return (new Socket\DnsSocketConnector())->connect($this->address, $context, $cancellation);
    }
};

connect('ssh.example.invalid:22', new UsernamePassword('root', 'root'), null, 'SSH-2.0-AmpSSH_0.1',
    new TimeoutCancellation(0.3), null, null, $connector);
```

Что из этого следует для маршрутов Меркурия:

| Маршрут | Как собрать |
|---------|-------------|
| Напрямую | `connect($uri, $auth, ...)` без коннектора |
| SOCKS5 / HTTP CONNECT | Свой `SocketConnector`: соединяется с прокси, выполняет рукопожатие, возвращает сокет. В `amphp-ssh` готового нет |
| VLESS | В `references/amphp-vless/src/VlessConnector.php` есть класс, который по комментарию в `Exception/VlessException.php:11` реализует `Amp\Socket\SocketConnector`. Я видел это только по результатам поиска, сам репозиторий не читал — нужен отдельный конспект |
| Через другой SSH-сервер (jump host) | Коннектор, возвращающий `$jump->createTunnel($host, $port)`: `Tunnel` реализует `Socket`, а TLS для SSH не нужен. Готового класса нет; комбинация следует из API, тестом в репозитории не покрыта |

Три оговорки:

1. В `$uri` должны оставаться настоящие хост и порт цели: из него берётся имя для проверки ключа хоста (L71-L72). Коннектор волен соединяться куда угодно — тест выше проверяет именно это (`requestedUri` равен исходному адресу).
2. Имя резолвит коннектор. Чтобы DNS шёл через прокси, свой коннектор не должен вызывать локальный резолвер.
3. `ConnectContext` и `Cancellation` передаются коннектору как есть; учитывать ли таймаут соединения — на его совести.

## 5. Устройство внутри

### 5.1. Слои

Стек обработчиков собирается в `connect()` (L62-L67) — декораторы над одним интерфейсом `BinaryPacketHandler` (`read`, `write`, `updateEncryption`, `updateDecryption`, `close`):

```
Amp\Socket\Socket                    — любой, от коннектора
  └ PayloadHandler                   — рамки пакетов, шифрование, MAC, счётчики последовательности
      └ MessageHandler               — байты ↔ объекты Message; шлюз на время rekey
          └ LoggerHandler            — PSR-3
              ├ Negotiator           — KEXINIT, обмен ключами, вывод ключей, установка шифров
              ├ Authentication       — ssh-userauth
              └ Dispatcher           — единственный читатель; раздаёт пакеты по очередям каналов
                  └ Channel (Session | DirectTcpIp) → ChannelInputStream / ChannelOutputStream
                      └ Process | Shell | Tunnel
```

| Слой | Ссылка | Суть |
|------|--------|------|
| Пакеты | `references/amphp-ssh/src/Transport/PayloadReader.php@9d171ac:L39-L116 (read)`, `L126-L160 (readAead)`; `PayloadWriter.php@9d171ac:L33-L56 (write)` | Классическая схема «шифр + MAC» и отдельный путь для AEAD |
| Сообщения | `references/amphp-ssh/src/Transport/MessageHandler.php@9d171ac:L38-L70 (create)`, `L80-L103 (read)` | Реестр «номер → класс». Незарегистрированный номер остаётся сырой строкой |
| Согласование | `references/amphp-ssh/src/Negotiator.php@9d171ac:L156-L310 (exchange)` | Первый общий алгоритм из списка клиента; при пустом пересечении — исключение с обоими списками |
| Rekey | `references/amphp-ssh/src/Channel/Dispatcher.php@9d171ac:L146-L167 (rekey)`, `Transport/MessageHandler.php@9d171ac:L113-L150`, `Transport/RekeyPacketHandler.php@9d171ac:L23-L72` | Поддержан повторный обмен **по инициативе сервера**: на время обмена запись канального трафика придерживается, пришедшие канальные пакеты возвращаются в диспетчер. Сам клиент rekey не инициирует |
| Диспетчер | `references/amphp-ssh/src/Channel/Dispatcher.php@9d171ac:L48-L79 (start)`, `L100-L136 (route)` | Один файбер читает соединение и не блокируется на медленном канале (`pushAsync()->ignore()`). Любая ошибка транспорта превращается в `ChannelException` для всех каналов и закрывает сокет |
| Каналы | `references/amphp-ssh/src/Channel/Channel.php@9d171ac:L220-L257 (open)`, `L266-L283 (data)`, `L331-L351 (consumeLocalWindow)`, `L382-L397 (sendClose)` | Свой номер канала и номер собеседника хранятся раздельно; окно приёма 2 МБ, максимальный пакет 32768 (L42-L52); на `CHANNEL_CLOSE` всегда отвечает своим — иначе после 10 команд сервер перестаёт открывать каналы (MIGRATION L262-L272) |

### 5.2. Алгоритмы

Порядок в списках — порядок предпочтения клиента.

| Класс | Алгоритмы | Где задано |
|-------|-----------|-----------|
| Обмен ключами | `curve25519-sha256@libssh.org`, `diffie-hellman-group18-sha512`, `group16-sha512`, `group14-sha256`, `group14-sha1` | `references/amphp-ssh/src/Negotiator.php@9d171ac:L451-L453`, `KeyExchange/DiffieHellmanGroup.php@9d171ac:L195-L202` |
| Ключи хоста | варианты `-cert-v01@openssh.com` первыми, затем `ssh-ed25519`, `ecdsa-sha2-nistp521/384/256`, `rsa-sha2-512`, `rsa-sha2-256`, `ssh-rsa`. `ssh-dss` не предлагается | `references/amphp-ssh/src/Negotiator.php@9d171ac:L405-L418`, `HostKey/Certificate.php@9d171ac:L33-L41` |
| Шифры | `chacha20-poly1305@openssh.com`, `aes256-gcm@openssh.com`, `aes128-gcm@openssh.com`, `aes256/192/128-ctr`, `aes256/192/128-cbc` | `references/amphp-ssh/src/Negotiator.php@9d171ac:L455-L464` |
| MAC | `hmac-sha2-256`, `hmac-sha1` (для AEAD не используется) | `references/amphp-ssh/src/Negotiator.php@9d171ac:L473-L478` |
| Сжатие | только `none` | `references/amphp-ssh/src/Negotiator.php@9d171ac:L423-L425` |
| Расширения | `ext-info-c` (RFC 8308) — получение `server-sig-algs` | `references/amphp-ssh/src/Negotiator.php@9d171ac:L387-L395` |

Список алгоритмов снаружи не настраивается: `connect()` вызывает `Negotiator::create()` без параметров (L66). **Наблюдение:** в списках нет имени `curve25519-sha256` без суффикса, ECDH на NIST-кривых, пост-квантовых обменов, MAC с `-etm@openssh.com` и `hmac-sha2-512`, а также маркера strict-KEX (`kex-strict-c-v00@openssh.com`, защита от атаки Terrapin на `chacha20-poly1305` и CBC+EtM) — поиск по `strict` в `Negotiator.php` пуст. Сервер, оставивший только такие варианты, получит «No common … algorithm».

### 5.3. Зависимости

Из [composer.json](../references/amphp-ssh/composer.json) L24-L36:

- PHP `>=8.1`; расширения `ext-openssl`, `ext-sodium`. `ext-pcntl` не требуется.
- `amphp/amp ^3.1`, `amphp/byte-stream ^2.1`, `amphp/file ^4.0` (чтение ключей и `known_hosts`), `amphp/pipeline ^1.2`, `amphp/socket ^2.4`, `revolt/event-loop ^1`, `psr/log ^3`.
- `paragonie/sodium_compat ^1.21 || ^2.0` — из него берётся Poly1305 (`ParagonIE_Sodium_Core_Poly1305`) для ChaCha20-Poly1305; сам ChaCha20 считается через OpenSSL (`references/amphp-ssh/src/Encryption/ChaCha20Poly1305.php@9d171ac:L149-L165`).
- Для разработки: `amphp/phpunit-util ^3`, `phpunit ^9`, `php-cs-fixer ^3`, `monolog ^3`.

Имя пакета в `composer.json` — `amphp/ssh`, homepage — `github.com/amphp/ssh`, а сабмодуль указывает на форк `rekryt/amphp-ssh`. Тегов в локальном клоне нет (клон неглубокий). **Наблюдение:** `composer require amphp/ssh` поставит то, что опубликовано под этим именем на Packagist, а не обязательно этот коммит; чтобы получить код из `9d171ac`, понадобится VCS-репозиторий в `composer.json` проекта с привязкой к коммиту. Что именно лежит на Packagist, я не проверял.

## 6. Что это даёт Меркурию

Сверено с требованиями ([BRIEF.md → Инструменты из коробки](../brief/requirements.md#инструменты-из-коробки)) и составом фазы 7.

### 6.1. Берём как есть

| Потребность фазы 7 | Чем закрывается |
|--------------------|-----------------|
| SSH на AMPHP v3 / Revolt, PHP 8.2+ | Стек совпадает; требование библиотеки — PHP 8.1+ |
| Команды с кодом выхода, раздельные stdout и stderr, потоковое чтение | `Process` |
| Интерактивные сессии | `Shell` (PTY, размер окна) |
| Туннели к сервисам за сервером (SQL на loopback) | `SshResource::createTunnel()` — если сервис не требует TLS до цели |
| Маршрут: напрямую, через прокси, через VLESS, SSH поверх VLESS | Параметр `$connector`; коннекторы — наши или из `amphp-vless` |
| Проверка ключа хоста | Интерфейс `HostKeyVerifier` — реализуем поверх реестра серверов |
| Много команд на одном соединении, параллельно | Мультиплексирование каналов, предел `MaxSessions` сервера |
| Таймаут подключения | `Cancellation` в `connect()` |
| Различение причин отказа для сообщений владельцу | Набор исключений из раздела 3.9 |
| Диагностический лог без секретов | PSR-3 логгер в `connect()` |

### 6.2. Дописываем поверх

| Что | Почему и как |
|-----|-------------|
| Реестр серверов и учётных данных | В библиотеке нет понятия «профиль сервера»: `connect()` принимает готовые значения |
| Аутентификация ключом из зашифрованного хранилища | `PublicKey` читает ключ **только из файла по пути**. Варианты: временный файл с правами 0600 на время подключения; своя реализация `Authentication` поверх `PrivateKeyLoader::load()` (принимает содержимое ключа, но класс `@internal`); ssh-agent в контейнере. Решение надо принять явно — от него зависит, появляется ли расшифрованный ключ на диске |
| Проверка ключа хоста из БД | Свой `HostKeyVerifier`: закреплённый отпечаток на сервер, первое подключение — с подтверждением владельца. Образец — `examples/host-key-verification.php` |
| Политика и классификация команд, подтверждения | Библиотека передаёт строку команды удалённому shell без разбора. Весь контроль — до вызова `new Process()` |
| Журнал команд | Обёртка вокруг `Process`: кто, сервер, команда, код выхода, длительность, объём вывода |
| Таймаут и принудительная остановка команды | `join()` с `TimeoutCancellation` только перестаёт ждать. Останавливать надо на сервере: префикс `timeout N`, либо закрытие соединения. Это влияет на пул: «убить команду» может означать «пересоздать соединение» |
| Ограничение объёма вывода | См. наблюдение 2 в разделе 7: непрочитанный вывод копится в памяти без предела. Читать с лимитом и обрывать |
| Пул соединений и переподключение | Нет ни того, ни другого. `SshResource::isClosed()` не показывает обрыв со стороны сервера (наблюдение 1) — пулу нужна своя проверка живости и пересоздание при `ChannelException` |
| Keepalive | Нет. Для долгоживущих соединений в пуле — периодическая лёгкая команда или короткое время жизни соединения |
| Передача файлов | Поверх `Process`: `cat > путь` / `cat путь` с `ByteStream\pipe()`, контроль размера или хеша отдельной командой. Путь экранировать самим (в `Process` экранируется только `$cwd`). Права, время изменения, докачка, листинг каталогов — тоже командами |
| SOCKS5 / HTTP CONNECT коннекторы | Свои реализации `SocketConnector` (или из `amphp-vless`, если там есть — не проверено) |
| Jump host | Коннектор поверх `createTunnel()` — десяток строк |
| `sudo` и команды, которым нужен tty | Через `Shell` (PTY всегда) или через внутренний `Session` (`pty()` + `exec()`). Ввод пароля sudo через stdin — отдельное решение по безопасности |

### 6.3. Чего в библиотеке нет

SFTP и SCP; подсистемы; обратный проброс портов и локальный слушающий порт; динамический SOCKS; проброс агента и X11; `keyboard-interactive` и многофакторная аутентификация; зашифрованные ключи `openssh-key-v1` и аппаратные ключи без агента; сжатие; keepalive; переподключение; пул; таймауты на команду; запись в `known_hosts`; чтение `~/.ssh/config`; настройка списка алгоритмов; rekey по инициативе клиента.

Для полноценной передачи файлов в фазе 7 придётся выбирать: довольствоваться `cat` через `Process`, реализовать SFTP как подсистему поверх `Session` (нужен запрос `subsystem`, которого нет) или взять другое средство. Это стоит записать в открытые решения.

## 7. Ограничения и риски

### 7.1. Зрелость

- Версия 2.0 — переписывание под AMPHP v3, несовместимое с 1.x. Последний коммит — 2026-08-06. Тегов в клоне нет, файла CHANGELOG нет.
- Строка идентификации по умолчанию — `SSH-2.0-AmpSSH_0.1`.
- В комментариях остались следы незавершённой миграции, расходящиеся с кодом: `docker/modern.Dockerfile` L1-L11 («клиент должен падать, пока не сделана фаза 7»), `docker/legacy.Dockerfile` L28-L32 и `modern.Dockerfile` L28 («rekey не реализован»), `docker/setup-test-sshd.sh` L35-L36 («ECDSA — неподдерживаемый формат», при том что есть тест `testEcdsaSuccess`), докблок `KnownHosts` L13-L15 («CA-записи распознаются, но не учитываются» — а `verifyCertificate` L114-L137 их учитывает), комментарий в `Dispatcher::route` L127-L128 («учёт окна ещё не реализован»).
- Авторы в `composer.json`: Joel Wurtz и Sergey Krupkin; последний — владелец форка и нашего проекта, так что исправления можно вносить в первоисточник.

### 7.2. Тесты

- 33 PHP-файла в `tests/` (плюс тестовые ключи), порядок выполнения случайный, `failOnRisky` и `failOnWarning` включены ([phpunit.xml.dist](../references/amphp-ssh/phpunit.xml.dist)).
- **Без сервера** (модульные, на поддельном обработчике пакетов или сценарном TCP-узле): `connect()` — отмена, закрытие сокета, преамбула, свой коннектор; диспетчер и жизненный цикл каналов; управление окном; жизненный цикл `Process`, экранирование `$cwd`; туннель; rekey; согласование алгоритмов; AES-CTR, AES-GCM, ChaCha20-Poly1305; подписи ключей хоста; сертификаты; `known_hosts`; ssh-agent; обмен при аутентификации (баннеры, отказ в сервисе); разбор `openssh-key-v1`; функции чтения полей.
- **С настоящим sshd** (пропускаются без `SSH_LOCAL_HOST`): пароль, ключи RSA / RSA с фразой / Ed25519 / ECDSA, `Process` (stdin, stderr, env, параллельные команды, большие объёмы, обрыв), `Shell`, `Session`, перебор всех алгоритмов обмена, шифров и MAC. В CI — три режима: legacy, modern и rekey с `RekeyLimit 1K`.
- **Не покрыто тестами** (по именам тестов и поиску): туннель против настоящего sshd (`TunnelTest` — только на поддельном обработчике); сертификаты пользователя и агент против настоящего сервера; подключение через реальный прокси или цепочку туннелей; поведение на простаивающем соединении; сигналы (`testSignal` пропущен). У `Shell` нет модульных тестов без сервера.
- Сам я тесты не запускал.

### 7.3. Ограничения из README и MIGRATION

- `kill()` и `signal()` — совет, OpenSSH его игнорирует.
- Отмена `join()` не останавливает команду.
- У `connect()` нет своего таймаута.
- Проверка ключа хоста включена по умолчанию: в контейнере без `known_hosts` любое подключение упадёт, пока не передан свой верификатор.
- TLS поверх туннеля невозможен.
- Зашифрованные `openssh-key-v1` и `sk-*` — только через агент.
- `ssh-dss` не поддерживается.
- Итератор канала нельзя бросать посреди чтения и нельзя брать второй раз — это касается только прямой работы с `Session` (MIGRATION L159-L166).
- Переменные окружения сервер, как правило, отклоняет.

### 7.4. Мои наблюдения по коду

Ничего из этого не проверено запуском; каждое — вывод из прочитанного кода.

1. **Обрыв соединения не виден через `SshResource`.** `isClosed()` возвращает `!$this->running`, а флаг сбрасывается только в `close()` (`references/amphp-ssh/src/SshResource.php@9d171ac:L66-L85`). `Dispatcher::doFail()` (`Channel/Dispatcher.php@9d171ac:L175-L183`) об обрыве ресурсу не сообщает, колбэка `onClose` нет. Мёртвое соединение обнаруживается только попыткой открыть канал.
2. **Окно приёма не создаёт обратного давления.** `consumeLocalWindow()` вызывается при получении пакета и пополняет окно на половине, не дожидаясь, пока потребитель прочитает данные (`references/amphp-ssh/src/Channel/Channel.php@9d171ac:L180-L193, L331-L351`); сами данные кладутся в очередь через `pushAsync()->ignore()`. Если вывод никто не читает (или читают stdout, а команда пишет в stderr), он копится в памяти без предела. Утверждение комментария L35-L41, что конечное окно ограничивает буфер, кодом не подтверждается. Для агента это означает: всегда читать оба потока и самим ограничивать объём.
3. **Нет способа закрыть канал `Process` или `Shell`.** Публичных `close()` у них нет, поле `$session` приватное. Остановить команду можно только `kill()` (ненадёжно), вводом в stdin (у `Shell` — `\x03`) или закрытием всего соединения.
4. **Глобальные запросы сервера остаются без ответа.** `Dispatcher::route()` отбрасывает всё вне диапазона канальных сообщений 90–100 (`Channel/Dispatcher.php@9d171ac:L109-L111`), константы `SSH_MSG_GLOBAL_REQUEST` / `REQUEST_SUCCESS` / `REQUEST_FAILURE` помечены `@TODO` (`Message/Message.php@9d171ac:L27-L29`), `GlobalRequest::decode()` не читает содержимое (`Message/GlobalRequest.php@9d171ac:L16-L18`). Запрос `keepalive@openssh.com` с `want_reply`, который sshd шлёт при настроенном `ClientAliveInterval`, ответа не получит. Предполагаю, что такой сервер разорвёт простаивающее соединение после `ClientAliveCountMax` попыток; поведение сервера я не проверял. Для пула это надо проверить на реальном сервере.
5. **Причина отключения от сервера теряется.** `Disconnect::decode()` не возвращает объект (`Message/Disconnect.php@9d171ac:L47-L54` — нет `return`), поэтому `MessageHandler::read()` отдаёт `null`, и диспетчер сообщает «SSH connection was closed by remote server» без кода и текста причины; во время аутентификации это превращается в «Connection closed during authentication».
6. **Неизвестный тип канального запроса роняет всё соединение.** `ChannelRequest::decode()` бросает `RuntimeException` на тип вне восьми известных (`Message/ChannelRequest.php@9d171ac:L31-L40, L62-L64`); исключение возникает в цикле чтения диспетчера и приводит к `doFail()` для всех каналов. Примеры таких запросов у OpenSSH — `eow@openssh.com`, `keepalive@openssh.com` на канале. Приходят ли они клиенту с идентификацией `AmpSSH`, я не проверял.
7. **Длина пакета не ограничена.** `PayloadReader` читает столько байт, сколько указано в поле длины (до 4 ГБ), и проверяет MAC только после (`Transport/PayloadReader.php@9d171ac:L84-L99, L136-L138`). Злонамеренный или сбойный сервер может заставить клиент накапливать буфер. Для строки идентификации такой предел есть (65536 байт), для пакетов — нет.
8. **Путь по умолчанию у `PublicKey` — `'~/.ssh/id_rsa'`** (`Authentication/PublicKey.php@9d171ac:L34`), а файл проверяется через `Amp\File\exists()` без раскрытия `~`. Скорее всего, значение по умолчанию не работает и путь надо всегда передавать явно и абсолютным. Не запускал.
9. **`PublicKey` перечитывает файл ключа при каждой аутентификации** (L49-L53) — при использовании временного файла он должен существовать до завершения `connect()`.
10. **Нет защиты strict-KEX** — см. раздел 5.2. При шифре по умолчанию `chacha20-poly1305` это стоит оценить отдельно; сам я применимость атаки к этому клиенту не анализировал.
11. **Команда — текст для shell.** Экранируется только `$cwd`. Любая подстановка значений в команду (пути, имена) — ответственность вызывающего; для агента, который сам сочиняет команды, это прямо относится к политике команд.
12. **Запись в канал не отменяется.** При заполненном окне получателя `write()` ждёт до закрытия канала (`Channel/Channel.php@9d171ac:L295-L306`); таймаут на запись придётся делать закрытием соединения.

## 8. Что читать под вопрос

| Вопрос | Куда идти |
|--------|-----------|
| Как подключиться и какие параметры есть | [src/functions.php](../references/amphp-ssh/src/functions.php) L20-L91; [README.md](../references/amphp-ssh/README.md) L20-L68 |
| Как пустить соединение через прокси, VLESS, другой туннель | [src/functions.php](../references/amphp-ssh/src/functions.php) L32-L35, L54; [tests/ConnectTest.php](../references/amphp-ssh/tests/ConnectTest.php) L182-L232 |
| Какие способы аутентификации и их ограничения | [examples/authentication.php](../references/amphp-ssh/examples/authentication.php); [src/Authentication/PublicKey.php](../references/amphp-ssh/src/Authentication/PublicKey.php); [src/Authentication/OpenSshPrivateKey.php](../references/amphp-ssh/src/Authentication/OpenSshPrivateKey.php) |
| Как написать свой метод аутентификации (ключ из памяти) | [src/Authentication/Authentication.php](../references/amphp-ssh/src/Authentication/Authentication.php); образец — [PublicKey.php](../references/amphp-ssh/src/Authentication/PublicKey.php) L44-L127; загрузка ключа — [PrivateKeyLoader.php](../references/amphp-ssh/src/Authentication/PrivateKeyLoader.php) |
| Как работает ssh-agent | [src/Authentication/AgentAuthentication.php](../references/amphp-ssh/src/Authentication/AgentAuthentication.php), [Agent.php](../references/amphp-ssh/src/Authentication/Agent.php); README L144-L161 |
| Как выполнить команду и прочитать результат | [src/Process.php](../references/amphp-ssh/src/Process.php); [examples/process.php](../references/amphp-ssh/examples/process.php), [streaming-output.php](../references/amphp-ssh/examples/streaming-output.php) |
| Как ограничить команду по времени и остановить её | [examples/timeouts.php](../references/amphp-ssh/examples/timeouts.php); MIGRATION L207-L219, L251-L260 |
| Рабочий каталог и переменные окружения | [examples/env-and-cwd.php](../references/amphp-ssh/examples/env-and-cwd.php); MIGRATION L274-L292 |
| Как передать файл или данные в команду | [examples/stdin-piping.php](../references/amphp-ssh/examples/stdin-piping.php) |
| Интерактивный shell, PTY, размер окна | [src/Shell.php](../references/amphp-ssh/src/Shell.php); [examples/pty-shell.php](../references/amphp-ssh/examples/pty-shell.php); запросы — [src/Channel/Session.php](../references/amphp-ssh/src/Channel/Session.php) |
| Туннель к сервису за сервером | [src/Tunnel.php](../references/amphp-ssh/src/Tunnel.php); [examples/tunnel.php](../references/amphp-ssh/examples/tunnel.php); README L70-L96 |
| Проверка ключа хоста, свой верификатор, закрепление отпечатка | [examples/host-key-verification.php](../references/amphp-ssh/examples/host-key-verification.php); [src/HostKey/KnownHosts.php](../references/amphp-ssh/src/HostKey/KnownHosts.php), [HostKeyVerifier.php](../references/amphp-ssh/src/HostKey/HostKeyVerifier.php) |
| Какое исключение что означает | [examples/error-handling.php](../references/amphp-ssh/examples/error-handling.php) |
| Много команд и много хостов одновременно | [examples/multiple-commands.php](../references/amphp-ssh/examples/multiple-commands.php), [concurrent-hosts.php](../references/amphp-ssh/examples/concurrent-hosts.php) |
| Что происходит при обрыве соединения | [src/Channel/Dispatcher.php](../references/amphp-ssh/src/Channel/Dispatcher.php) L48-L79, L175-L205; [src/Channel/Channel.php](../references/amphp-ssh/src/Channel/Channel.php) L433-L469; [tests/Channel/DispatcherLifecycleTest.php](../references/amphp-ssh/tests/Channel/DispatcherLifecycleTest.php) |
| Управление потоком, окна, размер пакета | [src/Channel/Channel.php](../references/amphp-ssh/src/Channel/Channel.php) L34-L52, L259-L351; [tests/Channel/FlowControlTest.php](../references/amphp-ssh/tests/Channel/FlowControlTest.php) |
| Какие алгоритмы поддержаны и почему сервер не подходит | README L112-L128; [src/Negotiator.php](../references/amphp-ssh/src/Negotiator.php) L348-L478 |
| Повторный обмен ключами | [src/Channel/Dispatcher.php](../references/amphp-ssh/src/Channel/Dispatcher.php) L81-L167; [src/Transport/RekeyPacketHandler.php](../references/amphp-ssh/src/Transport/RekeyPacketHandler.php); [tests/Transport/RekeyTest.php](../references/amphp-ssh/tests/Transport/RekeyTest.php) |
| Как поднять тестовый sshd | README L174-L189; [docker/](../references/amphp-ssh/docker/); [tests/SshServer.php](../references/amphp-ssh/tests/SshServer.php) |
| Что изменилось относительно 1.x | [MIGRATION.md](../references/amphp-ssh/MIGRATION.md) |

## 9. Оценка

Библиотека закрывает ядро SSH-инструмента Меркурия — подключение по произвольному транспорту, все нужные способы аутентификации, команды, shell, туннели — и делает это на том же стеке, без расширений сверх `openssl` и `sodium`. Код подробно прокомментирован: у большинства решений записана причина, что упрощает сопровождение. Слабые стороны — отсутствие SFTP, отсутствие управления жизнью соединения (keepalive, обнаружение обрыва, остановка команды) и несколько мест из раздела 7.4, которые стоит проверить на реальном сервере до того, как строить пул соединений. Автор форка — владелец проекта, поэтому найденное разумнее исправлять в самой библиотеке, а не обходить в агенте.

## 10. Что не прочитано

- `src/Internal/Der.php`, `src/Internal/Ecdsa.php` — только сигнатуры методов.
- `src/HostKey/HostKeySignature.php` — прочитаны L1-L80 и сигнатуры остального (проверка ECDSA и RSA, сборка PEM).
- `src/Transport/functions.php` — только сигнатуры (`read_byte`, `read_uint32`, `read_string`, `read_mpint`, `read_namelist` и др.).
- `src/Message/` — из 40 классов прочитаны `Message`, `GlobalRequest`, `Disconnect`, `ChannelRequest`, `ChannelRequestSignal`, `ChannelRequestExitSignal` и начало `ChannelRequestPty` (константы режимов). Остальные, включая `ExtInfo`, `KeyExchangeInit`, `ChannelOpen`, сообщения аутентификации, не читал. В частности, не знаю, какой тип терминала и какие режимы уходят в `pty-req`.
- `src/Encryption/CipherMode/Cbc.php`, `Encryption/None.php`, интерфейсы `Encryption`, `Decryption`, `CipherMode`, `Mac/Mac.php`, `Mac/None.php` — не читал.
- `tests/` — целиком прочитаны `ConnectTest.php`, `SshServer.php`, `IntegrationTestCase.php`, `NetworkHelper.php`; по остальным — только имена тестовых методов. Раздел 7.2 построен на них.
- `examples/process.php` — не читал; `examples/shell.php` — первые 40 строк.
- `.php-cs-fixer.dist.php` — первые 20 строк; `LICENSE`, `.gitignore` — не читал.
- `references/amphp-vless/` — не читал; о `VlessConnector` знаю только из поиска по строке `SocketConnector`.
- Ничего не запускалось: ни тесты, ни примеры. Поведение sshd в наблюдениях 4, 6, 8 и 10 раздела 7.4 — предположения.
