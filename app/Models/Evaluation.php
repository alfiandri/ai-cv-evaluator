<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Evaluation extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'cv_file_id',
        'project_file_id',
        'job_description',
        'study_case_brief',
        'status',
        'result_json',
        'error'
    ];
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = ['result_json' => 'array'];
}
