<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HumanResourcesController extends Controller
{
    private const EMPLOYEE_STATUSES = ['Dang lam', 'Tam nghi', 'Da nghi'];
    private const CONTRACT_STATUSES = ['Hieu luc', 'Het han', 'Da cham dut'];
    private const SHIFT_STATUSES = ['Dang hoat dong', 'Ngung hoat dong'];
    private const PAYROLL_STATUSES = ['Nhap', 'Cho ke toan', 'Can dieu chinh', 'Da chot'];

    // =====================================================
    // TỔNG QUAN NHÂN SỰ
    // =====================================================

    public function dashboard(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
        ]);

        $branchId = (int) $data['MaCS'];
        $today = now()->toDateString();
        $month = now()->startOfMonth()->toDateString();

        $employeeQuery = DB::table('NHAN_VIEN')
            ->where('MaCS', $branchId);

        $attendance = DB::table('CHAM_CONG')
            ->where('MaCS', $branchId)
            ->whereDate('Ngay', $today);

        $payroll = DB::table('BANG_LUONG as bl')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'bl.MaNV')
            ->where('nv.MaCS', $branchId)
            ->whereDate('bl.KyLuong', $month);

        return response()->json([
            'TongNhanVien' => (clone $employeeQuery)->count(),
            'DangLam' => (clone $employeeQuery)->where('TrangThai', 'Dang lam')->count(),
            'TamNghi' => (clone $employeeQuery)->where('TrangThai', 'Tam nghi')->count(),
            'DaNghi' => (clone $employeeQuery)->where('TrangThai', 'Da nghi')->count(),
            'ChamCongHomNay' => (clone $attendance)->count(),
            'DaCheckIn' => (clone $attendance)->whereNotNull('GioVao')->count(),
            'DaCheckOut' => (clone $attendance)->whereNotNull('GioRa')->count(),
            'ChoChamCong' => (clone $attendance)->whereNull('GioVao')->whereNull('LoaiNghi')->count(),
            'BangLuongNhap' => (clone $payroll)->where('bl.TrangThai', 'Nhap')->count(),
            'BangLuongChoKeToan' => (clone $payroll)->where('bl.TrangThai', 'Cho ke toan')->count(),
            'BangLuongDaChot' => (clone $payroll)->where('bl.TrangThai', 'Da chot')->count(),
        ]);
    }

    // =====================================================
    // UC6.1 - VỊ TRÍ / CHỨC DANH
    // =====================================================

    public function positions()
    {
        return response()->json(
            DB::table('VI_TRI')
                ->orderBy('TenVT')
                ->get(['MaVT', 'TenVT', 'MoTa'])
        );
    }

    public function storePosition(Request $request)
    {
        $data = $request->validate([
            'TenVT' => ['required', 'string', 'max:100'],
            'MoTa' => ['nullable', 'string'],
        ]);

        $name = trim($data['TenVT']);

        if (DB::table('VI_TRI')->where('TenVT', $name)->exists()) {
            throw ValidationException::withMessages([
                'TenVT' => 'Tên vị trí đã tồn tại.'
            ]);
        }

        $id = DB::table('VI_TRI')->insertGetId([
            'TenVT' => $name,
            'MoTa' => $data['MoTa'] ?? null,
        ], 'MaVT');

        $this->writeLog(
            'Tao vi tri',
            'VI_TRI',
            "Tạo vị trí #{$id}: {$name}."
        );

        return response()->json(
            DB::table('VI_TRI')->where('MaVT', $id)->first(),
            201
        );
    }

    public function updatePosition(Request $request, int $id)
    {
        $data = $request->validate([
            'TenVT' => ['required', 'string', 'max:100'],
            'MoTa' => ['nullable', 'string'],
        ]);

        $position = DB::table('VI_TRI')
            ->where('MaVT', $id)
            ->first();

        abort_unless($position, 404, 'Không tìm thấy vị trí.');

        $name = trim($data['TenVT']);

        $duplicate = DB::table('VI_TRI')
            ->where('TenVT', $name)
            ->where('MaVT', '<>', $id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'TenVT' => 'Tên vị trí đã được sử dụng.'
            ]);
        }

        DB::table('VI_TRI')
            ->where('MaVT', $id)
            ->update([
                'TenVT' => $name,
                'MoTa' => $data['MoTa'] ?? null,
            ]);

        $this->writeLog(
            'Cap nhat vi tri',
            'VI_TRI',
            "Cập nhật vị trí #{$id}: {$name}."
        );

        return response()->json(
            DB::table('VI_TRI')->where('MaVT', $id)->first()
        );
    }

    public function deletePosition(int $id)
    {
        $position = DB::table('VI_TRI')
            ->where('MaVT', $id)
            ->first();

        abort_unless($position, 404, 'Không tìm thấy vị trí.');

        if (DB::table('NHAN_VIEN')->where('MaVT', $id)->exists()) {
            throw ValidationException::withMessages([
                'MaVT' => 'Không thể xóa vị trí đang được nhân viên sử dụng. Hãy chuyển nhân viên sang vị trí khác trước.'
            ]);
        }

        DB::table('VI_TRI')->where('MaVT', $id)->delete();

        $this->writeLog(
            'Xoa vi tri',
            'VI_TRI',
            "Xóa vị trí #{$id}: {$position->TenVT}."
        );

        return response()->json([
            'message' => 'Đã xóa vị trí.'
        ]);
    }

    // =====================================================
    // UC6.1 - HỒ SƠ NHÂN VIÊN
    // =====================================================

    public function employees(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'q' => ['nullable', 'string', 'max:120'],
            'TrangThai' => ['nullable', 'in:Dang lam,Tam nghi,Da nghi'],
            'MaVT' => ['nullable', 'integer', 'exists:VI_TRI,MaVT'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = DB::table('NHAN_VIEN as nv')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'nv.MaCS')
            ->leftJoin('VI_TRI as vt', 'vt.MaVT', '=', 'nv.MaVT')
            ->where('nv.MaCS', $data['MaCS'])
            ->select([
                'nv.MaNV',
                'nv.HoTen',
                'nv.NgaySinh',
                'nv.SDT',
                'nv.Email',
                'nv.NgayVaoLam',
                'nv.TrangThai',
                'nv.MaCS',
                'nv.MaVT',
                'cs.TenCS',
                'vt.TenVT',
            ])
            ->orderByDesc('nv.MaNV');

        if (!empty($data['TrangThai'])) {
            $query->where('nv.TrangThai', $data['TrangThai']);
        }

        if (!empty($data['MaVT'])) {
            $query->where('nv.MaVT', $data['MaVT']);
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
        $data = $this->validateEmployee($request);

        $this->assertBranchActive((int) $data['MaCS']);
        $this->assertPositionExists($data['MaVT'] ?? null);

        $id = DB::table('NHAN_VIEN')->insertGetId([
            'HoTen' => trim($data['HoTen']),
            'NgaySinh' => $data['NgaySinh'] ?? null,
            'SDT' => $data['SDT'] ?? null,
            'Email' => $data['Email'] ?? null,
            'NgayVaoLam' => $data['NgayVaoLam'] ?? null,
            'TrangThai' => $data['TrangThai'] ?? 'Dang lam',
            'MaCS' => $data['MaCS'],
            'MaVT' => $data['MaVT'] ?? null,
        ], 'MaNV');

        $this->writeLog(
            'Tao nhan vien',
            'NHAN_VIEN',
            "Tạo nhân viên #{$id}: {$data['HoTen']}."
        );

        return response()->json($this->employeePayload($id), 201);
    }

    public function updateEmployee(Request $request, int $id)
    {
        $employee = DB::table('NHAN_VIEN')
            ->where('MaNV', $id)
            ->first();

        abort_unless($employee, 404, 'Không tìm thấy nhân viên.');

        $data = $this->validateEmployee($request);

        $this->assertBranchActive((int) $data['MaCS']);
        $this->assertPositionExists($data['MaVT'] ?? null);

        if (
            $employee->TrangThai === 'Da nghi'
            && ($data['TrangThai'] ?? 'Da nghi') !== 'Da nghi'
        ) {
            throw ValidationException::withMessages([
                'TrangThai' => 'Nhân viên đã nghỉ. Không tự kích hoạt lại hồ sơ đã kết thúc; hãy tạo hồ sơ nhân viên mới nếu cần.'
            ]);
        }

        DB::table('NHAN_VIEN')
            ->where('MaNV', $id)
            ->update([
                'HoTen' => trim($data['HoTen']),
                'NgaySinh' => $data['NgaySinh'] ?? null,
                'SDT' => $data['SDT'] ?? null,
                'Email' => $data['Email'] ?? null,
                'NgayVaoLam' => $data['NgayVaoLam'] ?? null,
                'TrangThai' => $data['TrangThai'] ?? $employee->TrangThai,
                'MaCS' => $data['MaCS'],
                'MaVT' => $data['MaVT'] ?? null,
            ]);

        $this->writeLog(
            'Cap nhat nhan vien',
            'NHAN_VIEN',
            "Cập nhật hồ sơ nhân viên #{$id}."
        );

        return response()->json($this->employeePayload($id));
    }

    public function changeEmployeeStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'TrangThai' => ['required', 'in:Dang lam,Tam nghi,Da nghi'],
        ]);

        $employee = DB::table('NHAN_VIEN')
            ->where('MaNV', $id)
            ->first();

        abort_unless($employee, 404, 'Không tìm thấy nhân viên.');

        if (
            $employee->TrangThai === 'Da nghi'
            && $data['TrangThai'] !== 'Da nghi'
        ) {
            throw ValidationException::withMessages([
                'TrangThai' => 'Hồ sơ đã nghỉ không thể tự kích hoạt lại.'
            ]);
        }

        $openAttendance = DB::table('CHAM_CONG')
            ->where('MaNV', $id)
            ->whereNotNull('GioVao')
            ->whereNull('GioRa')
            ->exists();

        if ($openAttendance && $data['TrangThai'] === 'Da nghi') {
            throw ValidationException::withMessages([
                'TrangThai' => 'Nhân viên còn ca chưa chấm công ra. Hãy hoàn tất chấm công trước khi chuyển trạng thái đã nghỉ.'
            ]);
        }

        DB::table('NHAN_VIEN')
            ->where('MaNV', $id)
            ->update([
                'TrangThai' => $data['TrangThai']
            ]);

        $this->writeLog(
            'Doi trang thai nhan vien',
            'NHAN_VIEN',
            "Nhân viên #{$id}: {$employee->TrangThai} → {$data['TrangThai']}."
        );

        return response()->json($this->employeePayload($id));
    }

    // =====================================================
    // UC6.1 - HỢP ĐỒNG LAO ĐỘNG
    // =====================================================

    public function contracts(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'MaNV' => ['nullable', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'TrangThai' => ['nullable', 'in:Hieu luc,Het han,Da cham dut'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = DB::table('HOP_DONG as hd')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'hd.MaNV')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'nv.MaCS')
            ->where('nv.MaCS', $data['MaCS'])
            ->select([
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
            ])
            ->orderByDesc('hd.NgayBD')
            ->orderByDesc('hd.MaHDLD');

        if (!empty($data['MaNV'])) {
            $query->where('hd.MaNV', $data['MaNV']);
        }

        if (!empty($data['TrangThai'])) {
            $query->where('hd.TrangThai', $data['TrangThai']);
        }

        return response()->json(
            $query->paginate($data['per_page'] ?? 15)->withQueryString()
        );
    }

    public function storeContract(Request $request)
    {
        $data = $this->validateContract($request);
        $employee = $this->employeeOrFail((int) $data['MaNV']);

        if (($data['TrangThai'] ?? 'Hieu luc') === 'Hieu luc') {
            $this->assertNoContractOverlap(
                (int) $data['MaNV'],
                $data['NgayBD'],
                $data['NgayKT'] ?? null
            );
        }

        $id = DB::table('HOP_DONG')->insertGetId([
            'LoaiHD' => trim($data['LoaiHD']),
            'NgayBD' => $data['NgayBD'],
            'NgayKT' => $data['NgayKT'] ?? null,
            'LuongCoBan' => round((float) $data['LuongCoBan'], 2),
            'TrangThai' => $data['TrangThai'] ?? 'Hieu luc',
            'MaNV' => $data['MaNV'],
        ], 'MaHDLD');

        $this->writeLog(
            'Tao hop dong',
            'HOP_DONG',
            "Tạo hợp đồng #{$id} cho nhân viên #{$employee->MaNV}."
        );

        return response()->json($this->contractPayload($id), 201);
    }

    public function updateContract(Request $request, int $id)
    {
        $contract = DB::table('HOP_DONG')
            ->where('MaHDLD', $id)
            ->first();

        abort_unless($contract, 404, 'Không tìm thấy hợp đồng.');

        if ($contract->TrangThai === 'Da cham dut') {
            throw ValidationException::withMessages([
                'TrangThai' => 'Hợp đồng đã chấm dứt, không thể sửa.'
            ]);
        }

        $data = $this->validateContract($request);
        $employee = $this->employeeOrFail((int) $data['MaNV']);

        if (($data['TrangThai'] ?? $contract->TrangThai) === 'Hieu luc') {
            $this->assertNoContractOverlap(
                (int) $data['MaNV'],
                $data['NgayBD'],
                $data['NgayKT'] ?? null,
                $id
            );
        }

        DB::table('HOP_DONG')
            ->where('MaHDLD', $id)
            ->update([
                'LoaiHD' => trim($data['LoaiHD']),
                'NgayBD' => $data['NgayBD'],
                'NgayKT' => $data['NgayKT'] ?? null,
                'LuongCoBan' => round((float) $data['LuongCoBan'], 2),
                'TrangThai' => $data['TrangThai'] ?? $contract->TrangThai,
                'MaNV' => $data['MaNV'],
            ]);

        $this->writeLog(
            'Cap nhat hop dong',
            'HOP_DONG',
            "Cập nhật hợp đồng #{$id} của nhân viên #{$employee->MaNV}."
        );

        return response()->json($this->contractPayload($id));
    }

    public function endContract(int $id)
    {
        $contract = DB::table('HOP_DONG')
            ->where('MaHDLD', $id)
            ->first();

        abort_unless($contract, 404, 'Không tìm thấy hợp đồng.');

        if ($contract->TrangThai === 'Da cham dut') {
            return response()->json($this->contractPayload($id));
        }

        $today = now()->toDateString();

        if ($contract->NgayBD > $today) {
            throw ValidationException::withMessages([
                'NgayBD' => 'Không thể chấm dứt hợp đồng trước ngày bắt đầu.'
            ]);
        }

        $endDate = $contract->NgayKT && $contract->NgayKT < $today
            ? $contract->NgayKT
            : $today;

        DB::table('HOP_DONG')
            ->where('MaHDLD', $id)
            ->update([
                'NgayKT' => $endDate,
                'TrangThai' => 'Da cham dut',
            ]);

        $this->writeLog(
            'Cham dut hop dong',
            'HOP_DONG',
            "Chấm dứt hợp đồng #{$id} ngày {$today}."
        );

        return response()->json($this->contractPayload($id));
    }
            $contract = DB::table('HOP_DONG')
            ->where('MaHDLD', $id)
            ->first();

        abort_unless($contract, 404, 'Không tìm thấy hợp đồng.');

        if ($contract->TrangThai === 'Da cham dut') {
            throw ValidationException::withMessages([
                'TrangThai' => 'Hợp đồng đã chấm dứt, không thể sửa.'
            ]);
        }

        $data = $this->validateContract($request);
        $employee = $this->employeeOrFail((int) $data['MaNV']);

        if (($data['TrangThai'] ?? $contract->TrangThai) === 'Hieu luc') {
            $this->assertNoContractOverlap(
                (int) $data['MaNV'],
                $data['NgayBD'],
                $data['NgayKT'] ?? null,
                $id
            );
        }

        DB::table('HOP_DONG')->where('MaHDLD', $id)->update([
            'LoaiHD' => trim($data['LoaiHD']),
            'NgayBD' => $data['NgayBD'],
            'NgayKT' => $data['NgayKT'] ?? null,
            'LuongCoBan' => round((float) $data['LuongCoBan'], 2),
            'TrangThai' => $data['TrangThai'] ?? $contract->TrangThai,
            'MaNV' => $data['MaNV'],
        ]);

        $this->writeLog(
            'Cap nhat hop dong',
            'HOP_DONG',
            "Cập nhật hợp đồng #{$id} của nhân viên #{$employee->MaNV}."
        );

        return response()->json($this->contractPayload($id));
    }

    public function endContract(int $id)
    {
        $contract = DB::table('HOP_DONG')
            ->where('MaHDLD', $id)
            ->first();

        abort_unless($contract, 404, 'Không tìm thấy hợp đồng.');

        if ($contract->TrangThai === 'Da cham dut') {
            return response()->json($this->contractPayload($id));
        }

        $today = now()->toDateString();

        if ($contract->NgayBD > $today) {
            throw ValidationException::withMessages([
                'NgayBD' => 'Không thể chấm dứt hợp đồng trước ngày bắt đầu.'
            ]);
        }

        $endDate = $contract->NgayKT && $contract->NgayKT < $today
            ? $contract->NgayKT
            : $today;

        DB::table('HOP_DONG')->where('MaHDLD', $id)->update([
            'NgayKT' => $endDate,
            'TrangThai' => 'Da cham dut',
        ]);

        $this->writeLog(
            'Cham dut hop dong',
            'HOP_DONG',
            "Chấm dứt hợp đồng #{$id} ngày {$today}."
        );

        return response()->json($this->contractPayload($id));
    }

    // =====================================================
    // UC6.2 - CA LÀM
    // =====================================================

    public function shifts(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'includeInactive' => ['nullable', 'boolean'],
        ]);

        $query = DB::table('CA_LAM')
            ->where('MaCS', $data['MaCS'])
            ->orderBy('GioVao')
            ->orderBy('TenCa');

        if (empty($data['includeInactive'])) {
            $query->where('TrangThai', 'Dang hoat dong');
        }

        return response()->json($query->get([
            'MaCa', 'TenCa', 'GioVao', 'GioRa', 'MaCS', 'TrangThai'
        ]));
    }

    public function storeShift(Request $request)
    {
        $data = $this->validateShift($request);
        $this->assertBranchActive((int) $data['MaCS']);

        if ($data['GioVao'] === $data['GioRa']) {
            throw ValidationException::withMessages([
                'GioRa' => 'Giờ vào và giờ ra không được trùng nhau.'
            ]);
        }

        $id = DB::table('CA_LAM')->insertGetId([
            'TenCa' => trim($data['TenCa']),
            'GioVao' => $this->normalizeTime($data['GioVao']),
            'GioRa' => $this->normalizeTime($data['GioRa']),
            'MaCS' => $data['MaCS'],
            'TrangThai' => $data['TrangThai'] ?? 'Dang hoat dong',
        ], 'MaCa');

        $this->writeLog(
            'Tao ca lam',
            'CA_LAM',
            "Tạo ca #{$id}: {$data['TenCa']}."
        );

        return response()->json(
            DB::table('CA_LAM')->where('MaCa', $id)->first(),
            201
        );
    }

    public function updateShift(Request $request, int $id)
    {
        $shift = DB::table('CA_LAM')
            ->where('MaCa', $id)
            ->first();

        abort_unless($shift, 404, 'Không tìm thấy ca làm.');

        $data = $this->validateShift($request);

        if ((int) $data['MaCS'] !== (int) $shift->MaCS) {
            throw ValidationException::withMessages([
                'MaCS' => 'Không chuyển ca làm sang cơ sở khác. Hãy tạo ca mới tại cơ sở đích.'
            ]);
        }

        if ($data['GioVao'] === $data['GioRa']) {
            throw ValidationException::withMessages([
                'GioRa' => 'Giờ vào và giờ ra không được trùng nhau.'
            ]);
        }

        DB::table('CA_LAM')->where('MaCa', $id)->update([
            'TenCa' => trim($data['TenCa']),
            'GioVao' => $this->normalizeTime($data['GioVao']),
            'GioRa' => $this->normalizeTime($data['GioRa']),
            'TrangThai' => $data['TrangThai'] ?? $shift->TrangThai,
        ]);

        $this->writeLog(
            'Cap nhat ca lam',
            'CA_LAM',
            "Cập nhật ca #{$id}."
        );

        return response()->json(
            DB::table('CA_LAM')->where('MaCa', $id)->first()
        );
    }

    public function disableShift(int $id)
    {
        $shift = DB::table('CA_LAM')
            ->where('MaCa', $id)
            ->first();

        abort_unless($shift, 404, 'Không tìm thấy ca làm.');

        DB::table('CA_LAM')->where('MaCa', $id)->update([
            'TrangThai' => 'Ngung hoat dong'
        ]);

        $this->writeLog(
            'Ngung ca lam',
            'CA_LAM',
            "Ngừng hoạt động ca #{$id}."
        );

        return response()->json([
            'message' => 'Đã ngừng hoạt động ca làm.'
        ]);
    }

    // =====================================================
    // UC6.2 / UC6.3 - CHẤM CÔNG VÀ ĐIỀU PHỐI NHÂN SỰ
    // =====================================================

    public function attendance(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'MaNV' => ['nullable', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'MaCa' => ['nullable', 'integer', 'exists:CA_LAM,MaCa'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = DB::table('CHAM_CONG as cc')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'cc.MaNV')
            ->join('CA_LAM as ca', 'ca.MaCa', '=', 'cc.MaCa')
            ->leftJoin('VI_TRI as vt', 'vt.MaVT', '=', 'nv.MaVT')
            ->where('cc.MaCS', $data['MaCS'])
            ->select([
                'cc.MaCC',
                'cc.Ngay',
                'cc.GioVao',
                'cc.GioRa',
                'cc.TangCa',
                'cc.LoaiNghi',
                'cc.MaNV',
                'cc.MaCa',
                'cc.MaCS',
                'nv.HoTen',
                'nv.TrangThai as TrangThaiNhanVien',
                'vt.TenVT',
                'ca.TenCa',
                'ca.GioVao as GioVaoCa',
                'ca.GioRa as GioRaCa',
            ])
            ->orderByDesc('cc.Ngay')
            ->orderBy('ca.GioVao')
            ->orderBy('nv.HoTen');

        if (!empty($data['from'])) {
            $query->whereDate('cc.Ngay', '>=', $data['from']);
        }

        if (!empty($data['to'])) {
            $query->whereDate('cc.Ngay', '<=', $data['to']);
        }

        if (!empty($data['MaNV'])) {
            $query->where('cc.MaNV', $data['MaNV']);
        }

        if (!empty($data['MaCa'])) {
            $query->where('cc.MaCa', $data['MaCa']);
        }

        return response()->json(
            $query->paginate($data['per_page'] ?? 20)->withQueryString()
        );
    }

    /** Tạo lịch phân ca; nếu có giờ vào/ra thì đồng thời ghi nhận chấm công. */
    public function assignAttendance(Request $request)
    {
        $data = $request->validate([
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'MaCa' => ['required', 'integer', 'exists:CA_LAM,MaCa'],
            'Ngay' => ['required', 'date_format:Y-m-d'],
            'GioVao' => ['nullable', 'date'],
            'GioRa' => ['nullable', 'date'],
            'TangCa' => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'LoaiNghi' => ['nullable', 'string', 'max:50'],
        ]);

        $employee = $this->employeeOrFail((int) $data['MaNV']);

        $shift = DB::table('CA_LAM')
            ->where('MaCa', $data['MaCa'])
            ->first();

        abort_unless($shift, 404, 'Không tìm thấy ca làm.');

        if ($employee->TrangThai !== 'Dang lam') {
            throw ValidationException::withMessages([
                'MaNV' => 'Chỉ nhân viên đang làm mới được xếp ca.'
            ]);
        }

        if ((int) $employee->MaCS !== (int) $shift->MaCS) {
            throw ValidationException::withMessages([
                'MaCa' => 'Ca làm không thuộc cơ sở của nhân viên.'
            ]);
        }

        if ($shift->TrangThai !== 'Dang hoat dong') {
            throw ValidationException::withMessages([
                'MaCa' => 'Ca làm đã ngừng hoạt động.'
            ]);
        }

        $this->assertPunchPair(
            $data['GioVao'] ?? null,
            $data['GioRa'] ?? null
        );

        if (
            empty($data['GioVao'])
            && empty($data['GioRa'])
            && empty($data['LoaiNghi'])
            && (float) ($data['TangCa'] ?? 0) > 0
        ) {
            throw ValidationException::withMessages([
                'TangCa' => 'Chỉ ghi nhận tăng ca sau khi có giờ vào/ra hoặc dữ liệu chấm công đầy đủ.'
            ]);
        }

        if (
            !empty($data['LoaiNghi'])
            && (!empty($data['GioVao']) || !empty($data['GioRa']))
        ) {
            throw ValidationException::withMessages([
                'LoaiNghi' => 'Bản ghi nghỉ không được đồng thời có giờ vào/ra.'
            ]);
        }

        try {
            $id = DB::table('CHAM_CONG')->insertGetId([
                'Ngay' => $data['Ngay'],
                'GioVao' => !empty($data['GioVao'])
                    ? Carbon::parse($data['GioVao'])->format('Y-m-d H:i:s')
                    : null,
                'GioRa' => !empty($data['GioRa'])
                    ? Carbon::parse($data['GioRa'])->format('Y-m-d H:i:s')
                    : null,
                'TangCa' => round((float) ($data['TangCa'] ?? 0), 2),
                'LoaiNghi' => $data['LoaiNghi'] ?? null,
                'MaNV' => $data['MaNV'],
                'MaCa' => $data['MaCa'],
                'MaCS' => $employee->MaCS,
            ], 'MaCC');

        } catch (\Illuminate\Database\QueryException $exception) {
            if (
                str_contains($exception->getMessage(), 'Duplicate')
                || str_contains($exception->getMessage(), '1062')
            ) {
                throw ValidationException::withMessages([
                    'MaNV' => 'Nhân viên đã được xếp ca này trong ngày đã chọn.'
                ]);
            }

            throw $exception;
        }

        $this->writeLog(
            'Xep ca nhan vien',
            'CHAM_CONG',
            "Tạo lịch/chấm công #{$id} cho nhân viên #{$data['MaNV']} ngày {$data['Ngay']}."
        );

        return response()->json($this->attendancePayload($id), 201);
    }

    public function updateAttendance(Request $request, int $id)
    {
        $record = DB::table('CHAM_CONG')
            ->where('MaCC', $id)
            ->first();

        abort_unless($record, 404, 'Không tìm thấy bản ghi chấm công.');

        $data = $request->validate([
            'GioVao' => ['sometimes', 'nullable', 'date'],
            'GioRa' => ['sometimes', 'nullable', 'date'],
            'TangCa' => ['sometimes', 'numeric', 'min:0', 'max:99.99'],
            'LoaiNghi' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $in = array_key_exists('GioVao', $data)
            ? $data['GioVao']
            : $record->GioVao;

        $out = array_key_exists('GioRa', $data)
            ? $data['GioRa']
            : $record->GioRa;

        $leave = array_key_exists('LoaiNghi', $data)
            ? $data['LoaiNghi']
            : $record->LoaiNghi;

        $this->assertPunchPair($in, $out);

        if (!empty($leave) && (!empty($in) || !empty($out))) {
            throw ValidationException::withMessages([
                'LoaiNghi' => 'Bản ghi nghỉ không được đồng thời có giờ vào/ra.'
            ]);
        }

        if ($in && $out && Carbon::parse($out)->lt(Carbon::parse($in))) {
            throw ValidationException::withMessages([
                'GioRa' => 'Giờ ra phải sau hoặc bằng giờ vào.'
            ]);
        }

        DB::table('CHAM_CONG')->where('MaCC', $id)->update([
            'GioVao' => $in
                ? Carbon::parse($in)->format('Y-m-d H:i:s')
                : null,
            'GioRa' => $out
                ? Carbon::parse($out)->format('Y-m-d H:i:s')
                : null,
            'TangCa' => array_key_exists('TangCa', $data)
                ? round((float) $data['TangCa'], 2)
                : $record->TangCa,
            'LoaiNghi' => $leave,
        ]);

        $this->writeLog(
            'Dieu chinh cham cong',
            'CHAM_CONG',
            "Điều chỉnh bản ghi chấm công #{$id}."
        );

        return response()->json($this->attendancePayload($id));
    }

    public function clockIn(int $id)
    {
        DB::transaction(function () use ($id) {
            $record = DB::table('CHAM_CONG')
                ->where('MaCC', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($record, 404, 'Không tìm thấy lịch làm việc.');

            if (!empty($record->LoaiNghi)) {
                throw ValidationException::withMessages([
                    'MaCC' => 'Bản ghi này đã được đánh dấu nghỉ.'
                ]);
            }

            if ($record->GioVao) {
                throw ValidationException::withMessages([
                    'GioVao' => 'Nhân viên đã check-in.'
                ]);
            }

            DB::table('CHAM_CONG')->where('MaCC', $id)->update([
                'GioVao' => now(),
            ]);

            $this->writeLog(
                'Check in',
                'CHAM_CONG',
                "Check-in bản ghi #{$id}."
            );
        });

        return response()->json($this->attendancePayload($id));
    }

    public function clockOut(Request $request, int $id)
    {
        $data = $request->validate([
            'TangCa' => ['nullable', 'numeric', 'min:0', 'max:99.99'],
        ]);

        DB::transaction(function () use ($id, $data) {
            $record = DB::table('CHAM_CONG')
                ->where('MaCC', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($record, 404, 'Không tìm thấy lịch làm việc.');

            if (!$record->GioVao) {
                throw ValidationException::withMessages([
                    'GioVao' => 'Chưa check-in, không thể check-out.'
                ]);
            }

            if ($record->GioRa) {
                throw ValidationException::withMessages([
                    'GioRa' => 'Nhân viên đã check-out.'
                ]);
            }

            $now = now();

            if ($now->lt(Carbon::parse($record->GioVao))) {
                throw ValidationException::withMessages([
                    'GioRa' => 'Thời điểm check-out không hợp lệ.'
                ]);
            }

            DB::table('CHAM_CONG')->where('MaCC', $id)->update([
                'GioRa' => $now,
                'TangCa' => round(
                    (float) ($data['TangCa'] ?? $record->TangCa),
                    2
                ),
            ]);

            $this->writeLog(
                'Check out',
                'CHAM_CONG',
                "Check-out bản ghi #{$id}."
            );
        });

        return response()->json($this->attendancePayload($id));
    }

    public function deleteAttendance(int $id)
        {
        $record = DB::table('CHAM_CONG')
            ->where('MaCC', $id)
            ->first();

        abort_unless($record, 404, 'Không tìm thấy bản ghi chấm công.');

        if ($record->GioVao || $record->GioRa || $record->LoaiNghi) {
            throw ValidationException::withMessages([
                'MaCC' => 'Không xóa bản ghi đã chấm công hoặc đã ghi nhận nghỉ. Hãy điều chỉnh dữ liệu thay vì xóa.'
            ]);
        }

        DB::table('CHAM_CONG')->where('MaCC', $id)->delete();

        $this->writeLog(
            'Huy xep ca',
            'CHAM_CONG',
            "Xóa lịch phân ca chưa chấm công #{$id}."
        );

        return response()->json([
            'message' => 'Đã hủy lịch phân ca.'
        ]);
    }

    // =====================================================
    // UC6.4 - BẢNG LƯƠNG
    // =====================================================

    public function payrolls(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'KyLuong' => ['nullable', 'date_format:Y-m-d'],
            'TrangThai' => ['nullable', 'in:Nhap,Cho ke toan,Can dieu chinh,Da chot'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = DB::table('BANG_LUONG as bl')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'bl.MaNV')
            ->leftJoin('VI_TRI as vt', 'vt.MaVT', '=', 'nv.MaVT')
            ->where('nv.MaCS', $data['MaCS'])
            ->select([
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
                'vt.TenVT',
            ])
            ->orderByDesc('bl.KyLuong')
            ->orderBy('nv.HoTen');

        if (!empty($data['KyLuong'])) {
            $query->whereDate('bl.KyLuong', $data['KyLuong']);
        }

        if (!empty($data['TrangThai'])) {
            $query->where('bl.TrangThai', $data['TrangThai']);
        }

        if (!empty($data['q'])) {
            $keyword = trim($data['q']);

            $query->where(function ($q) use ($keyword) {
                $q->where('nv.HoTen', 'like', "%{$keyword}%")
                    ->orWhere('nv.MaNV', $keyword)
                    ->orWhere('bl.MaBL', $keyword);
            });
        }

        return response()->json(
            $query->paginate($data['per_page'] ?? 20)->withQueryString()
        );
    }

    /** Sinh bảng lương nháp cho nhân viên có hợp đồng giao với kỳ lương. */
    public function generatePayroll(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'KyLuong' => ['required', 'date_format:Y-m-d'],
        ]);

        $period = Carbon::parse($data['KyLuong'])->startOfMonth();

        if ($data['KyLuong'] !== $period->toDateString()) {
            throw ValidationException::withMessages([
                'KyLuong' => 'Kỳ lương phải là ngày đầu tiên của tháng, ví dụ 2026-11-01.'
            ]);
        }

        $periodEnd = $period->copy()->endOfMonth()->toDateString();
        $periodStart = $period->toDateString();

        $employees = DB::table('NHAN_VIEN')
            ->where('MaCS', $data['MaCS'])
            ->whereIn('TrangThai', ['Dang lam', 'Tam nghi'])
            ->orderBy('MaNV')
            ->get();

        $created = [];
        $skipped = [];

        DB::transaction(function () use (
            $employees,
            $periodStart,
            $periodEnd,
            &$created,
            &$skipped
        ) {
            foreach ($employees as $employee) {
                $contracts = DB::table('HOP_DONG')
                    ->where('MaNV', $employee->MaNV)
                    ->whereIn('TrangThai', ['Hieu luc', 'Het han'])
                    ->whereDate('NgayBD', '<=', $periodEnd)
                    ->where(function ($q) use ($periodStart) {
                        $q->whereNull('NgayKT')
                            ->orWhereDate('NgayKT', '>=', $periodStart);
                    })
                    ->orderByDesc('NgayBD')
                    ->get();

                if ($contracts->count() !== 1) {
                    $skipped[] = [
                        'MaNV' => $employee->MaNV,
                        'HoTen' => $employee->HoTen,
                        'LyDo' => $contracts->isEmpty()
                            ? 'Không có hợp đồng phù hợp kỳ lương.'
                            : 'Có nhiều hợp đồng giao nhau trong kỳ; cần kiểm tra.'
                    ];

                    continue;
                }

                $existing = DB::table('BANG_LUONG')
                    ->where('MaNV', $employee->MaNV)
                    ->whereDate('KyLuong', $periodStart)
                    ->first();

                if ($existing) {
                    $skipped[] = [
                        'MaNV' => $employee->MaNV,
                        'HoTen' => $employee->HoTen,
                        'LyDo' => 'Đã có bảng lương trong kỳ này.'
                    ];

                    continue;
                }

                $salary = round(
                    (float) $contracts->first()->LuongCoBan,
                    2
                );

                $id = DB::table('BANG_LUONG')->insertGetId([
                    'KyLuong' => $periodStart,
                    'LuongCoBan' => $salary,
                    'PhuCap' => 0,
                    'Thuong' => 0,
                    'KhauTru' => 0,
                    'ThucLinh' => $salary,
                    'TrangThai' => 'Nhap',
                    'MaNV' => $employee->MaNV,
                ], 'MaBL');

                $created[] = $id;
            }
        });

        $this->writeLog(
            'Tao bang luong',
            'BANG_LUONG',
            "Sinh bảng lương kỳ {$periodStart}: tạo "
                . count($created)
                . ', bỏ qua '
                . count($skipped)
                . ' nhân viên.'
        );

        return response()->json([
            'message' => 'Đã tạo bảng lương nháp. Hãy kiểm tra chấm công và điều chỉnh phụ cấp/thưởng/khấu trừ trước khi gửi Kế toán.',
            'KyLuong' => $periodStart,
            'SoTaoMoi' => count($created),
            'MaBLMoi' => $created,
            'SoBoQua' => count($skipped),
            'NhanVienBoQua' => $skipped,
        ], 201);
    }

    public function updatePayroll(Request $request, int $id)
    {
        $data = $request->validate([
            'PhuCap' => ['required', 'numeric', 'min:0'],
            'Thuong' => ['required', 'numeric', 'min:0'],
            'KhauTru' => ['required', 'numeric', 'min:0'],
        ]);

        $payroll = DB::table('BANG_LUONG')
            ->where('MaBL', $id)
            ->lockForUpdate()
            ->first();

        abort_unless($payroll, 404, 'Không tìm thấy bảng lương.');

        if (!in_array($payroll->TrangThai, ['Nhap', 'Can dieu chinh'], true)) {
            throw ValidationException::withMessages([
                'TrangThai' => 'Chỉ được chỉnh sửa bảng lương Nháp hoặc Cần điều chỉnh.'
            ]);
        }

        $takeHome = round(
            (float) $payroll->LuongCoBan
            + (float) $data['PhuCap']
            + (float) $data['Thuong']
            - (float) $data['KhauTru'],
            2
        );

        if ($takeHome < 0) {
            throw ValidationException::withMessages([
                'KhauTru' => 'Khấu trừ không được làm thực lĩnh âm.'
            ]);
        }

        DB::table('BANG_LUONG')->where('MaBL', $id)->update([
            'PhuCap' => round((float) $data['PhuCap'], 2),
            'Thuong' => round((float) $data['Thuong'], 2),
            'KhauTru' => round((float) $data['KhauTru'], 2),
            'ThucLinh' => $takeHome,
        ]);

        $this->writeLog(
            'Cap nhat bang luong',
            'BANG_LUONG',
            "Điều chỉnh phụ cấp/thưởng/khấu trừ bảng lương #{$id}."
        );

        return response()->json($this->payrollPayload($id));
    }

    public function submitPayroll(Request $request, int $id)
    {
        $data = $request->validate([
            'MaNVThucHien' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
        ]);

        DB::transaction(function () use ($id, $data) {
            $payroll = DB::table('BANG_LUONG')
                ->where('MaBL', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($payroll, 404, 'Không tìm thấy bảng lương.');

            if (!in_array($payroll->TrangThai, ['Nhap', 'Can dieu chinh'], true)) {
                throw ValidationException::withMessages([
                    'TrangThai' => 'Bảng lương không ở trạng thái có thể trình Kế toán.'
                ]);
            }

            $employee = $this->employeeOrFail((int) $payroll->MaNV);

            $this->assertActorForBranch(
                (int) $data['MaNVThucHien'],
                (int) $employee->MaCS
            );

            $this->validatePayrollSourceData($payroll);

            DB::table('BANG_LUONG')
                ->where('MaBL', $id)
                ->update([
                    'TrangThai' => 'Cho ke toan'
                ]);

            $this->writeLog(
                'Trinh duyet bang luong',
                'BANG_LUONG',
                "Nhân viên thực hiện #{$data['MaNVThucHien']} trình bảng lương #{$id} của nhân viên #{$payroll->MaNV} cho Kế toán."
            );
        });

        return response()->json($this->payrollPayload($id));
    }

    public function returnPayroll(Request $request, int $id)
    {
        $data = $request->validate([
            'MaNVThucHien' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'LyDo' => ['required', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($id, $data) {
            $payroll = DB::table('BANG_LUONG')
                ->where('MaBL', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($payroll, 404, 'Không tìm thấy bảng lương.');

            if ($payroll->TrangThai !== 'Cho ke toan') {
                throw ValidationException::withMessages([
                    'TrangThai' => 'Chỉ bảng lương đang chờ Kế toán mới được trả về.'
                ]);
            }

            $employee = $this->employeeOrFail((int) $payroll->MaNV);

            $this->assertActorForBranch(
                (int) $data['MaNVThucHien'],
                (int) $employee->MaCS
            );

            DB::table('BANG_LUONG')
                ->where('MaBL', $id)
                ->update([
                    'TrangThai' => 'Can dieu chinh'
                ]);

            $this->writeLog(
                'Tra bang luong',
                'BANG_LUONG',
                "Nhân viên thực hiện #{$data['MaNVThucHien']} trả bảng lương #{$id} về điều chỉnh. Lý do: {$data['LyDo']}"
            );
        });

        return response()->json($this->payrollPayload($id));
    }

    public function approvePayroll(Request $request, int $id)
    {
        $data = $request->validate([
            'MaNVThucHien' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
        ]);

        DB::transaction(function () use ($id, $data) {
            $payroll = DB::table('BANG_LUONG')
                ->where('MaBL', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($payroll, 404, 'Không tìm thấy bảng lương.');

            if ($payroll->TrangThai !== 'Cho ke toan') {
                throw ValidationException::withMessages([
                    'TrangThai' => 'Chỉ bảng lương đã trình Kế toán mới được xác nhận.'
                ]);
            }

            $employee = $this->employeeOrFail((int) $payroll->MaNV);

            $this->assertActorForBranch(
                (int) $data['MaNVThucHien'],
                (int) $employee->MaCS
            );

            $this->validatePayrollSourceData($payroll);

            DB::table('BANG_LUONG')
                ->where('MaBL', $id)
                ->update([
                    'TrangThai' => 'Da chot'
                ]);

            $this->writeLog(
                'Chot bang luong',
                'BANG_LUONG',
                "Nhân viên thực hiện #{$data['MaNVThucHien']} xác nhận và chốt bảng lương #{$id} của nhân viên #{$payroll->MaNV}."
            );
        });

        return response()->json($this->payrollPayload($id));
    }

    // =====================================================
    // HÀM HỖ TRỢ / KIỂM TRA DỮ LIỆU
    // =====================================================
        private function validateEmployee(Request $request): array
    {
        return $request->validate([
            'HoTen' => ['required', 'string', 'max:150'],
            'NgaySinh' => ['nullable', 'date', 'before:today'],
            'SDT' => ['nullable', 'string', 'max:20'],
            'Email' => ['nullable', 'email', 'max:150'],
            'NgayVaoLam' => ['nullable', 'date'],
            'TrangThai' => ['nullable', 'in:Dang lam,Tam nghi,Da nghi'],
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'MaVT' => ['nullable', 'integer', 'exists:VI_TRI,MaVT'],
        ]);
    }

    private function validateContract(Request $request): array
    {
        return $request->validate([
            'LoaiHD' => ['required', 'string', 'max:100'],
            'NgayBD' => ['required', 'date_format:Y-m-d'],
            'NgayKT' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:NgayBD'],
            'LuongCoBan' => ['required', 'numeric', 'min:0'],
            'TrangThai' => ['nullable', 'in:Hieu luc,Het han,Da cham dut'],
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV'],
        ]);
    }

    private function validateShift(Request $request): array
    {
        return $request->validate([
            'TenCa' => ['required', 'string', 'max:100'],
            'GioVao' => ['required', 'date_format:H:i'],
            'GioRa' => ['required', 'date_format:H:i'],
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'TrangThai' => ['nullable', 'in:Dang hoat dong,Ngung hoat dong'],
        ]);
    }

    private function normalizeTime(string $value): string
    {
        return strlen($value) === 5 ? $value . ':00' : $value;
    }

    private function assertBranchActive(int $branchId): void
    {
        $branch = DB::table('CO_SO')
            ->where('MaCS', $branchId)
            ->first();

        if (!$branch || $branch->TrangThai !== 'Dang hoat dong') {
            throw ValidationException::withMessages([
                'MaCS' => 'Cơ sở không tồn tại hoặc đang ngừng hoạt động.'
            ]);
        }
    }

    private function assertPositionExists($positionId): void
    {
        if (
            $positionId
            && !DB::table('VI_TRI')->where('MaVT', $positionId)->exists()
        ) {
            throw ValidationException::withMessages([
                'MaVT' => 'Vị trí không tồn tại.'
            ]);
        }
    }

    private function employeeOrFail(int $employeeId): object
    {
        $employee = DB::table('NHAN_VIEN')
            ->where('MaNV', $employeeId)
            ->first();

        abort_unless($employee, 404, 'Không tìm thấy nhân viên.');

        return $employee;
    }

    private function assertActorForBranch(int $actorId, int $branchId): void
    {
        $actor = $this->employeeOrFail($actorId);

        if (
            $actor->TrangThai !== 'Dang lam'
            || (int) $actor->MaCS !== $branchId
        ) {
            throw ValidationException::withMessages([
                'MaNVThucHien' => 'Nhân viên thực hiện phải đang làm tại cùng cơ sở.'
            ]);
        }
    }

    private function assertNoContractOverlap(
        int $employeeId,
        string $startDate,
        ?string $endDate,
        ?int $excludeId = null
    ): void {
        $query = DB::table('HOP_DONG')
            ->where('MaNV', $employeeId)
            ->where('TrangThai', 'Hieu luc');

        if ($excludeId) {
            $query->where('MaHDLD', '<>', $excludeId);
        }

        if ($endDate) {
            $query->whereDate('NgayBD', '<=', $endDate);
        }

        $query->where(function ($q) use ($startDate) {
            $q->whereNull('NgayKT')
                ->orWhereDate('NgayKT', '>=', $startDate);
        });

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'NgayBD' => 'Nhân viên đang có hợp đồng hiệu lực giao thời gian. Hãy kết thúc hoặc điều chỉnh hợp đồng cũ trước.'
            ]);
        }
    }

    private function assertPunchPair($in, $out): void
    {
        if ((bool) $in !== (bool) $out) {
            throw ValidationException::withMessages([
                'GioVao' => 'Giờ vào và giờ ra phải được ghi nhận cùng lúc; lịch phân ca chưa chấm công thì để cả hai trống.'
            ]);
        }

        if (
            $in
            && $out
            && Carbon::parse($out)->lt(Carbon::parse($in))
        ) {
            throw ValidationException::withMessages([
                'GioRa' => 'Giờ ra phải sau hoặc bằng giờ vào.'
            ]);
        }
    }

    private function validatePayrollSourceData(object $payroll): void
    {
        $employee = $this->employeeOrFail((int) $payroll->MaNV);

        $periodStart = Carbon::parse($payroll->KyLuong)
            ->startOfMonth()
            ->toDateString();

        $periodEnd = Carbon::parse($payroll->KyLuong)
            ->endOfMonth()
            ->toDateString();

        $contracts = DB::table('HOP_DONG')
            ->where('MaNV', $employee->MaNV)
            ->whereIn('TrangThai', ['Hieu luc', 'Het han'])
            ->whereDate('NgayBD', '<=', $periodEnd)
            ->where(function ($q) use ($periodStart) {
                $q->whereNull('NgayKT')
                    ->orWhereDate('NgayKT', '>=', $periodStart);
            })
            ->count();

        if ($contracts !== 1) {
            throw ValidationException::withMessages([
                'HOP_DONG' => 'Không thể chốt lương: nhân viên phải có đúng một hợp đồng phù hợp kỳ lương.'
            ]);
        }

        $attendanceRows = DB::table('CHAM_CONG as cc')
            ->join('CA_LAM as ca', 'ca.MaCa', '=', 'cc.MaCa')
            ->where('cc.MaNV', $employee->MaNV)
            ->whereBetween('cc.Ngay', [$periodStart, $periodEnd])
            ->get([
                'cc.MaCC',
                'cc.Ngay',
                'cc.GioVao',
                'cc.GioRa',
                'cc.LoaiNghi',
                'cc.MaCS',
                'cc.MaCa',
                'ca.MaCS as MaCSCa'
            ]);

        if ($attendanceRows->isEmpty()) {
            throw ValidationException::withMessages([
                'CHAM_CONG' => 'Không có dữ liệu chấm công trong kỳ. Hãy kiểm tra lịch phân ca trước khi trình lương.'
            ]);
        }

        foreach ($attendanceRows as $row) {
            if (
                (int) $row->MaCS !== (int) $employee->MaCS
                || (int) $row->MaCSCa !== (int) $employee->MaCS
            ) {
                throw ValidationException::withMessages([
                    'CHAM_CONG' => "Bản ghi chấm công #{$row->MaCC} không khớp cơ sở nhân viên/ca làm."
                ]);
            }

            if (!empty($row->LoaiNghi)) {
                if ($row->GioVao || $row->GioRa) {
                    throw ValidationException::withMessages([
                        'CHAM_CONG' => "Bản ghi nghỉ #{$row->MaCC} vẫn có giờ vào/ra; cần điều chỉnh."
                    ]);
                }

                continue;
            }

            if (!$row->GioVao || !$row->GioRa) {
                throw ValidationException::withMessages([
                    'CHAM_CONG' => "Bản ghi #{$row->MaCC} chưa đủ giờ vào/ra hoặc chưa được đánh dấu nghỉ."
                ]);
            }

            if (
                Carbon::parse($row->GioRa)
                    ->lt(Carbon::parse($row->GioVao))
            ) {
                throw ValidationException::withMessages([
                    'CHAM_CONG' => "Bản ghi #{$row->MaCC} có giờ ra trước giờ vào."
                ]);
            }
        }

        $expected = round(
            (float) $payroll->LuongCoBan
            + (float) $payroll->PhuCap
            + (float) $payroll->Thuong
            - (float) $payroll->KhauTru,
            2
        );

        if (
            $expected < 0
            || abs($expected - (float) $payroll->ThucLinh) > 0.01
        ) {
            throw ValidationException::withMessages([
                'ThucLinh' => 'Thực lĩnh không khớp công thức lương. Hãy tính lại bảng lương.'
            ]);
        }
    }

    private function employeePayload(int $id): object
    {
        return DB::table('NHAN_VIEN as nv')
            ->join('CO_SO as cs', 'cs.MaCS', '=', 'nv.MaCS')
            ->leftJoin('VI_TRI as vt', 'vt.MaVT', '=', 'nv.MaVT')
            ->where('nv.MaNV', $id)
            ->first([
                'nv.MaNV',
                'nv.HoTen',
                'nv.NgaySinh',
                'nv.SDT',
                'nv.Email',
                'nv.NgayVaoLam',
                'nv.TrangThai',
                'nv.MaCS',
                'nv.MaVT',
                'cs.TenCS',
                'vt.TenVT'
            ]);
    }

    private function contractPayload(int $id): object
    {
        return DB::table('HOP_DONG as hd')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'hd.MaNV')
            ->where('hd.MaHDLD', $id)
            ->first([
                'hd.MaHDLD',
                'hd.LoaiHD',
                'hd.NgayBD',
                'hd.NgayKT',
                'hd.LuongCoBan',
                'hd.TrangThai',
                'hd.MaNV',
                'nv.HoTen'
            ]);
    }

    private function attendancePayload(int $id): object
    {
        return DB::table('CHAM_CONG as cc')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'cc.MaNV')
            ->join('CA_LAM as ca', 'ca.MaCa', '=', 'cc.MaCa')
            ->where('cc.MaCC', $id)
            ->first([
                'cc.MaCC',
                'cc.Ngay',
                'cc.GioVao',
                'cc.GioRa',
                'cc.TangCa',
                'cc.LoaiNghi',
                'cc.MaNV',
                'cc.MaCa',
                'cc.MaCS',
                'nv.HoTen',
                'ca.TenCa'
            ]);
    }

    private function payrollPayload(int $id): object
    {
        return DB::table('BANG_LUONG as bl')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'bl.MaNV')
            ->where('bl.MaBL', $id)
            ->first([
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
                'nv.MaCS'
            ]);
    }

    private function writeLog(
        string $action,
        string $entity,
        string $details
    ): void {
        try {
            DB::table('NHAT_KY')->insert([
                'ThoiGian' => now(),
                'HanhDong' => mb_substr($action, 0, 100),
                'DoiTuong' => mb_substr($entity, 0, 100),
                'NoiDungThayDoi' => $details,
                'MaTK' => null,
            ]);
        } catch (\Throwable $exception) {
            // Nhật ký lỗi không làm hỏng nghiệp vụ chính.
        }
    }
}