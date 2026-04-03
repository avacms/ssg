# Ava SSG (Static Site Generator)

A plugin for [Ava CMS](https://github.com/avacms/ava) that generates a complete static HTML site from your content. Deploy to GitHub Pages, Netlify, Cloudflare Pages, S3, or any static hosting provider — no PHP required on the production server.

> **Alpha software.** This plugin is under early development and testing. It could work for common site structures but will have edge cases with complex routing, custom plugin routes, or unusual configurations. Please report issues and test thoroughly before deploying to production.

## Features

- Generates all content pages, archive listings, and taxonomy pages
- Handles pagination automatically
- Generates sitemap.xml and feed.xml from Ava's bundled plugins
- Copies media files and theme assets
- Generates a JSON search index for client-side search
- Creates HTML redirect pages for `redirect_from` frontmatter
- Supports base URL override for subdirectory deployments
- Built-in preview server with clean URL support
- Respects `noindex` frontmatter — excluded from search index

## Requirements

- Ava CMS (latest version)
- PHP 8.3+

## Installation

1. Copy the `ssg` folder into `app/plugins/`:

```
app/plugins/ssg/
├── plugin.php
├── src/
│   ├── Builder.php
│   ├── PageRenderer.php
│   ├── RouteCollector.php
│   └── SearchIndexGenerator.php
└── README.md
```

2. Enable the plugin in `app/config/ava.php`:

```php
'plugins' => [
    'sitemap',
    'feed',
    'redirects',
    'ssg',
],
```

3. Rebuild the content index:

```bash
./ava rebuild
```

## Usage

### Build a Static Site

```bash
./ava static:build
```

This generates the complete static site in the `dist/` directory (default).

### Options

| Option | Description |
|--------|-------------|
| `--clean` | Remove output directory before building |
| `--output=DIR` | Custom output directory (default: `dist`) |
| `--base-url=URL` | Override the site's base URL for the build |
| `--no-assets` | Skip copying static assets (media, theme files) |
| `--no-search` | Skip generating the search index |

### Examples

```bash
# Clean build
./ava static:build --clean

# Build to a custom directory
./ava static:build --output=public_html

# Build for a subdirectory deployment (e.g., GitHub Pages project site)
./ava static:build --base-url=https://user.github.io/my-project

# Build without search index
./ava static:build --no-search
```

### Preview Locally

```bash
./ava static:serve
./ava static:serve --port=3000
```

Starts a local PHP server pointing at the generated site with clean URL support. Visit `http://127.0.0.1:8080` (default port).

### Clean Up

```bash
./ava static:clean
```

Removes the output directory entirely.

## Configuration

Add optional settings under `'ssg'` in `app/config/ava.php`:

```php
'ssg' => [
    'output_dir'             => 'dist',       // Output directory (relative to project root)
    'base_url'               => null,         // Override site base URL (null = use site.base_url)
    'copy_media'             => true,         // Copy public/ directory contents
    'generate_search_index'  => true,         // Generate search-index.json
    'extra_paths'            => [],           // Additional URL paths to generate
    'exclude_paths'          => [],           // URL paths/patterns to skip
    'redirects'              => 'html',       // 'html' = generate redirect pages, 'none' = skip
],
```

### Extra Paths

If you have custom routes registered in your theme or plugins that aren't automatically discovered, add them explicitly:

```php
'extra_paths' => [
    '/custom-page',
    '/api/data.json',
],
```

### Excluding Paths

Skip specific paths or patterns from generation:

```php
'exclude_paths' => [
    '/api/*',        // Wildcard suffix matching
    '/preview/*',
    '/draft-page',   // Exact match
],
```

## Output Structure

The generator creates clean URLs using `path/index.html`:

```
dist/
├── index.html              ← / (homepage)
├── about/
│   └── index.html          ← /about
├── blog/
│   ├── index.html          ← /blog (archive)
│   └── hello-world/
│       └── index.html      ← /blog/hello-world
├── category/
│   ├── index.html          ← /category (taxonomy index)
│   └── tutorials/
│       └── index.html      ← /category/tutorials
├── 404/
│   └── index.html          ← Custom 404 page
├── feed.xml                ← RSS feed
├── sitemap.xml             ← Sitemap index
├── sitemap-post.xml        ← Per-type sitemap
├── search-index.json       ← Client-side search data
├── robots.txt              ← From public/
├── media/                  ← Copied from public/media/
│   └── ...
└── theme/                  ← Theme CSS, JS, images
    ├── style.css
    └── script.js
```

## Search Index

The plugin generates a `search-index.json` file containing all published, indexable content. Each entry includes:

```json
{
    "title": "Hello World",
    "url": "/blog/hello-world",
    "type": "post",
    "excerpt": "Welcome to your new site...",
    "body": "Plain text content stripped of Markdown...",
    "date": "2024-01-15",
    "category": ["tutorials"],
    "tag": ["php", "beginner"]
}
```

You can use this with client-side search libraries:

- **[Pagefind](https://pagefind.app/)** — Auto-indexes your HTML (recommended, ignores this file)
- **[Fuse.js](https://fusejs.io/)** — Lightweight fuzzy search
- **[Lunr.js](https://lunrjs.com/)** — Full-text search in the browser
- **Custom JavaScript** — Fetch and filter `search-index.json` directly

## Deployment

### GitHub Pages

```bash
./ava static:build --base-url=https://username.github.io/repo --clean
# Then push dist/ to gh-pages branch
```

### Netlify

Set the build command (or build locally and drag-drop):

```bash
./ava rebuild && ./ava static:build --clean
```

Publish directory: `dist`

### Generic Static Host

Upload the contents of `dist/` to your web root. Ensure your server is configured to serve `path/index.html` for clean URLs, or configure it to try `$uri/index.html` as a fallback.

**Nginx example:**

```nginx
server {
    root /var/www/html;
    index index.html;

    location / {
        try_files $uri $uri/index.html $uri/ =404;
    }

    error_page 404 /404/index.html;
}
```

## Caveats & Limitations

### What Works

- All published content pages, archive listings, taxonomy pages
- Pagination for archives and taxonomy terms
- Plugin-generated routes (sitemap.xml, feed.xml)
- Theme assets and media files
- Redirects (as HTML meta-refresh pages)
- Custom 404 page
- Per-item CSS/JS assets

### What Doesn't Work

| Feature | Why | Workaround |
|---------|-----|------------|
| **Server-side search** | Requires PHP at runtime | Use `search-index.json` with a client-side library |
| **Preview mode** | Needs PHP to check tokens | Preview locally with `./ava static:serve` |
| **Form handling** | No server-side processing | Use a form service (Formspree, Netlify Forms, etc.) |
| **Dynamic API routes** | Custom PHP handlers | Pre-generate API responses as JSON files via `extra_paths` |
| **`cache: false` pages** | All pages are static by definition | Content is still generated; it's just always "cached" |
| **Webpage cache headers** | Static hosts set their own headers | Configure caching at the hosting level |
| **Comments / user content** | No server-side state | Use a third-party service (Disqus, Giscus, etc.) |

### Recommended Workflow

1. **Develop locally** using Ava normally (`php -S localhost:8000 -t public`)
2. **Preview and test** your content with the dynamic site
3. **Generate the static site** when ready to deploy: `./ava static:build --clean`
4. **Preview the static build** with `./ava static:serve`
5. **Deploy** the `dist/` directory to your static host

## License

This plugin is released under the [GNU General Public License v3.0](https://www.gnu.org/licenses/gpl-3.0.html) (GPL-3.0), the same license as Ava CMS.
