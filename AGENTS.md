# AGENTS.md — laravel-websockets

> Audience: AI coding agents (Claude Code, Copilot, Cursor) and human contributors.
> This is the **canonical, source-verified reference** for *how to use this package*.
> The README is a marketing overview; **this file is the contract**. When the two
> disagree, this file wins (and fix the README).
>
> Every claim below is grounded in `src/`. File\:line citations are given so you
> can verify before you trust. Do **not** answer questions about this package from
> training-data memory — read the cited source.

---

## 0. The one mental model you must hold

**Every inbound WebSocket message is handled in a `pcntl_fork()` child process that
runs your handler synchronously and then `exit(0)`s.** (`src/Websocket/Handler.php`,
`forkWithSocketPair`.)

Consequences — internalize these, they change how you write handlers:

- **Blocking code is SAFE inside a handler.** `sleep()`, `usleep()`, synchronous
  `Http::get()`, heavy DB queries — they block only *that one child*, never the
  event loop or other clients. You do **not** need ReactPHP promises in a handler.
  Write ordinary blocking Laravel code.
- **Laravel `defer()` works** — deferred callbacks are flushed just before the child
  persists its session and exits. But see §7: over the WS bridge `defer()` is a
  known hazard; prefer a real queued **Job** for anything heavy or that must survive.
- **State does not carry over between messages** except via `wsSession()` (§6) or the
  cache. Each child gets a *fresh* DB connection and purged Redis/cache singletons.
- **Concurrency is capped** by `config('websockets.max_concurrent_children')` (protects
  MySQL `max_connections`); excess messages queue in memory and run as children free up.

---

## 1. Two ways to expose an endpoint — pick the right one

There are **two controller families**. They do not mix. Choose before you write code.

### A) `#[Websocket]` attribute on a *regular HTTP controller* — `src/Attributes/Websocket.php`

Turn any `App\Http\Controllers\…` method into a WS endpoint with one annotation. The
**same method serves HTTP and WS**. Use this for thin "return data" endpoints that you
also want reachable over REST.

```php
use BlaxSoftware\LaravelWebSockets\Attributes\Websocket;

class AerodromeController extends Controller   // a normal HTTP controller
{
    #[Websocket]                                 // event: "api-v1-aerodrome.index"
    public function index() { return AerodromeService::v1Index(); }

    #[Websocket]                                 // args bound BY NAME from payload
    public function show(string $icao) { return AerodromeService::v1Show($icao); }

    #[Websocket(event: 'user.stats', needAuth: true)]   // explicit name + auth gate
    public function stats() { return [...]; }
}
```

- **Event name** defaults to `kebab(class path under App\Http\Controllers) + '.' + method`,
  e.g. `Api\V1\AerodromeController::index` → `api-v1-aerodrome.index`. Override with
  `event:`, `prefix:`, or `suffix:`. Class-level `suffix:` is ignored.
- **`needAuth` defaults to `false`** here. (Constructor: `Websocket.php:36`.)
- Method args are filled **by parameter name** from the payload (`Controller::resolveAttributeMethodArgs`).
  `show(string $icao)` receives `$data['icao']`.
- **These methods do NOT get `$this->progress()/success()/error()/broadcast()/whisper()`** —
  those live on the *native* base class (family B). An attribute method just `return`s data,
  which is auto-wrapped as `…:response`.
- ⚠️ **HTTP middleware is SKIPPED** on this path (`dispatchHttpAttributeTarget`). No
  auth/throttle/`ConvertEmptyStringsToNull`/`TrimStrings`. See §4.

### B) Native controller extending `Websocket\Controller` — `src/Websocket/Controller.php`

The richer family. Lives in `app/Websocket/Controllers/<Prefix>Controller.php`. Gives you the
full response API (`$this->progress/success/error/broadcast/whisper`) and `$this->connection`.

