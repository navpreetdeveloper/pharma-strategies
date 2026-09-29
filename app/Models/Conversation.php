<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = ['company_id', 'type', 'name', 'created_by', 'direct_key'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function participants()
    {
        return $this->belongsToMany(User::class, 'conversation_participants')->withTimestamps();
    }

    /**
     * Messages are intentionally unordered here. Each screen decides whether it
     * needs chronological or reverse-chronological data explicitly. This avoids
     * accidentally stacking an ASC and DESC ORDER BY after a user signs back in.
     */
    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
