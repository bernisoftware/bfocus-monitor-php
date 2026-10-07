<?php

declare(strict_types=1);

namespace Bfocus\Monitor;

/**
 * bFocus Monitor para PHP — os erros não tratados do seu sistema viram demanda no bFocus.
 *
 *     \Bfocus\Monitor\Monitor::init([
 *         'key' => 'bf_mon_…',
 *         'release' => '1.4.2',
 *         'environment' => 'production',
 *         'signing_secret' => getenv('BFOCUS_SIGNING_SECRET'),
 *     ]);
 *
 * Uma linha liga tudo: `set_exception_handler` (encadeando o anterior; nível `fatal`) e
 * `register_shutdown_function` (erro fatal do `error_get_last()` + envio da fila). Integrações:
 * {@see Laravel::register()} e {@see Psr15Middleware}.
 *
 * Nenhum método daqui lança exceção por causa do monitor (só `init` sem chave).
 */
final class Monitor
{
    public const VERSION = '0.1.0';
    public const SDK_NAME = 'bfocus-monitor-php';
    public const CLIENT_ID = self::SDK_NAME . '/' . self::VERSION;

    private const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    private static ?Client $client = null;

    private static ?Scope $global = null;

    /** @var list<Scope> escopos de requisição abertos (o último é o corrente) */
    private static array $stack = [];

    private static bool $hooksInstalled = false;

    private static bool $shutdownRegistered = false;

    /** @var callable|null */
    private static $previousHandler = null;

    private static bool $handledUncaught = false;

    /** Último sinal de vida deste processo (memória estática; no FPM vale com APCu). */
    private static ?int $lastHeartbeat = null;

    /**
     * Liga o monitor. Sem chamada de rede: a fila e o sinal de vida (no máximo 1 a cada 5 min por
     * processo) saem no encerramento — no FPM depois de entregar a resposta.
     *
     * Opções: key (obrigatória), release, environment ('production'), base_url, sample_rate (0..1),
     * ignore (textos contidos; "/regex/" também vale), before_send (callable(array): ?array),
     * signing_secret, auto_capture (true), in_app_prefixes, project_root, heartbeat (true).
     *
     * @param array<string, mixed> $options
     */
    public static function init(array $options): Client
    {
        $new = new Client($options);
        $old = self::$client;
        self::$client = $new;
        if ($old !== null) {
            try {
                $old->close(0.5);
            } catch (\Throwable) {
            }
        }
        self::registerShutdown();
        if ($new->autoCapture) {
            self::installHooks();
        }

        return $new;
    }

