<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function terminals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Terminal::class);
    }
}
