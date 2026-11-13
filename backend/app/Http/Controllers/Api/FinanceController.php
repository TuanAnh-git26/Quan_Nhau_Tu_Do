<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceController extends Controller
{
    private function page(Request $request, $query)
    {
        return response()->json($query->paginate(min(100, max(5, (int) $request->input('per_page', 10)))));
    }

    private function employee(int $id, int $branch): void
    {
        $actor = request()->user();
        if ($actor && $actor->MaNV && (int) $actor->MaNV !== $id && $actor->TenVaiTro !== 'Quan tri he thong') {
            abort(403, 'Không được thao tác dưới danh tính nhân viên khác.');
        }
        if (!DB::table('NHAN_VIEN')->where('MaNV', $id)->where('MaCS', $branch)->where('TrangThai', 'Dang lam')->exists()) {
            throw ValidationException::withMessages(['MaNV' => 'Nhân viên không hoạt động hoặc không thuộc cơ sở.']);
        }
    }

    private function log(string $action, string $entity, string $details): void
    {
        DB::table('NHAT_KY')->insert([
            'HanhDong' => $action, 'DoiTuong' => $entity,
            'NoiDungThayDoi' => $details,
            'MaTK' => request()->user()?->getAuthIdentifier(),
        ]);
    }

    public function vouchers(Request $request)
    {
        $input = $request->validate([
            'MaCS' => 'required|integer|exists:CO_SO,MaCS',
            'Loai' => ['nullable', Rule::in(['Thu', 'Chi'])],
            'TrangThai' => ['nullable', Rule::in(['Cho duyet', 'Da duyet', 'Tu choi'])],
            'from' => 'nullable|date', 'to' => 'nullable|date',
        ]);
        $q = DB::table('PHIEU_THU_CHI as p')
            ->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 'p.MaNV')
            ->where('p.MaCS', $input['MaCS'])
            ->select('p.*', 'nv.HoTen as NguoiLap')->orderByDesc('p.MaPhieuThuChi');
        foreach (['Loai', 'TrangThai'] as $key) if (!empty($input[$key])) $q->where('p.'.$key, $input[$key]);
        if (!empty($input['from'])) $q->whereDate('p.NgayLap', '>=', $input['from']);
        if (!empty($input['to'])) $q->whereDate('p.NgayLap', '<=', $input['to']);
        return $this->page($request, $q);
    }

    public function storeVoucher(Request $request)
    {
        $d = $request->validate([
            'MaCS' => 'required|integer|exists:CO_SO,MaCS',
            'MaNV' => 'required|integer|exists:NHAN_VIEN,MaNV',
            'Loai' => ['required', Rule::in(['Thu', 'Chi'])],
            'NoiDung' => 'required|string|min:3|max:5000',
            'SoTien' => 'required|numeric|gt:0',
        ]);
        $this->employee((int)$d['MaNV'], (int)$d['MaCS']);
        $id = DB::transaction(function () use ($d) {
            $id = DB::table('PHIEU_THU_CHI')->insertGetId($d + ['NgayLap' => now(), 'TrangThai' => 'Cho duyet'], 'MaPhieuThuChi');
            $this->log('Lap phieu thu chi', 'PHIEU_THU_CHI', "Lập phiếu #{$id}");
            return $id;
        });
        return response()->json(['message' => 'Đã tạo phiếu chờ duyệt.', 'MaPhieuThuChi' => $id], 201);
    }

    public function reviewVoucher(Request $request, int $id)
    {
        $d = $request->validate([
            'MaNVDuyet' => 'required|integer|exists:NHAN_VIEN,MaNV',
            'TrangThai' => ['required', Rule::in(['Da duyet', 'Tu choi'])],
        ]);
        DB::transaction(function () use ($id, $d) {
            $p = DB::table('PHIEU_THU_CHI')->where('MaPhieuThuChi', $id)->lockForUpdate()->first();
            abort_unless($p, 404, 'Không có phiếu thu chi.');
            if ($p->TrangThai !== 'Cho duyet') throw ValidationException::withMessages(['TrangThai' => 'Phiếu đã được xử lý.']);
            $this->employee((int)$d['MaNVDuyet'], (int)$p->MaCS);
            if ((int)$p->MaNV === (int)$d['MaNVDuyet']) throw ValidationException::withMessages(['MaNVDuyet' => 'Người duyệt phải khác người lập.']);
            DB::table('PHIEU_THU_CHI')->where('MaPhieuThuChi', $id)->update($d);
            $this->log('Duyet phieu thu chi', 'PHIEU_THU_CHI', "Phiếu #{$id}: {$d['TrangThai']}");
        });
        return response()->json(['message' => 'Đã cập nhật kết quả duyệt.']);
    }

    public function promotions(Request $request)
    {
        $q = DB::table('KHUYEN_MAI as km')->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 'km.MaNV')
            ->select('km.*', 'nv.HoTen as NguoiLap')->orderByDesc('km.MaKM');
        return $this->page($request, $q);
    }

    public function storePromotion(Request $request)
    {
        $d = $request->validate([
            'TenKM' => 'required|string|max:180',
            'HinhThuc' => ['required', Rule::in(['Phan tram', 'So tien'])],
            'GiaTri' => 'required|numeric|gt:0',
            'NgayBD' => 'required|date', 'NgayKT' => 'required|date|after_or_equal:NgayBD',
            'NganSach' => 'required|numeric|min:0',
            'MaNV' => 'nullable|integer|exists:NHAN_VIEN,MaNV',
        ]);
        if ($d['HinhThuc'] === 'Phan tram' && $d['GiaTri'] > 100) {
            throw ValidationException::withMessages(['GiaTri' => 'Giảm theo phần trăm không quá 100%.']);
        }
        $id = DB::transaction(function () use ($d) {
            $id = DB::table('KHUYEN_MAI')->insertGetId($d + ['TrangThai' => 'Nhap'], 'MaKM');
            $this->log('Lap khuyen mai', 'KHUYEN_MAI', "Khuyến mãi #{$id}");
            return $id;
        });
        return response()->json(['message' => 'Đã tạo khuyến mãi nháp.', 'MaKM' => $id], 201);
    }

    public function updatePromotion(Request $request, int $id)
    {
        $d = $request->validate([
            'TenKM' => 'required|string|max:180',
            'HinhThuc' => ['required', Rule::in(['Phan tram', 'So tien'])],
            'GiaTri' => 'required|numeric|gt:0',
            'NgayBD' => 'required|date', 'NgayKT' => 'required|date|after_or_equal:NgayBD',
            'NganSach' => 'required|numeric|min:0',
        ]);
        if ($d['HinhThuc'] === 'Phan tram' && $d['GiaTri'] > 100) throw ValidationException::withMessages(['GiaTri' => 'Tối đa 100%.']);
        DB::transaction(function () use ($id, $d) {
            $km = DB::table('KHUYEN_MAI')->where('MaKM', $id)->lockForUpdate()->first();
            abort_unless($km, 404, 'Không thấy chương trình.');
            if ($km->TrangThai !== 'Nhap') throw ValidationException::withMessages(['TrangThai' => 'Chỉ sửa khuyến mãi nháp.']);
            DB::table('KHUYEN_MAI')->where('MaKM', $id)->update($d);
            $this->log('Sua khuyen mai', 'KHUYEN_MAI', "Khuyến mãi #{$id}");
        });
        return response()->json(['message' => 'Đã sửa khuyến mãi.']);
    }

    public function promotionStatus(Request $request, int $id)
    {
        $d = $request->validate(['TrangThai' => ['required', Rule::in(['Cho duyet', 'Dang ap dung', 'Ket thuc', 'Da huy'])]]);
        DB::transaction(function () use ($id, $d) {
            $p = DB::table('KHUYEN_MAI')->where('MaKM', $id)->lockForUpdate()->first();
            abort_unless($p, 404, 'Không thấy khuyến mãi.');
            $allowed = [
                'Nhap' => ['Cho duyet', 'Da huy'],
                'Cho duyet' => ['Dang ap dung', 'Da huy'],
                'Dang ap dung' => ['Ket thuc'],
            ];
            if (!in_array($d['TrangThai'], $allowed[$p->TrangThai] ?? [], true)) {
                throw ValidationException::withMessages(['TrangThai' => 'Chuyển trạng thái không hợp lệ.']);
            }
            DB::table('KHUYEN_MAI')->where('MaKM', $id)->update($d);
            $this->log('Duyet khuyen mai', 'KHUYEN_MAI', "Khuyến mãi #{$id}: {$d['TrangThai']}");
        });
        return response()->json(['message' => 'Đã cập nhật khuyến mãi.']);
    }

    public function budgets(Request $request)
    {
        $d = $request->validate(['MaCS' => 'required|integer|exists:CO_SO,MaCS']);
        return $this->page($request, DB::table('KE_HOACH_NGAN_SACH')
            ->where('MaCS', $d['MaCS'])->orderByDesc('MaKHNS'));
    }

    public function storeBudget(Request $request)
    {
        $d = $request->validate([
            'MaCS' => 'required|integer|exists:CO_SO,MaCS',
            'MaNVLap' => 'required|integer|exists:NHAN_VIEN,MaNV',
            'TenKH' => 'required|string|max:180',
            'KyKeHoach' => 'required|string|max:30',
            'SoTien' => 'required|numeric|min:0',
        ]);
        $this->employee((int)$d['MaNVLap'], (int)$d['MaCS']);
        $id = DB::transaction(function () use ($d) {
            $id = DB::table('KE_HOACH_NGAN_SACH')->insertGetId($d + ['TrangThai' => 'Nhap'], 'MaKHNS');
            $this->log('Lap ngan sach', 'KE_HOACH_NGAN_SACH', "Kế hoạch #{$id}");
            return $id;
        });
        return response()->json(['message' => 'Đã lập kế hoạch ngân sách.', 'MaKHNS' => $id], 201);
    }

    public function budgetStatus(Request $request, int $id)
    {
        $d = $request->validate([
            'TrangThai' => ['required', Rule::in(['Cho duyet', 'Da duyet', 'Tu choi'])],
            'MaNVDuyet' => 'nullable|integer|exists:NHAN_VIEN,MaNV',
        ]);
        DB::transaction(function () use ($id, $d) {
            $b = DB::table('KE_HOACH_NGAN_SACH')->where('MaKHNS', $id)->lockForUpdate()->first();
            abort_unless($b, 404, 'Không có kế hoạch.');
            if (($b->TrangThai === 'Nhap' && $d['TrangThai'] !== 'Cho duyet') ||
                ($b->TrangThai === 'Cho duyet' && !in_array($d['TrangThai'], ['Da duyet', 'Tu choi'], true)) ||
                !in_array($b->TrangThai, ['Nhap', 'Cho duyet'], true)) {
                throw ValidationException::withMessages(['TrangThai' => 'Chuyển trạng thái không hợp lệ.']);
            }
            $update = ['TrangThai' => $d['TrangThai']];
            if ($d['TrangThai'] !== 'Cho duyet') {
                if (empty($d['MaNVDuyet'])) throw ValidationException::withMessages(['MaNVDuyet' => 'Cần người phê duyệt.']);
                $this->employee((int)$d['MaNVDuyet'], (int)$b->MaCS);
                if ((int)$d['MaNVDuyet'] === (int)$b->MaNVLap) throw ValidationException::withMessages(['MaNVDuyet' => 'Người duyệt phải khác người lập.']);
                $update['MaNVDuyet'] = $d['MaNVDuyet'];
            }
            DB::table('KE_HOACH_NGAN_SACH')->where('MaKHNS', $id)->update($update);
            $this->log('Duyet ngan sach', 'KE_HOACH_NGAN_SACH', "Kế hoạch #{$id}: {$d['TrangThai']}");
        });
        return response()->json(['message' => 'Đã cập nhật kế hoạch.']);
    }

    public function promotionPerformance(Request $request)
    {
        return response()->json(DB::table('KHUYEN_MAI as km')
            ->leftJoin('AP_DUNG_KM as ap', 'ap.MaKM', '=', 'km.MaKM')
            ->select('km.MaKM', 'km.TenKM', 'km.NganSach', 'km.TrangThai',
                DB::raw('COUNT(ap.MaHD) as SoHoaDon'),
                DB::raw('COALESCE(SUM(ap.SoTienGiam), 0) as TongDaGiam'))
            ->groupBy('km.MaKM', 'km.TenKM', 'km.NganSach', 'km.TrangThai')
            ->orderByDesc('TongDaGiam')->get());
    }

    public function dashboard(Request $request)
    {
        $d = $request->validate(['MaCS' => 'required|integer|exists:CO_SO,MaCS', 'from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $branch = (int)$d['MaCS'];
        $payments = DB::table('THANH_TOAN as tt')
            ->join('HOA_DON as hd', 'hd.MaHD', '=', 'tt.MaHD')
            ->join('CT_HOA_DON as ct', 'ct.MaHD', '=', 'hd.MaHD')
            ->join('CHI_TIET_ORDER as od', 'od.MaCTOrder', '=', 'ct.MaCTOrder')
            ->join('PHIEU_ORDER as po', 'po.MaOrder', '=', 'od.MaOrder')
            ->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')
            ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
            ->where('kv.MaCS', $branch)
            ->where('tt.TrangThaiGD', 'Thanh cong')
            ->whereDate('tt.ThoiGian', '>=', $d['from'])->whereDate('tt.ThoiGian', '<=', $d['to'])
            ->distinct()->pluck('tt.MaTT');
        $totalCollected = $payments->isEmpty() ? 0 : (float)DB::table('THANH_TOAN')->whereIn('MaTT', $payments)->sum('SoTien');
        $vouchers = DB::table('PHIEU_THU_CHI')->where('MaCS', $branch)->where('TrangThai', 'Da duyet')
            ->whereDate('NgayLap', '>=', $d['from'])->whereDate('NgayLap', '<=', $d['to']);
        $otherIncome = (float)(clone $vouchers)->where('Loai', 'Thu')->sum('SoTien');
        $otherExpense = (float)(clone $vouchers)->where('Loai', 'Chi')->sum('SoTien');
        return response()->json([
            'DoanhThuThanhToan' => $totalCollected,
            'ThuKhac' => $otherIncome,
            'ChiKhac' => $otherExpense,
            'TongThu' => $totalCollected + $otherIncome,
            'ChenhLechThuChi' => $totalCollected + $otherIncome - $otherExpense,
            'GiaoDichThanhCong' => $payments->count(),
            'PhieuChoDuyet' => DB::table('PHIEU_THU_CHI')->where('MaCS', $branch)->where('TrangThai', 'Cho duyet')->count(),
        ]);
    }

    public function reconciliation(Request $request)
    {
        $d = $request->validate(['MaCS' => 'required|integer|exists:CO_SO,MaCS', 'from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        return response()->json(DB::table('BAO_CAO_CUOI_CA as bc')
            ->join('CA_LAM as ca', 'ca.MaCa', '=', 'bc.MaCa')
            ->where('bc.MaCS', $d['MaCS'])->whereDate('bc.NgayCa', '>=', $d['from'])
            ->whereDate('bc.NgayCa', '<=', $d['to'])
            ->orderByDesc('bc.NgayCa')
            ->get(['bc.*', 'ca.TenCa']));
    }
}