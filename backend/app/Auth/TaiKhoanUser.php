<?php

namespace App\Auth;

use Illuminate\Auth\GenericUser;

/** Tài khoản đăng nhập từ bảng TAI_KHOAN; không sử dụng Eloquent. */
class TaiKhoanUser extends GenericUser
{
    public function getAuthIdentifierName()
    {
        return 'MaTK';
    }

    public function getAuthPasswordName()
    {
        return 'MatKhauHash';
    }

    public function getAuthPassword()
    {
        return (string) ($this->attributes['MatKhauHash'] ?? '');
    }

    // TAI_KHOAN không có cột remember_token (tắt "ghi nhớ đăng nhập").
    // GenericUser mặc định đọc $attributes['remember_token'] nên Auth::logout() bị lỗi
    // "Undefined array key remember_token" (HTTP 500) nếu không ghi đè các hàm dưới đây.
    public function getRememberTokenName()
    {
        return '';
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value)
    {
        // Không lưu remember token.
    }
}