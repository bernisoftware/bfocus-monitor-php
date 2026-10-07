# bfocus/monitor

Monitoramento de erros do [bFocus](https://bfocus.com.br) para **PHP**: os erros não tratados do
seu sistema chegam ao bFocus, são agrupados entre todos os clientes e viram demanda para a equipe.

Zero dependências (só `ext-curl` e `ext-json`) · PHP 8.1+ · nunca derruba o app · Laravel e PSR-15.

## Instalar

```bash
composer require bfocus/monitor
```

## Ligar (uma linha)

```php
\Bfocus\Monitor\Monitor::init([
    'key' => 'bf_mon_…',
    'release' => '1.4.2',
    'environment' => 'production',
    'signing_secret' => getenv('BFOCUS_SIGNING_SECRET'),
]);
```

A chave do agente está no bFocus em **Monitoramento → Agentes**. O `init` não faz chamada de rede e
liga a captura de:

- exceção não tratada (`set_exception_handler`, nível `fatal`) — o handler que já existia continua
  rodando depois do nosso; sem handler, o PHP quebra como sempre (mensagem, HTTP 500, saída 255);
- erro fatal (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`) visto no encerramento.

O PHP não tem thread: os eventos ficam numa fila (até 100) e saem no encerramento do script (ou num
`Monitor::flush()`). No PHP-FPM o envio acontece **depois** de `fastcgi_finish_request()`: a resposta
já foi entregue e o usuário não espera o monitor.

**Sinal de vida**: o painel mostra o agente vivo mesmo sem erro. O pacote manda no máximo 1 a cada
5 min por processo, junto do envio no encerramento (no FPM, depois da resposta). Com APCu o intervalo
vale entre as requisições do mesmo worker; sem APCu, numa requisição web ele só vai junto de um envio
de erros. Worker/daemon de longa duração: chame `Monitor::heartbeat()` no laço (respeita os 5 min).

Opções: `base_url`, `sample_rate` (0..1), `ignore` (textos contidos; `"/regex/"` também vale),
`before_send` (recebe o evento e devolve o evento alterado ou `null` para descartar),
`auto_capture => false` (sem ganchos globais), `heartbeat => false` (sem sinal de vida), `in_app_prefixes` (namespaces/caminhos seus mesmo em
`vendor/`) e `project_root`.

## Laravel

Laravel 11+ (`bootstrap/app.php`):

```php
->withExceptions(function (Exceptions $exceptions) {
    \Bfocus\Monitor\Laravel::register($exceptions);
})
```

Estrutura antiga com `app/Exceptions/Handler.php` (também vale no Laravel 11 atualizado):

```php
public function register(): void
{
    \Bfocus\Monitor\Laravel::register($this);
}
```

Configuração no `.env` (ou em `config/services.php` → `'bfocus_monitor' => [...]`, com as mesmas
chaves do `init`):

```dotenv
BFOCUS_MONITOR_KEY=bf_mon_…
BFOCUS_MONITOR_RELEASE=1.4.2   # sem ela, config('app.version')
BFOCUS_SIGNING_SECRET=…
```

O ambiente vem do `APP_ENV`. Só chega ao bFocus o que o Laravel **reportaria** (404, validação e o
`$dontReport` ficam de fora), e o log continua como sempre.

## Laminas / Mezzio / Slim (PSR-15)

Precisa de `psr/http-server-middleware` (todo framework PSR-15 já traz):

```php
// No Mezzio: DEPOIS do ErrorHandler (dentro dele) e antes das rotas.
$app->pipe(new \Bfocus\Monitor\Psr15Middleware());
```

Sem `Monitor::init` antes, o middleware liga pelo ambiente (`BFOCUS_MONITOR_KEY`,
`BFOCUS_MONITOR_RELEASE`, `BFOCUS_MONITOR_ENVIRONMENT`/`APP_ENV`, `BFOCUS_SIGNING_SECRET`). Ele abre
um escopo por requisição (rota e URL **sem** query string), captura a exceção e a relança.

## Quem foi afetado (identidade)

```php
\Bfocus\Monitor\Monitor::setUser($user->id, $user->company_id);
```

Com `signing_secret` (o segredo da chave de assinatura do sistema, o mesmo do `userHash` do widget)
o pacote assina a identidade sozinho. Sem ele, passe o hash que o seu servidor já gera:
`setUser($id, $empresa, $userHash)`. Sem assinatura válida o erro conta como "não identificado".
`Monitor::signUser($secret, $id, $empresa)` gera o mesmo hash para entregar ao front.

## Manual

```php
use Bfocus\Monitor\Monitor;

try {
    emitirNota();
} catch (\Throwable $e) {
    Monitor::captureException($e, ['tags' => ['modulo' => 'fiscal']]);
}

Monitor::captureMessage('estoque negativo', 'warning');
Monitor::setTag('filial', 'POA');
Monitor::addBreadcrumb('fiscal', 'XML assinado');
Monitor::flush(2.0); // worker, fila, job longo
```

Em processo longo (Octane, RoadRunner, Swoole, workers de fila) chame `Monitor::flush()` ao fim de
cada requisição/job — o encerramento do script demora a chegar.

## O que é enviado

Tipo e mensagem da exceção (encadeadas com `getPrevious()`: vai a causa raiz, com
`(dentro de: …)`), os frames (de fora para dentro, o do `throw` por último, caminho relativo à raiz
do projeto, `inApp` falso para `vendor/`), release, ambiente, rota, URL sem query, identidade, tags,
passos e versão do PHP/SO. Nunca: corpo de requisição, cookies, headers. O mesmo erro sai no máximo 1
vez a cada 30 s, e no máximo 100 eventos por minuto.

## Testes

```bash
composer install && vendor/bin/phpunit
```

Licença MIT — Berni Software.
