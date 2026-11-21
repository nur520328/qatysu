<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRoundConflict;
use App\Services\Duty\ConflictService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Екі планшеттің қақтығысын завуч шешеді. Келісім: docs/api.md. */
class RoundConflictController extends Controller
{
    public function __construct(private readonly ConflictService $conflicts) {}

    public function index(): JsonResponse
    {
        return response()->json(['conflicts' => $this->conflicts->open()]);
    }

    public function resolve(Request $request, AttendanceRoundConflict $conflict): JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', Rule::in(['keep', 'replace'])]]);

        try {
            $this->conflicts->resolve($conflict, $data['resolution'], $request->user());
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 409);
        }

        return response()->json(['conflicts' => $this->conflicts->open()]);
    }
}
