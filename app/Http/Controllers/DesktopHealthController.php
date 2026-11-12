<?php

namespace App\Http\Controllers;

use App\Services\DesktopStatus;
use Illuminate\Http\JsonResponse;

class DesktopHealthController extends Controller
{
    public function __invoke(DesktopStatus $status): JsonResponse
    {
        return new JsonResponse($status->health(), 200, ['Cache-Control' => 'no-store']);
    }
}
