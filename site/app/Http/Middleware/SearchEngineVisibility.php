<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SearchEngineVisibility
{
    /**
     * Prevent non-production environments from being indexed, even when a
     * crawler ignores robots.txt.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('creative_ai.allow_indexing')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }
}
