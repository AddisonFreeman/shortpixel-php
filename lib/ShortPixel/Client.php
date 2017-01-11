<?php

namespace ShortPixel;


class Client {

    private $options;
    public static function API_URL() {
        return "https://api.shortpixel.com";
    }
    public static function API_ENDPOINT() {
        return self::API_URL() . "/v2/reducer.php";
    }

    public static function API_UPLOAD_ENDPOINT() {
        //return self::API_URL() . "/v2/post-reducer-dev.php";
        return self::API_URL() . "/v2/post-reducer.php";
    }

    public static function userAgent() {
        $curl = curl_version();
        return "ShortPixel/" . VERSION . " PHP/" . PHP_VERSION . " curl/" . $curl["version"];
    }

    private static function caBundle() {
        return dirname(__DIR__) . "/data/shortpixel.crt";
    }

    function __construct() {
        $this->options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_BINARYTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CAINFO => self::caBundle(),
            CURLOPT_SSL_VERIFYPEER => false, //TODO true
            CURLOPT_SSL_VERIFYHOST => false, //TODO remove
            CURLOPT_USERAGENT => self::userAgent(),
        );
    }

    /**
     * Does the CURL request to the ShortPixel API
     * @param $method 'post' or 'get'
     * @param null $body - the POST fields
     * @param array $header - HTTP headers
     * @return array - metadata from the API
     * @throws ConnectionException
     */
    function request($method, $body = NULL, $header = array()){
        foreach($body as $key => $val) {
            if($val === null) {
                unset($body[$key]);
            }
        }

        $retUrls = array("body" => array(), "headers" => array(), "fileMappings" => array());
        $retPend = array("body" => array(), "headers" => array(), "fileMappings" => array());
        $retFiles = array("body" => array(), "headers" => array(), "fileMappings" => array());

        if(isset($body["urllist"])) {
            $retUrls = $this->requestInternal($method, $body, $header);
        }
        if(isset($body["pendingURLs"])) {
            unset($body["urllist"]);
            //some files might have already been processed as relaunches in the given max time
            foreach($retUrls["body"] as $url) {
                //first remove it from the files list as the file was uploaded properly
                if($url->Status->Code != -102 && $url->Status->Code != -106) {
                    // TODO check - should not enter here anymore
                    $notExpired[] = $url;
                    if(!isset($body["pendingURLs"][$url->OriginalURL])) {
                        $lala = "cucu";
                    } else
                    $unsetPath = $body["pendingURLs"][$url->OriginalURL];
                    if(($key = array_search($unsetPath, $body["files"])) !== false) {
                        unset($body["files"][$key]);
                    }
                }
                //now from the pendingURLs if we already have an answer with urllist
                if(isset($body["pendingURLs"][$url->OriginalURL])) {
                    $retUrls["fileMappings"][$url->OriginalURL] = $body["pendingURLs"][$url->OriginalURL];
                    unset($body["pendingURLs"][$url->OriginalURL]);
                }
            }
            if(count($body["pendingURLs"])) {
                $retPend = $this->requestInternal($method, $body, $header);
                if(isset($body["files"])) {
                    $notExpired = array();
                    foreach($retPend['body'] as $detail) {
                        if($detail->Status->Code != -102) { // -102 is expired, means we need to resend the image through post
                            $notExpired[] = $detail;
                            $unsetPath = $body["pendingURLs"][$detail->OriginalURL];
                            if(($key = array_search($unsetPath, $body["files"])) !== false) {
                                unset($body["files"][$key]);
                            }
                        }
                    }
                    $retPend['body'] = $notExpired;
                }
            }
        }
        if (isset($body["files"]) && count($body["files"])) {
            unset($body["pendingURLs"]);
            $retFiles = $this->requestInternal($method, $body, $header);
        }

        $body = isset($retUrls["body"]->Status) ? $retUrls["body"] : (isset($retPend["body"]->Status) ? $retPend["body"] : (isset($retFiles["body"]->Status) ? $retFiles["body"] : array_merge($retUrls["body"], $retPend["body"], $retFiles["body"])));
        return (object) array("body"    => $body,
                     "headers" => array_unique(array_merge($retUrls["headers"], $retPend["headers"], $retFiles["headers"])),
                     "fileMappings" => array_merge($retUrls["fileMappings"], $retPend["fileMappings"], $retFiles["fileMappings"]));
    }

    function requestInternal($method, $body = NULL, $header = array()){
        $request = curl_init();
        curl_setopt_array($request, $this->options);

        $files = $urls = false;

        if (isset($body["urllist"])) { //images are sent as a list of URLs
            $this->prepareJSONRequest(self::API_ENDPOINT(), $request, $body, $method, $header);
        }
        elseif(isset($body["pendingURLs"])) {
            //prepare the pending items request
            $urls = array();
            $fileCount = 1;
            foreach($body["pendingURLs"] as $url => $path) {
                $urls["url" . $fileCount] = $url;
                $fileCount++;
            }
            $pendingURLs = $body["pendingURLs"];
            unset($body["pendingURLs"]);
            $body["file_urls"] = $urls;
            $this->prepareJSONRequest(self::API_UPLOAD_ENDPOINT(), $request, $body, $method, $header);
        }
        elseif (isset($body["files"])) {
            $files = $this->prepareMultiPartRequest($request, $body, $header);
        }
        else {
            return array("body" => array(), "headers" => array(), "fileMappings" => array());
        }

        for($i = 0; $i < 6; $i++) {
            $response = curl_exec($request);
            if(!curl_errno($request)) {
                break;
            }
        }
        if(curl_errno($request)) {
            throw new ConnectionException("Error while connecting: " . curl_error($request) . "");
        }
        if (!is_string($response)) {
            $message = sprintf("%s (#%d)", curl_error($request), curl_errno($request));
            curl_close($request);
            throw new ConnectionException("Error while connecting: " . $message);
        }

        $status = curl_getinfo($request, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($request, CURLINFO_HEADER_SIZE);
        curl_close($request);

        $headers = self::parseHeaders(substr($response, 0, $headerSize));
        $body = substr($response, $headerSize);

        $details = json_decode($body);

        if(getenv("SHORTPIXEL_DEBUG")) {
            $info = '';
            if(is_array($details)) {
                foreach($details as $det) {
                    $info .= $det->Status->Code . " " . $det->OriginalURL . (isset($det->localPath) ? "({$det->localPath})" : "" ) . "\n";
                }
            } else {
                $info = $response;
            }
            @file_put_contents(dirname(__DIR__) . '/splog.txt', "\nURL Statuses: \n" . $info . "\n", FILE_APPEND);
        }
        if (!$details) {
            $message = sprintf("Error while parsing response: %s (#%d)",
                PHP_VERSION_ID >= 50500 ? json_last_error_msg() : "Error",
                json_last_error());
            $details = (object) array(
                "message" => $message,
                "error" => "ParseError"
            );
        }

        $fileMappings = array();
        if($files) {
            $fileMappings = array();
            foreach($details as $detail) {
                if(isset($detail->Key) && isset($files[$detail->Key])){
                    $fileMappings[$detail->OriginalURL] = $files[$detail->Key];
                }
            }
        } elseif($urls) {
            $fileMappings = $pendingURLs;
        }

        if(getenv("SHORTPIXEL_DEBUG")) {
            $info = '';
            foreach($fileMappings as $key => $val) {
                $info .= "$key -> $val\n";
            }
            @file_put_contents(dirname(__DIR__) . '/splog.txt', "\nFile mappings: \n" . $info . "\n", FILE_APPEND);
        }

        if ($status >= 200 && $status <= 299) {
            return array("body" => $details, "headers" => $headers, "fileMappings" => $fileMappings);
        }

        throw Exception::create($details->message, $details->error, $status);
    }

    protected function prepareJSONRequest($endpoint, $request, $body, $method, $header) {
        $body = json_encode($body);
        array_push($header, "Content-Type: application/json");
        curl_setopt($request, CURLOPT_URL, $endpoint);
        curl_setopt($request, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($request, CURLOPT_HTTPHEADER, $header);
        if ($body) {
            curl_setopt($request, CURLOPT_POSTFIELDS, $body);
        }
    }

    protected function prepareMultiPartRequest($request, $body, $header) {
        $files = array();
        $fileCount = 1;
        foreach($body["files"] as $filePath) {
            $files["file" . $fileCount] = $filePath;
            $fileCount++;
        }
        unset($body["files"]);
        $body["file_paths"] = json_encode($files);
        curl_setopt($request, CURLOPT_URL, Client::API_UPLOAD_ENDPOINT());
        $this->curl_custom_postfields($request, $body, $files, $header);
        return $files;
    }

    function curl_custom_postfields($ch, array $assoc = array(), array $files = array(), $header = array()) {

        // invalid characters for "name" and "filename"
        static $disallow = array("\0", "\"", "\r", "\n");

        // build normal parameters
        foreach ($assoc as $k => $v) {
            $k = str_replace($disallow, "_", $k);
            $body[] = implode("\r\n", array(
                "Content-Disposition: form-data; name=\"{$k}\"",
                "",
                filter_var($v),
            ));
        }

        // build file parameters
        foreach ($files as $k => $v) {
            switch (true) {
                case false === $v = realpath(filter_var($v)):
                case !is_file($v):
                case !is_readable($v):
                    continue; // or return false, throw new InvalidArgumentException
            }
            $data = file_get_contents($v);
            $v = call_user_func("end", explode(DIRECTORY_SEPARATOR, $v));
            $k = str_replace($disallow, "_", $k);
            $v = str_replace($disallow, "_", $v);
            $body[] = implode("\r\n", array(
                "Content-Disposition: form-data; name=\"{$k}\"; filename=\"{$v}\"",
                "Content-Type: application/octet-stream",
                "",
                $data,
            ));
        }

        // generate safe boundary
        do {
            $boundary = "---------------------" . md5(mt_rand() . microtime());
        } while (preg_grep("/{$boundary}/", $body));

        // add boundary for each parameters
        array_walk($body, function (&$part) use ($boundary) {
            $part = "--{$boundary}\r\n{$part}";
        });

        // add final boundary
        $body[] = "--{$boundary}--";
        $body[] = "";

        // set options
        return @curl_setopt_array($ch, array(
            CURLOPT_POST       => true,
            CURLOPT_BINARYTRANSFER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => implode("\r\n", $body),
            CURLOPT_HTTPHEADER => array_merge(array(
                "Expect: 100-continue",
                "Content-Type: multipart/form-data; boundary={$boundary}", // change Content-Type
            ), $header),
        ));
    }

    protected static function parseHeaders($headers) {
        if (!is_array($headers)) {
            $headers = explode("\r\n", $headers);
        }

        $res = array();
        foreach ($headers as $header) {
            if (empty($header)) continue;
            $split = explode(":", $header, 2);
            if (count($split) === 2) {
                $res[strtolower($split[0])] = trim($split[1]);
            }
        }
        return $res;
    }

    function download($sourceURL, $target) {
        $fp = @fopen ($target, 'w+');              // open file handle
        if(!$fp) {
            //file cannot be opened, probably no rights or path disappeared
            if(!is_dir(dirname($target))) {
                throw new ClientException("The file path cannot be found.", -15);
            } else {
                throw new ClientException("File cannot be updated. Please check rights.", -16);
            }
        }

        $ch = curl_init($sourceURL);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // enable if you want
        curl_setopt($ch, CURLOPT_FILE, $fp);          // output to file
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10000);      // some large value to allow curl to run for a long time
        curl_setopt($ch, CURLOPT_USERAGENT, $this->options[CURLOPT_USERAGENT]);
        // curl_setopt($ch, CURLOPT_VERBOSE, true);   // Enable this line to see debug prints
        curl_exec($ch);

        curl_close($ch);                              // closing curl handle
        fclose($fp);                                  // closing file handle
        return true;
    }
}
