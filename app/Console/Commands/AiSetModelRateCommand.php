<?php

namespace App\Console\Commands;

use App\Support\ColumnExists;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sets what a model costs, on its row in the AI model catalogue (`ai_models`). A dry run unless --apply is given. This is how the developer company
 * fills the platform's default prices: platform rows are read-only in the Model Management screen, so there is no other way to price them. An
 * organisation fills its own row in that screen; --tenant exists for the same job done from the command line.
 *
 * Prices are given the way vendors publish them (per 1,000,000 tokens, or per 1,000 with --per=1000) and stored per 1,000 tokens, in the stated
 * currency (rupees unless --currency says otherwise). The command only UPDATES a row that already exists: it never creates one, and it never touches
 * the platform row when --tenant names an organisation. A price that is already filled in is not replaced without --overwrite. Running it twice with
 * the same values changes nothing.
 *
 *   php artisan ai:set-model-rate --provider=deepseek --model=deepseek-flash --input=28.80 --output=115.20                    (dry run, platform row)
 *   php artisan ai:set-model-rate --provider=deepseek --model=deepseek-flash --input=28.80 --output=115.20 --apply
 *   php artisan ai:set-model-rate --provider=deepseek --model=deepseek-flash --input=0.30 --output=1.20 --currency=USD --tenant=6 --apply --database=live
 */
class AiSetModelRateCommand extends Command
{
    /** Per 1,000 tokens is what the column holds; per 1,000,000 is how vendors quote it. Anything else is a typo waiting to happen. */
    private const PER = [1000, 1000000];

    /** The column is DECIMAL(12,6). */
    private const MAX_PER_1K = 999999.999999;

    protected $signature = 'ai:set-model-rate
        {--provider= : Provider key as in the model catalogue (for example: deepseek)}
        {--model= : Model id as in the model catalogue (for example: deepseek-flash)}
        {--input= : Price of the input tokens, for --per tokens}
        {--output= : Price of the output tokens, for --per tokens}
        {--per=1000000 : The number of tokens the prices are quoted for: 1000000 or 1000}
        {--currency= : Currency of the prices; one of config ai.currencies (default: config ai.default_currency)}
        {--tenant= : One organisation (sub_institute_id) whose own row is set. Without it the PLATFORM row (every organisation) is set}
        {--database= : Connection name; the default connection when omitted (for example: live)}
        {--apply : Write the prices (default: show what would be written)}
        {--overwrite : Replace a price that already holds a value}';

    protected $description = 'Set the price of a model in the AI catalogue (per 1,000,000 tokens in, stored per 1,000). Updates an existing row only. Dry run unless --apply.';

