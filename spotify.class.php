<?php

/**
 * Simple class for Spotify API
 * Copyright (C) 2018  Bohdan Manko <mailmanbo@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Class Spotify
 *
 * @author   Bohdan Manko <mailmanbo@gmail.com>
 * @license  http://www.gnu.org/licenses/ GPL v3
 * @link     https://github.com/mkbodanu4/spotify-import-export
 */
class Spotify
{
    private $base = "https://api.spotify.com/v1";
    private $client_id;
    private $client_secret;
    private $redirect_uri;
    private $state;
    private $scopes;
    private $access_token;
    private $http_code = NULL;

    public function __construct($params = array())
    {
        $this->client_id = $params['client_id'];
        $this->client_secret = $params['client_secret'];
        $this->redirect_uri = $params['redirect_uri'];
        $this->state = $params['state'];
        $this->scopes = $params['scopes'];
        $this->access_token = isset($params['access_token']) ? $params['access_token'] : NULL;
    }

    public function set_access_token($access_token)
    {
        $this->access_token = $access_token;
    }

    public function get_auth_url()
    {
        $query = array(
            "response_type" => "code",
            "client_id" => $this->client_id,
            "redirect_uri" => $this->redirect_uri,
            "state" => $this->state,
            "scope" => implode(" ", $this->scopes),
        );

        return "https://accounts.spotify.com/authorize" .
            "?" . http_build_query($query);
    }

    public function get_url($endpoint, $params = array())
    {
        return $this->base . $endpoint . (count($params) > 0 ? "?" . http_build_query($params) : "");
    }

    public function get_token($code)
    {
        $post = array(
            "grant_type" => "authorization_code",
            "code" => $code,
            "redirect_uri" => $this->redirect_uri
        );

        return $this->get_json("https://accounts.spotify.com/api/token", urldecode(http_build_query($post)), array(
            "Authorization: Basic " . base64_encode($this->client_id . ":" . $this->client_secret),
            "Content-Type: application/x-www-form-urlencoded"
        ));
    }

    public function refresh_token($refresh_token)
    {
        $post = array(
            "grant_type" => "refresh_token",
            "refresh_token" => $refresh_token
        );

        return $this->get_json("https://accounts.spotify.com/api/token", urldecode(http_build_query($post)), array(
            "Authorization: Basic " . base64_encode($this->client_id . ":" . $this->client_secret),
            "Content-Type: application/x-www-form-urlencoded"
        ));
    }

    public function get($method, $endpoint, $params = array(), $headers = array())
    {
        switch (strtolower($method)) {
            case "put":
                return $this->get_json($this->get_url($endpoint), $params, array_merge(array(
                    "Authorization: Bearer " . $this->access_token
                ), $headers), "PUT");
                break;
            case "post":
                return $this->get_json($this->get_url($endpoint), $params, array_merge(array(
                    "Authorization: Bearer " . $this->access_token
                ), $headers));
                break;
            case "get":
            default:
                return $this->get_json($this->get_url($endpoint, $params), NULL, array_merge(array(
                    "Authorization: Bearer " . $this->access_token
                ), $headers));
        }
    }

    public function get_http_code()
    {
        return $this->http_code;
    }

    private function get_json($url, $post = NULL, $headers = array(), $method = NULL)
    {
        $content = $this->get_content($url, $post, $headers, $method);

        return json_decode($content);
    }

    private function get_content($url, $post = NULL, $headers = array(), $method = NULL)
    {
        $handler = curl_init();
        curl_setopt($handler, CURLOPT_URL, $url);
        curl_setopt($handler, CURLOPT_HEADER, FALSE);
        curl_setopt($handler, CURLOPT_HTTPHEADER, $headers);
        if ($post || $method !== NULL) {
            if ($method !== NULL) {
                curl_setopt($handler, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($handler, CURLOPT_POST, FALSE);
            } else {
                curl_setopt($handler, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($handler, CURLOPT_POST, TRUE);
            }
            curl_setopt($handler, CURLOPT_POSTFIELDS, $post);
        }
        curl_setopt($handler, CURLINFO_HEADER_OUT, FALSE);
        curl_setopt($handler, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($handler, CURLOPT_MAXREDIRS, 10);
        curl_setopt($handler, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($handler, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($handler, CURLOPT_TIMEOUT, 30);
        curl_setopt($handler, CURLOPT_USERAGENT, "PHP/" . phpversion());
        $result = curl_exec($handler);
        $this->http_code = curl_getinfo($handler, CURLINFO_HTTP_CODE);
        curl_close($handler);


        return $result;
    }
}