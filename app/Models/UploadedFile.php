<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;


class UploadedFile extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'original_name', 'mime_type', 'path', 'text_extracted'];
    public $incrementing = false;
    protected $keyType = 'string';
}
