<?php

namespace App\Http\Controllers;

use App\Services\FashionCatalog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StorefrontController extends Controller
{
    public function __construct(private FashionCatalog $catalog) {}

    private function render(string $view, array $data = [])
    {
        $data['description'] ??= match ($view) {
            'home' => 'Discover Reyana, a women’s clothing storefront for Kenya. Explore dresses, tops and a considered wardrobe edit.',
            'collection' => 'Explore '.str_replace(' | Reyana', '', $data['title']).' at Reyana. Refine the clothing edit by available size, colour and price.',
            'product' => mb_substr($data['product']['name'].'. '.$data['product']['description'], 0, 155),
            'page' => mb_substr($data['content']['intro'].' '.$data['content']['text'], 0, 155),
            default => str_replace(' | Reyana', '', $data['title'] ?? 'Your shopping details').' at Reyana.',
        };

        return response()->view('storefront.'.$view, $data + ['collections' => FashionCatalog::COLLECTIONS])
            ->header('X-Robots-Tag', config('reyana.preview') || ! config('reyana.indexable') ? 'noindex, nofollow' : 'index, follow');
    }

    public function home()
    {
        return $this->render('home', ['products' => $this->catalog->products()->take(4), 'picks' => $this->catalog->products()->slice(4, 4), 'title' => 'Ladies’ Clothes in Kenya | Shop Women’s Fashion | Reyana']);
    }

    public function collection(Request $request, ?string $collection = null)
    {
        abort_if($collection && ! isset(FashionCatalog::COLLECTIONS[$collection]), 404);
        $request->validate(['q' => 'nullable|string|max:120', 'size' => 'nullable|string|max:30', 'color' => 'nullable|string|max:40', 'min' => 'nullable|integer|min:0', 'max' => 'nullable|integer|min:0', 'sort' => 'nullable|in:newest,price-asc,price-desc,name', 'page' => 'nullable|integer|min:1|max:10000']);
        $all = $this->catalog->products()->filter(fn ($p) => ! $collection || $collection === 'new-arrivals' || $p['collection'] === $collection);
        $options = $all->flatMap(fn ($p) => $p['variants']);
        $filtered = $all->filter(function ($p) use ($request) {
            if ($request->filled('q') && ! str_contains(mb_strtolower($p['name']), mb_strtolower($request->q))) {
                return false;
            }
            if ($request->filled('min') && $p['price'] < (int) $request->min) {
                return false;
            }
            if ($request->filled('max') && $p['price'] > (int) $request->max) {
                return false;
            }

            return collect($p['variants'])->contains(fn ($v) => (! $request->filled('size') || $v['size'] === $request->size) && (! $request->filled('color') || $v['color'] === $request->color) && (! $request->boolean('available') || $v['stock'] > 0));
        });
        $filtered = match ($request->get('sort')) {
            'price-asc' => $filtered->sortBy('price'), 'price-desc' => $filtered->sortByDesc('price'), 'name' => $filtered->sortBy('name'), default => $filtered
        };
        $products = new LengthAwarePaginator($filtered->values()->forPage($request->integer('page', 1), 8), $filtered->count(), 8, $request->integer('page', 1), ['path' => $request->url(), 'query' => $request->query()]);
        abort_if($products->currentPage() > $products->lastPage(), 404);

        return $this->render('collection', ['products' => $products, 'collection' => $collection, 'sizes' => $options->pluck('size')->unique(), 'colors' => $options->pluck('color')->unique(), 'title' => ($collection ? FashionCatalog::COLLECTIONS[$collection] : ($request->filled('q') ? 'Search results' : 'Shop all')).' | Reyana']);
    }

    public function product(string $slug)
    {
        $p = $this->catalog->product($slug);

        return $this->render('product', ['product' => $p, 'related' => $this->catalog->products()->where('collection', $p['collection'])->where('slug', '!=', $slug)->take(4), 'title' => $p['name'].' | Reyana']);
    }

    public function cart()
    {
        $lines = $this->catalog->cart();

        return $this->render('cart', ['lines' => $lines, 'subtotal' => array_sum(array_column($lines, 'total')), 'title' => 'Your bag | Reyana']);
    }

    public function add(Request $request)
    {
        $data = $request->validate(['slug' => 'required|string', 'variant' => 'required|string', 'quantity' => 'required|integer|min:1|max:20']);
        $p = $this->catalog->product($data['slug']);
        $v = collect($p['variants'])->firstWhere('id', $data['variant']);
        $key = $p['slug'].'-'.$data['variant'];
        $cart = session('reyana.cart', []);
        $quantity = ($cart[$key]['quantity'] ?? 0) + $data['quantity'];
        if (! $v || $quantity > $v['stock']) {
            return back()->withErrors(['variant' => 'Choose an available size and colour. The requested quantity must be in stock.']);
        }
        $cart[$key] = ['slug' => $p['slug'], 'variant' => $v['id'], 'quantity' => $quantity];
        session(['reyana.cart' => $cart]);

        return redirect('/cart')->with('success', 'Added to your bag.');
    }

    public function update(Request $request)
    {
        $data = $request->validate(['key' => 'required|string', 'quantity' => 'required|integer|min:0|max:20']);
        $cart = session('reyana.cart', []);
        $lines = $this->catalog->cart();
        if ($data['quantity'] === 0) {
            unset($cart[$data['key']]);
        } elseif (isset($lines[$data['key']]) && $data['quantity'] <= $lines[$data['key']]['variant']['stock']) {
            $cart[$data['key']]['quantity'] = $data['quantity'];
        } else {
            return back()->withErrors(['quantity' => 'That quantity is not available.']);
        }
        session(['reyana.cart' => $cart]);

        return redirect('/cart');
    }

    public function checkout()
    {
        $lines = $this->catalog->cart();

        return $this->render('checkout', ['lines' => $lines, 'subtotal' => array_sum(array_column($lines, 'total')), 'title' => 'Checkout | Reyana']);
    }

    public function previewOrder(Request $request)
    {
        abort_unless(config('reyana.preview'), 503, 'Checkout is not configured. No payment has been taken.');
        $phone = preg_replace('/\D/', '', (string) $request->input('phone'));
        if (preg_match('/^0[17]\d{8}$/', $phone)) {
            $phone = '254'.substr($phone, 1);
        } elseif (preg_match('/^[17]\d{8}$/', $phone)) {
            $phone = '254'.$phone;
        }
        $request->merge(['phone' => $phone]);
        $data = $request->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:255', 'phone' => ['required', 'regex:/^254[17]\d{8}$/'], 'county' => 'required|string|max:100', 'town' => 'required|string|max:100', 'address' => 'required|string|max:255', 'landmark' => 'nullable|string|max:255', 'token' => 'required|string']);
        if (session('reyana.confirmed_token') === $data['token']) {
            return redirect('/order-confirmation');
        }
        abort_unless(hash_equals((string) session('reyana.checkout_token'), $data['token']), 419);
        $lines = $this->catalog->cart();
        if (! $lines || collect($lines)->contains(fn ($l) => ! $l['valid'])) {
            return redirect('/cart')->withErrors(['stock' => 'Review your bag before continuing.']);
        }
        session(['reyana.confirmation' => ['reference' => 'PREVIEW-'.strtoupper(substr(hash('sha256', $data['token']), 0, 8)), 'subtotal' => array_sum(array_column($lines, 'total')), 'phone' => $phone], 'reyana.confirmed_token' => $data['token'], 'reyana.cart' => [], 'reyana.checkout_token' => null]);

        return redirect('/order-confirmation');
    }

    public function confirmation()
    {
        abort_unless(session()->has('reyana.confirmation'), 404);

        return $this->render('confirmation', ['title' => 'Preview confirmation | Reyana']);
    }

    public function wishlist(Request $request)
    {
        if ($request->isMethod('post')) {
            $slug = $request->validate(['slug' => 'required|string'])['slug'];
            $this->catalog->product($slug);
            $list = session('reyana.wishlist', []);
            $list = in_array($slug, $list) ? array_values(array_diff($list, [$slug])) : [...$list, $slug];
            session(['reyana.wishlist' => $list]);

            return back()->with('success', 'Wishlist updated.');
        }

        return $this->render('wishlist', ['products' => $this->catalog->products()->whereIn('slug', session('reyana.wishlist', [])), 'title' => 'Your wishlist | Reyana']);
    }

    public function newsletter(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'consent' => 'accepted']);
        if (config('reyana.preview')) {
            return back()->with('success', 'Preview signup tested. Your email has not been subscribed to marketing.');
        }
        abort_unless(Schema::hasTable('fashion_subscribers'), 503);
        DB::table('fashion_subscribers')->insertOrIgnore(['email' => mb_strtolower($data['email']), 'consented_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Your signup has been saved.');
    }

    public function page(string $page)
    {
        $pages = require base_path('resources/demo/pages.php');
        abort_unless(isset($pages[$page]), 404);

        return $this->render('page', ['page' => $page, 'content' => $pages[$page], 'title' => $pages[$page]['title'].' | Reyana']);
    }

    public function robots()
    {
        return response(config('reyana.preview') || ! config('reyana.indexable') || ! app()->environment('production') ? "User-agent: *\nDisallow: /\n" : "User-agent: *\nDisallow: /admin\nDisallow: /cart\nDisallow: /checkout\nDisallow: /account\nDisallow: /*?\nSitemap: ".rtrim(config('app.url'), '/')."/sitemap.xml\n", 200, ['Content-Type' => 'text/plain']);
    }

    public function sitemap()
    {
        $paths = [];
        if (! config('reyana.preview') && config('reyana.indexable') && app()->environment('production')) {
            $paths = ['/', '/shop', '/about', '/blog', '/blog/a-wardrobe-that-works-for-you'];
            if ($this->catalog->products()->isNotEmpty()) {
                $paths[] = '/collections/new-arrivals';
            }
            foreach ($this->catalog->products()->pluck('collection')->unique() as $c) {
                $paths[] = '/collections/'.$c;
            }
            foreach ($this->catalog->products() as $p) {
                $paths[] = '/products/'.$p['slug'];
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($paths as $p) {
            $xml .= '<url><loc>'.htmlspecialchars(rtrim(config('app.url'),'/').$p, ENT_XML1).'</loc></url>';
        }

        return response($xml.'</urlset>',200,['Content-Type' => 'application/xml']);
    }
}
