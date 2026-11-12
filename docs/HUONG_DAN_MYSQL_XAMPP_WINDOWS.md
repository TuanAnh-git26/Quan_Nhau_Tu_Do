# HƯỚNG DẪN KẾT NỐI MYSQL/MARIADB TRÊN XAMPP – NHÓM 8

Tài liệu dành cho máy Windows đã cài XAMPP và phpMyAdmin. Dự án dùng Laravel 12, Vue 3 và MySQL/MariaDB.

## 1. Khởi động dịch vụ

1. Mở XAMPP Control Panel.
2. Nhấn Start tại Apache và MySQL.
3. Mở `http://localhost/phpmyadmin`.
4. Chọn database `quan_nhau_tu_do` đã tạo trước đó. Database đang rỗng là bình thường; Laravel sẽ tạo bảng bằng migration.

## 2. Kiểm tra phiên bản MySQL/MariaDB

Trong phpMyAdmin, xem trang Home hoặc tab SQL chạy:

```sql
SELECT VERSION() AS database_version;
```

Khuyến nghị MySQL 8.0.16+ hoặc MariaDB 10.3+ để các CHECK constraint trong schema được thực thi. Nếu XAMPP dùng phiên bản cũ hơn, cần nâng XAMPP/MariaDB trước khi chạy schema; không bỏ các ràng buộc CHECK để né lỗi.

## 3. Kiểm tra PHP và driver

Mở CMD mới và chạy:

```bat
php -v
where php
php -m | findstr /I "pdo_mysql mysqli"
composer --version
```

Máy của nhóm đang dùng PHP 8.2.12 tại `C:\xampp\php\php.exe`. Bộ source này dùng Laravel 12 (`php ^8.2`) để tương thích với PHP hiện tại. Cần thấy `pdo_mysql` và `mysqli` trong danh sách extension.

Nếu thiếu driver, mở `C:\xampp\php\php.ini`, tìm và bỏ dấu `;` đầu dòng:

```ini
extension=mysqli
extension=pdo_mysql
```

Lưu file, khởi động lại terminal và kiểm tra `php -m` lần nữa.

## 4. Cấu hình Laravel

Trong thư mục `backend`, sao chép `.env.example` thành `.env`:

```bat
copy .env.example .env
```

Cấu hình database mẫu cho XAMPP mặc định:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=quan_nhau_tu_do
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

`root` và mật khẩu trống chỉ đúng nếu XAMPP của bạn đang dùng cấu hình mặc định. Nếu tài khoản MySQL đã đặt mật khẩu, hãy nhập mật khẩu đó vào `DB_PASSWORD`. Không gửi mật khẩu cho người khác.

## 5. Cài dependencies và tạo bảng

Tại Terminal trong `backend`:

```bat
composer install
php artisan key:generate
php artisan config:clear
php artisan migrate --seed
```

Migration đọc `database/schema_mysql.sql`, tạo 42 bảng nghiệp vụ và bảng `migrations` do Laravel quản lý. Seeder thêm dữ liệu minh họa cho cơ sở, danh mục món, món ăn và giá bán.

## 6. Kiểm tra trong phpMyAdmin

1. Chọn database `quan_nhau_tu_do`.
2. Nhấn Refresh nếu cần.
3. Tab Structure sẽ hiển thị 42 bảng nghiệp vụ và bảng `migrations`.
4. Tab SQL chạy nội dung trong `backend/database/check_42_tables.sql`; kết quả mong đợi `so_bang_nghiep_vu = 42`.

## 7. Chạy backend và frontend

Backend (Terminal 1, thư mục `backend`):

```bat
php artisan serve --host=127.0.0.1 --port=8000
```

Frontend (Terminal 2, thư mục `frontend`):

```bat
copy .env.example .env
npm install
npm run dev
```

Mở URL Vite hiển thị, thường là `http://localhost:5173`.

## 8. Lưu ý

- Không tạo 42 bảng thủ công trong phpMyAdmin; Laravel migration tạo schema.
- Không chạy `php artisan migrate:fresh --seed` trên database có dữ liệu cần giữ vì lệnh này xóa toàn bộ bảng trước khi tạo lại.
- Schema MySQL dùng cột sinh `MaBanOrderMo` để mô phỏng unique index có điều kiện của PostgreSQL, bảo đảm tối đa một order trạng thái `Dang mo` cho mỗi bàn. Cột này là chi tiết triển khai, không phải bảng nghiệp vụ mới.
- MySQL không hỗ trợ partial index như PostgreSQL; chỉ mục FIFO được chuyển thành chỉ mục ghép thông thường có thêm `SoLuongCon`.
- Bộ source là bản triển khai Tuần 4/demo, chưa phải hệ thống production; cần bổ sung xác thực, phân quyền, kiểm thử nghiệp vụ và rà soát bảo mật trước khi dùng dữ liệu thật.
