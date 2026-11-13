<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Các quyền Use Case theo Bảng UC của BaoCao_Nhom8_v3.
 * Chỉ bổ sung quyền còn thiếu, không tạo tài khoản và không xóa quyền hiện hữu.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'Quan tri he thong' => [],
            'Giam sat' => ['UC1.2', 'UC1.3', 'UC2.1', 'UC2.3', 'UC2.5', 'UC5.3', 'UC5.6', 'UC6.3'],
            'Phuc vu' => ['UC1.1', 'UC1.3', 'UC2.2', 'UC2.3', 'UC2.4', 'UC2.5', 'UC5.1'],
            'Thu ngan' => ['UC5.1', 'UC5.2', 'UC5.3', 'UC5.4', 'UC5.5', 'UC5.6'],
            'Bep' => ['UC2.4', 'UC3.1', 'UC3.2', 'UC3.3', 'UC4.5'],
            'Bar' => ['UC2.4', 'UC3.1', 'UC3.2', 'UC3.3', 'UC4.5'],
            'Kho' => ['UC4.1', 'UC4.2', 'UC4.3', 'UC4.4'],
            'Mua hang' => ['UC4.6', 'UC4.7'],
            'Nhan su' => ['UC6.1', 'UC6.2', 'UC6.4'],
            'Ke toan' => ['UC6.4', 'UC7.1', 'UC7.2', 'UC7.5'],
            'Marketing' => ['UC7.3', 'UC7.4'],
            'Ban giam doc' => ['UC2.1', 'UC4.7', 'UC7.5', 'UC7.6'],
            'IT' => ['UC8.3', 'UC8.4', 'UC8.5'],
        ];

        DB::transaction(function () use ($roles): void {
            foreach ($roles as $roleName => $useCases) {
                DB::table('VAI_TRO')->insertOrIgnore([
                    'TenVaiTro' => $roleName,
                    'MoTaQuyen' => 'Phân quyền theo Bảng Use Case của báo cáo Nhóm 8 v3',
                ]);

                $roleId = DB::table('VAI_TRO')
                    ->where('TenVaiTro', $roleName)
                    ->value('MaVaiTro');

                if ($roleName !== 'Quan tri he thong') {
                    $useCases = array_values(array_unique(array_merge(
                        $useCases,
                        ['UC8.1', 'UC8.2', 'UC8.5']
                    )));
                }

                foreach ($useCases as $useCase) {
                    DB::table('PHAN_QUYEN')->insertOrIgnore([
                        'MaVaiTro' => $roleId,
                        'MaUC' => $useCase,
                    ]);
                }
            }
        });
    }
}
