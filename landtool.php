<?php
// Check for the configuration and definition files
if (!defined('ENV_READ_CONFIG')) require_once(realpath(dirname(__FILE__) . '/include/config.php'));

// Create XMLRPC server
$xmlrpc_server = xmlrpc_server_create();

// Define the functions here...

function db_query($query, $params = array())
{
    $db = opensim_new_db();
    if (!$db) {
        return false;
    }

    $stmt = mysqli_prepare($db, $query);
    if (!$stmt) {
        return false;
    }

    if (!empty($params)) {
        $types = str_repeat('s', count($params)); 
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    $result = mysqli_stmt_execute($stmt);
    if (!$result) {
        mysqli_stmt_close($stmt);
        return false;
    }

    $result = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);

    if ($result) {
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }

    return true;
}

function env_get_config($key, $default = null)
{
    $config = array(
        'currency_script_key' => 'SECRET_KEY',
        // Add other config keys here
    );

    return isset($config[$key]) ? $config[$key] : $default;
}

// Land buying functions
xmlrpc_server_register_method($xmlrpc_server, "preflightBuyLandPrep", "buy_land_prep");

function opensim_new_db($timeout = 60)
{
    // Create a new mysqli connection
    $link = mysqli_connect('DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME');

    // Check connection
    if (mysqli_connect_errno()) {
        die('Connect Error (' . mysqli_connect_errno() . ') ' . mysqli_connect_error());
    }

    // Set connection timeout (not directly possible with mysqli, handle in your application logic if needed)

    return $link;
}

function opensim_check_secure_session($uuid, $regionid, $secure, &$db = null)
{
    if (!isGUID($uuid) || !isGUID($secure)) return false;

    if (!is_object($db)) $db = opensim_new_db();

    if ($db) {
        if (table_exists($db, 'Presence')) {    
            $sql = "SELECT UserID FROM Presence WHERE UserID=? AND SecureSessionID=?";
            if (isGUID($regionid)) {
                $sql .= " AND RegionID=?";
            }

            $stmt = mysqli_prepare($db, $sql);
            if ($stmt) {
                if (isGUID($regionid)) {
                    mysqli_stmt_bind_param($stmt, 'sss', $uuid, $secure, $regionid);
                } else {
                    mysqli_stmt_bind_param($stmt, 'ss', $uuid, $secure);
                }

                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);

                if ($result && mysqli_num_rows($result) > 0) {
                    $row = mysqli_fetch_assoc($result);
                    mysqli_stmt_close($stmt);
                    return $row['UserID'] === $uuid;
                }
                mysqli_stmt_close($stmt);
            }
        } else {
            $sql = "SELECT UserID FROM GridUser WHERE UserID=? AND Online='True'";
            if (isGUID($regionid)) {
                $sql .= " AND LastRegionID=?";
            }

            $stmt = mysqli_prepare($db, $sql);
            if ($stmt) {
                if (isGUID($regionid)) {
                    mysqli_stmt_bind_param($stmt, 'ss', $uuid, $regionid);
                } else {
                    mysqli_stmt_bind_param($stmt, 's', $uuid);
                }

                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);

                if ($result && mysqli_num_rows($result) > 0) {
                    $row = mysqli_fetch_assoc($result);
                    mysqli_stmt_close($stmt);
                    return $row['UserID'] === $uuid;
                }
                mysqli_stmt_close($stmt);
            }
        }
    }

    return false;
}

function table_exists($db, $table_name)
{
    $result = mysqli_query($db, "SHOW TABLES LIKE '{$table_name}'");
    return mysqli_num_rows($result) > 0;
}

function convert_to_real($amount)
{
    return $amount; // Assuming no conversion is needed, adjust as necessary
}

function isGUID($uuid, $nullok = false)
{
    if ($uuid == null) return $nullok;
    if (!preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $uuid)) return false;
    return true;
}

function make_url($serverURI, $portnum = 0)
{
    $url  = '';
    $host = 'localhost';
    $port = 80;
    $protocol = 'http';

    if ($serverURI != null) {
        $uri = preg_split("/[:\/]/", $serverURI);

        // with http:// or https://
        if (array_key_exists(3, $uri)) {
            $protocol = $uri[0];
            $host = $uri[3];
            if (array_key_exists(4, $uri)) {
                $port = $uri[4];
            } else {
                if ($portnum != 0) {
                    $port = $portnum;
                } else {
                    if ($uri[0] == 'http')  $port = 80;
                    else if ($uri[0] == 'https') $port = 443;
                    else if ($uri[0] == 'ftp')   $port = 21;
                }
            }
        } else {
            // with no http:// and https:// 
            $host = $uri[0];
            if (array_key_exists(1, $uri)) {
                $port = $uri[1];
            } else {
                if ($portnum != 0) { 
                    $port = $portnum;
                } else {
                    $port = 80;
                }
            }
        }

        if ($port == 443) {
            $url = 'https://' . $host . ':' . $port . '/';
            $protocol = 'https';
        } else if ($port == 80) {
            $url = 'http://' . $host . '/';
            $protocol = 'http';
        } else if ($port == 21) {
            $url = 'ftp://' . $host . '/';
            $protocol = 'ftp';
        } else {
            $url = $protocol . '://' . $host . ':' . $port . '/';
        }
    }

    $server['url']  = $url;
    $server['host'] = $host;
    $server['port'] = $port;
    $server['protocol'] = $protocol;

    return $server;
}

