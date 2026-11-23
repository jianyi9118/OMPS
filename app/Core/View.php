<?php
namespace Omps\Core;

/**
 * Plain-PHP templates. Names: 'layouts/app' (resources/views) or 'System::users' (module views).
 * Every value printed in a template must go through e().
 */
class View
{
    public static function path($name)
    {
        if (strpos($name, '::') !== false) {
            list($module, $rel) = explode('::', $name, 2);
            if (!preg_match('/^[A-Za-z]+$/', $module)) {
                throw new \InvalidArgumentException('Bad module name');
            }
            $file = OMPS_ROOT . '/app/Modules/' . $module . '/views/' . $rel . '.php';
        } else {
            $file = OMPS_ROOT . '/resources/views/' . $name . '.php';
        }
        if (strpos($name, '..') !== false || !is_file($file)) {
            throw new \RuntimeException('View not found: ' . $name);
        }
        return $file;
    }

    public static function render($name, array $data = array())
    {
        $__file = self::path($name);
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $__file;
        } catch (\Exception $e) {
            ob_end_clean();
            throw $e;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
