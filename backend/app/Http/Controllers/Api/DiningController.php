<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DiningController extends Controller
{
    public function areas(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS'],
            'TrangThai' => ['nullable', Rule::in(['Dang hoat dong', 'Ngung hoat dong'])],
        ]);

        $query = DB::table('KHU_VUC as kv')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'kv.MaCS')
            ->leftJoin('BAN as b', 'b.MaKV', '=', 'kv.MaKV')
            ->select(
                'kv.MaKV', 'kv.TenKV', 'kv.MaCS', 'kv.TrangThai',
                'cs.TenCS', DB::raw('COUNT(b.MaBan) as SoLuongBan')
            )
            ->groupBy('kv.MaKV', 'kv.TenKV', 'kv.MaCS', 'kv.TrangThai', 'cs.TenCS')
            ->orderBy('kv.MaKV');

        if (!empty($data['MaCS'])) $query->where('kv.MaCS', $data['MaCS']);
        if (!empty($data['TrangThai'])) $query->where('kv.TrangThai', $data['TrangThai']);

        return response()->json($query->get());
    }

    public function storeArea(Request $request)
    {
        $data = $request->validate([
            'TenKV' => ['required', 'string', 'max:120'],
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'TrangThai' => ['nullable', Rule::in(['Dang hoat dong', 'Ngung hoat dong'])],
        ]);

        $exists = DB::table('KHU_VUC')
            ->where('MaCS', $data['MaCS'])
            ->whereRaw('LOWER(TenKV) = ?', [mb_strtolower(trim($data['TenKV']))])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['TenKV' => 'Tên khu vực đã tồn tại tại cơ sở này.']);
        }

        $id = DB::table('KHU_VUC')->insertGetId([
            'TenKV' => trim($data['TenKV']),
            'MaCS' => $data['MaCS'],
            'TrangThai' => $data['TrangThai'] ?? 'Dang hoat dong',
        ], 'MaKV');

        return response()->json(['message' => 'Đã tạo khu vực.', 'MaKV' => $id], 201);
    }

    public function updateArea(Request $request, int $id)
    {
        $area = DB::table('KHU_VUC')->where('MaKV', $id)->first();
        abort_unless($area, 404, 'Không tìm thấy khu vực.');

        $data = $request->validate([
            'TenKV' => ['required', 'string', 'max:120'],
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'TrangThai' => ['required', Rule::in(['Dang hoat dong', 'Ngung hoat dong'])],
        ]);

        $exists = DB::table('KHU_VUC')
            ->where('MaCS', $data['MaCS'])
            ->where('MaKV', '<>', $id)
            ->whereRaw('LOWER(TenKV) = ?', [mb_strtolower(trim($data['TenKV']))])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['TenKV' => 'Tên khu vực đã tồn tại tại cơ sở này.']);
        }

        DB::table('KHU_VUC')->where('MaKV', $id)->update([
            'TenKV' => trim($data['TenKV']),
            'MaCS' => $data['MaCS'],
            'TrangThai' => $data['TrangThai'],
        ]);

        return response()->json(['message' => 'Đã cập nhật khu vực.']);
    }

    public function destroyArea(int $id)
    {
        $area = DB::table('KHU_VUC')->where('MaKV', $id)->first();
        abort_unless($area, 404, 'Không tìm thấy khu vực.');

        if (DB::table('BAN')->where('MaKV', $id)->exists()) {
            DB::table('KHU_VUC')->where('MaKV', $id)->update(['TrangThai' => 'Ngung hoat dong']);
            return response()->json(['message' => 'Khu vực đã có bàn nên được chuyển sang ngừng hoạt động, không xóa dữ liệu.']);
        }

        DB::table('KHU_VUC')->where('MaKV', $id)->delete();
        return response()->json(['message' => 'Đã xóa khu vực.']);
    }

    public function tables(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS'],
            'MaKV' => ['nullable', 'integer', 'exists:KHU_VUC,MaKV'],
            'TrangThai' => ['nullable', Rule::in(['Trong', 'Dang giu cho', 'Dang phuc vu', 'Ngung su dung'])],
            'search' => ['nullable', 'string', 'max:60'],
        ]);

        $query = DB::table('BAN as b')
            ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'kv.MaCS')
            ->select('b.MaBan', 'b.SoBan', 'b.SoCho', 'b.TrangThai', 'b.MaKV', 'kv.TenKV', 'kv.MaCS', 'cs.TenCS')
            ->orderBy('b.MaBan');

        if (!empty($data['MaCS'])) $query->where('kv.MaCS', $data['MaCS']);
        if (!empty($data['MaKV'])) $query->where('b.MaKV', $data['MaKV']);
        if (!empty($data['TrangThai'])) $query->where('b.TrangThai', $data['TrangThai']);
        if (!empty($data['search'])) $query->where('b.SoBan', 'like', '%' . trim($data['search']) . '%');

        return response()->json($query->get());
    }

    public function storeTable(Request $request)
    {
        $data = $request->validate([
            'SoBan' => ['required', 'string', 'max:30'],
            'SoCho' => ['required', 'integer', 'min:1', 'max:1000'],
            'MaKV' => ['required', 'integer', 'exists:KHU_VUC,MaKV'],
            'TrangThai' => ['nullable', Rule::in(['Trong', 'Dang giu cho', 'Dang phuc vu', 'Ngung su dung'])],
        ]);

        $area = DB::table('KHU_VUC')->where('MaKV', $data['MaKV'])->first();
        if (!$area || $area->TrangThai !== 'Dang hoat dong') {
            throw ValidationException::withMessages(['MaKV' => 'Khu vực không hoạt động.']);
        }

        $exists = DB::table('BAN')->where('MaKV', $data['MaKV'])->where('SoBan', trim($data['SoBan']))->exists();
        if ($exists) throw ValidationException::withMessages(['SoBan' => 'Số bàn đã tồn tại trong khu vực này.']);

        $id = DB::table('BAN')->insertGetId([
            'SoBan' => trim($data['SoBan']),
            'SoCho' => $data['SoCho'],
            'MaKV' => $data['MaKV'],
            'TrangThai' => $data['TrangThai'] ?? 'Trong',
        ], 'MaBan');

        return response()->json(['message' => 'Đã tạo bàn.', 'MaBan' => $id], 201);
    }

    public function updateTable(Request $request, int $id)
    {
        $table = DB::table('BAN')->where('MaBan', $id)->first();
        abort_unless($table, 404, 'Không tìm thấy bàn.');

        $data = $request->validate([
            'SoBan' => ['required', 'string', 'max:30'],
            'SoCho' => ['required', 'integer', 'min:1', 'max:1000'],
            'MaKV' => ['required', 'integer', 'exists:KHU_VUC,MaKV'],
            'TrangThai' => ['required', Rule::in(['Trong', 'Dang giu cho', 'Dang phuc vu', 'Ngung su dung'])],
        ]);

        $exists = DB::table('BAN')->where('MaKV', $data['MaKV'])->where('SoBan', trim($data['SoBan']))->where('MaBan', '<>', $id)->exists();
        if ($exists) throw ValidationException::withMessages(['SoBan' => 'Số bàn đã tồn tại trong khu vực này.']);

        DB::table('BAN')->where('MaBan', $id)->update([
            'SoBan' => trim($data['SoBan']),
            'SoCho' => $data['SoCho'],
            'MaKV' => $data['MaKV'],
            'TrangThai' => $data['TrangThai'],
        ]);

        return response()->json(['message' => 'Đã cập nhật bàn.']);
    }

    public function destroyTable(int $id)
    {
        $table = DB::table('BAN')->where('MaBan', $id)->first();
        abort_unless($table, 404, 'Không tìm thấy bàn.');

        $hasOpenOrder = DB::table('PHIEU_ORDER')->where('MaBan', $id)->whereIn('TrangThai', ['Dang mo', 'Da gui', 'Dang thanh toan'])->exists();
        if ($hasOpenOrder) {
            throw ValidationException::withMessages(['MaBan' => 'Bàn đang có order hoạt động, không thể ngừng sử dụng.']);
        }

        DB::table('BAN')->where('MaBan', $id)->update(['TrangThai' => 'Ngung su dung']);
        return response()->json(['message' => 'Đã chuyển bàn sang trạng thái ngừng sử dụng.']);
    }

    public function availableTables(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'ThoiGianDen' => ['required', 'date'],
            'ThoiGianKetThucDuKien' => ['required', 'date', 'after:ThoiGianDen'],
            'SoNguoi' => ['required', 'integer', 'min:1'],
            'MaDatBan' => ['nullable', 'integer', 'exists:DAT_BAN,MaDatBan'],
        ]);

        $query = DB::table('BAN as b')
            ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
            ->where('kv.MaCS', $data['MaCS'])
            ->where('kv.TrangThai', 'Dang hoat dong')
            ->where('b.TrangThai', '<>', 'Ngung su dung')
            ->where('b.SoCho', '>=', $data['SoNguoi'])
            ->whereNotExists(function ($sub) use ($data) {
                $sub->selectRaw('1')
                    ->from('DAT_BAN_BAN as dbb')
                    ->join('DAT_BAN as db', 'db.MaDatBan', '=', 'dbb.MaDatBan')
                    ->whereColumn('dbb.MaBan', 'b.MaBan')
                    ->whereNotIn('db.TrangThai', ['Da huy', 'Hoan tat'])
                    ->where('db.ThoiGianDen', '<', $data['ThoiGianKetThucDuKien'])
                    ->where('db.ThoiGianKetThucDuKien', '>', $data['ThoiGianDen']);
                if (!empty($data['MaDatBan'])) $sub->where('db.MaDatBan', '<>', $data['MaDatBan']);
            })
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')->from('PHIEU_ORDER as po')
                    ->whereColumn('po.MaBan', 'b.MaBan')
                    ->whereIn('po.TrangThai', ['Dang mo', 'Da gui', 'Dang thanh toan']);
            })
            ->select('b.MaBan', 'b.SoBan', 'b.SoCho', 'b.TrangThai', 'b.MaKV', 'kv.TenKV', 'kv.MaCS')
            ->orderBy('b.MaBan');

        return response()->json($query->get());
    }

    public function employees(Request $request)
    {
        $data = $request->validate(['MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS']]);
        return response()->json(DB::table('NHAN_VIEN')
            ->where('MaCS', $data['MaCS'])
            ->where('TrangThai', 'Dang lam')
            ->orderBy('MaNV')
            ->get(['MaNV', 'HoTen', 'SDT', 'MaCS']));
    }

    public function quickStoreEmployee(Request $request)
    {
        $data = $request->validate([
            'HoTen' => ['required', 'string', 'max:150'],
            'SDT' => ['nullable', 'string', 'max:20'],
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
        ]);

        $id = DB::table('NHAN_VIEN')->insertGetId([
            'HoTen' => trim($data['HoTen']),
            'SDT' => $data['SDT'] ?? null,
            'MaCS' => $data['MaCS'],
            'NgayVaoLam' => now()->toDateString(),
            'TrangThai' => 'Dang lam',
            'MaVT' => null,
        ], 'MaNV');

        return response()->json(DB::table('NHAN_VIEN')->where('MaNV', $id)->first(['MaNV', 'HoTen', 'SDT', 'MaCS']), 201);
    }

    public function customers(Request $request)
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:150']]);
        $query = DB::table('KHACH_HANG')->select('MaKH', 'HoTen', 'SDT', 'GhiChu')->orderBy('MaKH');
        if (!empty($data['search'])) {
            $term = trim($data['search']);
            $query->where(function ($q) use ($term) {
                $q->where('HoTen', 'like', "%{$term}%")->orWhere('SDT', 'like', "%{$term}%");
            });
        }
        return response()->json($query->limit(50)->get());
    }

    private function reservationQuery()
    {
        return DB::table('DAT_BAN as db')
            ->leftJoin('KHACH_HANG as kh', 'kh.MaKH', '=', 'db.MaKH')
            ->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 'db.MaNV')
            ->select(
                'db.MaDatBan', 'db.ThoiGianDen', 'db.ThoiGianKetThucDuKien',
                'db.SoNguoi', 'db.YeuCauDacBiet', 'db.TrangThai', 'db.GhiChu',
                'db.MaKH', 'db.MaNV', 'kh.HoTen', 'kh.SDT', 'nv.HoTen as NhanVienPhuTrach'
            );
    }

    private function attachReservationTables($rows)
    {
        foreach ($rows as $row) {
            $tables = DB::table('DAT_BAN_BAN as dbb')
                ->join('BAN as b', 'b.MaBan', '=', 'dbb.MaBan')
                ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
                ->where('dbb.MaDatBan', $row->MaDatBan)
                ->orderBy('b.MaBan')
                ->get(['b.MaBan', 'b.SoBan', 'b.MaKV', 'kv.TenKV']);
            $row->DanhSachMaBan = $tables->pluck('MaBan')->values();
            $row->DanhSachBan = $tables->pluck('SoBan')->values();
            $row->DanhSachBanText = $tables->pluck('SoBan')->implode(', ');
            $row->DanhSachKhuVuc = $tables->pluck('TenKV')->unique()->values();
            $row->DanhSachKhuVucText = $tables->pluck('TenKV')->unique()->implode(', ');
            $row->MaCS = DB::table('BAN as b')->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')->join('DAT_BAN_BAN as dbb', 'dbb.MaBan', '=', 'b.MaBan')->where('dbb.MaDatBan', $row->MaDatBan)->value('kv.MaCS');
        }
        return $rows;
    }

    public function reservations(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'TrangThai' => ['nullable', Rule::in(['Cho xac nhan', 'Da xac nhan', 'Khach da den', 'Da huy', 'Hoan tat'])],
            'search' => ['nullable', 'string', 'max:150'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = $this->reservationQuery()->whereExists(function ($sub) use ($data) {
            $sub->selectRaw('1')->from('DAT_BAN_BAN as dbb')
                ->join('BAN as b', 'b.MaBan', '=', 'dbb.MaBan')
                ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
                ->whereColumn('dbb.MaDatBan', 'db.MaDatBan')
                ->where('kv.MaCS', $data['MaCS']);
        });

        if (!empty($data['TrangThai'])) $query->where('db.TrangThai', $data['TrangThai']);
        if (!empty($data['from'])) $query->where('db.ThoiGianDen', '>=', $data['from']);
        if (!empty($data['to'])) $query->where('db.ThoiGianDen', '<=', $data['to']);
        if (!empty($data['search'])) {
            $term = trim($data['search']);
            $query->where(function ($q) use ($term) {
                $q->where('kh.HoTen', 'like', "%{$term}%")
                    ->orWhere('kh.SDT', 'like', "%{$term}%")
                    ->orWhere('db.MaDatBan', 'like', "%{$term}%");
            });
        }

        $rows = $query->orderByDesc('db.ThoiGianDen')->orderByDesc('db.MaDatBan')->limit(500)->get();
        return response()->json($this->attachReservationTables($rows));
    }

    private function validateReservation(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'HoTen' => ['required_without:MaKH', 'nullable', 'string', 'max:150'],
            'SDT' => ['nullable', 'string', 'max:20'],
            'MaKH' => ['nullable', 'integer', 'exists:KHACH_HANG,MaKH'],
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'MaBan' => ['required', 'array', 'min:1'],
            'MaBan.*' => ['required', 'integer', 'distinct', 'exists:BAN,MaBan'],
            'MaNV' => ['nullable', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'ThoiGianDen' => ['required', 'date', 'after_or_equal:now'],
            'ThoiGianKetThucDuKien' => ['required', 'date', 'after:ThoiGianDen'],
            'SoNguoi' => ['required', 'integer', 'min:1', 'max:1000'],
            'YeuCauDacBiet' => ['nullable', 'string', 'max:2000'],
            'GhiChu' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function persistReservation(array $data, ?int $id = null): int
    {
        $tableIds = array_values(array_unique(array_map('intval', $data['MaBan'])));
        $tables = DB::table('BAN as b')->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
            ->whereIn('b.MaBan', $tableIds)->where('kv.MaCS', $data['MaCS'])
            ->where('kv.TrangThai', 'Dang hoat dong')->where('b.TrangThai', '<>', 'Ngung su dung')
            ->lockForUpdate()->get(['b.MaBan', 'b.SoCho']);

        if ($tables->count() !== count($tableIds)) {
            throw ValidationException::withMessages(['MaBan' => 'Một hoặc nhiều bàn không thuộc cơ sở đã chọn hoặc đã ngừng sử dụng.']);
        }
        if ($tables->sum('SoCho') < (int) $data['SoNguoi']) {
            throw ValidationException::withMessages(['SoNguoi' => 'Tổng sức chứa của các bàn đã chọn chưa đủ số khách.']);
        }

        $activeOrder = DB::table('PHIEU_ORDER')->whereIn('MaBan', $tableIds)
            ->whereIn('TrangThai', ['Dang mo', 'Da gui', 'Dang thanh toan'])->exists();
        if ($activeOrder) {
            throw ValidationException::withMessages(['MaBan' => 'Một trong các bàn đang có order hoạt động.']);
        }

        $conflict = DB::table('DAT_BAN_BAN as dbb')->join('DAT_BAN as db', 'db.MaDatBan', '=', 'dbb.MaDatBan')
            ->whereIn('dbb.MaBan', $tableIds)->whereNotIn('db.TrangThai', ['Da huy', 'Hoan tat'])
            ->where('db.ThoiGianDen', '<', $data['ThoiGianKetThucDuKien'])
            ->where('db.ThoiGianKetThucDuKien', '>', $data['ThoiGianDen']);
        if ($id) $conflict->where('db.MaDatBan', '<>', $id);
        if ($conflict->exists()) {
            throw ValidationException::withMessages(['MaBan' => 'Có bàn đã được đặt trong khoảng thời gian này. Vui lòng chọn bàn hoặc khung giờ khác.']);
        }

        $customerId = $data['MaKH'] ?? null;
        if (!$customerId) {
            $phone = trim((string) ($data['SDT'] ?? ''));
            if ($phone !== '') {
                $customerId = DB::table('KHACH_HANG')->where('SDT', $phone)->value('MaKH');
            }
            if ($customerId) {
                DB::table('KHACH_HANG')->where('MaKH', $customerId)->update(['HoTen' => trim($data['HoTen'] ?? '') ?: DB::raw('HoTen')]);
            } else {
                $customerId = DB::table('KHACH_HANG')->insertGetId([
                    'HoTen' => trim($data['HoTen'] ?? 'Khách hàng'),
                    'SDT' => $data['SDT'] ?? null,
                    'GhiChu' => null,
                ], 'MaKH');
            }
        }

        $payload = [
            'ThoiGianDen' => date('Y-m-d H:i:s', strtotime($data['ThoiGianDen'])),
            'ThoiGianKetThucDuKien' => date('Y-m-d H:i:s', strtotime($data['ThoiGianKetThucDuKien'])),
            'SoNguoi' => $data['SoNguoi'],
            'YeuCauDacBiet' => $data['YeuCauDacBiet'] ?? null,
            'GhiChu' => $data['GhiChu'] ?? null,
            'MaKH' => $customerId,
            'MaNV' => $data['MaNV'] ?? null,
        ];

        if ($id) {
            DB::table('DAT_BAN')->where('MaDatBan', $id)->update($payload);
            DB::table('DAT_BAN_BAN')->where('MaDatBan', $id)->delete();
            $reservationId = $id;
        } else {
            $payload['TrangThai'] = 'Cho xac nhan';
            $reservationId = DB::table('DAT_BAN')->insertGetId($payload, 'MaDatBan');
        }

        foreach ($tableIds as $tableId) {
            DB::table('DAT_BAN_BAN')->insert(['MaDatBan' => $reservationId, 'MaBan' => $tableId]);
        }

        return $reservationId;
    }

    public function storeReservation(Request $request)
    {
        $data = $this->validateReservation($request);
        $id = DB::transaction(fn () => $this->persistReservation($data));
        return response()->json(['message' => 'Đã tạo phiếu đặt bàn.', 'MaDatBan' => $id], 201);
    }

    public function updateReservation(Request $request, int $id)
    {
        $reservation = DB::table('DAT_BAN')->where('MaDatBan', $id)->first();
        abort_unless($reservation, 404, 'Không tìm thấy phiếu đặt bàn.');
        if (in_array($reservation->TrangThai, ['Da huy', 'Hoan tat'], true)) {
            throw ValidationException::withMessages(['TrangThai' => 'Phiếu đã kết thúc, không thể chỉnh sửa.']);
        }
        $data = $this->validateReservation($request, $id);
        DB::transaction(fn () => $this->persistReservation($data, $id));
        return response()->json(['message' => 'Đã cập nhật phiếu đặt bàn.']);
    }

    public function updateReservationStatus(Request $request, int $id)
    {
        $data = $request->validate(['TrangThai' => ['required', Rule::in(['Cho xac nhan', 'Da xac nhan', 'Khach da den', 'Da huy', 'Hoan tat'])]]);
        $reservation = DB::table('DAT_BAN')->where('MaDatBan', $id)->first();
        abort_unless($reservation, 404, 'Không tìm thấy phiếu đặt bàn.');
        DB::table('DAT_BAN')->where('MaDatBan', $id)->update(['TrangThai' => $data['TrangThai']]);
        return response()->json(['message' => 'Đã cập nhật trạng thái phiếu đặt bàn.']);
    }

    public function cancelReservation(int $id)
    {
        $reservation = DB::table('DAT_BAN')->where('MaDatBan', $id)->first();
        abort_unless($reservation, 404, 'Không tìm thấy phiếu đặt bàn.');
        if ($reservation->TrangThai === 'Hoan tat') {
            throw ValidationException::withMessages(['TrangThai' => 'Phiếu đã hoàn tất, không thể hủy.']);
        }
        DB::table('DAT_BAN')->where('MaDatBan', $id)->update(['TrangThai' => 'Da huy']);
        return response()->json(['message' => 'Đã hủy phiếu đặt bàn.']);
    }
}
