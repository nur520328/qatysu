<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** docs/api.md, «Баптаулар». */
class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['settings' => Setting::allInts()]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [];
        foreach (Setting::DEFAULTS as $key => $spec) {
            $rules[$key] = ['sometimes', 'integer', "min:{$spec['min']}", "max:{$spec['max']}"];
        }
        $data = $request->validate($rules);

        foreach ($data as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }

        return response()->json(['settings' => Setting::allInts()]);
    }
}
