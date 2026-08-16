<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use App\Http\Controllers\PublicReceiptController;

// Public Web Receipt View for Donors (Shared via WhatsApp / Web Link)
Route::get('/receipt/{id}', [PublicReceiptController::class, 'show'])->name('public.receipt')->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);
Route::get('/receipts/{id}', [PublicReceiptController::class, 'show'])->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);
Route::get('/r/{id}', [PublicReceiptController::class, 'show'])->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);

// Direct App APK Download Routes
Route::get('/download', function () {
    $apkPath = public_path('mandalhisab.apk');
    if (file_exists($apkPath)) {
        return response()->download($apkPath, 'MandalHishob.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
    return response()->json(['error' => 'APK file not found'], 404);
})->name('app.download')->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);

Route::get('/download/apk', function () {
    return redirect('/download');
})->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);

Route::get('/download-app', function () {
    return redirect('/download');
})->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);

Route::get('/', function () {
    $path = public_path('index.html');
    if (file_exists($path)) {
        return response()->file($path, [
            'Content-Type' => 'text/html; charset=utf-8'
        ]);
    }
    return redirect('/doc');
})->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);

// Legal & Compliance Pages (Google Play & Apple App Store Requirements)
Route::get('/privacy', function () {
    return response()->file(public_path('index.html'), ['Content-Type' => 'text/html; charset=utf-8']);
})->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);

Route::get('/privacy-policy', function () {
    return response()->file(public_path('index.html'), ['Content-Type' => 'text/html; charset=utf-8']);
})->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);

Route::get('/terms', function () {
    return response()->file(public_path('index.html'), ['Content-Type' => 'text/html; charset=utf-8']);
})->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);

Route::get('/terms-and-conditions', function () {
    return response()->file(public_path('index.html'), ['Content-Type' => 'text/html; charset=utf-8']);
})->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);

Route::get('/delete-account', function () {
    return response()->file(public_path('index.html'), ['Content-Type' => 'text/html; charset=utf-8']);
})->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);

Route::get('/data-deletion', function () {
    return response()->file(public_path('index.html'), ['Content-Type' => 'text/html; charset=utf-8']);
})->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);

// Interactive API Documentation (Scalar & Swagger UI)
Route::get('/doc', function () {
    return view('docs.index');
})->name('docs.index')->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);

Route::get('/docs', function () {
    return view('docs.index');
})->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);

// Raw OpenAPI Specifications
Route::get('/openapi.json', function () {
    $path = public_path('openapi.json');
    if (file_exists($path)) {
        return response(file_get_contents($path), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*'
        ]);
    }
    return response()->json(['error' => 'Specification not found'], 404);
})->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);

Route::get('/openapi.yaml', function () {
    $path = public_path('openapi.yaml');
    if (file_exists($path)) {
        return response(file_get_contents($path), 200, [
            'Content-Type' => 'text/yaml; charset=utf-8',
            'Access-Control-Allow-Origin' => '*'
        ]);
    }
    return response()->json(['error' => 'Specification not found'], 404);
})->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);


