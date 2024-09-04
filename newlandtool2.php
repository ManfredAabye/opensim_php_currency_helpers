<?php
// Configurations
define('DB_HOST', 'localhost');
define('DB_NAME', 'database_name');
define('DB_USER', 'db_user_name');
define('DB_PASS', 'db_password');
define('SECRET_KEY', '123456789');
define('USE_CURRENCY_SERVER', true);
define('UUID_ZERO', '00000000-0000-0000-0000-000000000000');

// Create XMLRPC server
$xmlrpc_server = xmlrpc_server_create();

// Database connection function
function opensim_new_db() {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($db->connect_error) {
        die("Connection failed: " . $db->connect_error);
    }
    return $db;
}

// Simple query execution function
function db_query($query, $params = []) {
    $db = opensim_new_db();
    $stmt = $db->prepare($query);
    if ($stmt === false) {
        return false;
    }

    if (!empty($params)) {
        $types = str_repeat('s', count($params)); 
        $stmt->bind_param($types, ...$params);
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $stmt->close();
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : true;
    }

    $stmt->close();
    return false;
}

// Utility functions
function env_get_config($key, $default = null) {
    $config = ['currency_script_key' => SECRET_KEY];
    return $config[$key] ?? $default;
}

function convert_to_real($amount)
{
    // Beispielumrechnung: 1 virtuelle Währungseinheit = 0,1 echte Währungseinheiten
    return $amount * 0.1;
}

function isGUID($uuid, $nullok = false) {
    return $uuid === null ? $nullok : preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid);
}

function make_url($serverURI, $portnum = 0) {
    $urlParts = parse_url($serverURI);
    $scheme = $urlParts['scheme'] ?? 'http';
    $host = $urlParts['host'] ?? $urlParts['path'];
    $port = $urlParts['port'] ?? $portnum ?: ($scheme === 'https' ? 443 : 80);
    return [
        'url' => "$scheme://$host:$port/",
        'host' => $host,
        'port' => $port,
        'protocol' => $scheme,
    ];
}

// Dummy functions to retrieve user information and session info
function opensim_get_userinfo($agentID) {
    // Replace with actual database query or API call
    return ['simip' => '127.0.0.1'];
}

function opensim_get_avatar_session($agentID) {
    // Replace with actual database query or API call
    return ['sessionID' => UUID_ZERO, 'secureID' => UUID_ZERO];
}

// Balance management functions
function get_balance($agentID, $secureID = null) {
    if (!USE_CURRENCY_SERVER || !isGUID($agentID) || ($secureID !== null && !isGUID($secureID, true))) {
        return -1;
    }

    $results = opensim_get_userinfo($agentID);
    $server = make_url($results['simip'], 9000);
    $session = opensim_get_avatar_session($agentID);

    $request = xmlrpc_encode_request('GetBalance', [['clientUUID' => $agentID, 'clientSessionID' => $session['sessionID'], 'clientSecureSessionID' => $secureID]]);
    $response = do_call($server['url'], $server['port'], $request);

    return $response['balance'] ?? -1;
}

function add_money($agentID, $amount, $secureID = null) {
    if (!USE_CURRENCY_SERVER || !isGUID($agentID) || ($secureID !== null && !isGUID($secureID, true))) {
        return false;
    }

    $results = opensim_get_userinfo($agentID);
    $server = make_url($results['simip'], 9000);
    $session = opensim_get_avatar_session($agentID);

    $request = xmlrpc_encode_request('AddBankerMoney', [['clientUUID' => $agentID, 'clientSessionID' => $session['sessionID'], 'clientSecureSessionID' => $secureID, 'amount' => $amount]]);
    $response = do_call($server['url'], $server['port'], $request);

    return $response['success'] ?? false;
}

// Transaction functions
function process_transaction($agentID, $cost, $ipAddress) {
    $query = "INSERT INTO transactions (agentid, cost, ip_address) VALUES (?, ?, ?)";
    return db_query($query, [$agentID, $cost, $ipAddress]);
}

function move_money($fromID, $toID, $amount, $type = 5003, $serverURI = null, $secretCode = null) {
    if (!USE_CURRENCY_SERVER || !isGUID($fromID) || !isGUID($toID)) {
        return false;
    }

    $server = $serverURI ? make_url($serverURI, 9000) : make_url(opensim_get_userinfo($fromID)['simip'], 9000);
    $secretCode = $secretCode ? md5($secretCode . '_' . $server['host']) : get_confirm_value($server['host']);

    $request = xmlrpc_encode_request('MoveMoney', [['fromUUID' => $fromID, 'toUUID' => $toID, 'secretAccessCode' => $secretCode, 'amount' => $amount]]);
    $response = do_call($server['url'], $server['port'], $request);

    return $response['success'] ?? false;
}

function update_simulator_balance($agentID, $amount = -1, $secureID = null) {
    if (!USE_CURRENCY_SERVER || !isGUID($agentID) || ($secureID !== null && !isGUID($secureID, true))) {
        return false;
    }

    if ($amount < 0) {
        $amount = get_balance($agentID, $secureID);
        if ($amount < 0) return false;
    }

    $results = opensim_get_userinfo($agentID);
    $server = make_url($results['simip'], 9000);
    $session = opensim_get_avatar_session($agentID);

    $request = xmlrpc_encode_request('UpdateBalance', [['clientUUID' => $agentID, 'clientSessionID' => $session['sessionID'], 'clientSecureSessionID' => $secureID, 'Balance' => $amount]]);
    $response = do_call($server['url'], $server['port'], $request);

    return $response['success'] ?? false;
}

// Helper functions
function do_call($uri, $port, $request) {
    $server = make_url($uri, $port);
    $header = [
        'Content-type: text/xml',
        'Content-length: ' . strlen($request),
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $server['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request);

    $data = curl_exec($ch);
    curl_close($ch);

    return $data ? xmlrpc_decode($data) : false;
}

function get_confirm_value($ipAddress) {
    $key = env_get_config('currency_script_key');
    return md5(($key ?: SECRET_KEY) . '_' . $ipAddress);
}

// XMLRPC methods
function buy_land_prep($method_name, $params, $app_data) {
    $req = $params[0];
    $agentid = $req['agentId'];
    $secureid = $req['secureSessionId'];
    $amount = $req['currencyBuy'];
    $ipAddress = $_SERVER['REMOTE_ADDR'];

    if (opensim_check_secure_session($agentid, null, $secureid)) {
        $confirmvalue = get_confirm_value($ipAddress);
        $currency = ['estimatedCost' => convert_to_real($amount)];
        $response = ['success' => true, 'currency' => $currency, 'confirm' => $confirmvalue];
    } else {
        $response = ['success' => false, 'errorMessage' => 'secure_session_mismatch'];
    }

    return $response;
}

// Register XMLRPC methods
xmlrpc_server_register_method($xmlrpc_server, 'currency.balance', 'get_balance');
xmlrpc_server_register_method($xmlrpc_server, 'currency.update', 'update_simulator_balance');
xmlrpc_server_register_method($xmlrpc_server, 'currency.buyLandPrep', 'buy_land_prep');

// Handle XMLRPC request
$request_xml = file_get_contents('php://input');
$response = xmlrpc_server_call_method($xmlrpc_server, $request_xml, null);
header('Content-Type: text/xml');
echo $response;

xmlrpc_server_destroy($xmlrpc_server);
