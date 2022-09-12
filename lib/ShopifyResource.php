<?php
/**
 * Created by PhpStorm.
 * @author Tareq Mahmood <tareqtms@yahoo.com>
 * Created at 8/17/16 3:14 PM UTC+06:00
 *
 * @see https://help.shopify.com/api/reference Shopify API Reference
 */

namespace PHPShopify;

use PHPShopify\Exception\ApiException;
use PHPShopify\Exception\SdkException;
use PHPShopify\Exception\CurlException;
use Psr\Http\Message\ResponseInterface;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Shopify API SDK Base Class
|--------------------------------------------------------------------------
|
| This class handles get, post, put, delete and any other custom actions for the API
|
*/
abstract class ShopifyResource
{
    /**
     * HTTP request headers
     *
     * @var array
     */
    protected $httpHeaders = array();

    /**
     * HTTP response headers of last executed request
     *
     * @var array
     */
    public static $lastHttpResponseHeaders = array();

    /**
     * The base URL of the API Resource (excluding the '.json' extension).
     *
     * Example : https://myshop.myshopify.com/admin/products
     *
     * @var string
     */
    protected $resourceUrl;

    /**
     * Key of the API Resource which is used to fetch data from request responses
     *
     * @var string
     */
    protected $resourceKey;

    /**
     * List of child Resource names / classes
     *
     * If any array item has an associative key => value pair, value will be considered as the resource name
     * (by which it will be called) and key will be the associated class name.
     *
     * @var array
     */
    protected $childResource = array();

    /**
     * If search is enabled for the resource
     *
     * @var boolean
     */
    public $searchEnabled = false;

    /**
     * If count is enabled for the resource
     *
     * @var boolean
     */
    public $countEnabled = true;

    /**
     * If the resource is read only. (No POST / PUT / DELETE actions)
     *
     * @var boolean
     */
    public $readOnly = false;

    /**
     * List of custom GET / POST / PUT / DELETE actions
     *
     * Custom actions can be used without calling the get(), post(), put(), delete() methods directly
     * @example: ['enable', 'disable', 'remove','default' => 'makeDefault']
     * Methods can be called like enable(), disable(), remove(), makeDefault() etc.
     * If any array item has an associative key => value pair, value will be considered as the method name
     * and key will be the associated path to be used with the action.
     *
     * @var array $customGetActions
     * @var array $customPostActions
     * @var array $customPutActions
     * @var array $customDeleteActions
     */
    protected $customGetActions = array();
    protected $customPostActions = array();
    protected $customPutActions = array();
    protected $customDeleteActions = array();

    /**
     * The ID of the resource
     *
     * If provided, the actions will be called against that specific resource ID
     *
     * @var integer
     */
    public $id;

    /**
     * Create a new Shopify API resource instance.
     *
     * @param integer $id
     * @param string $parentResourceUrl
     *
     * @throws SdkException if Either AccessToken or ApiKey+Password Combination is not found in configuration
     */

    /**
     * Response Header Link, used for pagination
     * @see: https://help.shopify.com/en/api/guides/paginated-rest-results?utm_source=exacttarget&utm_medium=email&utm_campaign=api_deprecation_notice_1908
     * @var string $nextLink
     */
    private $nextLink = null;

    /**
     * Response Header Link, used for pagination
     * @see: https://help.shopify.com/en/api/guides/paginated-rest-results?utm_source=exacttarget&utm_medium=email&utm_campaign=api_deprecation_notice_1908
     * @var string $prevLink
     */
    private $prevLink = null;

    public function __construct($id = null, $parentResourceUrl = '')
    {
        $this->id = $id;

        $config = ShopifySDK::$config;

        $this->resourceUrl = ($parentResourceUrl ? $parentResourceUrl . '/' :  $config['ApiUrl']) . $this->getResourcePath() . ($this->id ? '/' . $this->id : '');

        if (isset($config['AccessToken'])) {
            $this->httpHeaders['X-Shopify-Access-Token'] = $config['AccessToken'];
        } elseif (!isset($config['ApiKey']) || !isset($config['Password'])) {
            throw new SdkException("Either AccessToken or ApiKey+Password Combination (in case of private API) is required to access the resources. Please check SDK configuration!");
        }

        if (isset($config['ShopifyApiFeatures'])) {
            foreach($config['ShopifyApiFeatures'] as $apiFeature) {
                $this->httpHeaders['X-Shopify-Api-Features'] = $apiFeature;
            }
        }
    }

