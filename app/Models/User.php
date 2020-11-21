<?php

namespace App\Models;

use App\Models\Encryption;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use Encryption;

    protected $table = 'user';

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
     * Set the encrypted attribute.
     *
     * @param  string  $value
     * @return void
     */
    public function setDataAttribute($value)
    {
        $this->attributes['data'] = $this->string_encrypt(json_encode($value));
    }
}