<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class DynamicModel extends Model
{
    use HasFactory;

    protected $table;
    protected $fillable = [];

    public function __construct(array $attributes = [], array $columns = [])
    {
        parent::__construct($attributes);
        $this->fillable = $columns;
    }

    /**
     * Initialize the model with the given table.
     *
     * @param string $table
     * @return $this
     */

    /**
     * Every dynamic table name passes through here, and nothing else may set one.
     *
     * G-SEC-21 - THE STRUCTURAL FIX, not a per-site patch.
     *
     * The custom-module feature validated `table_name` ONLY at creation
     * (CustomModuleController::tableStore) and every downstream use trusted the
     * stored value - 21 uses across the controller and this model, plus 6
     * external call sites. G-SEC-20 proved the door was unlocked: the column
     * accepted `Z_probe;SELECT<empty-comment>1`, and DB::statement executes
     * multiple statements.
     *
     * Validation at the door is necessary and NOT sufficient, because the door
     * was open for the lifetime of the feature and the values are already
     * stored. So the check lives at the point of USE, where it cannot be
     * bypassed by any path that reaches a dynamic table.
     *
     * A table name is an IDENTIFIER, never data: MySQL identifiers here are
     * [A-Za-z0-9_]. Anything else is refused loudly - a malformed table name is
     * never a legitimate request, and failing silently would hand back an empty
     * result that reads like "no records".
     */
    protected static function assertSafeTable(string $table): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new \InvalidArgumentException('Unsafe dynamic table name.');
        }

        return $table;
    }

    public function initialize(string $table)
    {
        $this->setTable($table);
        $this->setFillableAttributes();

        return $this;
    }

    /**
     * Set the table name dynamically.
     *
     * @param string $table
     */
    public function setTable($table)
    {
        $table = static::assertSafeTable((string) $table);

        $this->table = $table;
        parent::setTable($table); // Set the table for the parent Model class
    }

    /**
     * Set the fillable attributes dynamically based on the table columns.
     */
    protected function setFillableAttributes()
    {
        if (Schema::hasTable($this->table)) {
            $this->fillable = Schema::getColumnListing($this->table);

            // Debugging information
            Log::info('Table ' . $this->table . ' fillable attributes: ' . implode(', ', $this->fillable));
        } else {
            throw new \Exception("Table {$this->table} does not exist.");
        }
    }

    /**
     * Create a new record in the dynamic table.
     *
     * @param string $table
     * @param array $data
     * @return Model
     * @throws \Exception
     */
    public static function createRecord(string $table, array $data)
    {
        $table = static::assertSafeTable($table);

        DB::table($table)->insert($data);
       /* $instance = (new static())->initialize($table);

        // Ensure all data keys are fillable
        $fillableAttributes = $instance->getFillable();
        $dataKeys = array_keys($data);

        $nonFillableKeys = array_diff($dataKeys, $fillableAttributes);
        if (!empty($nonFillableKeys)) {
            throw new \Exception('Non-fillable attributes detected: ' . implode(', ', $nonFillableKeys));
        }

        Log::info('Creating record with data: ' . json_encode($data));

        $createdRecord = $instance->create($data);

        Log::info('Record created: ' . json_encode($createdRecord));

        return $createdRecord;*/
    }

    /**
     * Retrieve records from the dynamic table.
     *
     * @param string $table
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function readRecords(string $table)
    {
        $table = static::assertSafeTable($table);

        $instance = (new static())->initialize($table);
        $query = $instance->newQuery();

        $currentYear = session('syear');
        $userId = session('user_id');
        $isAdmin = session()->get('user_profile_name') === "admin";

        if (Schema::hasColumn($table, 'syear') && $currentYear) {
            $query->where('syear', $currentYear);
        }

        if (!$isAdmin && Schema::hasColumn($table, 'created_by') && $userId) {
            $query->where('created_by', $userId);
        }

        return $query->get();
    }

    public static function readSingleRecord(string $table, int $id)
    {
        $table = static::assertSafeTable($table);

        $instance = (new static())->initialize($table);
        return $instance->find($id);
    }

    /**
     * Update a record in the dynamic table by ID.
     *
     * @param string $table
     * @param int $id
     * @param array $data
     * @return bool
     */
    public static function updateRecord(string $table, int $id, array $data)
    {
        $table = static::assertSafeTable($table);

        DB::table($table)->where('id', $id)->update($data);
        /*$instance = (new static())->initialize($table);
        $record = $instance->findOrFail($id);
        return $record->update($data);*/
    }

    /**
     * Delete a record from the dynamic table by ID.
     *
     * @param string $table
     * @param int $id
     * @return bool|null
     */
    public static function deleteRecord(string $table, int $id)
    {
        $table = static::assertSafeTable($table);

        $instance = (new static())->initialize($table);
        $record = $instance->findOrFail($id);
        return $record->delete();
    }
}
