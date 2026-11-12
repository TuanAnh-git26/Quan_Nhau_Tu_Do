<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phân hệ Thu ngân – Hóa đơn – Thanh toán (Đợt 5).
 *
 * Lưu ý:
 * - Dùng đúng các bảng hiện có, không tạo migration và không thay đổi schema.
 * - Thẻ/QR được ghi nhận "Cho doi soat" cho tới khi người dùng xác minh giao dịch.
 * - Đây là bản tích hợp nghiệp vụ demo; chưa kết nối cổng thanh toán thật.
 */
class CashierController extends Controller
{
    private const ORDER_READY_STATUSES = ['Da gui', 'Dang thanh toan'];
    private const INVOICE_OPEN = 'Dang mo';
    private const INVOICE_PAID = 'Da thanh toan';
    private const INVOICE_VOID = 'Da huy';
    private const INVOICE_MERGED = 'Da gop';
    private const PAYMENT_SUCCESS = 'Thanh cong';
    private const PAYMENT_PENDING = 'Cho doi soat';
    private const PAYMENT_FAILED = 'That bai';

    /** Danh sách ca làm theo cơ sở. */
    public function shifts(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
        ]);

        return response()->json(DB::table('CA_LAM')
            ->where('MaCS', $data['MaCS'])
            ->where('TrangThai', 'Dang hoat dong')
            ->orderBy('GioVao')
            ->get(['MaCa', 'TenCa', 'GioVao', 'GioRa', 'MaCS', 'TrangThai']));
    }

    /** Danh sách order đã gửi hoặc đang thanh toán, có phân trang. */
    public function orders(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'q' => ['nullable', 'string', 'max:100'],
            'TrangThai' => ['nullable', 'string', 'max:30'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = DB::table('PHIEU_ORDER as po')
            ->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')
            ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'po.MaNV')
            ->leftJoin('CHI_TIET_ORDER as ct', 'ct.MaOrder', '=', 'po.MaOrder')
            ->where('kv.MaCS', $data['MaCS'])
            ->whereIn('po.TrangThai', self::ORDER_READY_STATUSES)
            ->select(
                'po.MaOrder', 'po.MaBan', 'po.MaNV', 'po.ThoiGianTao', 'po.TrangThai',
                'b.SoBan', 'kv.TenKV', 'nv.HoTen as NhanVienPhuTrach',
                DB::raw("COALESCE(SUM(CASE WHEN ct.TrangThaiMon <> 'Da huy' THEN ct.SoLuong ELSE 0 END), 0) as TongSoLuong"),
                DB::raw("COALESCE(SUM(CASE WHEN ct.TrangThaiMon <> 'Da huy' THEN ct.SoLuong * ct.DonGia ELSE 0 END), 0) as TamTinh")
            )
            ->groupBy('po.MaOrder', 'po.MaBan', 'po.MaNV', 'po.ThoiGianTao', 'po.TrangThai', 'b.SoBan', 'kv.TenKV', 'nv.HoTen')
            ->orderByDesc('po.ThoiGianTao');

        if (!empty($data['TrangThai'])) {
            $query->where('po.TrangThai', $data['TrangThai']);
        }

        if (!empty($data['q'])) {
            $keyword = trim($data['q']);
            $query->where(function ($q) use ($keyword) {
                $q->where('b.SoBan', 'like', "%{$keyword}%")
                    ->orWhere('kv.TenKV', 'like', "%{$keyword}%")
                    ->orWhere('nv.HoTen', 'like', "%{$keyword}%")
                    ->orWhere('po.MaOrder', $keyword);
            });
        }

        return response()->json($query->paginate($data['per_page'] ?? 10)->withQueryString());
    }

    /** Chi tiết order và số lượng còn có thể lập hóa đơn. */
    public function showOrder(int $id)
    {
        $order = DB::table('PHIEU_ORDER as po')
            ->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')
            ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'po.MaNV')
            ->where('po.MaOrder', $id)
            ->first([
                'po.MaOrder', 'po.MaBan', 'po.MaNV', 'po.ThoiGianTao', 'po.TrangThai',
                'b.SoBan', 'kv.TenKV', 'kv.MaCS', 'nv.HoTen as NhanVienPhuTrach',
            ]);

        abort_unless($order, 404, 'Không tìm thấy order.');

        $details = DB::table('CHI_TIET_ORDER as ct')
            ->join('MON as m', 'm.MaMon', '=', 'ct.MaMon')
            ->where('ct.MaOrder', $id)
            ->where('ct.TrangThaiMon', '<>', 'Da huy')
            ->orderBy('ct.MaCTOrder')
            ->get([
                'ct.MaCTOrder', 'ct.MaMon', 'm.TenMon', 'ct.SoLuong', 'ct.DonGia',
                'ct.GhiChu', 'ct.TrangThaiMon', 'ct.ThoiGianNhan',
                'ct.ThoiGianBatDauCheBien', 'ct.ThoiGianHoanTat',
            ]);

        foreach ($details as $detail) {
            $allocated = (int) DB::table('CT_HOA_DON as cthd')
                ->join('HOA_DON as hd', 'hd.MaHD', '=', 'cthd.MaHD')
                ->where('cthd.MaCTOrder', $detail->MaCTOrder)
                ->whereNotIn('hd.TrangThai', [self::INVOICE_VOID, self::INVOICE_MERGED])
                ->sum('cthd.SoLuong');
            $detail->SoLuongDaLapHoaDon = $allocated;
            $detail->SoLuongConLai = max(0, (int) $detail->SoLuong - $allocated);
            $detail->ThanhTien = round((float) $detail->SoLuong * (float) $detail->DonGia, 2);
        }

        $order->ChiTiet = $details;
        $order->TamTinh = $details->sum(fn ($item) => (float) $item->ThanhTien);

        return response()->json($order);
    }

    /** Tạo hóa đơn từ một hoặc nhiều order cùng cơ sở. */
    public function createInvoice(Request $request)
    {
        $data = $request->validate([
            'MaOrders' => ['required', 'array', 'min:1', 'max:20'],
            'MaOrders.*' => ['required', 'integer', 'distinct', 'exists:PHIEU_ORDER,MaOrder'],
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'MaCa' => ['nullable', 'integer', 'exists:CA_LAM,MaCa'],
        ]);

        $invoiceId = DB::transaction(function () use ($data) {
            $orderIds = array_values(array_unique(array_map('intval', $data['MaOrders'])));
            sort($orderIds);

            $orders = DB::table('PHIEU_ORDER')
                ->whereIn('MaOrder', $orderIds)
                ->orderBy('MaOrder')
                ->lockForUpdate()
                ->get();

            if ($orders->count() !== count($orderIds)) {
                throw ValidationException::withMessages(['MaOrders' => 'Một hoặc nhiều order không tồn tại.']);
            }

            foreach ($orders as $order) {
                if (!in_array($order->TrangThai, self::ORDER_READY_STATUSES, true)) {
                    throw ValidationException::withMessages([
                        'MaOrders' => "Order #{$order->MaOrder} chưa được gửi Bếp/Bar hoặc đã kết thúc.",
                    ]);
                }
            }

            $branchIds = DB::table('PHIEU_ORDER as po')
                ->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')
                ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
                ->whereIn('po.MaOrder', $orderIds)
                ->distinct()->pluck('kv.MaCS')->map(fn ($id) => (int) $id)->unique()->values();

            if ($branchIds->count() !== 1) {
                throw ValidationException::withMessages(['MaOrders' => 'Chỉ được lập một hóa đơn cho các order thuộc cùng cơ sở.']);
            }

            $branchId = (int) $branchIds->first();
            $this->assertEmployeeForBranch((int) $data['MaNV'], $branchId);
            $shiftId = $this->resolveShift($branchId, $data['MaCa'] ?? null);

            $lines = DB::table('CHI_TIET_ORDER')
                ->whereIn('MaOrder', $orderIds)
                ->where('TrangThaiMon', '<>', 'Da huy')
                ->orderBy('MaCTOrder')
                ->lockForUpdate()
                ->get();

            $invoiceLines = [];
            $total = 0.0;
            foreach ($lines as $line) {
                $alreadyAllocated = (int) DB::table('CT_HOA_DON as cthd')
                    ->join('HOA_DON as hd', 'hd.MaHD', '=', 'cthd.MaHD')
                    ->where('cthd.MaCTOrder', $line->MaCTOrder)
                    ->whereNotIn('hd.TrangThai', [self::INVOICE_VOID, self::INVOICE_MERGED])
                    ->sum('cthd.SoLuong');

                $remaining = max(0, (int) $line->SoLuong - $alreadyAllocated);
                if ($remaining < 1) {
                    continue;
                }

                $lineTotal = round($remaining * (float) $line->DonGia, 2);
                $invoiceLines[] = [
                    'MaCTOrder' => $line->MaCTOrder,
                    'SoLuong' => $remaining,
                    'ThanhTien' => $lineTotal,
                ];
                $total += $lineTotal;
            }

            if (!$invoiceLines) {
                throw ValidationException::withMessages(['MaOrders' => 'Các order đã được lập hóa đơn toàn bộ hoặc không còn món hợp lệ.']);
            }

            $invoiceId = DB::table('HOA_DON')->insertGetId([
                'MaNV' => $data['MaNV'],
                'MaCa' => $shiftId,
                'MaBC' => null,
                'MaNVDuyet' => null,
                'NgayLap' => now(),
                'TongTien' => round($total, 2),
                'GiamGia' => 0,
                'TrangThai' => self::INVOICE_OPEN,
            ], 'MaHD');

            foreach ($invoiceLines as $line) {
                DB::table('CT_HOA_DON')->insert([
                    'MaHD' => $invoiceId,
                    'MaCTOrder' => $line['MaCTOrder'],
                    'SoLuong' => $line['SoLuong'],
                    'ThanhTien' => $line['ThanhTien'],
                ]);
            }

            DB::table('PHIEU_ORDER')->whereIn('MaOrder', $orderIds)
                ->update(['TrangThai' => 'Dang thanh toan']);

            $this->writeLog('Lap hoa don', 'HOA_DON', "Tạo hóa đơn #{$invoiceId} từ order: " . implode(',', $orderIds));

            return $invoiceId;
        });

        return $this->showInvoice($invoiceId);
    }

    /** Danh sách hóa đơn của một cơ sở, có phân trang. */
    public function invoices(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'TrangThai' => ['nullable', 'string', 'max:30'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = DB::table('HOA_DON as hd')
            ->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 'hd.MaNV')
            ->leftJoin('CA_LAM as ca', 'ca.MaCa', '=', 'hd.MaCa')
            ->whereExists(function ($sub) use ($data) {
                $sub->selectRaw('1')->from('CT_HOA_DON as cthd')
                    ->join('CHI_TIET_ORDER as cto', 'cto.MaCTOrder', '=', 'cthd.MaCTOrder')
                    ->join('PHIEU_ORDER as po', 'po.MaOrder', '=', 'cto.MaOrder')
                    ->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')
                    ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
                    ->whereColumn('cthd.MaHD', 'hd.MaHD')
                    ->where('kv.MaCS', $data['MaCS']);
            })
            ->select([
                'hd.MaHD', 'hd.MaNV', 'hd.MaCa', 'hd.MaBC', 'hd.MaNVDuyet',
                'hd.NgayLap', 'hd.TongTien', 'hd.GiamGia', 'hd.TrangThai',
                'nv.HoTen as ThuNgan', 'ca.TenCa',
            ])
            ->selectSub(function ($sub) {
                $sub->from('THANH_TOAN as tt')->selectRaw('COALESCE(SUM(tt.SoTien), 0)')
                    ->whereColumn('tt.MaHD', 'hd.MaHD')->where('tt.TrangThaiGD', self::PAYMENT_SUCCESS);
            }, 'SoDaThu')
            ->selectSub(function ($sub) {
                $sub->from('THANH_TOAN as tt')->selectRaw('COALESCE(SUM(tt.SoTien), 0)')
                    ->whereColumn('tt.MaHD', 'hd.MaHD')->where('tt.TrangThaiGD', self::PAYMENT_PENDING);
            }, 'SoChoDoiSoat')
            ->orderByDesc('hd.NgayLap');

        if (!empty($data['TrangThai'])) {
            $query->where('hd.TrangThai', $data['TrangThai']);
        }
        if (!empty($data['from'])) {
            $query->whereDate('hd.NgayLap', '>=', $data['from']);
        }
        if (!empty($data['to'])) {
            $query->whereDate('hd.NgayLap', '<=', $data['to']);
        }
        if (!empty($data['q'])) {
            $keyword = trim($data['q']);
            $query->where(function ($q) use ($keyword) {
                $q->where('hd.MaHD', $keyword)->orWhere('nv.HoTen', 'like', "%{$keyword}%");
            });
        }

        return response()->json($query->paginate($data['per_page'] ?? 10)->withQueryString());
    }

    /** Chi tiết hóa đơn, dòng món, giao dịch và số tiền còn phải thu. */
    public function showInvoice(int $id)
    {
        $invoice = DB::table('HOA_DON as hd')
            ->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 'hd.MaNV')
            ->leftJoin('CA_LAM as ca', 'ca.MaCa', '=', 'hd.MaCa')
            ->where('hd.MaHD', $id)
            ->first([
                'hd.MaHD', 'hd.MaNV', 'hd.MaCa', 'hd.MaBC', 'hd.MaNVDuyet',
                'hd.NgayLap', 'hd.TongTien', 'hd.GiamGia', 'hd.TrangThai',
                'nv.HoTen as ThuNgan', 'ca.TenCa',
            ]);

        abort_unless($invoice, 404, 'Không tìm thấy hóa đơn.');

        $details = DB::table('CT_HOA_DON as cthd')
            ->join('CHI_TIET_ORDER as cto', 'cto.MaCTOrder', '=', 'cthd.MaCTOrder')
            ->join('PHIEU_ORDER as po', 'po.MaOrder', '=', 'cto.MaOrder')
            ->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')
            ->join('MON as m', 'm.MaMon', '=', 'cto.MaMon')
            ->where('cthd.MaHD', $id)
            ->orderBy('cthd.MaCTHD')
            ->get([
                'cthd.MaCTHD', 'cthd.MaCTOrder', 'cthd.SoLuong', 'cthd.ThanhTien',
                'cto.MaOrder', 'cto.MaMon', 'cto.DonGia', 'cto.GhiChu',
                'm.TenMon', 'b.SoBan', 'po.MaBan', 'cto.TrangThaiMon',
            ]);

        $payments = DB::table('THANH_TOAN')
            ->where('MaHD', $id)->orderByDesc('ThoiGian')->get([
                'MaTT', 'MaYeuCau', 'MaGiaoDichCong', 'HinhThuc', 'SoTien',
                'ThoiGian', 'TrangThaiGD', 'MaNV',
            ]);

        $promotions = DB::table('AP_DUNG_KM as ap')
            ->join('KHUYEN_MAI as km', 'km.MaKM', '=', 'ap.MaKM')
            ->where('ap.MaHD', $id)
            ->get(['ap.MaKM', 'km.TenKM', 'km.HinhThuc', 'km.GiaTri', 'ap.SoTienGiam']);

        $paid = (float) $payments->where('TrangThaiGD', self::PAYMENT_SUCCESS)->sum('SoTien');
        $pending = (float) $payments->where('TrangThaiGD', self::PAYMENT_PENDING)->sum('SoTien');
        $payable = max(0, (float) $invoice->TongTien - (float) $invoice->GiamGia);

        $invoice->ChiTiet = $details;
        $invoice->ThanhToan = $payments;
        $invoice->KhuyenMai = $promotions;
        $invoice->SoPhaiTra = round($payable, 2);
        $invoice->SoDaThu = round($paid, 2);
        $invoice->SoChoDoiSoat = round($pending, 2);
        $invoice->ConPhaiTra = round(max(0, $payable - $paid), 2);
        $invoice->ConCoTheTaoGiaoDich = round(max(0, $payable - $paid - $pending), 2);
        $invoice->DanhSachBan = $details->pluck('SoBan')->unique()->values();

        return response()->json($invoice);
    }

    /** Danh sách khuyến mãi đang trong thời gian áp dụng. */
    public function promotions(Request $request)
    {
        $request->validate([
            'today' => ['nullable', 'date'],
        ]);
        $today = $request->input('today', now()->toDateString());

        $rows = DB::table('KHUYEN_MAI')
            ->whereDate('NgayBD', '<=', $today)
            ->whereDate('NgayKT', '>=', $today)
            ->orderBy('NgayKT')
            ->get(['MaKM', 'TenKM', 'HinhThuc', 'GiaTri', 'NgayBD', 'NgayKT', 'NganSach', 'TrangThai']);

        $active = $rows->filter(fn ($row) => !in_array(mb_strtolower((string) $row->TrangThai), ['nhap', 'da huy', 'ngung'], true))->values();
        return response()->json($active);
    }

    /** Áp dụng một chương trình khuyến mãi lên hóa đơn. */
    public function applyPromotion(Request $request, int $id)
    {
        $data = $request->validate([
            'MaKM' => ['required', 'integer', 'exists:KHUYEN_MAI,MaKM'],
        ]);

        DB::transaction(function () use ($id, $data) {
            $invoice = DB::table('HOA_DON')->where('MaHD', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404, 'Không tìm thấy hóa đơn.');
            $this->assertInvoiceOpen($invoice);

            $promotion = DB::table('KHUYEN_MAI')->where('MaKM', $data['MaKM'])->lockForUpdate()->first();
            abort_unless($promotion, 404, 'Không tìm thấy chương trình khuyến mãi.');

            $today = now()->toDateString();
            $status = mb_strtolower((string) $promotion->TrangThai);
            if (in_array($status, ['nhap', 'da huy', 'ngung'], true)
                || $today < $promotion->NgayBD || $today > $promotion->NgayKT) {
                throw ValidationException::withMessages(['MaKM' => 'Khuyến mãi chưa có hiệu lực hoặc đã hết hạn.']);
            }

            $alreadyApplied = DB::table('AP_DUNG_KM')->where('MaHD', $id)->where('MaKM', $data['MaKM'])->exists();
            if ($alreadyApplied) {
                throw ValidationException::withMessages(['MaKM' => 'Khuyến mãi này đã được áp dụng cho hóa đơn.']);
            }

            $total = (float) DB::table('CT_HOA_DON')->where('MaHD', $id)->sum('ThanhTien');
            $promotionDiscount = (float) DB::table('AP_DUNG_KM')->where('MaHD', $id)->sum('SoTienGiam');
            $manualDiscount = max(0, (float) $invoice->GiamGia - $promotionDiscount);
            $value = (float) $promotion->GiaTri;
            $form = mb_strtolower(trim((string) $promotion->HinhThuc));
            $discount = (str_contains($form, '%') || str_contains($form, 'phan tram') || str_contains($form, 'percent'))
                ? round($total * $value / 100, 2)
                : round($value, 2);

            if ($discount <= 0 || ($manualDiscount + $promotionDiscount + $discount) > $total) {
                throw ValidationException::withMessages(['MaKM' => 'Giá trị khuyến mãi không hợp lệ hoặc vượt tổng tiền hóa đơn.']);
            }

            if ((float) $promotion->NganSach > 0) {
                $usedBudget = (float) DB::table('AP_DUNG_KM')->where('MaKM', $data['MaKM'])->sum('SoTienGiam');
                if ($usedBudget + $discount > (float) $promotion->NganSach) {
                    throw ValidationException::withMessages(['MaKM' => 'Ngân sách còn lại của chương trình không đủ.']);
                }
            }

            DB::table('AP_DUNG_KM')->insert([
                'MaHD' => $id,
                'MaKM' => $data['MaKM'],
                'SoTienGiam' => $discount,
            ]);

            DB::table('HOA_DON')->where('MaHD', $id)->update([
                'TongTien' => round($total, 2),
                'GiamGia' => round($manualDiscount + $promotionDiscount + $discount, 2),
            ]);
            $this->finalizeInvoiceIfPaid($id);

            $this->writeLog('Ap dung khuyen mai', 'HOA_DON', "Áp dụng khuyến mãi #{$data['MaKM']} cho hóa đơn #{$id}, giảm {$discount}.");
        });

        return $this->showInvoice($id);
    }

    /** Gỡ khuyến mãi, giữ nguyên phần giảm giá thủ công đã được duyệt. */
    public function removePromotion(int $id, int $promotionId)
    {
        DB::transaction(function () use ($id, $promotionId) {
            $invoice = DB::table('HOA_DON')->where('MaHD', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404, 'Không tìm thấy hóa đơn.');
            $this->assertInvoiceOpen($invoice);

            $oldPromotionTotal = (float) DB::table('AP_DUNG_KM')->where('MaHD', $id)->sum('SoTienGiam');
            $manualDiscount = max(0, (float) $invoice->GiamGia - $oldPromotionTotal);
            $deleted = DB::table('AP_DUNG_KM')->where('MaHD', $id)->where('MaKM', $promotionId)->delete();
            if (!$deleted) {
                throw ValidationException::withMessages(['MaKM' => 'Khuyến mãi không tồn tại trên hóa đơn.']);
            }
            $newPromotionTotal = (float) DB::table('AP_DUNG_KM')->where('MaHD', $id)->sum('SoTienGiam');
            $total = (float) DB::table('CT_HOA_DON')->where('MaHD', $id)->sum('ThanhTien');
            DB::table('HOA_DON')->where('MaHD', $id)->update([
                'TongTien' => round($total, 2),
                'GiamGia' => round(min($total, $manualDiscount + $newPromotionTotal), 2),
            ]);
            $this->finalizeInvoiceIfPaid($id);

            $this->writeLog('Go khuyen mai', 'HOA_DON', "Gỡ khuyến mãi #{$promotionId} khỏi hóa đơn #{$id}.");
        });

        return $this->showInvoice($id);
    }

    /** Giảm giá thủ công; yêu cầu ghi nhận nhân viên giám sát phê duyệt. */
    public function setManualDiscount(Request $request, int $id)
    {
        $data = $request->validate([
            'SoTienGiam' => ['required', 'numeric', 'min:0'],
            'MaNVDuyet' => ['nullable', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
        ]);

        DB::transaction(function () use ($id, $data) {
            $invoice = DB::table('HOA_DON')->where('MaHD', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404, 'Không tìm thấy hóa đơn.');
            $this->assertInvoiceOpen($invoice);
            $branchId = $this->invoiceBranchId($id);
            $this->assertEmployeeForBranch((int) $data['MaNV'], $branchId);

            $managerId = $data['MaNVDuyet'] ?? null;
            $manual = (float) $data['SoTienGiam'];
            if ($manual > 0) {
                if (!$managerId) {
                    throw ValidationException::withMessages(['MaNVDuyet' => 'Giảm giá thủ công cần Giám sát/Trưởng ca phê duyệt.']);
                }
                $this->assertEmployeeForBranch((int) $managerId, $branchId);
            }

            $total = (float) DB::table('CT_HOA_DON')->where('MaHD', $id)->sum('ThanhTien');
            $promotionDiscount = (float) DB::table('AP_DUNG_KM')->where('MaHD', $id)->sum('SoTienGiam');
            if ($manual + $promotionDiscount > $total) {
                throw ValidationException::withMessages(['SoTienGiam' => 'Tổng giảm giá vượt quá tổng tiền hóa đơn.']);
            }

            DB::table('HOA_DON')->where('MaHD', $id)->update([
                'TongTien' => round($total, 2),
                'GiamGia' => round($manual + $promotionDiscount, 2),
                'MaNVDuyet' => $managerId,
            ]);
            $this->finalizeInvoiceIfPaid($id);

            $this->writeLog('Giam gia thu cong', 'HOA_DON', "Cập nhật giảm giá thủ công hóa đơn #{$id}: {$manual}; người duyệt: " . ($managerId ?: 'không có'));
        });

        return $this->showInvoice($id);
    }

    /** Tạo giao dịch thanh toán. Tiền mặt thành công ngay; thẻ/QR chờ xác minh. */
    public function createPayment(Request $request, int $id)
    {
        $data = $request->validate([
            'HinhThuc' => ['required', 'string', 'in:Tien mat,The,Chuyen khoan/QR,QR,Chuyen khoan'],
            'SoTien' => ['required', 'numeric', 'gt:0'],
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'MaYeuCau' => ['nullable', 'string', 'max:80'],
            'MaGiaoDichCong' => ['nullable', 'string', 'max:120'],
        ]);

        $paymentId = DB::transaction(function () use ($id, $data) {
            $invoice = DB::table('HOA_DON')->where('MaHD', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404, 'Không tìm thấy hóa đơn.');
            $this->assertInvoiceOpen($invoice);

            $branchId = $this->invoiceBranchId($id);
            $this->assertEmployeeForBranch((int) $data['MaNV'], $branchId);

            $requestCode = $data['MaYeuCau'] ?: (string) Str::uuid();
            $existing = DB::table('THANH_TOAN')->where('MaYeuCau', $requestCode)->first();
            if ($existing) {
                if ((int) $existing->MaHD !== $id) {
                    throw ValidationException::withMessages(['MaYeuCau' => 'Mã yêu cầu đã được sử dụng cho hóa đơn khác.']);
                }
                return (int) $existing->MaTT;
            }

            $total = (float) DB::table('CT_HOA_DON')->where('MaHD', $id)->sum('ThanhTien');
            $payable = max(0, $total - (float) $invoice->GiamGia);
            $paid = (float) DB::table('THANH_TOAN')->where('MaHD', $id)->where('TrangThaiGD', self::PAYMENT_SUCCESS)->sum('SoTien');
            $pending = (float) DB::table('THANH_TOAN')->where('MaHD', $id)->where('TrangThaiGD', self::PAYMENT_PENDING)->sum('SoTien');
            $available = max(0, $payable - $paid - $pending);

            if ((float) $data['SoTien'] > $available + 0.0001) {
                throw ValidationException::withMessages(['SoTien' => 'Số tiền vượt quá phần còn có thể tạo giao dịch. Hãy tải lại hóa đơn.']);
            }

            $isCash = $this->isCashMethod($data['HinhThuc']);
            $paymentId = DB::table('THANH_TOAN')->insertGetId([
                'MaHD' => $id,
                'MaYeuCau' => $requestCode,
                'MaGiaoDichCong' => $data['MaGiaoDichCong'] ?? null,
                'HinhThuc' => $data['HinhThuc'],
                'SoTien' => round((float) $data['SoTien'], 2),
                'ThoiGian' => now(),
                'TrangThaiGD' => $isCash ? self::PAYMENT_SUCCESS : self::PAYMENT_PENDING,
                'MaNV' => $data['MaNV'],
            ], 'MaTT');

            $this->writeLog('Tao giao dich', 'THANH_TOAN', "Tạo giao dịch #{$paymentId} cho hóa đơn #{$id}; trạng thái " . ($isCash ? self::PAYMENT_SUCCESS : self::PAYMENT_PENDING));

            if ($isCash) {
                $this->finalizeInvoiceIfPaid($id);
            }

            return $paymentId;
        });

        $payment = DB::table('THANH_TOAN')->where('MaTT', $paymentId)->first();
        return response()->json([
            'message' => $payment->TrangThaiGD === self::PAYMENT_SUCCESS
                ? 'Đã ghi nhận thanh toán tiền mặt.'
                : 'Đã tạo giao dịch. Cần xác minh trạng thái từ thiết bị/cổng thanh toán trước khi xác nhận.',
            'ThanhToan' => $payment,
            'HoaDon' => $this->invoicePayload($id),
        ], 201);
    }

    /** Xác minh giao dịch thẻ/QR sau khi kiểm tra thiết bị hoặc cổng thanh toán. */
    public function confirmPayment(Request $request, int $paymentId)
    {
        $data = $request->validate([
            'TrangThaiGD' => ['required', 'in:Thanh cong,That bai'],
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'DaXacMinh' => ['required', 'accepted'],
            'MaGiaoDichCong' => ['nullable', 'string', 'max:120'],
        ]);

        $invoiceId = DB::transaction(function () use ($paymentId, $data) {
            $payment = DB::table('THANH_TOAN')->where('MaTT', $paymentId)->lockForUpdate()->first();
            abort_unless($payment, 404, 'Không tìm thấy giao dịch.');
            if ($payment->TrangThaiGD !== self::PAYMENT_PENDING) {
                throw ValidationException::withMessages(['TrangThaiGD' => 'Chỉ giao dịch đang chờ đối soát mới được xác minh.']);
            }

            $invoice = DB::table('HOA_DON')->where('MaHD', $payment->MaHD)->lockForUpdate()->first();
            abort_unless($invoice, 404, 'Không tìm thấy hóa đơn của giao dịch.');
            $branchId = $this->invoiceBranchId((int) $payment->MaHD);
            $this->assertEmployeeForBranch((int) $data['MaNV'], $branchId);

            if ($data['TrangThaiGD'] === self::PAYMENT_SUCCESS) {
                $total = (float) DB::table('CT_HOA_DON')->where('MaHD', $payment->MaHD)->sum('ThanhTien');
                $payable = max(0, $total - (float) $invoice->GiamGia);
                $alreadyPaid = (float) DB::table('THANH_TOAN')->where('MaHD', $payment->MaHD)
                    ->where('TrangThaiGD', self::PAYMENT_SUCCESS)->sum('SoTien');
                if ($alreadyPaid + (float) $payment->SoTien > $payable + 0.0001) {
                    throw ValidationException::withMessages(['TrangThaiGD' => 'Xác nhận giao dịch này sẽ làm số tiền thu vượt số phải trả.']);
                }
            }

            DB::table('THANH_TOAN')->where('MaTT', $paymentId)->update([
                'TrangThaiGD' => $data['TrangThaiGD'],
                'MaGiaoDichCong' => $data['MaGiaoDichCong'] ?? $payment->MaGiaoDichCong,
            ]);

            $this->writeLog('Xac minh giao dich', 'THANH_TOAN', "Xác minh giao dịch #{$paymentId}: {$data['TrangThaiGD']}.");

            if ($data['TrangThaiGD'] === self::PAYMENT_SUCCESS) {
                $this->finalizeInvoiceIfPaid((int) $payment->MaHD);
            }

            return (int) $payment->MaHD;
        });

        return response()->json([
            'message' => 'Đã cập nhật trạng thái giao dịch.',
            'HoaDon' => $this->invoicePayload($invoiceId),
        ]);
    }

    /** Tách một phần số lượng món sang hóa đơn mới. Chỉ cho phép hóa đơn chưa thu tiền/khuyến mãi. */
    public function splitInvoice(Request $request, int $id)
    {
        $data = $request->validate([
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.MaCTHD' => ['required', 'integer', 'distinct'],
            'items.*.SoLuongTach' => ['required', 'integer', 'min:1'],
        ]);

        $newInvoiceId = DB::transaction(function () use ($id, $data) {
            $source = DB::table('HOA_DON')->where('MaHD', $id)->lockForUpdate()->first();
            abort_unless($source, 404, 'Không tìm thấy hóa đơn.');
            $this->assertInvoiceOpen($source);
            $branchId = $this->invoiceBranchId($id);
            $this->assertEmployeeForBranch((int) $data['MaNV'], $branchId);
            $this->assertInvoiceCanBeRestructured($id, $source);

            $newInvoiceId = DB::table('HOA_DON')->insertGetId([
                'MaNV' => $data['MaNV'],
                'MaCa' => $source->MaCa,
                'MaBC' => null,
                'MaNVDuyet' => null,
                'NgayLap' => now(),
                'TongTien' => 0,
                'GiamGia' => 0,
                'TrangThai' => self::INVOICE_OPEN,
            ], 'MaHD');

            foreach ($data['items'] as $item) {
                $line = DB::table('CT_HOA_DON')->where('MaCTHD', $item['MaCTHD'])
                    ->where('MaHD', $id)->lockForUpdate()->first();
                abort_unless($line, 404, 'Không tìm thấy dòng món cần tách trong hóa đơn nguồn.');
                $qty = (int) $item['SoLuongTach'];
                if ($qty > (int) $line->SoLuong) {
                    throw ValidationException::withMessages(['items' => 'Số lượng tách không được vượt số lượng trên hóa đơn.']);
                }

                $unitPrice = (float) $line->ThanhTien / max(1, (int) $line->SoLuong);
                $splitAmount = round($unitPrice * $qty, 2);
                if ($qty === (int) $line->SoLuong) {
                    DB::table('CT_HOA_DON')->where('MaCTHD', $line->MaCTHD)->update(['MaHD' => $newInvoiceId]);
                } else {
                    DB::table('CT_HOA_DON')->where('MaCTHD', $line->MaCTHD)->update([
                        'SoLuong' => (int) $line->SoLuong - $qty,
                        'ThanhTien' => round((float) $line->ThanhTien - $splitAmount, 2),
                    ]);
                    DB::table('CT_HOA_DON')->insert([
                        'MaHD' => $newInvoiceId,
                        'MaCTOrder' => $line->MaCTOrder,
                        'SoLuong' => $qty,
                        'ThanhTien' => $splitAmount,
                    ]);
                }
            }

            $this->recalculateInvoice($id);
            $this->recalculateInvoice($newInvoiceId);
            $this->writeLog('Tach hoa don', 'HOA_DON', "Tách hóa đơn #{$id} thành hóa đơn mới #{$newInvoiceId}.");

            return $newInvoiceId;
        });

        return $this->showInvoice($newInvoiceId);
    }

    /** Gộp các hóa đơn chưa thanh toán vào hóa đơn đích. */
    public function mergeInvoices(Request $request, int $id)
    {
        $data = $request->validate([
            'MaHDNguon' => ['required', 'array', 'min:1', 'max:20'],
            'MaHDNguon.*' => ['required', 'integer', 'distinct', 'exists:HOA_DON,MaHD'],
        ]);

        DB::transaction(function () use ($id, $data) {
            $sourceIds = array_values(array_unique(array_map('intval', $data['MaHDNguon'])));
            if (in_array($id, $sourceIds, true)) {
                throw ValidationException::withMessages(['MaHDNguon' => 'Không thể chọn hóa đơn đích làm hóa đơn nguồn.']);
            }

            $target = DB::table('HOA_DON')->where('MaHD', $id)->lockForUpdate()->first();
            abort_unless($target, 404, 'Không tìm thấy hóa đơn đích.');
            $this->assertInvoiceOpen($target);
            $this->assertInvoiceCanBeRestructured($id, $target);
            $branchId = $this->invoiceBranchId($id);

            $sources = DB::table('HOA_DON')->whereIn('MaHD', $sourceIds)->orderBy('MaHD')->lockForUpdate()->get();
            if ($sources->count() !== count($sourceIds)) {
                throw ValidationException::withMessages(['MaHDNguon' => 'Một hoặc nhiều hóa đơn nguồn không tồn tại.']);
            }

            foreach ($sources as $source) {
                $this->assertInvoiceOpen($source);
                $this->assertInvoiceCanBeRestructured((int) $source->MaHD, $source);
                if ($this->invoiceBranchId((int) $source->MaHD) !== $branchId) {
                    throw ValidationException::withMessages(['MaHDNguon' => 'Chỉ được gộp hóa đơn trong cùng cơ sở.']);
                }
                if ((int) $source->MaCa !== (int) $target->MaCa) {
                    throw ValidationException::withMessages(['MaHDNguon' => 'Chỉ được gộp hóa đơn thuộc cùng ca làm.']);
                }

                $sourceLines = DB::table('CT_HOA_DON')->where('MaHD', $source->MaHD)->lockForUpdate()->get();
                foreach ($sourceLines as $line) {
                    $targetLine = DB::table('CT_HOA_DON')->where('MaHD', $id)
                        ->where('MaCTOrder', $line->MaCTOrder)->lockForUpdate()->first();
                    if ($targetLine) {
                        DB::table('CT_HOA_DON')->where('MaCTHD', $targetLine->MaCTHD)->update([
                            'SoLuong' => (int) $targetLine->SoLuong + (int) $line->SoLuong,
                            'ThanhTien' => round((float) $targetLine->ThanhTien + (float) $line->ThanhTien, 2),
                        ]);
                        DB::table('CT_HOA_DON')->where('MaCTHD', $line->MaCTHD)->delete();
                    } else {
                        DB::table('CT_HOA_DON')->where('MaCTHD', $line->MaCTHD)->update(['MaHD' => $id]);
                    }
                }

                DB::table('HOA_DON')->where('MaHD', $source->MaHD)->update([
                    'TongTien' => 0,
                    'GiamGia' => 0,
                    'TrangThai' => self::INVOICE_MERGED,
                ]);
            }

            $this->recalculateInvoice($id);
            $this->writeLog('Gop hoa don', 'HOA_DON', 'Gộp hóa đơn nguồn ' . implode(',', $sourceIds) . " vào hóa đơn #{$id}.");
        });

        return $this->showInvoice($id);
    }

    /** Tóm tắt doanh thu theo ca, gồm tiền mặt, không tiền mặt và giao dịch chờ. */
    public function shiftSummary(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'MaCa' => ['required', 'integer', 'exists:CA_LAM,MaCa'],
            'NgayCa' => ['required', 'date'],
        ]);

        $summary = $this->buildShiftSummary((int) $data['MaCS'], (int) $data['MaCa'], $data['NgayCa']);
        return response()->json($summary);
    }

    /** Danh sách báo cáo cuối ca. */
    public function shiftReports(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = DB::table('BAO_CAO_CUOI_CA as bc')
            ->join('CA_LAM as ca', 'ca.MaCa', '=', 'bc.MaCa')
            ->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 'bc.MaNVBanGiao')
            ->where('bc.MaCS', $data['MaCS'])
            ->select([
                'bc.MaBC', 'bc.NgayCa', 'bc.MaCS', 'bc.MaCa', 'bc.TienMat',
                'bc.KhongTienMat', 'bc.ChenhLech', 'bc.MaNVBanGiao', 'bc.TrangThai',
                'ca.TenCa', 'nv.HoTen as NhanVienBanGiao',
            ])
            ->orderByDesc('bc.NgayCa')->orderByDesc('bc.MaBC');

        if (!empty($data['from'])) $query->whereDate('bc.NgayCa', '>=', $data['from']);
        if (!empty($data['to'])) $query->whereDate('bc.NgayCa', '<=', $data['to']);

        return response()->json($query->paginate($data['per_page'] ?? 10)->withQueryString());
    }

    /** Lập báo cáo và bàn giao ca; TienMat lưu số tiền mặt kiểm đếm thực tế. */
    public function closeShift(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'MaCa' => ['required', 'integer', 'exists:CA_LAM,MaCa'],
            'NgayCa' => ['required', 'date'],
            'TienMatThucTe' => ['required', 'numeric', 'min:0'],
            'MaNVBanGiao' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
        ]);

        $reportId = DB::transaction(function () use ($data) {
            $shift = DB::table('CA_LAM')->where('MaCa', $data['MaCa'])
                ->where('MaCS', $data['MaCS'])->lockForUpdate()->first();
            abort_unless($shift, 404, 'Ca làm không thuộc cơ sở đã chọn.');
            $this->assertEmployeeForBranch((int) $data['MaNVBanGiao'], (int) $data['MaCS']);

            $existing = DB::table('BAO_CAO_CUOI_CA')->where('MaCS', $data['MaCS'])
                ->where('MaCa', $data['MaCa'])->whereDate('NgayCa', $data['NgayCa'])
                ->lockForUpdate()->first();
            if ($existing) {
                throw ValidationException::withMessages(['NgayCa' => 'Ca này đã có báo cáo bàn giao.']);
            }

            $summary = $this->buildShiftSummary((int) $data['MaCS'], (int) $data['MaCa'], $data['NgayCa']);
            $actualCash = round((float) $data['TienMatThucTe'], 2);
            $cashDifference = round($actualCash - (float) $summary['TienMatHeThong'], 2);

            $reportId = DB::table('BAO_CAO_CUOI_CA')->insertGetId([
                'NgayCa' => $data['NgayCa'],
                'MaCS' => $data['MaCS'],
                'MaCa' => $data['MaCa'],
                'TienMat' => $actualCash,
                'KhongTienMat' => round((float) $summary['KhongTienMatHeThong'], 2),
                'ChenhLech' => $cashDifference,
                'MaNVBanGiao' => $data['MaNVBanGiao'],
                'TrangThai' => 'Cho doi soat',
            ], 'MaBC');

            $range = $this->shiftDateRange($shift, $data['NgayCa']);
            $invoiceIds = DB::table('THANH_TOAN as tt')
                ->join('HOA_DON as hd', 'hd.MaHD', '=', 'tt.MaHD')
                ->where('hd.MaCa', $data['MaCa'])
                ->whereBetween('tt.ThoiGian', [$range['start'], $range['end']])
                ->distinct()->pluck('hd.MaHD')->all();

            if ($invoiceIds) {
                DB::table('HOA_DON')->whereIn('MaHD', $invoiceIds)->whereNull('MaBC')->update(['MaBC' => $reportId]);
            }

            $this->writeLog('Ban giao ca', 'BAO_CAO_CUOI_CA', "Tạo báo cáo cuối ca #{$reportId}; chênh lệch tiền mặt {$cashDifference}.");
            return $reportId;
        });

        return response()->json([
            'message' => 'Đã lập báo cáo bàn giao ca. Báo cáo đang chờ đối soát.',
            'BaoCao' => DB::table('BAO_CAO_CUOI_CA')->where('MaBC', $reportId)->first(),
        ], 201);
    }

    /** Xác nhận đối soát báo cáo; không cho chốt khi còn giao dịch điện tử chờ xác minh. */
    public function confirmShiftReport(Request $request, int $id)
    {
        $data = $request->validate([
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
        ]);

        DB::transaction(function () use ($id, $data) {
            $report = DB::table('BAO_CAO_CUOI_CA')->where('MaBC', $id)->lockForUpdate()->first();
            abort_unless($report, 404, 'Không tìm thấy báo cáo cuối ca.');
            if ($report->TrangThai === 'Da doi soat') {
                return;
            }

            $this->assertEmployeeForBranch((int) $data['MaNV'], (int) $report->MaCS);
            $summary = $this->buildShiftSummary((int) $report->MaCS, (int) $report->MaCa, $report->NgayCa);
            if ((float) $summary['GiaoDichChoDoiSoat'] > 0) {
                throw ValidationException::withMessages(['TrangThai' => 'Còn giao dịch thẻ/QR đang chờ xác minh; chưa thể chốt đối soát.']);
            }

            DB::table('BAO_CAO_CUOI_CA')->where('MaBC', $id)->update(['TrangThai' => 'Da doi soat']);
            $this->writeLog('Xac nhan doi soat ca', 'BAO_CAO_CUOI_CA', "Xác nhận đối soát báo cáo #{$id} bởi nhân viên #{$data['MaNV']}.");
        });

        return response()->json([
            'message' => 'Đã xác nhận đối soát cuối ca.',
            'BaoCao' => DB::table('BAO_CAO_CUOI_CA')->where('MaBC', $id)->first(),
        ]);
    }

    private function invoicePayload(int $id): array
    {
        $response = $this->showInvoice($id);
        // Dùng cùng cấu trúc response với endpoint chi tiết hóa đơn.
        return json_decode($response->getContent(), true) ?? [];
    }

    private function assertInvoiceOpen(object $invoice): void
    {
        if ($invoice->TrangThai !== self::INVOICE_OPEN) {
            throw ValidationException::withMessages(['TrangThai' => 'Hóa đơn đã chốt, đã hủy hoặc đã gộp; không thể chỉnh sửa.']);
        }
    }

    private function assertInvoiceCanBeRestructured(int $invoiceId, object $invoice): void
    {
        if ((float) $invoice->GiamGia > 0
            || DB::table('THANH_TOAN')->where('MaHD', $invoiceId)->exists()
            || DB::table('AP_DUNG_KM')->where('MaHD', $invoiceId)->exists()) {
            throw ValidationException::withMessages([
                'MaHD' => 'Chỉ được tách/gộp hóa đơn chưa áp dụng giảm giá/khuyến mãi và chưa phát sinh giao dịch thanh toán.',
            ]);
        }
    }

    private function assertEmployeeForBranch(int $employeeId, int $branchId): object
    {
        $employee = DB::table('NHAN_VIEN')->where('MaNV', $employeeId)->first(['MaNV', 'MaCS', 'HoTen', 'TrangThai']);
        if (!$employee || $employee->TrangThai !== 'Dang lam' || (int) $employee->MaCS !== $branchId) {
            throw ValidationException::withMessages(['MaNV' => 'Nhân viên không hoạt động hoặc không thuộc cơ sở đang thao tác.']);
        }
        return $employee;
    }

    private function invoiceBranchId(int $invoiceId): int
    {
        $branchId = DB::table('CT_HOA_DON as cthd')
            ->join('CHI_TIET_ORDER as cto', 'cto.MaCTOrder', '=', 'cthd.MaCTOrder')
            ->join('PHIEU_ORDER as po', 'po.MaOrder', '=', 'cto.MaOrder')
            ->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')
            ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
            ->where('cthd.MaHD', $invoiceId)->value('kv.MaCS');

        if (!$branchId) {
            throw ValidationException::withMessages(['MaHD' => 'Không xác định được cơ sở của hóa đơn.']);
        }
        return (int) $branchId;
    }

    private function resolveShift(int $branchId, ?int $requestedShiftId): ?int
    {
        if ($requestedShiftId) {
            $shift = DB::table('CA_LAM')->where('MaCa', $requestedShiftId)->where('MaCS', $branchId)->first();
            if (!$shift) {
                throw ValidationException::withMessages(['MaCa' => 'Ca làm không thuộc cơ sở đang thao tác.']);
            }
            return (int) $shift->MaCa;
        }

        $nowTime = now()->format('H:i:s');
        $shifts = DB::table('CA_LAM')->where('MaCS', $branchId)->where('TrangThai', 'Dang hoat dong')->get();
        foreach ($shifts as $shift) {
            $start = (string) $shift->GioVao;
            $end = (string) $shift->GioRa;
            $matches = $start <= $end
                ? ($nowTime >= $start && $nowTime <= $end)
                : ($nowTime >= $start || $nowTime <= $end);
            if ($matches) return (int) $shift->MaCa;
        }
        return null;
    }

    private function recalculateInvoice(int $invoiceId): void
    {
        $total = (float) DB::table('CT_HOA_DON')->where('MaHD', $invoiceId)->sum('ThanhTien');
        DB::table('HOA_DON')->where('MaHD', $invoiceId)->update([
            'TongTien' => round($total, 2),
            'GiamGia' => min($total, (float) DB::table('HOA_DON')->where('MaHD', $invoiceId)->value('GiamGia')),
        ]);
    }

    private function isCashMethod(string $method): bool
    {
        $value = mb_strtolower(trim($method));
        return in_array($value, ['tien mat', 'tiền mặt', 'cash'], true);
    }

    private function finalizeInvoiceIfPaid(int $invoiceId): void
    {
        $invoice = DB::table('HOA_DON')->where('MaHD', $invoiceId)->lockForUpdate()->first();
        if (!$invoice || $invoice->TrangThai !== self::INVOICE_OPEN) return;

        $total = (float) DB::table('CT_HOA_DON')->where('MaHD', $invoiceId)->sum('ThanhTien');
        $payable = max(0, $total - (float) $invoice->GiamGia);
        $paid = (float) DB::table('THANH_TOAN')->where('MaHD', $invoiceId)
            ->where('TrangThaiGD', self::PAYMENT_SUCCESS)->sum('SoTien');

        if ($paid + 0.0001 < $payable) return;

        DB::table('HOA_DON')->where('MaHD', $invoiceId)->update([
            'TongTien' => round($total, 2),
            'TrangThai' => self::INVOICE_PAID,
        ]);

        $orderIds = DB::table('CT_HOA_DON as cthd')
            ->join('CHI_TIET_ORDER as cto', 'cto.MaCTOrder', '=', 'cthd.MaCTOrder')
            ->where('cthd.MaHD', $invoiceId)->distinct()->pluck('cto.MaOrder');

        foreach ($orderIds as $orderId) {
            $orderLines = DB::table('CHI_TIET_ORDER')->where('MaOrder', $orderId)
                ->where('TrangThaiMon', '<>', 'Da huy')->get(['MaCTOrder', 'SoLuong']);
            $hasUnbilled = false;
            foreach ($orderLines as $line) {
                $allocated = (int) DB::table('CT_HOA_DON as cthd')
                    ->join('HOA_DON as hd', 'hd.MaHD', '=', 'cthd.MaHD')
                    ->where('cthd.MaCTOrder', $line->MaCTOrder)
                    ->whereNotIn('hd.TrangThai', [self::INVOICE_VOID, self::INVOICE_MERGED])
                    ->sum('cthd.SoLuong');
                if ($allocated < (int) $line->SoLuong) {
                    $hasUnbilled = true;
                    break;
                }
            }

            $hasUnpaidInvoice = DB::table('CT_HOA_DON as cthd')
                ->join('CHI_TIET_ORDER as cto', 'cto.MaCTOrder', '=', 'cthd.MaCTOrder')
                ->join('HOA_DON as hd', 'hd.MaHD', '=', 'cthd.MaHD')
                ->where('cto.MaOrder', $orderId)
                ->whereNotIn('hd.TrangThai', [self::INVOICE_PAID, self::INVOICE_VOID, self::INVOICE_MERGED])
                ->exists();

            if (!$hasUnbilled && !$hasUnpaidInvoice) {
                DB::table('PHIEU_ORDER')->where('MaOrder', $orderId)->update(['TrangThai' => 'Da thanh toan']);
                $tableId = DB::table('PHIEU_ORDER')->where('MaOrder', $orderId)->value('MaBan');
                if ($tableId) $this->releaseTableIfDone((int) $tableId);
            }
        }

        $this->writeLog('Chot hoa don', 'HOA_DON', "Hóa đơn #{$invoiceId} đã thanh toán đủ.");
    }

    private function releaseTableIfDone(int $tableId): void
    {
        $hasActiveOrder = DB::table('PHIEU_ORDER')->where('MaBan', $tableId)
            ->whereIn('TrangThai', ['Dang mo', 'Da gui', 'Dang thanh toan'])->exists();
        if (!$hasActiveOrder) {
            DB::table('BAN')->where('MaBan', $tableId)
                ->where('TrangThai', 'Dang phuc vu')->update(['TrangThai' => 'Trong']);
        }
    }

    private function buildShiftSummary(int $branchId, int $shiftId, string $shiftDate): array
    {
        $shift = DB::table('CA_LAM')->where('MaCa', $shiftId)->where('MaCS', $branchId)->first();
        abort_unless($shift, 404, 'Ca làm không thuộc cơ sở đã chọn.');
        $range = $this->shiftDateRange($shift, $shiftDate);

        $payments = DB::table('THANH_TOAN as tt')
            ->join('HOA_DON as hd', 'hd.MaHD', '=', 'tt.MaHD')
            ->where('hd.MaCa', $shiftId)
            ->whereBetween('tt.ThoiGian', [$range['start'], $range['end']])
            ->whereExists(function ($sub) use ($branchId) {
                $sub->selectRaw('1')->from('CT_HOA_DON as cthd')
                    ->join('CHI_TIET_ORDER as cto', 'cto.MaCTOrder', '=', 'cthd.MaCTOrder')
                    ->join('PHIEU_ORDER as po', 'po.MaOrder', '=', 'cto.MaOrder')
                    ->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')
                    ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
                    ->whereColumn('cthd.MaHD', 'hd.MaHD')
                    ->where('kv.MaCS', $branchId);
            })
            ->get(['tt.MaTT', 'tt.MaHD', 'tt.HinhThuc', 'tt.SoTien', 'tt.ThoiGian', 'tt.TrangThaiGD']);

        $cash = 0.0;
        $nonCash = 0.0;
        $pending = 0.0;
        $failed = 0.0;
        $byMethod = [];
        foreach ($payments as $payment) {
            $method = (string) $payment->HinhThuc;
            if (!isset($byMethod[$method])) {
                $byMethod[$method] = ['HinhThuc' => $method, 'ThanhCong' => 0.0, 'ChoDoiSoat' => 0.0, 'ThatBai' => 0.0];
            }
            if ($payment->TrangThaiGD === self::PAYMENT_SUCCESS) {
                if ($this->isCashMethod($method)) $cash += (float) $payment->SoTien;
                else $nonCash += (float) $payment->SoTien;
                $byMethod[$method]['ThanhCong'] += (float) $payment->SoTien;
            } elseif ($payment->TrangThaiGD === self::PAYMENT_PENDING) {
                $pending += (float) $payment->SoTien;
                $byMethod[$method]['ChoDoiSoat'] += (float) $payment->SoTien;
            } else {
                $failed += (float) $payment->SoTien;
                $byMethod[$method]['ThatBai'] += (float) $payment->SoTien;
            }
        }

        return [
            'MaCS' => $branchId,
            'MaCa' => $shiftId,
            'TenCa' => $shift->TenCa,
            'NgayCa' => $shiftDate,
            'BatDau' => $range['start']->toDateTimeString(),
            'KetThuc' => $range['end']->toDateTimeString(),
            'TienMatHeThong' => round($cash, 2),
            'KhongTienMatHeThong' => round($nonCash, 2),
            'TongThuThanhCong' => round($cash + $nonCash, 2),
            'GiaoDichChoDoiSoat' => round($pending, 2),
            'GiaoDichThatBai' => round($failed, 2),
            'TheoHinhThuc' => array_values($byMethod),
            'GiaoDich' => $payments,
        ];
    }

    private function shiftDateRange(object $shift, string $shiftDate): array
    {
        $start = Carbon::parse($shiftDate . ' ' . $shift->GioVao);
        $end = Carbon::parse($shiftDate . ' ' . $shift->GioRa);
        if ($end->lessThanOrEqualTo($start)) $end->addDay();
        return ['start' => $start, 'end' => $end];
    }

    private function writeLog(string $action, string $entity, string $details): void
    {
        try {
            DB::table('NHAT_KY')->insert([
                'ThoiGian' => now(),
                'HanhDong' => mb_substr($action, 0, 100),
                'DoiTuong' => mb_substr($entity, 0, 100),
                'NoiDungThayDoi' => $details,
                'MaTK' => null,
            ]);
        } catch (\Throwable $exception) {
            // Nhật ký không được làm hỏng giao dịch nghiệp vụ chính.
        }
    }
}
