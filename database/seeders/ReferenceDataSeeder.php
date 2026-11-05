<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Terminal;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $cities = [
            ['Addis Ababa', 'አዲስ አበባ', 'Addis Ababa', ['Lamberet Terminal', 'Meskel Square']],
            ['Mekelle', 'መቐለ', 'Tigray', ['Mekelle Bus Station']],
            ['Bahir Dar', 'ባሕር ዳር', 'Amhara', ['Bahir Dar Bus Station']],
            ['Gondar', 'ጎንደር', 'Amhara', ['Gondar Bus Station']],
            ['Hawassa', 'ሐዋሳ', 'Sidama', ['Hawassa Bus Station']],
            ['Dire Dawa', 'ድሬ ዳዋ', 'Dire Dawa', ['Dire Dawa Bus Station']],
            ['Adama', 'አዳማ', 'Oromia', ['Adama Bus Station']],
            ['Dessie', 'ደሴ', 'Amhara', ['Dessie Bus Station']],
            ['Jimma', 'ጅማ', 'Oromia', ['Jimma Bus Station']],
            ['Harar', 'ሐረር', 'Harari', ['Harar Bus Station']],
            ['Arba Minch', 'አርባ ምንጭ', 'South Ethiopia', ['Arba Minch Bus Station']],
            ['Jijiga', 'ጅጅጋ', 'Somali', ['Jijiga Bus Station']],
        ];
        foreach ($cities as [$en, $am, $region, $terminals]) {
            $city = City::firstOrCreate(['name_en' => $en], ['name_am' => $am, 'region' => $region]);
            foreach ($terminals as $t) {
                Terminal::firstOrCreate(['city_id' => $city->id, 'name' => $t]);
            }
        }
    }
}
