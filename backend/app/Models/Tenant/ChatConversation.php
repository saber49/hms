<?php

namespace App\Models\Tenant;

class ChatConversation extends TenantModel
{
    protected $table = 'chat_conversations';

    protected $fillable = ['title', 'created_by'];

    public function participants()
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }
}
