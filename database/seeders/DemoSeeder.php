<?php

namespace Database\Seeders;

use App\Models\Bus;
use App\Models\City;
use App\Models\Operator;
use App\Models\Route;
use App\Models\Terminal;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/** Local demo data only. Logins: admin@bemengede.test / selam@bemengede.test, password "password". */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $selam = Operator::where('slug', 'selam-bus-line')->firstOrFail();
        $selam->update([
            'status' => 'active',
            'partnership_signed_at' => now(), // demo only — in production an admin records the real signing
            'legal_name' => 'Selam Bus Line S.C.',
            'brand_color' => '#1E6B57',
            'logo_alt' => 'Selam Bus logo',
        ]);
        $selam->accounts()->firstOrCreate(['bank' => 'CBE', 'account_number' => '1000000004521'], ['account_name' => 'Selam Bus Line S.C. (demo)']);
        $selam->accounts()->firstOrCreate(['bank' => 'TELEBIRR', 'account_number' => '500021'], ['account_name' => 'Selam Bus Line S.C. (demo)']);

        $user = User::firstOrNew(['email' => 'admin@bemengede.test']);
        $user->fill(['phone' => '+251900000001', 'name' => 'Platform Admin'])->forceFill(['role' => User::ROLE_PLATFORM_ADMIN, 'password' => 'password', 'adult_confirmed_at' => now()])->save();
        $owner = User::firstOrNew(['email' => 'selam@bemengede.test']);
        $owner->fill(['phone' => '+251900000002', 'name' => 'Selam Owner'])->forceFill(['role' => User::ROLE_OPERATOR_OWNER, 'operator_id' => $selam->id, 'password' => 'password', 'adult_confirmed_at' => now()])->save();

        $layout = ['rows' => 13, 'columns' => 4, 'aisle_after' => 2, 'blocked' => []];
        $buses = collect(['AA 3-B12345', 'AA 3-B19044', 'AA 3-B20517'])->map(fn ($plate, $i) => Bus::firstOrCreate(
            ['operator_id' => $selam->id, 'plate_number' => $plate],
            ['side_number' => 'SB-0'.($i + 3), 'class' => $i === 0 ? 'vip' : 'standard', 'seat_count' => 51, 'layout' => $layout, 'amenities' => $i === 0 ? ['ac', 'usb'] : ['ac']],
        ));

        $addis = Terminal::where('name', 'Lamberet Terminal')->firstOrFail();
        $routes = [
            ['Mekelle', 780, 735, 185000, '05:30', ['Dessie', 'Woldia']],
            ['Bahir Dar', 565, 600, 135000, '06:00', ['Debre Markos']],
            ['Hawassa', 275, 300, 65000, '06:30', ['Mojo', 'Ziway']],
            ['Gondar', 730, 720, 160000, '07:00', ['Debre Markos', 'Bahir Dar']],
        ];
        foreach ($routes as $i => [$city, $km, $mins, $fare, $time, $stops]) {
            $dest = Terminal::where('city_id', City::where('name_en', $city)->value('id'))->firstOrFail();
            $route = Route::firstOrCreate(
                ['operator_id' => $selam->id, 'origin_terminal_id' => $addis->id, 'destination_terminal_id' => $dest->id],
                ['distance_km' => $km, 'duration_minutes' => $mins, 'stops' => $stops],
            );
            for ($d = 0; $d < 14; $d++) {
                $dep = Carbon::parse(now('Africa/Addis_Ababa')->addDays($d)->format('Y-m-d').' '.$time, 'Africa/Addis_Ababa')->utc();
                Trip::firstOrCreate(
                    ['route_id' => $route->id, 'departure_at' => $dep],
                    ['operator_id' => $selam->id, 'bus_id' => $buses[$i % 3]->id, 'arrival_at' => $dep->copy()->addMinutes($mins), 'base_fare' => $fare],
                );
            }
        }
    }
}
