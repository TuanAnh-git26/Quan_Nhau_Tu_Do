<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RequireUseCase
{
    public function handle(Request $request, Closure $next, string ...$useCases): Response
    {
        $user = $request->user();
        abort_unless($user, 401, 'Bạn cần đăng nhập.');

        if ($user->TenVaiTro === 'Quan tri he thong') {
            return $next($request);
        }

        $allowed = DB::table('PHAN_QUYEN')
            ->where('MaVaiTro', (int) $user->MaVaiTro)
            ->whereIn('MaUC', $useCases)
            ->exists();

        abort_unless($allowed, 403, 'Tài khoản không có quyền thực hiện chức năng này.');
        return $next($request);
    }
}