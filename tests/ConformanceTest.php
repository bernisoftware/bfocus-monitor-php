<?php

declare(strict_types=1);

namespace Bfocus\Monitor\Tests;

use Bfocus\Monitor\Frames;
use Bfocus\Monitor\Monitor;
use Bfocus\Monitor\Tests\Support\Cases;
use Bfocus\Monitor\Tests\Support\MockServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Conformidade (monitor/BRIEF.md §8): cada caso de `cases.json` contra o servidor local
 * (`php -S` com o roteador `tests/server.php`, que grava as requisições e responde o roteiro).
 */
final class ConformanceTest extends TestCase
{
    protected function setUp(): void
    {
        Monitor::reset();
    }

    protected function tearDown(): void
    {
        Monitor::reset();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function sendCases(): iterable
    {
        foreach (Cases::load()['send'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    public function testHaTodosOsCasos(): void
    {
        $this->assertGreaterThanOrEqual(10, count(Cases::load()['send']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('sendCases')]
    public function testEnvio(array $case): void
    {
        MockServer::script(array_map(static fn (array $r): array => $r['respond'], $case['requests']));
        $opts = $case['init'];
        $su = $case['set_user'] ?? [];
        $client = Monitor::init(array_filter([
            'key' => $opts['key'],
            'release' => $opts['release'] ?? null,
            'environment' => $opts['environment'] ?? null,
            'signing_secret' => $opts['signing_secret'] ?? null,
            'ignore' => $opts['ignore'] ?? null,
            'base_url' => MockServer::url(),
            'auto_capture' => false,
            '_retry_delay' => 0.05,
            '_clock' => isset($su['ts']) ? static fn (): int => (int) $su['ts'] : null,
        ], static fn ($v): bool => $v !== null));

        if ($su !== []) {
            Monitor::setUser($su['user_external_id'], $su['customer_external_id'], $su['user_hash'] ?? null);
        }
        foreach ($case['breadcrumbs'] ?? [] as $crumb) {
            Monitor::addBreadcrumb($crumb['category'], $crumb['message'], $crumb['level'] ?? 'info');
        }
        for ($i = 0; $i < (int) ($case['repeat'] ?? 1); $i++) {
            self::capture($case['capture']);
        }
        $this->assertTrue(Monitor::flush(5.0), 'flush não esvaziou a fila');
        if (isset($case['then_capture'])) {
            self::capture($case['then_capture']);
            Monitor::flush(2.0);
        }

        $got = MockServer::received();
        $this->assertCount(count($case['requests']), $got, 'número de requisições');
        foreach ($case['requests'] as $i => $step) {
            $exp = $step['expect'];
            $rec = $got[$i];
            $this->assertSame($exp['method'], $rec['method']);
            $this->assertSame($exp['path'], $rec['uri'], 'a chave vai no header, nunca na URL');
            foreach ($exp['headers'] as $name => $value) {
                $this->assertSame($value, $rec['headers'][strtolower($name)] ?? null, $name);
            }
            foreach ($exp['header_prefix'] as $name => $prefix) {
                $this->assertStringStartsWith($prefix, $rec['headers'][strtolower($name)] ?? '', $name);
            }
            $this->assertSame('bfocus-monitor-php/' . Monitor::VERSION, $rec['headers']['x-bfocus-client'] ?? null);
            $body = json_decode($rec['body'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['events'], array_keys($body));
            $this->assertCount(1, $body['events']);
            $event = $body['events'][0];
            $this->assertSame([], Cases::nulls($event), 'evento com campo nulo');
            foreach ($exp['event'] as $path => $expected) {
                if ($expected === '$version') {
                    $expected = Monitor::VERSION;
                }
                $this->assertSame($expected, Cases::get($event, $path), $path);
            }
        }
        if (count($got) === 2) {
            $this->assertSame($got[0]['body'], $got[1]['body'], 'nova tentativa com o mesmo corpo');
        }

        if ($case['after'] === 'disabled') {
            $this->assertTrue($client->disabled);
        } else {
            $this->assertFalse($client->disabled);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function heartbeatCases(): iterable
    {
        foreach (Cases::load()['heartbeat'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /**
     * No PHP o sinal de vida do init sai no encerramento (junto da fila, depois de entregar a
     * resposta no FPM): aqui o encerramento é chamado à mão.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('heartbeatCases')]
    public function testSinalDeVida(array $case): void
    {
        MockServer::script([$case['respond']]);
        $opts = $case['init'];
        $client = Monitor::init([
            'key' => $opts['key'],
            'release' => $opts['release'] ?? null,
            'environment' => $opts['environment'] ?? null,
            'base_url' => MockServer::url(),
            'auto_capture' => false,
        ]);
        $this->assertTrue(Monitor::flush(1.0));
        $this->assertSame([], MockServer::received(), 'flush não manda sinal de vida');
        Monitor::onShutdown();
        $got = MockServer::received();
        $this->assertCount(1, $got);
        $exp = $case['expect'];
        $rec = $got[0];
        $this->assertSame($exp['method'], $rec['method']);
        $this->assertSame($exp['path'], $rec['uri']);
        foreach ($exp['headers'] as $name => $value) {
            $this->assertSame($value, $rec['headers'][strtolower($name)] ?? null, $name);
        }
        foreach ($exp['header_prefix'] as $name => $prefix) {
            $this->assertStringStartsWith($prefix, $rec['headers'][strtolower($name)] ?? '', $name);
        }
        $body = json_decode($rec['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], Cases::nulls($body));
        foreach ($exp['body'] as $path => $expected) {
            $this->assertSame($expected === '$version' ? Monitor::VERSION : $expected, Cases::get($body, $path), $path);
        }
        foreach ($exp['body_present'] as $path) {
            $this->assertNotEmpty(Cases::get($body, $path), $path);
        }
        $this->assertSame('bfocus-monitor-php', $body['sdk']['name']);
        $this->assertSame('php', $body['runtime']['name']);
        $this->assertFalse($client->disabled);
        // no máximo 1 a cada 5 min por processo
        Monitor::onShutdown();
        $this->assertCount(1, MockServer::received());
    }

    public function testVetoresDaAssinatura(): void
    {
        foreach (Cases::load()['user_hash'] as $v) {
            $this->assertSame(
                $v['expected'],
                Monitor::signUser($v['secret'], $v['user_external_id'], $v['customer_external_id'], $v['ts']),
                $v['user_external_id'],
            );
        }
    }

    public function testFrames(): void
    {
        foreach (Cases::load()['frames'] as $case) {
            // No PHP, "biblioteca" é o que está em vendor/: o rastro neutro vira um caminho de vendor.
            $php = static fn (array $f): string => !empty($f['library']) ? '/srv/app/vendor' . $f['file'] : $f['file'];
            $ro = $case['runtime_order']; // de dentro para fora, como o runtime dá
            // Formato do getTrace(): cada item traz o arquivo/linha da CHAMADA e a função chamada.
            $trace = [];
            for ($i = 0; $i < count($ro); $i++) {
                $item = ['function' => $ro[$i]['function']];
                if (isset($ro[$i + 1])) {
                    $item['file'] = $php($ro[$i + 1]);
                    $item['line'] = $ro[$i + 1]['line'];
                }
                $trace[] = $item;
            }
            $got = Frames::fromTrace($php($ro[0]), $ro[0]['line'], $trace, '');
            $expected = array_map(static function (array $f) use ($case, $php): array {
                $lib = !$f['inApp'];
                $f['file'] = $lib ? '/srv/app/vendor' . $f['file'] : $f['file'];

                return $f;
            }, $case['expected']);
            $this->assertEquals($expected, $got, $case['name']);
            $this->assertSame(array_column($case['expected'], 'inApp'), array_column($got, 'inApp'));
        }
    }

    /** @param array<string, mixed> $spec */
    private static function capture(array $spec): void
    {
        if ($spec['kind'] === 'message') {
            Monitor::captureMessage($spec['message'], $spec['level'] ?? 'info');

            return;
        }
        $class = self::throwableClass($spec['type']);
        try {
            self::boom($class, $spec['message']);
        } catch (\Throwable $e) {
            Monitor::captureException($e, array_filter([
                'level' => $spec['level'] ?? null,
                'tags' => $spec['tags'] ?? null,
                'fingerprint' => $spec['fingerprint'] ?? null,
            ], static fn ($v): bool => $v !== null));
        }
    }

    private static function boom(string $class, string $message): never
    {
        throw new $class($message); // mesma linha sempre: o "mesmo erro" do caso de repetição
    }

    /** O tipo do caso como classe do PHP: a nativa se existir (ValueError, Error), senão uma criada. */
    private static function throwableClass(string $type): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $type)) {
            throw new \InvalidArgumentException($type);
        }
        if (!class_exists($type)) {
            eval("class {$type} extends \\Exception {}");
        }

        return $type;
    }
}
