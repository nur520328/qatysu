<?php

namespace App\Http\Controllers;

use App\Services\AuthorPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Платформа туралы» беті: нұсқа және автордың суреті. Бет пен сурет кірмеген адамға да ашық
 * (автордың өз шешімі, 2026-11-23); суретті тек завуч ауыстырады. Мәтін сайттың аудармаларында.
 */
class AboutController extends Controller
{
    public function show(AuthorPhoto $photo): JsonResponse
    {
        return response()->json($this->state($photo));
    }

    public function photo(AuthorPhoto $photo): Response
    {
        if (! $photo->exists()) {
            return $this->error('not_found', 404);
        }

        // Мекенжайда нұсқа белгісі бар (`?v=`), сондықтан ұзақ кэштеуге болады.
        return response()->file($photo->path(), ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=604800']);
    }

    public function storePhoto(Request $request, AuthorPhoto $photo): JsonResponse
    {
        $file = $request->file('photo');
        if ($file === null) {
            return $this->error('validation_failed');
        }
        $code = $photo->store($file);

        return $code === null ? response()->json($this->state($photo)) : $this->error($code);
    }

    public function destroyPhoto(AuthorPhoto $photo): JsonResponse
    {
        $photo->delete();

        return response()->json($this->state($photo));
    }

    /** @return array{version: string, photo: ?string} */
    private function state(AuthorPhoto $photo): array
    {
        $v = $photo->version();

        return ['version' => (string) config('app.version'), 'photo' => $v === null ? null : '/api/about/photo?v='.$v];
    }
}