    /**
     * Return ShopifyResource instance for the child resource.
     *
     * @example $shopify->Product($productID)->Image->get(); //Here Product is the parent resource and Image is a child resource
     * Called like an object properties (without parenthesis)
     *
     * @param string $childName
     *
     * @return ShopifyResource
     */
    public function __get($childName)
    {
        return $this->$childName();
    }

    /**
     * Return ShopifyResource instance for the child resource or call a custom action for the resource
     *
     * @example $shopify->Product($productID)->Image($imageID)->get(); //Here Product is the parent resource and Image is a child resource
     * Called like an object method (with parenthesis) optionally with the resource ID as the first argument
     * @example $shopify->Discount($discountID)->enable(); //Calls custom action enable() on Discount resource
     *
     * Note : If the $name starts with an uppercase letter, it's considered as a child class and a custom action otherwise
     *
     * @param string $name
     * @param array $arguments
     *
     * @throws SdkException if the $name is not a valid child resource or custom action method.
     *
     * @return mixed / ShopifyResource
     */
    public function __call($name, $arguments)
    {
        //If the $name starts with an uppercase letter, it's considered as a child class
        //Otherwise it's a custom action
        if (ctype_upper($name[0])) {
            //Get the array key of the childResource in the childResource array
            $childKey = array_search($name, $this->childResource);

            if ($childKey === false) {
                throw new SdkException("Child Resource $name is not available for " . $this->getResourceName());
            }

            //If any associative key is given to the childname, then it will be considered as the class name,
            //otherwise the childname will be the class name
            $childClassName = !is_numeric($childKey) ? $childKey : $name;

            $childClass = __NAMESPACE__ . "\\" . $childClassName;

            //If first argument is provided, it will be considered as the ID of the resource.
            $resourceID = !empty($arguments) ? $arguments[0] : null;


            $api = new $childClass($resourceID, $this->resourceUrl);

            return $api;
        } else {
            $actionMaps = array(
                'post'  =>  'customPostActions',
                'put'   =>  'customPutActions',
                'get'   =>  'customGetActions',
                'delete'=>  'customDeleteActions',
            );

            //Get the array key for the action in the actions array
            foreach ($actionMaps as $httpMethod => $actionArrayKey) {
                $actionKey = array_search($name, $this->$actionArrayKey);
                if ($actionKey !== false) break;
            }

            if ($actionKey === false) {
                throw new SdkException("No action named $name is defined for " . $this->getResourceName());
            }

            //If any associative key is given to the action, then it will be considered as the method name,
            //otherwise the action name will be the method name
            $customAction = !is_numeric($actionKey) ? $actionKey : $name;


            //Get the first argument if provided with the method call
            $methodArgument = !empty($arguments) ? $arguments[0] : array();

            //Url parameters
            $urlParams = array();
            //Data body
            $dataArray = array();

            //Consider the argument as url parameters for get and delete request
            //and data array for post and put request
            if ($httpMethod == 'post' || $httpMethod == 'put') {
                $dataArray = $methodArgument;
            } else {
                $urlParams =  $methodArgument;
            }

            $url = $this->generateUrl($urlParams, $customAction);

            if ($httpMethod == 'post' || $httpMethod == 'put') {
                return $this->$httpMethod($dataArray, $url, false);
            } else {
                return $this->$httpMethod($dataArray, $url);
            }
        }
    }

    /**
     * Get the resource name (or the class name)
     *
     * @return string
     */
    public function getResourceName()
    {
        return substr(get_called_class(), strrpos(get_called_class(), '\\') + 1);
    }

    /**
     * Get the resource key to be used for while sending data to the API
     *
     * Normally its the same as $resourceKey, when it's different, the specific resource class will override this function
     *
     * @return string
     */
    public function getResourcePostKey()
    {
        return $this->resourceKey;
    }

