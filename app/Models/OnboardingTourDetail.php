<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OnboardingTourDetail extends Model
{
    use SoftDeletes;

    protected $table = 'Onboarding_tour_details';

    protected $fillable = [
        'menu_id',
        'access_link',
        'event_type',
        'title',
        'description',
        'on_click',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the menu that owns the tour detail.
     */
    public function menu()
    {
        return $this->belongsTo(tblmenumasterModel::class, 'menu_id', 'id');
    }
}
