<?php

namespace Tests\Feature\Duty;

use App\Enums\Shift;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Services\Duty\DutyCodeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Feature\Admin\AdminTestCase;

/** Кезекші тесттерінің ортақ негізі: жұма 2026-11-20, сағат 08:10 (таңғы сменаның 1-сабағы). Дерек жасанды. */
abstract class DutyTestCase extends AdminTestCase
{
    protected AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 8, 10));
        $this->year = $this->makeYear();
        // Негізгі дайындық завуч болып кіреді; кезекші тесттері кірмеген браузерден басталады.
        $this->app['auth']->forgetGuards();
        RateLimiter::clear('duty-login:127.0.0.1');
        RateLimiter::clear('duty-login-failures');
    }

    /** Завуч атынан код береді және ашық кодты қайтарады. */
    protected function issue(Shift $shift = Shift::Morning, ?string $date = null): string
    {
        [, $plain] = app(DutyCodeService::class)->issue(CarbonImmutable::parse($date ?? '2026-11-20'), $shift, $this->admin);

        return $plain;
    }

    /** Жаңа планшет (жаңа браузер) ретінде кодпен кіреді. */
    protected function dutyLogin(string $code): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
        $response = $this->postJson('/api/duty/login', ['code' => $code])->assertOk();
        $this->adoptSessionCookie($response);
    }

    /** @param  list<string>  $students  «Тегі Аты» */
    protected function classWith(string $name, array $students, Shift $shift = Shift::Morning): SchoolClass
    {
        $class = $this->makeClass($this->year, $name, shift: $shift);
        foreach ($students as $full) {
            [$last, $first] = explode(' ', $full);
            $this->enrol($class, $last, $first);
        }

        return $class;
    }

    protected function uuid(): string
    {
        return (string) Str::uuid();
    }
}
