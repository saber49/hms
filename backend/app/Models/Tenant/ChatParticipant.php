<?php

namespace App\Models\Tenant;

class ChatParticipant extends TenantModel
{
    protected $table = 'chat_participants';

    protected $fillable = ['conversation_id', 'user_id', 'last_read_at'];
}
