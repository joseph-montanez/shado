Shado
=====

A real-time, shared to do application in php. Currently this application only supports browser that support web sockets, additional support for older browsers will follow.

Install & Run
=====
    # -- Optional but makes the application scale better
    pecl install libevent-beta
    # -- Window users: http://windows.php.net/downloads/pecl/releases/libevent/
    # -- Add in the .so file
    vim /etc/php5/php.ini
    
    # -- Optional but nicer to have then APC Install phpredis
    git clone https://github.com/nrk/phpiredis.git
    # -- Follow the rest at https://github.com/nrk/phpiredis
    
    # -- Install APC
    pecl install apc
    # -- Windows users: http://windows.php.net/downloads/pecl/releases/apc/
    
    git clone https://github.com/joseph-montanez/shado.git
    cd shado
    
    curl -s https://getcomposer.org/installer | php
    # -- Without Curl: php -r "eval('?>'.file_get_contents('https://getcomposer.org/installer'));"
    
    php composer.phar install
    
    # -- Run the application
    php src/main.php

Then go to your browser http://127.0.0.1:8081/

Configuration
=====

To override any ports, address, or other settings create a file **config.override.json** with the json keys you want to override. I.E:
`
{
    "address": "192.168.1.10"
}
`

Technology
=====

 * PHP - 5.4
 * ReactPHP - Event Loops / HTTP Server
 * Ratchet - Web Socket Support
 * Twig - Templating
 * APC - Key / Value Storage Engine (Required, but can be replaced with Redis)
 * Redis - Key / Value Storage Engine (Optional)
 * PHP libevent - Faster processing (Optional)
 * Predis\Async - Asyc Redis for Php (Optional)
 * PHP phpiredis - Needed for Predis\Async (Optional)