```php
namespace App\Websocket\Controllers;

class DashboardController extends \BlaxSoftware\LaravelWebSockets\Websocket\Controller
{
    public $need_auth = true;        // see §3 — DEFAULT IS TRUE when omitted

    public function index()          // reachable at 'dashboard.index'
    {
        $user   = auth()->user();    // §4: NOT request()->user()
        $locale = request('locale') ?: app()->getLocale();

        $result = DashboardService::load($user, $locale, function ($section, $data) {
            $this->progress(['section' => $section, 'data' => $data]);   // stream
        });

        return $this->success($result);   // final ':response'
    }
}
```

- **Event name** = `<class-prefix>.<method>`. `SimulatorController::transmit` → `simulator.transmit`.
  Resolution: kebab→Pascal + `Controller`, e.g. `admin-user.x` → `AdminUserController`, with
  folder fallbacks (`Admin\UserController`). (`src/Websocket/ControllerResolver.php`.)
- **Do NOT add `#[Websocket]`** to these — they are resolved by class name, not the attribute registry.
- **Do NOT override `__construct`** — it's `final`. Use the `boot()` / `booted()` / `unboot()`
  lifecycle hooks instead (`Controller.php:35-52`). Returning exactly `false` from `boot()`/`booted()`
  aborts.

> **Resolution order:** the native resolver (family B, `app/Websocket/Controllers`) is tried
> **first**; the attribute registry (family A) is the **fallback** when no native controller
> matches. So a native controller prefix *shadows* an attribute endpoint with the same prefix —
> if you expose attribute endpoints under a prefix that also has a native controller, use a
> **distinct prefix**.

---

## 2. Responding to the caller — the `:suffix` wire protocol

When a client calls an event, the server replies on **suffixed** event names. The client library
(`@blax-software/networking`) correlates the reply to the call. You produce these via:

| You write…                       | Client receives event…   | Notes                                             |
|----------------------------------|--------------------------|---------------------------------------------------|
| `return $payload;` (non-bool)    | `<event>:response`       | **Auto-wrapped.** The normal happy path.          |
| `$this->success($payload)`       | `<event>:response`       | Explicit. Returns `true` (suppresses auto-wrap).  |
| `$this->progress($payload)`      | `<event>:progress`       | Stream 0..N times *before* the final response.    |
| `$this->error($msg)`             | `<event>:error`          | String → `['message' => $msg]`. Returns `true`.   |
| `return true;` / `return false;` | *(nothing)*              | Sends no frame — use after you already replied.   |
| *(uncaught `throw`)*             | `<event>:error`          | Auto-converted; reported to Sentry (not for `ValidationException`). |

Signatures (verbatim, `src/Websocket/Controller.php:344-434`):

```php
final public function progress(mixed $payload = null, ?string $event = null, ?string $channel = null): bool;  // :350
final public function success (mixed $payload = null, ?string $event = null, ?string $channel = null): bool;  // :377
final public function error   (array|string|null $payload = null, ?string $event = null, ?string $channel = null): bool;  // :410
```

The suffix is appended automatically — **never write `…:response` yourself**. `$event` overrides
the base event name; `$channel` defaults to the current channel.

**Canonical streaming pattern** — hand `$this->progress` down into a service as a closure so the
service stays WS-agnostic:

```php
public function transmit()
{
    $result = SimulatorConversationService::doSimulation(
        fn ($p) => $this->progress($p),     // each chunk → 'simulator.transmit:progress'
    );
    return $result;                          // final → 'simulator.transmit:response'
}
```

⚠️ **Footguns:**
- **Don't both `return $payload` AND `$this->success($payload)`** — the client gets two
  `:response` frames. Pick one.
- `$this->error(...)` returns `true` but does **not** halt the method — `return $this->error(...)`
  if you mean it as a guard clause. (Several call sites forget the `return` and keep executing.)
- `progress()`/`success()` deref `$this->channel->getName()` for the default channel — a controller
  with no channel context will fault if you omit `$channel`.

---

## 3. Auth gate

| Family | How to gate | Default |
|--------|-------------|---------|
| A — `#[Websocket]` | `needAuth:` attribute arg | **`false`** |
| B — native `Controller` | `public $need_auth = true\|false;` property | **`true`** (when property is absent — `Controller.php:108` reads `?? true`) |

