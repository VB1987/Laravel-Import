<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAttributesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('attribute', function (Blueprint $table) {
            $table->id();
            $table->integer('siteid');
            $table->string('type', 32);
            $table->string('domain', 32);
            $table->string('code', 255);
            $table->string('label', 255);
            $table->integer('pos');
            $table->smallInteger('status');
            $table->timestamps();
            $table->string('editor', 255);
        });

        Schema::create('attribute_type', function (Blueprint $table) {
            $table->id();
            $table->integer('siteid');
            $table->string('type', 32);
            $table->string('domain', 32);
            $table->string('code', 255);
            $table->string('label', 255);
            $table->integer('pos');
            $table->smallInteger('status');
            $table->timestamps();
            $table->string('editor', 255);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('attribute');
        Schema::dropIfExists('attribute_type');
    }
}
