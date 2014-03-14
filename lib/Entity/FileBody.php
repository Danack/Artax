<?php

namespace Artax\Entity;

class FileBody extends ResourceBody {
    public function __construct($filePath) {
        $resource = $this->generateFileResourceFromPath($filePath);
        parent::__construct($resource);
    }

    private function generateFileResourceFromPath($filePath) {
        if (FALSE === ($value = @fopen($filePath, 'r'))) {
            throw new \RuntimeException(
                'Failed reading file: ' . $filePath
            );
        }

        return $value;
    }
}

