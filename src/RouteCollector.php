<?php

declare(strict_types=1);

namespace StaticGen;

use Ava\Application;

/**
 * Route Collector
 *
 * Discovers all routes that need to be rendered as static pages.
 * Collects from:
 * - Content routes (single pages, archives)
 * - Taxonomy routes (index and term pages)
 * - Plugin routes (sitemap.xml, feed.xml)
 * - Redirect routes (generates HTML meta-refresh pages)
 * - Pagination for archives and taxonomy terms
 * - Extra user-specified paths
 */
final class RouteCollector
{
    private Application $app;
    private array $config;

    public function __construct(Application $app, array $config)
    {
        $this->app = $app;
        $this->config = $config;
    }

    /**
     * Collect all routes that need to be generated.
     *
     * @return array<array{path: string, type: string, extension?: string}>
     */
    public function collect(): array
    {
        $routes = [];
        $excludes = $this->config['exclude_paths'] ?? [];

        // 1. Content routes from cache
        $routes = array_merge($routes, $this->collectContentRoutes());

        // 2. Taxonomy routes
        $routes = array_merge($routes, $this->collectTaxonomyRoutes());

        // 3. Known plugin routes
        $routes = array_merge($routes, $this->collectPluginRoutes());

        // 4. Redirect routes
        if (($this->config['redirects'] ?? 'html') === 'html') {
            $routes = array_merge($routes, $this->collectRedirectRoutes());
        }

        // 5. Extra user-specified paths
        foreach ($this->config['extra_paths'] ?? [] as $path) {
            $routes[] = ['path' => $path, 'type' => 'extra'];
        }

        // 6. 404 page
        $routes[] = ['path' => '/404', 'type' => 'error'];

        // Filter excludes
        if (!empty($excludes)) {
            $routes = array_filter($routes, function (array $route) use ($excludes) {
                foreach ($excludes as $pattern) {
                    if ($this->pathMatches($route['path'], $pattern)) {
                        return false;
                    }
                }
                return true;
            });
            $routes = array_values($routes);
        }

        // Deduplicate by path
        $seen = [];
        $unique = [];
        foreach ($routes as $route) {
            $key = $route['path'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $route;
            }
        }

        return $unique;
    }

    /**
     * Collect routes from the content cache.
     * Includes single pages, archive pages, and their pagination.
     */
    private function collectContentRoutes(): array
    {
        $routes = [];
        $repository = $this->app->repository();
        $cachedRoutes = $repository->routes();

        // Exact routes (single pages and archives)
        foreach ($cachedRoutes['exact'] ?? [] as $path => $routeData) {
            $type = $routeData['type'] ?? 'single';

            if ($type === 'single') {
                $routes[] = ['path' => $path, 'type' => 'single'];
            } elseif ($type === 'archive') {
                // Archive page 1
                $routes[] = ['path' => $path, 'type' => 'archive'];

                // Discover pagination for this archive
                $contentType = $routeData['content_type'] ?? '';
                $paginatedRoutes = $this->discoverPagination($path, $contentType);
                $routes = array_merge($routes, $paginatedRoutes);
            }
        }

        return $routes;
    }

    /**
     * Collect taxonomy routes (index and term pages).
     */
    private function collectTaxonomyRoutes(): array
    {
        $routes = [];
        $repository = $this->app->repository();
        $cachedRoutes = $repository->routes();

        foreach ($cachedRoutes['taxonomy'] ?? [] as $taxName => $taxRoute) {
            $base = rtrim($taxRoute['base'], '/');

            // Taxonomy index page
            $routes[] = ['path' => $base, 'type' => 'taxonomy_index'];

            // Individual term pages
            $terms = $repository->terms($taxName);
            foreach ($terms as $term) {
                $termSlug = $term['slug'] ?? '';
                if ($termSlug === '') {
                    continue;
                }

                $termPath = $base . '/' . $termSlug;
                $routes[] = ['path' => $termPath, 'type' => 'taxonomy_term'];

                // Pagination for term pages
                $paginatedRoutes = $this->discoverTermPagination($termPath, $taxName, $termSlug);
                $routes = array_merge($routes, $paginatedRoutes);
            }
        }

        return $routes;
    }

