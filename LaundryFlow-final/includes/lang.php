<?php
/**
 * Language support: English (en) and Tagalog (tl).
 *
 * Every label on the screen goes through t('English text').
 * To change or add a Tagalog word, edit includes/lang_tl.php only.
 */

function lang(): string
{
    $u = current_user();
    if ($u && !empty($u['lang'])) {
        return $u['lang'] === 'tl' ? 'tl' : 'en';
    }
    return ($_SESSION['lang'] ?? 'en') === 'tl' ? 'tl' : 'en';
}

function t(string $text): string
{
    static $dict = null;
    if (lang() !== 'tl') {
        return $text;
    }
    if ($dict === null) {
        $dict = require __DIR__ . '/lang_tl.php';
    }
    return $dict[$text] ?? $text;
}

/** Small language switch form (works without JavaScript). */
function lang_switch(string $class = ''): string
{
    $cur = lang();
    $h = '<form method="post" action="' . e(url('language.php')) . '" class="lang-switch ' . e($class) . '">' . csrf_field();
    $h .= '<input type="hidden" name="back" value="' . e($_SERVER['REQUEST_URI'] ?? '') . '">';
    $h .= '<button name="lang" value="tl" class="' . ($cur === 'tl' ? 'on' : '') . '">Tagalog</button>';
    $h .= '<button name="lang" value="en" class="' . ($cur === 'en' ? 'on' : '') . '">English</button>';
    return $h . '</form>';
}
