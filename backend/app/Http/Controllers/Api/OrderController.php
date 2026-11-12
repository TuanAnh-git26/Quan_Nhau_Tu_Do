<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    private const ACTIVE_ORDER_STATUSES = ['Dang mo', 'Da gui', 'Dang thanh toan'];

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

    public function updateItem(Request $request, int $id, int $detailId)
    {
        $data = $request->validate([
            'SoLuong' => ['required', 'integer', 'min:1', 'max:999'],
            'GhiChu' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = DB::table('PHIEU_ORDER')->where('MaOrder', $id)->first();
        abort_unless($order, 404, 'Không tìm thấy order.');
        if ($order->TrangThai !== 'Dang mo') {
            throw ValidationException::withMessages(['MaOrder' => 'Chỉ được sửa món khi order còn ở trạng thái nháp.']);
        }

        $updated = DB::table('CHI_TIET_ORDER')->where('MaCTOrder', $detailId)->where('MaOrder', $id)
            ->where('TrangThaiMon', '<>', 'Da huy')
            ->update(['SoLuong' => $data['SoLuong'], 'GhiChu' => $data['GhiChu'] ?? null]);
        if (!$updated) abort(404, 'Không tìm thấy món trong order hoặc món đã bị hủy.');

        return response()->json(['message' => 'Đã cập nhật món trong order.']);
    }

    public function removeItem(int $id, int $detailId)
    {
        $order = DB::table('PHIEU_ORDER')->where('MaOrder', $id)->first();
        abort_unless($order, 404, 'Không tìm thấy order.');
        if ($order->TrangThai !== 'Dang mo') {
            throw ValidationException::withMessages(['MaOrder' => 'Chỉ được hủy món khi order còn ở trạng thái nháp.']);
        }

        $updated = DB::table('CHI_TIET_ORDER')->where('MaCTOrder', $detailId)->where('MaOrder', $id)
            ->where('TrangThaiMon', '<>', 'Da huy')->update(['TrangThaiMon' => 'Da huy']);
        if (!$updated) abort(404, 'Không tìm thấy món trong order hoặc món đã bị hủy.');

        return response()->json(['message' => 'Đã hủy món khỏi order nháp.']);
    }

    public function send(int $id)
    {
        $order = DB::table('PHIEU_ORDER')->where('MaOrder', $id)->first();
        abort_unless($order, 404, 'Không tìm thấy order.');
        if (!in_array($order->TrangThai, ['Dang mo', 'Da gui'], true)) {
            throw ValidationException::withMessages(['TrangThai' => 'Order không ở trạng thái có thể gửi.']);
        }

        $hasItems = DB::table('CHI_TIET_ORDER')->where('MaOrder', $id)->where('TrangThaiMon', '<>', 'Da huy')->exists();
        if (!$hasItems) throw ValidationException::withMessages(['ChiTiet' => 'Order chưa có món hợp lệ.']);

        DB::transaction(function () use ($id) {
            DB::table('PHIEU_ORDER')->where('MaOrder', $id)->update(['TrangThai' => 'Da gui']);
            DB::table('CHI_TIET_ORDER')->where('MaOrder', $id)->whereNull('ThoiGianNhan')
                ->where('TrangThaiMon', '<>', 'Da huy')->update(['ThoiGianNhan' => now()]);
        });

        return response()->json(['message' => 'Đã gửi order đến Bếp/Bar.']);
    }

    public function cancel(int $id)
    {
        $order = DB::table('PHIEU_ORDER')->where('MaOrder', $id)->first();
        abort_unless($order, 404, 'Không tìm thấy order.');
        if ($order->TrangThai !== 'Dang mo') {
            throw ValidationException::withMessages(['TrangThai' => 'Chỉ được hủy order khi order còn ở trạng thái nháp.']);
        }

        DB::transaction(function () use ($id, $order) {
            DB::table('CHI_TIET_ORDER')->where('MaOrder', $id)->update(['TrangThaiMon' => 'Da huy']);
            DB::table('PHIEU_ORDER')->where('MaOrder', $id)->update(['TrangThai' => 'Da huy']);
            DB::table('BAN')->where('MaBan', $order->MaBan)->where('TrangThai', 'Dang phuc vu')->update(['TrangThai' => 'Trong']);
        });

        return response()->json(['message' => 'Đã hủy order nháp.']);
    }
}
