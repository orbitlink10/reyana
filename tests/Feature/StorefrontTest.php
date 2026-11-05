<?php

namespace Tests\Feature;

use App\Models\FashionProduct;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['reyana.preview' => true, 'reyana.indexable' => false]);
    }

    public function test_public_templates_render_and_preview_is_not_indexable(): void
    {
        foreach (['/', '/shop', '/search', '/collections/dresses', '/collections/tops', '/collections/bottoms', '/collections/matching-sets', '/collections/outerwear', '/collections/sale', '/products/the-everyday-edit', '/cart', '/checkout', '/wishlist', '/about', '/contact', '/size-guide', '/delivery-shipping', '/returns-exchanges', '/faqs', '/privacy', '/terms', '/account', '/blog', '/blog/a-wardrobe-that-works-for-you', '/login', '/register', '/forgot-password'] as $path) {
            $this->get($path)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow')->assertSee('REYANA')->assertDontSee('Book a Service');
        }
        $this->get('/robots.txt')->assertSee('Disallow: /');
        $this->get('/sitemap.xml')->assertDontSee('<url>', false);
        $this->get('/products/not-a-product')->assertNotFound()->assertSee('Let’s find your way.');
        $this->get('/old-cleaning-service')->assertNotFound();
        $this->get('/collections/not-a-collection')->assertNotFound();
    }

    public function test_filters_sort_and_crawlable_pagination(): void
    {
        $this->get('/shop?size=L&available=1')->assertOk()->assertSee('No pieces match');
        $this->get('/shop?min=6000')->assertSee('The coordinated look')->assertDontSee('The everyday edit');
        $this->get('/shop?sort=price-asc')->assertSeeInOrder(['The city essential', 'The daily rotation']);
        $this->get('/shop?page=2')->assertSee('The daily rotation')->assertSee('?page=2', false);
        $this->get('/shop?min=-1')->assertRedirect();
    }

    public function test_cart_requires_valid_variant_and_uses_server_prices(): void
    {
        $this->post('/cart', ['slug' => 'the-everyday-edit', 'quantity' => 1])->assertSessionHasErrors('variant');
        $this->post('/cart', ['slug' => 'the-everyday-edit', 'variant' => 'l', 'quantity' => 1])->assertSessionHasErrors('variant');
        $this->post('/cart', ['slug' => 'the-everyday-edit', 'variant' => 's', 'quantity' => 2, 'price' => 1])->assertRedirect('/cart');
        $this->get('/cart')->assertSee('KSh 8,400')->assertSee('S / Reference colour');
        $this->post('/cart', ['slug' => 'the-everyday-edit', 'variant' => 's', 'quantity' => 2])->assertSessionHasErrors('variant');
        $this->patch('/cart', ['key' => 'the-everyday-edit-s', 'quantity' => 3])->assertRedirect('/cart');
        $this->get('/cart')->assertSee('KSh 12,600');
        $this->patch('/cart', ['key' => 'the-everyday-edit-s', 'quantity' => 0])->assertRedirect('/cart');
        $this->get('/cart')->assertSee('Your bag is empty');
    }

    public function test_preview_checkout_normalizes_phone_and_retries_without_real_records(): void
    {
        $this->post('/cart', ['slug' => 'the-everyday-edit', 'variant' => 's', 'quantity' => 1]);
        $this->get('/checkout')->assertOk();
        $token = session('reyana.checkout_token');
        $data = ['name' => 'Demo Shopper', 'email' => 'demo@example.test', 'phone' => '+254 712 345678', 'county' => 'Nairobi', 'town' => 'Demo town', 'address' => 'Demo address', 'token' => $token];
        $this->post('/checkout', $data)->assertRedirect('/order-confirmation')->assertSessionHas('reyana.confirmation.phone', '254712345678');
        $reference = session('reyana.confirmation.reference');
        $this->post('/checkout', $data)->assertRedirect('/order-confirmation')->assertSessionHas('reyana.confirmation.reference', $reference);
        $this->get('/order-confirmation')->assertSee('no real order was placed');
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('fashion_products', 0);
    }

    public function test_checkout_rejects_invalid_phone_and_disabled_real_payments(): void
    {
        $this->post('/checkout', ['phone' => '123'])->assertSessionHasErrors('phone');
        config(['reyana.preview' => false]);
        $this->post('/checkout', [])->assertStatus(503);
    }

    public function test_wishlist_and_newsletter_consent(): void
    {
        $this->post('/wishlist', ['slug' => 'the-everyday-edit'])->assertSessionHas('reyana.wishlist', ['the-everyday-edit']);
        $this->get('/wishlist')->assertSee('The everyday edit');
        $this->post('/newsletter', ['email' => 'demo@example.test'])->assertSessionHasErrors('consent');
        $this->post('/newsletter', ['email' => 'demo@example.test', 'consent' => 1])->assertSessionHas('success');
        $this->assertDatabaseCount('fashion_subscribers', 0);
        config(['reyana.preview' => false]);
        $this->post('/newsletter', ['email' => 'demo@example.test', 'consent' => 1]);
        $this->post('/newsletter', ['email' => 'demo@example.test', 'consent' => 1]);
        $this->assertDatabaseCount('fashion_subscribers', 1);
    }

    public function test_admin_authentication_and_existing_dashboard_are_preserved(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/admin/fashion')->assertRedirect('/login');
        $this->get('/admin/categories')->assertRedirect('/login');
        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/admin/fashion')->assertForbidden();
        $this->get('/admin/categories')->assertForbidden();
        $role = Role::create(['name' => 'admin', 'label' => 'Admin']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('dashboard-sidebar')->assertDontSee('reyana/store.css');
        $this->get('/admin/fashion')->assertOk()->assertSee('dashboard-sidebar');
        $this->get('/admin/categories')->assertOk()->assertSee('Dresses')->assertSee('/admin/fashion?collection=dresses', false);
        $this->get('/admin/fashion?collection=dresses')->assertOk()->assertSee('Showing Dresses.');
        $this->get('/admin/services')->assertOk();
        $this->get('/admin/providers')->assertOk();
        $this->get('/bookings')->assertOk();
    }

    public function test_live_catalog_is_separate_and_exposes_matching_product_schema(): void
    {
        $product = FashionProduct::create(['name' => 'Real Dress', 'slug' => 'real-dress', 'collection' => 'dresses', 'price' => 5000, 'active' => true, 'description' => 'Verified description']);
        $product->variants()->create(['size' => 'M', 'color' => 'Blue', 'stock' => 2]);
        $this->get('/shop')->assertDontSee('Real Dress');
        config(['reyana.preview' => false, 'reyana.indexable' => true, 'app.env' => 'production']);
        $this->app->instance('env', 'production');
        $this->get('/shop')->assertSee('Real Dress')->assertDontSee('The everyday edit');
        $this->get('/products/real-dress')->assertSee('priceCurrency', false)->assertSee('KES')->assertSee('InStock');
        $this->get('/sitemap.xml')->assertSee('/products/real-dress');
        $this->get('/cart')->assertHeader('X-Robots-Tag', 'noindex, follow');
    }

    public function test_catalog_admin_save_preserves_variant_identity_and_permissions(): void
    {
        $data = ['name' => 'Verified top', 'slug' => 'verified-top', 'collection' => 'tops', 'price' => 2500, 'description' => 'Verified product details', 'active' => 1, 'variants' => [['size' => 'M', 'color' => 'Blue', 'stock' => 4]]];
        $customer = User::factory()->create();
        $this->actingAs($customer)->post('/admin/fashion', $data)->assertForbidden();
        $role = Role::create(['name' => 'admin', 'label' => 'Admin']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($admin)->post('/admin/fashion', $data)->assertRedirect('/admin/fashion');
        $product = FashionProduct::firstOrFail();
        $variantId = $product->variants->first()->id;
        $data['id'] = $product->id;
        $data['variants'][0]['stock'] = 0;
        $this->post('/admin/fashion', $data)->assertRedirect('/admin/fashion');
        $this->assertDatabaseHas('fashion_variants', ['id' => $variantId, 'stock' => 0]);
        $this->assertDatabaseCount('fashion_variants', 1);
        $this->assertDatabaseCount('bookings', 0);
        $this->get('/pages')->assertOk();
        $this->get('/shop?page=999')->assertNotFound();
    }
}
