<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cô lập dữ liệu theo MaCS cho tài khoản nhân viên.
 * Với endpoint danh sách theo cơ sở, tự gắn MaCS từ phiên đăng nhập nếu client không gửi.
 * Với endpoint chi tiết, kiểm tra cơ sở của bản ghi trước khi controller xử lý.
 */
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
        $role = (string) $user->TenVaiTro;

        if ($role === 'Quan tri he thong') {
            // Quản trị hệ thống được phép chọn cơ sở trong từng màn hình.
            return $next($request);
        }

        abort_unless($branch > 0, 403, 'Tài khoản chưa được gán cơ sở làm việc.');
        abort_unless((int) $user->MaCS === $branch, 403, 'Cơ sở không thuộc nhân viên.');

        if ($request->exists('MaCS')) {
            abort_unless((int) $request->input('MaCS') === $branch, 403, 'Bạn không có quyền truy cập cơ sở này.');
        }

        $path = $request->path();

        // Các danh sách/chức năng nghiệp vụ gắn với cơ sở. Không để controller tự rơi về
        // cơ sở đầu tiên khi client bỏ quên MaCS.
        $branchScoped = preg_match(
            '~^api/(?:areas|tables|available-tables|reservations|orders|employees|menu-items|complaints'
            . '|bep-bar/(?:orders|issue-request-options|issue-requests)'
            . '|inventory/(?:dashboard|stock|purchase-orders|receipts|issues|stocktakes)'
            . '|cashier/(?:orders|invoices|shifts|shift-summary|shift-reports)'
            . '|hr/(?:dashboard|employees|contracts|shifts|attendance|payrolls)'
            . '|finance/(?:dashboard|reconciliation|vouchers|budgets|promotion-performance))$~',
            $path
        );

        if ($branchScoped && !$request->exists('MaCS')) {
            $request->merge(['MaCS' => $branch]);
        }

        if ($request->filled('MaNV')) {
            abort_unless(
                (int) DB::table('NHAN_VIEN')->where('MaNV', $request->input('MaNV'))->value('MaCS') === $branch,
                403,
                'Nhân viên không thuộc cơ sở.'
            );
        }

        // UC2.5 tự kiểm tra bàn trong ComplaintController và trả lỗi validation 422
        // khi bàn không thuộc cơ sở; không chặn trước bằng 403 tại middleware.
        $isComplaintSubmission = $path === 'api/complaints' && $request->isMethod('post');

        if ($request->filled('MaBan') && !$isComplaintSubmission) {
            $tableBranch = DB::table('BAN as b')
                ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
                ->where('b.MaBan', $request->input('MaBan'))
                ->value('kv.MaCS');

            abort_unless((int) $tableBranch === $branch, 403, 'Bàn không thuộc cơ sở.');
        }

        if ($request->filled('MaCa')) {
            abort_unless(
                (int) DB::table('CA_LAM')->where('MaCa', $request->input('MaCa'))->value('MaCS') === $branch,
                403,
                'Ca không thuộc cơ sở.'
            );
        }

        $id = null;
        $resource = null;

        if (preg_match('~^api/areas/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'area';
        } elseif (preg_match('~^api/tables/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'table';
        } elseif (preg_match('~^api/reservations/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'reservation';
        } elseif (preg_match('~^api/orders/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'order';
        } elseif (preg_match('~^api/bep-bar/items/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'order-line';
        } elseif (preg_match('~^api/cashier/invoices/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'invoice';
        } elseif (preg_match('~^api/cashier/payments/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'payment';
        } elseif (preg_match('~^api/cashier/shift-reports/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'shift-report';
        } elseif (preg_match('~^api/inventory/purchase-orders/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'purchase-order';
        } elseif (preg_match('~^api/inventory/receipts/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'receipt';
        } elseif (preg_match('~^api/inventory/issues/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'issue';
        } elseif (preg_match('~^api/inventory/stocktakes/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'stocktake';
        } elseif (preg_match('~^api/finance/vouchers/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'voucher';
        } elseif (preg_match('~^api/finance/budgets/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'budget';
        } elseif (preg_match('~^api/hr/employees/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'employee';
        } elseif (preg_match('~^api/hr/contracts/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'contract';
        } elseif (preg_match('~^api/hr/attendance/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'attendance';
        } elseif (preg_match('~^api/hr/payrolls/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'payroll';
        } elseif (preg_match('~^api/hr/shifts/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'shift';
        } elseif (preg_match('~^api/system/tickets/(\d+)(?:/|$)~', $path, $m)) {
            $id = (int) $m[1];
            $resource = 'ticket';
        }

        if ($resource && $id) {
            $actual = match ($resource) {
                'area' => DB::table('KHU_VUC')->where('MaKV', $id)->value('MaCS'),
                'table' => DB::table('BAN as b')->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
                    ->where('b.MaBan', $id)->value('kv.MaCS'),
                'reservation' => DB::table('DAT_BAN_BAN as dbb')
                    ->join('BAN as b', 'b.MaBan', '=', 'dbb.MaBan')
                    ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
                    ->where('dbb.MaDatBan', $id)->value('kv.MaCS'),
                'employee' => DB::table('NHAN_VIEN')->where('MaNV', $id)->value('MaCS'),
                'shift' => DB::table('CA_LAM')->where('MaCa', $id)->value('MaCS'),
                'ticket' => DB::table('TICKET_HO_TRO')->where('MaTicket', $id)->value('MaCS'),
                'attendance' => DB::table('CHAM_CONG')->where('MaCC', $id)->value('MaCS'),
                'contract' => DB::table('HOP_DONG as hd')->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'hd.MaNV')
                    ->where('hd.MaHDLD', $id)->value('nv.MaCS'),
                'payroll' => DB::table('BANG_LUONG as bl')->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'bl.MaNV')
                    ->where('bl.MaBL', $id)->value('nv.MaCS'),
                'order' => DB::table('PHIEU_ORDER as po')->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')
                    ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
                    ->where('po.MaOrder', $id)->value('kv.MaCS'),
                'order-line' => DB::table('CHI_TIET_ORDER as cto')->join('PHIEU_ORDER as po', 'po.MaOrder', '=', 'cto.MaOrder')
                    ->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
                    ->where('cto.MaCTOrder', $id)->value('kv.MaCS'),
                'invoice' => DB::table('CT_HOA_DON as cthd')->join('CHI_TIET_ORDER as cto', 'cto.MaCTOrder', '=', 'cthd.MaCTOrder')
                    ->join('PHIEU_ORDER as po', 'po.MaOrder', '=', 'cto.MaOrder')->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')
                    ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')->where('cthd.MaHD', $id)->value('kv.MaCS')
                    ?? DB::table('HOA_DON as hd')->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'hd.MaNV')
                        ->where('hd.MaHD', $id)->value('nv.MaCS'),
                'payment' => DB::table('THANH_TOAN as tt')->join('HOA_DON as hd', 'hd.MaHD', '=', 'tt.MaHD')
                    ->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 'hd.MaNV')
                    ->where('tt.MaTT', $id)->value('nv.MaCS'),
                'shift-report' => DB::table('BAO_CAO_CUOI_CA')->where('MaBC', $id)->value('MaCS'),
                'purchase-order' => DB::table('DON_DAT_HANG')->where('MaPO', $id)->value('MaCS'),
                'receipt' => DB::table('PHIEU_NHAP_KHO')->where('MaPN', $id)->value('MaCS'),
                'issue' => DB::table('PHIEU_XUAT_KHO')->where('MaPX', $id)->value('MaCS'),
                'stocktake' => DB::table('PHIEU_KIEM_KE')->where('MaKK', $id)->value('MaCS'),
                'voucher' => DB::table('PHIEU_THU_CHI')->where('MaPhieuThuChi', $id)->value('MaCS'),
                'budget' => DB::table('KE_HOACH_NGAN_SACH')->where('MaKHNS', $id)->value('MaCS'),
                default => null,
            };

            // IT/Kỹ thuật là bộ phận hỗ trợ toàn chuỗi; nhân viên khác chỉ thao tác dữ liệu cơ sở mình.
            if (!($resource === 'ticket' && $role === 'IT')) {
                abort_unless($actual !== null && (int) $actual === $branch, 403, 'Tài nguyên không thuộc cơ sở được phép.');
            }
        }

        return $next($request);
    }
}
