<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    private const ACTIVE_ORDER_STATUSES = ['Dang mo', 'Da gui', 'Dang thanh toan'];

    // Danh sách người có chức danh duyệt hủy/sửa món tại cơ sở.
    public function supervisors(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
        ]);
        $rows = DB::table('NHAN_VIEN as nv')
            ->join('VI_TRI as vt', 'vt.MaVT', '=', 'nv.MaVT')
            ->where('nv.MaCS', $data['MaCS'])
            ->where('nv.TrangThai', 'Dang lam')
            ->orderBy('nv.HoTen')
            ->get(['nv.MaNV', 'nv.HoTen', 'vt.TenVT']);

        return response()->json($rows->filter(function ($row) {
            $title = Str::ascii(mb_strtolower($row->TenVT, 'UTF-8'));
            return str_contains($title, 'giam sat')
                || str_contains($title, 'quan ly')
                || str_contains($title, 'truong ca');
        })->values());
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'includeClosed' => ['nullable', 'boolean'],
        ]);

        $query = DB::table('PHIEU_ORDER as po')
            ->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')
            ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'po.MaNV')
            ->leftJoin('CHI_TIET_ORDER as ct', 'ct.MaOrder', '=', 'po.MaOrder')
            ->where('kv.MaCS', $data['MaCS'])
            ->select(
                'po.MaOrder', 'po.MaBan', 'po.MaNV', 'po.ThoiGianTao', 'po.TrangThai',
                'b.SoBan', 'kv.TenKV', 'nv.HoTen as NhanVienPhuTrach',
                DB::raw("COALESCE(SUM(CASE WHEN ct.TrangThaiMon <> 'Da huy' THEN ct.SoLuong ELSE 0 END), 0) as TongSoLuong"),
                DB::raw("COALESCE(SUM(CASE WHEN ct.TrangThaiMon <> 'Da huy' THEN ct.SoLuong * ct.DonGia ELSE 0 END), 0) as TamTinh")
            )
            ->groupBy('po.MaOrder', 'po.MaBan', 'po.MaNV', 'po.ThoiGianTao', 'po.TrangThai', 'b.SoBan', 'kv.TenKV', 'nv.HoTen')
            ->orderByDesc('po.MaOrder');

        if (empty($data['includeClosed'])) {
            $query->whereIn('po.TrangThai', self::ACTIVE_ORDER_STATUSES);
        }

        return response()->json($query->limit(500)->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'MaBan' => ['required', 'integer', 'exists:BAN,MaBan'],
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
        ]);

        $result = DB::transaction(function () use ($data) {
            $table = DB::table('BAN as b')
                ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
                ->where('b.MaBan', $data['MaBan'])
                ->lockForUpdate()
                ->first(['b.MaBan', 'b.TrangThai', 'kv.MaCS']);

            if (!$table || $table->TrangThai === 'Ngung su dung') {
                throw ValidationException::withMessages(['MaBan' => 'Bàn không tồn tại hoặc đã ngừng sử dụng.']);
            }

            $employee = DB::table('NHAN_VIEN')->where('MaNV', $data['MaNV'])->first(['MaNV', 'MaCS', 'TrangThai']);
            if (!$employee || $employee->TrangThai !== 'Dang lam' || (int) $employee->MaCS !== (int) $table->MaCS) {
                throw ValidationException::withMessages(['MaNV' => 'Nhân viên không hoạt động hoặc không thuộc cơ sở của bàn.']);
            }

            $existing = DB::table('PHIEU_ORDER')->where('MaBan', $data['MaBan'])
                ->whereIn('TrangThai', self::ACTIVE_ORDER_STATUSES)
                ->orderByDesc('MaOrder')->lockForUpdate()->first();

            if ($existing) {
                return ['MaOrder' => $existing->MaOrder, 'DaTonTai' => true];
            }

            $id = DB::table('PHIEU_ORDER')->insertGetId([
                'MaBan' => $data['MaBan'],
                'MaNV' => $data['MaNV'],
                'ThoiGianTao' => now(),
                'TrangThai' => 'Dang mo',
            ], 'MaOrder');

            DB::table('BAN')->where('MaBan', $data['MaBan'])->update(['TrangThai' => 'Dang phuc vu']);
            return ['MaOrder' => $id, 'DaTonTai' => false];
        });

        return response()->json($result, $result['DaTonTai'] ? 200 : 201);
    }

    public function show(int $id)
    {
        $order = DB::table('PHIEU_ORDER as po')
            ->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')
            ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'po.MaNV')
            ->where('po.MaOrder', $id)
            ->first([
                'po.MaOrder', 'po.MaBan', 'po.MaNV', 'po.ThoiGianTao', 'po.TrangThai',
                'b.SoBan', 'kv.TenKV', 'kv.MaCS', 'nv.HoTen as NhanVienPhuTrach'
            ]);

        abort_unless($order, 404, 'Không tìm thấy order.');

        $details = DB::table('CHI_TIET_ORDER as ct')
            ->join('MON as m', 'm.MaMon', '=', 'ct.MaMon')
            ->join('DANH_MUC_MON as dm', 'dm.MaDM', '=', 'm.MaDM')
            ->where('ct.MaOrder', $id)
            ->orderBy('ct.MaCTOrder')
            ->get([
                'ct.MaCTOrder', 'ct.MaMon', 'm.TenMon', 'dm.TenDM', 'dm.NoiCheBien',
                'ct.SoLuong', 'ct.DonGia', 'ct.GhiChu', 'ct.TrangThaiMon',
                'ct.ThoiGianNhan', 'ct.ThoiGianBatDauCheBien', 'ct.ThoiGianHoanTat',
                DB::raw('(ct.SoLuong * ct.DonGia) as ThanhTien')
            ]);

        $order->ChiTiet = $details;
        $order->TongSoLuong = $details->where('TrangThaiMon', '<>', 'Da huy')->sum('SoLuong');
        $order->TamTinh = $details->where('TrangThaiMon', '<>', 'Da huy')->sum(fn ($item) => (float) $item->ThanhTien);

        return response()->json($order);
    }

    public function addItem(Request $request, int $id)
    {
        $data = $request->validate([
            'MaMon' => ['required', 'integer', 'exists:MON,MaMon'],
            'SoLuong' => ['required', 'integer', 'min:1', 'max:999'],
            'GhiChu' => ['nullable', 'string', 'max:1000'],
            'MaYeuCau' => ['nullable', 'string', 'max:80'],
        ]);

        $detailId = DB::transaction(function () use ($id, $data) {
            $order = DB::table('PHIEU_ORDER')->where('MaOrder', $id)->lockForUpdate()->first();
            abort_unless($order, 404, 'Không tìm thấy order.');
            if (!in_array($order->TrangThai, ['Dang mo', 'Da gui'], true)) {
                throw ValidationException::withMessages(['MaOrder' => 'Order đã kết thúc hoặc đang thanh toán, không thể thêm món.']);
            }

            if (!empty($data['MaYeuCau'])) {
                $duplicate = DB::table('CHI_TIET_ORDER')->where('MaYeuCau', $data['MaYeuCau'])->first();
                if ($duplicate) return $duplicate->MaCTOrder;
            }

            $branchId = DB::table('BAN as b')->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
                ->where('b.MaBan', $order->MaBan)->value('kv.MaCS');

            $menuItem = DB::table('MON as m')
                ->join('DANH_MUC_MON as dm', 'dm.MaDM', '=', 'm.MaDM')
                ->join('GIA_MON_CO_SO as g', 'g.MaMon', '=', 'm.MaMon')
                ->where('m.MaMon', $data['MaMon'])
                ->where('g.MaCS', $branchId)
                ->where('m.TrangThai', 'Dang ban')
                ->where('dm.TrangThai', 'Dang hoat dong')
                ->where('g.TrangThaiBan', 'Dang ban')
                ->first(['m.MaMon', 'g.DonGia']);

            if (!$menuItem) {
                throw ValidationException::withMessages(['MaMon' => 'Món không bán hoặc chưa được thiết lập giá tại cơ sở này.']);
            }

            $payload = [
                'MaOrder' => $id,
                'MaMon' => $data['MaMon'],
                'SoLuong' => $data['SoLuong'],
                'DonGia' => $menuItem->DonGia,
                'GhiChu' => $data['GhiChu'] ?? null,
                'TrangThaiMon' => 'Cho che bien',
                'ThoiGianNhan' => $order->TrangThai === 'Da gui' ? now() : null,
                'MaYeuCau' => $data['MaYeuCau'] ?? null,
            ];

            return DB::table('CHI_TIET_ORDER')->insertGetId($payload, 'MaCTOrder');
        });

        return response()->json(['message' => 'Đã thêm món vào order.', 'MaCTOrder' => $detailId], 201);
    }

    /** UC2.3: Sửa món đang chờ hoặc món Bếp/Bar đã nhận với phê duyệt. */
    public function updateItem(Request $request, int $id, int $detailId)
    {
        $data = $request->validate([
            'SoLuong' => ['required', 'integer', 'min:1', 'max:999'],
            'GhiChu' => ['nullable', 'string', 'max:1000'],
            'MaNVDuyet' => ['nullable', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'MatKhauNVDuyet' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($id, $detailId, $data) {
            $order = DB::table('PHIEU_ORDER')
                ->where('MaOrder', $id)->lockForUpdate()->first();
            abort_unless($order, 404, 'Không tìm thấy order.');
            $this->assertOrderEditable($order);

            $line = DB::table('CHI_TIET_ORDER')
                ->where('MaOrder', $id)->where('MaCTOrder', $detailId)
                ->lockForUpdate()->first();
            abort_unless($line, 404, 'Không tìm thấy món trong order.');

            $this->assertLineEditable($line);
            $managerId = $this->validateManagerForLine(
                $order, $line, $data['MaNVDuyet'] ?? null, $data['MatKhauNVDuyet'] ?? null
            );
            $this->assertLineNotInInvoice($detailId);

            $updates = [
                'SoLuong' => (int) $data['SoLuong'],
                'GhiChu' => $data['GhiChu'] ?? null,
            ];
            if ($managerId !== null) {
                $updates['MaNVDuyet'] = $managerId;
            }
            DB::table('CHI_TIET_ORDER')
                ->where('MaCTOrder', $detailId)->update($updates);

            $this->writeLog(
                'Sua mon', 'CHI_TIET_ORDER',
                "Sửa món #{$line->MaMon} (chi tiết #{$detailId}), order #{$id}; "
                . "số lượng {$line->SoLuong} -> {$updates['SoLuong']}; "
                . 'người duyệt: ' . ($managerId ?? 'không yêu cầu')
            );
        });

        return response()->json(['message' => 'Đã cập nhật món trong order.']);
    }

    /** UC2.3: Hủy mềm món và lưu nhật ký trong cùng giao dịch. */
    public function removeItem(Request $request, int $id, int $detailId)
    {
        $data = $request->validate([
            'MaNVDuyet' => ['nullable', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'MatKhauNVDuyet' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($id, $detailId, $data) {
            $order = DB::table('PHIEU_ORDER')
                ->where('MaOrder', $id)->lockForUpdate()->first();
            abort_unless($order, 404, 'Không tìm thấy order.');
            $this->assertOrderEditable($order);

            $line = DB::table('CHI_TIET_ORDER')
                ->where('MaOrder', $id)->where('MaCTOrder', $detailId)
                ->lockForUpdate()->first();
            abort_unless($line, 404, 'Không tìm thấy món trong order.');

            $this->assertLineEditable($line);
            $managerId = $this->validateManagerForLine(
                $order, $line, $data['MaNVDuyet'] ?? null, $data['MatKhauNVDuyet'] ?? null
            );
            $this->assertLineNotInInvoice($detailId);

            DB::table('CHI_TIET_ORDER')
                ->where('MaCTOrder', $detailId)->update([
                    'TrangThaiMon' => 'Da huy',
                    'MaNVDuyet' => $managerId,
                ]);

            $this->writeLog(
                'Huy mon', 'CHI_TIET_ORDER',
                "Hủy món #{$line->MaMon} (chi tiết #{$detailId}), order #{$id}; "
                . 'người duyệt: ' . ($managerId ?? 'không yêu cầu')
            );
        });

        return response()->json(['message' => 'Đã hủy món trong order.']);
    }

    private function assertOrderEditable(object $order): void
    {
        if (!in_array($order->TrangThai, ['Dang mo', 'Da gui'], true)) {
            throw ValidationException::withMessages([
                'MaOrder' => 'Chỉ được sửa/hủy món khi order đang mở hoặc đã gửi Bếp/Bar.',
            ]);
        }
    }

    private function assertLineEditable(object $line): void
    {
        if (in_array($line->TrangThaiMon, ['Da huy', 'Hoan tat'], true)) {
            throw ValidationException::withMessages([
                'TrangThaiMon' => 'Món đã hoàn tất hoặc đã hủy, không thể sửa/hủy.',
            ]);
        }
        if (!in_array($line->TrangThaiMon, [
            'Cho che bien', 'Da nhan', 'Dang che bien'
        ], true)) {
            throw ValidationException::withMessages([
                'TrangThaiMon' => 'Trạng thái món không hợp lệ để sửa/hủy.',
            ]);
        }
    }

    /** Bắt buộc giám sát cùng cơ sở cho món đã nhận hoặc đang chế biến. */
    private function validateManagerForLine(
        object $order,
        object $line,
        ?int $managerId,
        ?string $managerPassword = null
    ): ?int {
        if (!in_array($line->TrangThaiMon, ['Da nhan', 'Dang che bien'], true)) {
            return null;
        }
        if (!$managerId) {
            throw ValidationException::withMessages([
                'MaNVDuyet' => 'Món đã nhận/đang chế biến phải có Giám sát hoặc Quản lý duyệt.',
            ]);
        }

        $branchId = DB::table('BAN as b')
            ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
            ->where('b.MaBan', $order->MaBan)->value('kv.MaCS');

        $manager = DB::table('NHAN_VIEN as nv')
            ->join('VI_TRI as vt', 'vt.MaVT', '=', 'nv.MaVT')
            ->where('nv.MaNV', $managerId)
            ->first(['nv.MaCS', 'nv.TrangThai', 'vt.TenVT']);

        $position = $manager ? Str::ascii(mb_strtolower((string) $manager->TenVT, 'UTF-8')) : '';
        $isSupervisor = str_contains($position, 'giam sat')
            || str_contains($position, 'quan ly')
            || str_contains($position, 'truong ca');

        if (!$manager || $manager->TrangThai !== 'Dang lam'
            || (int) $manager->MaCS !== (int) $branchId || !$isSupervisor) {
            throw ValidationException::withMessages([
                'MaNVDuyet' => 'Người duyệt phải là Giám sát/Quản lý đang làm tại cùng cơ sở.',
            ]);
        }
        // TASK 8: người dùng không thể giả mạo người duyệt chỉ bằng MaNV.
        $loggedIn = request()->user();
        if ($loggedIn && (int) $loggedIn->MaNV !== (int) $managerId) {
            $approver = DB::table('TAI_KHOAN')->where('MaNV', $managerId)
                ->where('TrangThai', 'Hoat dong')->first();
            if (!$approver || !$managerPassword ||
                !Hash::check($managerPassword, $approver->MatKhauHash)) {
                throw ValidationException::withMessages([
                    'MatKhauNVDuyet' => 'Người duyệt phải xác nhận bằng mật khẩu tài khoản Giám sát.',
                ]);
            }
        }
        return $managerId;
    }

    private function assertLineNotInInvoice(int $detailId): void
    {
        if (DB::table('CT_HOA_DON')
            ->where('MaCTOrder', $detailId)->exists()) {
            throw ValidationException::withMessages([
                'MaCTOrder' => 'Món đã phát sinh chi tiết hóa đơn; không thể sửa/hủy trực tiếp.',
            ]);
        }
    }

    private function writeLog(string $action, string $object, string $details): void
    {
        DB::table('NHAT_KY')->insert([
            'ThoiGian' => now(),
            'HanhDong' => $action,
            'DoiTuong' => $object,
            'NoiDungThayDoi' => $details,
            'MaTK' => request()->user()?->getAuthIdentifier(),
        ]);
    }

    public function send(int $id)
    {
        DB::transaction(function () use ($id) {
            $order = DB::table('PHIEU_ORDER')->where('MaOrder', $id)
                ->lockForUpdate()->first();
            abort_unless($order, 404, 'Không tìm thấy order.');
            if (!in_array($order->TrangThai, ['Dang mo', 'Da gui'], true)) {
                throw ValidationException::withMessages([
                    'TrangThai' => 'Order không ở trạng thái có thể gửi.',
                ]);
            }

            $hasItems = DB::table('CHI_TIET_ORDER')->where('MaOrder', $id)
                ->where('TrangThaiMon', '<>', 'Da huy')->exists();
            if (!$hasItems) {
                throw ValidationException::withMessages([
                    'ChiTiet' => 'Order chưa có món hợp lệ.',
                ]);
            }

            DB::table('PHIEU_ORDER')->where('MaOrder', $id)->update([
                'TrangThai' => 'Da gui',
            ]);
            $sentLines = DB::table('CHI_TIET_ORDER')->where('MaOrder', $id)
                ->whereNull('ThoiGianNhan')->where('TrangThaiMon', '<>', 'Da huy')
                ->update(['ThoiGianNhan' => now()]);

            $this->writeLog('Gui order', 'PHIEU_ORDER',
                "Order #{$id}, bàn #{$order->MaBan}; " .
                "{$order->TrangThai} -> Da gui; {$sentLines} dòng món mới gửi Bếp/Bar.");
        });

        return response()->json(['message' => 'Đã gửi order đến Bếp/Bar.']);
    }

    public function cancel(int $id)
    {
        DB::transaction(function () use ($id) {
            $order = DB::table('PHIEU_ORDER')->where('MaOrder', $id)
                ->lockForUpdate()->first();
            abort_unless($order, 404, 'Không tìm thấy order.');
            if ($order->TrangThai !== 'Dang mo') {
                throw ValidationException::withMessages([
                    'TrangThai' => 'Chỉ được hủy order khi order còn ở trạng thái nháp.',
                ]);
            }

            DB::table('CHI_TIET_ORDER')->where('MaOrder', $id)
                ->update(['TrangThaiMon' => 'Da huy']);
            DB::table('PHIEU_ORDER')->where('MaOrder', $id)
                ->update(['TrangThai' => 'Da huy']);
            DB::table('BAN')->where('MaBan', $order->MaBan)
                ->where('TrangThai', 'Dang phuc vu')
                ->update(['TrangThai' => 'Trong']);

            $this->writeLog('Huy order', 'PHIEU_ORDER',
                "Order #{$id}, bàn #{$order->MaBan}; {$order->TrangThai} -> Da huy.");
        });

        return response()->json(['message' => 'Đã hủy order nháp.']);
    }
}
