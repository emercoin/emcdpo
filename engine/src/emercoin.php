<?php

namespace Emercoin {

    class Request
    {
        protected $data;

        protected $error;

        function __construct($method, $params = [])
        {
            $connect = RPC_TYPE.'://'.RPC_USERNAME.':'.RPC_PASSWORD.'@'.RPC_HOST.':'.RPC_PORT;


            // Prepares the request
            $request = json_encode(
                [
                    'method' => $method,
                    'params' => $params,
                ],
                JSON_UNESCAPED_UNICODE
            );
            // Prepare and performs the HTTP POST
            $opts = [
                'http' => [
                    'method' => 'GET',
                    'header' => join(
                        "\r\n",
                        [
                            'Content-Type: application/json; charset=utf-8',
                            'Accept-Charset: utf-8;q=0.7,*;q=0.7',
                        ]
                    ),
                    'content' => $request,
                ],
                'ssl' => [
                    "verify_peer" => false,
                    "verify_peer_name" => false,
                ],
            ];

            $response = @file_get_contents($connect, false, stream_context_create($opts));

            if (!$response) {
                throw new WalletConnectException('Something went wrong. Please wait for a while and try again.');
            }


            $rc = json_decode($response, true);

            $this->error = $rc['error'];

            if (!is_null($this->error)) {
                throw new ResponseException('Response error: '.$this->error);
            }

            $this->data = $rc['result'];
        }

        function getData()
        {
            return $this->data;
        }
    }

    class EmercoinException extends \Exception
    {
    }

    class RequestException extends EmercoinException
    {
    }

    class ResponseException extends EmercoinException
    {
    }

    class WalletConnectException extends RequestException
    {
    }

    class Manager
    {

        /**
         * @param Key $key
         */
        protected function getKeyHistory($key)
        {
            $history = new Request('name_history', [$key->getName()]);
            $histories = [];

            foreach ($history->getData() as $value) {
                $histories[] = new Key($value);
            }

            $key->setHistory($histories);
        }

        /**
         * @param String $string
         * @return Key|null
         */
        function getKey($string)
        {
            global $app;
            $vendor = $app['emercoin.dpo.vendor'];
            $key = null;
            for ($i = 0; $i < SEARCH_DEPTH; $i++) {
                try {
                    $verified = false;
                    $param = 'dpo:'.DPO_VENDOR.':'.$string.':'.$i;
                    $req = new Request('name_show', [$param]);
                    $key = new Key($req->getData());

                    if ($key) {
                        $this->getKeyHistory($key);

                        if ($key->hasSignature()) {
                            $factoryData = $key->getFactoryDataString();
                            $verify = new Request(
                                'verifymessage',
                                [
                                    $vendor->getAddress(),
                                    $key->getSignature(),
                                    $factoryData,
                                ]
                            );
                            $verified = $verify->getData(); // true || false
                            if ($verified && !$key->isExpired()) {
                                $key->setValidatedBy(Key::SIGNATURE);
                                break;
                            }
                            $key = null;
                        }

                        if ($key && !$key->hasSignature() && !$key->isExpired()) {
                            $history = $key->getHistory()[0];
                            $address = $history->getAddress();
                            if ($vendor->getAddress() !== $address) {
                                $key = null;
                            } else{
                                $key->setValidatedBy(Key::ADDRESS);
                            }
                            break;
                        }
                    }
                    $key = null;
                } catch (WalletConnectException $e) {
                }
            }

            return $key;
        }

        /**
         * @param Key $key
         * @return bool
         */
        function saveKey($key)
        {
            try {
                $req = new Request(
                    'name_update',
                    [
                        $key->getName(),
                        $key->getValue(),
                        NVS_DAYS,
                        (($key->getValidatedBy() === Key::ADDRESS)
                            ? $key->getHistory()[0]->getAddress()
                            : $key->getAddress()),
                    ]
                );
            } catch (EmercoinException $e) {
                echo $e->getMessage();

                return false;
            }

            return true;
        }
    }

    class Key
    {
        const SIGNATURE = SIGNATURE;
        const ADDRESS = 'address';

        /**
         * @var object
         */
        protected $key;

        /**
         * @var array
         */
        protected $data;

        /**
         * @var string
         */
        protected $address;

        /**
         * @var string
         */
        protected $validated_by;

        /**
         * @var Key[]
         */
        protected $history;

        /**
         * @param String $secret
         * @return string
         */
        static function secretEncoder($secret)
        {
            return hash('sha256', md5($secret.SALT));
        }

        /**
         * @param String $key
         */
        function __construct($key)
        {
            $this->key = (object)$key;
            $this->extractKeyValue();
        }

