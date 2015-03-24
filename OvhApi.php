<?php

# Copyright (c) 2013, OVH SAS.
# All rights reserved.
#
# Redistribution and use in source and binary forms, with or without
# modification, are permitted provided that the following conditions are met:
#
#* Redistributions of source code must retain the above copyright
#  notice, this list of conditions and the following disclaimer.
#* Redistributions in binary form must reproduce the above copyright
#  notice, this list of conditions and the following disclaimer in the
#  documentation and/or other materials provided with the distribution.
#* Neither the name of OVH SAS nor the
#  names of its contributors may be used to endorse or promote products
#  derived from this software without specific prior written permission.
#
# THIS SOFTWARE IS PROVIDED BY OVH SAS AND CONTRIBUTORS ``AS IS'' AND ANY
# EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
# WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
# DISCLAIMED. IN NO EVENT SHALL OVH SAS AND CONTRIBUTORS BE LIABLE FOR ANY
# DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
# (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
# LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
# ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
# (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
# SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

define('OVH_API_EU', 'https://eu.api.ovh.com/1.0');
define('OVH_API_CA', 'https://ca.api.ovh.com/1.0');

class OvhApi {

    var $AK;
    var $AS;
    var $CK;
    var $timeDrift = 0;
    function __construct($_root, $_ak, $_as, $_ck) {
        // INIT vars
        $this->AK = $_ak;
        $this->AS = $_as;
        $this->CK = $_ck;
        $this->ROOT = $_root;

        // Compute time drift
        $serverTimeRequest = file_get_contents($this->ROOT . '/auth/time');
        if($serverTimeRequest !== FALSE)
        {
            $this->timeDrift = time() - (int)$serverTimeRequest;
        }
    }
    function call($method, $url, $body = NULL)
    {
        $url = $this->ROOT . $url;
        if($body)
        {
            $body = json_encode($body);
        }
        else
        {
            $body = "";
        }

        // Compute signature
        $time = time() - $this->timeDrift;
        $toSign = $this->AS.'+'.$this->CK.'+'.$method.'+'.$url.'+'.$body.'+'.$time;
        $signature = '$1$' . sha1($toSign);

        // Call
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json',
            'X-Ovh-Application:' . $this->AK,
            'X-Ovh-Consumer:' . $this->CK,
            'X-Ovh-Signature:' . $signature,
            'X-Ovh-Timestamp:' . $time,
        ));
        if($body)
        {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($curl);
        if($result === FALSE)
        {
            echo curl_error($curl);
            return NULL;
        }

        return json_decode($result);
    }
    function get($url)
    {
        return $this->call("GET", $url);
    }
    function put($url, $body)
    {
        return $this->call("PUT", $url, $body);
    }
    function post($url, $body)
    {
        return $this->call("POST", $url, $body);
    }
    function delete($url)
    {
        return $this->call("DELETE", $url);
    }
}
?>
