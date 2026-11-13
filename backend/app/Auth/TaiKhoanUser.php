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
}