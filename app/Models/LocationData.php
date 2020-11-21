<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationData extends Model
{
    protected $table = 'tracking';

    protected $primaryKey = 'ID';

    public $timestamps = false;

    protected $attributes = [
        'TAN' => '',
        'gruppe' => '',
        'nummer' => '',
    ];

    protected $fillable = ['EID', 'OID', 'UID', 'lat', 'lon', 'timestamp', 'hdop', 'altitude', 'speed', 'herkunft', 'oidmitglied'];
}