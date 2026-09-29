<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request;
class EnsureRole { public function handle(Request $request,Closure $next,...$roles){ $user=$request->user(); if(!$user) abort(403); if($user->isPlatformSuperAdmin()) return $next($request); if(!$user->hasAnyRole($roles)) abort(403); return $next($request); } }
