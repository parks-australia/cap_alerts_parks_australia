## Introduction

The Parks CAP Alerts module extends the `cap_alert` module by adding additional features specific to Parks Australia.

In partiucular, it adds a new field to the `CAP Alert` content type that allows users to relate CAP Alerts to existing Parks Australia `Locations`.

## Requirements

This module requires the 'CAP Alerts' module to be installed and enabled.

It also requires a content type called `Location` to exist.

Ensure site configuration has been exported and captured in code before enabling this module.


## Installation

Install via `drush` or the Drupal UI like any other module;

`drush en -y parks_cap_alerts`

Once enabled, a new Entity Reference field will be available on the `CAP Alert` content type, called `field_location_reference`. This will allow users to relate CAP Alerts to existing Locations.

Once installed, running a config export will capture the new field and its settings.

When/if uninstalled, the field will be removed from the content type and its storage in the database will be removed (provided the field hasn't been used anywhere else). 

*Note: Uninstalling this module is a desctructive action, and will result in data loss if the field has been used!*
