<?php

namespace App\Controller;

class Index
{
    public function index(): string
    {
        return 'index';
    }

    public function test(\Magic\Http\Request $request)
    {
        return $request->get('page', '1');
    }
}
