<?php

declare(strict_types=1);

namespace App\Web;

/**
 * Schlanke Template-Engine auf reinem PHP. Templates enthalten keine Logik zur Datenbeschaffung und kein SQL;
 * jede Ausgabe läuft durch e() (kontextgerechtes HTML-Escaping).
 */
final class View
{
    /** Seitentitel; wird vom Inhaltstemplate gesetzt und vom Layout gelesen. */
    public ?string $title = null;

    /** @var array<string,mixed> */
    private array $shared = [];

    public function __construct(private readonly string $dir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = [], string $layout = 'layout/app'): string
    {
        $content = $this->partial($template, $data);
        if ($layout === '') {
            return $content;
        }
        return $this->partial($layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        if (preg_match('#^[a-z0-9_/-]+$#', $template) !== 1) {
            throw new \InvalidArgumentException('Ungültiger Template-Name.');
        }
        $file = $this->dir . '/' . $template . '.php';
        $vars = $data + $this->shared;
        $v = $this;
        return (static function () use ($file, $vars, $v): string {
            extract($vars, EXTR_SKIP);
            ob_start();
            try {
                require $file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        })();
    }

    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
