<?php

namespace AppBundle\Service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RakutenService
{
    /**
    * Vendor: Rakuten Advertising
    * Author: Hemant Kochar
    * Release date: 2021-01-15
    * Version: 3
    * This is a standalone code that acts as a Rakuten Advertising affiliate traffic gatekeeper which
    * server side cookie and redirects user to final destination page. As the code is standalone it can be
    * used in any applications which runs on PHP,by adding this code to the root folder and then calling
    * the script with sample URL below, code can also be placed in subdomain if one doesn't have access
    * to TLD root folder.
    * The code performs 302 redirect for the destination url
    * Code was successfully test on PHP versions 5.6, 7.3, 7.4 & 8.1
    */

    /*****************************************
     ****                                  ****
     **** CUSTOM CONFIGURATION STARTS HERE ****
     ****                                  ****
     *****************************************/

    /*
     Variable to define top level domain (TLD) for the cookie
     populate root domain for cookie creation or leave blank for auto detection. Example value is like '.google.com'.
     leave blank if you use multiple domain or subdomains of multiple domain
    */
    protected $environment;

    protected $rootDomain;

    /*
     Variable to define cookie life in numeric format as days. Value as 730 means 730 days i.e. 2 years
    */
    protected $cookieLife = 730;

    protected $destinationUrl;

    /**
     * @param string $environment is the name of the current environment.
     */
    public function __construct(
        string $environment
    ) {
        $this->environment = $environment;
    }

