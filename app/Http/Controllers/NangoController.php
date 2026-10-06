<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Nango-backed Google Calendar OAuth.
 *
 * This class did not exist, but four routes referenced it - two in
 * routes/api.php and two in routes/web.php. Every call therefore died with an
 * unhandled "Class not found" and a 500 HTML stack trace, which leaks file
 * paths and framework internals to the caller.
 *
 * This is a deliberate stub, not an implementation. The integration itself
 * needs a Nango account, a secret key and a decision about where connection
 * state lives, none of which can be inferred from the routes alone. What it
 * does do is fail honestly: a clear 501 saying the integration is not
 * configured, instead of an exception page.
 *
 * To implement: set NANGO_SECRET_KEY / NANGO_HOST, replace these bodies with
 * real Nango calls, and record the connection on lms_integrations (provider
 * 'google') so Administration & Governance reports it. Note that table stores
 * no tokens by design - those stay with Nango.
 */
class NangoController extends Controller
{
    /** Shared response so every entry point fails the same way. */
    private function notConfigured(string $capability)
    {
        return response()->json([
            'status' => false,
            'configured' => false,
            'message' => "The Google Calendar integration is not configured, so {$capability} is unavailable.",
        ], 501);
    }

    /** POST /api/nango/google/check-connection */
    public function checkConnection(Request $request)
    {
        // Answerable without Nango: report no connection rather than erroring,
        // since "is it connected?" has a truthful answer here.
        return response()->json([
            'status' => true,
            'configured' => false,
            'connected' => false,
            'message' => 'The Google Calendar integration is not configured.',
        ]);
    }

    /** POST /api/nango/google/oauth-url */
    public function getOauthUrl(Request $request)
    {
        return $this->notConfigured('starting a Google authorisation');
    }

    /** GET /nango/google/connect/{userId} */
    public function connectGoogle(Request $request, $userId = null)
    {
        return $this->notConfigured('connecting a Google account');
    }

    /** GET /nango/google/callback */
    public function googleCallback(Request $request)
    {
        return $this->notConfigured('completing the Google authorisation');
    }
}
