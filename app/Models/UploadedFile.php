<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Models\Concerns\BelongsToTenant;

class UploadedFile extends Model
{
    use HasUuids, BelongsToTenant;

    protected $fillable = ['id', 'original_name', 'mime_type', 'path', 'text_extracted', 'tenant_id'];
    public $incrementing = false;
    protected $keyType = 'string';
}
