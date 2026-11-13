<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent: tạo quyền mẫu trước; không tạo tài khoản hoặc mật khẩu mặc định.
        $this->call(RolePermissionSeeder::class);

        // Không tạo trùng dữ liệu mẫu khi chạy db:seed trên CSDL đã vận hành.
        if (DB::table('CO_SO')
            ->where('TenCS', 'Quán Nhậu Tự Do - Cơ sở Cầu Giấy (Demo)')
            ->exists()) {
            return;
        }

        // Dữ liệu minh họa, không phải dữ liệu vận hành thật của nhà hàng.
        $cs1 = DB::table('CO_SO')->insertGetId([
            'TenCS' => 'Quán Nhậu Tự Do - Cơ sở Cầu Giấy (Demo)',
            'DiaChi' => 'Địa chỉ minh họa',
            'SDT' => '0900000001',
            'TrangThai' => 'Dang hoat dong',
        ], 'MaCS');

        $cs2 = DB::table('CO_SO')->insertGetId([
            'TenCS' => 'Quán Nhậu Tự Do - Cơ sở Tây Hồ (Demo)',
            'DiaChi' => 'Địa chỉ minh họa',
            'SDT' => '0900000002',
            'TrangThai' => 'Dang hoat dong',
        ], 'MaCS');

        foreach (['Quan ly', 'Phuc vu', 'Thu ngan', 'Bep', 'Bar'] as $position) {
            DB::table('VI_TRI')->insertOrIgnore(['TenVT' => $position]);
        }

        DB::table('KHU_VUC')->insert([
            ['TenKV' => 'San trong nha', 'MaCS' => $cs1],
            ['TenKV' => 'San ngoai troi', 'MaCS' => $cs1],
            ['TenKV' => 'Tang 1', 'MaCS' => $cs2],
        ]);

        $food = DB::table('DANH_MUC_MON')->insertGetId([
            'TenDM' => 'Mon nhau', 'NoiCheBien' => 'Bep', 'MoTa' => 'Các món ăn chế biến tại bếp', 'TrangThai' => 'Dang hoat dong'
        ], 'MaDM');
        $snack = DB::table('DANH_MUC_MON')->insertGetId([
            'TenDM' => 'Mon khai vi', 'NoiCheBien' => 'Bep', 'MoTa' => 'Món khai vị', 'TrangThai' => 'Dang hoat dong'
        ], 'MaDM');
        $drink = DB::table('DANH_MUC_MON')->insertGetId([
            'TenDM' => 'Do uong', 'NoiCheBien' => 'Bar', 'MoTa' => 'Đồ uống pha chế và đóng chai', 'TrangThai' => 'Dang hoat dong'
        ], 'MaDM');

        $items = [
            ['TenMon' => 'Nem chua rán', 'DonViTinh' => 'Đĩa', 'MaDM' => $snack, 'price' => 65000],
            ['TenMon' => 'Chân gà sốt', 'DonViTinh' => 'Đĩa', 'MaDM' => $food, 'price' => 89000],
            ['TenMon' => 'Đậu phụ chiên sả', 'DonViTinh' => 'Đĩa', 'MaDM' => $snack, 'price' => 55000],
            ['TenMon' => 'Mực nướng sa tế', 'DonViTinh' => 'Đĩa', 'MaDM' => $food, 'price' => 159000],
            ['TenMon' => 'Lạc rang muối', 'DonViTinh' => 'Đĩa', 'MaDM' => $snack, 'price' => 35000],
            ['TenMon' => 'Bia chai', 'DonViTinh' => 'Chai', 'MaDM' => $drink, 'price' => 25000],
        ];

        foreach ($items as $item) {
            $price = $item['price'];
            unset($item['price']);
            $item['TrangThai'] = 'Dang ban';
            $maMon = DB::table('MON')->insertGetId($item, 'MaMon');
            DB::table('GIA_MON_CO_SO')->insert([
                ['MaCS' => $cs1, 'MaMon' => $maMon, 'DonGia' => $price, 'TrangThaiBan' => 'Dang ban'],
                ['MaCS' => $cs2, 'MaMon' => $maMon, 'DonGia' => $price, 'TrangThaiBan' => 'Dang ban'],
            ]);
        }
    }
}
