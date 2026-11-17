<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function branches()
    {
        return response()->json(DB::table('CO_SO')
            ->where('TrangThai', 'Dang hoat dong')
            ->orderBy('TenCS')
            ->get(['MaCS', 'TenCS']));
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'TenDangNhap' => ['required', 'string', 'max:100'],
            'MatKhau' => ['required', 'string'],
            // Không còn bắt chọn cơ sở khi đăng nhập; vẫn nhận MaCS (nếu có) để tương thích bản cũ.
            'MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS'],
        ]);

        $key = 'qntd-login:'.hash('sha256', mb_strtolower($data['TenDangNhap']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau.'], 429);
        }

        $accepted = Auth::guard('web')->attempt([
            'TenDangNhap' => $data['TenDangNhap'],
            'password' => $data['MatKhau'],
        ], false);

        if (!$accepted) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([
                'TenDangNhap' => 'Tên đăng nhập hoặc mật khẩu không đúng, hoặc tài khoản đã bị khóa.',
            ]);
        }

        $user = Auth::guard('web')->user();
        $isAdmin = $user->TenVaiTro === 'Quan tri he thong';
        $requestedBranch = !empty($data['MaCS']) ? (int) $data['MaCS'] : null;

        if ($isAdmin) {
            // Admin vào hệ thống không cần chọn cơ sở: lấy cơ sở của hồ sơ nhân viên (nếu có),
            // nếu không thì cơ sở đang hoạt động đầu tiên. Sau đó đổi cơ sở ngay trong từng trang quản lý.
            $branchId = $requestedBranch
                ?: ((int) $user->MaCS ?: (int) DB::table('CO_SO')->where('TrangThai', 'Dang hoat dong')->orderBy('MaCS')->value('MaCS'));
        } else {
            // Nhân viên luôn làm việc tại cơ sở của chính mình.
            if (!$user->MaNV || !$user->MaCS || ($requestedBranch && $requestedBranch !== (int) $user->MaCS)) {
                Auth::guard('web')->logout();
                throw ValidationException::withMessages([
                    'TenDangNhap' => ($user->MaNV && $user->MaCS)
                        ? 'Tài khoản này không được phép làm việc ở cơ sở đã chọn.'
                        : 'Tài khoản này chưa được gán nhân viên/cơ sở làm việc. Vui lòng liên hệ quản trị viên.',
                ]);
            }
            $branchId = (int) $user->MaCS;
        }
        $data['MaCS'] = $branchId;

        $request->session()->regenerate();
        $request->session()->put('qntd_branch_id', $branchId);
        $request->session()->put('qntd_password_fingerprint', hash('sha256', $user->MatKhauHash));
        RateLimiter::clear($key);

        DB::table('NHAT_KY')->insert([
            'ThoiGian' => now(), 'HanhDong' => 'Dang nhap',
            'DoiTuong' => 'TAI_KHOAN',
            'NoiDungThayDoi' => 'Đăng nhập tại cơ sở #'.$data['MaCS'],
            'MaTK' => $user->getAuthIdentifier(),
        ]);
        return response()->json(['message' => 'Đăng nhập thành công.', 'data' => $this->profile($request)]);
    }

    public function me(Request $request)
    {
        if ($request->session()->get('qntd_password_fingerprint') !== hash('sha256', $request->user()->MatKhauHash)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            return response()->json(['message' => 'Mật khẩu đã thay đổi. Vui lòng đăng nhập lại.'], 401);
        }
        return response()->json($this->profile($request));
    }

    public function logout(Request $request)
    {
        $id = $request->user()?->getAuthIdentifier();
        if ($id) {
            DB::table('NHAT_KY')->insert([
                'ThoiGian' => now(), 'HanhDong' => 'Dang xuat',
                'DoiTuong' => 'TAI_KHOAN',
                'NoiDungThayDoi' => 'Đăng xuất hệ thống.', 'MaTK' => $id,
            ]);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => 'Đã đăng xuất.']);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'MatKhauCu' => ['required', 'string'],
            'MatKhauMoi' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);
        $user = $request->user();
        if (!Hash::check($data['MatKhauCu'], $user->MatKhauHash)) {
            throw ValidationException::withMessages(['MatKhauCu' => 'Mật khẩu cũ không đúng.']);
        }
        $hash = Hash::make($data['MatKhauMoi']);
        DB::transaction(function () use ($user, $hash) {
            DB::table('TAI_KHOAN')->where('MaTK', $user->MaTK)
                ->update(['MatKhauHash' => $hash]);
            DB::table('NHAT_KY')->insert([
                'ThoiGian' => now(), 'HanhDong' => 'Doi mat khau',
                'DoiTuong' => 'TAI_KHOAN',
                'NoiDungThayDoi' => 'Đổi mật khẩu tài khoản #'.$user->MaTK,
                'MaTK' => $user->MaTK,
            ]);
        });
        $request->session()->put('qntd_password_fingerprint', hash('sha256', $hash));
        // Middleware AuthenticateSession của Sanctum ghi lại hash mật khẩu của đối tượng $user
        // SAU khi response được tạo. Nếu vẫn là hash cũ thì request kế tiếp bị đăng xuất (401),
        // nên cập nhật hash mới ngay trên đối tượng người dùng đang đăng nhập.
        $user->MatKhauHash = $hash;
        return response()->json(['message' => 'Đã đổi mật khẩu.']);
    }

    private function profile(Request $request): array
    {
        $user = $request->user() ?? Auth::guard('web')->user();
        $permissions = $user->TenVaiTro === 'Quan tri he thong'
            ? ['*']
            : DB::table('PHAN_QUYEN')->where('MaVaiTro', $user->MaVaiTro)
                ->orderBy('MaUC')->pluck('MaUC')->all();

        return [
            'MaTK' => (int) $user->MaTK,
            'TenDangNhap' => $user->TenDangNhap,
            'MaVaiTro' => (int) $user->MaVaiTro,
            'TenVaiTro' => $user->TenVaiTro,
            'MaNV' => $user->MaNV,
            'HoTen' => $user->HoTen,
            'MaCS' => (int) $request->session()->get('qntd_branch_id'),
            'Quyen' => $permissions,
        ];
    }
}