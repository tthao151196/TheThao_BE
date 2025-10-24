<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // thêm cột nếu chưa có & cho phép null
        if (!Schema::hasColumn('ptdt_product', 'thumbnail')) {
            Schema::table('ptdt_product', function (Blueprint $table) {
                $table->string('thumbnail')->nullable()->after('id');
            });
        } else {
            // đảm bảo nullable (nếu trước đó NOT NULL)
            Schema::table('ptdt_product', function (Blueprint $table) {
                $table->string('thumbnail')->nullable()->change();
            });
        }

        // gỡ unique nếu đang có (tên mặc định: ptdt_product_thumbnail_unique)
        Schema::table('ptdt_product', function (Blueprint $table) {
            try {
                $table->dropUnique(['thumbnail']); // = dropUnique('ptdt_product_thumbnail_unique')
            } catch (\Throwable $e) {
                // bỏ qua nếu index không tồn tại
            }
        });
    }

    public function down(): void
    {
        Schema::table('ptdt_product', function (Blueprint $table) {
            try { $table->unique('thumbnail'); } catch (\Throwable $e) {}
        });
    }
};
