<?php

namespace Artax;

class Event {
    const REQUEST = 1;
    const SOCKET = 2;
    const HEADERS = 3;
    const BODY_DATA = 4;
    const CANCEL = 5;
    const RESPONSE = 6;
    const REDIRECT = 7;
    const ERROR = 8;
    const SOCK_DATA_OUT = 9;
    const SOCK_DATA_IN = 10;
}
