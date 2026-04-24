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

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class RulesController extends Controller
{
    /**
     * Display the rules page with manual pagination.
     */
    public function index(Request $request): View
    {
        $page = (int) $request->get('page', 1);
        $totalPages = 5;

        $paginatedRules = new LengthAwarePaginator(
            [], // Empty items array since content is rendered in Blade
            $totalPages,
            1,
            $page,
            ['path' => route('rules')]
        );

        return view('rules', compact('paginatedRules'));
    }
}
