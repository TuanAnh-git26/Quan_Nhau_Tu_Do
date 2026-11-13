# QUÁN NHẬU TỰ DO – NHÓM 8 | TRIỂN KHAI TUẦN 4

Bộ source khởi tạo hệ thống quản lý Quán Nhậu Tự Do theo báo cáo Nhóm 8. Bản này được cấu hình cho môi trường XAMPP/phpMyAdmin hiện có của nhóm.

## 1. Công nghệ

- Backend: PHP 8.2+ / Laravel 12 / REST API.
- Frontend: Vue 3 + Vite.
- Database: MySQL 8.0.16+ hoặc MariaDB 10.3+ (InnoDB, utf8mb4).
- Quản lý mã nguồn: Git.

Laravel 12 được chọn để tương thích PHP 8.2.12 đang có trong XAMPP của nhóm. Laravel hỗ trợ MySQL/MariaDB; xem tài liệu chính thức tại https://laravel.com/docs/12.x/database.

## 2. Phạm vi source Tuần 4

- Cấu trúc backend Laravel và frontend Vue 3.
- Schema MySQL gồm 42 bảng nghiệp vụ theo các cụm trong báo cáo.
- Khóa chính, khóa ngoại, khóa ghép, CHECK, UNIQUE và chỉ mục phù hợp MySQL/MariaDB.
- Dữ liệu mẫu cho cơ sở, danh mục món, món ăn/đồ uống và giá theo cơ sở.
- REST API cho cơ sở, danh mục món và món.
- Giao diện quản lý danh mục và thực đơn: thêm, xem, sửa, ngừng bán; lọc theo cơ sở, danh mục, trạng thái và tìm kiếm.
- Không xóa vật lý món đã phát sinh nghiệp vụ; thao tác xóa chuyển trạng thái sang “Ngừng bán”.

Đây là bản khởi tạo và chức năng CRUD đầu tiên của Tuần 4, chưa phải hệ thống hoàn chỉnh 8 phân hệ. Đăng nhập/phân quyền đầy đủ, WebSocket, PWA offline, thanh toán thật, in hóa đơn, kiểm thử tải và triển khai máy chủ sẽ được tích hợp ở các giai đoạn sau.

## 3. Yêu cầu cài đặt

1. PHP 8.2+ và extension `pdo_mysql`, `mysqli`, `mbstring`, `openssl`, `fileinfo`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`.
2. Composer 2.
3. Node.js 20+ và npm.
4. XAMPP với MySQL/MariaDB đang chạy.
5. Visual Studio Code (khuyến nghị).

Kiểm tra:

```bat
php -v
where php
php -m | findstr /I "pdo_mysql mysqli"
composer --version
node -v
npm -v
```

## 4. Chuẩn bị database trong phpMyAdmin

1. Mở XAMPP Control Panel và nhấn Start tại Apache, MySQL.
2. Mở `http://localhost/phpmyadmin`.
3. Database `quan_nhau_tu_do` đã tạo trước đó có thể được giữ nguyên nếu đang rỗng. Nếu chưa có, tạo database mới cùng tên, collation `utf8mb4_unicode_ci`.
4. Không tạo bảng thủ công; Laravel sẽ tạo 42 bảng bằng migration.

Hướng dẫn chi tiết: `docs/HUONG_DAN_MYSQL_XAMPP_WINDOWS.md`.

## 5. Chạy Backend Laravel

Mở Terminal tại thư mục `backend`:

```bat
copy .env.example .env
composer install
php artisan key:generate
php artisan config:clear
```

Cấu hình `backend/.env` theo tài khoản MySQL thực tế. Mặc định XAMPP thường là:

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

Nếu MySQL của bạn có mật khẩu, nhập mật khẩu đó vào `DB_PASSWORD`.

Tạo bảng và dữ liệu mẫu:

```bat
php artisan migrate --seed
```

Khởi động API:

```bat
php artisan serve --host=127.0.0.1 --port=8000
```

API kiểm tra:

- `http://127.0.0.1:8000/api/health`
- `http://127.0.0.1:8000/api/branches`
- `http://127.0.0.1:8000/api/categories`
- `http://127.0.0.1:8000/api/menu-items?MaCS=1`

## 6. Chạy Frontend Vue 3

Mở Terminal thứ hai tại thư mục `frontend`:

```bat
copy .env.example .env
npm install
npm run dev
```

Mở URL Vite hiển thị trong Terminal, thường là `http://localhost:5173`.

## 7. API chính