So a native controller with **no** `$need_auth` property requires auth on *every* method. Set
`public $need_auth = false;` for guest/public endpoints. The gate is controller-**wide** for native
controllers (no per-method granularity — use family A for per-method auth). Auth self-heal: a client
may pass `data.authtoken` and the package resolves it via `config('websockets.auth_resolver')` /
Sanctum.

---

## 4. Reading the authed user / request inside a handler — the #1 mistake

> **`request()->user()` is `null` over the WS bridge.** WS handlers run *outside* HTTP middleware,
> so no `userResolver` is installed. Use the **guard**, which the package populates from the socket.

```php
$user = auth()->user();          // ✅ canonical
$user = auth()->guard()->user(); // ✅ equivalent (this is what User::auth() does)
$user = request()->user();       // ❌ null over WS — even for an authenticated connection
```

`request()` itself **is** rebuilt from the message payload, so `request('key')` and
`request()->validate([...])` work exactly like HTTP — only `->user()` is the trap.

**Corollary — blank strings are NOT nulled.** `ConvertEmptyStringsToNull` and `TrimStrings`
middleware do **not** run over the bridge, so an empty form field arrives as `''`, not `null`.
Add explicit `nullable` validation rules and nullify blank inputs yourself, or validation that
expects `null` will reject `''`.

---

## 5. Pushing events from *outside* a handler (jobs, commands, HTTP, services)

There is **no `$this`** in a job/command, so the `$this->broadcast/whisper` methods are unavailable.
Use the **global helpers** (`src/helpers_global.php`, autoloaded via `composer.json` `autoload.files`)
or `WebsocketService`. These send the event name **verbatim — no `:suffix`** (they are server-initiated
fan-out, not request/response).

```php
// Always guard — these no-op (return false) if the server isn't running:
if (! ws_available()) return;

ws_broadcast('chat.message', ['text' => 'Hi'], 'chat');                  // → whole channel
ws_whisper  ('info:notification', $data, $socketIds, 'websocket');       // → specific sockets
ws_broadcast_except('chat.message', ['text' => 'Hi'], [$senderSocketId], 'chat');
```

Signatures (verbatim, `src/helpers_global.php`):

```php
ws_broadcast(string $event, array $data, string $channel = 'websocket'): bool         // :28
ws_whisper(string $event, array $data, array $sockets, string $channel = 'websocket'): bool   // :48
ws_broadcast_except(string $event, array $data, array $excludeSockets, string $channel = 'websocket'): bool  // :68
ws_client(): BroadcastClient                                                          // :86
ws_available(): bool                                                                  // :98
```

**Whispering takes socket IDs, not user IDs.** Resolve them first:

```php
$sockets = \BlaxSoftware\LaravelWebSockets\Services\WebsocketService::getUserSocketIds($user->id);
if ($sockets) ws_whisper('info:notification', $data, $sockets);
```

`WebsocketService` is the class-based equivalent (`src/Services/WebsocketService.php`) and also exposes
live connection state — `getUserSocketIds()`, `isUserConnected()`, `getAuthedUsers()`,
`getActiveChannels()`, `getChannelConnections()`.

> ⚠️ **`broadcast`/`whisper` argument order differs between the controller methods and the helpers** —
> a real footgun:
> - controller: `$this->whisper($data, $event, $sockets, $channel)` — **data first**
> - helper:     `ws_whisper($event, $data, $sockets, $channel)` — **event first**

---

## 6. Per-connection session — `wsSession()`

A Redis-backed key/value store **scoped to one socket**, surviving across messages on that socket
(each child loads → mutates → `save()`s before exit). Only available inside a WS child handler.

```php
wsSession()->increment('transmit_count');
$count = wsSession()->get('transmit_count', 0);
wsSession()->put('last_action', 'transmitted');
if (wsSession()->has('pending')) wsSession()->forget('pending');
```

```php
function wsSession(): ?\BlaxSoftware\LaravelWebSockets\Websocket\ConnectionSession   // helpers_global.php:131
```