    /**
     * Liga a partir do ambiente, se ainda não estiver ligado: BFOCUS_MONITOR_KEY,
     * BFOCUS_MONITOR_RELEASE, BFOCUS_MONITOR_ENVIRONMENT (ou APP_ENV), BFOCUS_SIGNING_SECRET.
     * `$options` completa/substitui. Sem chave, não liga (e devolve false).
     *
     * @param array<string, mixed> $options
     */
    public static function initFromEnvironment(array $options = []): bool
    {
        if (self::$client !== null) {
            return true;
        }
        try {
            $opts = array_filter([
                'key' => self::env('BFOCUS_MONITOR_KEY'),
                'release' => self::env('BFOCUS_MONITOR_RELEASE'),
                'environment' => self::env('BFOCUS_MONITOR_ENVIRONMENT') ?? self::env('APP_ENV'),
                'signing_secret' => self::env('BFOCUS_SIGNING_SECRET'),
            ], static fn ($v): bool => $v !== null);
            foreach ($options as $k => $v) {
                if ($v !== null && $v !== '' && $v !== false) {
                    $opts[$k] = $v;
                } elseif ($k === 'auto_capture') {
                    $opts[$k] = (bool) $v;
                }
            }
            if (!isset($opts['key']) || !is_string($opts['key']) || trim($opts['key']) === '') {
                return false;
            }
            self::init($opts);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function getClient(): ?Client
    {
        return self::$client;
    }

    /** @param array{level?: string, tags?: array<string, mixed>, fingerprint?: list<string>} $extra */
    public static function captureException(\Throwable $e, array $extra = []): void
    {
        self::$client?->captureException($e, $extra);
    }

    public static function captureMessage(string $message, string $level = 'info'): void
    {
        self::$client?->captureMessage($message, $level);
    }

    /**
     * Quem foi afetado. Com `signing_secret` no init, o pacote assina sozinho (v2, a mesma do
     * widget); sem ele, passe o `userHash` que o seu servidor já gera. `setUser(null)` limpa.
     */
    public static function setUser(int|string|null $externalId, int|string|null $customerExternalId = null, ?string $userHash = null): void
    {
        try {
            $scope = self::currentScope();
            if ($externalId === null || (string) $externalId === '') {
                $scope->setUser(null, null, null);

                return;
            }
            $customer = $customerExternalId === null || (string) $customerExternalId === '' ? null : (string) $customerExternalId;
            $scope->setUser((string) $externalId, $customer, $userHash !== null && $userHash !== '' ? $userHash : null);
        } catch (\Throwable) {
        }
    }

    public static function setTag(string $key, string|int|float|bool $value): void
    {
        try {
            self::currentScope()->tags[self::cut($key, 64)] = self::cut((string) $value, 200);
        } catch (\Throwable) {
        }
    }

    public static function addBreadcrumb(string $category, string $message, string $level = 'info'): void
    {
        try {
            self::currentScope()->addBreadcrumb([
                'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
                'category' => self::cut($category, 40),
                'message' => self::cut($message, 300),
                'level' => in_array($level, Client::LEVELS, true) ? $level : 'info',
            ]);
        } catch (\Throwable) {
        }
    }

    /** Envia a fila agora (bloqueia até `$timeout` segundos). True se esvaziou. */
    public static function flush(float $timeout = 2.0): bool
    {
        return self::$client === null ? true : self::$client->flush($timeout);
    }

    /** Envia o que falta e desliga o monitor. */
    public static function close(float $timeout = 2.0): void
    {
        $c = self::$client;
        self::$client = null;
        $c?->close($timeout);
    }

    /** `v2.<ts>.<hex(HMAC_SHA256(secret, "v2:<ts>:<user>:<customer>"))>` — a assinatura do widget. */
    public static function signUser(string $secret, string $userExternalId, string $customerExternalId, ?int $ts = null): string
    {
        $ts ??= time();

        return 'v2.' . $ts . '.' . hash_hmac('sha256', 'v2:' . $ts . ':' . $userExternalId . ':' . $customerExternalId, $secret);
    }

    // ── escopo por requisição ──

    /** Abre um escopo de requisição (a URL perde a query string). Feche com {@see popScope()}. */
    public static function pushScope(?string $transaction = null, ?string $url = null): Scope
    {
        $scope = new Scope($transaction, self::stripQuery($url));
        self::$stack[] = $scope;

        return $scope;
    }

    public static function popScope(Scope $scope): void
    {
        $i = array_search($scope, self::$stack, true);
        if ($i !== false) {
            array_splice(self::$stack, (int) $i, 1);
        }
    }

    /** @internal @return list<Scope> do global ao corrente */
    public static function scopes(): array
    {
        return [self::globalScope(), ...self::$stack];
    }

    /** @internal */
    public static function currentScope(): Scope
    {
        return self::$stack !== [] ? self::$stack[count(self::$stack) - 1] : self::globalScope();
    }

    /** @internal Estado limpo (testes, ou processo longo entre jobs). */
    public static function reset(): void
    {
        self::$client = null;
        self::$global = null;
        self::$stack = [];
        self::$handledUncaught = false;
        self::$lastHeartbeat = null;
    }

    private static function globalScope(): Scope
    {
        return self::$global ??= new Scope();
    }

    public static function stripQuery(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $cut = preg_split('/[?#]/', $url, 2);

        return self::cut(is_array($cut) ? (string) $cut[0] : $url, 1000);
    }

    /** @internal Corte por caractere sem exigir ext-mbstring. */
    public static function cut(string $s, int $n): string
    {
        if (strlen($s) <= $n) {
            return $s;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $n, 'UTF-8');
        }
        $out = substr($s, 0, $n);
        // não deixa um caractere UTF-8 cortado ao meio
        return preg_replace('/[\x80-\xBF]*[\xC0-\xFF]?$/', '', $out) ?? $out;
    }

    private static function env(string $name): ?string
    {
        $v = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($v) && $v !== '' ? $v : null;
    }

    // ── ganchos globais (instalados uma vez; olham o cliente corrente) ──

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function([self::class, 'onShutdown']);
    }

