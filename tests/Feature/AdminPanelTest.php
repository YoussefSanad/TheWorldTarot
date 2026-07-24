<?php

use App\Models\Admin;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets an authenticated admin reach the panel', function () {
    $admin = Admin::factory()->create();

    $this->actingAs($admin, 'admin')
        ->get('/admin')
        ->assertSuccessful();
});

it('redirects a guest away from the panel to the login screen', function () {
    $this->get('/admin')
        ->assertRedirect('/admin/login');
});

it('does not let a customer into the panel', function () {
    $customer = Customer::factory()->create();

    // Authenticated on the web/customer guard, but not on the admin guard.
    $this->actingAs($customer, 'web')
        ->get('/admin')
        ->assertRedirect('/admin/login');
});

it('creates an admin through the console command', function () {
    $this->artisan('app:create-admin', [
        '--name' => 'Jennifer',
        '--email' => 'jennifersemail6064@gmail.com',
        '--password' => 'a-strong-password-123',
    ])->assertSuccessful();

    $this->assertDatabaseHas('admins', ['email' => 'jennifersemail6064@gmail.com']);
});
