<?php
namespace Omps\Modules\System\Services;

use Omps\Core\Lang;
use Omps\Database\DB;

/**
 * Languages: list, names, default language (design §16).
 */
class LanguageService
{
    public static function name($code)
    {
        foreach (Lang::languages() as $l) {
            if ((string) $l['code'] === (string) $code) {
                return (string) $l['name'];
            }
        }
        return (string) $code;
    }

    /** Only an enabled language can be the default. */
    public static function setDefault($code)
    {
        if (!in_array($code, Lang::enabledCodes(), true)) {
            return false;
        }
        DB::transaction(function () use ($code) {
            DB::statement('UPDATE languages SET is_default = 0, updated_at = ? WHERE is_default = 1', array(now()));
            DB::table('languages')->where('code', $code)->update(array('is_default' => 1, 'updated_at' => now()));
        });
        Lang::clearCache();
        return true;
    }
}
