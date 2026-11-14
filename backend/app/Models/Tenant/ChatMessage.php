<?php

namespace App\Models\Tenant;

class ChatMessage extends TenantModel
{
    protected $table = 'chat_messages';

    protected $fillable = ['conversation_id', 'sender_id', 'message', 'image_path'];
}
