<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Chặn đổi MaCS trong query/body và phần lớn đường dẫn tài nguyên chính. */
class EnsureBranchScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401, 'Bạn cần đăng nhập.');
        if ($request->session()->get('qntd_password_fingerprint') !== hash('sha256', $user->MatKhauHash)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            abort(401, 'Phiên đăng nhập không còn hợp lệ, vui lòng đăng nhập lại.');
        }

        $branch = (int) $request->session()->get('qntd_branch_id', 0);
        abort_unless($branch > 0, 403, 'Vui lòng chọn cơ sở hợp lệ khi đăng nhập.');

        if ($user->TenVaiTro === 'Quan tri he thong') {
            // Admin đã đăng nhập được phép quản lý nhiều cơ sở.
            return $next($request);
        }

        abort_unless((int) $user->MaCS === $branch, 403, 'Cơ sở không thuộc nhân viên.');
        if ($request->exists('MaCS')) {
            abort_unless((int) $request->input('MaCS') === $branch, 403, 'Bạn không có quyền truy cập cơ sở này.');
        }

        $path = $request->path();
        if ($request->isMethod('GET') && preg_match(
            '~^api/(?:hr/(?:dashboard|employees|contracts|shifts|attendance|payrolls)|orders|cashier/(?:orders|invoices))$~',
            $path
        )) {
            abort_unless((int) $request->input('MaCS') === $branch, 403, 'Phải lọc dữ liệu theo cơ sở của tài khoản.');
        }

        if ($request->filled('MaNV')) {
            abort_unless((int) DB::table('NHAN_VIEN')->where('MaNV', $request->input('MaNV'))->value('MaCS') === $branch, 403, 'Nhân viên không thuộc cơ sở.');
        }
        if ($request->filled('MaBan')) {
            $tableBranch = DB::table('BAN as b')->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')->where('b.MaBan', $request->input('MaBan'))->value('kv.MaCS');
            abort_unless((int) $tableBranch === $branch, 403, 'Bàn không thuộc cơ sở.');
        }
        if ($request->filled('MaCa')) {
            abort_unless((int) DB::table('CA_LAM')->where('MaCa', $request->input('MaCa'))->value('MaCS') === $branch, 403, 'Ca không thuộc cơ sở.');
        }

        $id = null;
        $table = null;
        $join = null;

        if (preg_match('~^api/orders/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $table = 'PHIEU_ORDER';
        } elseif (preg_match('~^api/bep-bar/items/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $table = 'CHI_TIET_ORDER';
        } elseif (preg_match('~^api/cashier/invoices/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $table = 'HOA_DON';
        } elseif (preg_match('~^api/hr/employees/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $table = 'NHAN_VIEN';
        } elseif (preg_match('~^api/hr/contracts/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $table = 'HOP_DONG';
        } elseif (preg_match('~^api/hr/attendance/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $table = 'CHAM_CONG';
        } elseif (preg_match('~^api/hr/payrolls/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $table = 'BANG_LUONG';
        } elseif (preg_match('~^api/system/tickets/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $table = 'TICKET_HO_TRO';
        } elseif (preg_match('~^api/hr/shifts/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $table = 'CA_LAM';
        }

        if ($table && $id) {
            $actual = match ($table) {
                'NHAN_VIEN' => DB::table('NHAN_VIEN')->where('MaNV', $id)->value('MaCS'),
                'CA_LAM' => DB::table('CA_LAM')->where('MaCa', $id)->value('MaCS'),
                'TICKET_HO_TRO' => DB::table('TICKET_HO_TRO')->where('MaTicket', $id)->value('MaCS'),
                'CHAM_CONG' => DB::table('CHAM_CONG')->where('MaCC', $id)->value('MaCS'),
                'HOP_DONG' => DB::table('HOP_DONG as hd')->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'hd.MaNV')->where('hd.MaHDLD', $id)->value('nv.MaCS'),
                'BANG_LUONG' => DB::table('BANG_LUONG as bl')->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'bl.MaNV')->where('bl.MaBL', $id)->value('nv.MaCS'),
                'PHIEU_ORDER' => DB::table('PHIEU_ORDER as po')->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')->where('po.MaOrder', $id)->value('kv.MaCS'),
                'CHI_TIET_ORDER' => DB::table('CHI_TIET_ORDER as cto')->join('PHIEU_ORDER as po', 'po.MaOrder', '=', 'cto.MaOrder')->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')->where('cto.MaCTOrder', $id)->value('kv.MaCS'),
                'HOA_DON' => DB::table('CT_HOA_DON as cthd')->join('CHI_TIET_ORDER as cto', 'cto.MaCTOrder', '=', 'cthd.MaCTOrder')->join('PHIEU_ORDER as po', 'po.MaOrder', '=', 'cto.MaOrder')->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')->where('cthd.MaHD', $id)->value('kv.MaCS'),
                default => null,
            };
            abort_unless($actual !== null && (int) $actual === $branch, 403, 'Tài nguyên không thuộc cơ sở được phép.');
        }

        return $next($request);
    }
}