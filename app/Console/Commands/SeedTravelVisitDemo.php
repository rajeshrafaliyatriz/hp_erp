<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Demo trips for Travel Visit, for ONE organisation.
 *
 *   php artisan travel:seed-demo 6              add the demo trips for tenant 6
 *   php artisan travel:seed-demo 6 --remove     take exactly those back out
 *
 * Builds completed trips (a route with one point a minute, like the phone
 * records) for the organisation's real employees, plus one trip still ongoing.
 * Demo trips carry the remarks "[Demo data]"; --remove deletes only those.
 * Safe to run twice: it does nothing if demo trips are already there.
 */
class SeedTravelVisitDemo extends Command
{
    protected $signature = 'travel:seed-demo {sub_institute_id} {--remove : Delete the demo trips instead}';
    protected $description = 'Add (or remove) demo Travel Visit trips with route points for one organisation';

    private const MARK = '[Demo data]';

    /** [from lat, lng, address, to lat, lng, address] - real Vadodara places, so the map is believable. */
    private const ROUTES = [
        [22.2397495, 73.1767608, '65QG+VP Vadodara, Gujarat, India', 22.2812898, 73.2124569, 'Pratapnagar, Danteshwar, Vadodara, Gujarat 390004'],
        [22.3072, 73.1812, 'Alkapuri, Vadodara, Gujarat, India', 22.3350, 73.1900, 'Gotri Road, Vadodara, Gujarat, India'],
        [22.2587, 73.1980, 'Manjalpur, Vadodara, Gujarat, India', 22.3039, 73.2120, 'Fatehgunj, Vadodara, Gujarat, India'],
    ];

    private const PURPOSES = ['Client Visit', 'Site Visit', 'Installation', 'Maintenance', 'Other'];
    private const VEHICLES = ['Two Wheeler', 'Four Wheeler', 'Two Wheeler'];

    public function handle(): int
    {
        $tenant = (int) $this->argument('sub_institute_id');

        if ($this->option('remove')) {
            $ids = DB::table('hrms_travel_visits')
                ->where('sub_institute_id', $tenant)
                ->where('remarks', 'like', self::MARK . '%')
                ->pluck('id');
            DB::table('hrms_travel_visit_points')->whereIn('visit_id', $ids)->delete();
            DB::table('hrms_travel_visits')->whereIn('id', $ids)->delete();
            $this->info("Removed {$ids->count()} demo trip(s).");

            return self::SUCCESS;
        }

        if (DB::table('hrms_travel_visits')->where('sub_institute_id', $tenant)->where('remarks', 'like', self::MARK . '%')->exists()) {
            $this->warn('Demo trips are already there. Use --remove first to rebuild them.');

            return self::SUCCESS;
        }

        $employees = DB::table('tbluser')
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->orderBy('id')
            ->limit(3)
            ->pluck('id');

        if ($employees->isEmpty()) {
            $this->error("No active employees found for tenant {$tenant}.");

            return self::FAILURE;
        }

        mt_srand(42);
        $made = 0;

        foreach ($employees as $n => $employeeId) {
            // Two finished trips on earlier days, per employee.
            foreach ([2, 1] as $k => $daysAgo) {
                $route = self::ROUTES[($n + $k) % count(self::ROUTES)];
                $start = now()->subDays($daysAgo)->setTime(9 + $k * 3, 14 + $n * 7, 9);
                $this->makeTrip($tenant, (int) $employeeId, $route, $start, 28 + $n * 4 + $k * 6, false, $k);
                $made++;
            }
        }

        // One trip still in progress, started 20 minutes ago.
        $this->makeTrip($tenant, (int) $employees->first(), self::ROUTES[1], now()->subMinutes(20)->setSecond(9), 20, true, 0);
        $made++;

        $this->info("Added {$made} demo trips with route points. Remove them with: php artisan travel:seed-demo {$tenant} --remove");

        return self::SUCCESS;
    }

    private function makeTrip(int $tenant, int $employeeId, array $route, $start, int $minutes, bool $ongoing, int $k): void
    {
        [$fLat, $fLng, $fAddr, $tLat, $tLng, $tAddr] = $route;
        $points = [];
        $km = 0.0;

        for ($i = 0; $i <= $minutes; $i++) {
            $t = $i / $minutes;
            // A gentle bend plus a little GPS noise, so it looks like roads, not a ruler line.
            $lat = $fLat + ($tLat - $fLat) * $t + 0.004 * sin($t * M_PI * 2) + (mt_rand(-20, 20) / 1000000);
            $lng = $fLng + ($tLng - $fLng) * $t + 0.003 * sin($t * M_PI) + (mt_rand(-20, 20) / 1000000);
            $at = (clone $start)->addMinutes($i);

            if ($points) {
                $p = end($points);
                $km += $this->km((float) $p['lat'], (float) $p['lng'], $lat, $lng);
            }

            $points[] = [
                'sub_institute_id' => $tenant,
                'lat' => round($lat, 7),
                'lng' => round($lng, 7),
                'accuracy' => mt_rand(5, 25),
                'speed' => $i === 0 ? 0 : mt_rand(20, 130) / 10,
                'recorded_at' => $at->format('Y-m-d H:i:s'),
                'created_at' => now(),
            ];
        }

        $last = end($points);
        $count = DB::table('hrms_travel_visits')->where('sub_institute_id', $tenant)->where('visit_no', 'like', 'TV/' . $employeeId . '/' . $start->format('my') . '/%')->count();

        $id = DB::table('hrms_travel_visits')->insertGetId([
            'sub_institute_id' => $tenant,
            'employee_id' => $employeeId,
            'visit_no' => 'TV/' . $employeeId . '/' . $start->format('my') . '/' . str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT),
            'purpose' => self::PURPOSES[($employeeId + $k) % count(self::PURPOSES)],
            'vehicle_type' => self::VEHICLES[($employeeId + $k) % count(self::VEHICLES)],
            'remarks' => self::MARK . ' CCTV project work',
            'status' => $ongoing ? 'ongoing' : 'completed',
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $ongoing ? null : $last['recorded_at'],
            'from_lat' => $points[0]['lat'],
            'from_lng' => $points[0]['lng'],
            'from_address' => $fAddr,
            'to_lat' => $ongoing ? null : $last['lat'],
            'to_lng' => $ongoing ? null : $last['lng'],
            'to_address' => $ongoing ? null : $tAddr,
            'distance' => round($km, 3),
            'app_distance' => $ongoing ? null : round($km * 0.97, 3),
            'created_by' => $employeeId,
            'updated_by' => $employeeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (array_chunk(array_map(fn ($p) => $p + ['visit_id' => $id], $points), 200) as $chunk) {
            DB::table('hrms_travel_visit_points')->insert($chunk);
        }
    }

    private function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371.0088 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
