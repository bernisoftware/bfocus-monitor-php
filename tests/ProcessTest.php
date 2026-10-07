<?php

declare(strict_types=1);

namespace Bfocus\Monitor\Tests;

use Bfocus\Monitor\Tests\Support\Cases;
use Bfocus\Monitor\Tests\Support\MockServer;
use PHPUnit\Framework\TestCase;

/** Processos PHP de verdade: o gancho, o encerramento e o PHP quebrando como sempre. */
final class ProcessTest extends TestCase
{
    protected function setUp(): void
    {
        MockServer::script([['status' => 202, 'body' => ['accepted' => 1]]]);
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function runScript(string $body, array $ini = []): array
    {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        $url = var_export(MockServer::url(), true);
        $code = "<?php\nrequire $autoload;\n\\Bfocus\\Monitor\\Monitor::init(['key' => 'bf_mon_proc', 'release' => '9.9.9', 'base_url' => $url]);\n" . $body;
        $file = tempnam(sys_get_temp_dir(), 'bfmon') . '.php';
        file_put_contents($file, $code);
        $cmd = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0'];
        foreach ($ini as $k => $v) {
            array_push($cmd, '-d', "$k=$v");
        }
        $cmd[] = $file;
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        @unlink($file);

        return [$code, $out, $err];
    }

    public function testExcecaoNaoTratadaFatalEOPhpQuebraComoSempre(): void
    {
        [$code, , $err] = $this->runScript("function pedido(): void { throw new RuntimeException('boom'); }\npedido();\n");
        $this->assertSame(255, $code);
        $this->assertStringContainsString('Fatal error: Uncaught RuntimeException: boom', $err);
        $events = Cases::events();
        $this->assertCount(1, $events, 'uma vez só (o shutdown não repete o Uncaught)');
        $this->assertSame('fatal', $events[0]['level']);
        $this->assertSame('RuntimeException', $events[0]['exception']['type']);
        $frames = $events[0]['exception']['frames'];
        $this->assertSame('pedido', $frames[count($frames) - 1]['function']);
    }

    public function testHandlerDoAppAntesDoInitEChamadoDepoisDoNosso(): void
    {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        $url = var_export(MockServer::url(), true);
        $file = tempnam(sys_get_temp_dir(), 'bfmon') . '.php';
        file_put_contents($file, "<?php\nrequire $autoload;\nset_exception_handler(function (Throwable \$e) { echo 'anterior: ', \$e->getMessage(); });\n"
            . "\\Bfocus\\Monitor\\Monitor::init(['key' => 'bf_mon_proc', 'base_url' => $url]);\nthrow new LogicException('encadeado');\n");
        $proc = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($proc);
        @unlink($file);
        $this->assertSame('anterior: encadeado', $out);
        [$event] = Cases::events();
        $this->assertSame('encadeado', $event['exception']['message']);
        $this->assertSame('fatal', $event['level']);
    }

    public function testErroFatalNoEncerramento(): void
    {
        [$code, , $err] = $this->runScript("\$a = str_repeat('x', 64 * 1024 * 1024);\n", ['memory_limit' => '16M']);
        $this->assertSame(255, $code);
        $this->assertStringContainsString('Allowed memory size', $err);
        [$event] = Cases::events();
        $this->assertSame('FatalError', $event['exception']['type']);
        $this->assertSame('fatal', $event['level']);
        $this->assertStringContainsString('Allowed memory size', $event['exception']['message']);
    }

    public function testFilaSaiNoEncerramento(): void
    {
        [$code, $out, $err] = $this->runScript("\\Bfocus\\Monitor\\Monitor::captureMessage('fim do job', 'warning');\n");
        $this->assertSame(0, $code);
        $this->assertSame('', $out . $err);
        [$event] = Cases::events();
        $this->assertSame('fim do job', $event['exception']['message']);
        $this->assertCount(1, Cases::heartbeats(), 'sinal de vida no encerramento');
        $this->assertSame('9.9.9', json_decode(Cases::heartbeats()[0]['body'], true)['release']);
    }
}
