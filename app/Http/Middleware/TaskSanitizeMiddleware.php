<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TaskSanitizeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->merge($this->sanitizeArray($request->all()));

        return $next($request);
    }

    private function sanitizeArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitizeArray($value);
                continue;
            }

            if (is_string($value)) {
                $clean = preg_replace('/^\xEF\xBB\xBF/u', '', $value);
                $data[$key] = is_string($clean) ? trim($clean) : trim($value);
            }
        }

        return $data;
    }
}