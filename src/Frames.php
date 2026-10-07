<?php

declare(strict_types=1);

namespace Bfocus\Monitor;

/**
 * Rastro do PHP → frames do contrato, de FORA para DENTRO (o último é onde estourou).
 *
 * `getTrace()` vem de dentro para fora, e cada item traz o arquivo/linha da CHAMADA da função
 * dele. Por isso o frame do throw (arquivo/linha da exceção) roda dentro de `trace[0].function`,
 * a chamada `trace[0].file:line` roda dentro de `trace[1].function`, e assim por diante.
 *
 * @internal
 */
final class Frames
{
    private static ?string $ownDir = null;

    /**
     * @param list<string> $inAppPrefixes
     *
     * @return list<array<string, mixed>>
     */
    public static function fromThrowable(\Throwable $e, string $root, array $inAppPrefixes = []): array
    {
        return self::fromTrace($e->getFile(), $e->getLine(), $e->getTrace(), $root, $inAppPrefixes);
    }

    /**
     * @param list<array<string, mixed>> $trace no formato de `getTrace()` (de dentro para fora)
     * @param list<string> $inAppPrefixes
     *
     * @return list<array<string, mixed>>
     */
    public static function fromTrace(?string $file, ?int $line, array $trace, string $root, array $inAppPrefixes = []): array
    {
        $trace = array_values($trace);
        $inner = [];
        // Função nativa que lançou (intdiv, json_decode…): trace[0] aponta a mesma linha do throw e
        // o frame dele já diz quem chamou — o frame sintético do throw seria repetido.
        $first = $trace[0] ?? null;
        if (!($first !== null && isset($first['file'], $first['line']) && $first['file'] === $file && (int) $first['line'] === $line)) {
            $inner[] = ['file' => $file, 'line' => $line, 'function' => self::fn($first)];
        }
        foreach ($trace as $i => $item) {
            $inner[] = [
                'file' => isset($item['file']) ? (string) $item['file'] : null,
                'line' => isset($item['line']) ? (int) $item['line'] : null,
                'function' => self::fn($trace[$i + 1] ?? null),
            ];
        }
        $inner = array_values(array_filter($inner, static fn (array $f): bool => $f['file'] !== null || $f['function'] !== null));
        $inner = array_slice($inner, 0, Client::MAX_FRAMES); // os mais internos

        return array_map(
            static fn (array $f): array => self::frame($f['file'], $f['function'], $f['line'], $root, $inAppPrefixes),
            array_reverse($inner),
        );
    }

    /**
     * Um frame do contrato (sem campos nulos).
     *
     * @param list<string> $inAppPrefixes
     *
     * @return array<string, mixed>
     */
    public static function frame(?string $file, ?string $function, ?int $line, string $root = '', array $inAppPrefixes = []): array
    {
        $rel = $file !== null && $file !== '' ? self::relative($file, $root) : null;
        $inApp = self::isApp($file);
        if ($inApp === false && $file !== null && !self::isOwn($file) && $inAppPrefixes !== []) {
            foreach ($inAppPrefixes as $p) {
                if ($p !== '' && (($rel !== null && str_starts_with($rel, $p)) || ($function !== null && str_starts_with(ltrim($function, '\\'), ltrim($p, '\\'))))) {
                    $inApp = true;
                    break;
                }
            }
        }
        $out = ['file' => $rel, 'function' => $function, 'line' => $line, 'inApp' => $inApp];

        return array_filter($out, static fn ($v): bool => $v !== null && $v !== '');
    }

    /** Biblioteca (vendor) e o próprio monitor não são código do sistema. */
    public static function isApp(?string $file): bool
    {
        if ($file === null || $file === '' || $file === '[internal function]' || str_starts_with($file, 'phar://')) {
            return false;
        }
        $norm = str_replace('\\', '/', $file);
        if (str_contains($norm, '/vendor/')) {
            return false;
        }

        return !self::isOwn($file);
    }

    public static function isOwn(string $file): bool
    {
        if (self::$ownDir === null) {
            self::$ownDir = str_replace('\\', '/', (string) (realpath(__DIR__) ?: __DIR__)) . '/';
        }
        $norm = str_replace('\\', '/', (string) (@realpath($file) ?: $file));

        return str_starts_with($norm, self::$ownDir) || str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', __DIR__) . '/');
    }

    public static function relative(string $file, string $root): string
    {
        if ($root === '') {
            return $file;
        }
        $base = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        if (str_starts_with($file, $base)) {
            return str_replace('\\', '/', substr($file, strlen($base)));
        }

        return $file;
    }

    /** @param array<string, mixed>|null $item */
    private static function fn(?array $item): ?string
    {
        if ($item === null || !isset($item['function'])) {
            return null;
        }
        $fn = (string) $item['function'];
        if (isset($item['class'])) {
            return $item['class'] . ($item['type'] ?? '::') . $fn;
        }

        return $fn;
    }

    /**
     * Raiz do projeto: onde está o `vendor/` que contém este pacote; fora dele, o diretório atual.
     * No PHP-FPM o diretório atual costuma ser o `public/`, que deixaria `src/` absoluto.
     */
    public static function projectRoot(): string
    {
        $dir = str_replace('\\', '/', __DIR__);
        $pos = strrpos($dir, '/vendor/');
        if ($pos !== false) {
            return substr(__DIR__, 0, $pos);
        }
        $cwd = getcwd();

        return $cwd === false ? '' : $cwd;
    }
}
