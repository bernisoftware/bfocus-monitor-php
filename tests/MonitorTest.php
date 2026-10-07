<?php

declare(strict_types=1);

namespace Bfocus\Monitor\Tests;

use Bfocus\Monitor\Client;
use Bfocus\Monitor\Frames;
use Bfocus\Monitor\Monitor;
use Bfocus\Monitor\Tests\Support\Cases;
use Bfocus\Monitor\Tests\Support\MockServer;
use PHPUnit\Framework\TestCase;

final class MonitorTest extends TestCase
{
    protected function setUp(): void
    {
        Monitor::reset();
        MockServer::script(array_fill(0, 10, ['status' => 202, 'body' => ['accepted' => 1]]));
    }

    protected function tearDown(): void
    {
        Monitor::reset();
    }

    /** @param array<string, mixed> $extra */
    private function init(array $extra = []): Client
    {
        return Monitor::init($extra + [
            'key' => 'bf_mon_unit',
            'release' => '1.0.0',
            'base_url' => MockServer::url() . '/',
            'auto_capture' => false,
            '_retry_delay' => 0.05,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        Monitor::flush(5.0);

        return Cases::events();
    }

    public function testSemChaveEErroDeArgumento(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Monitor::init(['key' => '']);
    }

    public function testInitNaoFazRede(): void
    {
        $client = $this->init();
        $this->assertSame([], MockServer::received());
        $this->assertSame($client->baseUrl . '/api/v1/monitor/events', $client->endpoint);
        $this->assertStringEndsNotWith('//api/v1/monitor/events', $client->endpoint);
    }

    public function testSemInitNadaQuebra(): void
    {
        Monitor::captureException(new \RuntimeException('x'));
        Monitor::captureMessage('x');
        Monitor::setTag('a', 'b');
        Monitor::setUser('u', 'c');
        $this->assertTrue(Monitor::flush(0.1));
        $this->assertSame([], MockServer::received());
    }

    public function testFramesDeForaParaDentroEOThrowPorUltimo(): void
    {
        $this->init();
        try {
            $this->calcular();
        } catch (\DivisionByZeroError $e) {
            Monitor::captureException($e);
        }
        [$event] = $this->events();
        $this->assertSame([], Cases::nulls($event));
        $this->assertSame('DivisionByZeroError', $event['exception']['type']);
        $this->assertSame('Division by zero', $event['exception']['message']);
        $frames = $event['exception']['frames'];
        $last = $frames[count($frames) - 1];
        $this->assertSame(__CLASS__ . '->dividir', $last['function']);
        $this->assertSame('tests/MonitorTest.php', $last['file']);
        $this->assertTrue($last['inApp']);
        $this->assertSame(__CLASS__ . '->calcular', $frames[count($frames) - 2]['function']);
        $this->assertSame(__CLASS__ . '->' . __FUNCTION__, $frames[count($frames) - 3]['function']);
        // PHPUnit está em vendor/: não é do sistema.
        $this->assertFalse($frames[0]['inApp']);
        $this->assertSame(['name' => 'bfocus-monitor-php', 'version' => Monitor::VERSION], $event['sdk']);
        $this->assertSame('php', $event['contexts']['runtime']['name']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $event['timestamp']);
    }

    private function calcular(): int
    {
        return $this->dividir(1, 0);
    }

    private function dividir(int $a, int $b): int
    {
        return intdiv($a, $b);
    }

    public function testProprioPacoteEVendorNaoSaoDoSistema(): void
    {
        $this->assertFalse(Frames::frame(dirname(__DIR__) . '/src/Client.php', 'x', 1)['inApp']);
        $this->assertFalse(Frames::frame('/srv/app/vendor/laravel/framework/x.php', 'x', 1)['inApp']);
        $this->assertTrue(Frames::frame('/srv/app/src/x.php', 'x', 1)['inApp']);
        $this->assertSame('src/x.php', Frames::frame('/srv/app/src/x.php', 'x', 1, '/srv/app')['file']);
        $this->assertTrue(Frames::frame('/srv/app/vendor/minha/lib/x.php', 'Minha\\Lib\\X->y', 1, '', ['Minha\\Lib'])['inApp']);
    }

    public function testRaizDoProjetoForaDoVendorEODiretorioAtual(): void
    {
        $this->assertSame(getcwd(), Frames::projectRoot());
    }

    public function testExcecaoEncadeadaMandaACausaRaiz(): void
    {
        $this->init();
        $inner = new \PDOException('duplicate key');
        Monitor::captureException(new \RuntimeException('falha ao salvar', 0, $inner));
        [$event] = $this->events();
        $this->assertSame('PDOException', $event['exception']['type']);
        $this->assertSame('duplicate key (dentro de: RuntimeException: falha ao salvar)', $event['exception']['message']);
    }

    public function testTagsGlobaisEDaCaptura(): void
    {
        $this->init();
        Monitor::setTag('modulo', 'fiscal');
        Monitor::captureException(new \RuntimeException('a'), ['tags' => ['tela' => 'nf']]);
        [$event] = $this->events();
        $this->assertSame(['modulo' => 'fiscal', 'tela' => 'nf'], $event['tags']);
    }

    public function testMensagem(): void
    {
        $this->init();
        Monitor::captureMessage('estoque negativo', 'warning');
        [$event] = $this->events();
        $this->assertSame('Message', $event['exception']['type']);
        $this->assertSame('warning', $event['level']);
        $this->assertSame(['estoque negativo'], $event['fingerprint']);
        $this->assertSame([], $event['exception']['frames']);
    }

    public function testNivelInvalidoViraError(): void
    {
        $this->init();
        Monitor::captureException(new \RuntimeException('a'), ['level' => 'critico']);
        [$event] = $this->events();
        $this->assertSame('error', $event['level']);
    }

    public function testBeforeSendAlteraOuDescarta(): void
    {
        $this->init(['before_send' => static function (array $e): ?array {
            if ($e['exception']['message'] === 'descarta') {
                return null;
            }
            $e['tags'] = ['alterado' => 'sim'];

            return $e;
        }]);
        Monitor::captureException(new \RuntimeException('descarta'));
        Monitor::captureException(new \RuntimeException('fica'));
        [$event] = $this->events();
        $this->assertSame(['alterado' => 'sim'], $event['tags']);
    }

    public function testBeforeSendComErroMandaComoEsta(): void
    {
        $this->init(['before_send' => static fn (array $e): array => throw new \LogicException('bug')]);
        Monitor::captureException(new \RuntimeException('a'));
        $this->assertCount(1, $this->events());
    }

    public function testIgnoreTextoERegex(): void
    {
        $this->init(['ignore' => ['ResizeObserver', '/^timeout \d+/']]);
        Monitor::captureException(new \RuntimeException('timeout 30s'));
        Monitor::captureException(new \RuntimeException('ResizeObserver loop'));
        Monitor::captureException(new \RuntimeException('fica'));
        $this->assertCount(1, $this->events());
    }

    public function testSampleRateZero(): void
    {
        $this->init(['sample_rate' => 0]);
        Monitor::captureException(new \RuntimeException('a'));
        $this->assertSame([], $this->events());
    }

    public function testSigningSecretAusenteDoGetenvNaoAssina(): void
    {
        $this->init(['signing_secret' => false]); // getenv() de variável ausente
        Monitor::setUser(42, 7);
        Monitor::captureException(new \RuntimeException('a'));
        [$event] = $this->events();
        $this->assertSame(['externalId' => '42'], $event['user']);
        $this->assertSame(['externalId' => '7'], $event['customer']);
    }

    public function testAssinaturaRecalculadaDepoisDe6Dias(): void
    {
        $now = 1760000000;
        $this->init(['signing_secret' => 'whs_secret_A', '_clock' => static function () use (&$now): int {
            return $now;
        }]);
        Monitor::setUser('u-123', 'cliente-9');
        Monitor::captureException(new \RuntimeException('a'));
        $now += 7 * 86400;
        Monitor::captureException(new \RuntimeException('b'));
        [$a, $b] = $this->events();
        $this->assertSame(Monitor::signUser('whs_secret_A', 'u-123', 'cliente-9', 1760000000), $a['user']['userHash']);
        $this->assertSame(Monitor::signUser('whs_secret_A', 'u-123', 'cliente-9', $now), $b['user']['userHash']);
    }

    public function testEscopoDeRequisicaoNaoVazaParaOGlobal(): void
    {
        $this->init();
        $scope = Monitor::pushScope('POST /pedidos', 'https://app.example/pedidos?token=segredo#x');
        Monitor::setUser('u-1', 'c-1');
        Monitor::addBreadcrumb('db', 'SELECT');
        Monitor::captureException(new \RuntimeException('dentro'));
        Monitor::popScope($scope);
        Monitor::captureException(new \RuntimeException('fora'));
        [$dentro, $fora] = $this->events();
        $this->assertSame('POST /pedidos', $dentro['transaction']);
        $this->assertSame('https://app.example/pedidos', $dentro['url']);
        $this->assertSame('u-1', $dentro['user']['externalId']);
        $this->assertSame('SELECT', $dentro['breadcrumbs'][0]['message']);
        $this->assertArrayNotHasKey('user', $fora);
        $this->assertArrayNotHasKey('transaction', $fora);
        $this->assertArrayNotHasKey('breadcrumbs', $fora);
    }

    public function testLoteUnicoEUserAgent(): void
    {
        $this->init();
        for ($i = 0; $i < 5; $i++) {
            Monitor::captureException(new \RuntimeException("e$i"));
        }
        Monitor::flush(5.0);
        $got = MockServer::received();
        $this->assertCount(1, $got);
        $this->assertCount(5, Cases::events());
        $this->assertSame('bfocus-monitor-php/' . Monitor::VERSION, $got[0]['headers']['user-agent']);
    }

    public function testLotesDe20(): void
    {
        $this->init();
        for ($i = 0; $i < 45; $i++) {
            Monitor::captureException(new \RuntimeException("e$i"));
        }
        Monitor::flush(5.0);
        $this->assertCount(3, MockServer::received());
        $this->assertCount(45, Cases::events());
    }

    public function test5xxTentaUmaVezEDesiste(): void
    {
        MockServer::script([['status' => 503, 'body' => []], ['status' => 500, 'body' => []]]);
        $client = $this->init();
        Monitor::captureException(new \RuntimeException('a'));
        $this->assertTrue(Monitor::flush(5.0));
        $this->assertCount(2, MockServer::received());
        $this->assertFalse($client->disabled);
    }

    public function testSemTempoParaANovaTentativaDescarta(): void
    {
        MockServer::script([['status' => 429, 'body' => []]]);
        $this->init(['_retry_delay' => 3.0]);
        Monitor::captureException(new \RuntimeException('a'));
        $t = microtime(true);
        Monitor::flush(0.5);
        $this->assertLessThan(1.5, microtime(true) - $t);
        $this->assertCount(1, MockServer::received());
    }

    public function test403DesligaENovoInitReliga(): void
    {
        MockServer::script([['status' => 403, 'body' => []], ['status' => 202, 'body' => []]]);
        $client = $this->init();
        Monitor::captureException(new \RuntimeException('a'));
        Monitor::flush(5.0);
        $this->assertTrue($client->disabled);
        Monitor::captureException(new \RuntimeException('b'));
        $this->assertSame(0, $client->pending());
        $this->init();
        Monitor::captureException(new \RuntimeException('c'));
        Monitor::flush(5.0);
        $this->assertCount(2, MockServer::received());
    }

    public function test400DescartaSemDesligar(): void
    {
        MockServer::script([['status' => 400, 'body' => []]]);
        $client = $this->init();
        Monitor::captureException(new \RuntimeException('a'));
        Monitor::flush(5.0);
        $this->assertFalse($client->disabled);
        $this->assertCount(1, MockServer::received());
    }

    public function testRedeForaNaoDerruba(): void
    {
        Monitor::init(['key' => 'k', 'base_url' => 'http://127.0.0.1:' . MockServer::freePort(), 'auto_capture' => false, '_retry_delay' => 0.01]);
        Monitor::captureException(new \RuntimeException('a'));
        $this->assertTrue(Monitor::flush(5.0));
    }

    public function testTetoPorMinuto(): void
    {
        $client = $this->init();
        for ($i = 0; $i < 150; $i++) {
            Monitor::captureException(new \RuntimeException("e$i"));
        }
        $this->assertSame(100, $client->pending());
    }

    public function testEventoGiganteECortado(): void
    {
        $event = ['level' => 'error', 'exception' => ['type' => 'E', 'message' => str_repeat('x', 2000),
            'frames' => array_fill(0, 60, ['file' => str_repeat('a', 1000), 'function' => 'f', 'line' => 1, 'inApp' => true])],
            'breadcrumbs' => array_fill(0, 30, ['message' => str_repeat('m', 300)])];
        $out = Client::fit($event);
        $this->assertNotNull($out);
        $this->assertLessThanOrEqual(Client::MAX_EVENT_BYTES, strlen((string) json_encode($out)));
        $this->assertArrayNotHasKey('breadcrumbs', $out);
    }

    public function testCloseDesliga(): void
    {
        $this->init();
        Monitor::close();
        Monitor::captureException(new \RuntimeException('a'));
        Monitor::flush(0.2);
        $this->assertSame([], MockServer::received());
    }

    public function testSinalDeVidaManual401DesligaEIntervalo(): void
    {
        MockServer::script([['status' => 401, 'body' => []]]);
        $client = $this->init();
        $this->assertTrue(Monitor::heartbeat());
        $this->assertTrue($client->disabled, '401 no sinal de vida desliga o envio');
        Monitor::captureException(new \RuntimeException('depois'));
        Monitor::flush(0.5);
        $this->assertCount(1, MockServer::received());
        $this->init();
        $this->assertFalse(Monitor::heartbeat(), 'já mandou neste processo há menos de 5 min');
    }

    public function testSinalDeVidaDesligavelERedeFora(): void
    {
        $this->init(['heartbeat' => false]);
        $this->assertFalse(Monitor::heartbeat());
        Monitor::reset();
        $c = Monitor::init(['key' => 'k', 'base_url' => 'http://127.0.0.1:' . MockServer::freePort(), 'auto_capture' => false]);
        $this->assertNull($c->sendHeartbeat(0.5));
        $this->assertFalse($c->disabled);
        $this->assertSame(Client::instanceId(), $c->heartbeatBody()['instance']);
        $this->assertSame(12, strlen(Client::instanceId()));
    }

    public function testCutNaoQuebraUtf8(): void
    {
        $this->assertSame('joã', Monitor::cut('joão', 3));
    }
}
