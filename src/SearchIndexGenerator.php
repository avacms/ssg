<?php

declare(strict_types=1);

namespace StaticGen;

use Ava\Application;

/**
 * Search Index Generator
 *
 * Generates a JSON search index for client-side search.
 * The index includes title, slug, URL, excerpt, and body text for
 * all published, indexable content items.
 *
 * The resulting JSON file can be consumed by client-side search libraries
 * such as Lunr.js, Fuse.js, Pagefind, or a custom implementation.
 */
final class SearchIndexGenerator
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Generate the search index and write it to the output directory.
     *
     * @param string $outputDir Output directory path
     * @return int Size of the generated index file in bytes
     */
    public function generate(string $outputDir): int
    {
        $repository = $this->app->repository();
        $routes = $repository->routes();
        $reverseRoutes = $routes['reverse'] ?? [];
        $contentTypes = $this->app->contentTypes();
        $types = $repository->types();

        $index = [];

        foreach ($types as $type) {
            $typeConfig = $contentTypes[$type] ?? [];
            $searchConfig = $typeConfig['search'] ?? [];

            // Skip types with search disabled
            if (isset($searchConfig['enabled']) && !$searchConfig['enabled']) {
                continue;
            }

            $items = $repository->published($type);

            foreach ($items as $item) {
                // Skip noindex items
                if ($item->noindex()) {
                    continue;
                }

                // Find URL for this item
                $key = $type . ':' . $item->slug();
                $url = $reverseRoutes[$key] ?? null;

                if ($url === null) {
                    $urlConfig = $typeConfig['url'] ?? [];
                    $pattern = $urlConfig['pattern'] ?? '/' . $type . '/{slug}';
                    $url = str_replace('{slug}', $item->slug(), $pattern);
                }

                // Build the index entry
                $entry = [
                    'title' => $item->title(),
                    'url' => $url,
                    'type' => $type,
                ];

                // Add excerpt if available
                $excerpt = $item->excerpt();
                if ($excerpt !== null && $excerpt !== '') {
                    $entry['excerpt'] = $excerpt;
                }

                // Add body text (stripped of HTML/Markdown for search)
                $rawContent = $item->rawContent();
                if ($rawContent !== '') {
                    $entry['body'] = $this->stripForSearch($rawContent);
                }

                // Add date if available
                $date = $item->date();
                if ($date !== null) {
                    $entry['date'] = $date->format('Y-m-d');
                }

                // Add taxonomy terms
                foreach ($repository->taxonomies() as $taxonomy) {
                    $terms = $item->terms($taxonomy);
                    if (!empty($terms)) {
                        $entry[$taxonomy] = $terms;
                    }
                }

                $index[] = $entry;
            }
        }

        // Write the index file
        $indexDir = $outputDir;
        if (!is_dir($indexDir)) {
            mkdir($indexDir, 0755, true);
        }

        $indexFile = $indexDir . '/search-index.json';
        $json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($indexFile, $json);

        return strlen($json);
    }

    /**
     * Strip Markdown/HTML markup from content for plain-text search indexing.
     */
    private function stripForSearch(string $content): string
    {
        // Remove YAML frontmatter if present (shouldn't be, but defensive)
        $content = preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $content);

        // Remove Markdown images ![alt](url)
        $content = preg_replace('/!\[([^\]]*)\]\([^)]+\)/', '$1', $content);

        // Remove Markdown links [text](url) → keep text
        $content = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $content);

        // Remove Markdown headings markers
        $content = preg_replace('/^#{1,6}\s+/m', '', $content);

        // Remove bold/italic markers
        $content = preg_replace('/(\*{1,3}|_{1,3})(.+?)\1/', '$2', $content);

        // Remove code blocks
        $content = preg_replace('/```[\s\S]*?```/', '', $content);
        $content = preg_replace('/`([^`]+)`/', '$1', $content);

        // Remove HTML tags
        $content = strip_tags($content);

        // Remove shortcode tags
        $content = preg_replace('/\[\/?\w+[^\]]*\]/', '', $content);

        // Collapse whitespace
        $content = preg_replace('/\s+/', ' ', $content);

        // Truncate to a reasonable size for the search index
        if (mb_strlen($content) > 2000) {
            $content = mb_substr($content, 0, 2000);
        }

        return trim($content);
    }
}
