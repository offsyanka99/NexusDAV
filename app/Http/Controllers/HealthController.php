<?php

namespace App\Http\Controllers;

use App\Support\DatabaseHealth;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(DatabaseHealth $database): JsonResponse
    {
        if (! $database->isUp()) {
            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
