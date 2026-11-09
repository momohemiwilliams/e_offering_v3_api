<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * Minimal Vite integration for Laravel 8, mirroring the @vite directive that
 * later Laravel versions ship. It understands the files laravel-vite-plugin
 * writes: `public/hot` while `npm run dev` is running, and
 * `public/build/manifest.json` after `npm run build`.
 */
class Vite
{
    /**
     * Script and style tags for the given entry points.
     *
     * @param  array<int, string>|string  $entries
     */
    public static function tags($entries, string $buildDirectory = 'build'): HtmlString
    {
        $entries = (array) $entries;

        if (app()->runningUnitTests()) {
            return new HtmlString('');
        }

        if (static::isRunningHot()) {
            $url = static::hotUrl();
            $html = '<script type="module" src="'.e($url.'/@vite/client').'"></script>';

            foreach ($entries as $entry) {
                $html .= static::tag($url.'/'.ltrim($entry, '/'), $entry);
            }

            return new HtmlString($html);
        }

        $manifest = static::manifest($buildDirectory);
        $html = '';
        $seen = [];

        foreach ($entries as $entry) {
            if (! isset($manifest[$entry])) {
                throw new RuntimeException("Unable to locate file in Vite manifest: {$entry}. Run `npm run build`.");
            }

            $chunk = $manifest[$entry];

            foreach ($chunk['imports'] ?? [] as $import) {
                if (isset($manifest[$import]['file'])) {
                    $html .= static::collect($seen, '<link rel="modulepreload" href="'.e(asset($buildDirectory.'/'.$manifest[$import]['file'])).'">');
                }

                foreach ($manifest[$import]['css'] ?? [] as $css) {
                    $html .= static::collect($seen, static::styleTag(asset($buildDirectory.'/'.$css)));
                }
            }

            foreach ($chunk['css'] ?? [] as $css) {
                $html .= static::collect($seen, static::styleTag(asset($buildDirectory.'/'.$css)));
            }

            $html .= static::collect($seen, static::tag(asset($buildDirectory.'/'.$chunk['file']), $entry));
        }

        return new HtmlString($html);
    }

    /**
     * The React Fast Refresh preamble, needed only while the dev server runs.
     */
    public static function reactRefresh(): HtmlString
    {
        if (! static::isRunningHot() || app()->runningUnitTests()) {
            return new HtmlString('');
        }

        $url = e(static::hotUrl().'/@react-refresh');

        return new HtmlString(implode("\n", [
            '<script type="module">',
            "import RefreshRuntime from '{$url}';",
            'RefreshRuntime.injectIntoGlobalHook(window);',
            'window.$RefreshReg$ = () => {};',
            'window.$RefreshSig$ = () => (type) => type;',
            'window.__vite_plugin_react_preamble_installed__ = true;',
            '</script>',
        ]));
    }

    public static function isRunningHot(): bool
    {
        return is_file(public_path('hot'));
    }

    protected static function hotUrl(): string
    {
        return rtrim(trim((string) file_get_contents(public_path('hot'))), '/');
    }

    /** @return array<string, array<string, mixed>> */
    protected static function manifest(string $buildDirectory): array
    {
        static $manifests = [];

        $path = public_path($buildDirectory.'/manifest.json');

        if (! isset($manifests[$path])) {
            if (! is_file($path)) {
                throw new RuntimeException('Vite manifest not found at: '.$path.'. Run `npm run build` (or `npm run dev`).');
            }

            $manifests[$path] = json_decode((string) file_get_contents($path), true) ?: [];
        }

        return $manifests[$path];
    }

    protected static function tag(string $url, string $entry): string
    {
        return preg_match('/[.]css$/', $entry)
            ? static::styleTag($url)
            : '<script type="module" src="'.e($url).'"></script>';
    }

    protected static function styleTag(string $url): string
    {
        return '<link rel="stylesheet" href="'.e($url).'">';
    }

    /** @param  array<string, bool>  $seen */
    protected static function collect(array &$seen, string $tag): string
    {
        if (isset($seen[$tag])) {
            return '';
        }

        $seen[$tag] = true;

        return $tag;
    }
}
