<?php

declare(strict_types=1);

namespace Bfocus\Monitor\Tests;

use Bfocus\Monitor\Monitor;
use Bfocus\Monitor\Psr15Middleware;
use Bfocus\Monitor\Tests\Support\Cases;
use Bfocus\Monitor\Tests\Support\MockServer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Com `psr/http-server-middleware` instalado (dev-dependency) a classe existe e funciona. */
final class Psr15MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        Monitor::reset();
        MockServer::script([['status' => 202, 'body' => ['accepted' => 1]]]);
        putenv('BFOCUS_MONITOR_KEY');
        unset($_ENV['BFOCUS_MONITOR_KEY'], $_SERVER['BFOCUS_MONITOR_KEY']);
    }

    protected function tearDown(): void
    {
        Monitor::reset();
    }

    private function request(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/pedidos/9');
        $uri->method('getHost')->willReturn('app.example');
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getPort')->willReturn(null);
        $uri->method('getQuery')->willReturn('token=segredo');
        $req = $this->createStub(ServerRequestInterface::class);
        $req->method('getUri')->willReturn($uri);
        $req->method('getMethod')->willReturn('POST');

        return $req;
    }

    public function testImplementaAInterface(): void
    {
        Monitor::init(['key' => 'k', 'base_url' => MockServer::url(), 'auto_capture' => false]);
        $this->assertInstanceOf(MiddlewareInterface::class, new Psr15Middleware());
    }

    public function testCapturaERelanca(): void
    {
        Monitor::init(['key' => 'bf_mon_psr15', 'base_url' => MockServer::url(), 'auto_capture' => false]);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                Monitor::setUser('u-1', 'c-1');
                throw new \DomainException('na rota');
            }
        };
        try {
            (new Psr15Middleware())->process($this->request(), $handler);
            $this->fail('a exceção tem de continuar subindo');
        } catch (\DomainException $e) {
            $this->assertSame('na rota', $e->getMessage());
        }
        Monitor::flush(5.0);
        [$event] = Cases::events();
        $this->assertSame('POST /pedidos/9', $event['transaction']);
        $this->assertSame('https://app.example/pedidos/9', $event['url']);
        $this->assertSame('u-1', $event['user']['externalId']);
        $this->assertSame('DomainException', $event['exception']['type']);
        // O escopo fechou: a identidade não ficou para a próxima requisição.
        $this->assertNull(Monitor::currentScope()->userId);
    }

    public function testSemErroDevolveAResposta(): void
    {
        Monitor::init(['key' => 'k', 'base_url' => MockServer::url(), 'auto_capture' => false]);
        $response = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);
        $this->assertSame($response, (new Psr15Middleware())->process($this->request(), $handler));
        Monitor::flush(1.0);
        $this->assertSame([], MockServer::received());
    }

    public function testLigaPeloAmbienteQuandoNaoHaInit(): void
    {
        putenv('BFOCUS_MONITOR_KEY=bf_mon_env');
        try {
            new Psr15Middleware(['base_url' => MockServer::url(), 'auto_capture' => false]);
            $this->assertNotNull(Monitor::getClient());
            $this->assertSame('bf_mon_env', Monitor::getClient()->key);
            $this->assertFalse(Monitor::getClient()->autoCapture);
        } finally {
            putenv('BFOCUS_MONITOR_KEY');
        }
    }
}
