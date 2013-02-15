Shado
=====

A real-time, shared to do application in php. Currently this application only supports browser that support web sockets, additional support for older browsers will follow.

Install & Run
=====
    git clone https://github.com/joseph-montanez/shado.git
    cd shado
    curl -s https://getcomposer.org/installer | php
    php composer.phar install
    
    pecl install libevent-beta
    vim /etc/php5/php.ini --add in the .so file
    
    php src/main.php

Then go to your browser http://127.0.0.1:8081/

Technology
=====

 * PHP - 5.4
 * ReactPHP - Event Loops / HTTP Server
 * Ratchet - Web Socket Support
 * Redis - Key / Value Storage Engine
 * PHP libevent - Faster processing