    /**
     * Get the pluralized version of the resource key
     *
     * Normally its the same as $resourceKey appended with 's', when it's different, the specific resource class will override this function
     *
     * @return string
     */
    protected function pluralizeKey()
    {
        return $this->resourceKey . 's';
    }

    /**
     * Get the resource path to be used to generate the api url
     *
     * Normally its the same as the pluralized version of the resource key,
     * when it's different, the specific resource class will override this function
     *
     * @return string
     */
    protected function getResourcePath()
    {
        return $this->pluralizeKey();
    }

    /**
     * Generate the custom url for api request based on the params and custom action (if any)
     *
     * @param array $urlParams
     * @param string $customAction
     *
     * @return string
     */
    public function generateUrl($urlParams = array(), $customAction = null)
    {
        return $this->resourceUrl . ($customAction ? "/$customAction" : '') . '.json' . (!empty($urlParams) ? '?' . http_build_query($urlParams) : '');
    }

    /**
     * Generate a HTTP GET request and return results as an array
     *
     * @param array $urlParams Check Shopify API reference of the specific resource for the list of URL parameters
     * @param string $url
     * @param string $dataKey Keyname to fetch data from response array
     *
     * @uses HttpRequestJson::get() to send the HTTP request
     *
     * @throws ApiException if the response has an error specified
     * @throws CurlException if response received with unexpected HTTP code.
     *
     * @return array
     */
    public function get($urlParams = array(), $url = null, $dataKey = null)
    {
        if (!$url) $url  = $this->generateUrl($urlParams);

        $this->throttle();

        $response = HttpRequestJson::get($url, $this->httpHeaders);

        if (!$dataKey) $dataKey = $this->id ? $this->resourceKey : $this->pluralizeKey();

        $this->log($url, __FUNCTION__, $urlParams, $response);

        return $this->processResponse($response, $dataKey);

    }

    /**
     * Get count for the number of resources available
     *
     * @param array $urlParams Check Shopify API reference of the specific resource for the list of URL parameters
     *
     * @throws SdkException
     * @throws ApiException if the response has an error specified
     * @throws CurlException if response received with unexpected HTTP code.
     *
     * @return integer
     */
    public function count($urlParams = array())
    {
        if (!$this->countEnabled) {
            throw new SdkException("Count is not available for " . $this->getResourceName());
        }

        $url = $this->generateUrl($urlParams, 'count');

        return $this->get(array(), $url, 'count');
    }

    /**
     * Search within the resouce
     *
     * @param mixed $query
     *
     * @throws SdkException if search is not enabled for the resouce
     * @throws ApiException if the response has an error specified
     * @throws CurlException if response received with unexpected HTTP code.
     *
     * @return array
     */
    public function search($query)
    {
        if (!$this->searchEnabled) {
            throw new SdkException("Search is not available for " . $this->getResourceName());
        }

        if (!is_array($query)) $query = array('query' => $query);

        $url = $this->generateUrl($query, 'search');

        return $this->get(array(), $url);
    }

    /**
     * Call POST method to create a new resource
     *
     * @param array $dataArray Check Shopify API reference of the specific resource for the list of required and optional data elements to be provided
     * @param string $url
     * @param bool $wrapData
     *
     * @uses HttpRequestJson::post() to send the HTTP request
     *
     * @throws ApiException if the response has an error specified
     * @throws CurlException if response received with unexpected HTTP code.
     *
     * @return array
     */
    public function post($dataArray, $url = null, $wrapData = true)
    {
        if (!$url) $url = $this->generateUrl();

        if ($wrapData && !empty($dataArray)) $dataArray = $this->wrapData($dataArray);

        $this->throttle();

        $response = HttpRequestJson::post($url, $dataArray, $this->httpHeaders);

        $this->log($url, __FUNCTION__, $dataArray, $response);

        return $this->processResponse($response, $this->resourceKey);
    }

