<?php

namespace Database\Seeders;

use App\Models\Operator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The 20 launch operators, all "invited" and unsigned. They are NOT shown publicly
 * until a platform admin activates them AND records the signed partnership agreement.
 */
class OperatorSeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            'Selam Bus Line S.C.', 'Abay Bus', 'ODAA Integrated Transport', 'Ethio Bus Transport Service', 'Zemen Bus',
            'Golden Bus Transport S.C.', 'Yegna Bus Transport PLC', 'Limalimo Bus Transport S.C.', 'Liyu Africa Express Bus',
            'Dream Liner Bus PLC', 'Birhan Bus', 'Buna Bus', 'Air Bus Transport', 'Habesha Bus Transport', 'Fiker Bus',
            'FM Bus', 'Ghion Bus', 'Noah Transport S.C.', 'Felege Ghion Bus Transport', 'Feker Bus Association',
        ];
        foreach ($names as $name) {
            Operator::firstOrCreate(
                ['slug' => Str::slug(preg_replace('/\s+(S\.C\.|PLC)$/', '', $name))],
                ['name' => $name, 'category' => 'intercity', 'status' => 'invited'],
            );
        }
    }
}
