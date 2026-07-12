<?php

namespace EzEcommerce\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\Ulid;

abstract class CommerceModel extends Model
{
    protected static bool $usesPublicId = false;

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (static::$usesPublicId && empty($model->public_id)) {
                $model->public_id = (string) Ulid::generate();
            }
        });
    }
}
