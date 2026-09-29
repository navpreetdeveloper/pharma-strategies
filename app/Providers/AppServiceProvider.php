<?php
namespace App\Providers; use Illuminate\Support\ServiceProvider; use Illuminate\Support\Facades\Schema; use Illuminate\Pagination\Paginator;
class AppServiceProvider extends ServiceProvider { public function register():void{} public function boot():void{Schema::defaultStringLength(191);Paginator::useTailwind();} }
