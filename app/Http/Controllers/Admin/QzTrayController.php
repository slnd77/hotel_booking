<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\QzTraySigning;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * QZ Tray's certificate and signing calls from the POS page. Only signed-in staff who may post
 * POS transactions reach them (admin.pos.*), so only this site's POS can print without the
 * "allow this website" prompt. Empty answers mean signing isn't set up: QZ Tray then asks.
 */
class QzTrayController extends Controller
{
    public function certificate(QzTraySigning $signing): Response
    {
        return response($signing->certificate() ?? '', 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function sign(Request $request, QzTraySigning $signing): Response
    {
        $data = $request->validate(['request' => ['required', 'string', 'max:65535']]);

        return response($signing->sign($data['request']) ?? '', 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }
}