    /**
     * Call PUT method to update an existing resource
     *
     * @param array $dataArray Check Shopify API reference of the specific resource for the list of required and optional data elements to be provided
     * @param string $url
     * @param bool $wrapData
     *
     * @uses HttpRequestJson::put() to send the HTTP request
     *
     * @throws ApiException if the response has an error specified
     * @throws CurlException if response received with unexpected HTTP code.
     *
     * @return array
     */
    public function put($dataArray, $url = null, $wrapData = true)
    {

        if (!$url) $url = $this->generateUrl();

        if ($wrapData && !empty($dataArray)) $dataArray = $this->wrapData($dataArray);

        $this->throttle();

        $response = HttpRequestJson::put($url, $dataArray, $this->httpHeaders);

        $this->log($url, __FUNCTION__, $dataArray, $response);

        return $this->processResponse($response, $this->resourceKey);
    }

    /**
     * Call DELETE method to delete an existing resource
     *
     * @param array $urlParams Check Shopify API reference of the specific resource for the list of URL parameters
     * @param string $url
     *
     * @uses HttpRequestJson::delete() to send the HTTP request
     *
     * @throws ApiException if the response has an error specified
     * @throws CurlException if response received with unexpected HTTP code.
     *
     * @return array an empty array will be returned if the request is successfully completed
     */
    public function delete($urlParams = array(), $url = null)
    {
        if (!$url) $url = $this->generateUrl($urlParams);

        $this->throttle();

        $response = HttpRequestJson::delete($url, $this->httpHeaders);

        $this->log($url, __FUNCTION__, $urlParams, $response);

        return $this->processResponse($response);
    }

    /**
     * Wrap data array with resource key
     *
     * @param array $dataArray
     * @param string $dataKey
     *
     * @return array
     */
    protected function wrapData($dataArray, $dataKey = null)
    {
        if (!$dataKey) $dataKey = $this->getResourcePostKey();

        return array($dataKey => $dataArray);
    }

    /**
     * Convert an array to string
     *
     * @param array $array
     *
     * @internal
     *
     * @return string
     */
    protected function castString($array)
    {
        if ( ! is_array($array)) return (string) $array;

        $string = '';
        $i = 0;
        foreach ($array as $key => $val) {
            //Add values separated by comma
            //prepend the key string, if it's an associative key
            //Check if the value itself is another array to be converted to string
            $string .= ($i === $key ? '' : "$key - ") . $this->castString($val) . ', ';
            $i++;
        }

        //Remove trailing comma and space
        $string = rtrim($string, ', ');

        return $string;
    }

    /**
     * Process the request response
     *
     * @param array $responseArray Request response in array format
     * @param string $dataKey Keyname to fetch data from response array
     *
     * @throws ApiException if the response has an error specified
     * @throws CurlException if response received with unexpected HTTP code.
     *
     * @return array
     */
    public function processResponse($responseArray, $dataKey = null, $request = [])
    {
        self::$lastHttpResponseHeaders = CurlRequest::$lastHttpResponseHeaders;

        if ($responseArray === null) {
            //Something went wrong, Checking HTTP Codes
            $httpOK = 200; //Request Successful, OK.
            $httpCreated = 201; //Create Successful.
            $httpDeleted = 204; //Delete Successful
            $httpOther = 303; //See other (headers).

            //should be null if any other library used for http calls
            $httpCode = CurlRequest::$lastHttpCode;

            if ($httpCode == $httpOther && array_key_exists('location', self::$lastHttpResponseHeaders)) {
                return ['location' => self::$lastHttpResponseHeaders['location']];
            }

            if ($httpCode != null && $httpCode != $httpOK && $httpCode != $httpCreated && $httpCode != $httpDeleted) {
                throw new Exception\CurlException("Request failed with HTTP Code $httpCode.", $httpCode);
            }
        }

        $lastResponseHeaders = CurlRequest::$lastHttpResponseHeaders;
        $this->getLinks($lastResponseHeaders);

        if (isset($responseArray['errors'])) {
            $message = $this->castString($responseArray['errors']);

            //check account already enabled or not
            if($message=='account already enabled'){
                return array('account_activation_url'=>false);
            }
            
            throw new ApiException($message, CurlRequest::$lastHttpCode);
        }

        if ($dataKey && isset($responseArray[$dataKey])) {
            return $responseArray[$dataKey];
        } else {
            return $responseArray;
        }
    }

