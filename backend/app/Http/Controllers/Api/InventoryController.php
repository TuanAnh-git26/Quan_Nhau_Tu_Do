<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    private const SYSTEM_SUPPLIER_EMAIL = 'system-stocktake@local.invalid';

    private function branchId(Request $request): int
    {
        $data = $request->validate([
            'MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS'],
        ]);

        $branchId = $data['MaCS'] ?? DB::table('CO_SO')
            ->where('TrangThai', 'Dang hoat dong')
            ->orderBy('MaCS')
            ->value('MaCS');

        abort_if(!$branchId, 422, 'Chưa có cơ sở đang hoạt động.');

        return (int) $branchId;
    }

    private function ensureActiveBranch(int $branchId): void
    {
        $active = DB::table('CO_SO')
            ->where('MaCS', $branchId)
            ->where('TrangThai', 'Dang hoat dong')
            ->exists();

        if (!$active) {
            throw ValidationException::withMessages([
                'MaCS' => 'Cơ sở không tồn tại hoặc đang ngừng hoạt động.',
            ]);
        }
    }

    private function ensureEmployee(int $employeeId, ?int $branchId = null): void
    {
        $query = DB::table('NHAN_VIEN')
            ->where('MaNV', $employeeId)
            ->where('TrangThai', 'Dang lam');

        if ($branchId !== null) {
            $query->where('MaCS', $branchId);
        }

        if (!$query->exists()) {
            throw ValidationException::withMessages([
                'MaNV' => 'Nhân viên không tồn tại, không còn làm việc hoặc không thuộc cơ sở đã chọn.',
            ]);
        }
    }

    private function stockSubquery(int $branchId, bool $availableOnly = false)
    {
        $query = DB::table('CT_PHIEU_NHAP as ct')
            ->join('PHIEU_NHAP_KHO as pn', 'pn.MaPN', '=', 'ct.MaPN')
            ->where('pn.MaCS', $branchId)
            ->where('pn.TrangThai', 'Da xac nhan');

        if ($availableOnly) {
            $query->where(function ($q) {
                $q->whereNull('ct.HanSuDung')
                    ->orWhereDate('ct.HanSuDung', '>=', now()->toDateString());
            });
        }

        return $query
            ->groupBy('ct.MaNL')
            ->select('ct.MaNL')
            ->selectRaw('SUM(ct.SoLuongCon) AS TonHienTai')
            ->selectRaw('SUM(ct.SoLuongCon * ct.DonGia) AS GiaTriTon');
    }

    private function logAction(string $action, string $object, string $description): void
    {
        DB::table('NHAT_KY')->insert([
            'ThoiGian' => now(),
            'HanhDong' => $action,
            'DoiTuong' => $object,
            'NoiDungThayDoi' => $description,
            'MaTK' => null,
        ]);
    }

    private function stockForIngredient(int $branchId, int $ingredientId, bool $lock = false): float
    {
        $query = DB::table('CT_PHIEU_NHAP as ct')
            ->join('PHIEU_NHAP_KHO as pn', 'pn.MaPN', '=', 'ct.MaPN')
            ->where('pn.MaCS', $branchId)
            ->where('pn.TrangThai', 'Da xac nhan')
            ->where('ct.MaNL', $ingredientId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return (float) $query->sum('ct.SoLuongCon');
    }

    private function ingredientRows(Request $request)
    {
        $branchId = $this->branchId($request);
        $stock = $this->stockSubquery($branchId);
        $query = DB::table('NGUYEN_LIEU as nl')
            ->leftJoinSub($stock, 'ton', function ($join) {
                $join->on('ton.MaNL', '=', 'nl.MaNL');
            })
            ->select(
                'nl.MaNL', 'nl.TenNL', 'nl.DonViTinh', 'nl.TonToiThieu', 'nl.TrangThai',
                DB::raw('COALESCE(ton.TonHienTai, 0) AS TonHienTai'),
                DB::raw('COALESCE(ton.GiaTriTon, 0) AS GiaTriTon')
            )
            ->orderBy('nl.TenNL');

        if ($request->filled('search')) {
            $query->where('nl.TenNL', 'like', '%' . trim((string) $request->string('search')) . '%');
        }

        if ($request->filled('TrangThai')) {
            $query->where('nl.TrangThai', $request->input('TrangThai'));
        }

        return $query->get()->map(function ($item) {
            $item->TonHienTai = (float) $item->TonHienTai;
            $item->GiaTriTon = (float) $item->GiaTriTon;
            $item->CanhBao = $item->TonHienTai < (float) $item->TonToiThieu;
            return $item;
        });
    }

    public function dashboard(Request $request)
    {
        $branchId = $this->branchId($request);
        $stock = $this->stockSubquery($branchId);

        $summary = DB::table('NGUYEN_LIEU as nl')
            ->leftJoinSub($stock, 'ton', function ($join) {
                $join->on('ton.MaNL', '=', 'nl.MaNL');
            })
            ->where('nl.TrangThai', 'Dang su dung')
            ->selectRaw('COUNT(*) AS TongNguyenLieu')
            ->selectRaw('SUM(CASE WHEN COALESCE(ton.TonHienTai, 0) > 0 THEN 1 ELSE 0 END) AS SoNguyenLieuCoTon')
            ->selectRaw('COALESCE(SUM(ton.GiaTriTon), 0) AS GiaTriTonKho')
            ->selectRaw('SUM(CASE WHEN COALESCE(ton.TonHienTai, 0) < nl.TonToiThieu THEN 1 ELSE 0 END) AS SoNguyenLieuCanNhap')
            ->first();

        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        return response()->json([
            'MaCS' => $branchId,
            'TongNguyenLieu' => (int) ($summary->TongNguyenLieu ?? 0),
            'SoNguyenLieuCoTon' => (int) ($summary->SoNguyenLieuCoTon ?? 0),
            'GiaTriTonKho' => (float) ($summary->GiaTriTonKho ?? 0),
            'SoNguyenLieuCanNhap' => (int) ($summary->SoNguyenLieuCanNhap ?? 0),
            'SoNhaCungCap' => DB::table('NHA_CUNG_CAP')
                ->where('TrangThai', 'Dang hop tac')
                ->where('Email', '<>', self::SYSTEM_SUPPLIER_EMAIL)
                ->count(),
            'POChoDuyet' => DB::table('DON_DAT_HANG')
                ->where('MaCS', $branchId)
                ->where('TrangThai', 'Cho duyet')
                ->count(),
            'PhieuNhapThang' => DB::table('PHIEU_NHAP_KHO')
                ->where('MaCS', $branchId)
                ->where('TrangThai', 'Da xac nhan')
                ->whereBetween(DB::raw('DATE(NgayNhap)'), [$startOfMonth, $endOfMonth])
                ->count(),
            'PhieuXuatThang' => DB::table('PHIEU_XUAT_KHO')
                ->where('MaCS', $branchId)
                ->where('TrangThai', 'Da xuat')
                ->whereBetween(DB::raw('DATE(NgayXuat)'), [$startOfMonth, $endOfMonth])
                ->count(),
        ]);
    }

    public function ingredients(Request $request)
    {
        return response()->json($this->ingredientRows($request));
    }

    public function storeIngredient(Request $request)
    {
        $data = $request->validate([
            'TenNL' => ['required', 'string', 'max:150', Rule::unique('NGUYEN_LIEU', 'TenNL')],
            'DonViTinh' => ['required', 'string', 'max:30'],
            'TonToiThieu' => ['required', 'numeric', 'min:0'],
            'TrangThai' => ['required', Rule::in(['Dang su dung', 'Ngung su dung'])],
        ]);

        $id = DB::table('NGUYEN_LIEU')->insertGetId($data, 'MaNL');
        $this->logAction('Tao', 'NGUYEN_LIEU', "Tạo nguyên liệu #{$id}: {$data['TenNL']}.");

        return response()->json(['message' => 'Đã thêm nguyên liệu.', 'MaNL' => $id], 201);
    }

    public function updateIngredient(Request $request, int $id)
    {
        abort_unless(DB::table('NGUYEN_LIEU')->where('MaNL', $id)->exists(), 404, 'Không tìm thấy nguyên liệu.');

        $data = $request->validate([
            'TenNL' => ['required', 'string', 'max:150', Rule::unique('NGUYEN_LIEU', 'TenNL')->ignore($id, 'MaNL')],
            'DonViTinh' => ['required', 'string', 'max:30'],
            'TonToiThieu' => ['required', 'numeric', 'min:0'],
            'TrangThai' => ['required', Rule::in(['Dang su dung', 'Ngung su dung'])],
        ]);

        DB::table('NGUYEN_LIEU')->where('MaNL', $id)->update($data);
        $this->logAction('Cap nhat', 'NGUYEN_LIEU', "Cập nhật nguyên liệu #{$id}: {$data['TenNL']}.");

        return response()->json(['message' => 'Đã cập nhật nguyên liệu.']);
    }

    public function disableIngredient(int $id)
    {
        $updated = DB::table('NGUYEN_LIEU')->where('MaNL', $id)->update(['TrangThai' => 'Ngung su dung']);
        abort_unless($updated, 404, 'Không tìm thấy nguyên liệu.');
        $this->logAction('Ngung su dung', 'NGUYEN_LIEU', "Ngừng sử dụng nguyên liệu #{$id}.");

        return response()->json(['message' => 'Đã chuyển nguyên liệu sang trạng thái ngừng sử dụng.']);
    }

    public function suppliers(Request $request)
    {
        $query = DB::table('NHA_CUNG_CAP')
            ->where(function ($q) {
                $q->whereNull('Email')->orWhere('Email', '<>', self::SYSTEM_SUPPLIER_EMAIL);
            })
            ->orderBy('TenNCC');

        if ($request->filled('search')) {
            $keyword = '%' . trim((string) $request->string('search')) . '%';
            $query->where(function ($q) use ($keyword) {
                $q->where('TenNCC', 'like', $keyword)
                    ->orWhere('SDT', 'like', $keyword)
                    ->orWhere('Email', 'like', $keyword);
            });
        }

        return response()->json($query->get());
    }

    public function storeSupplier(Request $request)
    {
        $data = $request->validate([
            'TenNCC' => ['required', 'string', 'max:180'],
            'DiaChi' => ['nullable', 'string', 'max:255'],
            'SDT' => ['nullable', 'string', 'max:20'],
            'Email' => ['nullable', 'email', 'max:150', Rule::notIn([self::SYSTEM_SUPPLIER_EMAIL])],
            'TrangThai' => ['required', Rule::in(['Dang hop tac', 'Ngung hop tac'])],
        ]);

        $id = DB::table('NHA_CUNG_CAP')->insertGetId($data, 'MaNCC');
        $this->logAction('Tao', 'NHA_CUNG_CAP', "Tạo nhà cung cấp #{$id}: {$data['TenNCC']}.");

        return response()->json(['message' => 'Đã thêm nhà cung cấp.', 'MaNCC' => $id], 201);
    }

    public function updateSupplier(Request $request, int $id)
    {
        abort_unless(DB::table('NHA_CUNG_CAP')
            ->where('MaNCC', $id)
            ->where(function ($q) {
                $q->whereNull('Email')->orWhere('Email', '<>', self::SYSTEM_SUPPLIER_EMAIL);
            })
            ->exists(), 404, 'Không tìm thấy nhà cung cấp.');

        $data = $request->validate([
            'TenNCC' => ['required', 'string', 'max:180'],
            'DiaChi' => ['nullable', 'string', 'max:255'],
            'SDT' => ['nullable', 'string', 'max:20'],
            'Email' => ['nullable', 'email', 'max:150', Rule::notIn([self::SYSTEM_SUPPLIER_EMAIL])],
            'TrangThai' => ['required', Rule::in(['Dang hop tac', 'Ngung hop tac'])],
        ]);

        DB::table('NHA_CUNG_CAP')->where('MaNCC', $id)->update($data);
        $this->logAction('Cap nhat', 'NHA_CUNG_CAP', "Cập nhật nhà cung cấp #{$id}: {$data['TenNCC']}.");

        return response()->json(['message' => 'Đã cập nhật nhà cung cấp.']);
    }

    public function disableSupplier(int $id)
    {
        $supplier = DB::table('NHA_CUNG_CAP')
            ->where('MaNCC', $id)
            ->where(function ($q) {
                $q->whereNull('Email')->orWhere('Email', '<>', self::SYSTEM_SUPPLIER_EMAIL);
            })
            ->first();

        abort_unless($supplier, 404, 'Không tìm thấy nhà cung cấp.');

        DB::table('NHA_CUNG_CAP')->where('MaNCC', $id)->update(['TrangThai' => 'Ngung hop tac']);
        $this->logAction('Ngung hop tac', 'NHA_CUNG_CAP', "Ngừng hợp tác nhà cung cấp #{$id}.");

        return response()->json(['message' => 'Đã ngừng hợp tác với nhà cung cấp.']);
    }

    public function supplierHistory(int $id)
    {
        $supplier = DB::table('NHA_CUNG_CAP')
            ->where('MaNCC', $id)
            ->where(function ($q) {
                $q->whereNull('Email')->orWhere('Email', '<>', self::SYSTEM_SUPPLIER_EMAIL);
            })
            ->first();

        abort_unless($supplier, 404, 'Không tìm thấy nhà cung cấp.');

        $orders = DB::table('DON_DAT_HANG as po')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'po.MaCS')
            ->where('po.MaNCC', $id)
            ->orderByDesc('po.MaPO')
            ->get(['po.MaPO', 'po.NgayLap', 'po.TrangThai', 'po.NgayGiaoDuKien', 'cs.TenCS']);

        $receipts = DB::table('PHIEU_NHAP_KHO as pn')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'pn.MaCS')
            ->leftJoin('DON_DAT_HANG as po', 'po.MaPO', '=', 'pn.MaPO')
            ->where('pn.MaNCC', $id)
            ->orderByDesc('pn.MaPN')
            ->get(['pn.MaPN', 'pn.NgayNhap', 'pn.TrangThai', 'pn.MaPO', 'pn.ChungTuNguon', 'cs.TenCS']);

        return response()->json([
            'supplier' => $supplier,
            'purchaseOrders' => $orders,
            'receipts' => $receipts,
        ]);
    }

    private function validatePurchaseOrder(Request $request): array
    {
        $data = $request->validate([
            'MaNCC' => ['required', 'integer', 'exists:NHA_CUNG_CAP,MaNCC'],
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'DieuKienTT' => ['nullable', 'string', 'max:180'],
            'NgayGiaoDuKien' => ['nullable', 'date', 'after_or_equal:today'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.MaNL' => ['required', 'integer', 'distinct', 'exists:NGUYEN_LIEU,MaNL'],
            'items.*.SoLuong' => ['required', 'numeric', 'gt:0'],
            'items.*.DonGia' => ['required', 'numeric', 'min:0'],
        ]);

        $this->ensureActiveBranch((int) $data['MaCS']);
        $this->ensureEmployee((int) $data['MaNV'], (int) $data['MaCS']);

        $supplierActive = DB::table('NHA_CUNG_CAP')
            ->where('MaNCC', $data['MaNCC'])
            ->where('TrangThai', 'Dang hop tac')
            ->where(function ($q) {
                $q->whereNull('Email')->orWhere('Email', '<>', self::SYSTEM_SUPPLIER_EMAIL);
            })
            ->exists();

        if (!$supplierActive) {
            throw ValidationException::withMessages(['MaNCC' => 'Nhà cung cấp đang ngừng hợp tác.']);
        }

        $activeIngredientIds = DB::table('NGUYEN_LIEU')
            ->whereIn('MaNL', collect($data['items'])->pluck('MaNL'))
            ->where('TrangThai', 'Dang su dung')
            ->pluck('MaNL')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($data['items'] as $index => $item) {
            if (!in_array((int) $item['MaNL'], $activeIngredientIds, true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.MaNL" => 'Nguyên liệu không tồn tại hoặc đã ngừng sử dụng.',
                ]);
            }
        }

        return $data;
    }

    private function replacePurchaseOrderLines(int $purchaseOrderId, array $items): void
    {
        DB::table('CT_DON_DAT_HANG')->where('MaPO', $purchaseOrderId)->delete();

        foreach ($items as $item) {
            DB::table('CT_DON_DAT_HANG')->insert([
                'MaPO' => $purchaseOrderId,
                'MaNL' => $item['MaNL'],
                'SoLuong' => $item['SoLuong'],
                'DonGia' => $item['DonGia'],
            ]);
        }
    }

    private function purchaseOrderPayload(int $id): array
    {
        $order = DB::table('DON_DAT_HANG as po')
            ->join('NHA_CUNG_CAP as ncc', 'ncc.MaNCC', '=', 'po.MaNCC')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'po.MaCS')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'po.MaNV')
            ->leftJoin('NHAN_VIEN as duyet', 'duyet.MaNV', '=', 'po.MaNVDuyet')
            ->where('po.MaPO', $id)
            ->select(
                'po.*', 'ncc.TenNCC', 'cs.TenCS', 'nv.HoTen as NguoiLap',
                'duyet.HoTen as NguoiDuyet'
            )
            ->first();

        abort_unless($order, 404, 'Không tìm thấy đơn đặt hàng.');

        $items = DB::table('CT_DON_DAT_HANG as ct')
            ->join('NGUYEN_LIEU as nl', 'nl.MaNL', '=', 'ct.MaNL')
            ->where('ct.MaPO', $id)
            ->orderBy('nl.TenNL')
            ->get([
                'ct.MaCTPO', 'ct.MaNL', 'nl.TenNL', 'nl.DonViTinh',
                'ct.SoLuong', 'ct.DonGia',
            ]);

        foreach ($items as $item) {
            $item->DaNhan = (float) DB::table('CT_PHIEU_NHAP as ct')
                ->join('PHIEU_NHAP_KHO as pn', 'pn.MaPN', '=', 'ct.MaPN')
                ->where('pn.MaPO', $id)
                ->where('pn.TrangThai', 'Da xac nhan')
                ->where('ct.MaNL', $item->MaNL)
                ->sum('ct.SoLuongNhap');
            $item->ConLai = max(0, (float) $item->SoLuong - $item->DaNhan);
            $item->ThanhTien = (float) $item->SoLuong * (float) $item->DonGia;
        }

        $order->items = $items;
        $order->TongTien = $items->sum('ThanhTien');

        return (array) $order;
    }

    public function purchaseOrders(Request $request)
    {
        $query = DB::table('DON_DAT_HANG as po')
            ->join('NHA_CUNG_CAP as ncc', 'ncc.MaNCC', '=', 'po.MaNCC')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'po.MaCS')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'po.MaNV')
            ->leftJoin('NHAN_VIEN as duyet', 'duyet.MaNV', '=', 'po.MaNVDuyet')
            ->select(
                'po.*', 'ncc.TenNCC', 'cs.TenCS', 'nv.HoTen as NguoiLap',
                'duyet.HoTen as NguoiDuyet'
            )
            ->orderByDesc('po.MaPO');

        if ($request->filled('MaCS')) {
            $query->where('po.MaCS', $request->input('MaCS'));
        }
        if ($request->filled('TrangThai')) {
            $query->where('po.TrangThai', $request->input('TrangThai'));
        }

        $orders = $query->limit(100)->get();
        foreach ($orders as $order) {
            $payload = $this->purchaseOrderPayload((int) $order->MaPO);
            $order->items = $payload['items'];
            $order->TongTien = $payload['TongTien'];
        }

        return response()->json($orders);
    }

    public function storePurchaseOrder(Request $request)
    {
        $data = $this->validatePurchaseOrder($request);

        $id = DB::transaction(function () use ($data) {
            $id = DB::table('DON_DAT_HANG')->insertGetId([
                'NgayLap' => now(),
                'DieuKienTT' => $data['DieuKienTT'] ?? null,
                'NgayGiaoDuKien' => $data['NgayGiaoDuKien'] ?? null,
                'TrangThai' => 'Nhap',
                'MaNCC' => $data['MaNCC'],
                'MaCS' => $data['MaCS'],
                'MaNV' => $data['MaNV'],
                'MaNVDuyet' => null,
                'LyDoTuChoi' => null,
            ], 'MaPO');

            $this->replacePurchaseOrderLines($id, $data['items']);
            $this->logAction('Tao', 'DON_DAT_HANG', "Tạo PO #{$id} ở trạng thái Nháp.");

            return $id;
        });

        return response()->json([
            'message' => 'Đã tạo đơn đặt hàng ở trạng thái Nháp.',
            'MaPO' => $id,
        ], 201);
    }

    public function updatePurchaseOrder(Request $request, int $id)
    {
        $data = $this->validatePurchaseOrder($request);

        DB::transaction(function () use ($data, $id) {
            $order = DB::table('DON_DAT_HANG')->where('MaPO', $id)->lockForUpdate()->first();
            abort_unless($order, 404, 'Không tìm thấy đơn đặt hàng.');

            if (!in_array($order->TrangThai, ['Nhap', 'Tu choi'], true)) {
                throw ValidationException::withMessages([
                    'TrangThai' => 'Chỉ được sửa đơn đặt hàng ở trạng thái Nháp hoặc Từ chối.',
                ]);
            }

            DB::table('DON_DAT_HANG')->where('MaPO', $id)->update([
                'DieuKienTT' => $data['DieuKienTT'] ?? null,
                'NgayGiaoDuKien' => $data['NgayGiaoDuKien'] ?? null,
                'MaNCC' => $data['MaNCC'],
                'MaCS' => $data['MaCS'],
                'MaNV' => $data['MaNV'],
                'TrangThai' => 'Nhap',
                'MaNVDuyet' => null,
                'LyDoTuChoi' => null,
            ]);

            $this->replacePurchaseOrderLines($id, $data['items']);
            $this->logAction('Cap nhat', 'DON_DAT_HANG', "Cập nhật nội dung PO #{$id}.");
        });

        return response()->json(['message' => 'Đã cập nhật đơn đặt hàng.']);
    }

    public function submitPurchaseOrder(int $id)
    {
        DB::transaction(function () use ($id) {
            $order = DB::table('DON_DAT_HANG')->where('MaPO', $id)->lockForUpdate()->first();
            abort_unless($order, 404, 'Không tìm thấy đơn đặt hàng.');

            if (!in_array($order->TrangThai, ['Nhap', 'Tu choi'], true)) {
                throw ValidationException::withMessages(['TrangThai' => 'Đơn không ở trạng thái có thể trình duyệt.']);
            }

            if (!DB::table('CT_DON_DAT_HANG')->where('MaPO', $id)->exists()) {
                throw ValidationException::withMessages(['items' => 'Đơn đặt hàng chưa có nguyên liệu.']);
            }

            DB::table('DON_DAT_HANG')->where('MaPO', $id)->update([
                'TrangThai' => 'Cho duyet',
                'LyDoTuChoi' => null,
                'MaNVDuyet' => null,
            ]);

            $this->logAction('Trinh duyet', 'DON_DAT_HANG', "Trình duyệt PO #{$id}.");
        });

        return response()->json(['message' => 'Đã gửi đơn đặt hàng chờ duyệt.']);
    }

    public function approvePurchaseOrder(Request $request, int $id)
    {
        $data = $request->validate(['MaNVDuyet' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV']]);

        DB::transaction(function () use ($id, $data) {
            $order = DB::table('DON_DAT_HANG')->where('MaPO', $id)->lockForUpdate()->first();
            abort_unless($order, 404, 'Không tìm thấy đơn đặt hàng.');
            if ($order->TrangThai !== 'Cho duyet') {
                throw ValidationException::withMessages(['TrangThai' => 'Chỉ PO đang chờ duyệt mới được phê duyệt.']);
            }

            $this->ensureEmployee((int) $data['MaNVDuyet']);
            DB::table('DON_DAT_HANG')->where('MaPO', $id)->update([
                'TrangThai' => 'Da duyet',
                'MaNVDuyet' => $data['MaNVDuyet'],
                'LyDoTuChoi' => null,
            ]);
            $this->logAction('Phe duyet', 'DON_DAT_HANG', "PO #{$id} được phê duyệt bởi nhân viên #{$data['MaNVDuyet']}.");
        });

        return response()->json(['message' => 'Đã phê duyệt đơn đặt hàng.']);
    }

    public function rejectPurchaseOrder(Request $request, int $id)
    {
        $data = $request->validate([
            'MaNVDuyet' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'LyDoTuChoi' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($id, $data) {
            $order = DB::table('DON_DAT_HANG')->where('MaPO', $id)->lockForUpdate()->first();
            abort_unless($order, 404, 'Không tìm thấy đơn đặt hàng.');
            if ($order->TrangThai !== 'Cho duyet') {
                throw ValidationException::withMessages(['TrangThai' => 'Chỉ PO đang chờ duyệt mới được từ chối.']);
            }

            $this->ensureEmployee((int) $data['MaNVDuyet']);
            DB::table('DON_DAT_HANG')->where('MaPO', $id)->update([
                'TrangThai' => 'Tu choi',
                'MaNVDuyet' => $data['MaNVDuyet'],
                'LyDoTuChoi' => $data['LyDoTuChoi'],
            ]);
            $this->logAction('Tu choi', 'DON_DAT_HANG', "Từ chối PO #{$id}: {$data['LyDoTuChoi']}.");
        });

        return response()->json(['message' => 'Đã từ chối đơn đặt hàng.']);
    }

    public function sendPurchaseOrder(int $id)
    {
        DB::transaction(function () use ($id) {
            $order = DB::table('DON_DAT_HANG')->where('MaPO', $id)->lockForUpdate()->first();
            abort_unless($order, 404, 'Không tìm thấy đơn đặt hàng.');
            if ($order->TrangThai !== 'Da duyet') {
                throw ValidationException::withMessages(['TrangThai' => 'PO phải được phê duyệt trước khi gửi nhà cung cấp.']);
            }

            DB::table('DON_DAT_HANG')->where('MaPO', $id)->update(['TrangThai' => 'Da gui']);
            $this->logAction('Gui nha cung cap', 'DON_DAT_HANG', "Đã gửi PO #{$id} cho nhà cung cấp (ghi nhận nội bộ).");
        });

        return response()->json(['message' => 'Đã ghi nhận PO được gửi cho nhà cung cấp.']);
    }

    public function receipts(Request $request)
    {
        $query = DB::table('PHIEU_NHAP_KHO as pn')
            ->join('NHA_CUNG_CAP as ncc', 'ncc.MaNCC', '=', 'pn.MaNCC')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'pn.MaCS')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'pn.MaNV')
            ->leftJoin('DON_DAT_HANG as po', 'po.MaPO', '=', 'pn.MaPO')
            ->select(
                'pn.*', 'ncc.TenNCC', 'cs.TenCS', 'nv.HoTen as NguoiNhap',
                'po.TrangThai as TrangThaiPO'
            )
            ->orderByDesc('pn.MaPN');

        if ($request->filled('MaCS')) $query->where('pn.MaCS', $request->input('MaCS'));
        if ($request->filled('TrangThai')) $query->where('pn.TrangThai', $request->input('TrangThai'));

        $receipts = $query->limit(100)->get();
        foreach ($receipts as $receipt) {
            $receipt->items = DB::table('CT_PHIEU_NHAP as ct')
                ->join('NGUYEN_LIEU as nl', 'nl.MaNL', '=', 'ct.MaNL')
                ->where('ct.MaPN', $receipt->MaPN)
                ->get([
                    'ct.MaCTPN', 'ct.MaNL', 'nl.TenNL', 'nl.DonViTinh',
                    'ct.SoLuongNhap', 'ct.SoLuongCon', 'ct.DonGia',
                    'ct.HanSuDung', 'ct.ChenhLechPO',
                ]);
        }

        return response()->json($receipts);
    }

    public function storeReceipt(Request $request)
    {
        $data = $request->validate([
            'MaPO' => ['nullable', 'integer', 'exists:DON_DAT_HANG,MaPO'],
            'MaNCC' => ['required', 'integer', 'exists:NHA_CUNG_CAP,MaNCC'],
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'ChungTuNguon' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.MaNL' => ['required', 'integer', 'distinct', 'exists:NGUYEN_LIEU,MaNL'],
            'items.*.SoLuongNhap' => ['required', 'numeric', 'gt:0'],
            'items.*.DonGia' => ['required', 'numeric', 'min:0'],
            'items.*.HanSuDung' => ['nullable', 'date', 'after_or_equal:today'],
            'items.*.DatChatLuong' => ['required', 'boolean'],
        ]);

        $this->ensureActiveBranch((int) $data['MaCS']);
        $this->ensureEmployee((int) $data['MaNV'], (int) $data['MaCS']);

        if (empty($data['MaPO']) && empty($data['ChungTuNguon'])) {
            throw ValidationException::withMessages([
                'ChungTuNguon' => 'Phiếu nhập không có PO phải kèm chứng từ giao hàng/nguồn nhập.',
            ]);
        }

        $activeIngredientIds = DB::table('NGUYEN_LIEU')
            ->whereIn('MaNL', collect($data['items'])->pluck('MaNL'))
            ->where('TrangThai', 'Dang su dung')
            ->pluck('MaNL')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($data['items'] as $index => $item) {
            if (!in_array((int) $item['MaNL'], $activeIngredientIds, true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.MaNL" => 'Không thể nhập nguyên liệu đã ngừng sử dụng.',
                ]);
            }
            if (!$item['DatChatLuong']) {
                throw ValidationException::withMessages([
                    "items.{$index}.DatChatLuong" => 'Hàng không đạt chất lượng phải được loại trước khi lập phiếu nhập.',
                ]);
            }
        }

        $id = DB::transaction(function () use ($data) {
            $po = null;
            if (!empty($data['MaPO'])) {
                $po = DB::table('DON_DAT_HANG')->where('MaPO', $data['MaPO'])->lockForUpdate()->first();
                if (!$po || !in_array($po->TrangThai, ['Da gui', 'Da nhan mot phan'], true)) {
                    throw ValidationException::withMessages(['MaPO' => 'Chỉ nhận hàng từ PO đã gửi hoặc đang nhận một phần.']);
                }
                if ((int) $po->MaNCC !== (int) $data['MaNCC'] || (int) $po->MaCS !== (int) $data['MaCS']) {
                    throw ValidationException::withMessages(['MaPO' => 'Nhà cung cấp hoặc cơ sở không khớp với PO.']);
                }
            } else {
                $supplierActive = DB::table('NHA_CUNG_CAP')
                    ->where('MaNCC', $data['MaNCC'])
                    ->where('TrangThai', 'Dang hop tac')
                    ->where(function ($q) {
                        $q->whereNull('Email')->orWhere('Email', '<>', self::SYSTEM_SUPPLIER_EMAIL);
                    })
                    ->exists();
                if (!$supplierActive) {
                    throw ValidationException::withMessages(['MaNCC' => 'Nhà cung cấp đang ngừng hợp tác.']);
                }
            }

            $acceptedItems = [];
            foreach ($data['items'] as $index => $item) {
                $acceptedQuantity = (float) $item['SoLuongNhap'];
                $difference = 0;

                if ($po) {
                    $poLine = DB::table('CT_DON_DAT_HANG')
                        ->where('MaPO', $po->MaPO)
                        ->where('MaNL', $item['MaNL'])
                        ->first();

                    if (!$poLine) {
                        throw ValidationException::withMessages([
                            "items.{$index}.MaNL" => 'Nguyên liệu không thuộc đơn đặt hàng này.',
                        ]);
                    }

                    $alreadyReceived = (float) DB::table('CT_PHIEU_NHAP as ct')
                        ->join('PHIEU_NHAP_KHO as pn', 'pn.MaPN', '=', 'ct.MaPN')
                        ->where('pn.MaPO', $po->MaPO)
                        ->where('pn.TrangThai', 'Da xac nhan')
                        ->where('ct.MaNL', $item['MaNL'])
                        ->sum('ct.SoLuongNhap');

                    $remaining = max(0, (float) $poLine->SoLuong - $alreadyReceived);
                    if ($remaining <= 0) {
                        throw ValidationException::withMessages([
                            "items.{$index}.SoLuongNhap" => 'Nguyên liệu này đã nhận đủ theo PO.',
                        ]);
                    }

                    $acceptedQuantity = min((float) $item['SoLuongNhap'], $remaining);
                    $difference = (float) $item['SoLuongNhap'] - $remaining;
                }

                if ($acceptedQuantity > 0) {
                    $acceptedItems[] = [
                        'MaNL' => $item['MaNL'],
                        'SoLuongNhap' => $acceptedQuantity,
                        'DonGia' => $item['DonGia'],
                        'HanSuDung' => $item['HanSuDung'] ?? null,
                        'ChenhLechPO' => $difference,
                    ];
                }
            }

            if (!$acceptedItems) {
                throw ValidationException::withMessages(['items' => 'Không có số lượng hợp lệ để nhập kho.']);
            }

            $receiptId = DB::table('PHIEU_NHAP_KHO')->insertGetId([
                'NgayNhap' => now(),
                'TrangThai' => 'Nhap nhap',
                'MaPO' => $po?->MaPO,
                'MaNCC' => $data['MaNCC'],
                'MaNV' => $data['MaNV'],
                'MaCS' => $data['MaCS'],
                'ChungTuNguon' => $data['ChungTuNguon'] ?? null,
            ], 'MaPN');

            foreach ($acceptedItems as $item) {
                DB::table('CT_PHIEU_NHAP')->insert([
                    'MaPN' => $receiptId,
                    'MaNL' => $item['MaNL'],
                    'SoLuongNhap' => $item['SoLuongNhap'],
                    'SoLuongCon' => 0,
                    'DonGia' => $item['DonGia'],
                    'HanSuDung' => $item['HanSuDung'],
                    'ChenhLechPO' => $item['ChenhLechPO'],
                ]);
            }

            $this->logAction('Lap phieu nhap', 'PHIEU_NHAP_KHO', "Tạo phiếu nhập #{$receiptId} ở trạng thái Nháp.");
            return $receiptId;
        }, attempts: 3);

        return response()->json([
            'message' => 'Đã lập phiếu nhập ở trạng thái Nháp. Xác nhận phiếu để cập nhật tồn kho.',
            'MaPN' => $id,
        ], 201);
    }

    public function confirmReceipt(int $id)
    {
        DB::transaction(function () use ($id) {
            $receipt = DB::table('PHIEU_NHAP_KHO')->where('MaPN', $id)->lockForUpdate()->first();
            abort_unless($receipt, 404, 'Không tìm thấy phiếu nhập.');

            if ($receipt->TrangThai !== 'Nhap nhap') {
                throw ValidationException::withMessages(['TrangThai' => 'Phiếu nhập không ở trạng thái Nháp.']);
            }

            $items = DB::table('CT_PHIEU_NHAP')->where('MaPN', $id)->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Phiếu nhập chưa có nguyên liệu.']);
            }

            foreach ($items as $item) {
                if ($item->HanSuDung && $item->HanSuDung < now()->toDateString()) {
                    throw ValidationException::withMessages(['HanSuDung' => 'Có lô hàng đã quá hạn, không thể xác nhận nhập kho.']);
                }
                DB::table('CT_PHIEU_NHAP')->where('MaCTPN', $item->MaCTPN)->update([
                    'SoLuongCon' => $item->SoLuongNhap,
                ]);
            }

            DB::table('PHIEU_NHAP_KHO')->where('MaPN', $id)->update(['TrangThai' => 'Da xac nhan']);

            if ($receipt->MaPO) {
                $poLines = DB::table('CT_DON_DAT_HANG')->where('MaPO', $receipt->MaPO)->get();
                $allReceived = $poLines->isNotEmpty();
                $anyReceived = false;

                foreach ($poLines as $line) {
                    $received = (float) DB::table('CT_PHIEU_NHAP as ct')
                        ->join('PHIEU_NHAP_KHO as pn', 'pn.MaPN', '=', 'ct.MaPN')
                        ->where('pn.MaPO', $receipt->MaPO)
                        ->where('pn.TrangThai', 'Da xac nhan')
                        ->where('ct.MaNL', $line->MaNL)
                        ->sum('ct.SoLuongNhap');

                    if ($received > 0) $anyReceived = true;
                    if ($received + 0.0001 < (float) $line->SoLuong) $allReceived = false;
                }

                $poStatus = $allReceived ? 'Da nhan du' : ($anyReceived ? 'Da nhan mot phan' : 'Da gui');
                DB::table('DON_DAT_HANG')->where('MaPO', $receipt->MaPO)->update(['TrangThai' => $poStatus]);
            }

            $this->logAction('Xac nhan nhap kho', 'PHIEU_NHAP_KHO', "Xác nhận phiếu nhập #{$id}; tồn kho được cập nhật.");
        }, attempts: 3);

        return response()->json(['message' => 'Đã xác nhận phiếu nhập và cập nhật tồn kho.']);
    }

    public function cancelReceipt(int $id)
    {
        DB::transaction(function () use ($id) {
            $receipt = DB::table('PHIEU_NHAP_KHO')->where('MaPN', $id)->lockForUpdate()->first();
            abort_unless($receipt, 404, 'Không tìm thấy phiếu nhập.');
            if ($receipt->TrangThai !== 'Nhap nhap') {
                throw ValidationException::withMessages(['TrangThai' => 'Chỉ được hủy phiếu nhập đang ở trạng thái Nháp.']);
            }

            DB::table('PHIEU_NHAP_KHO')->where('MaPN', $id)->update(['TrangThai' => 'Da huy']);
            $this->logAction('Huy phieu nhap', 'PHIEU_NHAP_KHO', "Hủy phiếu nhập nháp #{$id}.");
        });

        return response()->json(['message' => 'Đã hủy phiếu nhập nháp.']);
    }

    public function issues(Request $request)
    {
        $query = DB::table('PHIEU_XUAT_KHO as px')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'px.MaCS')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'px.MaNV')
            ->leftJoin('NHAN_VIEN as duyet', 'duyet.MaNV', '=', 'px.MaNVDuyet')
            ->select('px.*', 'cs.TenCS', 'nv.HoTen as NguoiLap', 'duyet.HoTen as NguoiDuyet')
            ->orderByDesc('px.MaPX');

        if ($request->filled('MaCS')) $query->where('px.MaCS', $request->input('MaCS'));

        $issues = $query->limit(100)->get();
        foreach ($issues as $issue) {
            $issue->items = DB::table('CT_PHIEU_XUAT as cx')
                ->join('CT_PHIEU_NHAP as cn', 'cn.MaCTPN', '=', 'cx.MaCTPN')
                ->join('NGUYEN_LIEU as nl', 'nl.MaNL', '=', 'cn.MaNL')
                ->where('cx.MaPX', $issue->MaPX)
                ->get([
                    'cx.MaCTPX', 'cx.SoLuongXuat', 'cn.MaNL', 'nl.TenNL',
                    'nl.DonViTinh', 'cn.DonGia', 'cn.HanSuDung',
                ]);
        }

        return response()->json($issues);
    }

    public function storeIssue(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'BoPhanNhan' => ['required', Rule::in(['Bep', 'Bar'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.MaNL' => ['required', 'integer', 'distinct', 'exists:NGUYEN_LIEU,MaNL'],
            'items.*.SoLuongXuat' => ['required', 'numeric', 'gt:0'],
        ]);

        $this->ensureActiveBranch((int) $data['MaCS']);
        $this->ensureEmployee((int) $data['MaNV'], (int) $data['MaCS']);

        $id = DB::transaction(function () use ($data) {
            $issueId = DB::table('PHIEU_XUAT_KHO')->insertGetId([
                'NgayXuat' => now(),
                'BoPhanNhan' => $data['BoPhanNhan'],
                'TrangThai' => 'Cho xac nhan',
                'MaCS' => $data['MaCS'],
                'MaNVDuyet' => null,
                'MaNV' => $data['MaNV'],
            ], 'MaPX');

            foreach ($data['items'] as $index => $item) {
                $ingredient = DB::table('NGUYEN_LIEU')->where('MaNL', $item['MaNL'])->first();
                if (!$ingredient || $ingredient->TrangThai !== 'Dang su dung') {
                    throw ValidationException::withMessages([
                        "items.{$index}.MaNL" => 'Nguyên liệu đã ngừng sử dụng.',
                    ]);
                }

                $remaining = (float) $item['SoLuongXuat'];
                $lots = DB::table('CT_PHIEU_NHAP as ct')
                    ->join('PHIEU_NHAP_KHO as pn', 'pn.MaPN', '=', 'ct.MaPN')
                    ->where('pn.MaCS', $data['MaCS'])
                    ->where('pn.TrangThai', 'Da xac nhan')
                    ->where('ct.MaNL', $item['MaNL'])
                    ->where('ct.SoLuongCon', '>', 0)
                    ->where(function ($q) {
                        $q->whereNull('ct.HanSuDung')
                            ->orWhereDate('ct.HanSuDung', '>=', now()->toDateString());
                    })
                    ->orderByRaw('DATE(pn.NgayNhap) ASC')
                    ->orderByRaw('CASE WHEN ct.HanSuDung IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('ct.HanSuDung')
                    ->orderBy('pn.NgayNhap')
                    ->orderBy('ct.MaCTPN')
                    ->lockForUpdate()
                    ->get(['ct.MaCTPN', 'ct.SoLuongCon']);

                $available = (float) $lots->sum('SoLuongCon');
                if ($available + 0.0001 < $remaining) {
                    throw ValidationException::withMessages([
                        "items.{$index}.SoLuongXuat" => "Không đủ tồn khả dụng cho {$ingredient->TenNL}. Còn {$available} {$ingredient->DonViTinh}.",
                    ]);
                }

                foreach ($lots as $lot) {
                    if ($remaining <= 0.0001) break;
                    $take = min($remaining, (float) $lot->SoLuongCon);

                    DB::table('CT_PHIEU_NHAP')->where('MaCTPN', $lot->MaCTPN)->update([
                        'SoLuongCon' => (float) $lot->SoLuongCon - $take,
                    ]);
                    DB::table('CT_PHIEU_XUAT')->insert([
                        'MaPX' => $issueId,
                        'MaCTPN' => $lot->MaCTPN,
                        'SoLuongXuat' => $take,
                    ]);

                    $remaining -= $take;
                }
            }

            DB::table('PHIEU_XUAT_KHO')->where('MaPX', $issueId)->update([
                'TrangThai' => 'Da xuat',
                'MaNVDuyet' => $data['MaNV'],
            ]);

            $this->logAction('Xuat kho FIFO', 'PHIEU_XUAT_KHO', "Tạo và xác nhận phiếu xuất #{$issueId} cho bộ phận {$data['BoPhanNhan']}.");
            return $issueId;
        }, attempts: 3);

        return response()->json([
            'message' => 'Đã xuất kho theo FIFO và cập nhật số lượng từng lô.',
            'MaPX' => $id,
        ], 201);
    }

    public function stock(Request $request)
    {
        return response()->json($this->ingredientRows($request));
    }

    public function stocktakes(Request $request)
    {
        $query = DB::table('PHIEU_KIEM_KE as kk')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'kk.MaCS')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'kk.MaNV')
            ->where('kk.MaCS', $this->branchId($request))
            ->orderByDesc('kk.MaKK');

        $rows = $query->get(['kk.*', 'cs.TenCS', 'nv.HoTen as NguoiLap']);
        foreach ($rows as $row) {
            $row->items = DB::table('CT_PHIEU_KIEM_KE as ct')
                ->join('NGUYEN_LIEU as nl', 'nl.MaNL', '=', 'ct.MaNL')
                ->where('ct.MaKK', $row->MaKK)
                ->get([
                    'ct.MaCTKK', 'ct.MaNL', 'nl.TenNL', 'nl.DonViTinh',
                    'ct.SoLuongSoSach', 'ct.SoLuongThucTe', 'ct.ChenhLech',
                ]);
        }

        return response()->json($rows);
    }

    public function storeStocktake(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.MaNL' => ['required', 'integer', 'distinct', 'exists:NGUYEN_LIEU,MaNL'],
            'items.*.SoLuongThucTe' => ['required', 'numeric', 'min:0'],
        ]);

        $this->ensureActiveBranch((int) $data['MaCS']);
        $this->ensureEmployee((int) $data['MaNV'], (int) $data['MaCS']);

        $id = DB::transaction(function () use ($data) {
            $countId = DB::table('PHIEU_KIEM_KE')->insertGetId([
                'NgayKK' => now(),
                'TrangThai' => 'Nhap',
                'MaCS' => $data['MaCS'],
                'MaNV' => $data['MaNV'],
            ], 'MaKK');

            foreach ($data['items'] as $item) {
                $book = $this->stockForIngredient((int) $data['MaCS'], (int) $item['MaNL']);
                $actual = (float) $item['SoLuongThucTe'];
                DB::table('CT_PHIEU_KIEM_KE')->insert([
                    'MaKK' => $countId,
                    'MaNL' => $item['MaNL'],
                    'SoLuongSoSach' => $book,
                    'SoLuongThucTe' => $actual,
                    'ChenhLech' => $actual - $book,
                ]);
            }

            $this->logAction('Lap kiem ke', 'PHIEU_KIEM_KE', "Tạo phiếu kiểm kê #{$countId} ở trạng thái Nháp.");
            return $countId;
        });

        return response()->json([
            'message' => 'Đã lập phiếu kiểm kê nháp. Xác nhận để điều chỉnh tồn kho theo số thực tế.',
            'MaKK' => $id,
        ], 201);
    }

    private function systemSupplierId(): int
    {
        $id = DB::table('NHA_CUNG_CAP')->where('Email', self::SYSTEM_SUPPLIER_EMAIL)->value('MaNCC');
        if ($id) return (int) $id;

        return (int) DB::table('NHA_CUNG_CAP')->insertGetId([
            'TenNCC' => 'Điều chỉnh kiểm kê hệ thống',
            'DiaChi' => 'Nhà cung cấp nội bộ, không dùng cho giao dịch mua hàng',
            'SDT' => null,
            'Email' => self::SYSTEM_SUPPLIER_EMAIL,
            'TrangThai' => 'Ngung hop tac',
        ], 'MaNCC');
    }

    public function confirmStocktake(int $id)
    {
        DB::transaction(function () use ($id) {
            $count = DB::table('PHIEU_KIEM_KE')->where('MaKK', $id)->lockForUpdate()->first();
            abort_unless($count, 404, 'Không tìm thấy phiếu kiểm kê.');
            if ($count->TrangThai !== 'Nhap') {
                throw ValidationException::withMessages(['TrangThai' => 'Phiếu kiểm kê không ở trạng thái Nháp.']);
            }

            $items = DB::table('CT_PHIEU_KIEM_KE')->where('MaKK', $id)->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Phiếu kiểm kê chưa có nguyên liệu.']);
            }

            $positiveAdjustments = [];

            foreach ($items as $item) {
                $lots = DB::table('CT_PHIEU_NHAP as ct')
                    ->join('PHIEU_NHAP_KHO as pn', 'pn.MaPN', '=', 'ct.MaPN')
                    ->where('pn.MaCS', $count->MaCS)
                    ->where('pn.TrangThai', 'Da xac nhan')
                    ->where('ct.MaNL', $item->MaNL)
                    ->where('ct.SoLuongCon', '>', 0)
                    ->orderByDesc('pn.NgayNhap')
                    ->orderByDesc('ct.MaCTPN')
                    ->lockForUpdate()
                    ->get(['ct.MaCTPN', 'ct.SoLuongCon', 'ct.DonGia']);

                $book = (float) $lots->sum('SoLuongCon');
                $actual = (float) $item->SoLuongThucTe;
                $difference = $actual - $book;

                DB::table('CT_PHIEU_KIEM_KE')->where('MaCTKK', $item->MaCTKK)->update([
                    'SoLuongSoSach' => $book,
                    'ChenhLech' => $difference,
                ]);

                if ($difference < -0.0001) {
                    $toReduce = abs($difference);
                    foreach ($lots as $lot) {
                        if ($toReduce <= 0.0001) break;
                        $reduce = min($toReduce, (float) $lot->SoLuongCon);
                        DB::table('CT_PHIEU_NHAP')->where('MaCTPN', $lot->MaCTPN)->update([
                            'SoLuongCon' => (float) $lot->SoLuongCon - $reduce,
                        ]);
                        $toReduce -= $reduce;
                    }
                    if ($toReduce > 0.0001) {
                        throw ValidationException::withMessages(['SoLuongThucTe' => 'Không thể điều chỉnh giảm do tồn kho đã thay đổi.']);
                    }
                } elseif ($difference > 0.0001) {
                    $totalValue = 0;
                    foreach ($lots as $lot) {
                        $totalValue += (float) $lot->SoLuongCon * (float) $lot->DonGia;
                    }
                    $averageCost = $book > 0 ? $totalValue / $book : 0;
                    $positiveAdjustments[] = [
                        'MaNL' => $item->MaNL,
                        'SoLuong' => $difference,
                        'DonGia' => $averageCost,
                    ];
                }
            }

            if ($positiveAdjustments) {
                $supplierId = $this->systemSupplierId();
                $receiptId = DB::table('PHIEU_NHAP_KHO')->insertGetId([
                    'NgayNhap' => now(),
                    'TrangThai' => 'Da xac nhan',
                    'MaPO' => null,
                    'MaNCC' => $supplierId,
                    'MaNV' => $count->MaNV,
                    'MaCS' => $count->MaCS,
                    'ChungTuNguon' => "Điều chỉnh tăng theo phiếu kiểm kê #{$id}",
                ], 'MaPN');

                foreach ($positiveAdjustments as $adjustment) {
                    DB::table('CT_PHIEU_NHAP')->insert([
                        'MaPN' => $receiptId,
                        'MaNL' => $adjustment['MaNL'],
                        'SoLuongNhap' => $adjustment['SoLuong'],
                        'SoLuongCon' => $adjustment['SoLuong'],
                        'DonGia' => $adjustment['DonGia'],
                        'HanSuDung' => null,
                        'ChenhLechPO' => 0,
                    ]);
                }
            }

            DB::table('PHIEU_KIEM_KE')->where('MaKK', $id)->update(['TrangThai' => 'Da xac nhan']);
            $this->logAction('Xac nhan kiem ke', 'PHIEU_KIEM_KE', "Xác nhận phiếu kiểm kê #{$id}; đã điều chỉnh tăng/giảm tồn theo số thực tế.");
        }, attempts: 3);

        return response()->json(['message' => 'Đã xác nhận kiểm kê và cập nhật số lượng tồn kho.']);
    }

    public function recipes(Request $request)
    {
        $query = DB::table('MON as m')
            ->join('DANH_MUC_MON as dm', 'dm.MaDM', '=', 'm.MaDM')
            ->where('m.TrangThai', 'Dang ban')
            ->orderBy('dm.TenDM')
            ->orderBy('m.TenMon');

        if ($request->filled('MaMon')) $query->where('m.MaMon', $request->input('MaMon'));

        $items = $query->get(['m.MaMon', 'm.TenMon', 'm.DonViTinh', 'm.MaDM', 'dm.TenDM', 'dm.NoiCheBien']);
        foreach ($items as $item) {
            $item->ingredients = DB::table('DINH_MUC as dm')
                ->join('NGUYEN_LIEU as nl', 'nl.MaNL', '=', 'dm.MaNL')
                ->where('dm.MaMon', $item->MaMon)
                ->orderBy('nl.TenNL')
                ->get([
                    'dm.MaNL', 'nl.TenNL', 'nl.DonViTinh as DonViGoc',
                    'dm.DinhLuong', 'dm.DonViTinh',
                ]);
        }

        return response()->json($items);
    }

    public function saveRecipe(Request $request, int $menuItemId)
    {
        abort_unless(DB::table('MON')->where('MaMon', $menuItemId)->exists(), 404, 'Không tìm thấy món.');

        $data = $request->validate([
            'items' => ['present', 'array'],
            'items.*.MaNL' => ['required', 'integer', 'distinct', 'exists:NGUYEN_LIEU,MaNL'],
            'items.*.DinhLuong' => ['required', 'numeric', 'gt:0'],
            'items.*.DonViTinh' => ['required', 'string', 'max:30'],
        ]);

        DB::transaction(function () use ($data, $menuItemId) {
            foreach ($data['items'] as $index => $item) {
                $active = DB::table('NGUYEN_LIEU')
                    ->where('MaNL', $item['MaNL'])
                    ->where('TrangThai', 'Dang su dung')
                    ->exists();
                if (!$active) {
                    throw ValidationException::withMessages([
                        "items.{$index}.MaNL" => 'Không thể dùng nguyên liệu đã ngừng sử dụng trong định mức mới.',
                    ]);
                }
            }

            DB::table('DINH_MUC')->where('MaMon', $menuItemId)->delete();
            foreach ($data['items'] as $item) {
                DB::table('DINH_MUC')->insert([
                    'MaMon' => $menuItemId,
                    'MaNL' => $item['MaNL'],
                    'DinhLuong' => $item['DinhLuong'],
                    'DonViTinh' => $item['DonViTinh'],
                ]);
            }

            $this->logAction('Cap nhat dinh muc', 'DINH_MUC', "Cập nhật công thức định mức cho món #{$menuItemId}.");
        });

        return response()->json(['message' => 'Đã lưu công thức và định mức nguyên liệu.']);
    }
}
