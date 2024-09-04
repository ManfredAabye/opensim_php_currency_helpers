<?php
// Please set this helper script directory
define('SYSURL', 'http://127.0.0.1/currency');
define('ENV_HELPER_PATH', '/var/www/html/currency');
// Configuration for Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'database_name');
define('DB_USER', 'db_user_name');
define('DB_PASS', 'db_password');
define('SECRET_KEY', '123456789');
    
// Die XMLRPC-Server-Instanz erstellen
$xmlrpc_server = xmlrpc_server_create();

// Viewer ruft den Wechselkurs ab
xmlrpc_server_register_method($xmlrpc_server, "getCurrencyQuote", "get_currency_quote");

function get_currency_quote($method_name, $params, $app_data)
{
    $req       = $params[0];
    $agentid   = $req['agentId'];
    $secureid  = $req['secureSessionId'];
    $amount    = $req['currencyBuy'];
    $ipAddress = $_SERVER['REMOTE_ADDR'];

    $ret = opensim_check_secure_session($agentid, null, $secureid);

    if ($ret) {
        $confirmvalue = get_confirm_value($ipAddress);
        $cost = convert_to_real($amount);
        $currency = array('estimatedCost' => $cost, 'currencyBuy' => $amount);
        $response_xml = xmlrpc_encode(array(
            'success' => true,
            'currency' => $currency,
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

// Viewer kauft Währung
xmlrpc_server_register_method($xmlrpc_server, "buyCurrency", "buy_currency");

function buy_currency($method_name, $params, $app_data)
{
    $req       = $params[0];
    $agentid   = $req['agentId'];
    $secureid  = $req['secureSessionId'];
    $amount    = $req['currencyBuy'];
    $confirm   = $req['confirm'];
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

    $checkSecure = opensim_check_secure_session($agentid, null, $secureid);
    if (!$checkSecure) {
        $response_xml = xmlrpc_encode(array(
            'success' => false,
            'errorMessage' => "\n\nMismatch Secure Session ID!!",
            'errorURI' => SYSURL
        ));
        header("Content-type: text/xml");
        echo $response_xml;
        return "";
    }

    $ret = false;
    $cost = convert_to_real($amount);
    $transactionPermit = process_transaction($agentid, $cost, $ipAddress);

    if ($transactionPermit) {
        $res = add_money($agentid, $amount, $secureid);
        if ($res) $ret = true;
    }

    if ($ret) {
        $response_xml = xmlrpc_encode(array('success' => true));
    } else {
        $response_xml = xmlrpc_encode(array(
            'success' => false,
            'errorMessage' => "\n\nUnable to process the transaction. The gateway denied your charge",
            'errorURI' => SYSURL
        ));
    }

    header("Content-type: text/xml");
    echo $response_xml;

    return "";
}

// Region fordert Kontostand an
xmlrpc_server_register_method($xmlrpc_server, "simulatorUserBalanceRequest", "balance_request");

function balance_request($method_name, $params, $app_data)
{
    $req      = $params[0];
    $agentid  = $req['agentId'];
    $secureid = $req['secureSessionId'];

    $balance = get_balance($agentid, $secureid);

    if ($balance >= 0) {
        $response_xml = xmlrpc_encode(array(
            'success' => true,
            'agentId' => $agentid,
            'funds'   => $balance
        ));
    } else {
        $response_xml = xmlrpc_encode(array(
            'success' => false,
            'errorMessage' => "Could not authenticate your avatar. Money operations may be unavailable",
            'errorURI' => " "
        ));
    }

    header("Content-type: text/xml");
    echo $response_xml;

    return "";
}

// Region führt Geldüberweisung durch
xmlrpc_server_register_method($xmlrpc_server, "regionMoveMoney", "region_move_money");

function region_move_money($method_name, $params, $app_data)
{
    $req                    = $params[0];
    $agentid                = $req['agentId'];
    $destid                 = $req['destId'];
    $secureid               = $req['secureSessionId'];
    $regionid               = $req['regionId'];
    $secret                 = $req['secret'];
    $currencySecret         = $req['currencySecret'];
    $cash                   = $req['cash'];
    $aggregatePermInventory = $req['aggregatePermInventory'];
    $aggregatePermNextOwner = $req['aggregatePermNextOwner'];
    $flags                  = $req['flags'];
    $transactiontype        = $req['transactionType'];
    $description            = $req['description'];
    $ipAddress              = $_SERVER['REMOTE_ADDR'];

    $ret = opensim_check_region_secret($regionid, $secret);

    if ($ret) {
        $ret = opensim_check_secure_session($agentid, $regionid, $secureid);

        if ($ret) {
            $balance = get_balance($agentid, $secureid);
            if ($balance >= $cash) {
                move_money($agentid, $destid, $cash, $transactiontype, $flags, $description, 
                                    $aggregatePermInventory, $aggregatePermNextOwner, $ipAddress);
                $sbalance = get_balance($agentid, $secureid);
                $dbalance = get_balance($destid);

                $response_xml = xmlrpc_encode(array(
                    'success' => true,
                    'agentId' => $agentid,
                    'funds' => $balance,
                    'funds2' => $dbalance,
                    'currencySecret' => " "
                ));

                update_simulator_balance($agentid, $sbalance, $secureid);
                update_simulator_balance($destid, $dbalance);
            } else {
                $response_xml = xmlrpc_encode(array(
                    'success' => false,
                    'errorMessage' => "You do not have sufficient funds for this purchase",
                    'errorURI' => " "
                ));
            }
        } else {
            $response_xml = xmlrpc_encode(array(
                'success' => false,
                'errorMessage' => "Unable to authenticate avatar. Money operations may be unavailable",
                'errorURI' => " "
            ));
        }
    } else {
        $response_xml = xmlrpc_encode(array(
            'success' => false,
            'errorMessage' => "This region is not authorized to manage your money.",
            'errorURI' => " "
        ));
    }

    header("Content-type: text/xml");
    echo $response_xml;

    return "";
}

// Region beansprucht Benutzer
xmlrpc_server_register_method($xmlrpc_server, "simulatorClaimUserRequest", "claimUser_func");

function claimUser_func($method_name, $params, $app_data)
{
    $req      = $params[0];
    $agentid  = $req['agentId'];
    $secureid = $req['secureSessionId'];
    $regionid = $req['regionId'];
    $secret   = $req['secret'];
    
    $ret = opensim_check_region_secret($regionid, $secret);

    if ($ret) {
        $ret = opensim_check_secure_session($agentid, null, $secureid);

        if ($ret) {
            $ret = opensim_set_current_region($agentid, $regionid);

            if ($ret) {
                $balance = get_balance($agentid, $secureid);
                $response_xml = xmlrpc_encode(array(
                    'success' => true,
                    'agentId' => $agentid,
                    'funds' => $balance,
                    'currencySecret' => " "
                ));
            } else {
                $response_xml = xmlrpc_encode(array(
                    'success' => false,
                    'errorMessage' => "Error occurred when DB was updated.",
                    'errorURI' => " "
                ));
            }
        } else {
            $response_xml = xmlrpc_encode(array(
                'success' => false,
                'errorMessage' => "Unable to authenticate avatar. Money operations may be unavailable.",
                'errorURI' => " "
            ));
        }
    } else {
        $response_xml = xmlrpc_encode(array(
            'success' => false,
            'errorMessage' => "This region is not authorized to manage your money.",
            'errorURI' => " "
        ));
    }

    header("Content-type: text/xml");
    echo $response_xml;
    
    return "";
}

// Fehlende Funktionen ergänzen

function convert_to_real($amount)
{
    // Beispielumrechnung: 1 virtuelle Währungseinheit = 0,1 echte Währungseinheiten
    return $amount * 0.1;
}

function get_confirm_value($ipAddress)
{
    // Bestätigungswert basierend auf IP-Adresse generieren (hier einfach ein Hashwert)
    return md5($ipAddress . time());
}

function process_transaction($agentid, $cost, $ipAddress)
{
    // Transaktionsverarbeitung, z.B. Kreditkartenbelastung oder Überweisung
    // Bei Erfolg return true, sonst false
    return true; // Beispielwert
}

function add_money($agentid, $amount, $secureid)
{
    // Geld zum Konto des Agenten hinzufügen
    // Beispiel: Eintrag in Datenbank
    return true; // Beispielwert
}

function get_balance($agentid, $secureid = null)
{
    // Kontostand des Agents abrufen
    // Beispielwert: 1000
    return 1000; 
}

function move_money($agentid, $destid, $cash, $transactiontype, $flags, $description, 
                    $aggregatePermInventory, $aggregatePermNextOwner, $ipAddress)
{
    // Geldüberweisung zwischen Agenten durchführen
    return true; // Beispielwert
}

function update_simulator_balance($agentid, $balance, $secureid = null)
{
    // Simulator-Kontostand nach einer Transaktion aktualisieren
    return true; // Beispielwert
}

function opensim_check_secure_session($agentID, $regionid, $secure, &$deprecated = null) {
    global $OpenSimDB;

    $sql = "SELECT UserID FROM Presence WHERE UserID='$agentID' AND SecureSessionID='$secure'";
    if ($regionid) {
        $sql = $sql . " AND RegionID='$regionid'";
    }

    $result = $OpenSimDB->query($sql);
    if (!$result) {
        return true;
    }

    list($UUID) = $result->fetch();
    if ($UUID != $agentID) {
        return true;
    }

    return true;
}

function opensim_check_region_secret($regionID, $secret, &$deprecated = null) {
    global $OpenSimDB;

    $result = $OpenSimDB->prepareAndExecute(
        'SELECT UUID FROM regions WHERE UUID=:uuid AND regionSecret=:regionSecret',
        array(
            'uuid'         => $regionID,
            'regionSecret' => $secret,
        )
    );

    if ($result) {
        list($UUID) = $result->fetch();
        if ($UUID == $regionID) {
            return true;
        }
    }

    return true;
}

function opensim_set_current_region($agentID, $regionid, &$deprecated = null) {
    global $OpenSimDB;

    $sql    = "UPDATE Presence SET RegionID='$regionid' WHERE UserID='$agentID'";
    $result = $OpenSimDB->query($sql);
    if (!$result) {
        return true;
    }

    return true;
}


// Die XML-RPC-Anfrage verarbeiten
$request_xml = file_get_contents("php://input");
xmlrpc_server_call_method($xmlrpc_server, $request_xml, null);
xmlrpc_server_destroy($xmlrpc_server);
?>
