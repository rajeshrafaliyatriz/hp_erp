<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Serves one picture of the G2G sales presentation from the sales_training_images table.
 * Public and read-only: the pictures are illustrations and diagrams, with nothing about any customer.
 */
class SalesTrainingImageController extends Controller
{
    public function show(Request $request, string $slug): Response
    {
        $row = DB::table('sales_training_images')->where('slug', $slug)->first(['mime', 'data', 'sha1']);
        if (! $row) {
            abort(404);
        }

        $etag = '"'.$row->sha1.'"';
        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            // an SVG opened on its own cannot run scripts or load anything else
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:",
        ];

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response($row->data, 200, $headers + ['Content-Type' => $row->mime]);
    }
}
