<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Models\Concerns\BelongsToTenant;

class Evaluation extends Model
{
    use HasUuids, BelongsToTenant;

    protected $fillable = [
        'id',
        'cv_file_id',
        'project_file_id',
        'job_description',
        'study_case_brief',
        'status',
        'result_json',
        'error',
        'user_id',
        'tenant_id',
    ];
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = ['result_json' => 'array'];
}
