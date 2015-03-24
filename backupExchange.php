#!/usr/bin/env php
<?php

/**
 * Backup Exchange mailboxes hosted at OVH
 *
 * @version 0.3 - 2015-03-24
 * @author Olivier Doucet (github odoucet)
 * @license http://www.php.net/license/3_01.txt  PHP License 3.01
 * @link https://www.github.com/odoucet/backupexchangeovh
 */

// We need an up to date cert.pem (to access htps://api.ovh.com)
ini_set('curl.cainfo', '/etc/ssl/cert.pem');
set_time_limit(3600*2);

require 'OvhApi.php';

// Check parameters.ini
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
if (!preg_match('@^[0-9]{1,}$@', $credentials['max_age_backup'])) {
    exit('max_age_backup should be filled (unit is "hours")');
}
if (!is_dir($credentials['destination_folder'])) {
    exit('Destination folder should exist (provided: '.$credentials['destination_folder'].')');
}
if (strlen($credentials['date_format']) == 0) {
    exit('date_format should be set with strftime format');
}


// Create backup folder
define('BACKUPDIR', rtrim($credentials['destination_folder'], '/').'/'.strftime($credentials['date_format']));
if (!is_dir(BACKUPDIR)) {
    mkdir(BACKUPDIR, 0755) or die('cannot create backup directory "'.BACKUPDIR.'"');
}


// Instanciate API
$ovhApi = new OvhApi(OVH_API_EU, $credentials['application_key'], $credentials['application_secret'], $credentials['consumer_key']);

// Check credentials by loading available services
$availableServices = $ovhApi->get('/email/exchange');

if (is_object($availableServices) && $availableServices->errorCode != '') {
    exit(
        "Credentials are failing. Message returned by API server: ".
        $availableServices->errorCode.' - '.
        $availableServices->message."\n"
    );
}

if (!is_array($availableServices)) {
    exit('It seems you have no Exchange account.'."\n");
}

// Now, loop for each organization and retrieve services list
$servicesList = array(); // will store organisation+service name

foreach ($availableServices as $service) {
    $result = $ovhApi->get('/email/exchange/'.$service.'/service');
    if (!is_array($result)) {
        exit('error retrieving service list'."\n");
    }
    foreach ($result as $ser) {
        $servicesList[] = array('org' => $service, 'service' => $ser, 'accounts' => array());
    }
}


// Now, retrieve account list
$accountCount = 0;
foreach ($servicesList as $id => $service) {
    $result = $ovhApi->get('/email/exchange/'.$service['org'].'/service/'.$service['service'].'/account');
    if (!is_array($result)) {
        exit(
            'error retrieving account list for '.$service['org'].
            '/service/'.$service['service']."\n"
        );
    }

    foreach ($result as $idres => $res) {
        if (substr($res, -15) == '@configureme.me') {
            // this is an unset account, no backup for this one
            unset($result[$idres]);
        }
    }
    sort($result);

    $servicesList[$id]['accounts'] = $result;
    $accountCount += count($result);
}
echo "We have a total of ".$accountCount." accounts to backup\n";

$createNewExportArray = array();

// Now the real job : ask for a backup.

$backupAlreadyDone = false;
foreach ($servicesList as $id => $service) {
    foreach ($service['accounts'] as $email) {
        // Check last export date
        printf("* Mailbox %40s : ", $service['service'].'/'.$email);

        $result = $ovhApi->get(
            '/email/exchange/'.$service['org'].'/service/'.
            $service['service'].'/account/'.$email.'/export'
        );

        if ($result === null || (is_object($result) && isset($result->message) && preg_match('@does not exist@', $result->message))) {
            printf("  (no previous backup done) \n");
            $createNewExportArray[] = $service['org'].'/service/'.$service['service'].'/account/'.$email;

        } elseif (!is_object($result) || !isset($result->creationDate)) {
            printf("  an error occured when grabing export data for %s\n", $service['service'].'/account/'.$email);
            continue;

        } else {
            if ($result->percentComplete == 100) {
                $status = 'finished';
            } else {
                $status = $result->percentComplete.' %';
            }

            $dateBackup = new Datetime($result->creationDate);
            $hoursAgo   = floor((time() - $dateBackup->getTimestamp())/3600);
            printf(
                "  Last backup (%10s) @ %20s -%4s hours ago\n",
                $status,
                $dateBackup->format('d-m-y H:i:s'),
                $hoursAgo
            );

            // need to reset to make a new export
            if ($result->percentComplete == 100 && $hoursAgo > $credentials['max_age_backup']) {
                printf("  Reset export status ... ");
                $result = $ovhApi->delete(
                    '/email/exchange/'.$service['org'].'/service/'.
                    $service['service'].'/account/'.$email.'/export'
                );

                if (isset($result->errorCode) && $result->errorCode == 'NOT_GRANTED_CALL') {
                    printf(
                        "Cannot reset export status for account %s because it seems ".
                        "grant DELETE is missing. Please read again readme file.\n"
                    );
                    continue;
                }
                echo "OK\n";
                $backupAlreadyDone = true;
                $createNewExportArray[] = $service['org'].'/service/'.$service['service'].'/account/'.$email;

            } else {
                // no need to reset
            }
        }
    }
}

