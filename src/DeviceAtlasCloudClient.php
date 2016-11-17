<?php
/*
 * Copyright (c) 2008-2014 by Afilias Technologies Limited (dotMobi). All rights reserved.
 */

/**
 * DeviceAtlas Cloud API. This client library can be used to easily get device
 * data from the DeviceAtlas Cloud service. To reduce cloud calls and improve
 * performance the API locally caches the data returned from the cloud service.
 * This API caches data on the disk.<br/>If the cache path is not writable the APin the I
 * will not proceed even if the device cache is turned off.<br/>
 * It is recommended to set DEBUG = true during implementation so if the cache
 * path is not writable the errors will not be ignored.<br/>
 *
 * The client is queried by passing either nothing (the request's headers will
 * be used) or by manually passing a collection of HTTP headers or a user-agent
 * string. The device properties will then be returned.<br/><br/>
 *
 * Usage (see the provided examples):<br/>
 * 1. Set your DeviceAtlas licence key to the class constant LICENCE_KEY in this file.<br/>
 * 2. Include this file into your application.<br/>
 * 3. To get the best results include the "DeviceAtlas Client Side Component"
 *     into your page (that is all you need to do).
 *     When available DeviceAtlasCloudClient will automatically find and use
 *     the client side properties.<br/>
 * 4. Get the data.<br/>
 * <pre>
 * try {
 *     $data = DeviceAtlasCloudClient::getDeviceData();
 * } catch (Exception $x) {
 *     // handle exceptions ...
 * }
 * </pre>
 * <pre>
 * // legacy: (class constant DEBUG = false) 
 * // the errors have to be checked manually from the returned array
 * $data = DeviceAtlasCloudClient::getDeviceData();
 * </pre>
 *
 * The returned data is as:<br/>
 * <pre>
 * $data[Client::KEY_ERROR]
 *     will exist if errors happened while fetching data
 * $data[Client::KEY_PROPERTIES]
 *     an array of device properties
 * $data[Client::KEY_USERAGENT]
 *     the user-agent string that was used to get the properties
 * $data[Client::KEY_SOURCE]
 *     shows where the data came from and is one of:
 *     DeviceAtlasCloudClient::SOURCE_FILE_CACHE
 *     DeviceAtlasCloudClient::SOURCE_CLOUD
 *     DeviceAtlasCloudClient::SOURCE_NONE
 * </pre>
 * <pre>
 * // get properties for a user-agent
 * try {
 *     $data = DeviceAtlasCloudClient::getDeviceData("user-agent-string ...");
 * } catch (Exception $x) {
 *     // handle exceptions ...
 * }
 * </pre>
 * <pre>
 * // get properties for a set of headers
 * $headers = array(
 *     "User-Agent"      => "Mozilla/5.0 (SymbianOS/9.2; ...",
 *     "X-Profile"       => "http://nds.nokia.com/uaprof/NN95_8GB-1r100.xml",
 *     "Accept"          => "text/html,text/css,multipart/mixed,application...",
 *     "Accept-Language" => "en-us,en;q=0.5",
 * );
 * try {
 *     $data = DeviceAtlasCloudClient::getDeviceData($headers);
 * } catch (Exception $x) {
 *     // handle exceptions ...
 * }
 * </pre>
 *
 * Notes:<br/>
 *   1 When in test mode and using cookie cache, changing the user-agent will
 *     not update the cookie cache and you have to manually remove the cookie.
 *     Because in real life the the cookie is on the system it represents.<br/>
 *
 *   2 It is recommended to always use file cache.
 *     Using cookie cache will cache device info on the user's browser.
 *     You can have both caches on, then if device data is found in the cookie
 *     it will be used otherwise if data is cached in the files the file cache
 *     will be used.
 *
 * @author dotMobi
 * @copyright Copyright (c) 2008-2014 by Afilias Technologies Limited (dotMobi). All rights reserved.
 */
class DeviceAtlasCloudClient {

    /////////////// BASIC SETUP ////////////////////////////////////////////////

    /** Set to your DeviceAtlas licence key */
    const LICENCE_KEY = 'cc5cf1d46655011ace834fb3e053ab9b';
    /** true = throw exceptions on all errors and failures */
    const DEBUG_MODE  = true;

    /** true:  server preference is decided by the API (faster server is preferred) 
     *  false: server preference is $SERVERS sort order (top server is preferred) **/
    const AUTO_SERVER_RANKING = true;

