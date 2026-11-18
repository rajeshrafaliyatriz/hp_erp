<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Locations\LocationCatalog;
use App\Domain\Signals\Support\RegionInvalid;
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

    private const DEFAULT_COUNTRY_CODE = 'IN';

    /** The label a country's first-level divisions go by, only when the data itself says so (else the neutral three-way label). @param list<array{type: ?string}> $states */
    private static function stateLabel(array $states): string
    {
        $counts = [];
        foreach ($states as $s) {
            $type = strtolower(trim((string) ($s['type'] ?? '')));
            if ($type !== '') {
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }
        arsort($counts);
        $top = (string) (array_key_first($counts) ?? '');

        return match (true) {
            $top === 'state' || str_contains($top, 'state') => 'State',
            str_contains($top, 'province') => 'Province',
            str_contains($top, 'region') => 'Region',
            str_contains($top, 'district') => 'District',
            str_contains($top, 'county') => 'County',
            default => 'State / Province / Region',   // the data names no single kind (for example a mix of councils and boroughs): say so neutrally
        };
    }

    /**
     * Every country the location data knows, India first by default. `q` narrows by name or code (case and accents ignored). About 250 small rows,
     * so the whole list is fine to send; states and cities are fetched per selection.
     */
    public function countries(Request $request): JsonResponse
    {
        $catalog = LocationCatalog::shared();
        $q = LocationCatalog::fold((string) $request->query('q', ''));
        $rows = array_values(array_filter($catalog->countries(), fn (array $c) => $q === ''
            || str_contains(LocationCatalog::fold($c['name']), $q) || LocationCatalog::fold($c['code']) === $q || LocationCatalog::fold($c['iso3']) === $q));

        return response()->json(['status' => 1, 'data' => [
            'default_country_code' => self::DEFAULT_COUNTRY_CODE,
            'countries' => $rows,
            'data_version' => $catalog->meta()['version'] ?? null,
        ]]);
    }

    /** The states / provinces / regions of one country, from the location data only. A country without any says so: nothing is invented. */
    public function states(Request $request): JsonResponse
    {
        $catalog = LocationCatalog::shared();
        $country = $catalog->country((string) $request->query('country', ''));
        if ($country === null) {
            return response()->json(['status' => 0, 'code' => 'unknown_country', 'message' => 'That country is not in the location data.'], 422);
        }
        $states = $catalog->states($country['code']);
        $q = LocationCatalog::fold((string) $request->query('q', ''));
        $rows = $q === '' ? $states : array_values(array_filter($states, fn (array $s) => str_contains(LocationCatalog::fold($s['name']), $q) || LocationCatalog::fold($s['code']) === $q));

        return response()->json(['status' => 1, 'data' => [
            'country' => $country,
            'states' => $rows,
            'has_states' => $states !== [],
            'label' => self::stateLabel($states),
            'has_cities' => $catalog->hasCities($country['code']),
            'message' => $states === [] ? "{$country['name']} has no states or provinces in the location data. Choose the country" . ($catalog->hasCities($country['code']) ? ' (and a city) ' : ' ') . 'only.' : null,
        ]]);
    }

    /**
     * Cities of one state, searched on the server and cut to `limit` (default 50): the browser never receives a whole state. For a country the data
     * lists no states for, the cities listed directly under the country.
     */
    public function cities(Request $request): JsonResponse
    {
        $catalog = LocationCatalog::shared();
        $country = $catalog->country((string) $request->query('country', ''));
        if ($country === null) {
            return response()->json(['status' => 0, 'code' => 'unknown_country', 'message' => 'That country is not in the location data.'], 422);
        }
        $stateInput = trim((string) $request->query('state', ''));
        $state = null;
        if ($country['has_states']) {
            if ($stateInput === '') {
                return response()->json(['status' => 0, 'code' => 'state_required', 'message' => 'Choose the state / province first.'], 422);
            }
            $state = $catalog->state($country['code'], $stateInput);
            if ($state === null) {
                return response()->json(['status' => 0, 'code' => 'unknown_state', 'message' => "That is not a state, province or region of {$country['name']}."], 422);
            }
        }
        $query = trim((string) $request->query('q', ''));
        $found = $catalog->cities($country['code'], $state['code'] ?? null, $query, (int) $request->query('limit', 50));

        return response()->json(['status' => 1, 'data' => [
            'country_code' => $country['code'], 'state_code' => $state['code'] ?? null,
            'cities' => $found['items'], 'total' => $found['total'], 'truncated' => $found['truncated'],
            'message' => $found['total'] === 0 && $query === '' ? 'There is no city data for this ' . ($state !== null ? 'state' : 'country') . ' in the location data.' : null,
        ]]);
    }

    /**
     * Checks one place without saving anything: the state must belong to the country and the city to the state. Returns the place with the
     * catalogue's codes, or the reasons it was refused. Places the data does not know come back as not verified, never as another country.
     */
    public function validatePlace(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'country' => 'nullable|string|max:80', 'state' => 'nullable|string|max:80', 'city' => 'nullable|string|max:80',
            'country_code' => 'nullable|string|size:2', 'state_code' => 'nullable|string|max:10', 'city_id' => 'nullable|integer',
        ]);
        if ($v->fails() || (! $request->filled('country') && ! $request->filled('country_code'))) {
            return response()->json(['status' => 0, 'code' => 'invalid_region', 'message' => 'Choose a country.', 'errors' => ['regions' => ['region 1 country: required']]], 422);
        }
        try {
            $scope = RegionScope::fromInput([array_filter($request->only(['country', 'state', 'city', 'country_code', 'state_code', 'city_id']), fn ($x) => $x !== null && $x !== '')]);
        } catch (RegionInvalid $e) {
            return response()->json(['status' => 0, 'code' => 'invalid_region', 'message' => $e->getMessage(), 'errors' => ['regions' => $e->problems]], 422);
        }

        return response()->json(['status' => 1, 'data' => $scope?->structured()[0] ?? null]);
    }

    /**
     * The older name-only listing (countries, then a country's states, then a state's cities), kept for existing callers. It now reads the full location
     * data, plus the places people added themselves. New code should use countries(), states() and cities().
     */
    public function index(Request $request): JsonResponse
    {
        $catalog = LocationCatalog::shared();
        $country = trim((string) $request->query('country', ''));
        $state = trim((string) $request->query('state', ''));

        $custom = [];
        if (Schema::hasTable('g2g_locations')) {
            $custom = DB::table('g2g_locations')->get()->toArray();
        }

        $countries = array_map(fn (array $c) => $c['name'], $catalog->countries());
        foreach ($custom as $row) {
            if (! empty($row->country) && ! in_array($row->country, $countries, true)) {
                $countries[] = $row->country;
            }
        }
        $countries = array_values(array_unique($countries));
        $indiaKey = array_search('India', $countries, true);
        if ($indiaKey !== false && $indiaKey > 0) {
            unset($countries[$indiaKey]);
            array_unshift($countries, 'India');
        }

        $states = [];
        $cities = [];
        $known = $country !== '' ? $catalog->country($country) : null;
        if ($country !== '') {
            if ($known !== null) {
                $states = array_map(fn (array $s) => $s['name'], $catalog->states($known['code']));
            }
            foreach ($custom as $row) {
                if (mb_strtolower((string) $row->country) === mb_strtolower($country) && ! empty($row->state)) {
                    $states[] = $row->state;
                }
            }
            $states = array_values(array_unique($states));
            sort($states, SORT_NATURAL | SORT_FLAG_CASE);
        }
        if ($country !== '' && $state !== '') {
            if ($known !== null && ($s = $catalog->state($known['code'], $state)) !== null) {
                $cities = array_map(fn (array $c) => $c['name'], $catalog->cities($known['code'], $s['code'], null, 500)['items']);
            }
            foreach ($custom as $row) {
                if (mb_strtolower((string) $row->country) === mb_strtolower($country) && mb_strtolower((string) $row->state) === mb_strtolower($state) && ! empty($row->city)) {
                    $cities[] = $row->city;
                }
            }
            $cities = array_values(array_unique($cities));
            sort($cities, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return response()->json([
            'status' => 1,
            'data' => ['default_country' => 'India', 'countries' => $countries, 'states' => $states, 'cities' => $cities],
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

