<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SystemController extends Controller
{
    private function account(Request $r): object
    {
        $acc = $r->attributes->get('account');
        abort_unless($acc, 401, 'Bạn cần đăng nhập.');
        return $acc;
    }
    private function log(string $action, string $entity, string $detail, ?int $accountId = null): void
    {
        DB::table('NHAT_KY')->insert([
            'HanhDong' => $action, 'DoiTuong' => $entity, 'NoiDungThayDoi' => $detail,
            'MaTK' => $accountId ?? request()->attributes->get('account')?->MaTK,
        ]);
    }
    private function issueToken(object $account): string
    {
        $token = bin2hex(random_bytes(40));
        Cache::store('file')->put('qntd_token_'.hash('sha256', $token), [
            'id' => $account->MaTK,
            'hash' => hash('sha256', $account->MatKhauHash),
        ], now()->addHours(8));
        return $token;
    }
    private function publicAccount(object $a): array
    {
        return ['MaTK' => $a->MaTK, 'TenDangNhap' => $a->TenDangNhap,
            'TenVaiTro' => $a->TenVaiTro, 'MaVaiTro' => $a->MaVaiTro,
            'MaNV' => $a->MaNV, 'MaCS' => $a->MaCS, 'HoTen' => $a->HoTen];
    }

    public function login(Request $request)
    {
        $d = $request->validate(['TenDangNhap' => 'required|string|max:100', 'MatKhau' => 'required|string|max:255']);
        $key = 'qntd_login:'.hash('sha256', mb_strtolower($d['TenDangNhap']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Đăng nhập quá nhiều lần. Thử lại sau '.RateLimiter::availableIn($key).' giây.'], 429);
        }
        $account = DB::table('TAI_KHOAN as tk')
            ->join('VAI_TRO as vt', 'vt.MaVaiTro', '=', 'tk.MaVaiTro')
            ->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 'tk.MaNV')
            ->where('tk.TenDangNhap', $d['TenDangNhap'])
            ->first(['tk.*', 'vt.TenVaiTro', 'nv.MaCS', 'nv.HoTen', 'nv.TrangThai as TrangThaiNV']);
        if (!$account || $account->TrangThai !== 'Hoat dong'
            || ($account->MaNV && $account->TrangThaiNV !== 'Dang lam')
            || !Hash::check($d['MatKhau'], $account->MatKhauHash)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['TenDangNhap' => 'Tên đăng nhập hoặc mật khẩu không đúng.']);
        }
        RateLimiter::clear($key);
        $token = $this->issueToken($account);
        $this->log('Dang nhap', 'TAI_KHOAN', 'Đăng nhập ứng dụng.', (int)$account->MaTK);
        return response()->json(['token' => $token, 'user' => $this->publicAccount($account)]);
    }

    public function me(Request $r)
    {
        $a = $this->account($r);
        $u = $this->publicAccount($a);
        $u['Quyen'] = $a->TenVaiTro === 'Quan tri he thong'
            ? ['*'] : DB::table('PHAN_QUYEN')->where('MaVaiTro', $a->MaVaiTro)->pluck('MaUC');
        return response()->json($u);
    }

    public function logout(Request $r)
    {
        Cache::store('file')->forget('qntd_token_'.hash('sha256', (string)$r->bearerToken()));
        return response()->json(['message' => 'Đã đăng xuất.']);
    }

    public function changePassword(Request $r)
    {
        $d = $r->validate(['MatKhauCu' => 'required|string', 'MatKhauMoi' => 'required|string|min:10|max:255|different:MatKhauCu|confirmed']);
        $a = $this->account($r);
        if (!Hash::check($d['MatKhauCu'], $a->MatKhauHash)) throw ValidationException::withMessages(['MatKhauCu' => 'Mật khẩu hiện tại không đúng.']);
        $hash = Hash::make($d['MatKhauMoi']);
        DB::transaction(function () use ($a, $hash) {
            DB::table('TAI_KHOAN')->where('MaTK', $a->MaTK)->update(['MatKhauHash' => $hash]);
            $this->log('Doi mat khau', 'TAI_KHOAN', "Đổi mật khẩu tài khoản #{$a->MaTK}");
        });
        // Mật khẩu đã thay đổi làm mọi token cũ tự mất hiệu lực qua kiểm tra hash.
        $a->MatKhauHash = $hash;
        return response()->json(['message' => 'Đã đổi mật khẩu.', 'token' => $this->issueToken($a)]);
    }

    public function roles()
    {
        return response()->json(DB::table('VAI_TRO')->orderBy('MaVaiTro')->get());
    }
    public function createRole(Request $r)
    {
        $d = $r->validate(['TenVaiTro' => 'required|string|max:100|unique:VAI_TRO,TenVaiTro', 'MoTaQuyen' => 'nullable|string|max:10000']);
        $id = DB::transaction(function () use ($d) {
            $id = DB::table('VAI_TRO')->insertGetId($d, 'MaVaiTro');
            $this->log('Tao vai tro', 'VAI_TRO', "Vai trò #{$id}"); return $id;
        });
        return response()->json(['message' => 'Đã tạo vai trò.', 'MaVaiTro' => $id], 201);
    }
    public function permissions(int $roleId)
    {
        abort_unless(DB::table('VAI_TRO')->where('MaVaiTro', $roleId)->exists(), 404);
        return response()->json(DB::table('PHAN_QUYEN')->where('MaVaiTro', $roleId)->orderBy('MaUC')->pluck('MaUC'));
    }
    public function savePermissions(Request $r, int $roleId)
    {
        $d = $r->validate(['Quyen' => 'required|array', 'Quyen.*' => ['required', 'string', 'distinct', 'regex:/^UC[1-8]\.\d+$/']]);
        $role = DB::table('VAI_TRO')->where('MaVaiTro', $roleId)->first();
        abort_unless($role, 404);
        if ($role->TenVaiTro === 'Quan tri he thong') throw ValidationException::withMessages(['Quyen' => 'Không sửa quyền vai trò quản trị hệ thống bằng giao diện.']);
        DB::transaction(function () use ($roleId, $d) {
            DB::table('PHAN_QUYEN')->where('MaVaiTro', $roleId)->delete();
            foreach ($d['Quyen'] as $uc) DB::table('PHAN_QUYEN')->insert(['MaVaiTro' => $roleId, 'MaUC' => $uc]);
            $this->log('Cap nhat phan quyen', 'PHAN_QUYEN', "Vai trò #{$roleId}: ".implode(', ', $d['Quyen']));
        });
        return response()->json(['message' => 'Đã cập nhật quyền.']);
    }

    public function accounts(Request $r)
    {
        $d = $r->validate(['q' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:5|max:100']);
        $q = DB::table('TAI_KHOAN as tk')->join('VAI_TRO as vt', 'vt.MaVaiTro', '=', 'tk.MaVaiTro')
            ->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 'tk.MaNV')
            ->select('tk.MaTK', 'tk.TenDangNhap', 'tk.TrangThai', 'tk.MaVaiTro', 'tk.MaNV', 'vt.TenVaiTro', 'nv.HoTen')->orderBy('tk.MaTK');
        if (!empty($d['q'])) $q->where('tk.TenDangNhap', 'like', '%'.$d['q'].'%');
        return response()->json($q->paginate($d['per_page'] ?? 10));
    }
    public function createAccount(Request $r)
    {
        $d = $r->validate([
            'TenDangNhap' => 'required|string|min:3|max:100|unique:TAI_KHOAN,TenDangNhap',
            'MatKhau' => 'required|string|min:10|max:255',
            'MaVaiTro' => 'required|integer|exists:VAI_TRO,MaVaiTro',
            'MaNV' => 'nullable|integer|exists:NHAN_VIEN,MaNV|unique:TAI_KHOAN,MaNV',
        ]);
        $id = DB::transaction(function () use ($d) {
            $id = DB::table('TAI_KHOAN')->insertGetId([
                'TenDangNhap' => $d['TenDangNhap'], 'MatKhauHash' => Hash::make($d['MatKhau']),
                'MaVaiTro' => $d['MaVaiTro'], 'MaNV' => $d['MaNV'] ?? null, 'TrangThai' => 'Hoat dong',
            ], 'MaTK');
            $this->log('Tao tai khoan', 'TAI_KHOAN', "Tạo tài khoản #{$id}"); return $id;
        });
        return response()->json(['message' => 'Đã tạo tài khoản.', 'MaTK' => $id], 201);
    }
    public function updateAccount(Request $r, int $id)
    {
        $d = $r->validate([
            'TrangThai' => ['required', Rule::in(['Hoat dong', 'Khoa'])],
            'MaVaiTro' => 'required|integer|exists:VAI_TRO,MaVaiTro',
        ]);
        if ($this->account($r)->MaTK == $id && $d['TrangThai'] !== 'Hoat dong') throw ValidationException::withMessages(['TrangThai' => 'Không tự khóa tài khoản đang đăng nhập.']);
        abort_unless(DB::table('TAI_KHOAN')->where('MaTK', $id)->exists(), 404, 'Không thấy tài khoản.');
        DB::table('TAI_KHOAN')->where('MaTK', $id)->update($d);
        $this->log('Sua tai khoan', 'TAI_KHOAN', "Tài khoản #{$id}: {$d['TrangThai']}");
        return response()->json(['message' => 'Đã cập nhật tài khoản.']);
    }
    public function resetPassword(Request $r, int $id)
    {
        $d = $r->validate(['MatKhauMoi' => 'required|string|min:10|max:255']);
        abort_unless(DB::table('TAI_KHOAN')->where('MaTK', $id)->exists(), 404);
        DB::transaction(function () use ($d, $id) {
            DB::table('TAI_KHOAN')->where('MaTK', $id)->update(['MatKhauHash' => Hash::make($d['MatKhauMoi'])]);
            $this->log('Dat lai mat khau', 'TAI_KHOAN', "Tài khoản #{$id}");
        });
        return response()->json(['message' => 'Đã đặt lại mật khẩu, phiên cũ sẽ hết hiệu lực.']);
    }
    public function audits(Request $r)
    {
        $d = $r->validate(['q' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:5|max:100']);
        $q = DB::table('NHAT_KY as nk')->leftJoin('TAI_KHOAN as tk', 'tk.MaTK', '=', 'nk.MaTK')
            ->select('nk.*', 'tk.TenDangNhap')->orderByDesc('nk.MaNK');
        if (!empty($d['q'])) $q->where(function ($x) use ($d) {
            $x->where('nk.HanhDong', 'like', '%'.$d['q'].'%')->orWhere('nk.DoiTuong', 'like', '%'.$d['q'].'%');
        });
        return response()->json($q->paginate($d['per_page'] ?? 20));
    }
    public function tickets(Request $r)
    {
        $a = $this->account($r);
        $d = $r->validate(['MaCS' => 'nullable|integer|exists:CO_SO,MaCS', 'per_page' => 'nullable|integer|min:5|max:100']);
        $q = DB::table('TICKET_HO_TRO as t')->leftJoin('TAI_KHOAN as tk', 'tk.MaTK', '=', 't.MaTKGui')
            ->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 't.MaNVXuLy')
            ->select('t.*', 'tk.TenDangNhap as NguoiGui', 'nv.HoTen as NguoiXuLy')->orderByDesc('t.MaTicket');
        if ($a->TenVaiTro !== 'Quan tri he thong') {
            if ($a->MaCS) $q->where('t.MaCS', $a->MaCS);
            else $q->where('t.MaTKGui', $a->MaTK);
        }
        if (!empty($d['MaCS'])) {
            if ($a->TenVaiTro !== 'Quan tri he thong' && (int)$a->MaCS !== (int)$d['MaCS']) abort(403, 'Không được xem ticket của cơ sở khác.');
            $q->where('t.MaCS', $d['MaCS']);
        }
        return response()->json($q->paginate($d['per_page'] ?? 10));
    }
    public function createTicket(Request $r)
    {
        $a = $this->account($r);
        $d = $r->validate([
            'MaCS' => 'required|integer|exists:CO_SO,MaCS',
            'MoTaSuCo' => 'required|string|min:5|max:10000',
            'MucUuTien' => ['required', Rule::in(['Thap', 'Trung binh', 'Cao', 'Khan cap'])],
        ]);
        if ($a->TenVaiTro !== 'Quan tri he thong' && (!$a->MaCS || (int)$a->MaCS !== (int)$d['MaCS'])) abort(403, 'Sai cơ sở.');
        $id = DB::transaction(function () use ($d, $a) {
            $id = DB::table('TICKET_HO_TRO')->insertGetId($d + ['TrangThai' => 'Moi', 'MaTKGui' => $a->MaTK], 'MaTicket');
            $this->log('Gui ticket', 'TICKET_HO_TRO', "Tạo yêu cầu hỗ trợ #{$id}"); return $id;
        });
        return response()->json(['message' => 'Đã gửi yêu cầu hỗ trợ.', 'MaTicket' => $id], 201);
    }
    public function updateTicket(Request $r, int $id)
    {
        $d = $r->validate([
            'TrangThai' => ['required', Rule::in(['Moi', 'Dang xu ly', 'Cho phan hoi', 'Da dong'])],
            'MaNVXuLy' => 'nullable|integer|exists:NHAN_VIEN,MaNV',
        ]);
        $ticket = DB::table('TICKET_HO_TRO')->where('MaTicket', $id)->first();
        abort_unless($ticket, 404);
        if (!empty($d['MaNVXuLy']) && !DB::table('NHAN_VIEN')->where('MaNV', $d['MaNVXuLy'])->where('MaCS', $ticket->MaCS)->exists()) {
            throw ValidationException::withMessages(['MaNVXuLy' => 'Nhân viên xử lý phải thuộc cùng cơ sở.']);
        }
        DB::transaction(function () use ($id, $d) {
            DB::table('TICKET_HO_TRO')->where('MaTicket', $id)->update($d);
            $this->log('Xu ly ticket', 'TICKET_HO_TRO', "Ticket #{$id}: {$d['TrangThai']}");
        });
        return response()->json(['message' => 'Đã cập nhật yêu cầu hỗ trợ.']);
    }
}
