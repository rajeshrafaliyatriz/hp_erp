<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use app\Http\Controllers\talent\talent_jobapplicationcontroller;
use app\Http\Controllers\talent\candidate\candidateController;
use App\Http\Controllers\talent\feedback\feedbackController;
class talent_jobapplication extends Model
{
	protected $table = 'talent_job_applications';
    protected $fillable = [
        'job_id',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'mobile',
        'current_location',
        'employment_type',
        'experience',
        'education',
        'expected_salary',
        'skills',
        'certifications',
        'resume_path',
        'applied_date',
        'status',
        'sub_institute_id',
        'created_by',
    ];

    public function hasReachedOfferOrHiredStage(): bool
    {
        $status = strtolower(trim((string) $this->status));
        if (str_contains($status, 'hired') || str_contains($status, 'offer')) {
            return true;
        }

        return DB::table('talent_offers')
            ->where('application_id', $this->id)
            ->whereIn(DB::raw('LOWER(status)'), ['sent', 'accepted'])
            ->exists();
    }
}


