<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public static function record(string $action, ?Model $subject = null, array $meta = [], ?int $operatorId = null): void
    {
        static::create([
            'user_id' => auth()->id(),
            'operator_id' => $operatorId ?? auth()->user()?->operator_id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
        ]);
    }
}
