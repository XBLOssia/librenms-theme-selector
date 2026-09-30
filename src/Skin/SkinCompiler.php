<?php

namespace Xblossia\ThemeSelector\Skin;

/**
 * Turns a skin bundle into something safe to publish, or explains why not.
 *
 *   zip  ->  ZipBundleReader     the entries, by allowlisted name, in memory
 *        ->  Manifest            id, name, ... (strict)
 *        ->  FontFile            each font is a real, unpadded WOFF/WOFF2
 *        ->  PngTexture          each texture is a real PNG, cleaned and re-written
 *        ->  TokenFile           the stylesheet, regenerated from a parse
 *        ->  GraphConf           the graph palette, exact shapes
 *
 * Nothing is written anywhere. The result is a CompiledSkin (the regenerated
 * stylesheet plus validated metadata) that the installer stores; the uploaded
 * bytes themselves are discarded.
 *
 * All stages run even after one fails, so the author gets the whole list.
 */
final class SkinCompiler
{
    public function __construct(private readonly TokenCatalog $catalog)
    {
    }

    public function compileZip(string $path, Report $report, Mode $mode = Mode::Upload): ?CompiledSkin
    {
        $files = ZipBundleReader::read($path, $report);

        return $files === null ? null : $this->compileFiles($files, $report, $mode);
    }

    /**
     * @param  array<string, string>  $files  entry name => contents
     */
    public function compileFiles(array $files, Report $report, Mode $mode = Mode::Upload): ?CompiledSkin
    {
        foreach (['skin.json', 'skin.css'] as $required) {
            if (! isset($files[$required])) {
                $report->error('bundle', $required . ' is missing');
            }
        }
        if (! $report->ok()) {
            return null;
        }

        $manifest = Manifest::parse($files['skin.json'], $report);

        $fonts = [];
        foreach ($files as $name => $bytes) {
            if (str_starts_with($name, 'fonts/')) {
                if (FontFile::check($name, $bytes, $report)) {
                    $fonts[$name] = $bytes;
                }
            }
        }

        $textures = [];
        $textureBytes = 0;
        foreach ($files as $name => $bytes) {
            if (str_starts_with($name, 'textures/')) {
                // Bundled skins ship as files, so theirs must already be clean.
                $t = PngTexture::check($name, $bytes, $report, $mode === Mode::Bundled);
                if ($t !== null) {
                    $textures[$name] = $t;
                    $textureBytes += strlen($t['png']);
                }
            }
        }
        if (count($textures) > Limits::TEXTURES) {
            $report->error('bundle', 'has more than ' . Limits::TEXTURES . ' textures');
        }
        if ($textureBytes > Limits::TEXTURES_TOTAL) {
            $report->error('bundle', 'has textures totalling ' . $textureBytes . ' bytes once cleaned; the limit is ' . Limits::TEXTURES_TOTAL);
        }

        $css = (new TokenFile($this->catalog, $mode))->compile($files['skin.css'], $fonts, $report, $textures);

        $graph = [];
        if (isset($files['graph.conf'])) {
            $parsed = GraphConf::parse($files['graph.conf'], $report);
            $graph = $parsed ?? [];
        }

        // The licence notice: data to store and show, never a served file.
        $licenseText = '';
        if (isset($files['LICENSE.txt'])) {
            $licenseText = LicenseText::check($files['LICENSE.txt'], $report) ?? '';
        }

        if (! $report->ok() || $manifest === null || $css === null) {
            return null;
        }

        return new CompiledSkin(
            $manifest,
            $css,
            $graph,
            hash('sha256', json_encode([$manifest, $css, $graph, $licenseText])),
            count($fonts),
            $licenseText,
            array_map(fn (string $file, array $t) => ['name' => substr($file, 9, -4), 'width' => $t['width'], 'height' => $t['height'], 'bytes' => strlen($t['png'])], array_keys($textures), array_values($textures)),
        );
    }
}