function process_transaction($agentID, $cost, $ipAddress)
{
    $query = "INSERT INTO transactions (agentid, cost, ip_address) VALUES (?, ?, ?)";
    return db_query($query, [$agentID, $cost, $ipAddress]);
}

function add_money($agentID, $amount, $secureID = null)
{
    if (!USE_CURRENCY_SERVER) return false;
    if (!isGUID($agentID)) return false;
    if ($secureID !== null && !isGUID($secureID, true)) return false;

    $results = opensim_get_userinfo($agentID);
    $server = make_url($results['simip'], 9000);
    if ($server['host'] === '') return false;

    $results = opensim_get_avatar_session($agentID);
    $sessionID = $results['sessionID'];
    if ($secureID === null) $secureID = $results['secureID'];

    $req = array('clientUUID' => $agentID, 'clientSessionID' => $sessionID, 'clientSecureSessionID' => $secureID, 'amount' => $amount);
    $params = array($req);
    $request = xmlrpc_encode_request('AddBankerMoney', $params);
    $response = do_call($server['url'], $server['port'], $request);

    return $response !== null && isset($response['success']) ? $response['success'] : false;
}

function get_balance($agentID, $secureID = null)
{
    $cash = -1;
    if (!USE_CURRENCY_SERVER) return (int)$cash;
    if (!isGUID($agentID)) return (int)$cash;
    if ($secureID !== null && !isGUID($secureID, true)) return (int)$cash;

    $results = opensim_get_userinfo($agentID);
    $server = make_url($results['simip'], 9000);
    if ($server['host'] === '') return (int)$cash;

    $results = opensim_get_avatar_session($agentID);
    if (!$results) return (int)$cash;
    $sessionID = $results['sessionID'];
    if ($secureID === null) $secureID = $results['secureID'];

    $req = array('clientUUID' => $agentID, 'clientSessionID' => $sessionID, 'clientSecureSessionID' => $secureID);
    $params = array($req);
    $request = xmlrpc_encode_request('GetBalance', $params);
    $response = do_call($server['url'], $server['port'], $request);

    return $response !== null && isset($response['balance']) ? (int)$response['balance'] : (int)$cash;
}

function move_money($fromID, $toID, $amount, $type = 5003, $serverURI = null, $secretCode = null)
{
    if (!USE_CURRENCY_SERVER) return false;
    if (!isGUID($fromID)) return false;
    if (!isGUID($toID)) return false;

    $server = array('url' => null);
    if ($serverURI !== null) $server = make_url($serverURI, 9000);

    if ($server['url'] === null) {
        $results = opensim_get_userinfo($fromID);
        $server = make_url($results['simip'], 9000);
    }
    if ($server['url'] === null) return false;

    if ($secretCode !== null) {
        $secretCode = md5($secretCode . '_' . $server['host']);
    } else {
        $secretCode = get_confirm_value($server['host']);
    }

    $req = array('fromUUID' => $fromID, 'toUUID' => $toID, 'secretAccessCode' => $secretCode, 'amount' => $amount);
    $params = array($req);
    $request = xmlrpc_encode_request('MoveMoney', $params);
    $response = do_call($server['url'], $server['port'], $request);

    return $response !== null && isset($response['success']) ? $response['success'] : false;
}

function update_simulator_balance($agentID, $amount = -1, $secureID = null)
{
    if (!USE_CURRENCY_SERVER) return false;
    if (!isGUID($agentID)) return false;
    if ($secureID !== null && !isGUID($secureID, true)) return false;

    if ($amount < 0) {
        $amount = get_balance($agentID, $secureID);
        if ($amount < 0) return false;
    }

    $results = opensim_get_userinfo($agentID);
    $server = make_url($results['simip'], 9000);
    if ($server['host'] === '') return false;

    $results = opensim_get_avatar_session($agentID);
    if (!$results) return false;
    $sessionID = $results['sessionID'];
    if ($secureID === null) $secureID = $results['secureID'];

    $req = array('clientUUID' => $agentID, 'clientSessionID' => $sessionID, 'clientSecureSessionID' => $secureID, 'Balance' => $amount);
    $params = array($req);
    $request = xmlrpc_encode_request('UpdateBalance', $params);
    $response = do_call($server['url'], $server['port'], $request);

    return $response !== null && isset($response['success']) ? $response['success'] : false;
}

