<?php

namespace App\Services\Duty;

use App\Enums\Shift;
use App\Models\DutyCode;
use App\Models\DutySession;
use App\Models\Setting;
use App\Models\User;
use App\Services\CalendarService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Кезекші кодын беру, жою және тексеру. Ережелер: docs/кезең-4-жоспар.md. */
class DutyCodeService
{
    public function __construct(private readonly CalendarService $calendar) {}

    /**
     * Завуч код бере алатын күндер: бүгін (оқу күні болса) және келесі оқу күні.
     *
     * @return list<CarbonImmutable>
     */
    public function issuableDates(): array
    {
        $today = CarbonImmutable::today();
        $dates = $this->calendar->isSchoolDay($today) ? [$today] : [];
        $next = $this->calendar->nextSchoolDay($today);

        return $next === null ? $dates : [...$dates, $next];
    }

    public function shiftEnd(CarbonImmutable $date, Shift $shift): CarbonImmutable
    {
        $minutes = Setting::int($shift === Shift::Morning ? 'morning_shift_end' : 'afternoon_shift_end');

        return $date->startOfDay()->addMinutes($minutes);
    }

    /**
     * Жаңа код береді; сол күн мен сменаның бұрынғы коды жарамсыз болады.
     *
     * @return array{DutyCode, string} код жазбасы және ашық код (бір-ақ рет көрсетіледі)
     *
     * @throws DomainException date_not_issuable | shift_ended
     */
    public function issue(CarbonImmutable $date, Shift $shift, User $admin): array
    {
        $date = $date->startOfDay();
        if (! in_array($date->toDateString(), array_map(static fn (CarbonImmutable $d): string => $d->toDateString(), $this->issuableDates()), true)) {
            throw new DomainException('date_not_issuable');
        }
        $expiresAt = $this->shiftEnd($date, $shift);
        if ($expiresAt->isPast()) {
            throw new DomainException('shift_ended');
        }

        return DB::transaction(function () use ($date, $shift, $admin, $expiresAt): array {
            foreach ($this->activeQuery($date, $shift)->lockForUpdate()->get() as $old) {
                $this->revoke($old);
            }
            $plain = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
            $code = DutyCode::query()->create([
                'date' => $date, 'shift' => $shift, 'code_hash' => Hash::make($plain),
                'issued_by' => $admin->id, 'expires_at' => $expiresAt,
            ]);

            return [$code, $plain];
        });
    }

    /** Кодты жояды; сол кодпен кірген планшеттер келесі сұраныста шығарылады. */
    public function revoke(DutyCode $code): void
    {
        if ($code->revoked_at === null) {
            $code->update(['revoked_at' => now()]);
            DutySession::query()->where('duty_code_id', $code->id)->whereNull('ended_at')->update(['ended_at' => now()]);
        }
    }

    public function active(CarbonImmutable $date, Shift $shift): ?DutyCode
    {
        return $this->activeQuery($date, $shift)->latest('id')->first();
    }

    /** Терілген кодқа сәйкес, дәл қазір жарамды код (бүгінгі, мерзімі өтпеген, жойылмаған). */
    public function findUsable(string $plain): ?DutyCode
    {
        $candidates = DutyCode::query()
            ->whereDate('date', CarbonImmutable::today()->toDateString())
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->get();

        foreach ($candidates as $code) {
            if (Hash::check($plain, $code->code_hash)) {
                return $code;
            }
        }

        return null;
    }

    /** @return Builder<DutyCode> */
    private function activeQuery(CarbonImmutable $date, Shift $shift)
    {
        return DutyCode::query()->whereDate('date', $date->toDateString())->where('shift', $shift)->whereNull('revoked_at');
    }
}
