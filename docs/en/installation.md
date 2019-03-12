# Installation

Install with Composer:

```
composer require silverstripe-terraformers/embargo-expiry
```

Add the extension to the `DataObject` classes you want to have embargoed.

```yml
SilverStripe\CMS\Model\SiteTree:
  extensions:
    - Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension
```

If you are applying the extension fo a DataObject other than `SiteTree`, ensure it has the `Versioned` extension applied so it can be published/unpublished.

```yml
MyCustomDataObject:
  extensions:
    - SilverStripe\Versioned\Versioned
    - Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension
```

Now run a `dev/build?flush=all` in your browser, or from the command line via `vendor/bin/sake dev/build flush=all`.

Finally, ensure you've set up [queuedjobs](https://github.com/symbiote/silverstripe-queuedjobs) correctly to execute your jobs periodically.

For more information please see [configuration](configuration.md).
