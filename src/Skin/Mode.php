<?php

namespace Xblossia\ThemeSelector\Skin;

/**
 * How much a skin is trusted.
 *
 * Upload is the default and the strict one: an admin-supplied bundle, treated
 * as hostile. Bundled is for the skins shipped in this package, which are
 * reviewed code and are the only ones allowed to set structural tokens
 * (decorative pseudo-elements, clip-path, animation) and use calc()/polygon().
 * Bundled skins go through the same parser, so a bug in either mode shows up
 * in the tests that validate them.
 */
enum Mode
{
    case Upload;
    case Bundled;
}
