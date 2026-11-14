<?php

namespace App\Models\Tenant;

class TenantUser extends TenantModel
{
    protected $table = 'users';

    protected $fillable = [
        'username', 'password_hash', 'full_name', 'role', 'aktiv', 'last_login',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'aktiv' => 'boolean',
        'last_login' => 'datetime',
    ];
}
