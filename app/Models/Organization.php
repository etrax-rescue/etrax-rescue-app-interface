<?php

namespace App\Models;

use App\Models\Encryption;
use Illuminate\Database\Eloquent\Model;
use MichaelAChrisco\ReadOnly\ReadOnlyTrait;

class Organization extends Model
{
    use Encryption;
    use ReadOnlyTrait;

    protected $table = 'organisation';

    protected $primaryKey = 'ID';

    public $timestamps = false;

    /**
     * Get the encrypted attribute.
     *
     * @param  string  $value
     * @return mixed
     */
    public function getDataAttribute($value)
    {
        return json_decode($this->string_decrypt($value), true, 512, JSON_OBJECT_AS_ARRAY);
    }

    /**
     * Get the encrypted attribute.
     *
     * @param  string  $value
     * @return mixed
     */
    public function getStatusAttribute($value)
    {
        return json_decode($value, true, 512, JSON_OBJECT_AS_ARRAY);
    }

    /**
     * Get the encrypted attribute.
     *
     * @param  string  $value
     * @return mixed
     */
    public function getFunktionenAttribute($value)
    {
        return json_decode($value, true, 512, JSON_OBJECT_AS_ARRAY);
    }

    /**
     * Get the encrypted attribute.
     *
     * @param  string  $value
     * @return mixed
     */
    public function getAppsettingsAttribute($value)
    {
        return json_decode($value, true, 512, JSON_OBJECT_AS_ARRAY);
    }
}
