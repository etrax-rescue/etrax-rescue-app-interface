<?php

namespace App\Models;

use App\Models\Encryption;
use Illuminate\Database\Eloquent\Model;

class Mission extends Model
{
    use Encryption;

    protected $table = 'settings';

    protected $primaryKey = 'EID';

    public $timestamps = false;

    /**
     * Create new attribute based on the "ende" field of the data json.
     */
    public function getActiveAttribute($value)
    {
        $ende = $this->data[0]['ende'];
        return $ende == '';
    }

    /**
     * Create new attribute based on the "typ" field.
     */
    public function getExerciseAttribute($value)
    {
        $type = $this->typ;
        return $type == 'uebung';
    }

    /**
     * Create new attribute which collects all participating organizations into one list.
     */
    public function getParticipatingOrganizationsAttribute($value)
    {
        $dat = collect($this->data[0]);
        $orgs = $dat->transform(function ($item, $key) {
            if ($key == 'OID') {
                return $item;
            } else if (in_array($key, ['Ogleich', 'Ozeichnen', 'Ozuweisen', 'Olesen'])) {
                return explode(',', $item);
            }
        })->whereNotNull()->flatten()->unique()->filter(function ($value, $key) {
            return $value != '';
        });
        return $orgs;
    }

    /**
     * Get the encrypted "data" attribute.
     *
     * @param  string  $value
     * @return mixed
     */
    public function getDataAttribute($value)
    {
        return json_decode($this->string_decrypt($value), true, 512, JSON_OBJECT_AS_ARRAY);
    }

    /**
     * Get the encrypted "personen_im_einsatz" attribute.
     *
     * @param  string  $value
     * @return mixed
     */
    public function getPersonenImEinsatzAttribute($value)
    {
        return json_decode($this->string_decrypt($value), true, 512, JSON_OBJECT_AS_ARRAY);
    }

    /**
     * Set the encrypted "personen_im_einsatz" attribute.
     *
     * @param  string  $value
     * @return void
     */
    public function setPersonenImEinsatzAttribute($value)
    {
        $this->attributes['personen_im_einsatz'] = $this->string_encrypt(json_encode($value));
    }

    /**
     * Get the encrypted "gesucht" attribute.
     *
     * @param  string  $value
     * @return mixed
     */
    public function getGesuchtAttribute($value)
    {
        return json_decode($this->string_decrypt($value), true, 512, JSON_OBJECT_AS_ARRAY);
    }

    /**
     * Get the encrypted "pois" attribute.
     *
     * @param  string  $value
     * @return mixed
     */
    public function getPoisAttribute($value)
    {
        return json_decode($this->string_decrypt($value), true, 512, JSON_OBJECT_AS_ARRAY);
    }

    /**
     * Set the encrypted "pois" attribute.
     *
     * @param  string  $value
     * @return void
     */
    public function setPoisAttribute($value)
    {
        $this->attributes['pois'] = $this->string_encrypt(json_encode($value));
    }

    /**
     * Get the encrypted "suchgebiete" attribute.
     *
     * @param  string  $value
     * @return mixed
     */
    public function getSuchgebieteAttribute($value)
    {
        return json_decode($this->string_decrypt($value), true, 512, JSON_OBJECT_AS_ARRAY);
    }
}
