<?php
/**
 *
 * Sample: php tr069config.php --debug scan --write ip-list.txt 10.55.54.170 10.55.54.179 admin admin123
 *
 */

namespace Tr069Config\Command;

use CLIFramework\Command;
use CLIFramework\Exception\InvalidCommandArgumentException;

class ScanCommand extends Command
{

    public function brief()
    {
        return 'Scan an IP range for eSpace devices and returns the IP if detected';
    }

    function init()
    {
        // register your subcommand here ..
    }

    function options($opts)
    {
        // command options
        $opts->add('w|write:', 'write the IP addresses of detected eSpace devices.');
        $opts->add('i|insecure', 'force non-https connection.');
        $opts->add('s|secure', 'force non-https connection.');
        $opts->add('h|hash-password', 'provide password as a hash rather than plain-text.');
        $opts->add('u|username:', 'default username to connect to the device.');
        $opts->add('p|password:', 'default password to connect to the device.');
        $opts->add('a|accounts-list:', 'csv file containing the list of default usermame and password.');

    }

    public function arguments($args)
    {
        $args->add('startIp')->desc('Starting IP');
        $args->add('endIp')->desc('Ending IP');
    }


    function execute($startIp, $endIp)
    {

        try {

            /*
             * checking parameters
             */

            if (!filter_var($startIp, FILTER_VALIDATE_IP)) {
                $e = new InvalidCommandArgumentException($this, 1, $startIp);
                $this->logger->error($e->getMessage());
                return false; //invalid parameter, exit
            }
            if (!filter_var($endIp, FILTER_VALIDATE_IP)) {
                $e = new InvalidCommandArgumentException($this, 1, $endIp);
                $this->logger->error($e->getMessage());
                return false; //invalid parameter, exit
            }

            $ipList = array();
            $startIpLong = ip2long($startIp);
            $endIpLong = ip2long($endIp);
            if ($endIpLong <= $startIpLong) {
                $this->logger->error('Invalid IP range.');
                return false; //invalid parameter, exit
            }

            /*
             * checking options
             */


            if ($this->options->has('insecure')) { //force insecure
                $connectionModes = array('insecure'=>'http');
            } elseif ($this->options->has('secure')) { //force secure
                $connectionModes = array('secure'=>'https');
            } else { //use secure then fall back to insecure
                $connectionModes = array('secure'=>'https','insecure'=>'http');
            }
            if ($this->options->has('hash-password')) { //force use hash-only password
                $passwordModes = array(true);
            } else { //use hash then fallback to non-hash password
                $passwordModes = array(true, false);
            }

            /*
             * checking config files
             */

            if ($this->options->has('accounts-list')) {
                $defaultAccountsListFile = $this->options['accounts-list']->value;
            } else {
                $defaultAccountsListFile = realpath(dirname(__FILE__)) . '/data/default-accounts.csv';
            }
            if (file_exists($defaultAccountsListFile)) {
                $rows = array_map('str_getcsv', file($defaultAccountsListFile));
                $header = array_shift($rows);
                $csvDefaultAccountList = array();
                foreach ($rows as $row) {
                    $csvDefaultAccountList[] = array_combine($header, $row);
                }
                $this->logger->debug('Account list file "' . $defaultAccountsListFile . '" has been found with ' . count($csvDefaultAccountList) . ' record(s).');
            } else {
                $this->logger->error('Accounts list file "' . $defaultAccountsListFile . '" does not exist.');
                return false; //missing files, exit
            }

            /*
             * other variables
             */

            $response = new \stdClass();
            $response->success = false;
            $response->data = null;




            /*
             * main program block
             */


            //** announce start operation */
            $this->logger->info('Scanning IP range "' . $startIp . ' -> ' . $endIp . '" ...');
            if ($this->options->has('debug')) $this->logger->info('[Debugging is enabled]');

            $ipCounter = $startIpLong - 1;
            while ($ipCounter <= $endIpLong) {

                $ipCounter++;
                $deviceIp = long2ip($ipCounter);

                //** do accounts */
                $i = 0;
                foreach ($csvDefaultAccountList as $csvDefaultAccount) {
                    $eSpaceUsername = $csvDefaultAccount['username'];
                    $eSpacePassword = $csvDefaultAccount['password'];
                    $i++;


                    //** do connection modes */
                    foreach($connectionModes as $connectionText => $connectionMode) {
                        $this->logger->debug('Checking IP "' . $deviceIp . ' using ' . $connectionText . ' mode.');
                        $eSpace = new \Tr069Config\Espace\EspaceClass($connectionMode.'://' . $deviceIp, null, $eSpaceUsername);
                        $response = $eSpace->requestSession($eSpaceUsername);
                        $this->logger->debug2('EspaceClass::requestSession = ' . var_export($response, true));
                        if (!$response->success) {
                            $this->logger->debug('Unable to open ' . $connectionText. ' session to "' . $deviceIp . '".');
                        } else {
                            break; //stop trying other connection mode
                        }
                    }//connection mode loop
                    if (!$response->success) {
                        continue; //cant connect, try next account
                    } else {

                        //** do logins */
                        foreach ($passwordModes as $passwordMode) {

                            $eSpace->setUseHashPassword($passwordMode);

                            $response = $eSpace->requestCertificate($eSpaceUsername, $eSpacePassword);
                            $this->logger->debug2('EspaceClass::requestCertificate = ' . var_export($response, true));
                            if (!$response->success) {
                                if (count($passwordModes) > 1 && $passwordMode === false) {
                                    $logMsg = 'Failed non-hashed ';
                                } else {
                                    $logMsg = 'Failed hashed ';
                                }
                                $this->logger->debug($logMsg . 'login to "' . $deviceIp
                                    . '" using "' . $eSpaceUsername . ':' . $eSpacePassword . '" '
                                    . 'at attempt ' . $i . '/' . count($csvDefaultAccountList) . '');
                            } else {
                                break; //stop trying different password mode
                            }
                        }//Password mode loop
                        if ($response->success) break; //stop looking for more accounts
                    }
                }//Account loop

                if (!$response->success) {
                    continue; //cant login, try next IP
                } else {

                    //** do request info */
                    $response = $eSpace->requestVersionInfo();
                    $this->logger->debug2('EspaceClass::requestVersionInfo = ' . var_export($response, true));
                    if (!$response->success) {
                        $this->logger->error('Cannot get hardware information of ' . $deviceIp . '.');
                        continue; // cant get info, try next IP
                    }

                    //** do display result */
                    $this->logger->info('eSpace device found at ' . $deviceIp);
                    $hardwareInfo = json_decode($response->data)->stMainVersionInfo;
                    $this->logger->debug2('EspaceClass::requestVersionInfo->data(StdClass) = ' . var_export($hardwareInfo, true));
                    $this->logger->info("Hardware Information ="
                        . "\n\tMain SoftWare Version: " . $hardwareInfo->szMainSoftWareVersion
                        . "\n\tBoot Version:          " . $hardwareInfo->szBootVersion
                        . "\n\tHardWare Version:      " . $hardwareInfo->szHardWareVersion
                        . "\n\tSerial Number:         " . $hardwareInfo->szSN
                        . "\n\tBuild Version:         " . $hardwareInfo->szBuildVersion
                    );

                    $ipList[] = $deviceIp . ','
                        . $hardwareInfo->szSN . ','
                        . $hardwareInfo->szMainSoftWareVersion . ','
                        . $hardwareInfo->szBootVersion . ','
                        . $hardwareInfo->szHardWareVersion . ','
                        . $hardwareInfo->szBuildVersion;

                }
            } //IP loop
            $this->logger->info('Finished. Scan found ' . count($ipList) . ' eSpace device(s).');

            //** optional, save to IP list */

            if (count($ipList) && $this->options->has('write')) {
                file_put_contents($this->options['write']->value, implode("\n", $ipList));
                $this->logger->info('Scan result written to file "' . $this->options['write']->value . '"');
            }

            return true;

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return false;
        }
    }
}