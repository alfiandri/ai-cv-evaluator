<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::creating(function ($model) {
            if ($model->isFillable('tenant_id')) {
                $model->tenant_id = $model->tenant_id ?? TenantContext::id();
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            $id = TenantContext::id();
            if ($id) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', $id);
            }
        });
    }
}