    /** List of cloud service provider end-points
     * The order of this list is par with end-point preference */
    public static $SERVERS = array(
        array('host' => 'region0.deviceatlascloud.com', 'port' => 80),
        array('host' => 'region1.deviceatlascloud.com', 'port' => 80),
        array('host' => 'region2.deviceatlascloud.com', 'port' => 80),
        array('host' => 'region3.deviceatlascloud.com', 'port' => 80),
    );

    /////////////// ADVANCED SETUP /////////////////////////////////////////////
    // edit these if you want to tweak behavior

    /** Build in test user agent */
    const TEST_USERAGENT        = 'Mozilla/5.0 (Linux; U; Android 2.3.3; en-gb; GT-I9100 Build/GINGERBREAD) AppleWebKit/533.1 (KHTML, like Gecko) Version/4.0 Mobile Safari/533.1';
    /** Max time (seconds) to wait for each cloud server to give service */
    const CLOUD_SERVICE_TIMEOUT = 2;
    /** Use device data which is created by the DeviceAtlas Client Side Component if exists */ 
    const USE_CLIENT_COOKIE     = true;
    /** Cache cloud results in cookies */
    const USE_COOKIE_CACHE      = false;
    /** Cache cloud results in files */
    const USE_FILE_CACHE        = true;
    /** Cache expire (for both file and cookie) 2592000 = 30 days in seconds */
    const CACHE_ITEM_EXPIRY_SEC = 2592000; 
    /** File cache > directory name */
    const CACHE_NAME            = 'deviceatlas_cache';
    /** File cache > leave as true to put cache in systems default temp directory */
    const USE_SYSTEM_TEMP_DIR   = true; 
    /** File cache > this is only used if USE_SYSTEM_TEMP_DIR is false */
    const CUSTOM_CACHE_DIR      = '/path/to/your/cache/';
    /** Cookie cache, cookie name */
    const CACHE_COOKIE_NAME     = 'DACACHE';
    /** true:  extra headers are sent with each request to the service
     *  false: only select headers which are essential for detection are sent */
    const SEND_EXTRA_HEADERS    = false;
    /** Name of the cookie created by "DeviceAtlas Client Side Component" */
    const CLIENT_COOKIE_NAME    = 'DAPROPS';
    /** When ranking servers, if a server fails more than this number phase it out */
    const AUTO_SERVER_RANKING_MAX_FAILURE  = 1;
    /** Number of requests to send when testing server latency */
    const AUTO_SERVER_RANKING_NUM_REQUESTS = 3;
    /** Server preferred list will be updated when older than this amount of minutes */
    const AUTO_SERVER_RANKING_LIFETIME     = 1440;
    /** Auto ranking = false > if top server fails it will be phased out for this amount of minutes */
    const SERVER_PHASEOUT_LIFETIME         = 1440;

    /////////////// END OF SETUP - do not edit below this point! ///////////////

    /////////////// CONSTANTS //////////////////////////////////////////////////

    /** DeviceAtlas Cloud API version */
    const API_VERSION          = '1.4';
    /** Min DeviceAtlas Cloud API required PHP version */
    const MIN_PHP_VERSION      = '5.2.1';
    /** A key of array returned by getDeviceData() - the user-agent is set to this key */
    const KEY_USERAGENT        = 'useragent';
    /** A key of array returned by getDeviceData() - the source of data is set to this key */
    const KEY_SOURCE           = 'source';
    /** A key of array returned by getDeviceData() - the error message is set to this key */
    const KEY_ERROR            = 'error';
    /** A key of array returned by getDeviceData() - the device properties are set to this key */
    const KEY_PROPERTIES       = 'properties';
    /** Device data source value set to KEY_SOURCE - device data source was cookie */
    const SOURCE_COOKIE        = 'cookie';
    /** Device data source value set to KEY_SOURCE - device data source was file cache */
    const SOURCE_FILE_CACHE    = 'file';
    /** Device data source value set to KEY_SOURCE - device data source was cloud service */
    const SOURCE_CLOUD         = 'cloud';
    /** Device data source value set to KEY_SOURCE - no device data, indicates an error */
    const SOURCE_NONE          = 'none';

    /** For API internal usage */
    const DA_HEADER_PREFIX     = 'X-DA-';
    /** For API internal usage */
    const CLIENT_COOKIE_HEADER = 'Client-Properties';
    /** For API internal usage */
    const CLOUD_PATH           = '/v1/detect/properties?licencekey=%s&useragent=%s';

