<?php

declare(strict_types=1);

namespace Bfocus\Monitor;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/*
 * Middleware PSR-15 (Laminas/Mezzio, Slim, qualquer pipeline PSR-15). Só existe quando
 * `psr/http-server-middleware` está instalado (é um "suggest" do pacote, não dependência).
 *
 *     $app->pipe(new \Bfocus\Monitor\Psr15Middleware());
 *
 * No Mezzio, coloque-o DEPOIS do `ErrorHandler` (dentro dele): o ErrorHandler transforma a
 * exceção em resposta 500, e acima dele ela não existe mais.
 */
if (interface_exists(MiddlewareInterface::class)) {
    final class Psr15Middleware implements MiddlewareInterface
    {
        /**
         * @param array<string, mixed>|null $options se dado e o monitor ainda não estiver ligado,
         *                                           liga com ele (o que faltar vem do ambiente)
         */
        public function __construct(private ?array $options = null)
        {
            if (Monitor::getClient() === null) {
                Monitor::initFromEnvironment($options ?? []);
            }
        }

        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $scope = null;
            try {
                $uri = $request->getUri();
                $path = $uri->getPath() !== '' ? $uri->getPath() : '/';
                $url = $uri->getHost() !== ''
                    ? ($uri->getScheme() !== '' ? $uri->getScheme() : 'http') . '://' . $uri->getHost()
                        . ($uri->getPort() !== null ? ':' . $uri->getPort() : '') . $path
                    : $path;
                $scope = Monitor::pushScope($request->getMethod() . ' ' . $path, $url);
            } catch (\Throwable) {
            }
            try {
                return $handler->handle($request);
            } catch (\Throwable $e) {
                Monitor::captureException($e);
                throw $e;
            } finally {
                if ($scope !== null) {
                    Monitor::popScope($scope);
                }
            }
        }
    }
}
