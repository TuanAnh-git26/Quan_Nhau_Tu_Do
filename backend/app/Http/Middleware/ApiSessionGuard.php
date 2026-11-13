<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ApiSessionGuard
{
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $token = $request->bearerToken();
        $stored = $token ? Cache::store('file')->get('qntd_token_'.hash('sha256', $token)) : null;
        if (!$stored || !isset($stored['id'], $stored['hash'])) {
            return response()->json(['message' => 'Bạn chưa đăng nhập hoặc phiên đã hết hạn.'], 401);
        }
        $account = DB::table('TAI_KHOAN as tk')
            ->join('VAI_TRO as vt', 'vt.MaVaiTro', '=', 'tk.MaVaiTro')
            ->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 'tk.MaNV')
            ->where('tk.MaTK', $stored['id'])
            ->first(['tk.*', 'vt.TenVaiTro', 'nv.MaCS', 'nv.HoTen', 'nv.TrangThai as TrangThaiNV']);
        if (!$account || $account->TrangThai !== 'Hoat dong'
            || ($account->MaNV && $account->TrangThaiNV !== 'Dang lam')
            || !hash_equals($stored['hash'], hash('sha256', $account->MatKhauHash))) {
            Cache::store('file')->forget('qntd_token_'.hash('sha256', $token));
            return response()->json(['message' => 'Tài khoản hoặc phiên đăng nhập không hợp lệ.'], 401);
        }
        $request->attributes->set('account', $account);
        $admin = $account->TenVaiTro === 'Quan tri he thong';
        // Mặc định áp dụng quyền tối thiểu tương ứng với mỗi phân hệ cũ.
        if (!$permission) {
            $path = preg_replace('~^api/~', '', $request->path());
            $root = explode('/', $path)[0];
            $permission = match ($root) {
                'branches' => 'UC1.1', 'categories' => 'UC1.2', 'menu-items' => 'UC1.3',
                'areas', 'tables', 'available-tables' => 'UC2.1',
                'reservations', 'customers' => 'UC2.2', 'orders' => 'UC2.4',
                'bep-bar', 'service-notifications' => 'UC3.2',
                'ingredients', 'suppliers', 'inventory' => 'UC4.1',
                'cashier' => 'UC5.4', 'employees' => 'UC6.1',
                'hr' => str_starts_with($path, 'hr/payrolls') ? 'UC6.4'
                    : (str_starts_with($path, 'hr/attendance') || str_starts_with($path, 'hr/shifts') ? 'UC6.2' : 'UC6.1'),
                default => null,
            };
        }
        if ($permission && !$admin && !DB::table('PHAN_QUYEN')
            ->where('MaVaiTro', $account->MaVaiTro)->where('MaUC', $permission)->exists()) {
            return response()->json(['message' => 'Bạn không có quyền thực hiện nghiệp vụ này.'], 403);
        }
        return $next($request);
    }
}
