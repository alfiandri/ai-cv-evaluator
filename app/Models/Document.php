<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = ['type', 'content', 'embedding', 'meta'];
    protected $casts = ['embedding' => 'array', 'meta' => 'array'];
}
