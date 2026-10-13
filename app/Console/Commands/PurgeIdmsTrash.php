<?php

namespace App\Console\Commands;

use App\Models\Idms\DocumentMaster;
use App\Services\Idms\DocumentStorageService;
use Illuminate\Console\Command;

class PurgeIdmsTrash extends Command
{
    protected $signature = 'idms:purge-trash';

    protected $description = 'Permanently delete IDMS documents that have been in the trash longer than the retention window';

    public function handle(DocumentStorageService $storage): int
    {
        $days = (int) config('idms.trash_retention_days', 30);
        $count = 0;

        DocumentMaster::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays($days))
            ->chunkById(100, function ($documents) use ($storage, &$count) {
                foreach ($documents as $document) {
                    $storage->deleteAllFiles($document);
                    $document->forceDelete();
                    $count++;
                }
            });

        $this->info("Purged {$count} document(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
