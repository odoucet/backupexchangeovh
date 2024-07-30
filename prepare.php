#!/usr/bin/env php
<?php

/**
 * Backup Exchange mailboxes hosted at OVH
 * ---------------------------------------
 * PREPARE SCRIPT: retrieve account list and that's all
 *
 * @version 1.0 - 2018-08-14
 * @author Olivier Doucet (github odoucet)
 * @license http://www.php.net/license/3_01.txt  PHP License 3.01
 * @link https://www.github.com/odoucet/backupexchangeovh
 */

require 'OvhApi.php';

$credentials = parse_ini_file('parameters.ini');
if (strlen($credentials['application_key']) < 16) {
    exit('Please fill application_key in parameters.ini');
}
if (strlen($credentials['application_secret']) < 32) {
    exit('Please fill application_secret in parameters.ini');
}
if (strlen($credentials['consumer_key']) < 32) {
    exit('Please fill consumer_key in parameters.ini');
}

if (!isset($argv[1])) {
    syntax();
}

if (!is_dir($argv[1]) && !mkdir($argv[1])) {
    echo "Cannot find directory or cannot create it\n";
    die(1);
}

// Instanciate API
$ovhApi = new OvhApi(OVH_API_EU, $credentials['application_key'], $credentials['application_secret'], $credentials['consumer_key']);

// Get org list
$orgList = $ovhApi->get('/email/exchange');

if (is_object($orgList) && $orgList->errorCode != '') {
    exit(
        "Credentials are failing. Message returned by API server: ".
        $orgList->errorCode.' - '.
        $orgList->message."\n"
    );
}

if (!is_array($orgList)) {
    exit('It seems you have no Exchange account.'."\n");
}

// Now, loop for each organization and retrieve services list
$accountList = array(); // will store organisation+service name

foreach ($orgList as $org) {
    $result = $ovhApi->get('/email/exchange/'.$org.'/service');
    if (!is_array($result)) {
        exit('error retrieving service list'."\n");
    }

    foreach ($result as $service) {
        $result = $ovhApi->get('/email/exchange/'.$org.'/service/'.$service.'/account');
        if (!is_array($result)) {
            exit(
                'error retrieving account list for '.$org.'/service/'.$service."\n"
            );
        }

        foreach ($result as $idres => $email) {
            if (substr($email, -15) == '@configureme.me') {
                // this is an unset account, no backup for this one
                continue;
            }
            $accountList[] = $org.'/service/'.$service.'/account/'.$email;
        }
    }
}

echo "We have a total of ".count($accountList)." accounts to backup\n";
sort($accountList);
file_put_contents($argv[1].'/accounts.txt', implode("\n", $accountList));

/**
 * Print syntax
 */
function syntax()
{
    global $argv;
    echo "Syntax: ".$argv[0].' <folder>'."\n";
    die(1);
}
