<?php

declare(strict_types=1);

namespace Bfocus\Monitor;

/**
 * O que vai junto de todo evento: identidade, tags, passos (breadcrumbs), transação e URL.
 *
 * Há um escopo global e, por cima dele, um por requisição (aberto pelo Psr15Middleware) — em
 * servidor de processo longo (Swoole, RoadRunner, FrankenPHP) uma requisição não herda a
 * identidade da anterior.
 *
 * @internal
 */
final class Scope
{
    public ?string $userId = null;

    public ?string $customerId = null;

    public ?string $userHash = null;

    /** @var array{0: string, 1: int, 2: string}|null segredo, ts, hash */
    public ?array $signed = null;

    /** @var array<string, string> */
    public array $tags = [];

    /** @var list<array{timestamp: string, category: string, message: string, level: string}> */
    public array $breadcrumbs = [];

    public function __construct(public ?string $transaction = null, public ?string $url = null)
    {
    }

    public function setUser(?string $userId, ?string $customerId, ?string $userHash): void
    {
        $this->userId = $userId;
        $this->customerId = $customerId;
        $this->userHash = $userHash;
        $this->signed = null;
    }

    /** O `userHash` dado ou, com segredo, a assinatura v2 (recalculada depois de 6 dias). */
    public function hashFor(?string $secret, int $now): ?string
    {
        if ($this->userHash !== null && $this->userHash !== '') {
            return $this->userHash;
        }
        if ($secret === null || $secret === '' || $this->userId === null) {
            return null;
        }
        $s = $this->signed;
        if ($s === null || $s[0] !== $secret || $now - $s[1] > Client::SIGN_MAX_AGE || $s[1] > $now + 300) {
            $s = [$secret, $now, Monitor::signUser($secret, $this->userId, $this->customerId ?? '', $now)];
            $this->signed = $s;
        }

        return $s[2];
    }

    public function addBreadcrumb(array $crumb): void
    {
        $this->breadcrumbs[] = $crumb;
        if (count($this->breadcrumbs) > Client::MAX_CRUMBS) {
            array_shift($this->breadcrumbs);
        }
    }
}
