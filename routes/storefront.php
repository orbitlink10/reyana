<?php

use App\Http\Controllers\Admin\FashionController;
use App\Http\Controllers\StorefrontController as Store;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::middleware(['auth', 'role:admin,dispatcher'])->group(function () {
    Route::get('/admin/fashion', [FashionController::class, 'index'])->name('admin.products.index');
    Route::get('/admin/categories', [FashionController::class, 'categories'])->name('admin.categories.index');
    Route::post('/admin/fashion', [FashionController::class, 'save']);
});

Route::get('/', [Store::class, 'home'])->name('home');
Route::get('/shop', [Store::class, 'collection'])->name('shop');
Route::get('/search', [Store::class, 'collection']);
Route::get('/collections/{collection}', [Store::class, 'collection']);
Route::get('/products/{slug}', [Store::class, 'product']);
Route::get('/fashion-images/{filename}', function (string $filename) {
    abort_unless(preg_match('/^[A-Za-z0-9]+\.(jpg|jpeg|png|webp)$/', $filename), 404);
    $disk = Storage::disk('public');
    abort_unless($disk->exists('reyana/'.$filename), 404);

    return response()->file($disk->path('reyana/'.$filename), ['Cache-Control' => 'public, max-age=604800', 'X-Content-Type-Options' => 'nosniff']);
});
Route::get('/cart', [Store::class, 'cart']);
Route::post('/cart', [Store::class, 'add']);
Route::patch('/cart', [Store::class, 'update']);
Route::get('/checkout', [Store::class, 'checkout']);
Route::post('/checkout', [Store::class, 'previewOrder'])->middleware('throttle:10,1');
Route::get('/order-confirmation', [Store::class, 'confirmation']);
Route::match(['get', 'post'], '/wishlist', [Store::class, 'wishlist']);
Route::post('/newsletter', [Store::class, 'newsletter'])->middleware('throttle:5,1');
Route::get('/robots.txt', [Store::class, 'robots']);
Route::get('/sitemap.xml', [Store::class, 'sitemap']);
foreach (['about', 'contact', 'size-guide', 'delivery-shipping', 'returns-exchanges', 'faqs', 'privacy', 'terms', 'account', 'blog', 'blog/a-wardrobe-that-works-for-you'] as $page) {
    Route::get('/'.$page, fn () => app(Store::class)->page($page));
}