    public function getLinks($responseHeaders){
        $this->nextLink = $this->getLink($responseHeaders,'next');
        $this->prevLink = $this->getLink($responseHeaders,'previous');
    }

    public function getLink($responseHeaders, $type='next'){

        if(array_key_exists('x-shopify-api-version', $responseHeaders)
            && $responseHeaders['x-shopify-api-version'] < '2019-07'){
            return null;
        }

        if(!empty($responseHeaders['link'])) {
            if (stristr($responseHeaders['link'], '; rel="'.$type.'"') > -1) {
                $headerLinks = explode(',', $responseHeaders['link']);
                foreach ($headerLinks as $headerLink) {
                    if (stristr($headerLink, '; rel="'.$type.'"') === -1) {
                        continue;
                    }

                    $pattern = '#<(.*?)>; rel="'.$type.'"#m';
                    preg_match($pattern, $headerLink, $linkResponseHeaders);
                    if ($linkResponseHeaders) {
                        return $linkResponseHeaders[1];
                    }
                }
            }
        }

        return null;
    }

    public function getPrevLink(){
        return $this->prevLink;
    }

    public function getNextLink(){
        return $this->nextLink;
    }

    public function getUrlParams($url) {
        if ($url) {
            $parts = parse_url($url);
            return $parts['query'];
        }
        return '';
    }

    public function getNextPageParams(){
        $nextPageParams = [];
        parse_str($this->getUrlParams($this->getNextLink()), $nextPageParams);
        return $nextPageParams;
    }

    public function getPrevPageParams(){
        $nextPageParams = [];
        parse_str($this->getUrlParams($this->getPrevLink()), $nextPageParams);
        return $nextPageParams;
    }

    public function setPriority($isPriority=true) {
        ShopifySDK::$config['Throttle']['priority']['enabled'] = ($isPriority ? true : false);
    }

    private function getPriority() {
        return ShopifySDK::$config['Throttle']['priority'];
    }

    public function isPriority() {
        $priority = $this->getPriority(ShopifySDK::$config['Throttle']['priority']);
        return $priority['enabled'];
    }

    private function checkRate($apiName, $apiAvailable=0, $apiMax=0) {
        $throttleConfig = ShopifySDK::$config['Throttle'];
        if (!$throttleConfig || empty($apiAvailable) || empty($apiMax)) {
            return false;
        }

        $percentAvailable = $apiAvailable / $apiMax * 100;

        $cacheKey = ShopifySDK::$config['ShopName'].'_shopify_api_'.strtolower($apiName).'_throttle';

        $throttle = isset($throttleConfig['threshold']) && $percentAvailable < $throttleConfig['threshold'];
        Cache::put($cacheKey, $throttle, 60);

        $throttlePriority = isset($throttleConfig['priority']['threshold']) && $percentAvailable < $throttleConfig['priority']['threshold'];
        Cache::put($cacheKey.'_priority', $throttlePriority, 60);

        return $throttle;
    }

    public function isThrottled($apiName='REST') {
        $cacheKey = ShopifySDK::$config['ShopName'].'_shopify_api_'.strtolower($apiName).'_throttle';
        if ($this->isPriority()) {
            $cacheKey .= '_priority';
        }
        return Cache::get($cacheKey);
    }

    public function throttle($apiName='REST') {
        if ($this->isThrottled($apiName)) {
            $priority = $this->isPriority();
            $throttle = $priority ? ShopifySDK::$config['Throttle']['priority']['milliseconds'] : ShopifySDK::$config['Throttle']['milliseconds'];
            if ((int)$throttle != $throttle) {
                $throttle = $priority ? 250 : 500;
            }
            if (isset(ShopifySDK::$config['Log'])) {
                $log = ShopifySDK::$config['Log']; 
                $logEntry = 'Throttling Shopify '.$apiName.' API for '.$throttle.'ms';
                @file_put_contents($log['path'], '[' . $log['date'] . '] '. $logEntry . PHP_EOL, FILE_APPEND);
            }
            usleep($throttle * 1000);
        }
    }

