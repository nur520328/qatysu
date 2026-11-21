<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Бұл қосымша тек API береді (routes/api.php). Интерфейс бөлек жиналып, орналастыру
// кезінде public/ қалтасына қойылады. `/api`-дан басқа кез келген мекенжай сол интерфейстің
// index.html файлын қайтарады: беттер арасындағы өту браузер ішінде шешіледі.
Route::fallback(function (Request $request) {
    // API-дің жоқ жолы интерфейсті емес, 404 қайтаруы тиіс.
    abort_if($request->is('api', 'api/*'), 404);

    $index = public_path('index.html');

    abort_unless(is_file($index), 503, 'Интерфейс файлдары орнатылмаған.');

    return response()->file($index, [
        'Content-Type' => 'text/html; charset=UTF-8',
        // index.html кэштелмейді, әйтпесе жаңартудан кейін ескі интерфейс ашылады.
        // Оның ішіндегі файл атауларында мазмұн хэші бар, олар ұзақ кэштеледі.
        'Cache-Control' => 'no-cache',
    ]);
});
