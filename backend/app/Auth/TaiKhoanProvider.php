<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** UserProvider cho TAI_KHOAN, đọc/ghi với DB::table. */
class TaiKhoanProvider implements UserProvider
{
    private function find(string $column, string|int $value): ?Authenticatable
    {
        $row = DB::table('TAI_KHOAN as tk')
            ->join('VAI_TRO as vt', 'vt.MaVaiTro', '=', 'tk.MaVaiTro')
            ->leftJoin('NHAN_VIEN as nv', 'nv.MaNV', '=', 'tk.MaNV')
            ->where('tk.'.$column, $value)
            ->first([
                'tk.MaTK', 'tk.TenDangNhap', 'tk.MatKhauHash',
                'tk.TrangThai', 'tk.MaVaiTro', 'tk.MaNV',
                'vt.TenVaiTro', 'nv.MaCS', 'nv.HoTen',
                'nv.TrangThai as TrangThaiNV',
            ]);

        if (!$row || $row->TrangThai !== 'Hoat dong') {
            return null;
        }
        if ($row->MaNV !== null && $row->TrangThaiNV !== 'Dang lam') {
            return null;
        }
        return new TaiKhoanUser((array) $row);
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->find('MaTK', (int) $identifier);
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        // Chỉ hỗ trợ phiên (không có remember_token trong 42 bảng).
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
        // Tắt remember-me: schema TAI_KHOAN không có cột remember_token.
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $username = trim((string) ($credentials['TenDangNhap'] ?? ''));
        return $username === '' ? null : $this->find('TenDangNhap', $username);
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return Hash::check((string) ($credentials['password'] ?? ''), $user->getAuthPassword());
    }

    public function rehashPasswordIfRequired(
        Authenticatable $user,
        array $credentials,
        bool $force = false
    ): void {
        if ($force || Hash::needsRehash($user->getAuthPassword())) {
            $newHash = Hash::make($credentials['password']);
            DB::table('TAI_KHOAN')
                ->where('MaTK', $user->getAuthIdentifier())
                ->update(['MatKhauHash' => $newHash]);
            if ($user instanceof TaiKhoanUser) {
                $user->MatKhauHash = $newHash;
            }
        }
    }
}