        /**
         * @return void
         */
        private function extractKeyValue()
        {
            foreach (explode("\n", utf8_decode($this->key->value)) as $row) {
                @list($key, $value) = explode('=', $row, 2);
                $this->data[$key] = $value;
            }
        }

        /**
         * @return void
         */
        private function implantKeyValue()
        {
            $pairs = [];
            foreach ($this->getData() as $key => $value) {
                $pairs[] = empty($value) ? $key : $key.'='.trim($value);
            }
            $this->key->value = join("\n", $pairs);
        }

        /**
         * @return string
         */
        function getName()
        {
            return $this->key->name;
        }

        function getFactoryDataString()
        {
            $values = [$this->key->name];
            foreach ($this->data as $key => $value) {
                if (0 === strpos($key, 'F-')) {
                    $values[] = $key.'='.$value;
                }
            }

            return join('|', $values);
        }

        /**
         * @return string
         */
        function getAddress()
        {
            return $this->key->address;
        }

        /**
         * @return bool
         */
        function isExpired()
        {
            return !!$this->key->expired;
        }

        /**
         * @return object
         */
        function getValue()
        {
            return $this->key->value;
        }

        /**
         * @return array
         */
        function getData()
        {
            return $this->data;
        }

        /**
         * @return bool
         */
        function hasSignature()
        {
            return array_key_exists(SIGNATURE, $this->data);
        }

        function getSignature()
        {
            return $this->data[SIGNATURE];
        }

        /**
         * @return bool
         */
        function hasSecret()
        {
            return array_key_exists(SECRET, $this->data);
        }

        /**
         * @param String $secret
         */
        function setSecret($secret)
        {
            if (strlen($secret) == 0) {
                $this->data[SECRET] = '';
            } else {
                $this->data[SECRET] = self::secretEncoder($secret);
            }
            $this->implantKeyValue();
        }

        /**
         * @return String
         */
        function getSecret()
        {
            return $this->data[SECRET];
        }

        /**
         * @param String $secret Insert raw secret
         * @return bool
         */
        function compareSecret($secret)
        {
            return $this->data[SECRET] === self::secretEncoder($secret);
        }

        /**
         * @param String $otp Insert raw otp
         * @return bool
         */
        function compareOtp($otp)
        {
            return $this->data[OTP] === self::secretEncoder($otp);
        }

        /**
         * @return string
         */
        function getDate()
        {
            $date = new \DateTime();

            return $date->setTimestamp($this->key->time)->format(\DateTime::RSS);
        }

        function setValidatedBy($validatedBy)
        {
            if (!empty($this->validated_by)) {
                return;
            }

            $this->validated_by = $validatedBy;

            if ($this->getValidatedBy() === Key::ADDRESS) {
                $keyData = $this->getData();
                $historyData = $this->getHistory()[0]->getData();

                foreach ($keyData as $k => $value) {
                    if (0 === strpos($k, 'F-')) {
                        unset($keyData[$k]);
                    }
                }

                foreach ($historyData as $k => $value) {
                    if (0 !== strpos($k, 'F-')) {
                        unset($historyData[$k]);
                    }
                }
                $combined = $historyData + $keyData;
                $this->data = $combined;

                $this->implantKeyValue();
            }
        }

        function getValidatedBy()
        {
            return $this->validated_by;
        }

        function setHistory($history)
        {
            $this->history = $history;
        }

        function getHistory()
        {
            return $this->history;
        }

        function setOwner($owner)
        {
            $this->data[OWNER] = filter_var($owner, FILTER_SANITIZE_STRING);
            $this->implantKeyValue();
        }

        function getOwner()
        {
            return $this->data[OWNER];
        }

        function setComment($comment)
        {
            $c = str_replace("\n", " ", $comment);
            $this->data[COMMENT] = filter_var($c, FILTER_SANITIZE_STRING);
            $this->implantKeyValue();
        }

        function hasComment()
        {
            return array_key_exists(COMMENT, $this->data);
        }

        function hasOwner()
        {
            return array_key_exists(OWNER, $this->data);
        }

        function getComment()
        {
            return $this->data[COMMENT];
        }

        function getOTP()
        {
            return $this->data[OTP];
        }

        function hasOTP()
        {
            return array_key_exists(OTP, $this->data);
        }

        public function getUpdated()
        {
            return $this->data[UPDATED];
        }

        public function incrementUpdates()
        {
            if (array_key_exists(UPDATED, $this->data)) {
                $this->data[UPDATED]++;
            } else {
                $this->data[UPDATED] = 1;
            }
            $this->implantKeyValue();
        }
    }
}
