<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use App\Services\Import\TeacherImport;
use App\Support\KazakhSort;
use App\Support\OneTimePassword;
use App\Support\Translit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** docs/api.md, «Аккаунттар». */
class TeacherController extends Controller
{
    public function index(): JsonResponse
    {
        $users = KazakhSort::sort(User::query()->get(), static fn (User $u): array => [$u->full_name]);

        return response()->json(['users' => array_map($this->present(...), $users)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'last_name' => ['required', 'string', 'max:60'],
            'first_name' => ['required', 'string', 'max:60'],
            'patronymic' => ['sometimes', 'nullable', 'string', 'max:60'],
            'login' => ['sometimes', 'nullable', 'string', 'max:60'],
            'role' => ['sometimes', Rule::in([Role::Admin->value, Role::Teacher->value])],
        ]);
        $last = Student::cleanName($data['last_name']);
        $first = Student::cleanName($data['first_name']);
        if ($last === null || $first === null) {
            return response()->json(['code' => 'validation_failed', 'fields' => [$last === null ? 'last_name' : 'first_name']], 422);
        }
        $fullName = trim(implode(' ', array_filter([$last, $first, Student::cleanName($data['patronymic'] ?? null)])));

        $taken = User::query()->pluck('login')->flip()->all();
        $given = User::normalizeLogin((string) ($data['login'] ?? ''));
        if ($given !== '') {
            if (strlen($given) < 3 || preg_match(TeacherImport::LOGIN_PATTERN, $given) !== 1) {
                return $this->error('invalid_login');
            }
            if (isset($taken[$given])) {
                return $this->error('login_taken', 409);
            }
            $login = $given;
        } else {
            $login = TeacherImport::freeLogin(Translit::loginBase($last, $first), $taken);
            if ($login === null) {
                return $this->error('invalid_login');
            }
        }

        $password = OneTimePassword::generate();
        $user = User::query()->create([
            'full_name' => $fullName,
            'login' => $login,
            'password' => $password,
            'role' => $data['role'] ?? Role::Teacher->value,
            'must_change_password' => true,
        ]);

        return response()->json(['user' => $this->present($user), 'one_time_password' => $password], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', Rule::in([Role::Admin->value, Role::Teacher->value])],
        ]);

        // Әкімші өзін өшіріп не рөлін төмендетіп, платформаны әкімшісіз қалдыра алмайды.
        $self = $user->is($request->user());
        if ($self && (($data['is_active'] ?? true) === false || ($data['role'] ?? Role::Admin->value) !== Role::Admin->value)) {
            return $this->error('cannot_change_self');
        }
        if (array_key_exists('full_name', $data)) {
            $name = Student::cleanName($data['full_name']);
            if ($name === null) {
                return response()->json(['code' => 'validation_failed', 'fields' => ['full_name']], 422);
            }
            $data['full_name'] = $name;
        }

        $user->forceFill($data)->save();
        if (($data['is_active'] ?? true) === false) {
            $this->closeSessions($user);
        }

        return response()->json(['user' => $this->present($user)]);
    }

    public function resetPassword(User $user): JsonResponse
    {
        $password = OneTimePassword::generate();
        $user->forceFill(['password' => $password, 'must_change_password' => true])->save();
        $this->closeSessions($user);

        return response()->json(['user' => $this->present($user), 'one_time_password' => $password]);
    }

    private function closeSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'login' => $user->login,
            'role' => $user->role->value,
            'is_active' => $user->is_active,
            'must_change_password' => $user->must_change_password,
            'homeroom_classes' => $user->homeroomClassNames(),
        ];
    }
}
