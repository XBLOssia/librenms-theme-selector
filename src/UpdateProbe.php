<?php

namespace Xblossia\ThemeSelector;

/** Interpret Composer's dry-run without mistaking an outage for an update check. */
final class UpdateProbe
{
    /** @return array{state: string, message: string} */
    public static function result(int $exit, string $output): array
    {
        if ($exit !== 0 || preg_match('/may be outdated|out of date|could not be fully loaded|loaded from (?:the )?(?:local )?cache|failed to download|could not (?:be )?download|could not authenticate|fatal:|failed to connect/i', $output)) {
            return ['state' => 'error', 'message' => 'Could not check for updates. Check the server connection and Composer configuration, then try again.'];
        }
        $package = preg_quote(Status::PACKAGE, '/');
        if (preg_match('/^\s*-\s*(?:Downgrading|Removing|Installing)\s+' . $package . '\b/im', $output)) {
            return ['state' => 'error', 'message' => 'Composer proposed a change that is not an update. Check the server configuration before continuing.'];
        }
        if (preg_match('/^\s*-\s*(?:Upgrading|Updating)\s+' . $package . '\s*\(([^\r\n)]{1,160})\)/im', $output, $match)) {
            return ['state' => 'available', 'message' => 'An update is available: ' . $match[1] . '.'];
        }
        if (str_contains($output, 'Nothing to modify in lock file')) {
            return ['state' => 'current', 'message' => 'Theme Selector is up to date for its configured update source.'];
        }
        return ['state' => 'error', 'message' => 'Composer could not determine whether an update is available.'];
    }
}
