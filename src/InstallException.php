<?php

namespace Xblossia\ThemeSelector;

use RuntimeException;

/**
 * An install or removal that can't go ahead, with a message that is safe to
 * show an admin (it never contains bundle content).
 */
class InstallException extends RuntimeException
{
}
