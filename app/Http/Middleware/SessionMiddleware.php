<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

        if($type !== "API"  &&  $type != "JSON"){
         
            $user_id = $request->session()->get('user_id');
            if(empty($user_id)){
                
                return redirect(route('home'));
            }
        }

        return $next($request);
    }
}