| Method | Endpoint | Chức năng |
|---|---|---|
| GET | `/api/health` | Kiểm tra API và database |
| GET | `/api/branches` | Danh sách cơ sở |
| GET/POST | `/api/categories` | Xem/thêm danh mục |
| PUT | `/api/categories/{id}` | Sửa danh mục |
| DELETE | `/api/categories/{id}` | Ngừng hoạt động danh mục |
| GET/POST | `/api/menu-items` | Xem/thêm món |
| PUT | `/api/menu-items/{id}` | Sửa món/giá theo cơ sở |
| DELETE | `/api/menu-items/{id}` | Ngừng bán món |

## 8. Kiểm tra 42 bảng

Trong phpMyAdmin, chọn database `quan_nhau_tu_do`, mở tab SQL và chạy nội dung `backend/database/check_42_tables.sql`. Kết quả mong đợi: `so_bang_nghiep_vu = 42`. Ngoài ra có bảng `migrations` do Laravel quản lý.

## 9. Lưu ý quan trọng

- `php artisan migrate:fresh --seed` xóa toàn bộ bảng và dữ liệu trước khi tạo lại; chỉ dùng trên database thử nghiệm.
- Schema giữ tên bảng/cột theo báo cáo (ví dụ `MON`, `MaMon`).
- Để thay thế partial unique index của PostgreSQL, bảng `PHIEU_ORDER` có thêm cột sinh `MaBanOrderMo` và unique index tương ứng; cột này không làm tăng số bảng nghiệp vụ.
- Chỉ mục FIFO được chuyển sang index ghép thông thường do MySQL/MariaDB không có partial index như PostgreSQL.
- Kiểm tra phiên bản MySQL/MariaDB bằng `SELECT VERSION();`. Các CHECK constraint cần MySQL 8.0.16+ hoặc MariaDB đủ mới (khuyến nghị MariaDB 10.3+).
- Báo cáo mô tả chi tiết kiểu dữ liệu cho một số bảng trọng tâm; một số trường của các bảng còn lại là đề xuất triển khai dựa trên ERD và mô tả nghiệp vụ, cần nhóm đối chiếu trước khi dùng dữ liệu thật.
- Đây là bản demo Tuần 4, chưa có đầy đủ xác thực và phân quyền production. Không nhập dữ liệu khách hàng/nhân viên thật.


## Cập nhật danh mục thực đơn công khai

Sau khi đã cài đặt và chạy migration, từ thư mục `backend` chạy:

```bash
php artisan db:seed --class=OfficialMenuSeeder
```

Seeder bổ sung danh sách cơ sở và 24 món có tên được xác minh từ nguồn công khai, không xóa dữ liệu hiện có. Xem `docs/DU_LIEU_THUC_DON_CONG_KHAI.md` để biết giới hạn nguồn dữ liệu và `docs/GIA_THAM_KHAO_WEBSITE.csv` để tham khảo giá hiển thị trên trang chủ. Không chạy `migrate:fresh`.


## Khởi tạo lần đầu – Nhóm 8 (phiên bản báo cáo v3)

1. Sao lưu cơ sở dữ liệu nếu đã có dữ liệu. Chỉnh `backend/.env` để trỏ tới **đúng database** trên XAMPP.
2. Với **database hoàn toàn mới**, nạp `backend/database/schema_mysql.sql` theo hướng dẫn dự án, sau đó kiểm tra migration hiện có trước khi chạy `php artisan migrate`. **Không dùng `migrate:fresh` trên database đang sử dụng.**
3. Từ thư mục `backend`, chạy `php artisan migrate:status` và `php artisan db:seed` (phân quyền mẫu được bổ sung qua `RolePermissionSeeder`). Nếu đã có dữ liệu mẫu, có thể chỉ chạy `php artisan db:seed --class=RolePermissionSeeder` để tránh trùng.
4. Chạy `php artisan qntd:make-admin <tên_đăng_nhập>`, nhập mật khẩu theo lời nhắc trong Terminal. Không seed mật khẩu mặc định vào source.
5. Đăng nhập bằng tài khoản quản trị, chọn cơ sở và tạo tài khoản cho nhân viên tại **Quản trị → Tài khoản**. Gán vai trò theo bảng Use Case v3.
6. Bộ seed **chỉ bổ sung** quyền còn thiếu và không tự thu hồi quyền cũ đã gán. Nếu trước đó đã gán sai UC, cần rà soát ở trang Quản trị → Vai trò & quyền.

### Kiểm thử tích hợp an toàn

- Tạo riêng database `quan_nhau_tu_do_test` và nhập cấu trúc `schema_mysql.sql` vào database test. Không nhập dữ liệu vận hành thật.
- Đặt biến môi trường `DB_DATABASE=quan_nhau_tu_do_test` trong `backend/.env.testing` (hoặc cấu hình PHPUnit phù hợp).
- Chạy `php artisan config:clear`, rồi `php artisan test --testsuite=Feature`.
- Không chạy `RefreshDatabase`/`migrate:fresh` trên database thật. Các test mới có chốt an toàn, từ chối database không có hậu tố `_test`.
