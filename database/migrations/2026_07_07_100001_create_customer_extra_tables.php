<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('customer_family')) {
            Schema::create('customer_family', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('customer_id');
                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
                $table->string('name');
                $table->string('relation')->default('Kind');
                $table->date('birth_date')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('customer_vehicles')) {
            Schema::create('customer_vehicles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('customer_id');
                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
                $table->string('brand');
                $table->string('model')->nullable();
                $table->string('license_plate')->nullable();
                $table->integer('year')->nullable();
                $table->string('vin')->nullable();
                $table->timestamps();
            });
        }
        $cols = ['email2','address2','iban2','nationality','occupation','last_contact'];
        foreach ($cols as $col) {
            if (!Schema::hasColumn('customers', $col)) {
                Schema::table('customers', function (Blueprint $table) use ($col) {
                    if ($col === 'last_contact') $table->date($col)->nullable();
                    else $table->string($col)->nullable();
                });
            }
        }
        foreach (['added_by','contract_color'] as $col) {
            if (!Schema::hasColumn('contracts', $col)) {
                Schema::table('contracts', function (Blueprint $table) use ($col) {
                    $table->string($col)->nullable();
                });
            }
        }
        foreach (['color','contract_id'] as $col) {
            if (!Schema::hasColumn('documents', $col)) {
                Schema::table('documents', function (Blueprint $table) use ($col) {
                    $table->string($col)->nullable();
                });
            }
        }
    }
    public function down(): void {
        Schema::dropIfExists('customer_vehicles');
        Schema::dropIfExists('customer_family');
    }
};
