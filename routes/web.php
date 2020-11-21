<?php

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

use App\Models\Organization;
use App\Models\User;
use App\Models\Mission;
use App\Models\LocationData;

/**
 * Landing page
 * 
 * Here we display some information about the eTrax|rescue App and display links to the
 * Play Store and App Store.
 */
$router->get('/', function () {
  return view('index');
});

/**
 * Version
 * 
 * This endpoint is used during the app connection to verify that the app is indeed connecting to 
 * an eTrax|rescue server.
 */
$router->get('/version', function () {
  return response()->json(['magic' => 'eTrax|rescue', 'version' => '5.0.0']);
});

/**
 * Get organizations
 * 
 * Returns a list of the organizations registered on the eTrax|rescue server.
 */
$router->get('/organizations', function () {
  $orgs = collect(Organization::where('aktiv', 1)->get())->map(function ($org) {
    return array(
      "id" => $org->OID,
      "name" => $org->data[0]['bezeichnung'] ?? '',
    );
  });

  return response()->json(
    $orgs,
    Response::HTTP_OK,
    ['Content-Type' => 'application/json;charset=UTF-8', 'Charset' => 'utf-8'],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  );
});

/**
 * Get organization image
 * 
 * Retrieves an image resource by its unique identifier (uid).
 * @param oid: the organization id
 */
$router->get('/orglogo/{oid:[A-Za-z0-9_\-]+}', function ($oid) {
  $rawpath = env('ETRAX_BASE_PATH') . '/orglogos/' . $oid;

  $headers = ['Cache-Control' => 'max-age=' . env('MAX_CACHE_TIME')];
  $path = '';
  $file_endings = ['.png', '.jpg', '.gif', '.bmp'];
  foreach ($file_endings as $key => $suffix) {
    if (file_exists($rawpath . $suffix)) {
      $path = $rawpath . $suffix;
      break;
    }
  }
  if ($path == '') {
    return response('File not found.', Response::HTTP_NOT_FOUND);
  }

  try {
    $response = new BinaryFileResponse($path, Response::HTTP_OK, $headers, false);
  } catch (Exception $e) {
    return response('File not found.', Response::HTTP_NOT_FOUND);
  }
  return $response;
});

/**
 * Generates a 64 character long token, seeded from the secure random_bytes function. The generated
 * token and its issuing date is then stored in the database entry of the corresponding user.
 * 
 * @param user: the user model which should get a new access token.
 */
function generateAndAssignToken(User $user)
{
  $token = base64_encode(random_bytes(48)); // -> 64 characters
  $user->token = hash('sha256', $token);
  $user->token_expiration_date = \Carbon\Carbon::now()->addSeconds(env('TOKEN_MAX_AGE'));
  //$user->aktiveEID = null;
  $user->save();
  return $token;
}

/**
 * Login
 * 
 * This endpoint receives the username and password. Checks if the username exists and matches the
 * password. If all checks pass, an access token is either generated or queried from the DB and
 * returned inside a JSON.
 * 
 * @param organization_id: the ID of the organization of which the user is a member of
 * @param username: the username
 * @param password: the user's password 
 */
$router->group(['middleware' => 'throttle:10,1'], function () use ($router) {
  $router->post('/login', function (Request $request) {
    $oid = $request->input('organization_id');
    $username =  $request->input('username');
    $password =  $request->input('password');

    if ($username == null || $password == null || $oid == null) return response('bad request', Response::HTTP_BAD_REQUEST);

    $userhash = hash('sha256', $oid . '-' . $username);

    $user = User::where('username', $userhash)->first();
    if ($user == null) return  response('unauthorized', Response::HTTP_UNAUTHORIZED);

    $user_passwort = $user->data[0]['pwd'];

    $salt = preg_split('/:/', $user_passwort);
    $user_passwort = md5($password . $salt[1]);
    if ($user_passwort == $salt[0]) {
      $token = generateAndAssignToken($user);
      return response()->json(['token' => $token, 'issuingDate' => strval(\Carbon\Carbon::now()->timestamp), 'expiration_date' => strval(intval(\Carbon\Carbon::now()->addSeconds(env('TOKEN_MAX_AGE'))->getPreciseTimestamp(3)))], Response::HTTP_OK, [], JSON_UNESCAPED_SLASHES);
    } else {
      return response('unauthorized', Response::HTTP_UNAUTHORIZED);
    }
  });
});

