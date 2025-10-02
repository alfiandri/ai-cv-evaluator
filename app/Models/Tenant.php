<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Tenant extends Model
{
    use HasUuids;
    protected $fillable = ['id', 'name', 'slug', 'user_id'];
    public $incrementing = false;
    protected $keyType = 'string';
}
