# Changelog

All notable changes to `byrcsc/laravel-customer-health` will be documented in
this file.

The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-08-10

### Added

- Business-event tracking with optional actors and queued writes.
- Permanent milestone records for feature adoption and onboarding progress.
- Declarative health scores with weighted signals and state-change events.
- Scheduled score recomputation, score history, and current health summaries.
- Configurable storage connections and table names.
- Database-per-tenant compatibility with `spatie/laravel-multitenancy`.
- Raw-event retention and complete subject data purging.
- Support for Laravel 12 and 13 on PHP 8.3 and 8.4.

[Unreleased]: https://github.com/byrcsc/laravel-customer-health/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/byrcsc/laravel-customer-health/releases/tag/v1.0.0