    public function handle(): int
    {
        $request = $this->request();
        if ($request === null) {
            return self::INVALID;
        }

        $previous = DB::getDefaultConnection();
        try {
            if ($request['database'] !== null) {
                DB::setDefaultConnection($request['database']);
            }

            return $this->priceRow($request);
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    /** @param  array{provider:string, model:string, input:float, output:float, quoted_input:float, quoted_output:float, per:int, currency:string, tenant:int|null, database:string|null}  $request */
    private function priceRow(array $request): int
    {
        $apply = (bool) $this->option('apply');
        $this->line(($apply ? 'APPLY' : 'DRY RUN') . ' on connection ' . DB::getDefaultConnection());

        if (! Schema::hasTable('ai_models')) {
            $this->error('This database has no ai_models table.');

            return self::FAILURE;
        }
        if (! ColumnExists::has('ai_models', 'currency')) {
            $this->error('ai_models has no currency column here, so a price cannot be recorded with its currency. Run 2026_12_08_100000_add_currency_to_ai_models first.');

            return self::FAILURE;
        }

        $row = $this->row($request);
        if ($row === null) {
            return self::FAILURE;
        }

        $this->line(sprintf(
            'Row:    %s / %s, %s, id %d',
            $row->provider,
            $row->model_id,
            $row->sub_institute_id === null ? 'platform (shared by every organisation)' : 'organisation ' . $row->sub_institute_id,
            $row->id
        ));
        $this->line('Before: ' . $this->describe($row->input_cost_per_1k, $row->output_cost_per_1k, $row->currency));
        $this->line('After:  ' . $this->describe($request['input'], $request['output'], $request['currency'])
            . sprintf('   (quoted as %s and %s per %s tokens)', $this->plain($request['quoted_input']), $this->plain($request['quoted_output']), number_format($request['per'])));

        $held = $this->heldValues($row, $request);
        if ($held === [] && $this->sameAs($row, $request)) {
            $this->info('These prices are already stored. Nothing to change.');

            return self::SUCCESS;
        }
        if ($held !== [] && ! $this->option('overwrite')) {
            $this->error('Not changed: ' . implode(' and ', $held) . ' already hold a price. Add --overwrite to replace it.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->line('Dry run: nothing was written. Run again with --apply to write.');

            return self::SUCCESS;
        }

        DB::transaction(fn () => DB::table('ai_models')->where('id', $row->id)->update([
            'input_cost_per_1k' => $request['input'],
            'output_cost_per_1k' => $request['output'],
            'currency' => $request['currency'],
            'updated_at' => now(),
        ]));

        $stored = DB::table('ai_models')->where('id', $row->id)->first(['input_cost_per_1k', 'output_cost_per_1k', 'currency']);
        $this->line('Stored: ' . $this->describe($stored->input_cost_per_1k, $stored->output_cost_per_1k, $stored->currency));
        $this->info('Done. Running it again changes nothing.');

        return self::SUCCESS;
    }

    /**
     * What was asked for, checked. Null after an error was printed.
     *
     * @return array{provider:string, model:string, input:float, output:float, quoted_input:float, quoted_output:float, per:int, currency:string, tenant:int|null, database:string|null}|null
     */
    private function request(): ?array
    {
        $provider = trim((string) $this->option('provider'));
        $model = trim((string) $this->option('model'));
        if ($provider === '' || $model === '') {
            return $this->refuse('--provider and --model are both required (they name the catalogue row to price).');
        }

        $per = (string) $this->option('per');
        if (! ctype_digit($per) || ! in_array((int) $per, self::PER, true)) {
            return $this->refuse('--per must be 1000000 (how vendors publish prices) or 1000.');
        }

        $quoted = [];
        foreach (['input', 'output'] as $which) {
            $value = $this->option($which);
            if (! is_string($value) || trim($value) === '' || ! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
                return $this->refuse("--{$which} must be a price of 0 or more.");
            }
            $quoted[$which] = (float) $value;
        }

        $stored = [];
        foreach ($quoted as $which => $value) {
            $per1k = round($value * 1000 / (int) $per, 6);
            if ($per1k > self::MAX_PER_1K) {
                return $this->refuse("--{$which} is too large to store.");
            }
            if ($value > 0 && $per1k === 0.0) {
                return $this->refuse("--{$which} is too small to store: prices are kept to six decimals per 1,000 tokens.");
            }
            $stored[$which] = $per1k;
        }

        $currencies = array_map('strval', array_keys((array) config('ai.currencies', [])));
        $currency = strtoupper(trim((string) ($this->option('currency') ?: config('ai.default_currency', 'INR'))));
        if (! in_array($currency, $currencies, true)) {
            return $this->refuse("--currency must be one of: " . implode(', ', $currencies) . '.');
        }

        // A tenant that is given but unreadable must stop the command: falling back to the platform row would price every organisation.
        $tenant = $this->option('tenant');
        if ($tenant !== null && $tenant !== '' && (! ctype_digit((string) $tenant) || (int) $tenant <= 0)) {
            return $this->refuse('--tenant must be an organisation id (a whole number above 0).');
        }

        $database = $this->option('database');
        if ($database !== null && $database !== '' && config('database.connections.' . $database) === null) {
            return $this->refuse("There is no database connection called '{$database}'.");
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'input' => $stored['input'],
            'output' => $stored['output'],
            'quoted_input' => $quoted['input'],
            'quoted_output' => $quoted['output'],
            'per' => (int) $per,
            'currency' => $currency,
            'tenant' => $tenant === null || $tenant === '' ? null : (int) $tenant,
            'database' => $database === null || $database === '' ? null : (string) $database,
        ];
    }

    private function refuse(string $message): ?array
    {
        $this->error($message);

        return null;
    }

    /** The one catalogue row to price, or null after an error was printed. */
    private function row(array $request): ?object
    {
        $query = DB::table('ai_models')->where('provider', $request['provider'])->where('model_id', $request['model']);
        $request['tenant'] === null ? $query->whereNull('sub_institute_id') : $query->where('sub_institute_id', $request['tenant']);

        $rows = $query->get(['id', 'provider', 'model_id', 'sub_institute_id', 'input_cost_per_1k', 'output_cost_per_1k', 'currency']);
        $where = $request['tenant'] === null ? 'the platform catalogue' : 'the catalogue of organisation ' . $request['tenant'];

        if ($rows->isEmpty()) {
            $this->error("There is no {$request['provider']} / {$request['model']} in {$where}. This command only prices a model that is already listed: add it in AI & Intelligence, Model Management first.");

            return null;
        }
        if ($rows->count() > 1) {
            $this->error("{$request['provider']} / {$request['model']} is listed more than once in {$where} (ids " . $rows->pluck('id')->implode(', ') . '). Remove the duplicate first.');

            return null;
        }

        return $rows->first();
    }

    /**
     * The prices that already hold a value and would change: a different input or output price, or a different currency over a price that exists.
     *
     * @return list<string>
     */
    private function heldValues(object $row, array $request): array
    {
        $held = [];
        $currencyChanges = strtoupper(trim((string) $row->currency)) !== $request['currency'];

        foreach (['input' => 'input_cost_per_1k', 'output' => 'output_cost_per_1k'] as $which => $column) {
            if ($row->{$column} === null) {
                continue;
            }
            if ($currencyChanges || abs(round((float) $row->{$column}, 6) - $request[$which]) > 0.0000001) {
                $held[] = "the {$which} price";
            }
        }

        return $held;
    }

    private function sameAs(object $row, array $request): bool
    {
        return $row->input_cost_per_1k !== null && $row->output_cost_per_1k !== null
            && abs(round((float) $row->input_cost_per_1k, 6) - $request['input']) <= 0.0000001
            && abs(round((float) $row->output_cost_per_1k, 6) - $request['output']) <= 0.0000001
            && strtoupper(trim((string) $row->currency)) === $request['currency'];
    }

    /** "input 0.0288, output 0.1152 per 1,000 tokens, INR"; an empty price reads as a dash, never as 0. */
    private function describe(mixed $input, mixed $output, mixed $currency): string
    {
        return sprintf(
            'input %s, output %s per 1,000 tokens, %s',
            $input === null ? '-' : $this->plain((float) $input),
            $output === null ? '-' : $this->plain((float) $output),
            strtoupper(trim((string) $currency))
        );
    }

    private function plain(float $number): string
    {
        $text = rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }
}
