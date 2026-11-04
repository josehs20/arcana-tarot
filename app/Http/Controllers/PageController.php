<?php

namespace App\Http\Controllers;

use App\Support\SiteContent;
use Illuminate\View\View;

class PageController extends Controller
{
    public function howItWorks(SiteContent $site): View
    {
        return view('public.how-it-works', [
            'site' => $site,
            'section' => $site->section('how_it_works'),
            'finalCta' => $site->section('final_cta'),
            'faqs' => array_slice($site->faqs(), 0, 3),
        ]);
    }

    public function faq(SiteContent $site): View
    {
        return view('public.faq', [
            'site' => $site,
            'section' => $site->section('faq'),
            'finalCta' => $site->section('final_cta'),
            'faqs' => $site->faqs(),
        ]);
    }

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
