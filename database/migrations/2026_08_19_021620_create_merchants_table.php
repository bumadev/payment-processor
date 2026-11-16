<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 255)->unique()->comment('Identificador público para exponer en APIs.');
            $table->enum('status', ['pending', 'active', 'suspended', 'disabled'])->default('pending')->comment('Estado del comercio.');
            $table->string('name', 60);
            $table->string('email', 255)->unique();
            $table->enum('currency', ['USD'])->default('USD')->comment('Moneda principal.');
            $table->timestamps();

            $table->comment('Se almacenan comercios que utilizan la pasarela de pagos.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
