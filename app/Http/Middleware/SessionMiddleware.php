<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\App;
use Illuminate\Http\Request;
use App\Http\Controllers\auth\authController;
use App\Models\auth\tbluserModel;
use Symfony\Component\HttpFoundation\Response; // Add this import

class SessionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $type = $request->input("type");

        if ($type == "web") {
            if ($request->has('user_id')) {
                $user = tbluserModel::where('id', $request->user_id)->where('status', 1)->first();

                if (!empty($user) && isset($user->id)) {
                    // Create a new internal request
                    $newRequest = Request::create('/login', 'GET', [
                        'type' => 'API',
                        'email' => $user->email,
                        'password' => $user->plain_password,
                    ]);

                    $newRequest->setLaravelSession(Session::driver());

                    // Call controller
                    $controller = new authController;
                    $getData = $controller->index($newRequest);
                }
            }
        }

        return $next($request);
    }
}