function do_call($uri, $port, $request)
{
    $server = make_url($uri, $port);

    $header = array(
        'Content-type: text/xml',
        'Content-length: ' . strlen($request)
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $server['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request);

    $data = curl_exec($ch);
    if (!curl_errno($ch)) curl_close($ch);

    $ret = false;
    if ($data) $ret = xmlrpc_decode($data);

    return $ret;
}

function get_confirm_value($ipAddress)
{
    $key = env_get_config('currency_script_key');
    if ($key === '') $key = 'SECRET_KEY';
    return md5($key . '_' . $ipAddress);
}

function buy_land_prep($method_name, $params, $app_data)
{
    $req = $params[0];
    $agentid = $req['agentId'];
    $secureid = $req['secureSessionId'];
    $amount = $req['currencyBuy'];
    $billableArea = $req['billableArea'];
    $ipAddress = $_SERVER['REMOTE_ADDR'];

    $ret = opensim_check_secure_session($agentid, null, $secureid);

    if ($ret) {
        $confirmvalue = get_confirm_value($ipAddress);
        $membership_levels = array('levels' => array('id' => "00000000-0000-0000-0000-000000000000", 'description' => "some level"));
        $landUse = array('upgrade' => false, 'action' => SYSURL);
        $currency = array('estimatedCost' => convert_to_real($amount));
        $membership = array('upgrade' => false, 'action' => SYSURL, 'levels' => $membership_levels);
        $response_xml = xmlrpc_encode(array(
            'success' => true,
            'currency' => $currency,
            'membership' => $membership,
            'landUse' => $landUse,
            'confirm' => $confirmvalue
        ));
    } else {
        $response_xml = xmlrpc_encode(array(
            'success' => false,
            'errorMessage' => "Unable to Authenticate\n\nClick URL for more info.",
            'errorURI' => SYSURL
        ));
    }

    header("Content-type: text/xml");
    echo $response_xml;

    return "";
}

xmlrpc_server_register_method($xmlrpc_server, "buyLandPrep", "buy_land");

function buy_land($method_name, $params, $app_data)
{
    $req = $params[0];
    $agentid = $req['agentId'];
    $secureid = $req['secureSessionId'];
    $amount = $req['currencyBuy'];
    $cost = $req['estimatedCost'];
    $billableArea = $req['billableArea'];
    $confirm = $req['confirm'];
    $ipAddress = $_SERVER['REMOTE_ADDR'];

    if ($confirm != get_confirm_value($ipAddress)) {
        $response_xml = xmlrpc_encode(array(
            'success' => false,
            'errorMessage' => "\n\nMismatch Confirm Value!!",
            'errorURI' => SYSURL
        ));
        header("Content-type: text/xml");
        echo $response_xml;
        return "";
    }

    $ret = opensim_check_secure_session($agentid, null, $secureid);

    if ($ret) {
        if ($amount >= 0) {
            if (!$cost) $cost = convert_to_real($amount);
            if (!process_transaction($agentid, $cost, $ipAddress)) {
                $response_xml = xmlrpc_encode(array(
                    'success' => false,
                    'errorMessage' => "\n\nThe gateway has declined your transaction. Please update your payment method AND try again later.",
                    'errorURI' => SYSURL
                ));
            }
            $enough_money = false;
            $res = add_money($agentid, $amount, $secureid);
            if ($res["success"]) $enough_money = true;

            if ($enough_money) {
                $amount += get_balance($agentid);
                move_money($agentid, null, $amount, 5002, 0, "Land Purchase", 0, 0, $ipAddress);
                update_simulator_balance($agentid, -1, $secureid);
                $response_xml = xmlrpc_encode(array('success' => true));
            } else {
                $response_xml = xmlrpc_encode(array(
                    'success' => false,
                    'errorMessage' => "\n\nYou do not have sufficient funds for this purchase",
                    'errorURI' => SYSURL
                ));
            }
        }
    } else {
        $response_xml = xmlrpc_encode(array(
            'success' => false,
            'errorMessage' => "\n\nUnable to Authenticate\n\nClick URL for more info.",
            'errorURI' => SYSURL
        ));
    }

    header("Content-type: text/xml");
    echo $response_xml;

    return "";
}

// Process XMLRPC request
$request_xml = file_get_contents('php://input');
function log_debug_data($data, $file = 'debugdialog.log') {
    $log_file = realpath(dirname(__FILE__) . '/' . $file);
    $log_data = "[" . date('Y-m-d H:i:s') . "] " . print_r($data, true) . "\n";

    if (file_put_contents($log_file, $log_data, FILE_APPEND | LOCK_EX) === false) {
        error_log("Failed to write to log file: $log_file");
    }
}

// Beispiel für currency.php und landtool.php
$request_xml = file_get_contents('php://input');
if ($request_xml === false) {
    error_log("Failed to get input data");
} else {
    log_debug_data($request_xml, 'debugdialog.log');
}

xmlrpc_server_call_method($xmlrpc_server, $request_xml, '');
xmlrpc_server_destroy($xmlrpc_server);
