<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FaxAllowedEmails extends Model
{
    use HasFactory, \App\Models\Traits\TraitUuid;

    protected $table = "fax_allowed_emails";

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';


    // Allow mass assignment on 'email' and 'fax_uuid' if needed
    protected $fillable = [
        'fax_uuid',
        'email',
    ];
}
