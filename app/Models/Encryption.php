<?php

namespace App\Models;

trait Encryption {
    private $cipher = "AES-256-CBC";

    protected function string_encrypt($string) {
        // Get the iv length required for the cipher method
        $ivlen = openssl_cipher_iv_length($this->cipher);
        // Create an iv with the length of ivlen
        $iv = random_bytes($ivlen);
        // Encrypt the provided string
        $encrypted = openssl_encrypt($string, $this->cipher, env('APP_KEY'), $options=0, $iv);
        // Prepend the base64 encoded iv to the encrypted data separated with a ":" as delimiter
        $encrypted = base64_encode($iv).":".$encrypted;
        return $encrypted;
    }
    
    protected function string_decrypt($string) {
        $cipher = "AES-256-CBC";
        // Split the provided string on the ":" delimiter
        $exploded_string = explode(":", $string);
        // Get the iv
        $iv = base64_decode($exploded_string[0]);
        if(isset($exploded_string[1])){
            // Get the encrypted data
            $encrypted_data = $exploded_string[1];
            // Decrypt the data
            $decrypted = openssl_decrypt($encrypted_data, $this->cipher, env('APP_KEY'), $options=0, $iv);
            return $decrypted;
        } else {
            return "";
        }
    }
}