    protected function log($requestUrl, $requestType, $requestData, $response, $graphQL = false) {
        try {
            $config = isset(ShopifySDK::$config['Log']) ? ShopifySDK::$config['Log'] : false;
            if (!$config) {
                return false;
            }

            $responseHeaders = CurlRequest::$lastHttpResponseHeaders;

            if ($graphQL) {
                $cost = [];
                $maximum = 2000;
                $retryAfter = 0;
                if (!empty($response['extensions']['cost'])) {
                    $cost = $response['extensions']['cost'];
                    $available = $cost['throttleStatus']['currentlyAvailable'];
                    $maximum = $cost['throttleStatus']['maximumAvailable'];
                }
            } else {
                $use = 0;
                $maximum = 80;
                if (!empty($responseHeaders['x-shopify-shop-api-call-limit'])) {
                    list($use, $maximum) = explode('/', $responseHeaders['x-shopify-shop-api-call-limit']);
                }
                $retryAfter = !empty($responseHeaders['retry-after']) ? $responseHeaders['retry-after'] : 0;
                $available = $maximum - $use;
            }

            $callers = @debug_backtrace();
            $caller = (isset($callers[2]['file']) ? str_replace(str_replace('public', '', getcwd()), '', $callers[2]['file']) : '').'+'.(isset($callers[2]['line']) ? $callers[2]['line'] : '');

            $errors = !empty($response['errors']) ? $this->castString($response['errors']) : '';
            $apiName = $graphQL ? 'GraphQL' : 'REST';
            $requestType = strtoupper($requestType);
            $responseCode = CurlRequest::$lastHttpCode;

            $this->checkRate($apiName, $available, $maximum);

            if (stripos($config['method'], 'db') !== false && !empty($config['db'])) {
                $creds = $config['db'];
                $db = @pg_pconnect('host='.$creds['host'].' port='.$creds['port'].' dbname='.$creds['database'].' user='.$creds['username'].' password='.$creds['password']);
                if (!$db) {
                    return false;
                }

                $columns = [
                    'api_name' => 'Shopify '.$apiName,
                    'request_type' => $requestType,
                    'request_url' => $requestUrl,
                    'response_code' => $responseCode,
                    'currently_available' => $available,
                    'maximum_available' => $maximum,
                    'retry_after' => $retryAfter,
                    'restore_rate' => 0,
                    'request_data' => '',
                    'response_data' => '',
                    'response_headers' => '',
                    //'caller' => $caller,
                ];
                if ($graphQL) {
                    if (!empty($cost['throttleStatus']['restoreRate'])) {
                        $columns['restore_rate'] = $cost['throttleStatus']['restoreRate'];
                    }
                    if (!empty($cost['requestedQueryCost'])) {
                        $columns['requested_query_cost'] = $cost['requestedQueryCost'];
                    }
                    if (!empty($cost['actualQueryCost'])) {
                        $columns['actual_query_cost'] = $cost['actualQueryCost'];
                    }
                }
                if ($config['verbose']) {
                    $columns['request_data'] = json_encode($requestData);
                    $columns['response_data'] = json_encode($response);
                    $columns['response_headers'] = json_encode($responseHeaders);
                }
                foreach($columns as $c => $v) {
                    $columns[$c] = @pg_escape_string($v);
                }

                $query = 'INSERT INTO '.$config['db_table'].' ('.implode(', ', array_keys($columns)).') VALUES (\''.implode('\', \'', array_values($columns)).'\')';
                
                $result = @pg_query($db, $query);
                if (!$result) {
                    return false;
                }
            }

            if (stripos($config['method'], 'file') !== false && !empty($config['path'])) {
                $logEntry = [
                    $apiName,
                    $caller,
                    $requestType,
                    $requestUrl,
                    $responseCode,
                    $available,
                    $maximum,
                    ($retryAfter === 0 ? '' : $retryAfter),
                    $errors,
                ];
                if ($config['verbose']) {
                    $logEntry[] = json_encode($requestData);
                    $logEntry[] = json_encode($response);
                    $logEntry[] = json_encode($responseHeaders);
                }
                @file_put_contents($config['path'], '[' . $config['date'] . '] '.implode(',', $logEntry) . PHP_EOL, FILE_APPEND);
            }

        } catch (Exception $e) {}
    }
}
