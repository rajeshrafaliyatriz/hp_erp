<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\auth\tbluserModel;
use App\Models\auth\schoolSetupModel;

class TblUserJourneyLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'tbl_user_journey_logs';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The data type of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Indicates if the IDs use auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'sub_institute_id',
        'menu_id',
        'access_link',
        'event_type',
        'step_key',
        'timestamp',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'timestamp' => 'datetime',
    ];

    /**
     * Get the user that owns the journey log.
     */
    public function user()
    {
        return $this->belongsTo(tbluserModel::class, 'user_id', 'id');
    }

    /**
     * Get the school/substitute associated with the journey log.
     */
    public function schoolSetup()
    {
        return $this->belongsTo(schoolSetupModel::class, 'sub_institute_id', 'id');
    }

    /**
     * Get the menu associated with the journey log.
     */
    public function menu()
    {
        return $this->belongsTo(tblmenumasterModel::class, 'menu_id', 'id');
    }
}