    /**
     * Collect known plugin routes (sitemap, feed, etc.).
     */
    private function collectPluginRoutes(): array
    {
        $routes = [];
        $enabledPlugins = $this->app->config('plugins', []);

        // Content types for per-type routes
        $contentTypesFile = $this->app->path('app/config/content_types.php');
        $contentTypes = file_exists($contentTypesFile) ? require $contentTypesFile : [];

        // Sitemap plugin
        if (in_array('sitemap', $enabledPlugins, true)) {
            $sitemapConfig = $this->app->config('sitemap', []);
            if ($sitemapConfig['enabled'] ?? true) {
                $routes[] = ['path' => '/sitemap.xml', 'type' => 'plugin', 'extension' => 'xml'];
                foreach (array_keys($contentTypes) as $type) {
                    $routes[] = ['path' => "/sitemap-{$type}.xml", 'type' => 'plugin', 'extension' => 'xml'];
                }
            }
        }

        // Feed plugin
        if (in_array('feed', $enabledPlugins, true)) {
            $feedConfig = $this->app->config('feed', []);
            if ($feedConfig['enabled'] ?? true) {
                $routes[] = ['path' => '/feed.xml', 'type' => 'plugin', 'extension' => 'xml'];
                foreach (array_keys($contentTypes) as $type) {
                    $routes[] = ['path' => "/feed/{$type}.xml", 'type' => 'plugin', 'extension' => 'xml'];
                }
            }
        }

        return $routes;
    }

    /**
     * Collect redirect routes to generate HTML redirect pages.
     */
    private function collectRedirectRoutes(): array
    {
        $routes = [];
        $repository = $this->app->repository();
        $cachedRoutes = $repository->routes();

        // Redirects from content frontmatter (redirect_from)
        foreach ($cachedRoutes['redirects'] ?? [] as $fromPath => $redirectData) {
            $routes[] = [
                'path' => $fromPath,
                'type' => 'redirect',
                'redirect_to' => $redirectData['to'] ?? '/',
                'redirect_code' => $redirectData['code'] ?? 301,
            ];
        }

        // Redirects from the redirects plugin (storage/redirects.json)
        $redirectsFile = $this->app->configPath('storage') . '/redirects.json';
        if (file_exists($redirectsFile)) {
            $contents = file_get_contents($redirectsFile);
            $redirects = json_decode($contents, true);
            if (is_array($redirects)) {
                foreach ($redirects as $redirect) {
                    $code = (int) ($redirect['code'] ?? 301);
                    // Only generate pages for actual redirects, not status-only responses
                    if (in_array($code, [301, 302, 307, 308], true)) {
                        $routes[] = [
                            'path' => $redirect['from'] ?? '',
                            'type' => 'redirect',
                            'redirect_to' => $redirect['to'] ?? '/',
                            'redirect_code' => $code,
                        ];
                    }
                }
            }
        }

        return $routes;
    }

    /**
     * Discover pagination for an archive path.
     */
    private function discoverPagination(string $archivePath, string $contentType): array
    {
        $routes = [];
        $repository = $this->app->repository();
        $count = $repository->count($contentType, 'published');

        // Default per-page from config or 10
        $perPage = $this->app->config('pagination.per_page', 10);

        if ($count <= $perPage) {
            return $routes;
        }

        $totalPages = (int) ceil($count / $perPage);

        for ($page = 2; $page <= $totalPages; $page++) {
            $routes[] = [
                'path' => $archivePath . '?page=' . $page,
                'type' => 'archive_page',
            ];
        }

        return $routes;
    }

    /**
     * Discover pagination for a taxonomy term page.
     */
    private function discoverTermPagination(string $termPath, string $taxonomy, string $termSlug): array
    {
        $routes = [];
        $repository = $this->app->repository();

        $term = $repository->term($taxonomy, $termSlug);
        if ($term === null) {
            return $routes;
        }

        $itemCount = count($term['items'] ?? []);
        $perPage = $this->app->config('pagination.per_page', 10);

        if ($itemCount <= $perPage) {
            return $routes;
        }

        $totalPages = (int) ceil($itemCount / $perPage);

        for ($page = 2; $page <= $totalPages; $page++) {
            $routes[] = [
                'path' => $termPath . '?page=' . $page,
                'type' => 'taxonomy_page',
            ];
        }

        return $routes;
    }

    /**
     * Check if a path matches a glob-like pattern.
     */
    private function pathMatches(string $path, string $pattern): bool
    {
        // Exact match
        if ($path === $pattern) {
            return true;
        }

        // Wildcard match (e.g., /api/*)
        if (str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);
            return str_starts_with($path, $prefix);
        }

        return false;
    }
}
