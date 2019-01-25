Request info
############

Get information about the current HTTP request.

.. image:: https://travis-ci.com/kuria/request-info.svg?branch=master
   :target: https://travis-ci.com/kuria/request-info

.. contents::
   :depth: 3


Features
********

- getting request information:

  - headers
  - HTTPS detection
  - client IP address
  - scheme
  - method
  - host
  - port
  - URL
  - base directory
  - base path
  - path info
  - script name

- trusted proxy header support (x-forwarded / forwarded)
- host validation (inc. defining specific trusted hosts or host patterns)
- optional HTTP method override support


Requirements
************

- PHP 7.1+


Usage
*****

All configuration and value retrieval is done via the static ``Kuria\RequestInfo\RequestInfo`` class.


Configuration
=============

Trusted proxies
---------------

By default all proxy headers are ignored. To trust select proxy headers, call ``RequestInfo::setTrustedProxies()``
with an appropriately configured ``TrustedProxies`` instance.

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;
   use Kuria\RequestInfo\TrustedProxies;

   $trustedProxies = new TrustedProxies(
       ['192.168.1.10', '192.168.1.20'],  // one or more IP adresses or subnets in CIDR notation
       TrustedProxies::HEADER_FORWARDED   // which headers to trust (bit mask)
   );

   RequestInfo::setTrustedProxies($trustedProxies);


Choosing which headers to trust
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

Trusted headers are a bitmask of the following constants:

============================================== ==============================
Constant                                       Allowed headers
============================================== ==============================
``TrustedProxies::HEADER_FORWARDED``           ``Forwarded``
``TrustedProxies::HEADER_X_FORWARDED_FOR``     ``X-Forwarded-For``
``TrustedProxies::HEADER_X_FORWARDED_HOST``    ``X-Forwarded-Host``
``TrustedProxies::HEADER_X_FORWARDED_PROTO``   ``X-Forwarded-Proto``
``TrustedProxies::HEADER_X_FORWARDED_PORT``    ``X-Forwarded-Port``
``TrustedProxies::HEADER_X_FORWARDED_ALL``     ``X-Forwarded-*``
============================================== ==============================

.. NOTE::

   Trusting both the ``Forwarded`` and ``X-Forwarded-*`` headers is supported,
   but they must report the same values. Different values will cause
   ``Kuria\RequestInfo\Exception\HeaderConflictException``.


Applications always behind a trusted proxy
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

If you are sure that an application will always be behind a trusted proxy, you can
use ``$_SERVER['REMOTE_ADDR']`` in place of a hardcoded IP address:

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;
   use Kuria\RequestInfo\Helper\Server;
   use Kuria\RequestInfo\TrustedProxies;

   $trustedProxies = new TrustedProxies(
       [Server::require('REMOTE_ADDR')],
       TrustedProxies::HEADER_FORWARDED
   );

   RequestInfo::setTrustedProxies($trustedProxies);


Trusted hosts
-------------

The request host is always validated according to the standards.

To restrict accepted hosts further, use the following methods:

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   // specific hosts (exact match)
   RequestInfo::setTrustedHosts([
       'www.example.com',
       'cdn.example.com',
   ]);

   // host patterns
   RequestInfo::setTrustedHostPatterns([
       '{\w+\.example\.com$}AD',
       '{example-node-\d+$}AD',
   ]);


HTTP method override
--------------------

By default, the ``X-HTTP-Method-Override`` header is ignored.

If you need to override the HTTP method via this header (e.g. because of restrictive firewall rules),
you can enable its support:

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   RequestInfo::setAllowHttpMethodOverride(true);


Resetting configuration
-----------------------

To restore default ``RequestInfo`` configuration:

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   RequestInfo::reset();


Getting request information
===========================

Headers
-------

Get all request headers as an array. Header names are lowercased and used as keys.

.. code:: php

   <?php

   print_r(RequestInfo::getHeaders());

Example output:

::

  Array
  (
      [host] => localhost:8080
      [connection] => keep-alive
      [cache-control] => max-age=0
      [upgrade-insecure-requests] => 1
      [user-agent] => Mozilla/5.0 (Example)
      [accept] => text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8
      [accept-encoding] => gzip, deflate, br
      [accept-language] => en-US,en;q=0.9,cs;q=0.8
  )


Trusted proxy detection
-----------------------

