<?php

declare(strict_types=1);

namespace StaticGen;

use Ava\Application;
use Ava\Cli\Output;
use Ava\Http\Request;
use Ava\Http\Response;
use Ava\Plugins\Hooks;

/**
 * Static Site Builder
 *
 * Orchestrates the full static site generation process:
 * 1. Discover all routes (content, archives, taxonomies, plugins)
 * 2. Render each route by simulating HTTP requests through the app
 * 3. Copy static assets
 * 4. Optionally generate a JSON search index
 */
final class Builder
{
    private Application $app;
    private Output $output;

    public function __construct(Application $app, Output $output)
    {
        $this->app = $app;
        $this->output = $output;
    }

    /**
     * Build the static site.
     *
     * Options:
     *   --output=DIR     Output directory (default: from config or 'dist')
     *   --base-url=URL   Override base URL for the build
     *   --clean          Remove output directory before building
     *   --no-assets      Skip copying static assets
     *   --no-search      Skip generating search index
     */
    public function build(array $args): int
    {
        $config = $this->resolveConfig($args);
        $outputDir = $config['output_dir'];
        $startTime = microtime(true);

        $this->output->header('Static Site Generator');
        $this->output->writeln('');

        // Boot the application to ensure plugins and theme are loaded.
        // In CLI mode, plugins aren't booted by default — but we need their
        // routes (sitemap, feed, etc.) to be registered for rendering.
        $this->app->boot();

        // Determine if clean build requested
        if (in_array('--clean', $args, true) && is_dir($outputDir)) {
            $this->output->withSpinner('Cleaning output directory', function () use ($outputDir) {
                $this->removeDirectory($outputDir);
            });
        }

        // Ensure output directory exists
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Collect all routes to render
        $routes = $this->output->withSpinner('Discovering routes', function () use ($config) {
            $collector = new RouteCollector($this->app, $config);
            return $collector->collect();
        });

        $totalRoutes = count($routes);
        $this->output->writeln("  Found {$totalRoutes} route(s) to generate");
        $this->output->writeln('');

        // Render all routes
        $renderer = new PageRenderer($this->app, $config);
        $generated = 0;
        $skipped = 0;
        $errors = [];

        $this->output->sectionHeader('Generating Pages');

        foreach ($routes as $route) {
            $path = $route['path'];
            $filePath = $this->pathToFile($path, $route['extension'] ?? 'html');
            $fullPath = $outputDir . '/' . $filePath;

            try {
                $content = $renderer->render($route);

                if ($content === null) {
                    $skipped++;
                    continue;
                }

                // Rewrite URLs if base URL is overridden
                if ($config['base_url'] !== null) {
                    $content = $this->rewriteBaseUrl($content, $config);
                }

                // Ensure parent directory exists
                $dir = dirname($fullPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                file_put_contents($fullPath, $content);
                $generated++;
            } catch (\Throwable $e) {
                $errors[] = ['path' => $path, 'error' => $e->getMessage()];
                $this->output->writeln('  ' . $this->output->color('✗', Output::RED) . " {$path}: {$e->getMessage()}");
            }
        }

        // Copy static assets
        $assetCount = 0;
        if (!in_array('--no-assets', $args, true)) {
            $this->output->writeln('');
            $assetCount = $this->output->withSpinner('Copying static assets', function () use ($outputDir, $config) {
                return $this->copyAssets($outputDir, $config);
            });
        }

        // Generate search index
        $searchIndexSize = 0;
        if (!in_array('--no-search', $args, true) && ($config['generate_search_index'] ?? true)) {
            $searchIndexSize = $this->output->withSpinner('Generating search index', function () use ($outputDir) {
                $indexer = new SearchIndexGenerator($this->app);
                return $indexer->generate($outputDir);
            });
        }

        // Summary
        $elapsed = round((microtime(true) - $startTime) * 1000);
        $this->output->writeln('');
        $this->output->sectionHeader('Build Complete');
        $this->output->keyValue('Pages', (string) $generated);
        if ($skipped > 0) {
            $this->output->keyValue('Skipped', (string) $skipped);
        }
        if (!empty($errors)) {
            $this->output->keyValue('Errors', $this->output->color((string) count($errors), Output::RED));
        }
        $this->output->keyValue('Assets', (string) $assetCount);
        if ($searchIndexSize > 0) {
            $this->output->keyValue('Search idx', $this->formatBytes($searchIndexSize));
        }
        $this->output->keyValue('Output', $outputDir);
        $this->output->keyValue('Time', "{$elapsed}ms");
        $this->output->writeln('');

        if (!empty($errors)) {
            $this->output->sectionHeader('Errors');
            foreach ($errors as $err) {
                $this->output->writeln('  ' . $this->output->color('•', Output::RED) . " {$err['path']}");
                $this->output->writeln('    ' . $this->output->color($err['error'], Output::DIM));
            }
            $this->output->writeln('');
        }

        $this->output->nextStep('static:serve', 'Preview locally');
        $this->output->writeln('');

        return empty($errors) ? 0 : 1;
    }

    /**
     * Remove the output directory.
     */
    public function clean(array $args): int
    {
        $config = $this->resolveConfig($args);
        $outputDir = $config['output_dir'];

        $this->output->header('Static Site Generator');
        $this->output->writeln('');

        if (!is_dir($outputDir)) {
            $this->output->info("Output directory does not exist: {$outputDir}");
            $this->output->writeln('');
            return 0;
        }

        $this->output->withSpinner('Removing output directory', function () use ($outputDir) {
            $this->removeDirectory($outputDir);
        });

        $this->output->success("Cleaned: {$outputDir}");
        $this->output->writeln('');

        return 0;
    }

    /**
     * Serve the static site locally for preview.
     */
    public function serve(array $args): int
    {
        $config = $this->resolveConfig($args);
        $outputDir = $config['output_dir'];

        if (!is_dir($outputDir)) {
            $this->output->error("Output directory does not exist: {$outputDir}");
            $this->output->writeln('  Run ' . $this->output->color('./ava static:build', Output::PRIMARY) . ' first.');
            $this->output->writeln('');
            return 1;
        }

        // Parse port from args
        $port = '8080';
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--port=')) {
                $port = substr($arg, 7);
            }
        }

