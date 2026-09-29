<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class AuditLog extends Model { public function user(){return $this->belongsTo(User::class);} protected $fillable=['company_id','user_id','action','target_type','target_id','ip_address','metadata']; protected $casts=['metadata'=>'array']; }
