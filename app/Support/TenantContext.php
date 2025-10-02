<?php

namespace App\Support;

class TenantContext
{
    private static ?string $id = null;
    public static function set(?string $tenantId): void
    {
        self::$id = $tenantId;
    }
    public static function id(): ?string
    {
        return self::$id;
    }
}
