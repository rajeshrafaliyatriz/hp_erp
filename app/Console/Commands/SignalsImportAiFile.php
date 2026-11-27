<?php

namespace App\Console\Commands;

use App\Domain\Signals\AiSignals\AiDeliveryImporter;
use App\Domain\Signals\AiSignals\AiDeliveryReceiver;
use App\Domain\Signals\AiSignals\AiSignalNormalizer;
use App\Domain\Signals\AiSignals\AiSignalProviders;
use App\Domain\Signals\Ingestion\DataConnector;
use Illuminate\Console\Command;

/**
 * Imports a file of AI-delivered signals (the g2g.ai-signals.v1 JSON) into ONE organisation, through exactly the code behind "Or paste an output" on the
 * connector card: the same storage of the delivery, the same market importer, the same quality gate, the same duplicate rules, the same scope
 * classification, and the same sourced-contact handling. Nothing is bypassed and nothing is made up.
 *
 *   - It needs --tenant (no default) and the AI source card to be set up for that organisation already: it never creates a connector or a secret.
 *   - --dry-run reads and checks the file and touches no database.
 *   - Running the same file twice imports nothing twice (the delivery is recognised by its content).
 *   - It makes no search or AI call. The public-contact lookup is held back for these signals (they already carry their sourced contacts); pass
 *     --search-contacts to let it run as for any new signal.
 */
class SignalsImportAiFile extends Command
{
    protected $signature = 'signals:import-ai-file {file : path to the JSON file} {--tenant= : organisation (sub_institute_id); required} {--provider=claude : claude|chatgpt|deepseek|gemini} {--dry-run : check the file only; no database access} {--search-contacts : allow the automatic public-contact lookup for these signals}';

    protected $description = 'Import a JSON file of AI-delivered signals into one organisation through the same path as "Or paste an output" (checked, de-duplicated, never invented)';

    public function handle(AiDeliveryReceiver $receiver, AiDeliveryImporter $importer): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Cannot read the file: {$file}");

            return self::FAILURE;
        }
        $raw = (string) file_get_contents($file);
        $provider = strtolower(trim((string) $this->option('provider')));
        if (! in_array($provider, AiSignalProviders::keys(), true)) {
            $this->error('Unknown provider. Use one of: ' . implode(', ', AiSignalProviders::keys()));

            return self::FAILURE;
        }

        $parsed = AiSignalNormalizer::parse($raw);
        if (! $parsed['ok']) {
            $this->error('The file cannot be used: ' . (string) $parsed['error']);

            return self::FAILURE;
        }
        $this->info(count($parsed['records']) . ' signal(s) found in the file.');
        $withContacts = 0;
        foreach ($parsed['records'] as $i => $r) {
            $name = is_array($r) && is_array($r['buyer'] ?? null) ? (string) ($r['buyer']['name'] ?? '?') : '?';
            $n = is_array($r) && is_array($r['contacts'] ?? null) ? count($r['contacts']) : 0;
            $withContacts += $n > 0 ? 1 : 0;
            $this->line(sprintf('  #%d %s (%d contact(s) in the file)', $i + 1, $name, $n));
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run: the file reads correctly. Nothing was saved and no database was used.');

            return self::SUCCESS;
        }

        $tenant = (int) $this->option('tenant');
        if ($tenant <= 0) {
            $this->error('Pass --tenant=<sub_institute_id>.');

            return self::FAILURE;
        }
        $connector = DataConnector::where('sub_institute_id', $tenant)->where('type', AiSignalProviders::typeFor($provider))->orderBy('id')->first();
        if ($connector === null) {
            $this->error('Organisation ' . $tenant . ' has no ' . AiSignalProviders::label($provider) . ' source set up. Open the card in Connectors and press Set up first; this command never creates one.');

            return self::FAILURE;
        }
        if (! AiDeliveryReceiver::tableReady()) {
            $this->error('AI signal delivery is not set up on this server (the g2g_ai_deliveries table is missing). Run its migration first.');

            return self::FAILURE;
        }
        $this->info("Importing into organisation {$tenant}, source \"{$connector->name}\" (connector #{$connector->id}).");

        if (! $this->option('search-contacts')) {
            // This process only: the sourced contacts are in the file, so no search credit is spent on these signals.
            config(['signals_connectors.contact_research.enabled' => false]);
        }

        $received = $receiver->receive($connector, $raw, 'manual');
        if (! $received['ok']) {
            $this->error('Not received: ' . $received['message']);

            return self::FAILURE;
        }
        if ($received['duplicate']) {
            $this->warn('This exact file was already received for this source. Nothing new to import.');

            return self::SUCCESS;
        }

        $r = $importer->process($connector, null);
        if ($r['error'] !== null) {
            $this->error((string) $r['error']);

            return self::FAILURE;
        }
        $this->table(['received', 'new', 'updated', 'duplicate', 'rejected'], [[$r['records'], $r['accepted'], $r['updated'], $r['duplicate'], $r['rejected']]]);
        if ($r['rejected'] > 0 || $r['failed_deliveries'] > 0) {
            $this->warn('Some signals were rejected by the quality checks. Open the source card -> Recent deliveries to see each reason and how to fix it.');
        }
        $this->info('Done. The signals are in the normal Signals list, under today\'s date. Contacts in the file are saved with their source pages, unverified.');

        return $r['failed_deliveries'] > 0 && $r['accepted'] + $r['updated'] + $r['duplicate'] === 0 ? self::FAILURE : self::SUCCESS;
    }
}