        // Validate port
        $portInt = (int) $port;
        if ($portInt < 1 || $portInt > 65535) {
            $this->output->error("Invalid port: {$port}");
            return 1;
        }

        $host = '127.0.0.1';

        $this->output->header('Static Site Generator');
        $this->output->writeln('');
        $this->output->info("Serving static site from: {$outputDir}");
        $this->output->writeln('  ' . $this->output->color("http://{$host}:{$port}", Output::PRIMARY));
        $this->output->writeln('  ' . $this->output->color('Press Ctrl+C to stop', Output::DIM));
        $this->output->writeln('');

        // Use PHP built-in server with a simple router for clean URLs
        $routerScript = $this->createRouterScript($outputDir);

        // exec replaces the process so Ctrl+C works properly
        $command = sprintf(
            'php -S %s:%s -t %s %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($outputDir),
            escapeshellarg($routerScript)
        );

        passthru($command, $exitCode);

        // Clean up router script
        if (file_exists($routerScript)) {
            unlink($routerScript);
        }

        return $exitCode;
    }

    /**
     * Resolve configuration from args and plugin config.
     */
    private function resolveConfig(array $args): array
    {
        $pluginConfig = $this->app->config('ssg', []);

        $config = [
            'output_dir' => $pluginConfig['output_dir'] ?? 'dist',
            'base_url' => $pluginConfig['base_url'] ?? null,
            'copy_media' => $pluginConfig['copy_media'] ?? true,
            'generate_search_index' => $pluginConfig['generate_search_index'] ?? true,
            'extra_paths' => $pluginConfig['extra_paths'] ?? [],
            'exclude_paths' => $pluginConfig['exclude_paths'] ?? [],
            'redirects' => $pluginConfig['redirects'] ?? 'html',
        ];

        // Parse CLI overrides
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $config['output_dir'] = substr($arg, 9);
            }
            if (str_starts_with($arg, '--base-url=')) {
                $config['base_url'] = substr($arg, 11);
            }
        }

        // Make output directory absolute
        if (!str_starts_with($config['output_dir'], '/')) {
            $config['output_dir'] = $this->app->path($config['output_dir']);
        }

        // Store the original base URL for URL rewriting
        $config['original_base_url'] = rtrim($this->app->config('site.base_url', ''), '/');

        return $config;
    }

    /**
     * Convert a URL path to a filesystem path.
     *
     * /about        → about/index.html
     * /             → index.html
     * /feed.xml     → feed.xml
     * /blog         → blog/index.html
     * /blog?page=2  → blog/page/2/index.html
     */
    private function pathToFile(string $urlPath, string $extension = 'html'): string
    {
        // Remove query string for file path
        $path = explode('?', $urlPath)[0];

        // Handle paths that already have a file extension
        if (preg_match('/\.\w+$/', $path)) {
            return ltrim($path, '/');
        }

        // Root path
        if ($path === '/' || $path === '') {
            return 'index.html';
        }

        $path = trim($path, '/');
        return $path . '/index.html';
    }

    /**
     * Copy static assets to the output directory.
     */
    private function copyAssets(string $outputDir, array $config): int
    {
        $count = 0;

        // Copy public/ directory (media, robots.txt, etc.) excluding index.php
        $publicDir = $this->app->path('public');
        if (is_dir($publicDir)) {
            $count += $this->copyDirectory(
                $publicDir,
                $outputDir,
                ['index.php', '.htaccess']
            );
        }

        // Copy theme assets
        $theme = $this->app->config('theme', 'default');
        $themeAssetsDir = $this->app->configPath('themes') . '/' . $theme . '/assets';
        if (is_dir($themeAssetsDir)) {
            $themeOutputDir = $outputDir . '/theme';
            $count += $this->copyDirectory($themeAssetsDir, $themeOutputDir);
        }

        return $count;
    }

    /**
     * Recursively copy a directory, excluding specified files.
     */
    private function copyDirectory(string $src, string $dst, array $excludeFiles = []): int
    {
        $count = 0;

        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($src) + 1);

            // Skip excluded files
            if (in_array(basename($relativePath), $excludeFiles, true)) {
                continue;
            }

            // Skip hidden files/directories
            if (str_starts_with(basename($relativePath), '.')) {
                continue;
            }

            $targetPath = $dst . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                copy($item->getPathname(), $targetPath);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * Rewrite URLs from the original base URL to the new one.
     */
    private function rewriteBaseUrl(string $content, array $config): string
    {
        $original = $config['original_base_url'];
        $new = rtrim($config['base_url'], '/');

        if ($original === $new || $original === '') {
            return $content;
        }

        // Replace absolute URLs in href, src, action, and content attributes
        return str_replace($original, $new, $content);
    }

    /**
     * Create a temporary PHP router script for the preview server.
     * Handles clean URLs by looking for path/index.html.
     */
    private function createRouterScript(string $outputDir): string
    {
        $script = <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve existing files directly
if ($path !== '/' && file_exists(__DIR__ . $path) && is_file(__DIR__ . $path)) {
    return false;
}

// Try path/index.html for clean URLs
$indexPath = rtrim($path, '/') . '/index.html';
$fullPath = __DIR__ . $indexPath;
if (file_exists($fullPath)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($fullPath);
    return;
}

// Root
if ($path === '/') {
    $fullPath = __DIR__ . '/index.html';
    if (file_exists($fullPath)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($fullPath);
        return;
    }
}

// 404
http_response_code(404);
$custom404 = __DIR__ . '/404/index.html';
if (file_exists($custom404)) {
    readfile($custom404);
} else {
    echo '<h1>404 Not Found</h1>';
}
PHP;

        $tmpDir = $this->app->path('storage/tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $routerFile = $tmpDir . '/ssg-router.php';
        file_put_contents($routerFile, $script);

        return $routerFile;
    }

    /**
     * Format bytes into a human-readable string.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . 'B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . 'KB';
        }
        return round($bytes / 1048576, 1) . 'MB';
    }
}
