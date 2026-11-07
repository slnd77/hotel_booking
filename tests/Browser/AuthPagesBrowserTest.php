<?php

use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'user']);
    config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
});

test('the log-in page offers Google sign-in and the way to create an account', function () {
    visit(route('login'))
        ->assertSee('Log in to your account')
        ->assertSee('Continue with Google')
        ->assertSee('Create an account')
        ->assertNoJavaScriptErrors();
});

test('the create-account page offers sign-up with Google', function () {
    visit(route('register'))
        ->assertSee('Create your account')
        ->assertSee('Sign up with Google')
        ->assertNoJavaScriptErrors();
});

test('the forgot-password page asks for the email', function () {
    visit(route('password.request'))
        ->assertSee('send you a link to choose a new password')
        ->assertNoJavaScriptErrors();
});

test('a new account is created from the create-account page', function () {
    visit(route('register'))
        ->type('#name', 'Asha Rao')
        ->type('#email', 'asha@example.com')
        ->type('#password', 'Str0ng-Passw0rd!')
        ->type('#password_confirmation', 'Str0ng-Passw0rd!')
        ->press('Create account')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});