    /** Action to be taken after an end-point responds: If an-endpoint response was fine */
    const FAILOVER_NOT_REQUIRED= 0;
    /** Action to be taken after an end-point responds: If the error controller returns this the fail-over mechanism must stop trying the next end-point */
    const FAILOVER_STOP        = 1;
    /** Action to be taken after an end-point responds: If the error controller returns this the fail-over mechanism must try the next end-point */
    const FAILOVER_CONTINUE    = 2;

    /**
     * A list of http-headers to be sent to the DeviceAtlas Cloud. This headers
     * are used for device detection, specially if a third party browser or a proxy
     * changes the original user-agent.
     */
    protected static $ESSENTIAL_HEADERS = array(
        'x-profile',
        'x-wap-profile',
        'x-att-deviceid',
        'accept',
        'accept-language',
    );
    /**
     * A list of http-headers which may contain the original user-agent.
     * this headers are sent to DeviceAtlas Cloud beside.
     */
    protected static $ESSENTIAL_USER_AGENT_HEADERS = array(
        'x-device-user-agent',
        'x-original-user-agent',
        'x-operamini-phone-ua',
        'x-skyfire-phone',
        'x-bolt-phone-ua',
        'device-stock-ua',
        'x-ucbrowser-ua',
        'x-ucbrowser-device-ua',
        'x-ucbrowser-device',
        'x-puffin-ua',
    );
    /**
     * A list of additional http-headers to be sent to the DeviceAtlas Cloud.
     * This headers are not sent by default. This headers can be used for
     * carrier detection and geoip.
     */
    protected static $EXTRA_HEADERS = array(
        'client-ip',
        'x-forwarded-for',
        'x-forwarded',
        'forwarded-for',
        'forwarded',
        'proxy-client-ip',
        'wl-proxy-client-ip',
    );

    public  static $rankingStatus;
    public  static $calledServers = array();
    private static $lastUsedCloudUrl;
    private static $selfAutoRanking;
    private static $headers;
    private static $fatalErrors;

