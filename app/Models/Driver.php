<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['license_expires_on' => 'date'];
    }
}