Check whether the request originated from a trusted proxy.

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   if (RequestInfo::isFromTrustedProxy()) {
       // request is from a trusted proxy
   }


HTTPS detection
---------------

See whether the request uses HTTPS.

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   if (RequestInfo::isSecure()) {
       // request uses HTTPS
   }


Client IP address
-----------------

Get the client IP address.

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   var_dump(RequestInfo::getClientIp());

Example output:

::

  string(9) "127.0.0.1"

.. NOTE::

   ``RequestInfo::getClientIp()`` will return ``NULL`` if the client IP address is not known (e.g. in CLI).

To get all known client IP addresses (ordered from most trusted to least trusted), use ``getClientIps()``:

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   print_r(RequestInfo::getClientIps());

Example output:

::

  Array
  (
      [0] => 20.30.40.50
      [1] => 10.20.30.40
  )

.. NOTE::

   ``RequestInfo::getClientIps()`` will return an empty array if the client IP addresses are not known (e.g. in CLI).


Method
------

Get the request method. The method name will always be in uppercase.

Also see `HTTP method override`_.

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   var_dump(RequestInfo::getMethod());

Example output:

::

  string(3) "GET"



Scheme
------

Get the request scheme.

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   var_dump(RequestInfo::getScheme());

Example output:

::

  string(4) "https"


Host
----

Get the host name.

Also see `Trusted hosts`_.

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   var_dump(RequestInfo::getHost());

Example output:

::

  string(9) "localhost"

.. NOTE::

   The returned host name does not include the port number. Use ``RequestInfo::getPort()`` to get
   the port number or ``RequestInfo::getUrl()->getFullHost()`` to get the host name with the port
   number (if it is non-standard).


Port
----

Get the port number.

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   var_dump(RequestInfo::getPort());

Example output:

::

  int(80)


URL
---

Get the request URL. Returns an unique instance of ``Kuria\Url\Url``.

See the `kuria/url <https://github.com/kuria/url>`_ component for more information.

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   $url = RequestInfo::getUrl();

   echo
       "URL:\t", $url->build(), PHP_EOL,
       "Scheme:\t", $url->getScheme(), PHP_EOL,
       "Host:\t", $url->getHost(), PHP_EOL,
       "Port:\t", $url->getPort(), PHP_EOL,
       "Path:\t", $url->getPath(), PHP_EOL,
       "Query:\t", json_encode($url->getQuery()), PHP_EOL;

Example output:

::

  URL:    http://localhost:8080/test/index.php/foo?bar=baz
  Scheme: http
  Host:   localhost
  Port:   8080
  Path:   /test/index.php/foo
  Query:  {"bar":"baz"}


Base directory
--------------

Get base directory (without script name, if any). The returned path never ends with a "/".

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   var_dump(RequestInfo::getBaseDir());

Examples:

================================= ===============
URL                               Base directory
================================= ===============
http://localhost/index.php        *(empty string)*
http://localhost/index.php/page   *(empty string)*
http://localhost/web/index.php    /web
http://localhost/we%20b/index.php /we%20b
================================= ===============


Base path
---------

Get base path (including the script name, if any). The returned path never ends with a "/".

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   var_dump(RequestInfo::getBasePath());

Examples:

================================= =================
URL                               Base path
================================= =================
http://localhost/index.php        /index.php
http://localhost/index.php/page   /index.php
http://localhost/web/index.php    /web/index.php
http://localhost/we%20b/index.php /we%20b/index.php
================================= =================


Path info
---------

Get path info.

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   var_dump(RequestInfo::getPathInfo());

Examples:

=========================================== =================
URL                                         Path info
=========================================== =================
http://localhost/index.php                  *(empty string)*
http://localhost/index.php/page             /page
http://localhost/web/index.php              *(empty string)*
http://localhost/we%20b/index.php/foo%20bar /foo%20bar
=========================================== =================


Script name
-----------

Get the current script name.

.. code:: php

   <?php

   use Kuria\RequestInfo\RequestInfo;

   var_dump(RequestInfo::getScriptName());

Example output:

::

  string(18) "./public/index.php"


Internal cache
==============

Most methods of the ``RequestInfo`` class cache their results internally. If you manipulate ``$_SERVER``
after already reading some request information, you will need to call ``RequestInfo::clearCache()``
to clear the cache.
