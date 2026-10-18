<?php

declare(strict_types=1);

namespace Emc\Core;

final class App
{
    private Container $container;
    private Router $router;
    private View $view;

    /** @param array<string,mixed> $config */
    private function __construct(private array $config)
    {
        $GLOBALS['emc_basis_url'] = (string) ($config['basis_url'] ?? '');
        $this->container = new Container();
        $this->router = new Router();
        $this->view = new View(dirname(__DIR__, 2) . '/templates');
        $this->container->set('config', fn() => $this->config);
        $this->container->set('router', fn() => $this->router);
        $this->container->set('view', fn() => $this->view);

        $routen = dirname(__DIR__) . '/routes.php';
        if (is_file($routen)) {
            (require $routen)($this->router, $this);
        }
    }

    /** @param array<string,mixed> $config */
    public static function erzeuge(array $config): self
    {
        return new self($config);
    }

    public function handle(Request $request): Response
    {
        try {
            $antwort = $this->router->dispatch($request);
            if ($antwort->status === 404 && $antwort->body === 'Seite nicht gefunden.') {
                return $this->fehlerSeite(404);
            }
            return $antwort;
        } catch (\Throwable $t) {
            error_log(sprintf('%s: %s in %s:%d', $t::class, $t->getMessage(), $t->getFile(), $t->getLine()));
            return $this->fehlerSeite(500, !empty($this->config['debug']) ? $t->getMessage() : null);
        }
    }

    public function container(): Container
    {
        return $this->container;
    }

    /** @return array<string,mixed> */
    public function config(): array
    {
        return $this->config;
    }

    public function view(): View
    {
        return $this->view;
    }

    private function fehlerSeite(int $status, ?string $detail = null): Response
    {
        try {
            $html = $this->view->render('fehler/' . $status, [
                'titel' => 'Fehler',
                'app_name' => $this->config['app_name'] ?? '',
                'detail' => $detail,
            ]);
        } catch (\Throwable $t) {
            $html = 'Es ist ein technischer Fehler aufgetreten.';
        }
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
