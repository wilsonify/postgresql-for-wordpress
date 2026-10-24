# Third-party test dependencies

The plugin itself is first-party code. Two third-party test dependencies are
acquired by `scripts/bootstrap.sh` instead of being committed:

| Dependency | Version | Source | Integrity | License |
| --- | --- | --- | --- | --- |
| WordPress core fixtures | 6.4.1 | <https://wordpress.org/wordpress-6.4.1.tar.gz> | Pinned tarball SHA-256 plus WordPress.org per-file MD5 checksums | GPLv2 or later |
| PHPUnit | 10.4.2 | <https://phar.phpunit.de/phpunit-10.4.2.phar> | Pinned SHA-256 | BSD-3-Clause |

Only five `wp-includes` files are extracted: `version.php`, `cache.php`,
`class-wp-object-cache.php`, `class-wpdb.php`, and `l10n.php`. The WordPress
tarball is downloaded to `deps/cache/` and is not part of the repository or the
plugin release.

The five files were verified byte-for-byte against WordPress 6.4.1 before the
committed copies were removed. `pg4wp/core.php` loads them from the WordPress
installation's own `wp-includes/` directory in production; the fetched copy is
strictly for running this repository's tests.

To update WordPress, change `WORDPRESS_VERSION` and the checksums in
`scripts/versions.sh`, then run `scripts/bootstrap.sh` and the test suite. Do not
edit files under `wp-includes/` directly. To update PHPUnit, change
`PHPUNIT_VERSION` and `PHPUNIT_SHA256` in the same file.

The WordPress GPLv2 text is available at
<https://wordpress.org/about/license/>. PHPUnit's license is at
<https://github.com/sebastianbergmann/phpunit/blob/main/LICENSE>.
