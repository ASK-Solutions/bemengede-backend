<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatorAccount extends Model
{
    protected $guarded = ['id'];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /** "1000 •••• 4521" — never show a full account number publicly. */
    public function masked(): string
    {
        $n = preg_replace('/\D/', '', $this->account_number);

        return strlen($n) <= 6 ? $n : substr($n, 0, 4).' •••• '.substr($n, -4);
    }
}
