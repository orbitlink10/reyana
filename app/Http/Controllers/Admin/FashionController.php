<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FashionProduct;
use App\Services\FashionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FashionController extends Controller
{
    public function index(Request $request)
    {
        $collections = array_diff_key(FashionCatalog::COLLECTIONS, array_flip(['new-arrivals', 'sale']));
        $data = $request->validate(['collection' => ['nullable', Rule::in(array_keys($collections))]]);
        $collection = $data['collection'] ?? null;

        return view('admin.fashion', [
            'products' => FashionProduct::with('variants')->when($collection, fn ($query) => $query->where('collection', $collection))->latest()->get(),
            'collections' => $collections,
            'selectedCollection' => $collection,
        ]);
    }

    public function categories()
    {
        return view('admin.categories', [
            'collections' => array_diff_key(FashionCatalog::COLLECTIONS, array_flip(['new-arrivals', 'sale'])),
            'counts' => FashionProduct::selectRaw('collection, count(*) as total')->groupBy('collection')->pluck('total', 'collection'),
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate(['id' => 'nullable|exists:fashion_products,id', 'name' => 'required|string|max:160', 'slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:180', Rule::unique('fashion_products', 'slug')->ignore($request->id)], 'collection' => ['required', Rule::in(['dresses', 'tops', 'bottoms', 'matching-sets', 'outerwear'])], 'price' => 'required|integer|min:1|max:1000000', 'description' => 'required|string|max:5000', 'care' => 'nullable|string|max:3000', 'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096', 'active' => 'nullable|boolean', 'variants' => 'required|array|min:1|max:50', 'variants.*.size' => 'required|string|max:30', 'variants.*.color' => 'required|string|max:40', 'variants.*.stock' => 'required|integer|min:0|max:100000']);
        $pairs = collect($data['variants'])->map(fn ($v) => mb_strtolower($v['size'].'|'.$v['color']));
        if ($pairs->unique()->count() !== $pairs->count()) {
            return back()->withErrors(['variants' => 'Each size and colour combination must be unique.'])->withInput();
        }
        DB::transaction(function () use ($request, $data) {
            $product = isset($data['id']) ? FashionProduct::findOrFail($data['id']) : new FashionProduct;
            $fields = collect($data)->except(['id', 'variants', 'image'])->all();
            $fields['active'] = $request->boolean('active');
            if ($request->hasFile('image')) {
                $fields['image'] = '/fashion-images/'.basename($request->file('image')->store('reyana', 'public'));
            }
            $product->fill($fields)->save();
            foreach ($data['variants'] as $v) {
                $product->variants()->updateOrCreate(['size' => $v['size'], 'color' => $v['color']], ['stock' => $v['stock']]);
            }
        });

        return redirect('/admin/fashion')->with('success', 'Catalog saved. Preview mode continues to show only the separate demonstration dataset.');
    }
}
