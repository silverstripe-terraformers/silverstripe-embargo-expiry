# Embargo/Expiry Module

[![Build Status](http://img.shields.io/travis/silverstripe-terraformers/silverstripe-embargo-expiry.svg?style=flat)](https://travis-ci.org/silverstripe-terraformers/silverstripe-embargo-expiry)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/silverstripe-terraformers/silverstripe-embargo-expiry/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/silverstripe-terraformers/silverstripe-embargo-expiry/?branch=master)
[![codecov](https://codecov.io/gh/silverstripe-terraformers/silverstripe-embargo-expiry/branch/master/graph/badge.svg)](https://codecov.io/gh/silverstripe-terraformers/silverstripe-embargo-expiry)

## Overview

Based on the work by [Marcus Nyeholt](https://github.com/nyeholt) and [Andrew Short](https://github.com/ajshort) for the [Advanced Workflow](https://github.com/symbiote/silverstripe-advancedworkflow/) module.

This module adds the ability to schedule publish and unpublish events at a certain date and time. It can be applied to different model classes, but is commonly used with `SiteTree` where it comes with built-in UI triggers.

Features:

 * Date and time picker (through SilverStripe CMS, where browsers [support](https://developer.mozilla.org/en-US/docs/Web/HTML/Element/input/datetime-local) it)
 * Publish "windows" by setting a publish date with a subsequent unpublish date
 * Scheduled publication through [queuedjobs](https://github.com/symbiote/silverstripe-queuedjobs)
 * Respects cascading publish through [ownership relations](https://docs.silverstripe.org/en/developer_guides/model/versioning/#dataobject-ownership)
 * Expiry unpublishes the page (leaves it in "draft" mode)
 * Optionally lock editing while publication is scheduled
 * Add status flags to pages in the tree
 * Support for translations in [silverstripe/fluent](https://github.com/tractorcow/silverstripe-fluent)

## Requirements

 * SilverStripe 4.0
 * [Queuedjobs](https://github.com/symbiote/silverstripe-queuedjobs)

## Installation

Install with Composer:

```
composer require silverstripe-terraformers/embargo-expiry
```

## Configuration

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

Now run a `dev/build` in your browser,
or from the command line via `vendor/bin/sake dev/build`.

Finally, ensure you've set up [queuedjobs](https://github.com/symbiote/silverstripe-queuedjobs) correctly
to execute your jobs periodically.

## Using Embargo & Expiry with Fluent

[Fluent](https://github.com/tractorcow/silverstripe-fluent)

`EmbargoExpiryFluentExtension` is provided to add support for DataObject that have `FluentVersionedExtension` applied.

The expected behaviour is that you can now set an Embargo & Expiry date in each Locale separately from each other, and when those dates pass, the Jobs will publish/un-publish only the record in that Locale.

**Please be very aware that there is no test coverage for this Extension as Fluent is not an included dependency for this module. You will need to cover your own tests if you decide to use this Extension.**

## Known Limitations

 * Does not support recurring embargo or expiry schedules
 * Does not support multiple concurrent schedules for the same object
 * Does not support embargo to a particular live version
 * Does not support expiry to an earlier live version
 * Any edits to an embargoed page will be published on the date (not tied to a version)
