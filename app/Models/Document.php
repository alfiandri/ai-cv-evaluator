<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Document extends Model
{
    use BelongsToTenant;

    protected $fillable = ['type', 'content', 'embedding', 'meta'];
    protected $casts = ['embedding' => 'array', 'meta' => 'array'];
}
