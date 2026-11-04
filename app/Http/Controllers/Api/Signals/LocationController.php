<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Support\RegionScope;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Location Management API for Signals Region Filtering.
 * Supports Country -> State -> City hierarchy with India as the default,
 * pre-seeded territories, and custom location persistence.
 */
class LocationController extends Controller
{
    use ResolvesApiIdentity;

    private const SEED_LOCATIONS = [
        'India' => [
            'Gujarat' => ['Ahmedabad', 'Surat', 'Vadodara', 'Rajkot', 'Gandhinagar', 'Bhavnagar', 'Jamnagar', 'Junagadh', 'Anand', 'Navsari', 'Morbi', 'Bharuch', 'Vapi', 'Mehsana'],
            'Maharashtra' => ['Mumbai', 'Pune', 'Nagpur', 'Thane', 'Nashik', 'Aurangabad', 'Solapur', 'Navi Mumbai', 'Kolhapur'],
            'Delhi' => ['New Delhi', 'North Delhi', 'South Delhi', 'East Delhi', 'West Delhi', 'Central Delhi'],
            'Karnataka' => ['Bengaluru', 'Mysuru', 'Hubballi', 'Mangaluru', 'Belagavi', 'Davanagere', 'Ballari'],
            'Tamil Nadu' => ['Chennai', 'Coimbatore', 'Madurai', 'Tiruchirappalli', 'Salem', 'Tirunelveli', 'Erode'],
            'Uttar Pradesh' => ['Lucknow', 'Kanpur', 'Ghaziabad', 'Agra', 'Varanasi', 'Meerut', 'Prayagraj', 'Noida'],
            'Rajasthan' => ['Jaipur', 'Jodhpur', 'Kota', 'Bikaner', 'Ajmer', 'Udaipur', 'Bhilwara', 'Alwar'],
            'Telangana' => ['Hyderabad', 'Warangal', 'Nizamabad', 'Karimnagar', 'Khammam'],
            'Kerala' => ['Thiruvananthapuram', 'Kochi', 'Kozhikode', 'Kollam', 'Thrissur', 'Kannur'],
            'West Bengal' => ['Kolkata', 'Howrah', 'Durgapur', 'Asansol', 'Siliguri'],
            'Andhra Pradesh' => ['Visakhapatnam', 'Vijayawada', 'Guntur', 'Nellore', 'Kurnool'],
            'Madhya Pradesh' => ['Indore', 'Bhopal', 'Jabalpur', 'Gwalior', 'Ujjain'],
            'Punjab' => ['Ludhiana', 'Amritsar', 'Jalandhar', 'Patiala', 'Bathinda'],
            'Haryana' => ['Gurugram', 'Faridabad', 'Panipat', 'Ambala', 'Karnal'],
            'Bihar' => ['Patna', 'Gaya', 'Bhagalpur', 'Muzaffarpur'],
            'Odisha' => ['Bhubaneswar', 'Cuttack', 'Rourkela', 'Berhampur'],
            'Assam' => ['Guwahati', 'Silchar', 'Dibrugarh', 'Jorhat'],
            'Goa' => ['Panaji', 'Margao', 'Vasco da Gama', 'Mapusa'],
            'Chandigarh' => ['Chandigarh'],
            'Uttarakhand' => ['Dehradun', 'Haridwar', 'Roorkee', 'Haldwani'],
            'Himachal Pradesh' => ['Shimla', 'Dharamshala', 'Solan', 'Mandi'],
            'Jharkhand' => ['Ranchi', 'Jamshedpur', 'Dhanbad', 'Bokaro'],
            'Chhattisgarh' => ['Raipur', 'Bhilai', 'Bilaspur', 'Korba'],
            'Jammu and Kashmir' => ['Srinagar', 'Jammu', 'Anantnag'],
            'Dadra and Nagar Haveli and Daman and Diu' => ['Daman', 'Diu', 'Silvassa'],
        ],
        'United States' => [
            'California' => ['Los Angeles', 'San Francisco', 'San Diego', 'San Jose', 'Sacramento'],
            'New York' => ['New York', 'Buffalo', 'Rochester', 'Albany'],
            'Texas' => ['Houston', 'Dallas', 'Austin', 'San Antonio'],
            'Washington' => ['Seattle', 'Spokane', 'Tacoma', 'Bellevue'],
            'Illinois' => ['Chicago', 'Aurora', 'Naperville'],
        ],
        'United Kingdom' => [
            'England' => ['London', 'Manchester', 'Birmingham', 'Leeds', 'Liverpool'],
            'Scotland' => ['Edinburgh', 'Glasgow', 'Aberdeen'],
            'Wales' => ['Cardiff', 'Swansea'],
            'Northern Ireland' => ['Belfast', 'Derry'],
        ],
        'United Arab Emirates' => [
            'Dubai' => ['Dubai'],
            'Abu Dhabi' => ['Abu Dhabi', 'Al Ain'],
            'Sharjah' => ['Sharjah'],
        ],
        'Canada' => [
            'Ontario' => ['Toronto', 'Ottawa', 'Mississauga'],
            'British Columbia' => ['Vancouver', 'Victoria', 'Surrey'],
            'Quebec' => ['Montreal', 'Quebec City'],
            'Alberta' => ['Calgary', 'Edmonton'],
        ],
        'Australia' => [
            'New South Wales' => ['Sydney', 'Newcastle', 'Wollongong'],
            'Victoria' => ['Melbourne', 'Geelong', 'Ballarat'],
            'Queensland' => ['Brisbane', 'Gold Coast'],
        ],
        'Germany' => [
            'Bavaria' => ['Munich', 'Nuremberg'],
            'Berlin' => ['Berlin'],
            'North Rhine-Westphalia' => ['Cologne', 'Dusseldorf'],
        ],
        'Singapore' => [
            'Central Region' => ['Singapore'],
        ],
    ];

