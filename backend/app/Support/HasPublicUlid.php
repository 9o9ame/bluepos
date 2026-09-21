<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasPublicUlid
{
    protected static function bootHasPublicUlid(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('ulid'))) {
                $model->setAttribute('ulid', (string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