> ⚠️ **`wsSession()` takes NO arguments.** Older docs showed `wsSession('channel', [...])` returning an
> "auth payload" — that signature is **fictional**; it never existed. `wsSession()` returns a
> `ConnectionSession` store (or `null` outside a WS child — null-guard it).

---

## 7. `defer()` vs Jobs

`defer()` runs work after the response frame is sent (keeps handlers snappy). It *works* over the
bridge, but it is a **documented hazard**: deferred callbacks can accumulate and be re-executed by
later forked children, and they are **never invoked on connection close**. Heavy/durable work is being
migrated to real queued **Jobs** (which also get retry semantics + show up in tooling).

- ✅ tiny, fire-and-forget, must-not-survive side effects → `defer(fn () => ...)`
- ✅ heavy, slow, or must-survive-a-crash work → dispatch a **Job**
- Capture connection state into locals **before** deferring (`$socketId = $this->connection->socketId;`) —
  `$this->connection` may be gone inside the deferred callback.

---

## 8. Routing, caching & iteration

- The controller resolver **caches** the event→class map for the server's lifetime.
- After **adding/renaming** a controller or method, the running server won't see it until you clear
  the cache or restart:
  ```bash
  php artisan websocket:steer cache:clear   # clear OPcache + resolver cache, no restart
  php artisan websockets:restart            # graceful restart
  ```
  In dev, `config('websockets.hot_reload')` (defaults to `APP_DEBUG`) bypasses the cache automatically.
- **A consumer app must restart the WS daemon after a code/model/config swap** — the daemon caches
  code at boot.
- **Introspection** (dev only): send a single-segment event (`websocket`, or a bare prefix like
  `simulator`) to get a JSON description of available controllers/methods. Gated behind
  `config('websockets.introspection')` or `local` env — **never enable in production**.

---

## 9. Footgun checklist (skim before writing a handler)

- [ ] `request()->user()` is `null` — use `auth()->user()`.
- [ ] Blank strings aren't nulled — add `nullable` + nullify yourself.
- [ ] Native controller default is `need_auth = true` — set `false` for public endpoints.
- [ ] Don't `return $payload` **and** `$this->success($payload)` (double `:response`).
- [ ] `$this->error(...)` doesn't halt — `return` it if it's a guard.
- [ ] There is **no** `$this->respond()` and **no** global `ws_progress()`. The verbs are
      `progress()` / `success()` / `error()` / `broadcast()` / `whisper()`, all instance methods.
- [ ] `ws_whisper(event, data, …)` vs `$this->whisper(data, event, …)` — argument order differs.
- [ ] `wsSession()` takes no args.
- [ ] New/renamed controller → `websocket:steer cache:clear` or restart.
- [ ] Heavy/durable work → a Job, not `defer()`.
- [ ] Don't override the `final __construct` — use `boot()/booted()/unboot()`.

---

## 10. Source map (read these, not your memory)

| Concern | File |
|---------|------|
| Response verbs, suffixes, resolution, auth gate, introspection | `src/Websocket/Controller.php` |
| Fork/IPC model, parent relay, protocol acks, ping fast-path | `src/Websocket/Handler.php` |
| `#[Websocket]` attribute semantics | `src/Attributes/Websocket.php` |
| Event→controller mapping | `src/Websocket/ControllerResolver.php`, `src/Websocket/EventRegistry.php` |
| Global helpers (`ws_broadcast`, `ws_whisper`, `wsSession`, …) | `src/helpers_global.php` |
| Out-of-band broadcast/whisper + tracking | `src/Services/WebsocketService.php`, `src/Broadcast/BroadcastClient.php` |
| Per-connection store | `src/Websocket/ConnectionSession.php` |
| Canonical handler example | `src/Websocket/Controllers/ExampleController.php` |
| Config (introspection, hot_reload, max_concurrent_children, broadcast_socket) | `config/websockets.php` |

**Frontend counterpart:** the client side of this protocol (how `:progress`/`:response`/`:error`
are consumed) is documented in `@blax-software/networking`'s `AGENTS.md`.
