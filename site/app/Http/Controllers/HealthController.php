<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

class HealthController extends Controller
{
    public function ready(): JsonResponse
    {
        try {
            DB::select('select 1');
            Redis::connection()->ping();

            if (! is_writable(storage_path('app/public'))) {
                throw new RuntimeException('Persistent storage is not writable.');
            }
        } catch (Throwable) {
            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ready']);
    }
}
