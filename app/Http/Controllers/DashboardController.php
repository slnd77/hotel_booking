<?php

namespace App\Http\Controllers;

use App\Services\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Where login lands (Fortify "home"): admins go straight on to the admin panel.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->user() !== null && PermissionCatalog::canEnterPanel($request->user())) {
            return redirect()->route('admin.dashboard');
        }

        return Inertia::render('Dashboard');
    }
}
