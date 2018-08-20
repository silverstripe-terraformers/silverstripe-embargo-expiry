# Embargo/Expiry Module

[![Build Status](http://img.shields.io/travis/silverstripe-terraformers/silverstripe-embargo-expiry.svg?style=flat)](https://travis-ci.org/silverstripe-terraformers/silverstripe-embargo-expiry)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/silverstripe-terraformers/silverstripe-embargo-expiry/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/silverstripe-terraformers/silverstripe-embargo-expiry/?branch=master)
[![codecov](https://codecov.io/gh/silverstripe-terraformers/silverstripe-embargo-expiry/branch/master/graph/badge.svg)](https://codecov.io/gh/silverstripe-terraformers/silverstripe-embargo-expiry)

## Overview

This module adds the ability to schedule publish and unpublish events
at a certain date and time. It can be applied to different model classes,
but is commonly used with `SiteTree` where it comes with built-in UI triggers.

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

Add the following YAML configuration (e.g. in `mysite/_config/embargo.yml`):

```yml
SilverStripe\CMS\Controllers\CMSMain:
  extensions:
    - Terraformers\EmbargoExpiry\Extension\EmbargoExpiryCMSMainExtension
```

Then add the extension to the `DataObject` classes you want to have embargoed.

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

## Known Issues

 * Does not support recurring embargo or expiry schedules
 * Does not support multiple concurrent schedules for the same object
 * Does not support embargo to a particular live version
 * Does not support expiry to an earlier live version
 * Any edits to an embargoed page will be published on the date (not tied to a version)
