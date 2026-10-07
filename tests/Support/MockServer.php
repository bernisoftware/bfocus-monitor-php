<?php

declare(strict_types=1);

namespace Bfocus\Monitor\Tests\Support;

/**
 * Sobe (uma vez por processo) o servidor embutido do PHP com o roteador `tests/server.php`.
 *
 * Fluxo de um teste: `script([...respostas])` → chamada da SDK → `received()` para conferir.
 */
final class MockServer
{
    /** @var resource|null */
    private static $process = null;

    private static int $port = 0;

    private static string $dir = '';

    public static function url(): string
    {
        self::start();

        return 'http://127.0.0.1:' . self::$port;
    }

    /**
     * Define as respostas (em ordem) e zera o registro de requisições.
     *
     * @param array<int, mixed> $responses cada uma: {status, headers?, body, delay_ms?}
     */
    public static function script(array $responses): void
    {
        self::start();
        file_put_contents(self::$dir . '/script.json', json_encode(array_values($responses), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        file_put_contents(self::$dir . '/requests.jsonl', '');
    }

    /**
     * Requisições recebidas desde o último `script()`.
     *
     * @return list<array{method: string, uri: string, headers: array<string, string>, body: string}>
     */
    public static function received(): array
    {
        $lines = file(self::$dir . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines === false ? [] : $lines,
        );
    }

    /** Porta TCP livre no momento (para o servidor ou para simular "fora do ar"). */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException("não consegui reservar uma porta: $errstr");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) strrchr($name, ':'), 1);
    }

    public static function start(): void
    {
        if (self::$process !== null) {
            return;
        }

        self::$dir = sys_get_temp_dir() . '/bfocus-monitor-php-mock-' . bin2hex(random_bytes(6));
        if (!mkdir(self::$dir, 0777, true) && !is_dir(self::$dir)) {
            throw new \RuntimeException('não consegui criar ' . self::$dir);
        }
        file_put_contents(self::$dir . '/script.json', '[]');
        file_put_contents(self::$dir . '/requests.jsonl', '');

        $env = getenv();
        unset($env['PHP_CLI_SERVER_WORKERS']); // um worker só: requisições em série, índice estável
        $env['BFOCUS_MOCK_DIR'] = self::$dir;

        for ($try = 0; $try < 3 && self::$process === null; $try++) {
            $port = self::freePort();
            $process = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', self::$dir, dirname(__DIR__) . '/server.php'],
                [0 => ['pipe', 'r'], 1 => ['file', self::$dir . '/server.log', 'a'], 2 => ['file', self::$dir . '/server.log', 'a']],
                $pipes,
                null,
                $env,
            );
            if (!is_resource($process)) {
                continue;
            }
            if (self::waitUntilListening($port)) {
                self::$process = $process;
                self::$port = $port;
                register_shutdown_function([self::class, 'stop']);

                return;
            }
            proc_terminate($process);
            proc_close($process);
        }

        throw new \RuntimeException('servidor de teste não subiu: ' . @file_get_contents(self::$dir . '/server.log'));
    }

    public static function stop(): void
    {
        if (self::$process === null) {
            return;
        }
        proc_terminate(self::$process);
        proc_close(self::$process);
        self::$process = null;
        foreach (glob(self::$dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$dir);
    }

    private static function waitUntilListening(int $port): bool
    {
        for ($i = 0; $i < 100; $i++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($conn)) {
                fclose($conn);

                return true;
            }
            usleep(50_000);
        }

        return false;
    }
}
