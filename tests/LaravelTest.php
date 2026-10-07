<?php

declare(strict_types=1);

namespace Bfocus\Monitor\Tests;

use Bfocus\Monitor\Laravel;
use Bfocus\Monitor\Monitor;
use Bfocus\Monitor\Tests\Support\Cases;
use Bfocus\Monitor\Tests\Support\MockServer;
use PHPUnit\Framework\TestCase;

/** Sem o Laravel instalado: dublês com a mesma forma de `Exceptions` (11+) e do `Handler`. */
final class LaravelTest extends TestCase
{
    protected function setUp(): void
    {
        Monitor::reset();
        MockServer::script([['status' => 202, 'body' => ['accepted' => 1]]]);
    }

    protected function tearDown(): void
    {
        Monitor::reset();
        foreach (['BFOCUS_MONITOR_KEY', 'BFOCUS_MONITOR_RELEASE', 'APP_ENV', 'BFOCUS_SIGNING_SECRET'] as $k) {
            putenv($k);
            unset($_ENV[$k]);
        }
    }

    public function testRegisterComReportableLigaPreguicosoPeloAmbiente(): void
    {
        $exceptions = new class {
            /** @var list<callable> */
            public array $callbacks = [];

            public function reportable(callable $cb): object
            {
                $this->callbacks[] = $cb;

                return $this;
            }
        };
        Laravel::register($exceptions, ['base_url' => MockServer::url()]);
        $this->assertCount(1, $exceptions->callbacks);
        $this->assertNull(Monitor::getClient(), 'init preguiçoso: nada no register');

        $_ENV['BFOCUS_MONITOR_KEY'] = 'bf_mon_laravel';
        $_ENV['BFOCUS_MONITOR_RELEASE'] = '3.2.1';
        $_ENV['APP_ENV'] = 'staging';
        $_ENV['BFOCUS_SIGNING_SECRET'] = 'whs_secret_A';
        $result = ($exceptions->callbacks[0])(new \RuntimeException('no controller'));
        $this->assertNull($result, 'nunca devolve false: o log do Laravel continua');

        $client = Monitor::getClient();
        $this->assertNotNull($client);
        $this->assertFalse($client->autoCapture, 'o Laravel já trata o não tratado; ganchos duplicariam');
        $this->assertSame('bf_mon_laravel', $client->key);
        $this->assertSame('whs_secret_A', $client->signingSecret);
        Monitor::flush(5.0);
        [$event] = Cases::events();
        $this->assertSame('3.2.1', $event['release']);
        $this->assertSame('staging', $event['environment']);
        $this->assertSame('no controller', $event['exception']['message']);
    }

    public function testSemChaveNaoFazNada(): void
    {
        $exceptions = new class {
            public ?\Closure $cb = null;

            public function reportable(callable $cb): void
            {
                $this->cb = \Closure::fromCallable($cb);
            }
        };
        Laravel::register($exceptions);
        ($exceptions->cb)(new \RuntimeException('x'));
        $this->assertNull(Monitor::getClient());
    }

    public function testSoComReport(): void
    {
        $exceptions = new class {
            public ?\Closure $cb = null;

            public function report(callable $cb): void
            {
                $this->cb = \Closure::fromCallable($cb);
            }
        };
        Monitor::init(['key' => 'k', 'base_url' => MockServer::url(), 'auto_capture' => false]);
        Laravel::register($exceptions);
        ($exceptions->cb)(new \LogicException('via report'));
        Monitor::flush(5.0);
        [$event] = Cases::events();
        $this->assertSame('LogicException', $event['exception']['type']);
    }
}
