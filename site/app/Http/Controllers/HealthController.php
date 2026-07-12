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
            Redis::connection('cache')->ping();

            foreach (['app/private', 'app/public'] as $storageDirectory) {
                $path = storage_path($storageDirectory);

                if (! is_dir($path) || ! is_writable($path)) {
                    throw new RuntimeException('Persistent storage is not ready.');
                }
            }
        } catch (Throwable) {
            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ready']);
    }
}
