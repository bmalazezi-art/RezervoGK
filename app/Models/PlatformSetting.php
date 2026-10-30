<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public $timestamps = true;

    public static function value(string $key, mixed $default = null): mixed
    {
        $value = static::query()->whereKey($key)->value('value');

        if ($value === null) {
            return $default;
        }

        return match ($key) {
            'registration_enabled' => $value === '1',
            default => $value,
        };
    }
}
