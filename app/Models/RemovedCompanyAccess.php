<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RemovedCompanyAccess extends Model
{
    protected $table = 'removed_company_access';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['membership' => 'array', 'removed_at' => 'datetime'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
