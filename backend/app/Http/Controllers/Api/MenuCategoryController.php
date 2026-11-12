<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MenuCategoryController extends Controller
{
    public function index()
    {
        return response()->json(DB::table('DANH_MUC_MON')->orderBy('TenDM')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'TenDM' => ['required', 'string', 'max:120', 'unique:DANH_MUC_MON,TenDM'],
            'NoiCheBien' => ['required', Rule::in(['Bep', 'Bar'])],
            'MoTa' => ['nullable', 'string'],
            'TrangThai' => ['nullable', Rule::in(['Dang hoat dong', 'Ngung hoat dong'])],
        ]);
        $data['TrangThai'] = $data['TrangThai'] ?? 'Dang hoat dong';
        $id = DB::table('DANH_MUC_MON')->insertGetId($data, 'MaDM');
        return response()->json(DB::table('DANH_MUC_MON')->where('MaDM', $id)->first(), 201);
    }

    public function update(Request $request, int $id)
    {
        abort_unless(DB::table('DANH_MUC_MON')->where('MaDM', $id)->exists(), 404, 'Không tìm thấy danh mục.');
        $data = $request->validate([
            'TenDM' => ['required', 'string', 'max:120', Rule::unique('DANH_MUC_MON', 'TenDM')->ignore($id, 'MaDM')],
            'NoiCheBien' => ['required', Rule::in(['Bep', 'Bar'])],
            'MoTa' => ['nullable', 'string'],
            'TrangThai' => ['required', Rule::in(['Dang hoat dong', 'Ngung hoat dong'])],
        ]);
        DB::table('DANH_MUC_MON')->where('MaDM', $id)->update($data);
        return response()->json(DB::table('DANH_MUC_MON')->where('MaDM', $id)->first());
    }

    public function destroy(int $id)
    {
        $exists = DB::table('DANH_MUC_MON')->where('MaDM', $id)->exists();
        abort_unless($exists, 404, 'Không tìm thấy danh mục.');
        DB::table('DANH_MUC_MON')->where('MaDM', $id)->update(['TrangThai' => 'Ngung hoat dong']);
        return response()->json(['message' => 'Danh mục đã được ngừng hoạt động.']);
    }
}
