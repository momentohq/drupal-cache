# Changelog

## [0.5.2](https://github.com/momentohq/drupal-cache/compare/v0.5.1...v0.5.2) (2023-11-14)


### Miscellaneous

* logging and set_multiple operation improvements ([#23](https://github.com/momentohq/drupal-cache/issues/23)) ([fe1513a](https://github.com/momentohq/drupal-cache/commit/fe1513a8573880d255da9bf21cae52d6e464373d))

## [0.5.1](https://github.com/momentohq/drupal-cache/compare/v0.5.0...v0.5.1) (2023-11-10)


### Bug Fixes

* fix the default limits README section ([#19](https://github.com/momentohq/drupal-cache/issues/19)) ([8c23672](https://github.com/momentohq/drupal-cache/commit/8c236722d5f657fbdd66f44c28f00b1fa99bb494))

## [0.5.0](https://github.com/momentohq/drupal-cache/compare/v0.4.1...v0.5.0) (2023-10-26)


### Features

* specify cache name in settings instead of prefix ([#16](https://github.com/momentohq/drupal-cache/issues/16)) ([df96e79](https://github.com/momentohq/drupal-cache/commit/df96e79b0999f981aea4f7fd3d17fb0b748f8474))


### Miscellaneous

* removing container bootstrap definition from settings ([#18](https://github.com/momentohq/drupal-cache/issues/18)) ([b0d6500](https://github.com/momentohq/drupal-cache/commit/b0d65006513c6d0dc04ab507207e883211362854))

## [0.4.1](https://github.com/momentohq/drupal-cache/compare/v0.4.0...v0.4.1) (2023-10-24)


### Bug Fixes

* set grpc config to force new channel ([#13](https://github.com/momentohq/drupal-cache/issues/13)) ([8af3ce6](https://github.com/momentohq/drupal-cache/commit/8af3ce6815da8033c7f3902389ac07773d8cd95a))


### Miscellaneous

* extend versions under test ([#11](https://github.com/momentohq/drupal-cache/issues/11)) ([1ab6110](https://github.com/momentohq/drupal-cache/commit/1ab6110dd4c534f3c18c4ce9164584857e422335))

## [0.4.0](https://github.com/momentohq/drupal-cache/compare/v0.3.2...v0.4.0) (2023-10-20)


### Features

* single cache implementation ([#5](https://github.com/momentohq/drupal-cache/issues/5)) ([f64f579](https://github.com/momentohq/drupal-cache/commit/f64f5794026cb94eb1ef63733887d1c7b480a2f8))


### Bug Fixes

* add PHP 8.2 to test matrix ([caf9d36](https://github.com/momentohq/drupal-cache/commit/caf9d36e1ace87c25f0c2f97c126462787d99cb9))
* fix array to string error ([aa073b3](https://github.com/momentohq/drupal-cache/commit/aa073b3e518df154d2f6c6626d8122a6f90619be))
* trying to get PHP and Drupal versions aligned for testing ([fabe186](https://github.com/momentohq/drupal-cache/commit/fabe186df719d3efc6604bc1ee5d5e4c4090fba6))
* use Drupal getRequestTime where possible ([e470bd4](https://github.com/momentohq/drupal-cache/commit/e470bd495c77cfec04f05e522d7cd4a3bec646e2))


### Miscellaneous

* adjust ttl test ([715f48e](https://github.com/momentohq/drupal-cache/commit/715f48eac1e57fccad5355103311002d1d0d5446))
* cleanup ([1d1a7a0](https://github.com/momentohq/drupal-cache/commit/1d1a7a0181b9ed5ccd162efb96178aed9ea9e61c))
* fix yaml ([#6](https://github.com/momentohq/drupal-cache/issues/6)) ([cfb7534](https://github.com/momentohq/drupal-cache/commit/cfb7534ac1b061f4ba31908927ebcd355244c2ad))
* looking into clear cache performance ([bf63573](https://github.com/momentohq/drupal-cache/commit/bf6357342d438f879e41c6889193b5ba3c1497f6))
* README work ([79c5eac](https://github.com/momentohq/drupal-cache/commit/79c5eac7e50180d0a1f670e6224f797646171252))
* remove unused invalidator class ([da14960](https://github.com/momentohq/drupal-cache/commit/da14960aab48d2c90614a1ae93cacd8100d8194d))
* test matrix of PHP versions ([4cc5769](https://github.com/momentohq/drupal-cache/commit/4cc5769f7eff6266a5b0b0343f5e0ca7b18690cd))
* update README in preparation for 0.4.0 stable release ([#10](https://github.com/momentohq/drupal-cache/issues/10)) ([e849610](https://github.com/momentohq/drupal-cache/commit/e849610053cd82b682f090e66b945b2c5b55f44c))
* use token from secrets and allow tests to run in parallel ([#9](https://github.com/momentohq/drupal-cache/issues/9)) ([7a0c2a2](https://github.com/momentohq/drupal-cache/commit/7a0c2a2a121f7fa285bb140bd2b74cb04e3a7163))
* yaml work ([#7](https://github.com/momentohq/drupal-cache/issues/7)) ([d597994](https://github.com/momentohq/drupal-cache/commit/d5979942cea2f22f52669ec3e724773f1ae97b5c))
