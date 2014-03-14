<?php

namespace Artax\Ext\Cookies;

interface CookieJar {
    public function get($domain, $path = '', $name = NULL);
    public function getAll();
    public function store(Cookie $cookie);
    public function remove(Cookie $cookie);
    public function removeAll();
}
