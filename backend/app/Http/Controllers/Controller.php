<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /** Қате жауабы: машина оқитын код, мәтінсіз (мәтінді интерфейс аударады). */
    protected function error(string $code, int $status = 422): JsonResponse
    {
        return response()->json(['code' => $code], $status);
    }
}
