# Roundcube Easy Unsubscribe v2.0
Displays an icon in the primary toolbar allowing you to quickly unsubscribe from said email. Multiple ways to unsubscribe are presented if the received email allows it. This plugin is reliant upon the `List-Unsubscribe` header.


## Demo Visual
<img width="800" height="246" alt="Demo Visual" src="https://github.com/user-attachments/assets/eaad3a7b-43bd-4f31-aaaf-3d22ff4a2531" />

## Deployment

Download a copy of this repo and upload the contents to:
```
/path/to/roundcube/plugins/easy_unsubscribe
```

Edit your `/path/to/roundcube/config/config.inc.php` file and add `easy_unsubscribe` to the `$config['plugins']` variable. It should look something like the following: 

```
$config['plugins'] = array(
    'password',
    'easy_unsubscribe'
);
```
