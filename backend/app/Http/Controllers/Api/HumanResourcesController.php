<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HumanResourcesController extends Controller
{
    private const EMPLOYEE_STATUSES = ['Dang lam', 'Tam nghi', 'Da nghi'];
    private const CONTRACT_STATUSES = ['Hieu luc', 'Het han', 'Da cham dut'];
    private const SHIFT_STATUSES = ['Dang hoat dong', 'Ngung hoat dong'];
    private const PAYROLL_STATUSES = ['Nhap', 'Cho ke toan', 'Can dieu chinh', 'Da chot'];

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD
    |--------------------------------------------------------------------------
    */

    public function dashboard(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS'],
        ]);

        $today = Carbon::today()->toDateString();
        $branchId = $data['MaCS'] ?? null;

        $employees = DB::table('NHAN_VIEN');
        if ($branchId) {
            $employees->where('MaCS', $branchId);
        }

        $attendance = DB::table('CHAM_CONG')->whereDate('Ngay', $today);
        if ($branchId) {
            $attendance->where('MaCS', $branchId);
        }

        $shifts = DB::table('CA_LAM')->where('TrangThai', 'Dang hoat dong');
        if ($branchId) {
            $shifts->where('MaCS', $branchId);
        }

        $contracts = DB::table('HOP_DONG')
            ->where('TrangThai', 'Hieu luc')
            ->whereNotNull('NgayKT')
            ->whereDate('NgayKT', '>=', $today)
            ->whereDate('NgayKT', '<=', Carbon::today()->addDays(30)->toDateString())
            ->whereIn('MaNV', function ($query) use ($branchId) {
                $query->select('MaNV')->from('NHAN_VIEN');
                if ($branchId) {
                    $query->where('MaCS', $branchId);
                }
            });

        $payrolls = DB::table('BANG_LUONG as bl')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'bl.MaNV');

        if ($branchId) {
            $payrolls->where('nv.MaCS', $branchId);
        }

        return response()->json([
            'TongNhanVien' => (clone $employees)->count(),
            'DangLam' => (clone $employees)->where('TrangThai', 'Dang lam')->count(),
            'TamNghi' => (clone $employees)->where('TrangThai', 'Tam nghi')->count(),
            'DaNghi' => (clone $employees)->where('TrangThai', 'Da nghi')->count(),
            'HopDongSapHetHan' => $contracts->count(),
            'TongCaHomNay' => (clone $shifts)->count(),
            'TongLichChamCongHomNay' => (clone $attendance)->count(),
            'DaCheckIn' => (clone $attendance)->whereNotNull('GioVao')->count(),
            'ChuaCheckOut' => (clone $attendance)
                ->whereNotNull('GioVao')
                ->whereNull('GioRa')
                ->count(),
            'BangLuongChoKeToan' => (clone $payrolls)
                ->where('bl.TrangThai', 'Cho ke toan')
                ->count(),
            'BangLuongCanDieuChinh' => (clone $payrolls)
                ->where('bl.TrangThai', 'Can dieu chinh')
                ->count(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | VỊ TRÍ / CHỨC DANH
    |--------------------------------------------------------------------------
    */

    public function positions()
    {
        $rows = DB::table('VI_TRI as vt')
            ->leftJoin('NHAN_VIEN as nv', 'nv.MaVT', '=', 'vt.MaVT')
            ->select(
                'vt.MaVT',
                'vt.TenVT',
                'vt.MoTa',
                DB::raw('COUNT(nv.MaNV) as SoNhanVien')
            )
            ->groupBy('vt.MaVT', 'vt.TenVT', 'vt.MoTa')
            ->orderBy('vt.TenVT')
            ->get();

        return response()->json($rows);
    }

    public function storePosition(Request $request)
    {
        $data = $request->validate([
            'TenVT' => ['required', 'string', 'max:100', 'unique:VI_TRI,TenVT'],
            'MoTa' => ['nullable', 'string', 'max:5000'],
        ]);

        $id = DB::table('VI_TRI')->insertGetId([
            'TenVT' => trim($data['TenVT']),
            'MoTa' => $data['MoTa'] ?? null,
        ], 'MaVT');

        $this->writeLog(
            'Tao vi tri',
            'VI_TRI',
            "Tạo vị trí #{$id}: {$data['TenVT']}"
        );

        return response()->json([
            'message' => 'Đã tạo vị trí.',
            'MaVT' => $id,
        ], 201);
    }

    public function updatePosition(Request $request, int $id)
    {
        $data = $request->validate([
            'TenVT' => [
                'required',
                'string',
                'max:100',
                Rule::unique('VI_TRI', 'TenVT')->ignore($id, 'MaVT'),
            ],
            'MoTa' => ['nullable', 'string', 'max:5000'],
        ]);

        $position = DB::table('VI_TRI')->where('MaVT', $id)->first();
        abort_unless($position, 404, 'Không tìm thấy vị trí.');

        DB::table('VI_TRI')->where('MaVT', $id)->update([
            'TenVT' => trim($data['TenVT']),
            'MoTa' => $data['MoTa'] ?? null,
        ]);

        $this->writeLog(
            'Cap nhat vi tri',
            'VI_TRI',
            "Cập nhật vị trí #{$id}."
        );

        return response()->json([
            'message' => 'Đã cập nhật vị trí.',
        ]);
    }

    public function deletePosition(int $id)
    {
        $position = DB::table('VI_TRI')->where('MaVT', $id)->first();
        abort_unless($position, 404, 'Không tìm thấy vị trí.');

        if (DB::table('NHAN_VIEN')->where('MaVT', $id)->exists()) {
            throw ValidationException::withMessages([
                'MaVT' => 'Không thể xóa vị trí đang được gán cho nhân viên. Hãy chuyển nhân viên sang vị trí khác trước.',
            ]);
        }

        DB::table('VI_TRI')->where('MaVT', $id)->delete();

        $this->writeLog(
            'Xoa vi tri',
            'VI_TRI',
            "Xóa vị trí #{$id}: {$position->TenVT}"
        );

        return response()->json([
            'message' => 'Đã xóa vị trí.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | HỒ SƠ NHÂN VIÊN
    |--------------------------------------------------------------------------
    */

    public function employees(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS'],
            'MaVT' => ['nullable', 'integer', 'exists:VI_TRI,MaVT'],
            'TrangThai' => ['nullable', Rule::in(self::EMPLOYEE_STATUSES)],
            'q' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = DB::table('NHAN_VIEN as nv')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'nv.MaCS')
            ->leftJoin('VI_TRI as vt', 'vt.MaVT', '=', 'nv.MaVT')
            ->select(
                'nv.MaNV',
                'nv.HoTen',
                'nv.NgaySinh',
                'nv.SDT',
                'nv.Email',
                'nv.NgayVaoLam',
                'nv.TrangThai',
                'nv.MaCS',
                'cs.TenCS',
                'nv.MaVT',
                'vt.TenVT'
            )
            ->orderBy('nv.MaNV', 'desc');

        if (!empty($data['MaCS'])) {
            $query->where('nv.MaCS', $data['MaCS']);
        }

        if (!empty($data['MaVT'])) {
            $query->where('nv.MaVT', $data['MaVT']);
        }

        if (!empty($data['TrangThai'])) {
            $query->where('nv.TrangThai', $data['TrangThai']);
        }

        if (!empty($data['q'])) {
            $keyword = trim($data['q']);

            $query->where(function ($q) use ($keyword) {
                $q->where('nv.HoTen', 'like', "%{$keyword}%")
                    ->orWhere('nv.SDT', 'like', "%{$keyword}%")
                    ->orWhere('nv.Email', 'like', "%{$keyword}%")
                    ->orWhere('nv.MaNV', $keyword);
            });
        }

        return response()->json(
            $query->paginate($data['per_page'] ?? 15)->withQueryString()
        );
    }

    public function storeEmployee(Request $request)
    {
        $data = $request->validate([
            'HoTen' => ['required', 'string', 'max:150'],
            'NgaySinh' => ['nullable', 'date', 'before:today'],
            'SDT' => ['nullable', 'string', 'max:20'],
            'Email' => ['nullable', 'email', 'max:150'],
            'NgayVaoLam' => ['nullable', 'date'],
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'MaVT' => ['nullable', 'integer', 'exists:VI_TRI,MaVT'],
        ]);

        $branch = DB::table('CO_SO')->where('MaCS', $data['MaCS'])->first();

        if (!$branch || $branch->TrangThai !== 'Dang hoat dong') {
            throw ValidationException::withMessages([
                'MaCS' => 'Cơ sở không tồn tại hoặc đang ngừng hoạt động.',
            ]);
        }

        $id = DB::table('NHAN_VIEN')->insertGetId([
            'HoTen' => trim($data['HoTen']),
            'NgaySinh' => $data['NgaySinh'] ?? null,
            'SDT' => $data['SDT'] ?? null,
            'Email' => $data['Email'] ?? null,
            'NgayVaoLam' => $data['NgayVaoLam'] ?? null,
            'TrangThai' => 'Dang lam',
            'MaCS' => $data['MaCS'],
            'MaVT' => $data['MaVT'] ?? null,
        ], 'MaNV');

        $this->writeLog(
            'Tao nhan vien',
            'NHAN_VIEN',
            "Tạo nhân viên #{$id}: {$data['HoTen']}"
        );

        return response()->json([
            'message' => 'Đã thêm nhân viên.',
            'MaNV' => $id,
        ], 201);
    }

    public function updateEmployee(Request $request, int $id)
    {
        $data = $request->validate([
            'HoTen' => ['required', 'string', 'max:150'],
            'NgaySinh' => ['nullable', 'date', 'before:today'],
            'SDT' => ['nullable', 'string', 'max:20'],
            'Email' => ['nullable', 'email', 'max:150'],
            'NgayVaoLam' => ['nullable', 'date'],
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'MaVT' => ['nullable', 'integer', 'exists:VI_TRI,MaVT'],
        ]);

        $employee = DB::table('NHAN_VIEN')->where('MaNV', $id)->first();
        abort_unless($employee, 404, 'Không tìm thấy nhân viên.');

        $branch = DB::table('CO_SO')->where('MaCS', $data['MaCS'])->first();

        if (!$branch || $branch->TrangThai !== 'Dang hoat dong') {
            throw ValidationException::withMessages([
                'MaCS' => 'Cơ sở không tồn tại hoặc đang ngừng hoạt động.',
            ]);
        }

        if ((int) $employee->MaCS !== (int) $data['MaCS']) {
            $hasOpenAttendance = DB::table('CHAM_CONG')
                ->where('MaNV', $id)
                ->whereNotNull('GioVao')
                ->whereNull('GioRa')
                ->exists();

            if ($hasOpenAttendance) {
                throw ValidationException::withMessages([
                    'MaCS' => 'Nhân viên đang trong ca chưa check-out, không thể chuyển cơ sở.',
                ]);
            }
        }

        DB::table('NHAN_VIEN')->where('MaNV', $id)->update([
            'HoTen' => trim($data['HoTen']),
            'NgaySinh' => $data['NgaySinh'] ?? null,
            'SDT' => $data['SDT'] ?? null,
            'Email' => $data['Email'] ?? null,
            'NgayVaoLam' => $data['NgayVaoLam'] ?? null,
            'MaCS' => $data['MaCS'],
            'MaVT' => $data['MaVT'] ?? null,
        ]);

        $this->writeLog(
            'Cap nhat nhan vien',
            'NHAN_VIEN',
            "Cập nhật hồ sơ nhân viên #{$id}."
        );

        return response()->json([
            'message' => 'Đã cập nhật hồ sơ nhân viên.',
        ]);
    }

    public function changeEmployeeStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'TrangThai' => ['required', Rule::in(self::EMPLOYEE_STATUSES)],
        ]);

        DB::transaction(function () use ($id, $data) {
            $employee = DB::table('NHAN_VIEN')
                ->where('MaNV', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($employee, 404, 'Không tìm thấy nhân viên.');

            if ($data['TrangThai'] === 'Da nghi') {
                $hasOpenAttendance = DB::table('CHAM_CONG')
                    ->where('MaNV', $id)
                    ->whereNotNull('GioVao')
                    ->whereNull('GioRa')
                    ->exists();

                if ($hasOpenAttendance) {
                    throw ValidationException::withMessages([
                        'TrangThai' => 'Nhân viên còn ca chưa check-out, không thể chuyển sang trạng thái đã nghỉ.',
                    ]);
                }

                $today = Carbon::today()->toDateString();

                DB::table('HOP_DONG')
                    ->where('MaNV', $id)
                    ->where('TrangThai', 'Hieu luc')
                    ->whereDate('NgayBD', '<=', $today)
                    ->where(function ($q) use ($today) {
                        $q->whereNull('NgayKT')
                            ->orWhereDate('NgayKT', '>=', $today);
                    })
                    ->update([
                        'NgayKT' => $today,
                        'TrangThai' => 'Da cham dut',
                    ]);

                DB::table('HOP_DONG')
                    ->where('MaNV', $id)
                    ->where('TrangThai', 'Hieu luc')
                    ->whereDate('NgayBD', '>', $today)
                    ->update([
                        'TrangThai' => 'Da cham dut',
                    ]);
            }

            DB::table('NHAN_VIEN')->where('MaNV', $id)->update([
                'TrangThai' => $data['TrangThai'],
            ]);

            $this->writeLog(
                'Doi trang thai nhan vien',
                'NHAN_VIEN',
                "Nhân viên #{$id}: {$employee->TrangThai} -> {$data['TrangThai']}."
            );
        });

        return response()->json([
            'message' => 'Đã cập nhật trạng thái nhân viên.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | HỢP ĐỒNG LAO ĐỘNG
    |--------------------------------------------------------------------------
    */

    public function contracts(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS'],
            'MaNV' => ['nullable', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'TrangThai' => ['nullable', Rule::in(self::CONTRACT_STATUSES)],
            'q' => ['nullable', 'string', 'max:150'],
        ]);

        $query = DB::table('HOP_DONG as hd')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'hd.MaNV')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'nv.MaCS')
            ->leftJoin('VI_TRI as vt', 'vt.MaVT', '=', 'nv.MaVT')
            ->select(
                'hd.MaHDLD',
                'hd.LoaiHD',
                'hd.NgayBD',
                'hd.NgayKT',
                'hd.LuongCoBan',
                'hd.TrangThai',
                'hd.MaNV',
                'nv.HoTen',
                'nv.MaCS',
                'cs.TenCS',
                'vt.TenVT'
            )
            ->orderByDesc('hd.NgayBD')
            ->orderByDesc('hd.MaHDLD');

        if (!empty($data['MaCS'])) {
            $query->where('nv.MaCS', $data['MaCS']);
        }

        if (!empty($data['MaNV'])) {
            $query->where('hd.MaNV', $data['MaNV']);
        }

        if (!empty($data['TrangThai'])) {
            $query->where('hd.TrangThai', $data['TrangThai']);
        }

        if (!empty($data['q'])) {
            $keyword = trim($data['q']);

            $query->where(function ($q) use ($keyword) {
                $q->where('nv.HoTen', 'like', "%{$keyword}%")
                    ->orWhere('hd.LoaiHD', 'like', "%{$keyword}%")
                    ->orWhere('hd.MaHDLD', $keyword);
            });
        }

        return response()->json(
            $query->paginate(15)->withQueryString()
        );
    }

    public function storeContract(Request $request)
    {
        $data = $request->validate([
            'LoaiHD' => ['required', 'string', 'max:100'],
            'NgayBD' => ['required', 'date'],
            'NgayKT' => ['nullable', 'date', 'after_or_equal:NgayBD'],
            'LuongCoBan' => ['required', 'numeric', 'min:0'],
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
        ]);

        $id = DB::transaction(function () use ($data) {
            $employee = DB::table('NHAN_VIEN')
                ->where('MaNV', $data['MaNV'])
                ->lockForUpdate()
                ->first();

            abort_unless($employee, 404, 'Không tìm thấy nhân viên.');

            if ($employee->TrangThai === 'Da nghi') {
                throw ValidationException::withMessages([
                    'MaNV' => 'Không thể tạo hợp đồng cho nhân viên đã nghỉ.',
                ]);
            }

            $this->assertNoOverlappingContract(
                (int) $data['MaNV'],
                $data['NgayBD'],
                $data['NgayKT'] ?? null
            );

            $id = DB::table('HOP_DONG')->insertGetId([
                'LoaiHD' => trim($data['LoaiHD']),
                'NgayBD' => $data['NgayBD'],
                'NgayKT' => $data['NgayKT'] ?? null,
                'LuongCoBan' => $data['LuongCoBan'],
                'TrangThai' => 'Hieu luc',
                'MaNV' => $data['MaNV'],
            ], 'MaHDLD');

            $this->writeLog(
                'Tao hop dong',
                'HOP_DONG',
                "Tạo hợp đồng #{$id} cho nhân viên #{$data['MaNV']}."
            );

            return $id;
        });

        return response()->json([
            'message' => 'Đã tạo hợp đồng lao động.',
            'MaHDLD' => $id,
        ], 201);
    }

    public function updateContract(Request $request, int $id)
    {
        $data = $request->validate([
            'LoaiHD' => ['required', 'string', 'max:100'],
            'NgayBD' => ['required', 'date'],
            'NgayKT' => ['nullable', 'date', 'after_or_equal:NgayBD'],
            'LuongCoBan' => ['required', 'numeric', 'min:0'],
            'TrangThai' => ['required', Rule::in(self::CONTRACT_STATUSES)],
        ]);

        DB::transaction(function () use ($id, $data) {
            $contract = DB::table('HOP_DONG')
                ->where('MaHDLD', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($contract, 404, 'Không tìm thấy hợp đồng.');

            if ($data['TrangThai'] === 'Hieu luc') {
                $this->assertNoOverlappingContract(
                    (int) $contract->MaNV,
                    $data['NgayBD'],
                    $data['NgayKT'] ?? null,
                    $id
                );
            }

            DB::table('HOP_DONG')->where('MaHDLD', $id)->update([
                'LoaiHD' => trim($data['LoaiHD']),
                'NgayBD' => $data['NgayBD'],
                'NgayKT' => $data['NgayKT'] ?? null,
                'LuongCoBan' => $data['LuongCoBan'],
                'TrangThai' => $data['TrangThai'],
            ]);

            $this->writeLog(
                'Cap nhat hop dong',
                'HOP_DONG',
                "Cập nhật hợp đồng #{$id}."
            );
        });

        return response()->json([
            'message' => 'Đã cập nhật hợp đồng.',
        ]);
    }

    public function endContract(int $id)
    {
        DB::transaction(function () use ($id) {
            $contract = DB::table('HOP_DONG')
                ->where('MaHDLD', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($contract, 404, 'Không tìm thấy hợp đồng.');

            if ($contract->TrangThai === 'Da cham dut') {
                throw ValidationException::withMessages([
                    'MaHDLD' => 'Hợp đồng đã chấm dứt trước đó.',
                ]);
            }

            $today = Carbon::today()->toDateString();

            if ($contract->NgayBD > $today) {
                throw ValidationException::withMessages([
                    'NgayBD' => 'Hợp đồng chưa đến ngày bắt đầu nên không thể kết thúc theo ngày hiện tại.',
                ]);
            }

            DB::table('HOP_DONG')->where('MaHDLD', $id)->update([
                'NgayKT' => $today,
                'TrangThai' => 'Da cham dut',
            ]);

            $this->writeLog(
                'Cham dut hop dong',
                'HOP_DONG',
                "Chấm dứt hợp đồng #{$id}."
            );
        });

        return response()->json([
            'message' => 'Đã chấm dứt hợp đồng.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CA LÀM
    |--------------------------------------------------------------------------
    */

    public function shifts(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS'],
            'TrangThai' => ['nullable', Rule::in(self::SHIFT_STATUSES)],
        ]);

        $query = DB::table('CA_LAM as ca')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'ca.MaCS')
            ->select(
                'ca.MaCa',
                'ca.TenCa',
                'ca.GioVao',
                'ca.GioRa',
                'ca.MaCS',
                'cs.TenCS',
                'ca.TrangThai'
            )
            ->orderBy('ca.MaCS')
            ->orderBy('ca.GioVao');

        if (!empty($data['MaCS'])) {
            $query->where('ca.MaCS', $data['MaCS']);
        }

        if (!empty($data['TrangThai'])) {
            $query->where('ca.TrangThai', $data['TrangThai']);
        }

        return response()->json($query->get());
    }

    public function storeShift(Request $request)
    {
        $data = $request->validate([
            'TenCa' => ['required', 'string', 'max:100'],
            'GioVao' => ['required', 'date_format:H:i'],
            'GioRa' => ['required', 'date_format:H:i', 'different:GioVao'],
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
        ]);

        $branch = DB::table('CO_SO')->where('MaCS', $data['MaCS'])->first();

        if (!$branch || $branch->TrangThai !== 'Dang hoat dong') {
            throw ValidationException::withMessages([
                'MaCS' => 'Cơ sở không tồn tại hoặc đang ngừng hoạt động.',
            ]);
        }

        $id = DB::table('CA_LAM')->insertGetId([
            'TenCa' => trim($data['TenCa']),
            'GioVao' => $data['GioVao'],
            'GioRa' => $data['GioRa'],
            'MaCS' => $data['MaCS'],
            'TrangThai' => 'Dang hoat dong',
        ], 'MaCa');

        $this->writeLog(
            'Tao ca lam',
            'CA_LAM',
            "Tạo ca làm #{$id}: {$data['TenCa']}."
        );

        return response()->json([
            'message' => 'Đã tạo ca làm.',
            'MaCa' => $id,
        ], 201);
    }

    public function updateShift(Request $request, int $id)
    {
        $data = $request->validate([
            'TenCa' => ['required', 'string', 'max:100'],
            'GioVao' => ['required', 'date_format:H:i'],
            'GioRa' => ['required', 'date_format:H:i', 'different:GioVao'],
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'TrangThai' => ['required', Rule::in(self::SHIFT_STATUSES)],
        ]);

        $shift = DB::table('CA_LAM')->where('MaCa', $id)->first();
        abort_unless($shift, 404, 'Không tìm thấy ca làm.');

        if ((int) $shift->MaCS !== (int) $data['MaCS']) {
            $hasAttendance = DB::table('CHAM_CONG')
                ->where('MaCa', $id)
                ->exists();

            if ($hasAttendance) {
                throw ValidationException::withMessages([
                    'MaCS' => 'Không thể chuyển ca sang cơ sở khác vì ca đã có dữ liệu chấm công.',
                ]);
            }
        }

        DB::table('CA_LAM')->where('MaCa', $id)->update([
            'TenCa' => trim($data['TenCa']),
            'GioVao' => $data['GioVao'],
            'GioRa' => $data['GioRa'],
            'MaCS' => $data['MaCS'],
            'TrangThai' => $data['TrangThai'],
        ]);

        $this->writeLog(
            'Cap nhat ca lam',
            'CA_LAM',
            "Cập nhật ca làm #{$id}."
        );

        return response()->json([
            'message' => 'Đã cập nhật ca làm.',
        ]);
    }

    public function disableShift(int $id)
    {
        $shift = DB::table('CA_LAM')->where('MaCa', $id)->first();
        abort_unless($shift, 404, 'Không tìm thấy ca làm.');

        DB::table('CA_LAM')->where('MaCa', $id)->update([
            'TrangThai' => 'Ngung hoat dong',
        ]);

        $this->writeLog(
            'Ngung ca lam',
            'CA_LAM',
            "Ngừng hoạt động ca #{$id}."
        );

        return response()->json([
            'message' => 'Đã ngừng hoạt động ca làm.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | XẾP CA VÀ CHẤM CÔNG
    |--------------------------------------------------------------------------
    */

    public function attendance(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS'],
            'MaNV' => ['nullable', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'MaCa' => ['nullable', 'integer', 'exists:CA_LAM,MaCa'],
            'Ngay' => ['nullable', 'date'],
            'TuNgay' => ['nullable', 'date'],
            'DenNgay' => ['nullable', 'date', 'after_or_equal:TuNgay'],
            'TrangThaiChamCong' => [
                'nullable',
                Rule::in(['Chua vao ca', 'Dang lam', 'Da hoan tat', 'Nghi']),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = DB::table('CHAM_CONG as cc')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'cc.MaNV')
            ->join('CA_LAM as ca', 'ca.MaCa', '=', 'cc.MaCa')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'cc.MaCS')
            ->leftJoin('VI_TRI as vt', 'vt.MaVT', '=', 'nv.MaVT')
            ->select(
                'cc.MaCC',
                'cc.Ngay',
                'cc.GioVao',
                'cc.GioRa',
                'cc.TangCa',
                'cc.LoaiNghi',
                'cc.MaNV',
                'nv.HoTen',
                'nv.MaVT',
                'vt.TenVT',
                'cc.MaCa',
                'ca.TenCa',
                'ca.GioVao as GioVaoCa',
                'ca.GioRa as GioRaCa',
                'cc.MaCS',
                'cs.TenCS'
            )
            ->orderByDesc('cc.Ngay')
            ->orderBy('ca.GioVao')
            ->orderBy('nv.HoTen');

        if (!empty($data['MaCS'])) {
            $query->where('cc.MaCS', $data['MaCS']);
        }

        if (!empty($data['MaNV'])) {
            $query->where('cc.MaNV', $data['MaNV']);
        }

        if (!empty($data['MaCa'])) {
            $query->where('cc.MaCa', $data['MaCa']);
        }

        if (!empty($data['Ngay'])) {
            $query->whereDate('cc.Ngay', $data['Ngay']);
        }

        if (!empty($data['TuNgay'])) {
            $query->whereDate('cc.Ngay', '>=', $data['TuNgay']);
        }

        if (!empty($data['DenNgay'])) {
            $query->whereDate('cc.Ngay', '<=', $data['DenNgay']);
        }

        if (!empty($data['TrangThaiChamCong'])) {
            switch ($data['TrangThaiChamCong']) {
                case 'Chua vao ca':
                    $query->whereNull('cc.GioVao')
                        ->whereNull('cc.LoaiNghi');
                    break;

                case 'Dang lam':
                    $query->whereNotNull('cc.GioVao')
                        ->whereNull('cc.GioRa');
                    break;

                case 'Da hoan tat':
                    $query->whereNotNull('cc.GioVao')
                        ->whereNotNull('cc.GioRa');
                    break;

                case 'Nghi':
                    $query->whereNotNull('cc.LoaiNghi');
                    break;
            }
        }

        return response()->json(
            $query->paginate($data['per_page'] ?? 20)->withQueryString()
        );
    }

    public function assignAttendance(Request $request)
    {
        $data = $request->validate([
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'MaCa' => ['required', 'integer', 'exists:CA_LAM,MaCa'],
            'Ngay' => ['required', 'date'],
            'LoaiNghi' => ['nullable', 'string', 'max:50'],
        ]);

        $id = DB::transaction(function () use ($data) {
            $employee = DB::table('NHAN_VIEN')
                ->where('MaNV', $data['MaNV'])
                ->lockForUpdate()
                ->first();

            abort_unless($employee, 404, 'Không tìm thấy nhân viên.');

            if ($employee->TrangThai !== 'Dang lam') {
                throw ValidationException::withMessages([
                    'MaNV' => 'Chỉ nhân viên đang làm mới được xếp ca.',
                ]);
            }

            $shift = DB::table('CA_LAM')->where('MaCa', $data['MaCa'])->first();
            abort_unless($shift, 404, 'Không tìm thấy ca làm.');

            if ($shift->TrangThai !== 'Dang hoat dong') {
                throw ValidationException::withMessages([
                    'MaCa' => 'Ca làm đã ngừng hoạt động.',
                ]);
            }

            if ((int) $employee->MaCS !== (int) $shift->MaCS) {
                throw ValidationException::withMessages([
                    'MaCa' => 'Ca làm không thuộc cơ sở của nhân viên.',
                ]);
            }

            $exists = DB::table('CHAM_CONG')
                ->where('MaNV', $data['MaNV'])
                ->whereDate('Ngay', $data['Ngay'])
                ->where('MaCa', $data['MaCa'])
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'MaCa' => 'Nhân viên đã được xếp ca này trong ngày đã chọn.',
                ]);
            }

            $id = DB::table('CHAM_CONG')->insertGetId([
                'Ngay' => $data['Ngay'],
                'GioVao' => null,
                'GioRa' => null,
                'TangCa' => 0,
                'LoaiNghi' => $data['LoaiNghi'] ?? null,
                'MaNV' => $data['MaNV'],
                'MaCa' => $data['MaCa'],
                'MaCS' => $employee->MaCS,
            ], 'MaCC');

            $this->writeLog(
                'Xep ca nhan vien',
                'CHAM_CONG',
                "Tạo lịch chấm công #{$id} cho nhân viên #{$data['MaNV']}."
            );

            return $id;
        });

        return response()->json([
            'message' => 'Đã xếp ca / tạo lịch chấm công.',
            'MaCC' => $id,
        ], 201);
    }

    public function updateAttendance(Request $request, int $id)
    {
        $data = $request->validate([
            'MaNV' => ['sometimes', 'required', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'MaCa' => ['sometimes', 'required', 'integer', 'exists:CA_LAM,MaCa'],
            'Ngay' => ['sometimes', 'required', 'date'],
            'LoaiNghi' => ['sometimes', 'nullable', 'string', 'max:50'],
            'GioVao' => ['sometimes', 'nullable', 'date'],
            'GioRa' => ['sometimes', 'nullable', 'date'],
            'TangCa' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999'],
        ]);

        DB::transaction(function () use ($id, $data) {
            $attendance = DB::table('CHAM_CONG')
                ->where('MaCC', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($attendance, 404, 'Không tìm thấy bản ghi chấm công.');

            $newEmployeeId = $data['MaNV'] ?? $attendance->MaNV;
            $newShiftId = $data['MaCa'] ?? $attendance->MaCa;
            $newDate = $data['Ngay'] ?? $attendance->Ngay;

            $employee = DB::table('NHAN_VIEN')->where('MaNV', $newEmployeeId)->first();
            abort_unless($employee, 404, 'Không tìm thấy nhân viên.');

            $shift = DB::table('CA_LAM')->where('MaCa', $newShiftId)->first();
            abort_unless($shift, 404, 'Không tìm thấy ca làm.');

            if ((int) $employee->MaCS !== (int) $shift->MaCS) {
                throw ValidationException::withMessages([
                    'MaCa' => 'Ca làm không thuộc cơ sở của nhân viên.',
                ]);
            }

            $identityChanged =
                (int) $attendance->MaNV !== (int) $newEmployeeId
                || (int) $attendance->MaCa !== (int) $newShiftId
                || $attendance->Ngay !== $newDate;

            if ($identityChanged && ($attendance->GioVao !== null || $attendance->GioRa !== null)) {
                throw ValidationException::withMessages([
                    'MaCC' => 'Không thể đổi nhân viên, ca hoặc ngày sau khi đã ghi nhận giờ vào/ra.',
                ]);
            }

            $duplicate = DB::table('CHAM_CONG')
                ->where('MaNV', $newEmployeeId)
                ->whereDate('Ngay', $newDate)
                ->where('MaCa', $newShiftId)
                ->where('MaCC', '<>', $id)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'MaCa' => 'Nhân viên đã có lịch chấm công trùng ca trong ngày này.',
                ]);
            }

            $update = [];

            if (array_key_exists('MaNV', $data)) $update['MaNV'] = $newEmployeeId;
            if (array_key_exists('MaCa', $data)) $update['MaCa'] = $newShiftId;
            if (array_key_exists('Ngay', $data)) $update['Ngay'] = $newDate;
            if (array_key_exists('LoaiNghi', $data)) $update['LoaiNghi'] = $data['LoaiNghi'];
            if (array_key_exists('GioVao', $data)) $update['GioVao'] = $data['GioVao'];
            if (array_key_exists('GioRa', $data)) $update['GioRa'] = $data['GioRa'];
            if (array_key_exists('TangCa', $data)) $update['TangCa'] = $data['TangCa'];

            if (array_key_exists('MaNV', $data) || array_key_exists('MaCa', $data)) {
                $update['MaCS'] = $employee->MaCS;
            }

            $gioVao = array_key_exists('GioVao', $update)
                ? $update['GioVao']
                : $attendance->GioVao;

            $gioRa = array_key_exists('GioRa', $update)
                ? $update['GioRa']
                : $attendance->GioRa;

            if ($gioVao && $gioRa && Carbon::parse($gioRa)->lt(Carbon::parse($gioVao))) {
                throw ValidationException::withMessages([
                    'GioRa' => 'Giờ ra không được trước giờ vào.',
                ]);
            }

            if (!empty($data['LoaiNghi']) && ($gioVao || $gioRa)) {
                throw ValidationException::withMessages([
                    'LoaiNghi' => 'Bản ghi nghỉ không được đồng thời có giờ vào hoặc giờ ra.',
                ]);
            }

            if ($update) {
                DB::table('CHAM_CONG')->where('MaCC', $id)->update($update);

                $this->writeLog(
                    'Dieu chinh cham cong',
                    'CHAM_CONG',
                    "Điều chỉnh bản ghi chấm công #{$id}."
                );
            }
        });

        return response()->json([
            'message' => 'Đã cập nhật lịch / dữ liệu chấm công.',
        ]);
    }

    public function clockIn(int $id)
    {
        DB::transaction(function () use ($id) {
            $attendance = DB::table('CHAM_CONG')
                ->where('MaCC', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($attendance, 404, 'Không tìm thấy bản ghi chấm công.');

            if ($attendance->LoaiNghi) {
                throw ValidationException::withMessages([
                    'MaCC' => 'Nhân viên đã được ghi nhận nghỉ trong ca này.',
                ]);
            }

            $employee = DB::table('NHAN_VIEN')
                ->where('MaNV', $attendance->MaNV)
                ->first();

            if (!$employee || $employee->TrangThai !== 'Dang lam') {
                throw ValidationException::withMessages([
                    'MaNV' => 'Chỉ nhân viên đang làm mới được check-in.',
                ]);
            }

            if ($attendance->GioVao) {
                throw ValidationException::withMessages([
                    'GioVao' => 'Bản ghi này đã check-in.',
                ]);
            }

            $now = now();

            DB::table('CHAM_CONG')->where('MaCC', $id)->update([
                'GioVao' => $now,
            ]);

            $this->writeLog(
                'Check in',
                'CHAM_CONG',
                "Nhân viên #{$attendance->MaNV} check-in bản ghi #{$id} lúc {$now}."
            );
        });

        return response()->json([
            'message' => 'Check-in thành công.',
            'ThoiGian' => now()->toDateTimeString(),
        ]);
    }

    public function clockOut(int $id)
    {
        $result = DB::transaction(function () use ($id) {
            $attendance = DB::table('CHAM_CONG')
                ->where('MaCC', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($attendance, 404, 'Không tìm thấy bản ghi chấm công.');

            if (!$attendance->GioVao) {
                throw ValidationException::withMessages([
                    'GioVao' => 'Nhân viên chưa check-in.',
                ]);
            }

            if ($attendance->GioRa) {
                throw ValidationException::withMessages([
                    'GioRa' => 'Bản ghi này đã check-out.',
                ]);
            }

            $shift = DB::table('CA_LAM')->where('MaCa', $attendance->MaCa)->first();
            abort_unless($shift, 404, 'Không tìm thấy ca làm.');

            $now = now();

            $scheduledStart = Carbon::parse($attendance->Ngay . ' ' . $shift->GioVao);
            $scheduledEnd = Carbon::parse($attendance->Ngay . ' ' . $shift->GioRa);

            // Ca kết thúc vào ngày hôm sau nếu giờ ra nhỏ hơn hoặc bằng giờ vào.
            if ($scheduledEnd->lessThanOrEqualTo($scheduledStart)) {
                $scheduledEnd->addDay();
            }

            $extraMinutes = max(
                0,
                $now->getTimestamp() - $scheduledEnd->getTimestamp()
            ) / 60;

            $overtime = round($extraMinutes / 60, 2);

            DB::table('CHAM_CONG')->where('MaCC', $id)->update([
                'GioRa' => $now,
                'TangCa' => $overtime,
            ]);

            $this->writeLog(
                'Check out',
                'CHAM_CONG',
                "Nhân viên #{$attendance->MaNV} check-out bản ghi #{$id}; tăng ca {$overtime} giờ."
            );

            return [
                'GioRa' => $now->toDateTimeString(),
                'TangCa' => $overtime,
            ];
        });

        return response()->json([
            'message' => 'Check-out thành công.',
            ...$result,
        ]);
    }

    public function deleteAttendance(int $id)
    {
        DB::transaction(function () use ($id) {
            $attendance = DB::table('CHAM_CONG')
                ->where('MaCC', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($attendance, 404, 'Không tìm thấy bản ghi chấm công.');

            if ($attendance->GioVao || $attendance->GioRa) {
                throw ValidationException::withMessages([
                    'MaCC' => 'Không thể xóa bản ghi đã có giờ vào/ra. Hãy dùng chức năng điều chỉnh chấm công.',
                ]);
            }

            DB::table('CHAM_CONG')->where('MaCC', $id)->delete();

            $this->writeLog(
                'Xoa lich cham cong',
                'CHAM_CONG',
                "Xóa bản ghi chấm công #{$id}."
            );
        });

        return response()->json([
            'message' => 'Đã xóa lịch chấm công.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | BẢNG LƯƠNG
    |--------------------------------------------------------------------------
    */

    public function payrolls(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS'],
            'MaNV' => ['nullable', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'KyLuong' => ['nullable', 'date'],
            'TrangThai' => ['nullable', Rule::in(self::PAYROLL_STATUSES)],
            'q' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = DB::table('BANG_LUONG as bl')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'bl.MaNV')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'nv.MaCS')
            ->leftJoin('VI_TRI as vt', 'vt.MaVT', '=', 'nv.MaVT')
            ->select(
                'bl.MaBL',
                'bl.KyLuong',
                'bl.LuongCoBan',
                'bl.PhuCap',
                'bl.Thuong',
                'bl.KhauTru',
                'bl.ThucLinh',
                'bl.TrangThai',
                'bl.MaNV',
                'nv.HoTen',
                'nv.MaCS',
                'cs.TenCS',
                'vt.TenVT'
            )
            ->orderByDesc('bl.KyLuong')
            ->orderBy('nv.HoTen');

        if (!empty($data['MaCS'])) {
            $query->where('nv.MaCS', $data['MaCS']);
        }

        if (!empty($data['MaNV'])) {
            $query->where('bl.MaNV', $data['MaNV']);
        }

        if (!empty($data['KyLuong'])) {
            $month = Carbon::parse($data['KyLuong'])->startOfMonth()->toDateString();
            $query->whereDate('bl.KyLuong', $month);
        }

        if (!empty($data['TrangThai'])) {
            $query->where('bl.TrangThai', $data['TrangThai']);
        }

        if (!empty($data['q'])) {
            $keyword = trim($data['q']);

            $query->where(function ($q) use ($keyword) {
                $q->where('nv.HoTen', 'like', "%{$keyword}%")
                    ->orWhere('bl.MaBL', $keyword)
                    ->orWhere('nv.MaNV', $keyword);
            });
        }

        return response()->json(
            $query->paginate($data['per_page'] ?? 20)->withQueryString()
        );
    }

    public function generatePayroll(Request $request)
    {
        $this->assertActorRole(['Nhan su']);
        $data = $request->validate([
            'KyLuong' => ['required', 'date'],
            'MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS'],
        ]);

        $periodStart = Carbon::parse($data['KyLuong'])->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $periodKey = $periodStart->toDateString();

        $result = DB::transaction(function () use (
            $data,
            $periodStart,
            $periodEnd,
            $periodKey
        ) {
            $employeesQuery = DB::table('NHAN_VIEN')
                ->whereIn('TrangThai', ['Dang lam', 'Tam nghi'])
                ->orderBy('MaNV');

            if (!empty($data['MaCS'])) {
                $employeesQuery->where('MaCS', $data['MaCS']);
            }

            $employees = $employeesQuery->lockForUpdate()->get();

            $created = [];
            $skipped = [];

            foreach ($employees as $employee) {
                $contracts = $this->contractsForPeriod(
                    (int) $employee->MaNV,
                    $periodStart->toDateString(),
                    $periodEnd->toDateString()
                );

                if ($contracts->isEmpty()) {
                    $skipped[] = [
                        'MaNV' => $employee->MaNV,
                        'HoTen' => $employee->HoTen,
                        'LyDo' => 'Không có hợp đồng có hiệu lực trong kỳ.',
                    ];
                    continue;
                }

                if ($contracts->count() > 1) {
                    throw ValidationException::withMessages([
                        'MaNV' => "Nhân viên {$employee->HoTen} có nhiều hợp đồng chồng lấn trong kỳ lương.",
                    ]);
                }

                $this->assertAttendanceComplete(
                    (int) $employee->MaNV,
                    $periodStart->toDateString(),
                    $periodEnd->toDateString()
                );

                $exists = DB::table('BANG_LUONG')
                    ->where('MaNV', $employee->MaNV)
                    ->whereDate('KyLuong', $periodKey)
                    ->exists();

                if ($exists) {
                    $skipped[] = [
                        'MaNV' => $employee->MaNV,
                        'HoTen' => $employee->HoTen,
                        'LyDo' => 'Đã có bảng lương trong kỳ.',
                    ];
                    continue;
                }

                $contract = $contracts->first();
                $baseSalary = round((float) $contract->LuongCoBan, 2);

                $payrollId = DB::table('BANG_LUONG')->insertGetId([
                    'KyLuong' => $periodKey,
                    'LuongCoBan' => $baseSalary,
                    'PhuCap' => 0,
                    'Thuong' => 0,
                    'KhauTru' => 0,
                    'ThucLinh' => $baseSalary,
                    'TrangThai' => 'Nhap',
                    'MaNV' => $employee->MaNV,
                ], 'MaBL');

                $created[] = [
                    'MaBL' => $payrollId,
                    'MaNV' => $employee->MaNV,
                    'HoTen' => $employee->HoTen,
                    'LuongCoBan' => $baseSalary,
                ];
            }

            $this->writeLog(
                'Tao bang luong',
                'BANG_LUONG',
                "Tạo bảng lương kỳ {$periodKey}; tạo mới "
                . count($created)
                . " nhân viên, bỏ qua "
                . count($skipped)
                . " nhân viên."
            );

            return [
                'KyLuong' => $periodKey,
                'SoLuongTaoMoi' => count($created),
                'SoLuongBoQua' => count($skipped),
                'DanhSachTaoMoi' => $created,
                'DanhSachBoQua' => $skipped,
            ];
        });

        return response()->json([
            'message' => 'Đã tổng hợp bảng lương.',
            ...$result,
        ], 201);
    }

    public function updatePayroll(Request $request, int $id)
    {
        $this->assertActorRole(['Nhan su']);
        $data = $request->validate([
            'PhuCap' => ['sometimes', 'required', 'numeric', 'min:0'],
            'Thuong' => ['sometimes', 'required', 'numeric', 'min:0'],
            'KhauTru' => ['sometimes', 'required', 'numeric', 'min:0'],
        ]);

        if (!$data) {
            throw ValidationException::withMessages([
                'BangLuong' => 'Bạn chưa cung cấp khoản phụ cấp, thưởng hoặc khấu trừ cần cập nhật.',
            ]);
        }

        DB::transaction(function () use ($id, $data) {
            $payroll = DB::table('BANG_LUONG')
                ->where('MaBL', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($payroll, 404, 'Không tìm thấy bảng lương.');

            if (!in_array($payroll->TrangThai, ['Nhap', 'Can dieu chinh'], true)) {
                throw ValidationException::withMessages([
                    'TrangThai' => 'Chỉ được sửa bảng lương ở trạng thái Nháp hoặc Cần điều chỉnh.',
                ]);
            }

            $allowance = array_key_exists('PhuCap', $data)
                ? (float) $data['PhuCap']
                : (float) $payroll->PhuCap;

            $bonus = array_key_exists('Thuong', $data)
                ? (float) $data['Thuong']
                : (float) $payroll->Thuong;

            $deduction = array_key_exists('KhauTru', $data)
                ? (float) $data['KhauTru']
                : (float) $payroll->KhauTru;

            $takeHome = round(
                (float) $payroll->LuongCoBan + $allowance + $bonus - $deduction,
                2
            );

            if ($takeHome < 0) {
                throw ValidationException::withMessages([
                    'KhauTru' => 'Tổng khấu trừ không được lớn hơn tổng lương cơ bản, phụ cấp và thưởng.',
                ]);
            }

            DB::table('BANG_LUONG')->where('MaBL', $id)->update([
                'PhuCap' => $allowance,
                'Thuong' => $bonus,
                'KhauTru' => $deduction,
                'ThucLinh' => $takeHome,
            ]);

            $this->writeLog(
                'Cap nhat bang luong',
                'BANG_LUONG',
                "Cập nhật bảng lương #{$id}; thực lĩnh {$takeHome}."
            );
        });

        return response()->json([
            'message' => 'Đã cập nhật bảng lương.',
        ]);
    }

    public function submitPayroll(int $id)
    {
        $this->assertActorRole(['Nhan su']);
        DB::transaction(function () use ($id) {
            $payroll = DB::table('BANG_LUONG')
                ->where('MaBL', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($payroll, 404, 'Không tìm thấy bảng lương.');

            if (!in_array($payroll->TrangThai, ['Nhap', 'Can dieu chinh'], true)) {
                throw ValidationException::withMessages([
                    'TrangThai' => 'Bảng lương không ở trạng thái có thể trình kế toán.',
                ]);
            }

            $periodStart = Carbon::parse($payroll->KyLuong)->startOfMonth();
            $periodEnd = $periodStart->copy()->endOfMonth();

            $contracts = $this->contractsForPeriod(
                (int) $payroll->MaNV,
                $periodStart->toDateString(),
                $periodEnd->toDateString()
            );

            if ($contracts->count() !== 1) {
                throw ValidationException::withMessages([
                    'MaNV' => 'Không thể trình duyệt vì nhân viên không có đúng một hợp đồng có hiệu lực trong kỳ.',
                ]);
            }

            $this->assertAttendanceComplete(
                (int) $payroll->MaNV,
                $periodStart->toDateString(),
                $periodEnd->toDateString()
            );

            $expectedBase = round((float) $contracts->first()->LuongCoBan, 2);

            $takeHome = round(
                $expectedBase
                + (float) $payroll->PhuCap
                + (float) $payroll->Thuong
                - (float) $payroll->KhauTru,
                2
            );

            if ($takeHome < 0) {
                throw ValidationException::withMessages([
                    'KhauTru' => 'Khấu trừ đang lớn hơn tổng thu nhập.',
                ]);
            }

            DB::table('BANG_LUONG')->where('MaBL', $id)->update([
                'LuongCoBan' => $expectedBase,
                'ThucLinh' => $takeHome,
                'TrangThai' => 'Cho ke toan',
            ]);

            $this->writeLog(
                'Trinh bang luong',
                'BANG_LUONG',
                "Trình bảng lương #{$id} cho kế toán xác nhận."
            );
        });

        return response()->json([
            'message' => 'Đã trình bảng lương cho kế toán.',
        ]);
    }

    public function returnPayroll(Request $request, int $id)
    {
        $this->assertActorRole(['Ke toan']);
        $data = $request->validate([
            'LyDo' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        DB::transaction(function () use ($id, $data) {
            $payroll = DB::table('BANG_LUONG')
                ->where('MaBL', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($payroll, 404, 'Không tìm thấy bảng lương.');

            if ($payroll->TrangThai !== 'Cho ke toan') {
                throw ValidationException::withMessages([
                    'TrangThai' => 'Chỉ bảng lương đang chờ kế toán mới được trả về.',
                ]);
            }

            DB::table('BANG_LUONG')->where('MaBL', $id)->update([
                'TrangThai' => 'Can dieu chinh',
            ]);

            $this->writeLog(
                'Tra lai bang luong',
                'BANG_LUONG',
                "Trả bảng lương #{$id} về điều chỉnh. Lý do: {$data['LyDo']}"
            );
        });

        return response()->json([
            'message' => 'Đã trả bảng lương về trạng thái cần điều chỉnh.',
        ]);
    }

    public function approvePayroll(int $id)
    {
        $this->assertActorRole(['Ke toan']);
        DB::transaction(function () use ($id) {
            $payroll = DB::table('BANG_LUONG')
                ->where('MaBL', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($payroll, 404, 'Không tìm thấy bảng lương.');

            if ($payroll->TrangThai !== 'Cho ke toan') {
                throw ValidationException::withMessages([
                    'TrangThai' => 'Chỉ bảng lương đang chờ kế toán mới được xác nhận và chốt.',
                ]);
            }

            $periodStart = Carbon::parse($payroll->KyLuong)->startOfMonth();
            $periodEnd = $periodStart->copy()->endOfMonth();

            $contracts = $this->contractsForPeriod(
                (int) $payroll->MaNV,
                $periodStart->toDateString(),
                $periodEnd->toDateString()
            );

            if ($contracts->count() !== 1) {
                throw ValidationException::withMessages([
                    'MaNV' => 'Không thể chốt vì nhân viên không có đúng một hợp đồng có hiệu lực trong kỳ.',
                ]);
            }

            $this->assertAttendanceComplete(
                (int) $payroll->MaNV,
                $periodStart->toDateString(),
                $periodEnd->toDateString()
            );

            $takeHome = round(
                (float) $payroll->LuongCoBan
                + (float) $payroll->PhuCap
                + (float) $payroll->Thuong
                - (float) $payroll->KhauTru,
                2
            );

            if ($takeHome < 0) {
                throw ValidationException::withMessages([
                    'ThucLinh' => 'Thực lĩnh không hợp lệ, vui lòng kiểm tra lại các khoản thu nhập và khấu trừ.',
                ]);
            }

            DB::table('BANG_LUONG')->where('MaBL', $id)->update([
                'ThucLinh' => $takeHome,
                'TrangThai' => 'Da chot',
            ]);

            $this->writeLog(
                'Chot bang luong',
                'BANG_LUONG',
                "Kế toán xác nhận và chốt bảng lương #{$id}; thực lĩnh {$takeHome}."
            );
        });

        return response()->json([
            'message' => 'Đã xác nhận và chốt bảng lương.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | HÀM HỖ TRỢ NGHIỆP VỤ
    |--------------------------------------------------------------------------
    */

    private function contractsForPeriod(
        int $employeeId,
        string $startDate,
        string $endDate
    ) {
        return DB::table('HOP_DONG')
            ->where('MaNV', $employeeId)
            ->where('TrangThai', '<>', 'Da cham dut')
            ->whereDate('NgayBD', '<=', $endDate)
            ->where(function ($query) use ($startDate) {
                $query->whereNull('NgayKT')
                    ->orWhereDate('NgayKT', '>=', $startDate);
            })
            ->orderByDesc('NgayBD')
            ->orderByDesc('MaHDLD')
            ->get([
                'MaHDLD',
                'MaNV',
                'LoaiHD',
                'NgayBD',
                'NgayKT',
                'LuongCoBan',
                'TrangThai',
            ]);
    }

    private function assertNoOverlappingContract(
        int $employeeId,
        string $startDate,
        ?string $endDate,
        ?int $ignoreContractId = null
    ): void {
        $query = DB::table('HOP_DONG')
            ->where('MaNV', $employeeId)
            ->where('TrangThai', '<>', 'Da cham dut')
            ->whereDate('NgayBD', '<=', $endDate ?? '9999-12-31')
            ->where(function ($q) use ($startDate) {
                $q->whereNull('NgayKT')
                    ->orWhereDate('NgayKT', '>=', $startDate);
            });

        if ($ignoreContractId) {
            $query->where('MaHDLD', '<>', $ignoreContractId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'NgayBD' => 'Thời gian hợp đồng bị chồng lấn với một hợp đồng khác của nhân viên.',
            ]);
        }
    }

    private function assertAttendanceComplete(
        int $employeeId,
        string $startDate,
        string $endDate
    ): void {
        $openAttendance = DB::table('CHAM_CONG')
            ->where('MaNV', $employeeId)
            ->whereDate('Ngay', '>=', $startDate)
            ->whereDate('Ngay', '<=', $endDate)
            ->whereNotNull('GioVao')
            ->whereNull('GioRa')
            ->exists();

        if ($openAttendance) {
            throw ValidationException::withMessages([
                'ChamCong' => "Nhân viên #{$employeeId} còn ca chưa check-out trong kỳ lương.",
            ]);
        }
    }

    private function assertActorRole(array $roles): void
    {
        $actor = request()->user();
        if (!$actor || $actor->TenVaiTro === 'Quan tri he thong') return;
        abort_unless(in_array((string) $actor->TenVaiTro, $roles, true), 403, 'Vai trò hiện tại không được thực hiện thao tác này.');
    }

    private function writeLog(
        string $action,
        string $object,
        string $details
    ): void {
        DB::table('NHAT_KY')->insert([
            'ThoiGian' => now(),
            'HanhDong' => mb_substr($action, 0, 100),
            'DoiTuong' => mb_substr($object, 0, 100),
            'NoiDungThayDoi' => $details,
            'MaTK' => request()->user()?->getAuthIdentifier(),
        ]);
    }
}
