<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('qntd:make-admin {username}', function (string $username) {
    $username = trim($username);
    if (mb_strlen($username) < 3 || mb_strlen($username) > 100) {
        $this->error('Tên đăng nhập phải có từ 3 đến 100 ký tự.');
        return;
    }
    $password = $this->secret('Mật khẩu quản trị (tối thiểu 12 ký tự)');
    $confirm = $this->secret('Nhập lại mật khẩu');
    if (!$password || strlen($password) < 12 || $password !== $confirm) {
        $this->error('Mật khẩu không hợp lệ hoặc không trùng khớp.');
        return;
    }

    try {
        DB::transaction(function () use ($username, $password) {
            $role = DB::table('VAI_TRO')->where('TenVaiTro', 'Quan tri he thong')
                ->lockForUpdate()->first();
            $roleId = $role ? $role->MaVaiTro : DB::table('VAI_TRO')->insertGetId([
                'TenVaiTro' => 'Quan tri he thong',
                'MoTaQuyen' => 'Quyền quản trị hệ thống',
            ], 'MaVaiTro');
            if (DB::table('TAI_KHOAN')->where('MaVaiTro', $roleId)->exists()) {
                throw new \RuntimeException('Đã có tài khoản quản trị. Không tạo thêm bằng lệnh khởi tạo.');
            }
            if (DB::table('TAI_KHOAN')->where('TenDangNhap', $username)->exists()) {
                throw new \RuntimeException('Tên đăng nhập đã tồn tại.');
            }
            DB::table('TAI_KHOAN')->insert([
                'TenDangNhap' => $username,
                'MatKhauHash' => Hash::make($password),
                'TrangThai' => 'Hoat dong',
                'MaVaiTro' => $roleId,
                'MaNV' => null,
            ]);
        });
        $this->info('Đã tạo tài khoản quản trị đầu tiên.');
    } catch (\Throwable $exception) {
        $this->error($exception->getMessage());
    }
})->purpose('Tạo tài khoản quản trị đầu tiên cho Quán Nhậu Tự Do');