<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    public function index()
    {
        return response()->json(DB::table('CO_SO')
            ->where('TrangThai', 'Dang hoat dong')
            ->orderBy('MaCS')
            ->get(['MaCS', 'TenCS', 'DiaChi', 'SDT']));
    }
}
