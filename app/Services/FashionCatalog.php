<?php

namespace App\Services;

use App\Models\FashionProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class FashionCatalog
{
    public const COLLECTIONS = ['new-arrivals' => 'New Arrivals', 'dresses' => 'Dresses', 'tops' => 'Tops', 'bottoms' => 'Bottoms', 'matching-sets' => 'Matching Sets', 'outerwear' => 'Outerwear', 'sale' => 'Sale'];

    public static function categories(): array
    {
        return Schema::hasTable('fashion_categories')
            ? \App\Models\FashionCategory::orderBy('name')->pluck('name', 'slug')->all()
            : array_diff_key(self::COLLECTIONS, array_flip(['new-arrivals', 'sale']));
    }

    public static function collections(): array
    {
        return ['new-arrivals' => 'New Arrivals'] + self::categories() + ['sale' => 'Sale'];
    }

    public function products(): Collection
    {
        if (config('reyana.preview')) {
            return collect(require base_path('resources/demo/catalog.php'));
        }
        if (! Schema::hasTable('fashion_products')) {
            return collect();
        }

        return FashionProduct::with(['variants', 'subcategory'])->where('active', true)->latest()->get()->map(function ($p) {
            return ['id' => (string) $p->id, 'slug' => $p->slug, 'name' => $p->name, 'collection' => $p->collection,
                'price' => $p->price, 'marked_price' => $p->marked_price, 'subcategory' => $p->subcategory?->slug, 'meta_description' => $p->meta_description, 'description_html' => $p->description_html, 'description' => $p->description, 'care' => $p->care,
                'image' => $p->image ?: '/reyana/placeholder.svg', 'alt' => $p->name,
                'demo' => false, 'variants' => $p->variants->map(fn ($v) => ['id' => (string) $v->id, 'size' => $v->size, 'color' => $v->color, 'stock' => $v->stock])->all()];
        });
    }

    public function product(string $slug): array
    {
        return $this->products()->firstWhere('slug', $slug) ?? abort(404);
    }

    public function cart(): array
    {
        $lines = [];
        foreach (session('reyana.cart', []) as $key => $line) {
            $p = $this->products()->firstWhere('slug', $line['slug']);
            $v = $p ? collect($p['variants'])->firstWhere('id', $line['variant']) : null;
            if (! $v) {
                continue;
            }
            $lines[$key] = ['product' => $p, 'variant' => $v, 'quantity' => $line['quantity'], 'total' => $p['price'] * $line['quantity'], 'valid' => $v['stock'] >= $line['quantity']];
        }

        return $lines;
    }
}
