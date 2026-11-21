<?php

namespace App\Http\Controllers\Duty;

use App\Http\Controllers\Controller;
use App\Models\DutySession;
use App\Models\SchoolClass;
use App\Services\Duty\RoundService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Кезекшінің сыныпты белгілеуі. Келісім: docs/api.md, «Кезекші API». */
class RoundController extends Controller
{
    public function __construct(private readonly RoundService $rounds) {}

    public function show(Request $request, SchoolClass $class): JsonResponse
    {
        /** @var DutySession $session */
        $session = $request->attributes->get('duty_session');

        try {
            $roster = $this->rounds->roster($session->code, $class);
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 404);
        }

        return response()->json(['class' => ['id' => $class->id, 'name' => $class->name()]] + $roster);
    }

    public function save(Request $request, SchoolClass $class): JsonResponse
    {
        /** @var DutySession $session */
        $session = $request->attributes->get('duty_session');
        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'absent_student_ids' => ['present', 'array', 'max:100'],
            'absent_student_ids.*' => ['integer'],
            'revision' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'client_time' => ['sometimes', 'nullable', 'date'],
        ]);

        try {
            $result = $this->rounds->save(
                $session, $class, strtolower($data['client_uuid']), $data['absent_student_ids'],
                $data['revision'] ?? null,
                isset($data['client_time']) ? CarbonImmutable::parse($data['client_time']) : null,
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), match ($e->getMessage()) {
                'class_not_in_shift' => 404,
                'round_changed' => 409,
                default => 422,
            });
        }

        return response()->json(
            ['class' => ['id' => $class->id, 'name' => $class->name()], 'stored_as' => $result['stored_as'], 'kept_locked' => $result['kept_locked']]
            + $this->rounds->roster($session->code, $class),
        );
    }
}
