<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes — servem a SPA React compilada
|--------------------------------------------------------------------------
|
| O front React é buildado e sua index.html é copiada para
| resources/views/spa.blade.php (veja o script de deploy). Qualquer rota que
| NÃO seja /api cai aqui e devolve a SPA — assim o roteamento do lado do
| cliente (React) funciona, inclusive em deep links / F5.
|
*/

Route::get('/', fn () => view('spa'));

Route::fallback(function (Request $request) {
    // Requisições de API que não casaram viram 404 JSON, não a SPA.
    if ($request->is('api/*')) {
        abort(404);
    }

    return view('spa');
});
