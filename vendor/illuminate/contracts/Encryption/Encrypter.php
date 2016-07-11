<?php

namespace Illuminate\Contracts\Encryption;

interface Encrypter
{
    /**
     * Encrypt the given value.
     * 给定的值加密
     *
     * @param  string  $value
     * @return string
     */
    public function encrypt($value);

    /**
     * Decrypt the given value.
     * 给定的值解密
     *
     * @param  string  $payload
     * @return string
     */
    public function decrypt($payload);
}
