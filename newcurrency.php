<?php
// Check for the configuration and definition files
if (!defined('ENV_READ_CONFIG')) require_once(realpath(dirname(__FILE__) . '/include/config.php'));

// The XMLRPC server object
$xmlrpc_server = xmlrpc_server_create();

// Viewer retrieves currency buy quote
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

// Viewer buys currency
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

// Region requests account balance
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

function opensim_check_region_secret($uuid, $secret, &$db = null)
{
    if (!isGUID($uuid)) return false;
    if (!is_object($db)) $db = opensim_new_db();

    $sql = "SELECT UUID FROM regions WHERE UUID='".$uuid."' AND regionSecret='".$db->escape($secret)."'";
    $db->query($sql);
    if ($db->Errno == 0) {
        list($UUID) = $db->next_record();
        if ($UUID == $uuid) return true;
    }

    return false;
}

// Region initiates money transfer (Direct DB Operation for security)
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

function opensim_set_current_region($uuid, $regionid, &$db = null)
{
    if (!isGUID($uuid) || !isGUID($regionid)) return false;
    if (!is_object($db)) $db = opensim_new_db();

    if ($db->exist_table('Presence')) {
        $sql = "UPDATE Presence SET RegionID='".$regionid."' WHERE UserID='". $uuid."'";
    } else {
        $sql = "UPDATE GridUser SET LastRegionID='".$regionid."' WHERE UserID='". $uuid."'";
    }

    $db->query($sql);
    if ($db->Errno != 0) return false;
    $db->next_record();

    return true;
}

// Region claims user
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

// Process the request
if (!isset($HTTP_RAW_POST_DATA)) $HTTP_RAW_POST_DATA = file_get_contents('php://input');
$request_xml = $HTTP_RAW_POST_DATA;

xmlrpc_server_call_method($xmlrpc_server, $request_xml, '');
xmlrpc_server_destroy($xmlrpc_server);
