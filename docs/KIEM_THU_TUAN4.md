# KẾ HOẠCH KIỂM THỬ TUẦN 4 – MYSQL/VUE 3

| Mã | Nội dung | Cách kiểm tra | Kết quả mong đợi | Trạng thái |
|---|---|---|---|---|
| TC01 | Kết nối database | Mở API health sau khi cấu hình `.env` | API trả về `status: ok`, database connected | Chờ chạy trên máy nhóm |
| TC02 | Migration 42 bảng | `php artisan migrate --seed` | Tạo đủ 42 bảng nghiệp vụ và bảng `migrations` | Chờ chạy trên máy nhóm |
| TC03 | Đếm bảng | Chạy `database/check_42_tables.sql` trong phpMyAdmin | `so_bang_nghiep_vu = 42` | Chờ chạy trên máy nhóm |
| TC04 | Danh sách cơ sở | GET `/api/branches` | Trả về cơ sở mẫu | Chờ chạy trên máy nhóm |
| TC05 | Danh mục món | GET `/api/categories` | Trả về danh mục mẫu | Chờ chạy trên máy nhóm |
| TC06 | Danh sách món | GET `/api/menu-items?MaCS=1` | Trả về món và giá theo cơ sở | Chờ chạy trên máy nhóm |
| TC07 | Thêm danh mục | POST `/api/categories` | Tạo danh mục hợp lệ | Chờ chạy trên máy nhóm |
| TC08 | Sửa danh mục | PUT `/api/categories/{id}` | Cập nhật thông tin | Chờ chạy trên máy nhóm |
| TC09 | Ngừng danh mục | DELETE `/api/categories/{id}` | Đổi trạng thái, không xóa vật lý | Chờ chạy trên máy nhóm |
| TC10 | Thêm món | POST `/api/menu-items` | Tạo món và giá cho cơ sở | Chờ chạy trên máy nhóm |
| TC11 | Sửa món/giá | PUT `/api/menu-items/{id}` | Cập nhật món và giá | Chờ chạy trên máy nhóm |
| TC12 | Ngừng bán món | DELETE `/api/menu-items/{id}` | Đổi trạng thái món và giá bán | Chờ chạy trên máy nhóm |
| TC13 | Tìm kiếm | GET `/api/menu-items?search=nem` | Lọc theo tên không phân biệt hoa/thường theo collation | Chờ chạy trên máy nhóm |

Không đánh dấu “Đạt” cho đến khi nhóm thực hiện trên máy và lưu bằng chứng ảnh/chụp kết quả.
