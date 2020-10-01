# Configuration

## Allow/disallow editing of content while an Embargo date is set

When a `PublishTargetJob` is run, it will publish whatever the current draft state is. In your project, you might want
to allow or disallow the editing of content once an Embargo date is set. Disallowing editing of content will mean that
once an Embargo date is set, you'll know that no additional changes have been made to the record.

By default, editing while an Embargo date is set is **enabled**.

You can change this globally by updating the config value on `DataObject`:
```yml
SilverStripe\ORM\DataObject:
  allow_embargoed_editing: false
```

Or you can target specific `DataObject` classes:
```yml
My\Awesome\DataObject:
  allow_embargoed_editing: false
```

## Enforce sequential Embargo and Expiry dates

When setting an Embargo and Expiry date, you may wish to allow or disallow users setting an Expiry date that is
**prior** to the Embargo date. Allowing a user to have dates in either order could be useful if they have a use case for
un-publishing an already published page for a small window of time before it is automatically published again.

By default, sequential dates are **not** enforced (Expiry dates can be for earlier than the Embargo date).

You can change this globally by updating the config value on `DataObject`:
```yml
SilverStripe\ORM\DataObject:
  enforce_sequential_dates: true
```

Or you can target specific `DataObject` classes:
```yml
My\Awesome\DataObject:
  enforce_sequential_dates: true
```

With this feature enabled, Embargo dates will be removed if they are **after** the Expiry date.
