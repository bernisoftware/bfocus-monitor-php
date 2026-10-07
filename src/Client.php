<?php

declare(strict_types=1);

namespace Bfocus\Monitor;

/**
 * Núcleo: monta o evento, segura a fila e envia. Use a fachada {@see Monitor}.
 *
 * O PHP não tem thread: os eventos ficam na fila (até 100; cheia → descarta o mais novo) e saem
 * no encerramento (`register_shutdown_function`) ou num `flush()` manual. No PHP-FPM o envio
 * acontece DEPOIS de `fastcgi_finish_request()` — a resposta já foi entregue ao usuário.
 *
 * Nunca derruba o app: toda falha do monitor (rede, serialização, bug nosso) é engolida.
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.bfocus.com.br';
    public const EVENTS_PATH = '/api/v1/monitor/events';
    public const HEARTBEAT_PATH = '/api/v1/monitor/heartbeat';
    public const HEARTBEAT_INTERVAL = 300;
    public const LEVELS = ['fatal', 'error', 'warning', 'info'];
    public const MAX_QUEUE = 100;
    public const BATCH_SIZE = 20;
    public const RETRY_DELAY = 2.0;
    public const DEDUPE_SECONDS = 30.0;
    public const MAX_PER_MINUTE = 100;
    public const MAX_CRUMBS = 30;
    public const MAX_FRAMES = 60;
    public const MAX_TAGS = 20;
    public const MAX_MESSAGE = 2000;
    public const MAX_EVENT_BYTES = 65536;
    public const MAX_CHAIN = 10;
    public const SIGN_MAX_AGE = 6 * 86400;
    public const SHUTDOWN_TIMEOUT = 2.0;
    /** Com a resposta já entregue (fastcgi_finish_request) dá tempo da nova tentativa. */
    public const SHUTDOWN_TIMEOUT_DETACHED = 6.0;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR;

    public readonly string $key;
    public readonly ?string $release;
    public readonly string $environment;
    public readonly string $baseUrl;
    public readonly string $endpoint;
    public readonly string $heartbeatUrl;
    public readonly bool $heartbeat;
    public readonly float $sampleRate;
    /** @var list<string> */
    public readonly array $ignore;
    /** @var (callable(array<string, mixed>): ?array<string, mixed>)|null */
    private $beforeSend;
    public readonly ?string $signingSecret;
    public readonly bool $autoCapture;
    /** @var list<string> */
    public readonly array $inAppPrefixes;
    public readonly string $projectRoot;
    private float $retryDelay;
    /** @var callable(): int */
    private $clock;
    /** @var array<string, array<string, string>> */
    private array $contexts;

    public bool $disabled = false;
    public bool $closed = false;
    /** @var list<array<string, mixed>> */
    private array $queue = [];
    /** @var array<string, float> */
    private array $seen = [];
    /** @var list<float> */
    private array $minute = [];
    private bool $flushing = false;

    /** @param array<string, mixed> $options */
    public function __construct(array $options)
    {
        $key = $options['key'] ?? null;
        if (!is_string($key) || trim($key) === '') {
            throw new \InvalidArgumentException("Bfocus\\Monitor: 'key' é obrigatória (a chave do agente, bf_mon_…)");
        }
        $this->key = trim($key);
        $release = $options['release'] ?? null;
        $this->release = is_scalar($release) && (string) $release !== '' ? (string) $release : null;
        $env = $options['environment'] ?? null;
        $this->environment = is_scalar($env) && (string) $env !== '' ? (string) $env : 'production';
        $base = $options['base_url'] ?? null;
        $this->baseUrl = rtrim(is_string($base) && $base !== '' ? $base : self::DEFAULT_BASE_URL, '/');
        $this->endpoint = $this->baseUrl . self::EVENTS_PATH;
        $this->heartbeatUrl = $this->baseUrl . self::HEARTBEAT_PATH;
        $this->heartbeat = (bool) ($options['heartbeat'] ?? true);
        $rate = $options['sample_rate'] ?? 1.0;
        $this->sampleRate = is_numeric($rate) ? max(0.0, min(1.0, (float) $rate)) : 1.0;
        $this->ignore = array_values(array_filter(array_map('strval', (array) ($options['ignore'] ?? [])), static fn (string $s): bool => $s !== ''));
        $bs = $options['before_send'] ?? null;
        $this->beforeSend = is_callable($bs) ? $bs : null;
        $secret = $options['signing_secret'] ?? null;
        $this->signingSecret = is_string($secret) && $secret !== '' ? $secret : null; // getenv() ausente = false
        $this->autoCapture = (bool) ($options['auto_capture'] ?? true);
        $this->inAppPrefixes = array_values(array_filter(array_map('strval', (array) ($options['in_app_prefixes'] ?? [])), static fn (string $s): bool => $s !== ''));
        $root = $options['project_root'] ?? null;
        $this->projectRoot = is_string($root) && $root !== '' ? $root : Frames::projectRoot();
        $this->retryDelay = (float) ($options['_retry_delay'] ?? self::RETRY_DELAY);
        $clock = $options['_clock'] ?? null;
        $this->clock = is_callable($clock) ? $clock : static fn (): int => time();
        $this->contexts = self::runtimeContexts();
    }

    // ── captura ──

    /** @param array{level?: string, tags?: array<string, mixed>, fingerprint?: list<string>} $extra */
    public function captureException(\Throwable $e, array $extra = []): void
    {
        try {
            $chain = [$e];
            $cur = $e;
            while (count($chain) < self::MAX_CHAIN && ($prev = $cur->getPrevious()) !== null && !in_array($prev, $chain, true)) {
                $chain[] = $prev;
                $cur = $prev;
            }
            $inner = $chain[count($chain) - 1];
            $message = $inner->getMessage();
            if ($inner !== $e) {
                $message .= ' (dentro de: ' . get_class($e) . ': ' . $e->getMessage() . ')';
            }
            $frames = Frames::fromThrowable($inner, $this->projectRoot, $this->inAppPrefixes);
            $this->enqueue(get_class($inner), $message, $frames, (string) ($extra['level'] ?? 'error'), $extra['tags'] ?? null, $extra['fingerprint'] ?? null);
        } catch (\Throwable) {
            // nunca derruba o app
        }
    }

    /**
     * @param array<string, mixed>|null $tags
     * @param list<string>|null $fingerprint
     */
    public function captureMessage(string $message, string $level = 'info', ?array $tags = null, ?array $fingerprint = null): void
    {
        try {
            $this->enqueue('Message', $message, [], $level, $tags, $fingerprint ?: [Monitor::cut($message, 200)]);
        } catch (\Throwable) {
        }
    }

    /**
     * Erro fatal visto no encerramento (`error_get_last()`): não há Throwable, só arquivo e linha.
     *
     * @param array{type: int, message: string, file: string, line: int} $error
     */
    public function captureFatalError(array $error): void
    {
        try {
            $types = [E_ERROR => 'FatalError', E_PARSE => 'ParseError', E_CORE_ERROR => 'CoreError', E_COMPILE_ERROR => 'CompileError'];
            $type = $types[$error['type']] ?? 'FatalError';
            $frames = [Frames::frame((string) $error['file'], null, (int) $error['line'], $this->projectRoot, $this->inAppPrefixes)];
            $this->enqueue($type, (string) $error['message'], $frames, 'fatal', null, null);
        } catch (\Throwable) {
        }
    }

    private function ignored(string $message): bool
    {
        foreach ($this->ignore as $rule) {
            // "/…/" ou "#…#" válido é expressão regular; o resto é texto contido.
            if (($rule[0] === '/' || $rule[0] === '#') && strlen($rule) > 2 && @preg_match($rule, '') !== false) {
                if (@preg_match($rule, $message) === 1) {
                    return true;
                }
                continue;
            }
            if (str_contains($message, $rule)) {
                return true;
            }
        }

        return false;
    }

    private function allow(string $key): bool
    {
        $now = microtime(true);
        if (isset($this->seen[$key]) && $now - $this->seen[$key] < self::DEDUPE_SECONDS) {
            return false;
        }
        $this->minute = array_values(array_filter($this->minute, static fn (float $t): bool => $now - $t < 60.0));
        if (count($this->minute) >= self::MAX_PER_MINUTE) {
            return false;
        }
        $this->seen[$key] = $now;
        $this->minute[] = $now;
        if (count($this->seen) > 1000) {
            $this->seen = array_filter($this->seen, static fn (float $t): bool => $now - $t < self::DEDUPE_SECONDS);
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $frames
     * @param array<string, mixed>|null $tags
     * @param list<string>|null $fingerprint
     */
    private function enqueue(string $type, string $message, array $frames, string $level, ?array $tags, ?array $fingerprint): void
    {
        if ($this->closed || $this->disabled) {
            return;
        }
        if ($this->ignored($message)) {
            return;
        }
        if ($this->sampleRate < 1.0 && mt_rand() / mt_getrandmax() >= $this->sampleRate) {
            return;
        }
        $top = [];
        foreach (array_reverse($frames) as $f) {
            if (!empty($f['inApp'])) {
                $top = $f;
                break;
            }
        }
        if ($top === [] && $frames !== []) {
            $top = $frames[count($frames) - 1];
        }
        if (!$this->allow($type . '|' . $message . '|' . ($top['file'] ?? '') . ':' . ($top['line'] ?? ''))) {
            return;
        }
        $event = $this->build($type, $message, $frames, $level, $tags, $fingerprint);
        if ($this->beforeSend !== null) {
            try {
                $event = ($this->beforeSend)($event);
            } catch (\Throwable) {
                // beforeSend com erro: manda como está
            }
        }
        if (!is_array($event)) {
            return;
        }
        $event = self::fit($event);
        if ($event === null || count($this->queue) >= self::MAX_QUEUE) {
            return; // cheia: descarta o mais novo
        }
        $this->queue[] = $event;
    }

    /**
     * @param list<array<string, mixed>> $frames
     * @param array<string, mixed>|null $tags
     * @param list<string>|null $fingerprint
     *
     * @return array<string, mixed>
     */
    private function build(string $type, string $message, array $frames, string $level, ?array $tags, ?array $fingerprint): array
    {
        $scopes = Monitor::scopes();
        $mergedTags = [];
        $crumbs = [];
        $who = null;
        $transaction = null;
        $url = null;
        foreach ($scopes as $s) {
            $mergedTags = array_merge($mergedTags, $s->tags);
            array_push($crumbs, ...$s->breadcrumbs);
            if ($s->userId !== null) {
                $who = $s;
            }
            $transaction = $s->transaction ?? $transaction;
            $url = $s->url ?? $url;
        }
        if ($transaction === null && $url === null) {
            [$transaction, $url] = self::fromServerGlobals();
        }
        foreach ($tags ?? [] as $k => $v) {
            if (is_scalar($v)) {
                $mergedTags[Monitor::cut((string) $k, 64)] = Monitor::cut((string) $v, 200);
            }
        }
        $event = [
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'level' => in_array($level, self::LEVELS, true) ? $level : 'error',
            'release' => $this->release,
            'environment' => $this->environment,
            'exception' => ['type' => $type, 'message' => Monitor::cut($message, self::MAX_MESSAGE), 'frames' => $frames],
            'transaction' => $transaction,
            'url' => $url,
        ];
        if ($who !== null) {
            $user = ['externalId' => (string) $who->userId];
            $hash = $who->hashFor($this->signingSecret, (int) ($this->clock)());
            if ($hash !== null) {
                $user['userHash'] = $hash;
            }
            $event['user'] = $user;
            if ($who->customerId !== null && $who->customerId !== '') {
                $event['customer'] = ['externalId' => $who->customerId];
            }
        }
        if ($mergedTags !== []) {
            $event['tags'] = array_slice($mergedTags, 0, self::MAX_TAGS, true);
        }
        if ($crumbs !== []) {
            $event['breadcrumbs'] = array_slice($crumbs, -self::MAX_CRUMBS);
        }
        if ($fingerprint) {
            $event['fingerprint'] = array_map(static fn ($x): string => Monitor::cut((string) $x, 200), array_slice(array_values($fingerprint), 0, 10));
        }
        if ($this->contexts !== []) {
            $event['contexts'] = $this->contexts;
        }
        $event['sdk'] = ['name' => Monitor::SDK_NAME, 'version' => Monitor::VERSION];

        return array_filter($event, static fn ($v): bool => $v !== null);
    }

    /** @return array{0: ?string, 1: ?string} transação e URL da requisição corrente (sem query). */
    private static function fromServerGlobals(): array
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' || !isset($_SERVER['REQUEST_METHOD'])) {
            return [null, null];
        }
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';

        return [
            $_SERVER['REQUEST_METHOD'] . ' ' . ($path !== '' ? $path : '/'),
            $host !== '' ? ($https ? 'https' : 'http') . '://' . $host . $path : null,
        ];
    }

    /** @return array<string, array<string, string>> */
    private static function runtimeContexts(): array
    {
        $ctx = ['runtime' => ['name' => 'php', 'version' => PHP_VERSION]];
        $os = ['name' => PHP_OS_FAMILY];
        if (function_exists('php_uname')) {
            $release = @php_uname('r');
            if (is_string($release) && $release !== '') {
                $os['version'] = $release;
            }
        }
        $ctx['os'] = $os;

        return $ctx;
    }

    /**
     * Cada evento ≤ 64 KB: corta passos, frames e mensagem antes de passar disso.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>|null
     */
    public static function fit(array $event): ?array
    {
        $size = static fn (array $e): int => strlen((string) json_encode($e, self::JSON_FLAGS));
        if ($size($event) <= self::MAX_EVENT_BYTES) {
            return $event;
        }
        $steps = [
            static function (array &$e): void { unset($e['breadcrumbs']); },
            static function (array &$e): void { $e['exception']['frames'] = array_slice($e['exception']['frames'] ?? [], -20); },
            static function (array &$e): void { $e['exception']['message'] = Monitor::cut((string) ($e['exception']['message'] ?? ''), 500); },
            static function (array &$e): void { $e['exception']['frames'] = array_slice($e['exception']['frames'] ?? [], -5); },
            static function (array &$e): void { unset($e['tags']); },
            static function (array &$e): void { unset($e['contexts']); },
        ];
        foreach ($steps as $step) {
            $step($event);
            if ($size($event) <= self::MAX_EVENT_BYTES) {
                return $event;
            }
        }

        return null;
    }

    // ── envio ──

    public function pending(): int
    {
        return count($this->queue);
    }

    /** Envia a fila em lotes. Bloqueia até `$timeout` segundos. True se esvaziou. */
    public function flush(float $timeout = 2.0): bool
    {
        if ($this->flushing) {
            return false;
        }
        $this->flushing = true;
        try {
            $deadline = microtime(true) + max(0.0, $timeout);
            while ($this->queue !== [] && !$this->disabled) {
                if (microtime(true) >= $deadline) {
                    return false;
                }
                $batch = array_splice($this->queue, 0, self::BATCH_SIZE);
                $this->send($batch, $deadline);
            }
            if ($this->disabled) {
                $this->queue = [];
            }

            return $this->queue === [];
        } catch (\Throwable) {
            return false;
        } finally {
            $this->flushing = false;
        }
    }

    /** @param list<array<string, mixed>> $batch */
    private function send(array $batch, float $deadline): void
    {
        $body = json_encode(['events' => $batch], self::JSON_FLAGS);
        if (!is_string($body)) {
            return;
        }
        $status = $this->post($body, $deadline);
        if ($status === null || $status === 429 || $status >= 500) {
            if (microtime(true) + $this->retryDelay >= $deadline) {
                return; // sem tempo para a nova tentativa: descarta o lote
            }
            usleep((int) ($this->retryDelay * 1_000_000));
            $status = $this->post($body, $deadline);
        }
        if ($status === 401 || $status === 403) {
            // Chave errada/revogada, módulo desligado: nunca martelar a API (até o próximo init).
            $this->disabled = true;
            $this->queue = [];
        }
    }

    private function post(string $body, float $deadline, ?string $url = null): ?int
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $remainingMs = (int) max(300, min(5000, ($deadline - microtime(true)) * 1000));
        $ch = curl_init($url ?? $this->endpoint);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-bFocus-Monitor-Key: ' . $this->key,
                'Content-Type: application/json',
                'X-bFocus-Client: ' . Monitor::CLIENT_ID,
                'Expect:',
            ],
            CURLOPT_USERAGENT => Monitor::CLIENT_ID,
            CURLOPT_CONNECTTIMEOUT_MS => min(2000, $remainingMs),
            CURLOPT_TIMEOUT_MS => $remainingMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch); // curl_close() é no-op desde o 8.0 e obsoleto no 8.5

        return $ok === false || $status === 0 ? null : $status;
    }

    /** @return array<string, mixed> corpo do sinal de vida (sem campos nulos) */
    public function heartbeatBody(): array
    {
        $host = function_exists('gethostname') ? @gethostname() : false;
        $host = is_string($host) && $host !== '' ? $host : null;
        $body = [
            'instance' => self::instanceId(),
            'release' => $this->release,
            'environment' => $this->environment,
            'host' => $host !== null ? Monitor::cut($host, 200) : null,
            'runtime' => $this->contexts['runtime'] ?? null,
            'sdk' => ['name' => Monitor::SDK_NAME, 'version' => Monitor::VERSION],
        ];

        return array_filter($body, static fn ($v): bool => $v !== null);
    }

    /** Id estável do processo: hash curto de hostname + pid. */
    public static function instanceId(): string
    {
        $host = function_exists('gethostname') ? (string) @gethostname() : '';

        return substr(sha1($host . ':' . (int) getmypid()), 0, 12);
    }

    /**
     * Um sinal de vida (sem a regra dos 5 min — ela fica em {@see Monitor}). 401/403 desliga o
     * envio, como nos eventos; falha de rede é ignorada (o próximo tenta).
     */
    public function sendHeartbeat(float $timeout = 2.0): ?int
    {
        if ($this->closed || $this->disabled || !$this->heartbeat) {
            return null;
        }
        try {
            $body = json_encode($this->heartbeatBody(), self::JSON_FLAGS);
            if (!is_string($body)) {
                return null;
            }
            $status = $this->post($body, microtime(true) + max(0.3, $timeout), $this->heartbeatUrl);
            if ($status === 401 || $status === 403) {
                $this->disabled = true;
                $this->queue = [];
            }

            return $status;
        } catch (\Throwable) {
            return null;
        }
    }

    public function close(float $timeout = 2.0): void
    {
        $this->flush($timeout);
        $this->closed = true;
    }
}