    private static function installHooks(): void
    {
        if (self::$hooksInstalled) {
            return;
        }
        self::$hooksInstalled = true;
        self::$previousHandler = set_exception_handler([self::class, 'onUncaught']);
    }

    /** @internal Exceção não tratada: nível fatal, e depois o handler anterior (ou o padrão do PHP). */
    public static function onUncaught(\Throwable $e): void
    {
        self::$handledUncaught = true;
        try {
            $c = self::$client;
            if ($c !== null && $c->autoCapture) {
                $c->captureException($e, ['level' => 'fatal']);
            }
        } catch (\Throwable) {
        }
        $prev = self::$previousHandler;
        if (is_callable($prev)) {
            $prev($e);

            return;
        }
        self::defaultUncaught($e);
    }

    /** O que o PHP faria sem handler: "PHP Fatal error:  Uncaught …", HTTP 500 e saída 255. */
    private static function defaultUncaught(\Throwable $e): void
    {
        $msg = 'Uncaught ' . $e . "\n  thrown in " . $e->getFile() . ' on line ' . $e->getLine();
        if (filter_var(ini_get('log_errors'), FILTER_VALIDATE_BOOLEAN)) {
            error_log('PHP Fatal error:  ' . $msg);
        }
        $display = strtolower((string) ini_get('display_errors'));
        if ($display !== '' && $display !== '0' && $display !== 'off' && $display !== 'false') {
            $out = PHP_EOL . 'Fatal error: ' . $msg . PHP_EOL;
            if ($display === 'stderr' && defined('STDERR')) {
                fwrite(STDERR, $out);
            } else {
                echo $out;
            }
        }
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code(500);
        }
        exit(255);
    }

    /**
     * Sinal de vida agora, se o deste processo passou de 5 min (para worker/daemon de longa duração,
     * que demora a chegar ao encerramento). Bloqueia até `$timeout`. True se mandou.
     */
    public static function heartbeat(float $timeout = 2.0): bool
    {
        $c = self::$client;
        if ($c === null || !self::heartbeatDue($c, true)) {
            return false;
        }

        return $c->sendHeartbeat($timeout) !== null;
    }

    /**
     * No máximo 1 a cada 5 min por processo: memória estática (CLI e workers de longa duração) e,
     * com APCu, entre as requisições do mesmo worker do FPM. Sem APCu, numa SAPI por requisição só
     * vai junto de um envio de erros (senão seria 1 por requisição).
     */
    private static function heartbeatDue(Client $c, bool $hasEvents): bool
    {
        if (!$c->heartbeat || $c->disabled || $c->closed) {
            return false;
        }
        $now = time();
        if (self::$lastHeartbeat !== null && $now - self::$lastHeartbeat < Client::HEARTBEAT_INTERVAL) {
            return false;
        }
        if (function_exists('apcu_enabled') && @apcu_enabled()) {
            if (@apcu_add('bfocus_monitor_hb_' . Client::instanceId(), $now, Client::HEARTBEAT_INTERVAL) === false) {
                self::$lastHeartbeat = $now;

                return false;
            }
        } elseif (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' && !$hasEvents) {
            return false;
        }
        self::$lastHeartbeat = $now;

        return true;
    }

    /** @internal Fatal do `error_get_last()` + envio da fila (depois de entregar a resposta, no FPM). */
    public static function onShutdown(): void
    {
        try {
            $c = self::$client;
            if ($c === null) {
                return;
            }
            if ($c->autoCapture) {
                $err = error_get_last();
                if (is_array($err) && in_array($err['type'], self::FATAL_TYPES, true)
                    && !(self::$handledUncaught && str_starts_with((string) $err['message'], 'Uncaught '))) {
                    $c->captureFatalError($err);
                }
            }
            $hasEvents = $c->pending() > 0;
            $heartbeat = self::heartbeatDue($c, $hasEvents);
            if (!$hasEvents && !$heartbeat) {
                return;
            }
            $timeout = Client::SHUTDOWN_TIMEOUT;
            if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request(); // a resposta sai agora; o envio não a atrasa
                $timeout = Client::SHUTDOWN_TIMEOUT_DETACHED;
            }
            $start = microtime(true);
            if ($hasEvents) {
                $c->flush($timeout);
            }
            if ($heartbeat) {
                $c->sendHeartbeat(max(0.5, $timeout - (microtime(true) - $start)));
            }
        } catch (\Throwable) {
        }
    }
}
