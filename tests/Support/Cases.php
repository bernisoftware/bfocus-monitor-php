<?php

declare(strict_types=1);

namespace Bfocus\Monitor\Tests\Support;

/**
 * Casos de conformidade. A fonte é `monitor/conformance/cases.json` no monorepo; o espelho público
 * recebe só `monitor/php`, por isso existe a cópia `tests/cases.json` (escrita pelo
 * `monitor/conformance/generate.py` — não edite à mão).
 */
final class Cases
{
    public static function sourcePath(): string
    {
        return dirname(__DIR__, 3) . '/conformance/cases.json';
    }

    public static function vendoredPath(): string
    {
        return dirname(__DIR__) . '/cases.json';
    }

    /** @return array<string, mixed> */
    public static function load(): array
    {
        $path = is_file(self::sourcePath()) ? self::sourcePath() : self::vendoredPath();

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** "breadcrumbs.0.category" → valor; lança se não existir. */
    public static function get(array $data, string $dotted): mixed
    {
        $cur = $data;
        foreach (explode('.', $dotted) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                throw new \OutOfBoundsException("caminho ausente no evento: $dotted");
            }
            $cur = $cur[$part];
        }

        return $cur;
    }

    /** @return list<string> caminhos com valor nulo (o contrato proíbe) */
    public static function nulls(mixed $data, string $prefix = ''): array
    {
        $out = [];
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $out = $v === null ? [...$out, $prefix . $k] : [...$out, ...self::nulls($v, $prefix . $k . '.')];
            }
        }

        return $out;
    }

    /** Sinais de vida recebidos. @return list<array<string, mixed>> */
    public static function heartbeats(): array
    {
        return array_values(array_filter(MockServer::received(), static fn (array $r): bool => $r['uri'] === '/api/v1/monitor/heartbeat'));
    }

    /** Eventos de todas as requisições recebidas. @return list<array<string, mixed>> */
    public static function events(): array
    {
        $out = [];
        foreach (MockServer::received() as $r) {
            if ($r['uri'] !== '/api/v1/monitor/events') {
                continue;
            }
            $body = json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR);
            array_push($out, ...$body['events']);
        }

        return $out;
    }
}
