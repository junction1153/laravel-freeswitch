<?php

namespace App\Console\Commands;

use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\ProvisioningTemplate;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ProvisionTemplatesSeed extends Command
{
    protected $signature = 'prov:templates:seed 
        {--vendor= : Only seed for a specific vendor folder}
        {--dry-run : Show what would change, don\'t write}
        {--dedupe-only : Skip seeding and only run checksum-based dedupe}';

    protected $description = 'Seed locked DEFAULT provisioning templates from resources/provisioning/<vendor>/<TemplateName> and dedupe by checksum';

    public function handle(): int
    {
        // Gracefully skip if table doesn't exist
        if (!Schema::hasTable('provisioning_templates')) {
            $this->warn('Skipping prov:templates:seed — table "provisioning_templates" not found. (Run migrations first.)');
            return 0; // treat as success so callers won't fail
        }

        $base = resource_path('provisioning');
        if (!File::exists($base)) {
            $this->error("Missing folder: $base");
            return 1;
        }

        $dry = (bool) $this->option('dry-run');

        if (!$this->option('dedupe-only')) {
            [$inserted, $updated, $skipped] = $this->runSeeder($base, $dry);
            $this->info("Seed complete. Inserted: {$inserted}, Updated: {$updated}, Skipped: {$skipped}.");
        }

        // Always finish with a checksum-based dedupe pass
        [$removed, $repointed] = $this->runDedupe($dry);
        $this->info("Dedupe complete. Removed duplicates: {$removed}, Re-pointed devices: {$repointed}.");

        return 0;
    }

    private function runSeeder(string $base, bool $dry): array
    {
        $vendorFilter = strtolower((string) $this->option('vendor'));
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach (File::directories($base) as $vendorDir) {
            $vendor = strtolower(basename($vendorDir));
            if ($vendorFilter && $vendorFilter !== $vendor) {
                continue;
            }

            foreach (File::directories($vendorDir) as $tplDir) {
                $templateName = basename($tplDir); // folder name is the template name

                // enforce exactly one .blade.php per template folder
                $files = collect(File::files($tplDir))
                    ->filter(fn($f) => Str::endsWith($f->getFilename(), '.blade.php'))
                    ->values();

                if ($files->count() === 0) {
                    $this->warn("[$vendor/$templateName] no .blade.php found");
                    continue;
                }
                if ($files->count() > 1) {
                    $this->error("[$vendor/$templateName] multiple .blade.php files—keep exactly one");
                    continue;
                }

                $file = $files->first();
                $src  = File::get($file->getPathname());
                $meta = $this->parseFrontMatter($src);

                $version = $meta['version'] ?? null;
                if (!$version || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                    $this->error("[$vendor/$templateName] invalid or missing SemVer in {$file->getFilename()}");
                    continue;
                }

                $checksum = hash('sha256', $src);

                // Find any existing DEFAULT template for this vendor/name (regardless of version)
                $existingAny = ProvisioningTemplate::where('vendor', $vendor)
                    ->where('name', $templateName)
                    ->where('type', 'default')
                    ->orderBy('created_at', 'asc') // prefer the oldest; dedupe will clean later
                    ->first();

                if ($existingAny) {
                    // If content is identical, skip
                    if ($existingAny->checksum === $checksum) {
                        $this->line("[skip] {$vendor}/{$templateName} @ {$version}");
                        $skipped++;
                        continue;
                    }

                    // Replace the existing template in-place
                    $this->line(($dry ? '[dry]' : '[update]') . " {$vendor}/{$templateName} -> {$version}");
                    if (!$dry) {
                        $existingAny->fill([
                            'version'       => $version,
                            'revision'      => 0,
                            'base_template' => null,
                            'base_version'  => null,
                            'content'       => $src,
                            'checksum'      => $checksum,
                            'updated_by'    => null,
                        ])->save();
                    }
                    $updated++;
                    continue;
                }

                // No existing record → create it
                $this->line(($dry ? '[dry]' : '[seed]') . " {$vendor}/{$templateName} @ {$version}");
                if (!$dry) {
                    ProvisioningTemplate::create([
                        // template_uuid generated by DB default
                        'domain_uuid'   => null,
                        'vendor'        => $vendor,
                        'name'          => $templateName,
                        'type'          => 'default',   // locked
                        'version'       => $version,    // SemVer
                        'revision'      => 0,           // defaults don’t use revisions
                        'base_template' => null,
                        'base_version'  => null,
                        'content'       => $src,
                        'checksum'      => $checksum,
                        'updated_by'    => null,
                    ]);
                }

                $inserted++;
            }
        }

        return [$inserted, $updated, $skipped];
    }

    /**
     * Simple checksum-based dedupe:
     * - Find checksums with count > 1 among DEFAULT templates
     * - Keep the OLDEST by created_at
     * - Re-point devices to the kept template
     * - Delete newer duplicates
     *
     * NOTE: This intentionally scopes to type='default'.
     * If you want to dedupe customs too, remove that where clause.
     */
    private function runDedupe(bool $dry): array
    {
        if (!Schema::hasTable('provisioning_templates')) {
            return [0, 0];
        }

        $removed = 0;
        $repointed = 0;

        // Find duplicate checksums among DEFAULT templates
        $dupeChecksums = DB::table('provisioning_templates')
            ->select('checksum')
            ->where('type', '=', 'default')
            ->groupBy('checksum')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('checksum');

        foreach ($dupeChecksums as $checksum) {
            // Keep the oldest row; remove the more recent ones
            $rows = ProvisioningTemplate::where('type', 'default')
                ->where('checksum', $checksum)
                ->orderBy('created_at', 'asc')
                ->get();

            if ($rows->count() < 2) {
                continue;
            }

            $keep   = $rows->first();
            $delete = $rows->slice(1);
            $idsToDelete = $delete->pluck('template_uuid')->all();

            $this->line(sprintf(
                "[dedupe] checksum=%s keep=%s delete=%s",
                substr($checksum, 0, 12) . '…',
                $keep->template_uuid,
                implode(',', $idsToDelete)
            ));

            if (!$dry) {
                DB::transaction(function () use ($idsToDelete, $keep, &$repointed, &$removed) {
                    // Re-point devices that referenced a duplicate to the kept template
                    $repointed += DB::table('v_devices')
                        ->whereIn('device_template_uuid', $idsToDelete)
                        ->update(['device_template_uuid' => $keep->template_uuid]);

                    // Remove newer duplicates
                    ProvisioningTemplate::whereIn('template_uuid', $idsToDelete)->delete();
                    $removed += count($idsToDelete);
                });
            } else {
                $removed += count($idsToDelete);
            }
        }

        return [$removed, $repointed];
    }

    /**
     * Parse Blade front-matter at the very top:
     * {{-- 
     * version: 1.0.8
     * --}}
     */
    private function parseFrontMatter(string $src): ?array
    {
        // Look only at the start of the file for speed
        $head = substr($src, 0, 8192);

        // Accept optional UTF-8 BOM, then Blade comment front-matter
        if (
            preg_match('/\A(?:\xEF\xBB\xBF)?\s*\{\{\-\-\s*(.*?)\s*\-\-\}\}/s', $head, $m)
            // Optional: also accept HTML comment style
            || preg_match('/\A(?:\xEF\xBB\xBF)?\s*<!--\s*(.*?)\s*-->/s', $head, $m)
        ) {
            $out = [];
            foreach (preg_split('/\r?\n/', trim($m[1])) as $line) {
                if (preg_match('/^\s*([A-Za-z0-9_\-]+)\s*:\s*(.+?)\s*$/', $line, $kv)) {
                    $out[strtolower($kv[1])] = trim($kv[2]);
                }
            }
            return $out;
        }

        return null;
    }
}