if ($backupAlreadyDone === true) {
    echo "We need to create new export, but we have to wait for the API to sync previous deletions\n";
    echo "So we sleep 100 seconds. See you later !\n";
    for ($i = 0; $i<10; $i++) {
        sleep(10);
        echo "zzZZzz ";
    }
    echo "\nWake UP !";
}

foreach ($createNewExportArray as $exp) {
    // Ask for new export
    $result = $ovhApi->post(
        '/email/exchange/'.$exp.'/export',
        null
    );
    // debug
    if (isset( $result->status)) {
        printf("  New export requested. Status: %s\n", $result->status);
    } else {
        printf("  Error when requesting new export. Message: %s\n", $result->message);
    }
}
unset($createNewExportArray);

// Will spend many time in this loop, waiting for backups to be completed.
$backupStatus = array();

while (sleep(10) === 0) {
    // Empty == done
    if (count($servicesList) == 0) {
        break;
    }

    foreach ($servicesList as $id => $service) {
        if (!is_array($service['accounts']) || count($service['accounts']) == 0) {
            unset($servicesList[$id]);
            continue;
        }

        foreach ($service['accounts'] as $idEmail => $email) {
            // used for array backupStatus
            $magicKey = $service['org'].'/'.$service['service'].'/'.$email;
            $apiUrl = '/email/exchange/'.$service['org'].'/service/'.$service['service'].'/account/'.$email.'/export';
            $result = $ovhApi->get($apiUrl);

            $filePath = BACKUPDIR.'/'.$service['service'].'/'.$email.'.pst';

            if (file_exists($filePath)) {
                unset($servicesList[$id]['accounts'][$idEmail]);
                continue;
            }

            if (isset($result->percentComplete) && $result->percentComplete < 100) {
                printf("%-20s  backup done at %3s %%\n", $email, $result->percentComplete);

            } elseif (isset($result->percentComplete) && $result->percentComplete == 100) {
                if (!isset($backupStatus[$magicKey])) {
                    // Yes ! Generate URL
                    printf("%-20s is OK, generating URL ...\n", $email);
                    $result = $ovhApi->post(
                        '/email/exchange/'.$service['org'].'/service/'.
                        $service['service'].'/account/'.$email.'/exportURL',
                        null
                    );
                    $backupStatus[$magicKey] = 'pendingUrl';

                } elseif ($backupStatus[$magicKey] == 'pendingUrl') {
                    // waiting for URL
                    $result = $ovhApi->get(
                        '/email/exchange/'.$service['org'].'/service/'.
                        $service['service'].'/account/'.$email.'/exportURL'
                    );
                    if (isset($result->url)) {
                        // Yes, backup !
                        if (file_exists($filePath)) {
                            printf("URL %s already downloaded, skipping it\n", $result->url);
                            unset($servicesList[$id]['accounts'][$idEmail]);
                            continue;
                        }
                        printf("Downloading to %s URL %s\n", $filePath, $result->url);

                        if (!file_exists(dirname($filePath))) {
                            mkdir(dirname($filePath));
                        }

                        if ($credentials['backup_method'] == 'wget') {
                            passthru('cd '.BACKUPDIR.' && wget -nc --limit-rate=10M -O "'.$filePath.'" -q '.$result->url);

                        } elseif ($credentials['backup_method'] == 'fopen') {
                            $fp = fopen($result->url, 'r');
                            $out= fopen($filePath, 'w');

                            while ($buf = fread($fp, 65536)) {
                                fwrite($out, $buf);
                                usleep(500);
                            }
                            fclose($out);
                            fclose($fp);
                        }

                        // Cleanup
                        unset($servicesList[$id]['accounts'][$idEmail]);

                        if (count($service['accounts']) == 0) {
                            unset($servicesList[$id]);
                        }

                    } else {
                        printf("Export not ready yet (no url given). API: ".$apiUrl." Debug: ".print_r($result, 1));
                        // sometimes, OVH forgets what we ask for ...
                        $result = $ovhApi->post(
                            '/email/exchange/'.$service['org'].'/service/'.
                            $service['service'].'/account/'.$email.'/exportURL',
                            null
                        );
                    }
                } else {
                    echo "Bug, case not handled line ".__LINE__.". \$backupStatus[magicKey]: ";
                    var_dump($backupStatus[$magicKey]);
                    echo 'magicKey: ';
                    var_dump($magicKey);
                }

            } elseif (isset($result->message) && $result->message == 'The requested object (export) does not exist') {
                // Export has not been done. This is a problem ... Ask for new export
                $result = $ovhApi->post(
                    '/email/exchange/'.$service['org'].'/service/'.
                    $service['service'].'/account/'.$email.'/export',
                    null
                );
                // debug
                if (isset( $result->status)) {
                    printf("  New export requested. Status: %s\n", $result->status);
                } else {
                    printf("  Error when requesting new export. Message: %s\n", $result->message);
                }


            } else {
                echo "Bug, case not handled line ".__LINE__.". \$result: ";
                var_dump($result);
            }

        }
    }
    echo "Sleeping 10 seconds then refresh ... zzzZzzzzZzzz .... \n";
    //var_dump($servicesList);
}
echo "Finished !\n";
