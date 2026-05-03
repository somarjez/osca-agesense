<?php

namespace App\Http\Controllers;

class HelpController extends Controller
{
    public function __invoke()
    {
        return view('help.index');
    }
}
