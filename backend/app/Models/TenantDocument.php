<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legal / corporate paperwork attached to a tenant (Gewerbeschein,
 * Handelsregisterauszug, Steuerbescheid, insurance certificates, …).
 *
 * Files live on the `public` disk under `tenants/{slug}/documents/`.
 *
 * SECURITY: `file_path` is treated as an internal implementation detail —
 * it MUST NOT be exposed in any API response. Instead, the frontend
 * addresses files by id and the bytes are streamed through the dedicated
 *   GET /api/dashboard/documents/{id}/view
 *   GET /api/dashboard/documents/{id}/download
 * endpoints which sit behind auth:sanctum + tenant.boundary +
 * permission:users.manage and re-verify `tenant_id` on every request.
 */
class TenantDocument extends Model
{
    protected $connection = 'mysql';
    protected $table = 'tenant_documents';

    protected $fillable = [
        'tenant_id', 'category', 'title',
        'file_path', 'original_filename', 'mime_type', 'size_bytes',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'tenant_id'  => 'integer',
        'uploaded_by'=> 'integer',
    ];

    /** Allowed category keys — keep in sync with the frontend dropdown. */
    public const CATEGORIES = [
        'gewerbe'         => 'Gewerbeschein / Gewerbeanmeldung',
        'handelsregister' => 'Handelsregisterauszug',
        'steuer'          => 'Steuerbescheid / USt-Bescheinigung',
        'versicherung'    => 'Haftpflichtversicherung',
        'ausweis'         => 'Personalausweis Geschäftsführer',
        'sonstiges'       => 'Sonstiges',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
