# PHẠM VI TRIỂN KHAI TUẦN 4 – NHÓM 8

## Công nghệ và kiến trúc
- Ứng dụng web nhiều lớp, REST API.
- Backend: PHP 8.2+ / Laravel 12.
- Frontend: Vue 3 + Vite.
- Database: MySQL/MariaDB, InnoDB, utf8mb4.
- Mã nguồn quản lý bằng Git.

## Hạng mục đã chuẩn bị trong source
1. Cấu trúc backend Laravel 12 và frontend Vue 3.
2. `backend/database/schema_mysql.sql` tạo 42 bảng nghiệp vụ theo thiết kế nhóm.
3. Migration Laravel nạp schema MySQL; dữ liệu mẫu được tạo bằng seeder.
4. REST API và giao diện CRUD danh mục/món ăn, gồm thêm, xem, sửa và ngừng bán.
5. Tài liệu cài đặt XAMPP/phpMyAdmin, cấu hình kết nối, kiểm tra 42 bảng.

## Hạng mục cần xác nhận trên máy nhóm
- Kết nối PHP 8.2.12 với MySQL/MariaDB thông qua `pdo_mysql`.
- Chạy `php artisan migrate --seed` thành công.
- Kiểm tra 42 bảng trong phpMyAdmin.
- Chạy API và Vue 3; kiểm thử CRUD thực tế.

## Tương thích MySQL
- Các ID dùng `BIGINT UNSIGNED AUTO_INCREMENT`; khóa ngoại cùng kiểu unsigned.
- `TIMESTAMPTZ` được thay bằng `DATETIME(6)` cho MySQL.
- Tên bảng/cột được quote bằng backtick.
- Partial unique index cho order mở được thay bằng generated column nullable và unique index.
- Partial FIFO index được thay bằng composite index `(MaNL, MaPN, SoLuongCon)`.
