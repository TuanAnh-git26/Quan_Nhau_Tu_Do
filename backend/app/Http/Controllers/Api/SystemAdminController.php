<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SystemAdminController extends Controller
{
    private function audit(Request $request, string $action, string $object, string $description): void
    {
        DB::table('NHAT_KY')->insert([
            'ThoiGian' => now(), 'HanhDong' => $action,
            'DoiTuong' => $object, 'NoiDungThayDoi' => $description,
            'MaTK' => $request->user()->getAuthIdentifier(),
        ]);
    }

    public function roles()
    {
        return response()->json(DB::table('VAI_TRO')->orderBy('TenVaiTro')->get());
    }

    public function createRole(Request $request)
    {
        $data = $request->validate([
            'TenVaiTro' => ['required', 'string', 'max:100', 'unique:VAI_TRO,TenVaiTro'],
            'MoTaQuyen' => ['nullable', 'string'],
        ]);
        $id = DB::transaction(function () use ($request, $data) {
            $id = DB::table('VAI_TRO')->insertGetId($data, 'MaVaiTro');
            $this->audit($request, 'Tao vai tro', 'VAI_TRO', 'Vai trò #'.$id);
            return $id;
        });
        return response()->json(['message' => 'Đã tạo vai trò.', 'data' => ['MaVaiTro' => $id]], 201);
    }

    public function permissions(int $id)
    {
        abort_unless(DB::table('VAI_TRO')->where('MaVaiTro', $id)->exists(), 404);
        return response()->json(DB::table('PHAN_QUYEN')->where('MaVaiTro', $id)
            ->orderBy('MaUC')->pluck('MaUC'));
    }

    public function savePermissions(Request $request, int $id)
    {
        $data = $request->validate([
            'Quyen' => ['required', 'array'],
            'Quyen.*' => ['required', 'string', 'distinct', 'regex:/^UC[1-8]\.\d+$/'],
        ]);
        DB::transaction(function () use ($request, $id, $data) {
            $role = DB::table('VAI_TRO')->where('MaVaiTro', $id)->lockForUpdate()->first();
            abort_unless($role, 404);
            if ($role->TenVaiTro === 'Quan tri he thong') {
                throw ValidationException::withMessages(['Quyen' => 'Không sửa quyền quản trị hệ thống.']);
            }
            DB::table('PHAN_QUYEN')->where('MaVaiTro', $id)->delete();
            foreach ($data['Quyen'] as $uc) {
                DB::table('PHAN_QUYEN')->insert(['MaVaiTro' => $id, 'MaUC' => $uc]);
            }
            $this->audit($request, 'Cap nhat quyen', 'PHAN_QUYEN', 'Vai trò #'.$id.': '.implode(', ', $data['Quyen']));
        });
        Cache::forget('qntd:perm:'.$id);
        return response()->json(['message' => 'Đã lưu phân quyền.']);
    }

    public function accounts(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $query = DB::table('TAI_KHOAN as tk')
            ->join('VAI_TRO as vt', 'vt.MaVaiTro', '=', 'tk.MaVaiTro')
            ->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 'tk.MaNV')
            ->select('tk.MaTK', 'tk.TenDangNhap', 'tk.TrangThai',
                'tk.MaVaiTro', 'tk.MaNV', 'vt.TenVaiTro', 'nv.HoTen', 'nv.MaCS')
            ->orderBy('tk.MaTK');
        if (!empty($data['q'])) {
            $query->where('tk.TenDangNhap', 'like', '%'.$data['q'].'%');
        }
        return response()->json($query->paginate($data['per_page'] ?? 15));
    }

    public function createAccount(Request $request)
    {
        $data = $request->validate([
            'TenDangNhap' => ['required', 'string', 'min:3', 'max:100', 'unique:TAI_KHOAN,TenDangNhap'],
            'MatKhau' => ['required', 'string', 'min:12', 'max:255'],
            'MaVaiTro' => ['required', 'integer', 'exists:VAI_TRO,MaVaiTro'],
            'MaNV' => ['required', 'integer', 'exists:NHAN_VIEN,MaNV', 'unique:TAI_KHOAN,MaNV'],
        ]);
        $chosenRole = DB::table('VAI_TRO')->where('MaVaiTro', $data['MaVaiTro'])->value('TenVaiTro');
        if ($chosenRole === 'Quan tri he thong' && $request->user()->TenVaiTro !== 'Quan tri he thong') {
            abort(403, 'Chỉ quản trị hệ thống được cấp vai trò quản trị.');
        }
        $id = DB::transaction(function () use ($request, $data) {
            $employee = DB::table('NHAN_VIEN')->where('MaNV', $data['MaNV'])
                ->lockForUpdate()->first();
            if (!$employee || $employee->TrangThai !== 'Dang lam') {
                throw ValidationException::withMessages(['MaNV' => 'Nhân viên không hoạt động.']);
            }
            $id = DB::table('TAI_KHOAN')->insertGetId([
                'TenDangNhap' => trim($data['TenDangNhap']),
                'MatKhauHash' => Hash::make($data['MatKhau']),
                'MaVaiTro' => $data['MaVaiTro'],
                'MaNV' => $data['MaNV'],
                'TrangThai' => 'Hoat dong',
            ], 'MaTK');
            $this->audit($request, 'Tao tai khoan', 'TAI_KHOAN', 'Tài khoản #'.$id);
            return $id;
        });
        return response()->json(['message' => 'Đã thêm tài khoản.', 'data' => ['MaTK' => $id]], 201);
    }

    public function updateAccount(Request $request, int $id)
    {
        $data = $request->validate([
            'TrangThai' => ['required', Rule::in(['Hoat dong', 'Khoa'])],
            'MaVaiTro' => ['required', 'integer', 'exists:VAI_TRO,MaVaiTro'],
        ]);
        DB::transaction(function () use ($request, $id, $data) {
            $account = DB::table('TAI_KHOAN')->where('MaTK', $id)
                ->lockForUpdate()->first();
            abort_unless($account, 404, 'Không có tài khoản này.');
            if ((int) $id === (int) $request->user()->MaTK && (
                $data['TrangThai'] === 'Khoa' || (int) $data['MaVaiTro'] !== (int) $account->MaVaiTro
            )) {
                throw ValidationException::withMessages(['MaTK' => 'Không được tự khóa hoặc tự đổi vai trò của chính mình.']);
            }
            $oldRole = DB::table('VAI_TRO')->where('MaVaiTro', $account->MaVaiTro)->value('TenVaiTro');
            $newRole = DB::table('VAI_TRO')->where('MaVaiTro', $data['MaVaiTro'])->value('TenVaiTro');
            if (($newRole === 'Quan tri he thong' || $oldRole === 'Quan tri he thong') &&
                $request->user()->TenVaiTro !== 'Quan tri he thong') {
                abort(403, 'Chỉ quản trị hệ thống được sửa tài khoản quản trị.');
            }
            if ($oldRole === 'Quan tri he thong' && ($newRole !== $oldRole || $data['TrangThai'] === 'Khoa')) {
                $otherAdmins = DB::table('TAI_KHOAN as tk')
                    ->join('VAI_TRO as vt', 'vt.MaVaiTro', '=', 'tk.MaVaiTro')
                    ->where('vt.TenVaiTro', 'Quan tri he thong')
                    ->where('tk.TrangThai', 'Hoat dong')
                    ->where('tk.MaTK', '<>', $id)->exists();
                if (!$otherAdmins) {
                    throw ValidationException::withMessages(['MaVaiTro' => 'Phải duy trì ít nhất một tài khoản quản trị.']);
                }
            }
            DB::table('TAI_KHOAN')->where('MaTK', $id)->update($data);
            $this->audit($request, 'Sua tai khoan', 'TAI_KHOAN', 'Cập nhật tài khoản #'.$id);
        });
        return response()->json(['message' => 'Đã cập nhật tài khoản.']);
    }

    public function resetPassword(Request $request, int $id)
    {
        $data = $request->validate(['MatKhauMoi' => ['required', 'string', 'min:12', 'max:255']]);
        DB::transaction(function () use ($request, $id, $data) {
            $account = DB::table('TAI_KHOAN as tk')
                ->join('VAI_TRO as vt', 'vt.MaVaiTro', '=', 'tk.MaVaiTro')
                ->where('tk.MaTK', $id)->first(['vt.TenVaiTro']);
            abort_unless($account, 404);
            if ($account->TenVaiTro === 'Quan tri he thong' &&
                $request->user()->TenVaiTro !== 'Quan tri he thong') {
                abort(403, 'Không được đặt lại mật khẩu quản trị.');
            }
            DB::table('TAI_KHOAN')->where('MaTK', $id)->update([
                'MatKhauHash' => Hash::make($data['MatKhauMoi']),
            ]);
            $this->audit($request, 'Dat lai mat khau', 'TAI_KHOAN', 'Đặt lại mật khẩu tài khoản #'.$id);
        });
        return response()->json(['message' => 'Đã đặt lại mật khẩu. Phiên cũ sẽ hết hiệu lực.']);
    }

    public function audits(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $query = DB::table('NHAT_KY as nk')
            ->leftJoin('TAI_KHOAN as tk', 'tk.MaTK', '=', 'nk.MaTK')
            ->select('nk.MaNK', 'nk.ThoiGian', 'nk.HanhDong', 'nk.DoiTuong',
                'nk.NoiDungThayDoi', 'nk.MaTK', 'tk.TenDangNhap')
            ->orderByDesc('nk.MaNK');
        if (!empty($data['q'])) {
            $query->where('nk.HanhDong', 'like', '%'.$data['q'].'%');
        }
        return response()->json($query->paginate($data['per_page'] ?? 20));
    }

    public function tickets(Request $request)
    {
        $data = $request->validate(['MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS']]);
        $query = DB::table('TICKET_HO_TRO as t')
            ->leftJoin('TAI_KHOAN as tk', 'tk.MaTK', '=', 't.MaTKGui')
            ->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 't.MaNVXuLy')
            ->select('t.*', 'tk.TenDangNhap as NguoiGui', 'nv.HoTen as NguoiXuLy')
            ->orderByDesc('t.MaTicket');

        if (!empty($data['MaCS'])) $query->where('t.MaCS', $data['MaCS']);
        if ($request->user()->TenVaiTro !== 'Quan tri he thong') {
            $query->where('t.MaCS', (int) $request->session()->get('qntd_branch_id'));
            // Nhân viên chỉ xem ticket do chính tài khoản của mình gửi; IT xem toàn bộ ticket trong phạm vi.
            if ($request->user()->TenVaiTro !== 'IT') {
                $query->where('t.MaTKGui', $request->user()->getAuthIdentifier());
            }
        }
        return response()->json($query->paginate(15));
    }

    public function createTicket(Request $request)
    {
        $data = $request->validate([
            'MoTaSuCo' => ['required', 'string', 'min:5'],
            'MucUuTien' => ['required', Rule::in(['Thap', 'Trung binh', 'Cao', 'Khan cap'])],
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
        ]);
        $id = DB::transaction(function () use ($request, $data) {
            $id = DB::table('TICKET_HO_TRO')->insertGetId([
                'MoTaSuCo' => $data['MoTaSuCo'], 'MucUuTien' => $data['MucUuTien'],
                'MaCS' => $data['MaCS'], 'TrangThai' => 'Moi',
                'MaTKGui' => $request->user()->MaTK, 'ThoiGianGui' => now(),
            ], 'MaTicket');
            $this->audit($request, 'Tao yeu cau ho tro', 'TICKET_HO_TRO', 'Ticket #'.$id);
            return $id;
        });
        return response()->json(['message' => 'Đã gửi yêu cầu hỗ trợ.', 'data' => ['MaTicket' => $id]], 201);
    }

    public function updateTicket(Request $request, int $id)
    {
        abort_unless(in_array($request->user()->TenVaiTro, ['IT', 'Quan tri he thong'], true), 403, 'Chỉ bộ phận IT được cập nhật và xử lý ticket.');
        $data = $request->validate([
            'TrangThai' => ['required', Rule::in(['Moi', 'Dang xu ly', 'Cho phan hoi', 'Da dong'])],
            'MaNVXuLy' => ['nullable', 'integer', 'exists:NHAN_VIEN,MaNV'],
        ]);
        DB::transaction(function () use ($request, $id, $data) {
            $ticket = DB::table('TICKET_HO_TRO')->where('MaTicket', $id)
                ->lockForUpdate()->first();
            abort_unless($ticket, 404);
            if (!empty($data['MaNVXuLy'])) {
                $employee = DB::table('NHAN_VIEN')->where('MaNV', $data['MaNVXuLy'])->first();
                if (!$employee || (int) $employee->MaCS !== (int) $ticket->MaCS) {
                    throw ValidationException::withMessages(['MaNVXuLy' => 'Người xử lý phải thuộc cùng cơ sở.']);
                }
            }
            DB::table('TICKET_HO_TRO')->where('MaTicket', $id)->update($data);
            $this->audit($request, 'Cap nhat ticket', 'TICKET_HO_TRO', 'Ticket #'.$id);
        });
        return response()->json(['message' => 'Đã cập nhật yêu cầu hỗ trợ.']);
    }
}