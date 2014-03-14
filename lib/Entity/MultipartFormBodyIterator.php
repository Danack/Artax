<?php

namespace Artax\Entity;

class MultipartFormBodyIterator implements \Iterator, \Countable {
    private $fields;
    private $length;
    private $currentCache;
    private $position = 0;

    public function __construct(array $fields, $length) {
        $this->fields = $fields;
        $this->length = $length;
    }

    public function current() {
        if (isset($this->currentCache)) {
            $current = $this->currentCache;
        } elseif (current($this->fields) instanceof FileBody) {
            $current = $this->currentCache = current($this->fields)->current();
        } else {
            $current = $this->currentCache = current($this->fields);
        }

        return $current;
    }

    public function key() {
        return key($this->fields);
    }

    public function next() {
        $this->currentCache = NULL;
        if (current($this->fields) instanceof FormBody) {
            current($this->fields)->next();
        } else {
            next($this->fields);
        }
    }

    public function valid() {
        return isset($this->fields[key($this->fields)]);
    }

    public function rewind() {
        foreach ($this->fields as $field) {
            if ($field instanceof MultipartFormFile) {
                $field->rewind();
            }
        }

        reset($this->fields);

        $this->currentCache = NULL;
    }

    public function count() {
        return $this->length;
    }
}

