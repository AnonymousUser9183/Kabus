<?php

/*
 | *--------------------------------------------------------------------------
 | Copyright Notice
 |--------------------------------------------------------------------------
 | Updated for Laravel 13.4.0 by AnonymousUser9183 / The Erebus Development Team.
 | Original Kabus Marketplace Script created by Sukunetsiz.
 |--------------------------------------------------------------------------
 */

namespace App\Http\Controllers;

use Illuminate\View\View;

class GuidesController extends Controller
{
    public function index(): View
    {
        return view('guides.index');
    }

    public function keepassxc(): View
    {
        return view('guides.keepassxc-guide');
    }

    public function monero(): View
    {
        return view('guides.monero-guide');
    }

    public function tor(): View
    {
        return view('guides.tor-guide');
    }

    public function kleopatra(): View
    {
        return view('guides.kleopatra-guide');
    }
}
