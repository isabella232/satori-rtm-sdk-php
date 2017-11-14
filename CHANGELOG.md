v1.0.1 (2017-11-14)
------------------------
* Add support of CBOR.

v0.1.0 (2017-10-11)
------------------------
* Add ability to create Rtm client using the previous one;
* Change context variable that passes to the Subscription callback **[no-backward-compatibility]**:  
    Before it was a Subscription instance  
    Now it is an associative array with the following keys:
    - subscription
    - client
* Add support of Persistent connections (check README for details);
* Add processing of unsolocited /error PDUs.

v0.0.2 (2017-08-31)
------------------------
* New Subscription model **[no-backward-compatibility]**:
  - Add single callback for all events;
  - Remove all events registering functions, like onEventName;
  - Make all subscription properties public
  - Remove all methods to getting properties.

v0.0.1-beta (2017-08-11)
------------------------
* Initial release
