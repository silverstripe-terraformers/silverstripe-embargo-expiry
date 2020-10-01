# Fluent Support

[Fluent](https://github.com/tractorcow/silverstripe-fluent)

`EmbargoExpiryFluentExtension` is provided to add support for DataObject that have `FluentVersionedExtension` applied.

The expected behaviour is that you can now set an Embargo & Expiry date in each Locale separately from each other, and
when those dates pass, the Jobs will publish/un-publish only the record in that Locale.
