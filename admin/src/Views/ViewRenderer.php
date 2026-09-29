<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Views;

use RuntimeException;

/**
 * Server-side HTML template renderer for the pure PHP admin views.
 */
final class ViewRenderer
{
    public function __construct(
        private readonly string $viewsDirectory = __DIR__
    ) {
    }

    /**
     * Renders a view template, optionally wrapped in a master layout.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], ?string $layout = 'layout.php'): string
    {
        $content = $this->renderPartial($template, $data);

        if ($layout === null) {
            return $content;
        }

        $layoutData = array_merge($data, ['content' => $content]);
        return $this->renderPartial($layout, $layoutData);
    }

    /**
     * Renders an isolated template file with extracted parameters.
     *
     * @param array<string, mixed> $data
     */
    public function renderPartial(string $template, array $data = []): string
    {
        $file = $this->viewsDirectory . '/' . ltrim($template, '/');
        if (!file_exists($file)) {
            throw new RuntimeException("View template not found: {$template} at {$file}");
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
}
