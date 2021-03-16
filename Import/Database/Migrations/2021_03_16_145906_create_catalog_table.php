<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCatalogTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('catalog', function (Blueprint $table) {
            $table->id();
            $table->integer('parentid');
            $table->integer('siteid');
            $table->smallInteger('level');
            $table->string('code', 32);
            $table->string('label', 255);
            $table->string('config', 255);
            $table->integer('nleft');
            $table->integer('nright');
            $table->smallInteger('status');
            $table->timestamps();
            $table->string('editor', 255);
            $table->string('target', 255);
        });

        Schema::create('catalogList', function (Blueprint $table) {
            $table->id();
            $table->integer('parentid');
            $table->integer('siteid');
            $table->smallInteger('level');
            $table->string('code', 32);
            $table->string('label', 255);
            $table->string('config', 255);
            $table->integer('nleft');
            $table->integer('nright');
            $table->smallInteger('status');
            $table->timestamps();
            $table->string('editor', 255);
            $table->string('target', 255);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('catalog');
        Schema::dropIfExists('catalogList');
    }
}
