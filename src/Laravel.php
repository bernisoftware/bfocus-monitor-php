<?php

declare(strict_types=1);

namespace Bfocus\Monitor;

/**
 * Laravel (10, 11, 12) — sem depender de pacote nenhum do Laravel no composer.json.
 *
 * Laravel 11+ (`bootstrap/app.php`):
 *
 *     ->withExceptions(function (Exceptions $exceptions) {
 *         \Bfocus\Monitor\Laravel::register($exceptions);
 *     })
 *
 * Estrutura antiga (`app/Exceptions/Handler.php`, também no Laravel 11 atualizado de versão anterior):
 *
 *     public function register(): void
 *     {
 *         \Bfocus\Monitor\Laravel::register($this);
 *     }
 *
 * Usa o `reportable()` do Laravel: só chega aqui o que o Laravel REPORTARIA (404, validação e o
 * `$dontReport` ficam de fora), e o log continua como sempre. O monitor liga na primeira exceção
 * lendo a configuração (`config/services.php` → 'bfocus_monitor' => [...]) ou o ambiente:
 * BFOCUS_MONITOR_KEY, BFOCUS_MONITOR_RELEASE (senão `config('app.version')`), APP_ENV e
 * BFOCUS_SIGNING_SECRET. Sem chave, não faz nada.
 */
final class Laravel
{
    /**
     * @param object $exceptions `Illuminate\Foundation\Configuration\Exceptions` ou o `Handler`
     * @param array<string, mixed> $options completa/substitui o que vem da configuração
     */
    public static function register(object $exceptions, array $options = []): void
    {
        $report = static function (\Throwable $e) use ($options): void {
            try {
                if (self::boot($options)) {
                    Monitor::captureException($e);
                }
            } catch (\Throwable) {
                // nunca atrapalha o report do Laravel
            }
        };
        if (method_exists($exceptions, 'reportable')) {
            $exceptions->reportable($report);
        } elseif (method_exists($exceptions, 'report')) {
            $exceptions->report($report);
        }
    }

    /** @param array<string, mixed> $options */
    private static function boot(array $options): bool
    {
        if (Monitor::getClient() !== null) {
            return true;
        }
        $fromConfig = [];
        $cfg = self::config('services.bfocus_monitor');
        if (is_array($cfg)) {
            $fromConfig = $cfg;
        }
        $release = self::env('BFOCUS_MONITOR_RELEASE') ?? self::config('app.version');
        $environment = self::env('BFOCUS_MONITOR_ENVIRONMENT') ?? self::env('APP_ENV') ?? self::config('app.env');

        return Monitor::initFromEnvironment(array_merge(
            array_filter([
                'release' => is_scalar($release) ? (string) $release : null,
                'environment' => is_scalar($environment) ? (string) $environment : null,
            ]),
            $fromConfig,
            // O Laravel já trata o não tratado e os fatais (HandleExceptions) e manda tudo pelo
            // report: os ganchos globais do PHP aqui só duplicariam.
            ['auto_capture' => false],
            $options,
        ));
    }

    private static function config(string $key): mixed
    {
        try {
            if (function_exists('config') && function_exists('app') && app()->bound('config')) {
                return config($key);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private static function env(string $name): ?string
    {
        $v = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($v) && $v !== '' ? $v : null;
    }
}
