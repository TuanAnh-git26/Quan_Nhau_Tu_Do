<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** UC2.5 - Tiếp nhận và xử lý khiếu nại theo cơ sở của phiên Sanctum. */
class ComplaintController extends Controller
{
    private const STATUSES = ['Moi tiep nhan', 'Dang xu ly', 'Da xu ly', 'Da dong'];

    private function branchId(Request $request): int
    {
        $branchId = (int) $request->session()->get('qntd_branch_id', 0);
        abort_unless($branchId > 0, 403, 'Chưa chọn cơ sở đăng nhập.');
        return $branchId;
    }

    private function scopedQuery(int $branchId)
    {
        return DB::table('PHIEU_KHIEU_NAI as kn')
            ->join('BAN as b', 'b.MaBan', '=', 'kn.MaBan')
            ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
            ->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 'kn.MaNV')
            ->where('kv.MaCS', $branchId);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'TrangThai' => ['nullable', Rule::in(self::STATUSES)],
            'q' => ['nullable', 'string', 'max:150'],
            'MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $branchId = $this->branchId($request);
        if (isset($data['MaCS']) && (int) $data['MaCS'] !== $branchId) {
            abort(403, 'Không thể xem khiếu nại của cơ sở khác.');
        }

        $query = $this->scopedQuery($branchId)
            ->select(
                'kn.MaKN', 'kn.MoTa', 'kn.TrangThai', 'kn.HuongXuLy',
                'kn.ThoiGianTiepNhan', 'kn.MaBan', 'b.SoBan',
                'kn.MaNV', 'nv.HoTen as NguoiTiepNhan', 'kv.TenKV', 'kv.MaCS'
            )
            ->orderByDesc('kn.ThoiGianTiepNhan')
            ->orderByDesc('kn.MaKN');

        if (!empty($data['TrangThai'])) {
            $query->where('kn.TrangThai', $data['TrangThai']);
        }
        if (!empty($data['q'])) {
            $word = trim($data['q']);
            $query->where(function ($q) use ($word) {
                $q->where('kn.MoTa', 'like', "%{$word}%")
                    ->orWhere('b.SoBan', 'like', "%{$word}%");
            });
        }

        return response()->json([
            'message' => 'Đã tải danh sách khiếu nại.',
            'data' => $query->paginate((int) ($data['per_page'] ?? 15)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'MaBan' => ['required', 'integer', 'exists:BAN,MaBan'],
            'MoTa' => ['required', 'string', 'min:5', 'max:5000'],
        ]);
        $branchId = $this->branchId($request);
        $employeeId = (int) ($request->user()?->MaNV ?? 0);
        if (!$employeeId) {
            throw ValidationException::withMessages([
                'MaNV' => 'Tài khoản phải liên kết với một nhân viên để tiếp nhận khiếu nại.',
            ]);
        }

        $id = DB::transaction(function () use ($data, $branchId, $employeeId, $request) {
            $table = DB::table('BAN as b')
                ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
                ->where('b.MaBan', $data['MaBan'])
                ->lockForUpdate()
                ->first(['b.MaBan', 'kv.MaCS']);

            if (!$table || (int) $table->MaCS !== $branchId) {
                throw ValidationException::withMessages([
                    'MaBan' => 'Bàn không thuộc cơ sở đang đăng nhập.',
                ]);
            }

            $employee = DB::table('NHAN_VIEN')->where('MaNV', $employeeId)->first();
            if (!$employee || $employee->TrangThai !== 'Dang lam' ||
                (int) $employee->MaCS !== $branchId) {
                throw ValidationException::withMessages([
                    'MaNV' => 'Nhân viên tiếp nhận không hoạt động tại cơ sở này.',
                ]);
            }

            $id = DB::table('PHIEU_KHIEU_NAI')->insertGetId([
                'MaBan' => $data['MaBan'],
                'MaNV' => $employeeId,
                'MoTa' => trim($data['MoTa']),
                'TrangThai' => 'Moi tiep nhan',
                'ThoiGianTiepNhan' => now(),
                'HuongXuLy' => null,
            ], 'MaKN');
            $this->writeLog($request, 'Tao khieu nai',
                "Phiếu #{$id}, bàn #{$data['MaBan']}, nhân viên #{$employeeId}; trạng thái Moi tiep nhan.");
            return $id;
        });

        return response()->json([
            'message' => 'Đã tiếp nhận khiếu nại.',
            'data' => ['MaKN' => $id],
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'TrangThai' => ['required', Rule::in(self::STATUSES)],
            'HuongXuLy' => ['nullable', 'string', 'max:5000'],
        ]);
        $branchId = $this->branchId($request);
        $role = (string) ($request->user()?->TenVaiTro ?? '');
        if (!in_array($role, ['Giam sat', 'Quan tri he thong'], true)) {
            abort(403, 'Chỉ Giám sát hoặc Quản trị mới được thay đổi trạng thái khiếu nại.');
        }

        DB::transaction(function () use ($request, $id, $data, $branchId) {
            $complaint = $this->scopedQuery($branchId)
                ->where('kn.MaKN', $id)
                ->lockForUpdate()
                ->first(['kn.MaKN', 'kn.MaBan', 'kn.TrangThai', 'kn.HuongXuLy']);
            abort_unless($complaint, 404, 'Không tìm thấy khiếu nại tại cơ sở này.');

            $next = [
                'Moi tiep nhan' => 'Dang xu ly',
                'Dang xu ly' => 'Da xu ly',
                'Da xu ly' => 'Da dong',
            ];
            if (($next[$complaint->TrangThai] ?? null) !== $data['TrangThai']) {
                throw ValidationException::withMessages([
                    'TrangThai' => 'Chỉ được chuyển trạng thái theo quy trình: Mới tiếp nhận → Đang xử lý → Đã xử lý → Đã đóng.',
                ]);
            }

            $resolution = trim((string) ($data['HuongXuLy'] ?? $complaint->HuongXuLy ?? ''));
            if (in_array($data['TrangThai'], ['Da xu ly', 'Da dong'], true) && $resolution === '') {
                throw ValidationException::withMessages([
                    'HuongXuLy' => 'Cần ghi rõ hướng xử lý trước khi đánh dấu đã xử lý hoặc đã đóng.',
                ]);
            }

            DB::table('PHIEU_KHIEU_NAI')->where('MaKN', $id)->update([
                'TrangThai' => $data['TrangThai'],
                'HuongXuLy' => $resolution ?: null,
            ]);
            $this->writeLog($request, 'Xu ly khieu nai',
                "Phiếu #{$id}, bàn #{$complaint->MaBan}; {$complaint->TrangThai} -> {$data['TrangThai']}; hướng xử lý: {$resolution}");
        });

        return response()->json([
            'message' => 'Đã cập nhật trạng thái khiếu nại.',
            'data' => DB::table('PHIEU_KHIEU_NAI')->where('MaKN', $id)->first(),
        ]);
    }

    private function writeLog(Request $request, string $action, string $details): void
    {
        DB::table('NHAT_KY')->insert([
            'ThoiGian' => now(),
            'HanhDong' => $action,
            'DoiTuong' => 'PHIEU_KHIEU_NAI',
            'NoiDungThayDoi' => $details,
            'MaTK' => $request->user()?->getAuthIdentifier(),
        ]);
    }
}
