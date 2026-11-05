@extends('layouts.dashboard')
@section('title', 'Categories')
@section('dashboard-title', 'Categories')
@section('dashboard-subtitle', 'Browse the shop collections and manage their products.')
@section('content')
<div class="card"><div class="card-body">
    <p>Assign products to these categories using the Collection field in the product editor.</p>
    <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Category</th><th>Products</th><th>Actions</th></tr></thead>
        <tbody>
        @foreach($collections as $slug => $label)
            <tr><td>{{ $label }}</td><td>{{ $counts[$slug] ?? 0 }}</td><td>
                <a class="btn btn-sm btn-primary" href="{{ route('admin.products.index', ['collection' => $slug]) }}">Manage products</a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ url('/collections/'.$slug) }}">View collection</a>
            </td></tr>
        @endforeach
        </tbody>
    </table></div>
</div></div>
@endsection