    /**
     * @overload
     * Get the device data for the current request from DeviceAtlas Cloud.
     * Once data has been returned from DeviceAtlas Cloud it can be cached locally
     * to speed up subsequent requests. If device data provided by "DeviceAtlas
     * Client Side Component" exists in a cookie then cloud data will be merged
     * with the cookie data. When no parameter is provided the client's headers
     * will be used for detection.
     *
     * @return array
     * <pre>
     * {
     *      Client::KEY_USERAGENT: "UA" | null,
     *      Client::KEY_SOURCE: "data source",
     *      Client::KEY_PROPERTIES: {"propertyName": "PropertyVal",} | null,
     *      Client::KEY_ERROR: err-msg-string | null,
     * }
     * </pre>
     *
     * @overload
     * Get the device data for a set of HTTP headers from DeviceAtlas Cloud.
     * Once data has been returned from DeviceAtlas Cloud it can be cached locally
     * to speed up subsequent requests. If device data provided by "DeviceAtlas
     * Client Side Component" exists in a cookie then cloud data will be merged
     * with the cookie data. When no parameter is provided the client's headers
     * will be used for detection.
     *
     * @param array headers The HTTP headers to get properties from
     * @return array
     * <pre>
     * {
     *      Client::KEY_USERAGENT: "UA" | null,
     *      Client::KEY_SOURCE: "data source",
     *      Client::KEY_PROPERTIES: {"propertyName": "PropertyVal",} | null,
     *      Client::KEY_ERROR: err-msg-string | null,
     * }
     * </pre>
     *
     * @overload
     * Get the device data for a user-agent string from DeviceAtlas Cloud.
     * Once data has been returned from DeviceAtlas Cloud it can be cached locally
     * to speed up subsequent requests. If device data provided by "DeviceAtlas
     * Client Side Component" exists in a cookie then cloud data will be merged
     * with the cookie data. When no parameter is provided the client's headers
     * will be used for detection.
     *
     * @param string userAgent The user-agent to get properties from
     * @return array
     * <pre>
     * {
     *      Client::KEY_USERAGENT: "UA" | null,
     *      Client::KEY_SOURCE: "data source",
     *      Client::KEY_PROPERTIES: {"propertyName": "PropertyVal",} | null,
     *      Client::KEY_ERROR: err-msg-string | null,
     * }
     * </pre>
     *
     * @overload
     * Get the device data for a built in test user-agent.
     *
     * @param bool test true=get properties of a test user-agent
     * @return array
     * <pre>
     * {
     *      Client::KEY_USERAGENT: "UA" | null,
     *      Client::KEY_SOURCE: "data source",
     *      Client::KEY_PROPERTIES: {"propertyName": "PropertyVal",} | null,
     *      Client::KEY_ERROR: err-msg-string | null,
     * }
     * </pre>
     */
    public static function getDeviceData($param=false) {
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION) < 0) {
            throw new Exception(
                'DeviceAtlas Cloud Client API requires PHP version '.
                self::MIN_PHP_VERSION.' or later.'
            );
        }

        // get the user-agent
        self::$headers = null;
        if ($param === false) {
            $userAgent = isset($_SERVER['HTTP_USER_AGENT'])? $_SERVER['HTTP_USER_AGENT']: '';
        } elseif ($param === true) {
            $userAgent = self::TEST_USERAGENT;
        } else {
            $headers   = self::getNormalisedHeaders($param);
            $userAgent = isset($headers['user-agent'])? $headers['user-agent']: '';
        }

        // get the DeviceAtlas Client Side Component cookie if usage=on and exists
        $cookie = null;
        if (self::USE_CLIENT_COOKIE) {
            if (isset($_COOKIE[self::CLIENT_COOKIE_NAME])) {
                $cookie = $_COOKIE[self::CLIENT_COOKIE_NAME];
            } elseif (isset($headers['cookie'])) {
                foreach (explode(';', $headers['cookie']) as $c) {
                    $c = explode('=', $c);
                    if (self::CLIENT_COOKIE_NAME === trim($c[0])) {
                        $cookie = trim($c[1]);
                        break;
                    }
                }
            }
        }

        // get device data from cache or cloud
        self::$lastUsedCloudUrl = null;
        self::$selfAutoRanking  = 'n';     // 'y' = the API called the ranking
        self::$rankingStatus    = null;    // for debugging
        self::$calledServers    = array(); // for debugging
 
        $source = self::SOURCE_NONE;
        try {
            // check cookie cache for cached data
            if (self::USE_COOKIE_CACHE) {
                $source  = self::SOURCE_COOKIE;
                $results = self::getCookieCache($cookie);
            }
            // check file cache for cached data
            if (self::USE_FILE_CACHE && empty($results)) {
                $source  = self::SOURCE_FILE_CACHE;
                $results = self::getFileCache($userAgent, $cookie);
            }
            // use cloud service to get data
            if (empty($results)) {
                $source  = self::SOURCE_CLOUD;
                $results = self::getCloudService($userAgent, $cookie);

                // set the caches for future queries - cookie
                if (self::USE_COOKIE_CACHE && $source !== self::SOURCE_COOKIE) {
                    self::setCookieCache($cookie, $results[self::KEY_PROPERTIES]);
                }
                // set the caches for future queries - file
                if (self::USE_FILE_CACHE && $source === self::SOURCE_CLOUD) {
                    self::setFileCache(
                        $userAgent,
                        $cookie,
                        $results[self::KEY_PROPERTIES]
                    );
                }
            }
        // handle errors
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            $results[self::KEY_ERROR] = $errorMsg;
            if (php_sapi_name() !== 'cli') {
                error_log($errorMsg);
            }
            if (self::DEBUG_MODE) {
                throw new Exception($errorMsg);
            }
        }

        $results[self::KEY_SOURCE]    = $source;
        $results[self::KEY_USERAGENT] = $userAgent;
        return $results;
    }

    /**
     * normalize the HTTP header keys
     */
    protected static function getNormalisedHeaders($setHeaders=null) {
        if (!self::$headers || $setHeaders) {
            if ($setHeaders) {
                if (is_array($setHeaders)) {
                    self::$headers = array();
                    foreach ($setHeaders as $k => $v) {
                        $k = str_replace('_', '-', strtolower($k));
                        if (strpos($k, 'http-') === 0) {
                            $k = substr($k, 5);
                        }
                        self::$headers[$k] = $v;
                    }
                } else {
                    self::$headers = array('user-agent' => $setHeaders);
                }
            } else {
                if (function_exists('getallheaders')) {
                    self::$headers = array_change_key_case(getallheaders());
                } elseif (function_exists('apache_request_headers')) {
                    self::$headers = array_change_key_case(apache_request_headers());
                } else {
                    self::$headers = array();
                    foreach ($_SERVER as $k => $v) {
                        if (substr($k, 0, 5) === 'HTTP_') {
                            self::$headers[str_replace('_', '-', strtolower(substr($k, 5)))] = $v;
                        }
                    }
                }
            }
        }
        return self::$headers;
    }

    /**
     * get data from the DeviceAtlas Cloud service
     */
    private static function getCloudService($userAgent, $cookie) {
        $errors  = array();
        $servers = self::getServers();

        if (self::$fatalErrors) {
            throw new Exception(implode("\n", self::$fatalErrors));
        }

        $headers = self::prepareHeaders();
        // add the client side component cookie
        if ($cookie) {
            $headers .= self::DA_HEADER_PREFIX.self::CLIENT_COOKIE_HEADER.': '.$cookie."\r\n";
        }
        // request cloud
        foreach ($servers as $i => $server) {
            $response = self::connectCloud(
                $server,
                'GET '. // don't HTTP/1.1
                    sprintf(self::CLOUD_PATH, urlencode(self::LICENCE_KEY), urlencode($userAgent)).
                    " HTTP/1.0\r\n".
                    "Host: $server[host]:$server[port]\r\n".
                    "Accept: application/json\r\n".
                    "User-Agent: php/".self::API_VERSION."\r\n".
                    $headers.
                    "Connection: Close\r\n\r\n",
                $errors
            );

            self::$lastUsedCloudUrl = $server['host'];
            if ($response[0] === self::FAILOVER_NOT_REQUIRED) {
                if ($i > 0) {
                    // i = index of the healthy server, all servers with index less than
                    // i have failed, move them to the end of the list:
                    for ($j=0; $j<$i; $j++) {
                        array_push($servers, array_shift($servers));
                    }
                    // save the list to cache
                    // if servers is passed, it means only cache server list without ranking
                    self::__rankServers($servers);
                }
                return $response[1];

            } elseif ($response[0] === self::FAILOVER_STOP) {
                break;
            } 
        }

        throw new Exception($errors? implode("\n", $errors): 'No server has been defined.');
    }

    /**
     * connect to a cloud server and get device data, return data or null
     * @return (action, props) & errors byref
     */
    private static function connectCloud($server, $headers, &$errors) {
        self::$calledServers[] = $server['host'];

        $fp = @fsockopen(
            $server['host'], $server['port'],
            $errno,
            $errstr,
            self::CLOUD_SERVICE_TIMEOUT
        );
        if ($fp) {
            // write the request headers and get response
            fwrite($fp, $headers);
            $results = '';
            while (!feof($fp)) {
                $results .= fgets($fp, 4096);
            }
            fclose($fp);
            // extract response headers and body...
            $parts = explode("\r\n\r\n", $results, 2);
            if (count($parts) === 2) {

                $status = explode(' ', $parts[0], 3);
                if (isset($status[1]) && ((int)$status[1] / 100) === 2) {
                    $props = null;
                    if ($body=$parts[1]) {
                        $props = json_decode($body, true);
                        if (isset($props[self::KEY_PROPERTIES])) {
                            return array(self::FAILOVER_NOT_REQUIRED, $props);
                        }
                        $errorMsg = "Returned invalid data \"$body\"";
                    } else {
                        $errorMsg = 'Returned empty!';
                    }
                } else {
                    $errorMsg = $parts[1];
                }
            } else {
                $errorMsg = "Cant parse response \"$results\"";
            }
        } else {
            $errorMsg = " $errstr ($errno)";
        }

        $e = self::errorControler($server, isset($status[1])? $status[1]: null, $errorMsg);
        $errors[] = $e[1];

        return array($e[0], null);
    }

    /**
     * when an end-point returns an error this method will check it
     * @return (action, error-message)
     */
    private static function errorControler($server, $status, $msg) {
        $action = self::FAILOVER_CONTINUE;

        // Invalid licence key, Licence monthly quota exceeded
        if (stripos($msg, 'forbidden') !== false) {
            $action = self::FAILOVER_STOP;
        }

        return array(
            $action,
            'Error getting data from DeviceAtlas Cloud end-point "'.$server['host'].
            '" Reason: '.str_replace(array("\n", "\r"), ' ', strip_tags($msg))
        );
    }
    
    /**
     * prepare cloud request headers which can help the detection
     */
    protected static function prepareHeaders() {
        $headersNew = '';
        $headers    = self::getNormalisedHeaders();
        // add headers which are required for detection
        foreach (self::$ESSENTIAL_USER_AGENT_HEADERS as $k) {
            if (isset($headers[$k])) {
                $headersNew .= self::DA_HEADER_PREFIX.$k.': '.$headers[$k]."\r\n";
            }
        }
        foreach (self::$ESSENTIAL_HEADERS as $k) {
            if (isset($headers[$k])) {
                $headersNew .= self::DA_HEADER_PREFIX.$k.': '.$headers[$k]."\r\n";
            }
        }
        // opera headers
        foreach ($headers as $k => $val) {
            if (strpos($k, 'opera') !== false) {
                $headersNew .= self::DA_HEADER_PREFIX.$k.': '.$headers[$k]."\r\n";
            }
        }
        // add headers which are optional
        if (self::SEND_EXTRA_HEADERS) {
            foreach (self::$EXTRA_HEADERS as $k) {
                if (isset($headers[$k])) {
                    $headersNew .= self::DA_HEADER_PREFIX.$k.': '.$headers[$k]."\r\n";
                }
            }
            if (isset($_SERVER['REMOTE_ADDR'])) {
                $headersNew .= self::DA_HEADER_PREFIX.'remote-addr: '.
                    $_SERVER['REMOTE_ADDR']."\r\n";
            }
        }

        return $headersNew;
    }

    /**
     * COOKIE CACHE > cache device data into two cookies on the client
     * cookie cache remains in the API only to be compatible with older versions
     */
    private static function setCookieCache($cookie, $deviceData) {
        // to detect for next rounds if "DeviceAtlas Client Side Component" is changed
        $deviceData['client_props'] = md5($cookie);
        // split names and values to keep cookies smaller
        setcookie(
            self::CACHE_COOKIE_NAME.'N',
            implode(',', array_keys($deviceData)),
            time() + self::CACHE_ITEM_EXPIRY_SEC
        );
        setcookie(
            self::CACHE_COOKIE_NAME.'V',
            json_encode(array_values($deviceData)),
            time() + self::CACHE_ITEM_EXPIRY_SEC
        );
    }

    /**
     * COOKIE CACHE > if device data is cached in a cookie then fetch data
     * cookie cache remains in the API only to be compatible with older versions
     */
    private static function getCookieCache($cookie) {
        $nameKey  = self::CACHE_COOKIE_NAME.'N';
        $valueKey = self::CACHE_COOKIE_NAME.'V';
        // if cached data exists on client
        if (isset($_COOKIE[$nameKey]) && isset($_COOKIE[$valueKey])) {
            $deviceData = array_combine(
                explode(',', $_COOKIE[$nameKey]),
                json_decode($_COOKIE[$valueKey], true)
            );
            // compare current "DeviceAtlas Client Side Component" device data
            // cookie to the one when cached
            $cookieCached = isset($deviceData['client_props'])?
                $deviceData['client_props']: md5(null);
            // if "DeviceAtlas Client Side Component" device data cookie has
            // changed or exists only in one side then cache is not acceptable
            if (md5($cookie) !== $cookieCached) {
                return null;
            }
            // cleanup
            unset($deviceData['client_props'], $deviceData['generation']);
            if ($deviceData) {
                return array(self::KEY_PROPERTIES => $deviceData);
            }
        }
        return null;
    }

    /**
     * FILE CACHE > if device data is cached in a file then fetch data
     */
    private static function getFileCache($userAgent, $cookie) {
        $path = self::getDeviceCachePath($userAgent, $cookie);
        // check file modification time
        if (is_readable($path) 
            && (filemtime($path) + self::CACHE_ITEM_EXPIRY_SEC) > time()) {

            $data = @unserialize(@file_get_contents($path));
            unset($data['generation']);
            // check if cache is healthy
            if (isset($data[self::KEY_PROPERTIES])) {
                return $data;
            }
        }
        return null;
    }

    /**
     * FILE CACHE > cache device data into a file
     */
    private static function setFileCache($userAgent, $cookie, $deviceData) {
        $path      = self::getDeviceCachePath($userAgent, $cookie);
        $dirName   = dirname($path);
        $dirExists = file_exists($dirName);
        // if expected dir is a file
        if ($dirExists && !is_dir($dirName)) {
            $dirExists = false;
            @unlink($dirName);
        }
        // if directory not exists make it
        if (!$dirExists) {
            if (!@mkdir($dirName, 0755, true)) {
                throw new Exception("Unable to create cache directories $path");
            }
        }
        // write cache
        self::writeCacheToFile(
            $path,
            serialize(array(
                self::KEY_PROPERTIES => $deviceData,
            ))
        );
    }

    /**
     * lock cache file and write to it
     */
    private static function writeCacheToFile($path, $data, $errMsg=null) {
        if ($fp=@fopen($path, 'w')) {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                ftruncate($fp, 0);
                fwrite($fp, $data);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
            return true;
        }
        throw new Exception("Unable to write $errMsg cache to file $path");
    }

    /**
     * FILE CACHE > get the device data cache file-path
     */
    private static function getDeviceCachePath($userAgent, $cookie) {
        // 1- headers which are used for device detection
        $headers = self::getNormalisedHeaders();
        foreach (self::$ESSENTIAL_USER_AGENT_HEADERS as $header) {
            if (isset($headers[$header])) {
                $userAgent .= $headers[$header];
                break;
            }
        }
        // 2, 3 - the user-agent, the DeviceAtlas client side component cookie
        $key = md5($userAgent.$cookie);

        return
            self::getCacheBasePath().
            substr($key, 0, 2).            // first dir
            DIRECTORY_SEPARATOR.
            substr($key, 2, 2).            // second dir
            DIRECTORY_SEPARATOR.
            substr($key, 4, strlen($key)); // filename
    }

    /**
     * Get the directory path in which the DeviceAtlas CloudApi puts cache files
     * in (device data cache and server fail-over list).
     *
     * @return string The cache directory
     */
    public static function getCacheBasePath() {
        return
            (self::USE_SYSTEM_TEMP_DIR? sys_get_temp_dir(): self::CUSTOM_CACHE_DIR).
            DIRECTORY_SEPARATOR.
            self::CACHE_NAME.
            DIRECTORY_SEPARATOR;
    }

    /**
     * Get a list of cloud end-points and their service latencies.
     *
     * @param int numRequests=DeviceAtlasCloudClient::AUTO_SERVER_RANKING_NUM_REQUESTS
     *        The number of times to send request to an end-point per test
     * @return array Cloud end-point info {{avg:, latencies:, host:, port:},}
     */
    public static function getServersLatencies($numRequests=self::AUTO_SERVER_RANKING_NUM_REQUESTS) {
        self::$rankingStatus = 'L';
        // test servers in a randomly order
        $servers = self::$SERVERS;
        $seed    = range(0, count($servers) - 1);
        shuffle($seed);

        foreach ($seed as $i) {
            $latencies = self::getServerLatency($servers[$i], $numRequests);
            if (self::$fatalErrors) {
                return null;
            }
            $servers[$i]['latencies'] = $latencies;
            $servers[$i]['avg']       = in_array(-1, $latencies)?
                -1: (array_sum($servers[$i]['latencies']) / $numRequests);
        }

        return $servers;
    }

    /**
     * send request(s) to a DA cloud server and return the latencies
     */
    private static function getServerLatency($server, $numRequests) {
        $failures  = 0;
        $latencies = array();
        self::$fatalErrors = null;
        // common header parts
        $headers = 
            'GET '.sprintf(self::CLOUD_PATH, urlencode(self::LICENCE_KEY), '')." HTTP/1.0\r\n".
            "Host: $server[host]:$server[port]\r\n".
            "Accept: application/json\r\n".
            "User-Agent: PHP/".self::API_VERSION."\r\n".
            self::DA_HEADER_PREFIX.'Latency-Checker: ';
        // only the first request will send the settings
        $configs = 
            self::$selfAutoRanking.';'.
            (self::AUTO_SERVER_RANKING? 'y': 'n').';'.
            self::CLOUD_SERVICE_TIMEOUT.';'.
            self::AUTO_SERVER_RANKING_MAX_FAILURE.';'.
            self::AUTO_SERVER_RANKING_NUM_REQUESTS.';'.
            self::AUTO_SERVER_RANKING_LIFETIME.';'.
            self::SERVER_PHASEOUT_LIFETIME.
            "\r\n";
        // ignore the first call because it can take an unreal long time
        for ($i=0; $i < $numRequests+1 && $failures < self::AUTO_SERVER_RANKING_MAX_FAILURE; ++$i) {
            $start = microtime(true);
            try {
                $errors = array();
                $response = self::connectCloud(
                    $server,
                    $headers.($i===0? $configs: "$i\r\n")."Connection: Close\r\n\r\n",
                    $errors
                );

                if ($response[0] === self::FAILOVER_NOT_REQUIRED) {
                    if ($i) {
                        $latencies[] = (microtime(true) - $start) * 1000;
                    }
                    continue;
                } elseif ($response[0] === self::FAILOVER_STOP) {
                    // licence errors which are found at ranking, to stop any further cloud call
                    self::$fatalErrors = $errors;
                    break;
                }
            } catch (Exception $e) { }
            
            ++$failures;
            $latencies[] = -1;
        }

        return $latencies;
    }

    /**
     * Get DeviceAtlas cloud end-point list in the same order used by the API.
     * If auto-ranking is on then the ranked end-point list is returned otherwise
     * the manual or the default fail-over list is returned.</br>
     * If auto-ranking is on and the end-point list cache is invalid or out of date
     * then the end-points will be ranked and cached first.
     *
     * @return array DeviceAtlas cloud End-pont list {host: server-address, port: server-port}
     */
    public static function getServers() {
        if (self::AUTO_SERVER_RANKING) {
            self::$selfAutoRanking = 'y';
            // if possible fetch server ranked list from cache
            $path = self::getCacheBasePath().'servers';
            if (is_readable($path)
                && (!self::AUTO_SERVER_RANKING_LIFETIME || filemtime($path) >
                   (time() - (60 + rand(-5, 5)) * self::AUTO_SERVER_RANKING_LIFETIME))) {

                $servers = @json_decode(@file_get_contents($path), true);
                if (is_array($servers) && $servers) {
                    self::$rankingStatus = 'A';
                    return $servers;
                }
            }
            // no or expired server ranked list - rank servers
            $servers = self::__rankServers();
            if ($servers) {
                return $servers;
            }
        }
        // check if manual list is cached or not
        // manual list is cached and used for some time when top server fails
        $path = self::getCacheBasePath().'servers-manual';
        if (is_readable($path)
            && self::SERVER_PHASEOUT_LIFETIME
            && filemtime($path) >
               time() - (60 + rand(-5, 5)) * self::SERVER_PHASEOUT_LIFETIME) {

            $servers = @json_decode(@file_get_contents($path), true);
            if (is_array($servers) && $servers) {
                self::$rankingStatus = 'M';
                return $servers;
            }
        }
        // default list
        self::$rankingStatus = 'D';
        return self::$SERVERS;
    }

    /**
     * If auto-ranking is on then rank the DeviceAtlas cloud end-points and put in cache.
     *
     * @return array the ranked end-point list or null=did not rank successfully
     * @throws Exception when unable to create cache file or directory
     */
    public static function rankServers() {
        return self::__rankServers();
    }

    /**
     * servers=null rank and cache end-points
     * servers=array cache servers (for updating the manual fail-over list)
     */
    protected static function __rankServers($servers=array()) {
        if (!$servers && !self::AUTO_SERVER_RANKING) {
            return null;
        }
        // proceed only if cache directory exists or is writable
        $cachePath = self::getCacheBasePath();
        if (!file_exists($cachePath)) {
            if (!@mkdir($cachePath, 0755, true)) {
                throw new Exception("Unable to create cache directory $cachePath");
            }
        }
        $cacheFile = self::AUTO_SERVER_RANKING? $cachePath.'servers': $cachePath.'servers-manual';
        // get the ranked servers and pick the healthy items
        if (!$servers) {
            // proceed only if cache file is writable
            if ($fp=@fopen($cacheFile.'w', 'w')) {
                fclose($fp);

                $serverLatencies = self::getServersLatencies();
                if (!$serverLatencies) {
                    return null;
                }

                foreach ($serverLatencies as $server) {
                    if ($server['avg'] !== -1) {
                        $servers[] = $server;
                    }
                }
            } else {
                throw new Exception("Unable to write end-point cache to file $cacheFile");
            }
            // if server list is empty then extend the cache timeout
            if (!$servers) {
                touch($cacheFile, time());
                return null;
            }
            // sort by latency ASC
            usort($servers, array('DeviceAtlasCloudClient', 'rankSort'));
        }
        // write to cache
        $ok = self::writeCacheToFile($cacheFile, json_encode($servers), 'server list');
        // return list only if ranked
        return $ok && self::AUTO_SERVER_RANKING? $servers: null;
    }

    /**
     * sort by latency
     */
    private static function rankSort($a, $b) {
        $a = $a['avg'];
        $b = $b['avg'];
        return $a == $b? 0: ($a < $b? -1: 1);
    }

    /**
     * Get the URL of the last DeviceAtlas cloud end-point which was called to
     * get device properties.
     *
     * @return string The end-point URL, null=no call to a cloud end-point was made probably because
     * the data was fetched from cache.
     */
    public static function getCloudUrl() {
        return self::$lastUsedCloudUrl;
    }
}
