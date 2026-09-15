<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V1.1 F05 / T-024：收藏与浏览足迹
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamps();

            $table->unique(['user_id', 'product_id']);
            $table->index('user_id');
        });

        Schema::create('browse_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamp('browsed_at');
            $table->timestamps();

            $table->unique(['user_id', 'product_id']);
            $table->index(['user_id', 'browsed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browse_histories');
        Schema::dropIfExists('favorites');
    }
};