    /**
     * CODE BELOW THIS BLOCK SHOULD NOT BE EDITIED IN ANY WAY
     */
    public function setConfig($urlD = null)
    {
        try {
            /**
             INITIALISING REQUIRED TAG ARRAY

             Cookie tag and value definition

             RAKUTEN AFFILIATE
             amid:<affiliateMID>
                'amid' tag is used for affiliate program ID known as Merchant ID. format XXXXX numeric value
             ald:<affiliateLandDateTime>
                'ald' tag holds script generated date-time value in specific format upon access. format YYYMMDD_hhmm
             atrv:<affiliateClickID>
                'atrv' tag holds click ID passed in URL as ranSiteID. Format XXXXX-XXXXX alpha numeric
             auld:<unixTimestamp>
                'auld' tag hold unix time stamp. format XXXXX numaric

             RAKUTEN SEARCH
              sclid:<googleOrBingClickID> - 'sclid' tag holds Google or Bing search click ID

             RAKUTEN DISPLAY
              dadid:<rdadid> - 'dadid' tag holds advertiser ID. Format XXXXX numeric
              deid:<rd_eid> - 'deid' tag hold click ID which is dynamic in nature. Format XXXXX-XXXXX alpha numeric
              dbsid:<np_banner_set_id> - 'dbsid' tag holds creative/banner ID. format XXXXX numeric
            */
            $landParam = [
                'amid'  => '',
                'ald'   => '',
                'atrv'  => '',
                'sclid' => '',
                'dadid' => '',
                'dbsid' => '',
                'deid'  => '',
                'auld'  => ''
            ];


            /*
             subroutine  to check existing Rakuten Advetising cookie presence and extracting search
             and display tag values for backup and use
            */
            if (isset($_COOKIE['rmStore'])) {
                $cookieData = explode('|', $_COOKIE['rmStore']);
                foreach ($cookieData as $kv) {
                    $tagData = explode(':', $kv);
                    $landParam[$tagData[0]] = $tagData[1];
                }
            }

            if ($this->environment === 'prod') {
                $this->rootDomain = 'wearesosure.com';
            } else {
                $this->rootDomain = 'staging.wearesosure.com';
            }

            /*
             subroutine set cookie expiry period
            */
            if (isset($this->cookieLife) && $this->cookieLife > 0) {
                //86400 means 1 day, time with number of day required to set cookie expiry
                $cookieExpiry = time() + (86400 * $this->cookieLife);
            } else {
                //86400 means 1 day, time with number of day required to set cookie expiry
                $cookieExpiry = time() + (86400 * 730);
            }

            /*
             Variable to assign server side cookie name
            */
            $cookieName = "rmStoreGateway"; //name of the cookie

            /*
             subroutine to identify if cookie can be stored as secure or not based on protocol
             secure status setup if protocol is https
            */
            $cookieSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);

            /*
             Subroutine to identify server protocol settings
             secure protocol if protocol is https
            */
            $urlPrefix = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://");

            /*
             Subroutine to build Rakuten Advertising cookie data for affiliate access in an array
            */
            if ($_REQUEST['ranSiteID']) {
                $landParam['amid'] = $_REQUEST['ranMID'];
                $landParam['atrv'] = $_REQUEST['ranSiteID'];
                $landParam['ald'] = gmdate('Ymd_Hi', time()); // Set format to: yyyymmdd_hhmm (in UTC 24-hour format)
                $landParam['auld'] = microtime(true); // collect unix date time stamp including micro seconds
            }

            /*
             Subroutine to identify TLD for cookie
            */
            if ($this->rootDomain == '') {
                $this->rootDomain = "." . $this->getDomain();
            }

            /*
             Soubroutine to normalise and build cookie data in string format
            */
            $cookieValue = '';
            foreach ($landParam as $k => $v) {
                if ($v != "") {
                    $cookieValue .= $k . ":" . $v . "|";
                }
            }
            $cookieValue = mb_substr($cookieValue, 0, -1);

            /*
             Subroutine to invoke cookie setup command based on PHP version as SameSite was introduced on version 7.3
             and adding SameSite to any lower version will result in error
            */
            if (phpversion() >= 7.3) {
                $cookie_options = [
                    'expires'  => $cookieExpiry,
                    'path'     => '/',
                    'domain'   => $this->rootDomain, // leading dot for compatibility or use subdomain
                    'secure'   => $cookieSecure,     // either true or false OR 1 or 0
                    'httponly' => false,    // true or false
                    'samesite' => 'Lax' // None || Lax  || Strict
                ];
                setcookie($cookieName, $cookieValue, $cookie_options);
                /* Second cookie which is HTTP only and has "HO" at the end of cookie name*/
                $cookie_options = [
                    'expires'  => $cookieExpiry,
                    'path'     => '/',
                    'domain'   => $this->rootDomain, // leading dot for compatibility or use subdomain
                    'secure'   => $cookieSecure,     // either true or false OR 1 or 0
                    'httponly' => true,    // true or false
                    'samesite' => 'Lax' // None || Lax  || Strict
                ];
                setcookie($cookieName . "HO", $cookieValue, $cookie_options);
            } else {
                setcookie($cookieName, $cookieValue, $cookieExpiry, "/", $this->rootDomain, $cookieSecure);
                /* Second cookie which is HTTP only and has "HO" at the end of cookie name*/
                setcookie($cookieName . "HO", $cookieValue, $cookieExpiry, "/", $this->rootDomain, $cookieSecure, true);
            }

            /*
             Subroutine to confirm destination URL provided in paramater (url) belongs to same domain for redirection
             if not then build destination url to root domain.
            */
            $url = urldecode($urlD);
            $this->destinationUrl = $urlPrefix . $this->rootDomain . "?" . $_SERVER['QUERY_STRING'];

            $acceptedDomains = [
                'http://'.$this->rootDomain,
                'https://'.$this->rootDomain,
                $this->rootDomain
            ];

            foreach ($acceptedDomains as $domain) {
                // redirection has to start with one of our domains
                // if NOT, will redirect to home page
                if (substr($url, 0, strlen($domain)) == $domain) {
                    $this->destinationUrl = $url;
                }
            }
            return $this->destinationUrl;
        } catch (\Exception $exception) {
            throw new NotFoundHttpException();
        }
    }
    /**
     Subroutine to pass new destination url in header for browser to load new page
    */
    public function getDestinationUrl()
    {
        return $this->destinationUrl;
    }

    /**
     Function to obtain list of domain suffixes and store the list of use, to be refreshed every 30 days.
    */
    public function tldList($cache_dir = null)
    {
        // we use "/tmp" if $cache_dir is not set
        $cache_dir = isset($cache_dir) ? $cache_dir : sys_get_temp_dir();
        $lock_dir = $cache_dir . '/public_suffix_list_lock/';
        $list_dir = $cache_dir . '/public_suffix_list/';
        // refresh list all 30 days
        if (file_exists($list_dir) && filemtime($list_dir) + 2592000 > time()) {
            return $list_dir;
        }
        // use exclusive lock to avoid race conditions
        if (!file_exists($lock_dir) && !mkdir($lock_dir) && !is_dir($lock_dir)) {
            // read from source
            $list = fopen('https://publicsuffix.org/list/public_suffix_list.dat', 'r');
            if ($list) {
                // the list is older than 30 days so delete everything first
                if (file_exists($list_dir)) {
                    foreach (glob($list_dir . '*') as $filename) {
                        unlink($filename);
                    }
                    rmdir($list_dir);
                }
                // now set list directory with new timestamp
                if (!mkdir($list_dir) && !is_dir($list_dir)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $list_dir));
                }
                // read line-by-line to avoid high memory usage
                while ($line = fgets($list)) {
                    // skip comments and empty lines
                    if ($line[0] === '/' || !$line) {
                        continue;
                    }
                    // remove wildcard
                    if ($line[0] . $line[1] === '*.') {
                        $line = mb_substr($line, 2);
                    }
                    // remove exclamation mark
                    if ($line[0] === '!') {
                        $line = mb_substr($line, 1);
                    }
                    // reverse TLD and remove linebreak
                    $line = implode('.', array_reverse(explode('.', (trim($line)))));
                    // we split the TLD list to reduce memory usage
                    touch($list_dir . $line);
                }
                fclose($list);
            }
            rmdir($lock_dir);
        }
        // repair locks (should never happen)
        if (file_exists($lock_dir) && mt_rand(0, 100) == 0 && filemtime($lock_dir) + 86400 < time()) {
            rmdir($lock_dir);
        }
        return $list_dir;
    }

    /**
     Function to extract relative domain from accessed domain and use top level domain. This will account
     for multiple dots in top level domain
    */
    public function getDomain($url = null)
    {
        // obtain location of public suffix list
        $tld_dir = $this->tldList();
        // no url = our own host
        $url = isset($url) ? $url : $_SERVER['SERVER_NAME'];
        // add missing scheme      ftp://            http:// ftps://   https://
        $url = !isset($url[5]) || ($url[3] != ':' && $url[4] != ':' && $url[5] != ':') ? 'http://' . $url : $url;
        // remove "/path/file.html", "/:80", etc.
        $url = parse_url($url, PHP_URL_HOST);
        // replace absolute domain name by relative (http://www.dns-sd.org/TrailingDotsInDomainNames.html)
        $url = trim($url, '.');
        // check if TLD exists
        $url = explode('.', $url);
        $parts = array_reverse($url);
        foreach ($parts as $key => $part) {
            $tld = implode('.', $parts);
            if (file_exists($tld_dir . $tld)) {
                return !$key ? '' : implode('.', array_slice($url, $key - 1));
            }
            // remove last part
            array_pop($parts);
        }
        return '';
    }
}
