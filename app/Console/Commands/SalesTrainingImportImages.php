<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads the pictures of the G2G sales presentation into sales_training_images.
 *
 *   php artisan sales-training:import-images <folder>
 *
 * The folder holds manifest.json (a list of { slug, file, mime, alt, tag, credit }) and the files it names.
 * docs/sales-training/generator/export-story-deck.js writes both. Running it again updates the rows in place.
 */
class SalesTrainingImportImages extends Command
{
    protected $signature = 'sales-training:import-images {folder : Folder with manifest.json and the image files}';

    protected $description = 'Store the G2G sales presentation pictures in the sales_training_images table';

    public function handle(): int
    {
        $folder = rtrim((string) $this->argument('folder'), '/\\');
        $manifestPath = $folder.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($manifestPath)) {
            $this->error("No manifest.json in {$folder}");

            return self::FAILURE;
        }

        $items = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($items) || $items === []) {
            $this->error('manifest.json is empty or not valid JSON');

            return self::FAILURE;
        }

        $allowed = ['image/svg+xml', 'image/png', 'image/jpeg'];
        $done = 0;
        foreach ($items as $item) {
            $slug = (string) ($item['slug'] ?? '');
            $file = $folder.DIRECTORY_SEPARATOR.basename((string) ($item['file'] ?? ''));
            $mime = (string) ($item['mime'] ?? '');
            if (! preg_match('/^[a-z0-9-]{1,80}$/', $slug) || ! in_array($mime, $allowed, true) || ! is_file($file)) {
                $this->error("Skipped {$slug}: bad slug, type or missing file");

                return self::FAILURE;
            }
            $data = (string) file_get_contents($file);
            $now = now();
            $exists = DB::table('sales_training_images')->where('slug', $slug)->exists();
            $values = [
                'mime' => $mime,
                'alt' => $item['alt'] ?? null,
                'tag' => $item['tag'] ?? null,
                'credit' => $item['credit'] ?? null,
                'bytes' => strlen($data),
                'sha1' => sha1($data),
                'data' => $data,
                'updated_at' => $now,
            ];
            if ($exists) {
                DB::table('sales_training_images')->where('slug', $slug)->update($values);
            } else {
                DB::table('sales_training_images')->insert($values + ['slug' => $slug, 'created_at' => $now]);
            }
            $done++;
        }

        $this->info("Stored {$done} pictures in sales_training_images.");

        return self::SUCCESS;
    }
}
