<?php

declare(strict_types=1);

/**
 * Ava Static Site Generator Plugin
 *
 * Generates a complete static HTML site from Ava CMS content.
 * Deploy to GitHub Pages, Netlify, S3, or any static host.
 *
 * Features:
 * - Generates all content pages, archives, taxonomy pages
 * - Handles pagination automatically
 * - Copies static assets (media, theme assets)
 * - Generates plugin routes (sitemap.xml, feed.xml)
 * - Generates a JSON search index for client-side search
 * - Supports base URL override for subdirectory deployments
 * - Generates HTML redirects for redirect_from frontmatter
 *
 * @package Ava\Plugins\SSG
 */

use Ava\Application;
use Ava\Http\Request;
use Ava\Http\Response;
use Ava\Plugins\Hooks;

/**
 * Register autoloader for the StaticGen classes.
 * Since Ava plugins don't use Composer autoloading, we register a simple
 * class loader for the StaticGen namespace.
 */
(static function (): void {
    $baseDir = __DIR__ . '/src/';
    spl_autoload_register(static function (string $class) use ($baseDir): void {
        $prefix = 'StaticGen\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
})();

return [
    'name' => 'Static Site Generator',
    'version' => '1.0.0',
    'description' => 'Generate a complete static HTML site from your Ava CMS content',
    'author' => 'Ava CMS',

    'boot' => function (Application $app) {
        // No routes or hooks needed at runtime — this plugin is CLI-only.
    },

    'commands' => [
        [
            'name' => 'static:build',
            'description' => 'Generate a static HTML site',
            'handler' => function (array $args, $output, Application $app) {
                $builder = new StaticGen\Builder($app, $output);
                return $builder->build($args);
            },
        ],
        [
            'name' => 'static:clean',
            'description' => 'Remove the static output directory',
            'handler' => function (array $args, $output, Application $app) {
                $builder = new StaticGen\Builder($app, $output);
                return $builder->clean($args);
            },
        ],
        [
            'name' => 'static:serve',
            'description' => 'Preview the static site locally',
            'handler' => function (array $args, $output, Application $app) {
                $builder = new StaticGen\Builder($app, $output);
                return $builder->serve($args);
            },
        ],
    ],
];
