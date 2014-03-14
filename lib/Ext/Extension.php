<?php

namespace Artax\Ext;

interface Extension {
    public function extend(\Artax\Observable $client);
    public function unextend();
}
