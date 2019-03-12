# Embargo/Expiry Module

[![Build Status](http://img.shields.io/travis/silverstripe-terraformers/silverstripe-embargo-expiry.svg?style=flat)](https://travis-ci.org/silverstripe-terraformers/silverstripe-embargo-expiry)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/silverstripe-terraformers/silverstripe-embargo-expiry/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/silverstripe-terraformers/silverstripe-embargo-expiry/?branch=master)
[![codecov](https://codecov.io/gh/silverstripe-terraformers/silverstripe-embargo-expiry/branch/master/graph/badge.svg)](https://codecov.io/gh/silverstripe-terraformers/silverstripe-embargo-expiry)

## Overview

Based on the work by [Marcus Nyeholt](https://github.com/nyeholt) and [Andrew Short](https://github.com/ajshort) for the [Advanced Workflow](https://github.com/symbiote/silverstripe-advancedworkflow/) module.

This module adds the ability to schedule publish and unpublish events at a certain date and time. It can be applied to different model classes, but is commonly used with `SiteTree`.

Features:

 * Date and time picker (through SilverStripe CMS, where browsers [support](https://developer.mozilla.org/en-US/docs/Web/HTML/Element/input/datetime-local) it)
 * Publish "windows" by setting a publish date with a subsequent unpublish date
 * Scheduled publication through [queuedjobs](https://github.com/symbiote/silverstripe-queuedjobs)
 * Respects cascading publish through [ownership relations](https://docs.silverstripe.org/en/developer_guides/model/versioning/#dataobject-ownership)
 * Expiry unpublishes the page (leaves it in "draft" mode)
 * Optionally lock editing while publication is scheduled
 * Add status flags to pages in the tree
 * Support for translations in [silverstripe/fluent](https://github.com/tractorcow/silverstripe-fluent)

## Credit and Authors

 * [Chris Penny](https://github.com/chrispenny) - [SilverStripe Embargo & Expiry](https://github.com/silverstripe-terraformers/silverstripe-embargo-expiry)
 * [Marcus Nyeholt](https://github.com/nyeholt) - [Advanced Workflow](https://github.com/symbiote/silverstripe-advancedworkflow/)
 * [Andrew Short](https://github.com/ajshort) - [Advanced Workflow](https://github.com/symbiote/silverstripe-advancedworkflow/)

## Requirements

 * SilverStripe 4.0
 * [Queuedjobs](https://github.com/symbiote/silverstripe-queuedjobs)

## Documentation

 * [Installation](docs/en/installation.md)
 * [Configuration](docs/en/configuration.md)
 * [Fluent Support](docs/en/fluent-support.md)

## Known Limitations

 * Does not support recurring embargo or expiry schedules
 * Does not support multiple concurrent schedules for the same object
 * Does not support embargo to a particular live version
 * Does not support expiry to an earlier live version
 * Any edits to an embargoed page will be published on the date (not tied to a version)
