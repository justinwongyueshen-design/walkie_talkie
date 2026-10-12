<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Renders PHP templates from views/ inside an optional layout.
 */
final class View
{
    /** @param array<string,mixed> $data */
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/main'): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require WT_ROOT . '/views/' . $view . '.php';
        $content = (string) ob_get_clean();

        if ($layout === null) {
            return $content;
        }

        ob_start();
        require WT_ROOT . '/views/' . $layout . '.php';
        return (string) ob_get_clean();
    }
}
