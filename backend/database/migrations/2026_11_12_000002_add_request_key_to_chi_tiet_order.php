<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('CHI_TIET_ORDER', 'MaYeuCau')) {
            Schema::table('CHI_TIET_ORDER', function (Blueprint $table) {
                $table->string('MaYeuCau', 80)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('CHI_TIET_ORDER', 'MaYeuCau')) {
            Schema::table('CHI_TIET_ORDER', function (Blueprint $table) {
                $table->dropUnique(['MaYeuCau']);
                $table->dropColumn('MaYeuCau');
            });
        }
    }
};
