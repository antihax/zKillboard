<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

if ($redis->get("zkb:reinforced") == true) exit();

$esi = new RedisTimeQueue('tqApiESI', 3600);
if (date('i') == 22 || $esi->size() < 100) {
    $esis = $mdb->find("scopes", ['scope' => 'esi-killmails.read_killmails.v1']);
    foreach ($esis as $row) {
        $charID = $row['characterID'];
        $esi->add($charID);
    }
}
if ($esi->size() == 0) exit();

$guzzler = new Guzzler($esiCharKillmails, 500);

$minute = date('Hi');
while ($minute == date('Hi')) {
    Status::checkStatus($guzzler, 'esi');
    Status::checkStatus($guzzler, 'sso');
    $charID = $esi->next(false);
    if ($charID) {
        $row = $mdb->findDoc("scopes", ['characterID' => (int) $charID, 'scope' => "esi-killmails.read_killmails.v1"], ['lastFetch' => 1]);
        if ($row != null) {
            $params = ['row' => $row, 'esi' => $esi];
            $refreshToken = $row['refreshToken'];
            $content = $redis->get("auth:$charID:$refreshToken");
            if ($content !== false) {
                accessTokenDone($guzzler, $params, $content, false);
                continue;
            }

            CrestSSO::getAccessTokenCallback($guzzler, $refreshToken, "accessTokenDone", "accessTokenFail", $params);
        } else {
            $esi->remove($charID);
        }
    } else {
        $guzzler->tick();
        usleep(100000);
    }
}
$guzzler->finish();


function accessTokenDone(&$guzzler, &$params, $content, $cacheIt = true)
{
    global $ccpClientID, $ccpSecret, $redis, $esiServer;

    $row = $params['row'];
    $charID = $row['characterID'];
    $refreshToken = $row['refreshToken'];
    if ($cacheIt == true) {
        $redis->setex("auth:$charID:$refreshToken", 1100, $content);
    }

    $response = json_decode($content, true);
    $accessToken = $response['access_token'];
    $params['content'] = $content;

    $headers = [];
    $headers['Content-Type'] ='application/json';
    $headers['Authorization'] = "Bearer $accessToken";
    $headers['etag'] = true;

    $url = "$esiServer/v1/characters/$charID/killmails/recent/";
    $guzzler->call($url, "success", "fail", $params, $headers, 'GET');
}

function success($guzzler, $params, $content) 
{
    global $mdb, $redis;

    $row = $params['row'];
    $esi = $params['esi'];

    $newKills = 0;
    $kills = $content == "" ? [] : json_decode($content, true);
    foreach ($kills as $kill) {
        $killID = $kill['killmail_id'];
        $hash = $kill['killmail_hash'];

        $newKills += addMail($killID, $hash);
    }

    $charID = (int) $row['characterID'];
    $corpID = (int) Info::getInfoField("characterID", $charID, 'corporationID');
    $successes = (int) @$row['successes'];
    $successes++;

    $modifiers = ['corporationID' => $corpID, 'lastFetch' => $mdb->now(), 'errorCount' => 0, 'successes' => $successes];
    if (!isset($row['added']->sec)) $modifiers['added'] = $mdb->now();
    if ($content != "" && sizeof($kills) > 0) $modifiers['last_has_data'] = $mdb->now();
    $mdb->set("scopes", $row, $modifiers); 
    $redis->setex("apiVerified:$charID", 86400, time());

    // Check active chars once an hour, check inactive chars less often
    $mKillID = (int) $mdb->findField("killmails", "killID", ['involved.characterID' => $charID], ['killID' => -1]);
    if ($newKills == 0 && $mKillID < ($redis->get("zkb:topKillID") - 5000000)) {
        $esi->setTime($charID, time() + (rand(18, 23) * 3600));

        // Remove scope if they've never had a kill and it has been 30 days since they've added the scope
        if ($mKillID == 0 && isset($row['added']->sec) && $row['added']->sec < (time() - (30 * 86400))) {
            $esi->remove($charID);
            $mdb->remove("scopes", $row);
            $redis->del("apiVerified:$charID");
            Util::out("Removed char killmail scope for $charID after 1 month for never having had a kill or loss.");
        }

        // Remove scope if they haven't been involved on a killmail in 6 months
        if (isset($row['added']->sec) && $row['added']->sec < (time() - (6 * 30 * 86400))) {
            $esi->remove($charID);
            $mdb->remove("scopes", $row);
            $redis->del("apiVerified:$charID");
            Util::out("Removed char killmail scope for $charID after 6 months of not being involved on a killmail.");
        }
        return;
    }
    // Check recently active characters every 5 minutes
    if ($redis->get("recentKillmailActivity:$charID") == "true") {
        $esi->setTime($charID, time() + 310);
    }

    if ($newKills > 0) {
        $name = Info::getInfoField('characterID', $charID, 'name');
        if ($name === null) $name = $charID;
        while (strlen("$newKills") < 3) $newKills = " " . $newKills;
        ZLog::add("$newKills kills added by char $name", $charID);
        if ($newKills >= 10) User::sendMessage("$newKills kills added for char $name", $charID);
    }
}

function addMail($killID, $hash) 
{
    global $mdb;

    $exists = $mdb->exists('crestmails', ['killID' => $killID, 'hash' => $hash]);
    if (!$exists) {
        try {
            $mdb->getCollection('crestmails')->save(['killID' => (int) $killID, 'hash' => $hash, 'processed' => false, 'source' => 'esi', 'added' => $mdb->now()]);
            return 1;
        } catch (MongoDuplicateKeyException $ex) {
            // ignore it *sigh*
        }
    }
    return 0;
}

function fail($guzzer, $params, $ex) 
{
    global $mdb;

    $row = $params['row'];
    $esi = $params['esi'];
    $charID = $row['characterID'];
    $code = $ex->getCode();

    $json = json_decode($params['content'], true);
    $code = isset($json['sso_status']) ? $json['sso_status'] : $code;

    switch ($code) {
        case 400: // Server timed out during SSO authentication
        case 420:
        case 500:
        case 502: // Server error, try again in 5 minutes
        case 503:
        case 504: // gateway timeout
        case "": // typically a curl timeout error
            $esi->setTime($charID, time() + 30);
            break;
        case 403: // Server decided to throw a 403 during SSO authentication when that throws a 502...
        default:
            Util::out("killmail char $charID: " . $ex->getMessage() . "\nkillmail content: " . $params['content']);
    }
    sleep(1);
}

function accessTokenFail(&$guzzler, &$params, $ex)
{
    global $mdb;

    $row = $params['row'];
    $esi = $params['esi'];
    $charID = $row['characterID'];
    $code = $ex->getCode();

    $json = json_decode($params['content'], true);
    if (@$json['error'] == 'invalid_grant' || @$json['error'] == 'invalid_token') {
        $mdb->remove("scopes", ['characterID' => $charID]);
        $esi->remove($charID);
        return;
    }

    switch ($code) {
        case 403: // A 403 without an invalid_grant isn't valid
        case 500:
        case 502: // Server error, try again in 5 minutes
        case "": // typically a curl timeout error
            $esi->setTime($charID, time() + 30);
            break;
        default:
            Util::out("char token: $charID " . $ex->getMessage() . "\n\n" . $params['content']);
    }
    sleep(1);
}
