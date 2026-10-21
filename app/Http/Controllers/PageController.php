<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PageController extends Controller
{
    public function terms(): View
    {
        return view('public.legal.terms');
    }

    public function privacy(): View
    {
        return view('public.legal.privacy');
    }

    public function contact(): View
    {
        return view('public.contact');
    }
}
