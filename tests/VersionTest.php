<?php

declare(strict_types=1);

namespace Bfocus\Monitor\Tests;

use Bfocus\Monitor\Monitor;
use Bfocus\Monitor\Tests\Support\Cases;
use PHPUnit\Framework\TestCase;

/**
 * Versão: no PHP o manifesto (composer.json) NÃO leva versão — o Packagist a tira da tag git.
 * A fonte única é `Monitor::VERSION` (vai no `X-bFocus-Client` e no `sdk.version` de todo evento).
 */
final class VersionTest extends TestCase
{
    public function testVersaoSemverEIdentificacao(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Monitor::VERSION);
        $this->assertSame('bfocus-monitor-php', Monitor::SDK_NAME);
        $this->assertSame('bfocus-monitor-php/' . Monitor::VERSION, Monitor::CLIENT_ID);
    }

    public function testLinhaDaVersaoCasaComORegexDoRelease(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Monitor.php');
        $this->assertSame(1, preg_match_all("/public const VERSION = '([^']+)'/", $source, $m));
        $this->assertSame(Monitor::VERSION, $m[1][0]);
    }

    public function testManifesto(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('bfocus/monitor', $manifest['name']);
        $this->assertArrayNotHasKey('version', $manifest, 'a versão vem da tag git (vX.Y.Z == Monitor::VERSION)');
        $this->assertSame('Bfocus\\Monitor\\', array_key_first($manifest['autoload']['psr-4']));
        $this->assertSame(['php', 'ext-curl', 'ext-json'], array_keys($manifest['require']), 'zero dependência de runtime');
        $this->assertArrayHasKey('psr/http-server-middleware', $manifest['suggest']);
    }

    public function testVersaoDoMonorepo(): void
    {
        $release = dirname(__DIR__, 2) . '/release.json';
        if (!is_file($release)) {
            $this->markTestSkipped('fora do monorepo');
        }
        $this->assertSame(json_decode((string) file_get_contents($release), true)['version'], Monitor::VERSION);
    }

    public function testCopiaDosCasosIgualAFonte(): void
    {
        if (!is_file(Cases::sourcePath())) {
            $this->markTestSkipped('fora do monorepo: só existe a cópia tests/cases.json.');
        }
        $this->assertFileEquals(Cases::sourcePath(), Cases::vendoredPath(), 'rode: python monitor/conformance/generate.py');
    }
}
