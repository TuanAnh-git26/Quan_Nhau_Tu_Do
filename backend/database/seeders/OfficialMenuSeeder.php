<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OfficialMenuSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {

            $branches = [
                ['Quán Nhậu Tự Do - 10 Nguyễn Văn Huyên', '10 Nguyễn Văn Huyên, Cầu Giấy, Hà Nội', 'Dang hoat dong'],
                ['Quán Nhậu Tự Do - 68 Khúc Thừa Dụ', '68 Khúc Thừa Dụ, Cầu Giấy, Hà Nội', 'Dang hoat dong'],
                ['Quán Nhậu Tự Do - 67A Phó Đức Chính', '67A Phó Đức Chính, Ba Đình, Hà Nội', 'Dang hoat dong'],
                ['Lẩu Cua Đồng Tự Do - 86 Ngọc Khánh', '86 Ngọc Khánh, Ba Đình, Hà Nội', 'Dang hoat dong'],
                ['Nướng Tự Do - Ngoại Giao Đoàn', 'Khu Ngoại Giao Đoàn, Hà Nội', 'Dang hoat dong'],
                ['Nướng Tự Do - 02 Lê Đức Thọ', '02 Lê Đức Thọ, Cầu Giấy, Hà Nội', 'Dang hoat dong'],
                ['Quán Nhậu Tự Do - 02 Lê Đức Thọ', '02 Lê Đức Thọ, Cầu Giấy, Hà Nội', 'Dang hoat dong'],
                ['Quán Nhậu Tự Do - 112 Nguyễn Văn Tuyết', '112 Nguyễn Văn Tuyết, Đống Đa, Hà Nội', 'Dang hoat dong'],
                ['Quán Nhậu Tự Do - 68 Láng Thượng', '68 Láng Thượng, Đống Đa, Hà Nội', 'Dang hoat dong'],
                ['Quán Nhậu Tự Do - 75 Yên Lãng', '75 Yên Lãng, Đống Đa, Hà Nội', 'Dang hoat dong'],
                ['Quán Nhậu Tự Do - 08 Lê Đại Hành', '08 Lê Đại Hành, Hai Bà Trưng, Hà Nội', 'Ngung hoat dong'],
                ['Quán Nhậu Tự Do - 505 Minh Khai', '505 Minh Khai, Hai Bà Trưng, Hà Nội', 'Dang hoat dong'],
                ['Quán Nhậu Tự Do - Nguyễn Hữu Thọ (Linh Đàm)', 'Nguyễn Hữu Thọ, Linh Đàm, Hà Nội', 'Dang hoat dong'],
                ['Nướng Tự Do - 89 Thành Trung', '89 Thành Trung, Gia Lâm, Hà Nội', 'Dang hoat dong'],
                ['Quán Nhậu Tự Do - 86 Hồng Tiến', '86 Hồng Tiến, Long Biên, Hà Nội', 'Dang hoat dong'],
                ['Quán Nhậu Tự Do - Mega Grand World', 'Mega Grand World, Ocean Park, Hà Nội', 'Dang hoat dong'],
                ['Quán Nhậu Tự Do - 201 Nguyễn Tuân', '201 Nguyễn Tuân, Thanh Xuân, Hà Nội', 'Dang hoat dong'],
                ['Quán Nhậu Tự Do - Gleximco A An Khánh', 'Gleximco A An Khánh, Vinhomes Smart City, Hà Nội', 'Dang hoat dong'],
                ['Quán Nhậu Tự Do - Parking Zone 4', 'Parking Zone 4, Vinhomes Smart City, Hà Nội', 'Dang hoat dong'],
            ];

            foreach ($branches as [$name, $address, $status]) {
                if (!DB::table('CO_SO')->where('TenCS', $name)->exists()) {
                    DB::table('CO_SO')->insert([
                        'TenCS' => $name,
                        'DiaChi' => $address,
                        'SDT' => null,
                        'TrangThai' => $status,
                    ]);
                }
            }

            // Việt hóa ba danh mục mẫu.
            // Giữ nguyên MaDM để không ảnh hưởng khóa ngoại.

            $oldCategories = [
                'Mon nhau' => 'Món nhậu',
                'Mon khai vi' => 'Món khai vị',
                'Do uong' => 'Đồ uống',
            ];

            foreach ($oldCategories as $old => $new) {
                $exists = DB::table('DANH_MUC_MON')
                    ->where('TenDM', $new)
                    ->exists();

                if (!$exists) {
                    DB::table('DANH_MUC_MON')
                        ->where('TenDM', $old)
                        ->update(['TenDM' => $new]);
                }
            }

            // Danh mục thực đơn.
            // NoiCheBien giữ mã kỹ thuật Bep/Bar theo CSDL.

            $categories = [
                ['Món nhậu', 'Bep'],
                ['Món khai vị', 'Bep'],
                ['Đồ uống', 'Bar'],
                ['Món bê và bò', 'Bep'],
                ['Món đậu và rau', 'Bep'],
                ['Món nổ muối', 'Bep'],
                ['Đặc sản vùng miền', 'Bep'],
                ['Món nướng', 'Bep'],
                ['Gỏi và nộm', 'Bep'],
            ];

            foreach ($categories as [$name, $department]) {
                $exists = DB::table('DANH_MUC_MON')
                    ->where('TenDM', $name)
                    ->exists();

                if (!$exists) {
                    DB::table('DANH_MUC_MON')->insert([
                        'TenDM' => $name,
                        'NoiCheBien' => $department,
                        'MoTa' => 'Danh mục thực đơn tham khảo, cần đối chiếu khi vận hành.',
                        'TrangThai' => 'Dang hoat dong',
                    ]);
                }
            }

            $categoryIds = DB::table('DANH_MUC_MON')
                ->pluck('MaDM', 'TenDM');

            // Tên món được đối chiếu từ thông tin công khai.
            // Đơn vị tính là dữ liệu tạm, cần đối chiếu menu.
            // KHÔNG tạo giá giả trong GIA_MON_CO_SO.

            $dishes = [
                ['Bê thui', 'Phần', 'Món bê và bò'],
                ['Đậu Pháp xào tôm sốt XO', 'Phần', 'Món đậu và rau'],
                ['Bò tơ nướng riềng mẻ', 'Phần', 'Món nướng'],
                ['Đậu tẩm hành Tự Do chiên cả bìa', 'Đĩa', 'Món đậu và rau'],
                ['Bê ghim', 'Phần', 'Món bê và bò'],
                ['Gà đen nướng mọi', 'Con', 'Món nướng'],
                ['Bê xào rau muống', 'Phần', 'Món bê và bò'],
                ['Đậu mơ lướt ván', 'Đĩa', 'Món đậu và rau'],
                ['Lạp xưởng Tây Bắc xào dân tộc', 'Phần', 'Đặc sản vùng miền'],
                ['Nộm bê bóp thấu', 'Đĩa', 'Gỏi và nộm'],
                ['Bê tái chanh', 'Phần', 'Món bê và bò'],
                ['Bê xào măng trúc', 'Phần', 'Món bê và bò'],
                ['Gỏi bê thui ba miền', 'Đĩa', 'Gỏi và nộm'],
                ['Thập toàn bê thui', 'Phần', 'Món bê và bò'],
                ['Bê xào sả ớt', 'Phần', 'Món bê và bò'],
                ['Ếch nổ muối', 'Phần', 'Món nổ muối'],
                ['Lươn nổ muối', 'Phần', 'Món nổ muối'],
                ['Chim câu nổ muối', 'Con', 'Món nổ muối'],
                ['Ba ba nổ muối', 'Phần', 'Món nổ muối'],
                ['Tóp mỡ dưa chua', 'Đĩa', 'Đặc sản vùng miền'],
                ['Cà mặn Nghệ An', 'Đĩa', 'Đặc sản vùng miền'],
                ['Lươn xào sả ớt', 'Phần', 'Đặc sản vùng miền'],
                ['Chim câu xào cà pháo', 'Phần', 'Đặc sản vùng miền'],
                ['Gân kiệu Huế', 'Đĩa', 'Đặc sản vùng miền'],
            ];

            foreach ($dishes as [$name, $unit, $category]) {
                $exists = DB::table('MON')
                    ->where('TenMon', $name)
                    ->exists();

                if (!$exists) {
                    DB::table('MON')->insert([
                        'TenMon' => $name,
                        'DonViTinh' => $unit,
                        'MaDM' => $categoryIds[$category],
                        'TrangThai' => 'Dang ban',
                    ]);
                }
            }

        });
    }
}