    /**
     * Lists countries, states, and cities with India as default.
     */
    public function index(Request $request): JsonResponse
    {
        $country = trim((string) $request->query('country', ''));
        $state = trim((string) $request->query('state', ''));

        // Load custom locations from database if table exists
        $custom = [];
        if (Schema::hasTable('g2g_locations')) {
            $custom = DB::table('g2g_locations')->get()->toArray();
        }

        // Build countries list with India first
        $countries = array_keys(self::SEED_LOCATIONS);
        foreach ($custom as $row) {
            if (!empty($row->country) && !in_array($row->country, $countries, true)) {
                $countries[] = $row->country;
            }
        }
        $countries = array_values(array_unique($countries));
        $indiaKey = array_search('India', $countries, true);
        if ($indiaKey !== false && $indiaKey > 0) {
            unset($countries[$indiaKey]);
            array_unshift($countries, 'India');
        }

        // If specific country requested, return its states
        $states = [];
        if ($country !== '') {
            $matchedCountry = null;
            foreach (self::SEED_LOCATIONS as $c => $sList) {
                if (mb_strtolower($c) === mb_strtolower($country)) {
                    $matchedCountry = $c;
                    $states = array_keys($sList);
                    break;
                }
            }
            foreach ($custom as $row) {
                if (mb_strtolower((string) $row->country) === mb_strtolower($country) && !empty($row->state)) {
                    $states[] = $row->state;
                }
            }
            $states = array_values(array_unique($states));
            sort($states, SORT_NATURAL | SORT_FLAG_CASE);
        }

        // If specific state requested, return its cities
        $cities = [];
        if ($country !== '' && $state !== '') {
            foreach (self::SEED_LOCATIONS as $c => $sList) {
                if (mb_strtolower($c) === mb_strtolower($country)) {
                    foreach ($sList as $s => $cList) {
                        if (mb_strtolower($s) === mb_strtolower($state)) {
                            $cities = $cList;
                            break 2;
                        }
                    }
                }
            }
            foreach ($custom as $row) {
                if (
                    mb_strtolower((string) $row->country) === mb_strtolower($country) &&
                    mb_strtolower((string) $row->state) === mb_strtolower($state) &&
                    !empty($row->city)
                ) {
                    $cities[] = $row->city;
                }
            }
            $cities = array_values(array_unique($cities));
            sort($cities, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return response()->json([
            'status' => 1,
            'data' => [
                'default_country' => 'India',
                'countries' => $countries,
                'states' => $states,
                'cities' => $cities,
            ],
        ]);
    }

    /**
     * Stores a new location (Country -> State -> City).
     * Prevents duplicates and validates hierarchy.
     */
    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'country' => 'required|string|min:2|max:80',
            'state' => 'nullable|string|min:2|max:80',
            'city' => 'nullable|string|min:2|max:80',
        ]);

        if ($v->fails()) {
            return response()->json([
                'status' => 0,
                'message' => 'Invalid location data.',
                'errors' => $v->errors(),
            ], 422);
        }

        $country = trim((string) $request->input('country'));
        $state = $request->filled('state') ? trim((string) $request->input('state')) : null;
        $city = $request->filled('city') ? trim((string) $request->input('city')) : null;

        if ($city !== null && $state === null) {
            return response()->json([
                'status' => 0,
                'message' => 'A city needs its state/province.',
                'errors' => ['city' => ['Choose the state first.']],
            ], 422);
        }

        $key = mb_strtolower("{$country}|{$state}|{$city}");

        if (Schema::hasTable('g2g_locations')) {
            $existing = DB::table('g2g_locations')->where('normalized_key', $key)->first();
            if ($existing) {
                return response()->json([
                    'status' => 1,
                    'message' => 'Location already exists.',
                    'data' => [
                        'id' => $existing->id,
                        'country' => $existing->country,
                        'state' => $existing->state,
                        'city' => $existing->city,
                    ],
                ]);
            }

            $id = DB::table('g2g_locations')->insertGetId([
                'country' => $country,
                'state' => $state,
                'city' => $city,
                'normalized_key' => $key,
                'is_system' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'status' => 1,
                'message' => 'Location added successfully.',
                'data' => [
                    'id' => $id,
                    'country' => $country,
                    'state' => $state,
                    'city' => $city,
                ],
            ], 201);
        }

        return response()->json([
            'status' => 1,
            'message' => 'Location registered successfully.',
            'data' => [
                'country' => $country,
                'state' => $state,
                'city' => $city,
            ],
        ]);
    }
}

