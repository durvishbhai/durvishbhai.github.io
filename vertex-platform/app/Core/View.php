<?php

declare(strict_types=1);

namespace Vertex\Core;

use RuntimeException;

/**
 * Plain PHP templates. Everything echoed must go through e(); templates never contain business logic.
 *   View::render('pages/home', ['x' => 1], 'layouts/public');
 */
final class View
{
    /** @param array<string,mixed> $data */
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/public'): string
    {
        $content = self::capture($view, $data);
        if ($layout === null) {
            return $content;
        }

        return self::capture($layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    public static function partial(string $view, array $data = []): string
    {
        return self::capture('partials/' . $view, $data);
    }

    /** @param array<string,mixed> $data */
    private static function capture(string $view, array $data): string
    {
        if (!preg_match('#^[a-z0-9_/\-]+$#i', $view) || str_contains($view, '..')) {
            throw new RuntimeException('Invalid view name.');
        }
        $file = VERTEX_ROOT . '/app/Views/' . $view . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$view}");
        }

        return (static function () use ($file, $data): string {
            extract($data, EXTR_SKIP);
            ob_start();
            try {
                require $file;

                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        })();
    }
}
