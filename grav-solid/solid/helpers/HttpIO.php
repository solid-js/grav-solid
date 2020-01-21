<?php
namespace Solid\helpers;

/**
 * TODO : CURL ...
 */

class HttpIO
{
    /**
     * Get parameters sent by client.
     * Will read json through POST for classic rest.
     * Will fallback to classic post forms otherwise.
     * TODO : Mixed part type form
     * @param array $override Parameters to override
     * @param bool $allowGET Allow parameters to be gathered from $_GET
     * @param bool $allowPOST Allow parameters to be gathered from $_POST
     * @return array|mixed
     */
    public static function request ( $override = [], $allowGET = true, $allowPOST = true )
    {
        // Get data as json from post
        $postData = file_get_contents('php://input');
        $data = json_decode($postData, true);

        // If there is no json in post
        if ( is_null($data) )
        {
            // Clone post fields
            $data = [];

            // From GET
            if ( $allowGET )
            {
                foreach ( $_GET as $key => $value )
                    $data[$key] = $value;
            }

            // From POST
            if ( $allowPOST )
            {
                foreach ( $_POST as $key => $value )
                    $data[$key] = $value;
            }
        }

        // Add overridden parameters
        $data += $override;
        return $data;
    }

    /**
     * Respond data as JSON for client.
     * @param mixed $data object to send back to the client.
     * @param int $httpCode HTTP response code. Default is 200.
     * @return string|array
     */
    public static function response ( $data, $httpCode = 200 )
    {
        http_response_code( $httpCode );
        header('Content-type: application/json');
        return is_null( $data ) ? NULL : json_encode( $data );
    }

    /**
     * Send headers to tell client to disable cache on this request
     */
    public static function sendNoCacheHeaders ()
    {
        header('Expires: Sun, 01 Jan 2000 00:00:00 GMT');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
    }

    /**
     * Send 404 headers and exit script if needed
     * @param bool $exit quit script
     * @param bool $html push some HTML after headers
     */
    public static function sendNotFoundHeader ( $exit = false, $html = null )
    {
        header('HTTP/1.1 404 Not Found');
        if (!is_null($html)) print $html;
        $exit && exit;
    }
}