$router->group(['middleware' => 'auth'], function () use ($router) {
  /**
   * Logout
   * 
   * The authenticated user is logged out by sending the logout status to the status update interface
   * and subsequently deleting the token data and aktiveEID field from the user entry.
   */
  $router->get('/logout', function () {
    $user = Auth::user();

    $org = Organization::where('OID', $user->OID)->first();
    if ($org == null) return response('No content.', Response::HTTP_NO_CONTENT);
    $token = $org->token;

    if ($user->aktiveEID != null) {
      $response = Http::attach('jsonfile', collect([
        'token' => $token,
        'data' => [
          [
            'uid' => $user->UID,
            'properties' => [
              [
                'status' => '11', // Logout status
              ]
            ]
          ]
        ],
      ])->toJson(), 'jsonfile')->post(env('STATUS_UPDATE_URL'));
    }

    $user->token = null;
    $user->token_expiration_date = null;
    $user->aktiveEID = null;
    $user->save();
    return 'ok';
  });

  /**
   * Get initialization data.
   * 
   * Initialization data consist of the app configuration (location update and info update settings),
   * a list of user states (which the user can selected during the mission), a list of user roles 
   * (what role a user has during a mission) and a list of active missions.
   */
  $router->get('/initialization', function () {
    $user = Auth::user();
    $org = Organization::where('aktiv', 1)->where('OID', $user->OID)->first();

    if ($org == null) return response('No content.', Response::HTTP_NO_CONTENT);

    // App Configuration
    $config = collect($org->appsettings);
    $appConfiguration = array(
      'locationUpdateInterval' => intval($config['readposition']),
      'locationUpdateMinDistance' => intval($config['distance']),
      'infoUpdateInterval' => intval($config['updateinfo']),
    );

    // States
    $states = collect($org->status['app'])->where('use', 1)->transform(function ($item, $key) {
      if ($key <= 11) {
        $item['id'] = $key;
        return $item;
      } else {
        return null;
      }
    })->whereNotNull()->map(function ($item) {
      return [
        'id' => $item['id'],
        'name' => $item['text'],
        'description' => '',
        'locationAccuracy' => intval($item['tracking']),
      ];
    })->whereNotIn('id', [11])->values();

    // Quick Actions
    $actions = collect($org->status['app'])->where('use', 1)->transform(function ($item, $key) {
      if ($key > 11) {
        $item['id'] = $key;
        return $item;
      } else {
        return null;
      }
    })->whereNotNull()->map(function ($item) {
      return [
        'id' => $item['id'],
        'name' => $item['text'],
        'description' => '',
        'locationAccuracy' => intval($item['tracking']),
      ];
    })->values();

    // Roles
    $roles = collect($org->funktionen)->where('app', 1)->transform(function ($item, $key) {
      $item['id'] = $key;
      return $item;
    })->map(function ($item) {
      return array(
        'id' => $item['id'],
        'name' => $item['lang'],
        'description' => '',
      );
    })->values();

    // Missions
    $missions = collect(Mission::select(array('EID', 'data', 'typ'))->get())->sortByDesc('EID')->map(function ($item) use ($user) {
      if (in_array($user->OID, $item->participating_organizations->toArray()) && $item->active) {
        return array(
          'id' => $item->EID,
          'name' => $item->data[0]['einsatz'],
          'start' =>  \Carbon\Carbon::parse($item->data[0]['anfang']),
          'latitude' => floatval($item->data[0]['elat']),
          'longitude' => floatval($item->data[0]['elon']),
          'exercise' => $item->exercise,
        );
      }
    })->whereNotNull()->values();

    return response()->json(
      array(
        'appConfiguration' => $appConfiguration,
        'states' => $states,
        'roles' => $roles,
        'actions' => $actions,
        'missions' => $missions,
      ),
      Response::HTTP_OK,
      ['Content-Type' => 'application/json;charset=UTF-8', 'Charset' => 'utf-8'],
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
  });

  /**
   * Utility function to handle reading and writing from/to "personen_im_einsatz" field of a mission.
   */
  function insertOrUpdateActiveUser($mission, $uid, $data)
  {
    $data_defaults = [
      'UID' => '',
      'OID' => '',
      'dienstnummer' => '',
      'orgname' => '',
      'name' => '',
      'phone' => '',
      'email' => '',
      'bos' => '',
      'typ' => '',
      'pause' => '',
      'sender' => '',
      'ausbildungen' => '',
      'gruppe' => '',
      'status' => 0,
      'aktivierungszeit' => '',
      'eingerueckt' => '',
      'inPause' => '',
      'ausPause' => '',
      'abgemeldet' => '',
    ];

    // If the 'personen_im_einsatz' field does not exist, initialize it with an empty array.
    if ($mission['personen_im_einsatz'] == null) {
      $mission['personen_im_einsatz'] = [];
    }
    // Check if the person is already present in the personen_im_einsatz json array
    $index = -1;
    foreach ($mission['personen_im_einsatz'] as $n => $person) {
      if (array_key_exists('id', $person)) {
        if ($person['id'] == $uid) {
          $index = $n;
          break;
        }
      }
    }

    if ($index >= 0) {
      // The user is already present in the json array
      // Check if there are some fields missing
      $missing_fields = array_diff_key($mission['personen_im_einsatz'][$index]['data'][0], $data_defaults);
      $person = $mission['personen_im_einsatz'][$index];
      $active_persons = $mission['personen_im_einsatz'];
      foreach ($missing_fields as $key => $value) {
        $person['data'][0][$key] = $value;
      }
      $active_persons = $mission['personen_im_einsatz'];
      $active_persons[$index] = $person;
    } else {
      // The user is missing from the json array, add the required fields and initialize them with default values
      $active_persons = $mission['personen_im_einsatz'];
      array_push($active_persons, ['id' => $uid, 'data' => [$data_defaults]]);
      $index = count($mission->personen_im_einsatz);
    }

    // Update the fields based on the supplied data array
    foreach ($active_persons[$index]['data'][0] as $key => $value) {
      if (array_key_exists($key, $data)) {
        $active_persons[$index]['data'][0][$key] = $data[$key];
      }
    }

    $mission->personen_im_einsatz = $active_persons;
    $mission->save();

    return collect($mission);
  }

  /**
   * Set the selected mission
   * 
   * Here we report that a user participated in a given mission.
   * @param mission_id: the ID of the selected mission
   */
  $router->post('/missionselect', function (Request $request) {
    $user = Auth::user();
    $eid = $request->input('mission_id');

    if ($eid == null) return response('bad request', Response::HTTP_BAD_REQUEST);

    // Check if the mission existst and is active
    $mission = Mission::where('EID', $eid)->first();
    if ($mission == null) return response('No content.', Response::HTTP_NO_CONTENT);
    if (!($mission->active)) return response('No content.', Response::HTTP_NO_CONTENT);

    $org = Organization::where('OID', $user->OID)->first();
    if ($org == null) return response('No content.', Response::HTTP_NO_CONTENT);

    $user->aktiveEID = $eid;
    $user->save();

    $data = [
      'UID' => $user->UID,
      'OID' => $user->OID,
      'dienstnummer' => $user->data[0]['dienstnummer'] ?? '',
      'name' => $user->data[0]['name'],
      'phone' => $user->data[0]['telefon'] ?? '',
      'email' => $user->data[0]['email'] ?? '',
      'bos' => $user->data[0]['bos'] ?? '',
      'orgname' => $org->data[0]['kurzname'],
      'ausbildungen' => $user->data[0]['ausbildungen'] ?? '',
      'aktivierungszeit' => strval(\Carbon\Carbon::now()->timestamp),
      'sender' => 'active',
    ];
    insertOrUpdateActiveUser($mission, $user->UID, $data);

    return response('ok', Response::HTTP_OK);
  });

  /**
   * Set the user role
   * 
   * Here the role the user has during a mission is updated.
   * @param role_id: The ID of the selected role
   */
  $router->post('/roleselect', function (Request $request) {
    $user = Auth::user();
    $rid = $request->input('role_id');
    if ($rid == null) return response('bad request', Response::HTTP_BAD_REQUEST);

    $org = Organization::where('OID', $user->OID)->first();
    if ($org == null) return response('No content.', Response::HTTP_NO_CONTENT);

    $roles = collect($org->funktionen)->where('app', 1)->transform(function ($item, $key) {
      $item['id'] = $key;
      return $item;
    })->map(function ($item) {
      return array(
        'id' => $item['id'],
        'name' => $item['kurz'],
      );
    })->values();

    $role = $roles->where('id', $rid)->values()->first();
    if ($role == null) return response('No content.', Response::HTTP_NO_CONTENT);

    $mission = Mission::where('EID', $user->aktiveEID)->first();
    if ($mission == null) return response('No content.', Response::HTTP_NO_CONTENT);
    if (!($mission->active)) return response('No content.', Response::HTTP_NO_CONTENT);

    insertOrUpdateActiveUser($mission, $user->UID, ['typ' => $role['name']]);

    return response('ok', Response::HTTP_OK);
  });

  /**
   * Set the user state
   * 
   * Here we update the user state
   * @param state_id: the ID of the selected state
   */
  $router->post('/stateselect', function (Request $request) {
    $user = Auth::user();
    $sid = $request->input('state_id');
    if ($sid == null) return response('bad request', Response::HTTP_BAD_REQUEST);

    // Retrieve the organization token
    $org = Organization::where('OID', $user->OID)->first();
    if ($org == null) return response('No content.', Response::HTTP_NO_CONTENT);
    $token = $org->token;

    // Retrieve the available states
    $states = collect($org->status['app'])->where('use', 1)->transform(function ($item, $key) {
      $item['id'] = $key;
      return $item;
    })->map(function ($item) {
      return [
        'id' => $item['id'],
        'name' => $item['text'],
      ];
    })->values();

    // Check if the selected state exists
    $state = $states->where('id', $sid)->values()->first();
    if ($state == null) return response('No content.', Response::HTTP_NO_CONTENT);

    // Retrieve the mission 
    $mission = Mission::where('EID', $user->aktiveEID)->first();
    if ($mission == null) return response('No content.', Response::HTTP_NO_CONTENT);
    if (!($mission->active)) return response('No content.', Response::HTTP_NO_CONTENT);

    // Send multipart post data to status update endpoint
    $response = Http::attach('jsonfile', collect([
      'token' => $token,
      'data' => [
        [
          'uid' => $user->UID,
          'properties' => [
            [
              'status' => strval($sid),
            ]
          ]
        ]
      ],
    ])->toJson(), 'jsonfile')->post(env('STATUS_UPDATE_URL'));
    //return $response;


    return response('ok', Response::HTTP_OK);
  });

  /**
   * Trigger a QuickAction
   * 
   * Here a quickaction is triggered
   * @param action_id: the ID of the selected action
   * @param location: the location of the user sending the quick action request
   */
  $router->post('/quickaction', function (Request $request) {
    $user = Auth::user();
    $id = $request->input('action_id');
    $location = $request->input('location');
    if ($id == null) return response('bad request', Response::HTTP_BAD_REQUEST);

    // Retrieve the organization token
    $org = Organization::where('OID', $user->OID)->first();
    if ($org == null) return response('No content.', Response::HTTP_NO_CONTENT);
    $token = $org->token;

    // Retrieve the available actions
    $actions = collect($org->status['app'])->where('use', 1)->transform(function ($item, $key) {
      if ($key > 11) {
        $item['id'] = $key;
        return $item;
      } else {
        return null;
      }
    })->whereNotNull()->map(function ($item) {
      return [
        'id' => $item['id'],
        'name' => $item['text'],
      ];
    })->values();

    // Check if the selected state exists
    $state = $actions->where('id', $id)->values()->first();
    if ($state == null) return response('No content.', Response::HTTP_NO_CONTENT);

    // Retrieve the mission 
    $mission = Mission::where('EID', $user->aktiveEID)->first();
    if ($mission == null) return response('No content.', Response::HTTP_NO_CONTENT);
    if (!($mission->active)) return response('No content.', Response::HTTP_NO_CONTENT);

    // Send multipart post data to status update endpoint
    $response = Http::attach('jsonfile', collect([
      'token' => $token,
      'data' => [
        [
          'uid' => $user->UID,
          'properties' => [
            [
              'status' => strval($id),
              'lat' => $location['latitude'] ?? -1,
              'lon' => $location['longitude'] ?? -1,
              'altitude' => $location['altitude'] ?? 0,
              'hdop' => $location['accuracy'] ?? -1,
              'speed' => $location['speed'] ?? -1,
              'timestamp' => strval(intval($location['time'])),
            ]
          ]
        ]
      ],
    ])->toJson(), 'jsonfile')->post(env('STATUS_UPDATE_URL'));

    return response('ok', Response::HTTP_OK);
  });

  /**
   * Get mission details
   * 
   * Returns additional data about the mission. Currently two types, "text" and "image" are supported.
   * When the "image" type is selected, the unique identifier of this image is returned instead of the
   * image itself, so it can be downloaded seperately.
   */
  $router->get('/missiondetails',   function () {
    $user = Auth::user();
    $eid = $user->aktiveEID;
    if ($eid == null) return response('No content.', Response::HTTP_NO_CONTENT);

    $mission = Mission::where('EID', $eid)->first();
    if ($mission == null) return response('No content.', Response::HTTP_NO_CONTENT);

    $info = collect(collect($mission->gesucht)[0]);

    $labels = array(
      'gesuchtbeschreibung' => 'Beschreibung',
      'gesuchtname' => 'Name',
      'gesuchtalter' => 'Alter',
    );

    $answer = array();

    $path = env('SECURE_PATH') . '/data/' . $eid  . '/gesucht_big.jpg';

    if (file_exists($path)) {
      array_push($answer, array(
        'type' => 'image',
        'title' => 'Bild',
        'uid' => 'gesucht_big',
      ));
    }

    // Only use the info which has the same keys as $labels
    $intersect = array_intersect_key($info->toArray(), $labels);

    // If 'gesuchtalter' is not available, try to calculate age from
    // birthdate field.
    $age = null;
    if ($intersect['gesuchtalter'] == null) {
      if ($info->has('gesuchtgebdatum')) {
        try {
          $birthdate = \Carbon\Carbon::parse($info['gesuchtgebdatum']);
          $age = $birthdate->diffInYears(\Carbon\Carbon::now());
          $intersect['gesuchtalter'] = $age;
        } catch (Exception $e) {
          Log::info('parsing failed');
        }
      }
    }

    foreach ($intersect as $key => $value) {
      if ($value == null || $value == "" || $value == " ") continue;
      array_push($answer, array(
        'type' => 'text',
        'title' => $labels[$key],
        'body' => $value,
      ));
    }

    return response()->json(
      $answer,
      Response::HTTP_OK,
      ['Content-Type' => 'application/json;charset=UTF-8', 'Charset' => 'utf-8'],
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
  });

  /**
   * Get image resource
   * 
   * Retrieves an image resource by its unique identifier (uid).
   * @param eid: the mission id to which this image belongs to
   * @param uid: the unique identifier of the image resource
   */
  $router->get('/image/{eid}/{uid:[A-Za-z0-9_\-]+}', function ($eid, $uid) {
    $user = Auth::user();
    $eid = $user->aktiveEID;
    if ($eid == null) return response('No content.', Response::HTTP_NO_CONTENT);

    $type = 'image/jpeg';
    $headers = ['Content-Type' => $type, 'Cache-Control' => 'max-age=' . env('MAX_CACHE_TIME')];
    $path = env('SECURE_PATH') . '/data/' . $eid  . '/' . $uid . '.jpg';

    if (!file_exists($path)) {
      return response('File not found.', Response::HTTP_NOT_FOUND);
    }
    $response = new BinaryFileResponse($path, Response::HTTP_OK, $headers, false);
    return $response;
  });

  /**
   * Location update
   * 
   * Writes the location from the eTrax App into the tracking table. With this data we can later
   * generate the tracks of the user in the eTrax web interface.
   * @param: the POST body must contain a JSON array with location data.
   */
  $router->post('/locationupdate', function (Request $request) {
    $locations = collect($request->input());

    $locations->each(function ($location, $key) {
      $user = Auth::user();
      $location = collect($location);
      LocationData::create([
        'EID' => $user->aktiveEID,
        'OID' => $user->OID,
        'UID' => $user->UID,
        'lat' => $location['latitude'],
        'lon' => $location['longitude'],
        'timestamp' => strval(intval($location['time'])),
        'hdop' => $location['accuracy'],
        'altitude' => $location['altitude'],
        'speed' => $location['speed'],
        'herkunft' => 'APP',
        'oidmitglied' => $user->OID,
      ]);
    });
    return response('created', Response::HTTP_CREATED);
  });

  /**
   * Mission activity check
   * 
   * Check whether the mission assigned to the user is still active.
   */
  $router->get('/missionactive', function () {
    $user = Auth::user();
    $eid = $user->aktiveEID;
    if ($eid == null) return false;
    $mission = Mission::where('EID', $eid)->first();
    if ($mission == null) return false;
    return $mission->active;
  });

  /**
   * Create a POI entry for the database
   */
  function buildPOI($user, $description, $latitude, $longitude, $timestamp)
  {
    return [
      'type' => 'Feature',
      'properties' => [
        'uid' => $user->UID,
        'oid' => $user->OID,
        'name' => 'POI',
        'color' => '#c00',
        'beschreibung' => $description,
        'img' => 'poi_' . $user->UID . '_' . $timestamp,
        'poi' => $timestamp,
      ],
      'geometry' => [
        'type' => 'Point',
        'coordinates' => [
          $longitude,
          $latitude,
        ]
      ]
    ];
  }

  /**
   * Upload a POI
   * 
   * Upload a point of interest (POI). The POI consists of its corresponding location, a short
   * description and an image. The image 
   * poi_<UID>_<timestamp ms>_big.jpg: 800x550
   * poi_<UID>_<timestamp ms>.jpg: 300x206
   * 
   * @param location_data: json containing the location information
   * @param description: text describing the POI
   * @param image: the image file
   */
  $router->post('/uploadpoi', function (Request $request) {
    $user = Auth::user();
    $eid = $user->aktiveEID;
    if ($eid == null) return response('No content.', Response::HTTP_NO_CONTENT);

    $mission = Mission::where('EID', $eid)->first();
    if ($mission == null) return response('No content.', Response::HTTP_NO_CONTENT);

    $location = $request->input('location_data');
    $location = collect(json_decode($location));
    $description = $request->input('description');
    $image = $request->file('image');
    if ($location['latitude'] == null || $location['longitude'] == null || $description == null || $image == null) return response('bad request', Response::HTTP_BAD_REQUEST);

    // Retrieve the image size
    list($width, $height, $type, $attr) = getimagesize($image->getRealPath());
    $img = imagecreatefromjpeg($image->getRealPath());

    // Scle the image, but preserve its aspect ratio
    if ($width > $height) {
      // Landscape image
      $big_scale = 800 / $width;
      $small_scale = 300 / $width;
      $big_img = imagescale($img, 800, intval($height * $big_scale));
      $small_img = imagescale($img, 300, intval($height * $small_scale));
    } else {
      // Portrait image
      $big_scale = 800 / $height;
      $small_scale = 300 / $height;
      $big_img = imagescale($img, intval($width * $big_scale), 800);
      $small_img = imagescale($img, intval($width * $small_scale), 300);
    }

    // Generate image filenames
    $now_ms = strval(intval(\Carbon\Carbon::now()->getPreciseTimestamp(3)));
    $filename_big = env('SECURE_PATH') . '/data/' . $eid  . '/poi_' . $user->UID . '_' . $now_ms . '_big.jpg';
    $filename_small = env('SECURE_PATH') . '/data/' . $eid  . '/poi_' . $user->UID . '_' . $now_ms . '.jpg';

    // Save to the filesystem
    imagejpeg($big_img, $filename_big);
    imagejpeg($small_img, $filename_small);

    // Free up memory
    imagedestroy($big_img);
    imagedestroy($small_img);

    // Generate the POI entry for the database
    $new_poi = buildPOI($user, $description, $location['latitude'], $location['longitude'], $now_ms);

    // Insert the POI entry into the database
    $pois = $mission->pois;
    array_push($pois['features'], $new_poi);

    $mission->pois = $pois;
    $mission->save();

    return response('created', Response::HTTP_CREATED);
  });

  /**
   * Get a list of search areas
   * 
   * Returns a json array of search areas of the active mission.
   */
  $router->get('/searchareas', function (Request $request) {
    $user = Auth::user();
    $eid = $user->aktiveEID;
    if ($eid == null) return response('No content.', Response::HTTP_NO_CONTENT);

    $mission = Mission::where('EID', $eid)->first();
    if ($mission == null) return response('No content.', Response::HTTP_NO_CONTENT);

    $features = collect($mission->suchgebiete['features']);
    $areas = $features->map(function ($item) {
      if (in_array($item['properties']['typ'], ['Wegsuche', 'Punktsuche', 'Suchgebiet'])) {
        if ($item['geometry']['type'] == 'Polygon') {
          return [
            'id' => $item['properties']['id'],
            'label' => $item['properties']['name'] ?? '',
            'description' => $item['properties']['beschreibung'] ?? '',
            'color' => $item['properties']['color'] ?? '',
            'coordinates' => $item['geometry']['coordinates'][0]
          ];
        } else if ($item['geometry']['type'] == 'Point') {
          return [
            'id' => $item['properties']['id'],
            'label' => $item['properties']['name'] ?? '',
            'description' => $item['properties']['beschreibung'] ?? '',
            'color' => $item['properties']['color'] ?? '',
            'coordinates' => [$item['geometry']['coordinates']]
          ];
        }
      }
    })->whereNotNull()->values();

    return $areas;
  });
});
