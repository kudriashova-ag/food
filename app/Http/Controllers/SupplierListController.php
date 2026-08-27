<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\View\View;

class SupplierListController extends Controller
{
    public function __invoke(): View
    {
        return view('home', [
            'suppliers' => Supplier::query()
                ->visible()
                ->orderBy('sort')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
