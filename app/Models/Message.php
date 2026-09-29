<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'company_id',
        'conversation_id',
        'user_id',
        'body',
        'message_type',
        'edited_at',
        'deleted_at',
        'deleted_by',
        'reply_to_message_id',
        'forwarded_from_message_id',
    ];

    protected $casts = [
        'edited_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo()
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function forwardedFrom()
    {
        return $this->belongsTo(self::class, 'forwarded_from_message_id');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }
}
