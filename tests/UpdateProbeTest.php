<?php

use Xblossia\ThemeSelector\UpdateProbe;

function test_update_probe(): void
{
    T::group('plugin update: Composer preview results');
    $package = 'xblossia/librenms-theme-selector';
    T::ok('a current lock is current', UpdateProbe::result(0, 'Nothing to modify in lock file')['state'] === 'current');
    T::ok('a release upgrade is available', UpdateProbe::result(0, "  - Upgrading $package (1.1.3 => 1.1.4)")['state'] === 'available');
    T::ok('a branch advancing to a new revision is available', UpdateProbe::result(0, "  - Upgrading $package (dev-main abc1234 => dev-main def5678)")['state'] === 'available');
    T::ok('a change to another package does not imply this plugin is available', UpdateProbe::result(0, '  - Upgrading other/package (1.0 => 2.0)')['state'] === 'error');
    T::ok('a downgrade is not offered as an update', UpdateProbe::result(0, "  - Downgrading $package (2.0 => 1.0)")['state'] === 'error');
    T::ok('a failed preview is not current', UpdateProbe::result(1, 'Nothing to modify in lock file')['state'] === 'error');
    T::ok('a stale cache is not current', UpdateProbe::result(0, 'Nothing to modify in lock file; package information may be outdated')['state'] === 'error');
    T::ok('Composer local-cache fallback is not current', UpdateProbe::result(0, 'Package information was loaded from the local cache and may be out of date. Nothing to modify in lock file')['state'] === 'error');
    T::ok('a git failure is not current', UpdateProbe::result(0, "fatal: cannot access repository\nNothing to modify in lock file")['state'] === 'error');
}
