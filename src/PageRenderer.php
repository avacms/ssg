<?php

declare(strict_types=1);

namespace StaticGen;

use Ava\Application;
use Ava\Content\Query;
use Ava\Http\Request;
use Ava\Http\Response;
use Ava\Routing\RouteMatch;

/**
 * Page Renderer
 *
 * Renders individual pages by simulating HTTP requests through the Ava
 * application stack. This ensures plugins, hooks, templates, and all
 * normal rendering logic is applied identically to a live request.
 *
 * For redirect routes, generates HTML meta-refresh pages instead.
 */
final class PageRenderer
{
    private Application $app;
    private array $config;

    public function __construct(Application $app, array $config)
    {
        $this->app = $app;
        $this->config = $config;
    }

    /**
     * Render a single route and return the HTML/XML content.
     *
     * @param array $route Route definition from RouteCollector
     * @return string|null Rendered content, or null to skip
     */
    public function render(array $route): ?string
    {
        $path = $route['path'];
        $type = $route['type'];

        // Handle redirect routes specially
        if ($type === 'redirect') {
            return $this->renderRedirect($route);
        }

        // Handle 404 page
        if ($type === 'error') {
            return $this->renderErrorPage($path);
        }

        // Simulate an HTTP request to this path
        return $this->renderViaApp($path);
    }

    /**
     * Render a page by simulating an HTTP request through the app.
     */
    private function renderViaApp(string $urlPath): ?string
    {
        // Split path and query string
        $parts = explode('?', $urlPath, 2);
        $path = $parts[0];
        $queryString = $parts[1] ?? '';

        // Parse query parameters
        $queryParams = [];
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
        }

        // Build the full URI with query string
        $uri = $path;
        if ($queryString !== '') {
            $uri .= '?' . $queryString;
        }

        // Create a simulated request
        $request = new Request(
            method: 'GET',
            uri: $uri,
            query: $queryParams,
            headers: [
                'Host' => parse_url($this->app->config('site.base_url', ''), PHP_URL_HOST) ?? 'localhost',
                'User-Agent' => 'Ava-StaticGen/1.0',
            ],
        );

        // Route and render through the app
        $router = $this->app->router();
        $match = $router->match($request);

        if ($match === null) {
            return null;
        }

        // Handle redirect matches — don't follow them, generate a redirect page
        if ($match->isRedirect()) {
            return $this->renderRedirect([
                'redirect_to' => $match->getRedirectUrl(),
                'redirect_code' => $match->getRedirectCode(),
            ]);
        }

        // Handle routes with embedded Response objects (plugin routes)
        if ($match->hasResponse()) {
            $response = $match->getResponse();
            return $response->content();
        }

        if (in_array($match->getType(), ['plugin', 'response'], true)) {
            $response = $match->getParam('response');
            if ($response instanceof Response) {
                return $response->content();
            }
        }

        // Render the matched route using the app's rendering pipeline
        $renderer = $this->app->renderer();
        $context = [
            'request' => $request,
            'route' => $match,
        ];

        if ($match->getContentItem() !== null) {
            $context['content'] = $match->getContentItem();
        }

        if ($match->getQuery() !== null) {
            $context['query'] = $match->getQuery();
        }

        if ($match->getTaxonomy() !== null) {
            $context['tax'] = $match->getTaxonomy();
        }

        $html = $renderer->render($match->getTemplate(), $context);

        return $html;
    }

    /**
     * Render a 404 error page.
     */
    private function renderErrorPage(string $path): ?string
    {
        $template = ltrim($path, '/');

        try {
            $request = new Request(method: 'GET', uri: $path, query: [], headers: []);
            return $this->app->renderer()->render($template, [
                'request' => $request,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Generate an HTML redirect page.
     *
     * Uses both meta-refresh and JavaScript redirect for broad compatibility.
     * Also includes a visible link as fallback.
     */
    private function renderRedirect(array $route): string
    {
        $target = htmlspecialchars($route['redirect_to'] ?? '/', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $code = $route['redirect_code'] ?? 301;
        $isPermanent = $code === 301 || $code === 308;
        $label = $isPermanent ? 'Moved Permanently' : 'Redirecting';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="0;url={$target}">
<title>{$label}</title>
<link rel="canonical" href="{$target}">
</head>
<body>
<p>{$label}. If not redirected, <a href="{$target}">click here</a>.</p>
<script>window.location.replace("{$target}");</script>
</body>
</html>
HTML;
    }
}
