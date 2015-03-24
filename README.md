Exchange mailboxes @ OVH backup tool
-------------------------

This script will help you backup Exchange mailboxes hosted at OVH.
It uses the API provided to backup .pst files.

Please note that this is a heavy process, that's why I recommand doing backup ~ once in a week.

Usage
-----
Just edit parameters.ini, and run script.


Requirements
-------------
This script uses PHP and is working with versions >= 5.2

License
-------
Feel free to modify this script to suit your need. If you find any bug or improve it, please share your patch.

Generate credentials
--------------------

- Obtain Application key and application secret at https://api.ovh.com
- Generate a consumer key (CK) with access to resources /email/exchange. You can do it by executing following query :
(note : I was unable to get a working token with path: "/email/exchange". Was forced to access /* ... ) 
(note2: DELETE grant is needed, because one export request can be made at a time, and we need to delete old exports before doing new ones).

```
curl -XPOST -H"X-Ovh-Application: REPLACEMEPLEASE" -H "Content-type: application/json" \
https://eu.api.ovh.com/1.0/auth/credential  -d '{
    "accessRules": [
        {
            "method": "GET",
            "path": "/email/exchange/*"
        },
        {
            "method": "POST",
            "path": "/email/exchange/*"
        },
        {
            "method": "DELETE",
            "path": "/email/exchange/*"
        }
    ]
}'
```