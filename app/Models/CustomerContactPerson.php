<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerContactPerson extends Model
{
    // Eloquent's pluralizer guesses "customer_contact_people" (person ->
    // people) from the class name — the real table is
    // "customer_contact_persons", so the convention-based guess has to be
    // overridden explicitly.
    protected $table = 'customer_contact_persons';

    protected $fillable = [
        'zoho_contact_person_id',
        'zoho_customer_id',
        'first_name',
        'last_name',
        'email',
        'zoho_created_time',
        'zoho_last_modified_time',
    ];

    protected function casts(): array
    {
        return [
            'zoho_created_time' => 'datetime',
            'zoho_last_modified_time' => 'datetime',
        ];
    }
}
